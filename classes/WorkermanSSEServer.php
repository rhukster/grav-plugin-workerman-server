<?php
namespace Grav\Plugin\WorkermanServer;

use Workerman\Worker;
use Workerman\Timer;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Http\Request;
use Workerman\Connection\TcpConnection;

/**
 * Workerman-based SSE Server with pluggable handler support
 * Provides efficient, non-blocking SSE handling with proper resource management
 */
class WorkermanSSEServer
{
    protected Worker $worker;
    protected array $connections = [];
    protected array $subscriptions = [];
    protected array $config;
    protected WorkermanRegistry $registry;
    protected array $fileWatchers = [];
    protected int $heartbeatInterval = 30;
    
    public function __construct(array $config = [], WorkermanRegistry $registry = null)
    {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 8080,
            'worker_count' => 4,
            'max_connections_per_ip' => 10,
            'heartbeat_interval' => 30,
            'connection_timeout' => 300,
            'check_interval' => 2,
            'log_file' => null,
            'pid_file' => null,
            'ssl' => [
                'enabled' => false,
                'cert_file' => null,
                'key_file' => null,
                'verify_peer' => false
            ]
        ], $config);
        
        $this->registry = $registry ?: new WorkermanRegistry();
        $this->heartbeatInterval = $this->config['heartbeat_interval'];
    }
    
    /**
     * Initialize the Workerman worker
     */
    public function initialize(): void
    {
        // Determine protocol based on SSL configuration
        $protocol = $this->config['ssl']['enabled'] ? 'https' : 'http';
        $address = "{$protocol}://{$this->config['host']}:{$this->config['port']}";
        
        $this->worker = new Worker($address);
        
        // Configure SSL context if enabled
        if ($this->config['ssl']['enabled']) {
            $this->setupSSLContext();
        }
        
        // Set worker properties
        $this->worker->count = $this->config['worker_count'];
        $this->worker->name = 'Grav-SSE';
        
        // Set a cleaner process title
        Worker::$processTitle = 'grav-sse-server';
        
        if ($this->config['pid_file']) {
            Worker::$pidFile = $this->config['pid_file'];
        }
        
        if ($this->config['log_file']) {
            Worker::$logFile = $this->config['log_file'];
        }
        
        // Set up event handlers
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];
    }
    
    /**
     * Set up SSL context for HTTPS
     */
    protected function setupSSLContext(): void
    {
        $sslConfig = $this->config['ssl'];
        
        if (!$sslConfig['cert_file'] || !$sslConfig['key_file']) {
            throw new \Exception('SSL certificate and key files must be specified when SSL is enabled');
        }
        
        // Handle relative paths
        $certFile = $this->resolvePath($sslConfig['cert_file']);
        $keyFile = $this->resolvePath($sslConfig['key_file']);
        
        if (!file_exists($certFile)) {
            throw new \Exception("SSL certificate file not found: {$certFile}");
        }
        
        if (!file_exists($keyFile)) {
            throw new \Exception("SSL key file not found: {$keyFile}");
        }
        
        $context = [
            'ssl' => [
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
                'verify_peer' => $sslConfig['verify_peer'],
                'allow_self_signed' => true,
                'verify_peer_name' => false
            ]
        ];
        
        $this->worker->transport = 'ssl';
        $this->worker->context = $context;
    }
    
    /**
     * Resolve file path (handle relative paths)
     */
    protected function resolvePath(string $path): string
    {
        if (strpos($path, '/') === 0) {
            return $path; // Absolute path
        }
        
        // Relative to Grav root
        $gravRoot = defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();
        return $gravRoot . '/' . $path;
    }
    
    /**
     * Start the server
     */
    public function start(): void
    {
        Worker::runAll();
    }
    
    /**
     * Worker start callback
     */
    public function onWorkerStart(Worker $worker): void
    {
        $protocol = $this->config['ssl']['enabled'] ? 'HTTPS' : 'HTTP';
        echo "Workerman SSE Worker #{$worker->id} started on {$this->config['host']}:{$this->config['port']} ({$protocol})\n";
        
        // Set up periodic tasks
        $this->setupHeartbeat();
        $this->setupFileWatcher();
    }
    
    /**
     * Handle incoming HTTP requests
     */
    public function onMessage(TcpConnection $connection, Request $request): void
    {
        $path = $request->path();
        $method = $request->method();
        
        // CORS headers
        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
        
        // Handle OPTIONS requests
        if ($method === 'OPTIONS') {
            $connection->send(new Response(200, $headers));
            return;
        }
        
        // Handle POST requests for notifications
        if ($method === 'POST' && preg_match('#^/notify/(.*)$#', $path, $matches)) {
            $this->handleNotification($connection, $headers, $matches[1], $request);
            return;
        }
        
        // Only handle GET requests for SSE and stats
        if ($method !== 'GET') {
            $connection->send(new Response(405, $headers, 'Method Not Allowed'));
            return;
        }
        
        // Handle stats endpoint
        if ($path === '/stats') {
            $this->handleStatsRequest($connection, $headers);
            return;
        }
        
        // Parse path to get handler and route
        if (!preg_match('#^/sse/([^/]+)(.*)$#', $path, $matches)) {
            $connection->send(new Response(404, $headers, 'Not Found'));
            return;
        }
        
        $handlerName = $matches[1];
        $route = $matches[2] ?: '/';
        
        // Check if handler exists
        if (!$this->registry->hasHandler($handlerName)) {
            $connection->send(new Response(404, $headers, "Handler '{$handlerName}' not found"));
            return;
        }
        
        // Rate limiting check
        if (!$this->checkRateLimit($connection)) {
            $connection->send(new Response(429, $headers, 'Too Many Requests'));
            return;
        }
        
        // Start SSE stream
        $this->startSSEStream($connection, $handlerName, $route);
    }
    
    /**
     * Start SSE stream for a connection
     */
    protected function startSSEStream(TcpConnection $connection, string $handlerName, string $route): void
    {
        // Set SSE headers
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'X-Accel-Buffering' => 'no'
        ];
        
        // Build SSE response with headers and initial content
        $responseContent = "retry: 5000\n\n";
        $response = new Response(200, $headers, $responseContent);
        $connection->send($response, false); // Don't close connection
        
        // Store connection details
        $connectionId = spl_object_id($connection);
        $this->connections[$connectionId] = [
            'connection' => $connection,
            'handler' => $handlerName,
            'route' => $route,
            'client_ip' => $this->getClientIP($connection),
            'start_time' => time(),
            'last_heartbeat' => time()
        ];
        
        // Subscribe to handler updates
        $subscriptionKey = "{$handlerName}:{$route}";
        if (!isset($this->subscriptions[$subscriptionKey])) {
            $this->subscriptions[$subscriptionKey] = [];
        }
        $this->subscriptions[$subscriptionKey][] = $connectionId;
        
        // Send initial connection event
        $this->sendSSEEvent($connection, 'connected', [
            'status' => 'connected',
            'timestamp' => time(),
            'handler' => $handlerName,
            'route' => $route
        ]);
        
        echo "SSE connection established for {$handlerName}:{$route} (ID: {$connectionId})\n";
    }
    
    /**
     * Connection close callback
     */
    public function onClose(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        if (isset($this->connections[$connectionId])) {
            $handlerName = $this->connections[$connectionId]['handler'];
            $route = $this->connections[$connectionId]['route'];
            $subscriptionKey = "{$handlerName}:{$route}";
            
            // Remove from subscriptions
            if (isset($this->subscriptions[$subscriptionKey])) {
                $this->subscriptions[$subscriptionKey] = array_filter(
                    $this->subscriptions[$subscriptionKey],
                    fn($id) => $id !== $connectionId
                );
                
                if (empty($this->subscriptions[$subscriptionKey])) {
                    unset($this->subscriptions[$subscriptionKey]);
                }
            }
            
            unset($this->connections[$connectionId]);
            echo "SSE connection closed for {$handlerName}:{$route} (ID: {$connectionId})\n";
        }
    }
    
    /**
     * Error callback
     */
    public function onError(TcpConnection $connection, $code, $msg): void
    {
        echo "Connection error: {$code} - {$msg}\n";
    }
    
    /**
     * Set up heartbeat timer
     */
    protected function setupHeartbeat(): void
    {
        Timer::add($this->heartbeatInterval, function() {
            $now = time();
            $timeout = $this->config['connection_timeout'];
            
            foreach ($this->connections as $connectionId => $data) {
                $connection = $data['connection'];
                $lastHeartbeat = $data['last_heartbeat'];
                
                // Check for stale connections
                if (($now - $lastHeartbeat) > $timeout) {
                    echo "Closing stale connection {$connectionId}\n";
                    $connection->close();
                    continue;
                }
                
                // Send heartbeat
                $this->sendSSEEvent($connection, 'heartbeat', [
                    'timestamp' => $now,
                    'uptime' => $now - $data['start_time']
                ]);
                
                $this->connections[$connectionId]['last_heartbeat'] = $now;
            }
        });
    }
    
    /**
     * Set up file watcher for all handlers
     */
    protected function setupFileWatcher(): void
    {
        Timer::add($this->config['check_interval'], function() {
            foreach ($this->subscriptions as $subscriptionKey => $connectionIds) {
                if (empty($connectionIds)) {
                    continue;
                }
                
                list($handlerName, $route) = explode(':', $subscriptionKey, 2);
                
                try {
                    $this->checkForUpdates($handlerName, $route, $connectionIds);
                } catch (\Exception $e) {
                    echo "Error checking updates for {$handlerName}:{$route}: {$e->getMessage()}\n";
                }
            }
        });
    }
    
    /**
     * Check for updates using handler
     */
    protected function checkForUpdates(string $handlerName, string $route, array $connectionIds): void
    {
        $handler = $this->registry->getHandler($handlerName);
        if (!$handler) {
            return;
        }
        
        $cacheKey = "{$handlerName}:{$route}";
        $lastModified = $this->fileWatchers[$cacheKey] ?? 0;
        
        // Check for updates using handler
        $updateData = $handler->checkForUpdates($route, $lastModified);
        
        if ($updateData !== null) {
            echo "Updates detected by {$handlerName} on {$route} - notifying " . count($connectionIds) . " connections\n";
            
            // Update last modified time
            $this->fileWatchers[$cacheKey] = $updateData['timestamp'] ?? time();
            
            // Broadcast update to all connections
            foreach ($connectionIds as $connectionId) {
                if (isset($this->connections[$connectionId])) {
                    $connection = $this->connections[$connectionId]['connection'];
                    $this->sendSSEEvent($connection, 'update', array_merge($updateData, [
                        'handler' => $handlerName,
                        'route' => $route
                    ]));
                }
            }
        }
    }
    
    /**
     * Send SSE event to a connection
     */
    protected function sendSSEEvent(TcpConnection $connection, string $event, array $data): void
    {
        $sseData = "event: {$event}\n";
        $sseData .= "data: " . json_encode($data) . "\n\n";
        
        $connection->send($sseData, false);
    }
    
    /**
     * Check rate limiting for connections
     */
    protected function checkRateLimit(TcpConnection $connection): bool
    {
        $clientIP = $this->getClientIP($connection);
        $maxConnections = $this->config['max_connections_per_ip'];
        
        $currentConnections = 0;
        foreach ($this->connections as $data) {
            if ($data['client_ip'] === $clientIP) {
                $currentConnections++;
            }
        }
        
        return $currentConnections < $maxConnections;
    }
    
    /**
     * Get client IP from connection
     */
    protected function getClientIP(TcpConnection $connection): string
    {
        return $connection->getRemoteIp();
    }
    
    /**
     * Handle stats endpoint request
     */
    protected function handleStatsRequest(TcpConnection $connection, array $headers): void
    {
        $stats = [
            'total_connections' => count($this->connections),
            'handlers' => [],
            'subscriptions' => [],
            'uptime' => time() - (Worker::$globalStatistics['start_timestamp'] ?? time())
        ];
        
        // Group connections by handler
        foreach ($this->connections as $data) {
            $handler = $data['handler'];
            if (!isset($stats['handlers'][$handler])) {
                $stats['handlers'][$handler] = 0;
            }
            $stats['handlers'][$handler]++;
        }
        
        // List active subscriptions
        foreach ($this->subscriptions as $key => $connections) {
            $stats['subscriptions'][$key] = count($connections);
        }
        
        $headers['Content-Type'] = 'application/json';
        $connection->send(new Response(200, $headers, json_encode($stats, JSON_PRETTY_PRINT)));
    }
    
    /**
     * Handle notification requests from Grav
     */
    protected function handleNotification(TcpConnection $connection, array $headers, string $route, $request): void
    {
        $headers['Content-Type'] = 'application/json';
        
        try {
            // Get POST data from request
            $body = $request->rawBody();
            $data = json_decode($body, true) ?? [];
            
            // Validate notification type
            $type = $data['type'] ?? 'update';
            
            // Find all connections for the specified route and handler(s)
            $notified = 0;
            foreach ($this->connections as $connectionData) {
                // For comment changes, notify all comment handlers on this route
                if ($connectionData['handler'] === 'comments' && $connectionData['route'] === "/{$route}") {
                    $this->sendSSEEvent($connectionData['connection'], 'update', [
                        'type' => $type,
                        'route' => $route,
                        'timestamp' => time()
                    ]);
                    $notified++;
                }
            }
            
            // Send success response
            $connection->send(new Response(200, $headers, json_encode([
                'success' => true,
                'notified' => $notified,
                'route' => $route
            ])));
            
        } catch (\Exception $e) {
            // Send error response
            $connection->send(new Response(500, $headers, json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ])));
        }
    }
    
    /**
     * Graceful shutdown
     */
    public function shutdown(): void
    {
        foreach ($this->connections as $connectionId => $data) {
            $connection = $data['connection'];
            $this->sendSSEEvent($connection, 'shutdown', [
                'reason' => 'Server shutdown',
                'timestamp' => time()
            ]);
            $connection->close();
        }
        
        Worker::stopAll();
    }
    
    /**
     * Set the handler registry
     */
    public function setRegistry(WorkermanRegistry $registry): void
    {
        $this->registry = $registry;
    }
}