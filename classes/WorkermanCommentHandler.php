<?php

namespace Grav\Plugin\WorkermanServer;

use Workerman\Connection\TcpConnection;
use Grav\Common\Grav;
use Grav\Plugin\CommentsPro\CommentManager;

/**
 * Comment handler for Workerman SSE server
 */
class WorkermanCommentHandler implements WorkermanHandlerInterface
{
    protected $grav;
    protected $config;
    protected $connections = [];
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->grav = Grav::instance();
    }
    
    /**
     * Handle new SSE connection
     */
    public function onConnect(TcpConnection $connection, string $route): void
    {
        // Store connection with route for targeted updates
        if (!isset($this->connections[$route])) {
            $this->connections[$route] = [];
        }
        
        $this->connections[$route][] = $connection;
        
        // Send initial data
        $this->sendInitialData($connection, $route);
        
        // Set up connection close handler
        $connection->onClose = function() use ($connection, $route) {
            $this->onDisconnect($connection, $route);
        };
    }
    
    /**
     * Handle SSE connection close
     */
    public function onDisconnect(TcpConnection $connection, string $route): void
    {
        if (isset($this->connections[$route])) {
            $key = array_search($connection, $this->connections[$route], true);
            if ($key !== false) {
                unset($this->connections[$route][$key]);
            }
            
            // Clean up empty routes
            if (empty($this->connections[$route])) {
                unset($this->connections[$route]);
            }
        }
    }
    
    /**
     * Send initial comment data to new connection
     */
    protected function sendInitialData(TcpConnection $connection, string $route): void
    {
        try {
            // Find the page
            $page = $this->grav['pages']->find($route);
            if (!$page) {
                return;
            }
            
            // Get comment manager
            $manager = new CommentManager($page, $this->config['plugin_config'] ?? [], $this->grav);
            $comments = $manager->getComments('published');
            
            // Send initial comment count
            $data = [
                'type' => 'initial',
                'route' => $route,
                'count' => count($comments),
                'timestamp' => time()
            ];
            
            $connection->send("data: " . json_encode($data) . "\n\n");
        } catch (\Exception $e) {
            error_log('WorkermanCommentHandler: Error sending initial data: ' . $e->getMessage());
        }
    }
    
    /**
     * Broadcast update to all connections for a route
     */
    public function broadcastUpdate(string $route, array $data): void
    {
        if (!isset($this->connections[$route])) {
            return;
        }
        
        $message = "data: " . json_encode($data) . "\n\n";
        
        foreach ($this->connections[$route] as $connection) {
            try {
                $connection->send($message);
            } catch (\Exception $e) {
                error_log('WorkermanCommentHandler: Error broadcasting to connection: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Get connection count for a route
     */
    public function getConnectionCount(string $route): int
    {
        return count($this->connections[$route] ?? []);
    }
    
    /**
     * Get total connection count
     */
    public function getTotalConnections(): int
    {
        $total = 0;
        foreach ($this->connections as $route => $connections) {
            $total += count($connections);
        }
        return $total;
    }
    
    /**
     * Get routes with active connections
     */
    public function getActiveRoutes(): array
    {
        return array_keys($this->connections);
    }
    
    /**
     * Get the paths this handler should watch for changes
     */
    public function getWatchPaths(string $route): array
    {
        // Watch the comments.yaml file for this page
        $page = $this->grav['pages']->find($route);
        if (!$page) {
            return [];
        }
        
        $commentsFile = dirname($page->filePath()) . '/comments.yaml';
        return file_exists($commentsFile) ? [$commentsFile] : [];
    }
    
    /**
     * Check for updates on the watched paths
     */
    public function checkForUpdates(string $route, int $lastModified): ?array
    {
        $watchPaths = $this->getWatchPaths($route);
        if (empty($watchPaths)) {
            return null;
        }
        
        $maxModified = 0;
        foreach ($watchPaths as $path) {
            if (file_exists($path)) {
                $maxModified = max($maxModified, filemtime($path));
            }
        }
        
        if ($maxModified > $lastModified) {
            try {
                // Get updated comment count
                $page = $this->grav['pages']->find($route);
                if ($page) {
                    $manager = new CommentManager($page, $this->config['plugin_config'] ?? [], $this->grav);
                    $comments = $manager->getComments('published');
                    
                    return [
                        'type' => 'update',
                        'route' => $route,
                        'count' => count($comments),
                        'timestamp' => time(),
                        'modified' => $maxModified
                    ];
                }
            } catch (\Exception $e) {
                error_log('WorkermanCommentHandler: Error checking updates: ' . $e->getMessage());
            }
        }
        
        return null;
    }
    
    /**
     * Get the event type this handler emits
     */
    public function getEventType(): string
    {
        return 'comments';
    }
    
    /**
     * Handle a client event/message
     */
    public function handleClientEvent(string $event, array $data, string $route): ?array
    {
        switch ($event) {
            case 'get_count':
                try {
                    $page = $this->grav['pages']->find($route);
                    if ($page) {
                        $manager = new CommentManager($page, $this->config['plugin_config'] ?? [], $this->grav);
                        $comments = $manager->getComments('published');
                        
                        return [
                            'type' => 'count',
                            'route' => $route,
                            'count' => count($comments),
                            'timestamp' => time()
                        ];
                    }
                } catch (\Exception $e) {
                    error_log('WorkermanCommentHandler: Error getting count: ' . $e->getMessage());
                }
                break;
        }
        
        return null;
    }
    
    /**
     * Get handler configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}