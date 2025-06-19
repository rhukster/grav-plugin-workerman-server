<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\WorkermanServer\WorkermanRegistry;
use Grav\Plugin\WorkermanServer\WorkermanManager;
use RocketTheme\Toolbox\Event\Event;

/**
 * Workerman Server Plugin
 * 
 * Provides a high-performance SSE/WebSocket server for real-time communication
 * Other plugins can register handlers to provide real-time functionality
 *
 * @package    Grav\Plugin
 * @author     Your Name
 * @license    MIT License
 */
class WorkermanServerPlugin extends Plugin
{
    protected WorkermanRegistry $registry;
    protected WorkermanManager $manager;
    
    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001], // Before any other plugins
                ['onPluginsInitialized', 1000]
            ],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Initialize the registry for handler registration
        $this->registry = new WorkermanRegistry();
        $this->grav['workerman_registry'] = $this->registry;
        
        // Initialize the manager
        $config = $this->config->get('plugins.workerman-server');
        $this->manager = new WorkermanManager($config);
        $this->grav['workerman_manager'] = $this->manager;
        
        // Enable CLI commands and admin functionality
        if ($this->isAdmin() || defined('GRAV_CLI')) {
            $this->enable([
                'onAdminMenu' => ['onAdminMenu', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
                'onTwigInitialized' => ['onTwigInitialized', 0],
                'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
                'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
            ]);
            
            // Register CLI commands
            if (defined('GRAV_CLI')) {
                $this->setupCli();
            }
        }

        // Fire event to allow other plugins to register handlers
        $event = new Event(['registry' => $this->registry]);
        $this->grav->fireEvent('onWorkermanInitialized', $event);
    }
    
    /**
     * Add Workerman management to admin menu
     */
    public function onAdminMenu(): void
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_WORKERMAN_SERVER.TITLE'] = [
            'route' => 'workerman-server',
            'icon' => 'fa-server',
            'badge' => [
                'updates' => false
            ]
        ];
    }
    
    /**
     * Get the public URL for SSE connections
     * Other plugins can call this to get the correct Workerman URL
     */
    public function getSSEUrl(): ?string
    {
        $config = $this->config->get('plugins.workerman-server');
        if (!$config || !$config['enabled']) {
            return null;
        }
        
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 8080;
        
        // For browser connections, we need to use the actual hostname
        // Convert localhost/127.0.0.1 to the current domain
        if ($host === '127.0.0.1' || $host === 'localhost') {
            $uri = $this->grav['uri'];
            $scheme = $uri->scheme();
            $currentHost = $uri->host();
            
            // Use same scheme as current request, but use the configured port
            return "{$scheme}://{$currentHost}:{$port}";
        }
        
        // Use configured host as-is
        $scheme = $config['ssl']['enabled'] ? 'https' : 'http';
        return "{$scheme}://{$host}:{$port}";
    }
    
    /**
     * Handle admin tasks (following seo-magic plugin pattern)
     */
    public function onAdminTaskExecute($event): void
    {
        $task = $event['method'] ?? null;
        
        // Define allowed workerman tasks (mapped from action:workermanX URLs)
        $allowedTasks = [
            'taskWorkermanStart',
            'taskWorkermanStop', 
            'taskWorkermanRestart',
            'taskWorkermanReload',
            'taskWorkermanStatus',
            'taskWorkermanConnections',
            'taskWorkermanGenerateService'
        ];
        
        if (!in_array($task, $allowedTasks)) {
            return;
        }
        
        $controller = $event['controller'];
        
        // Set JSON response header immediately
        header('Content-Type: application/json');
        
        // Log the task for debugging
        if ($this->grav['log']) {
            $this->grav['log']->info("Workerman Server: Handling admin task: $task");
        }
        
        $result = ['success' => false, 'message' => 'Unknown task'];
        
        switch ($task) {
            case 'taskWorkermanStart':
                $result = $this->manager->start();
                break;
            case 'taskWorkermanStop':
                $result = $this->manager->stop();
                break;
            case 'taskWorkermanRestart':
                $result = $this->manager->restart();
                break;
            case 'taskWorkermanReload':
                $result = $this->manager->reload();
                break;
            case 'taskWorkermanStatus':
                $result = $this->manager->getStatus();
                break;
            case 'taskWorkermanConnections':
                $result = $this->manager->getConnections();
                break;
            case 'taskWorkermanGenerateService':
                $result = $this->manager->generateSystemdService();
                break;
        }
        
        // Log the result for debugging
        if ($this->grav['log']) {
            $this->grav['log']->info("Workerman Server: Task result: " . json_encode($result));
        }
        
        echo json_encode($result);
        exit;
    }
    
    /**
     * Add SSE check endpoint
     */
    public function onPageInitialized(): void
    {
        $uri = $this->grav['uri'];
        $route = $uri->path();
        
        // Check if this is a Workerman SSE check request
        if ($route === '/_workerman/check') {
            $this->handleCheckRequest();
        }
    }
    
    /**
     * Handle SSE availability check
     */
    protected function handleCheckRequest(): void
    {
        $uri = $this->grav['uri'];
        $handler = $uri->query('handler');
        
        if (!$handler) {
            $this->jsonResponse(['error' => 'No handler specified']);
            return;
        }
        
        // Check if Workerman is running
        if ($this->manager->isWorkermanAvailable() && $this->manager->isRunning()) {
            $config = $this->config->get('plugins.workerman-server');
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 8080;
            
            // Determine protocol based on SSL configuration
            $sslEnabled = $config['ssl']['enabled'] ?? false;
            $protocol = $sslEnabled ? 'https' : 'http';
            
            // Get route from query parameter
            $route = $uri->query('route') ?? '/';
            
            $url = "{$protocol}://{$host}:{$port}/sse/{$handler}{$route}";
            
            $this->jsonResponse([
                'available' => true,
                'url' => $url,
                'handler' => $handler,
                'route' => $route
            ]);
        } else {
            $this->jsonResponse([
                'available' => false,
                'error' => 'Workerman server not running'
            ]);
        }
    }
    
    /**
     * Send JSON response
     */
    protected function jsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Add admin template paths
     */
    public function onTwigAdminTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }
    
    /**
     * Initialize Twig for admin
     */
    public function onTwigInitialized(): void
    {
        // Set up manager for workerman-server route
        if ($this->isAdmin() && $this->grav['admin']->route === 'workerman-server') {
            $this->grav['twig']->twig_vars['workermanManager'] = $this->manager;
            $this->grav['twig']->twig_vars['workermanStatus'] = $this->manager->getStatus();
        }
    }
    
    /**
     * Add plugin assets
     */
    public function onTwigSiteVariables(): void
    {
        if ($this->isAdmin()) {
            $this->grav['assets']->addCss('plugin://workerman-server/assets/admin.css');
            $this->grav['assets']->addJs('plugin://workerman-server/assets/admin.js');
            
            // Add variables for workerman-server admin route
            if ($this->grav['admin']->route === 'workerman-server') {
                $this->grav['twig']->twig_vars['workermanManager'] = $this->manager;
                $this->grav['twig']->twig_vars['workermanStatus'] = $this->manager->getStatus();
                $this->grav['twig']->twig_vars['workermanConfig'] = $this->manager->getConfig();
            }
        }
    }
    
    /**
     * Setup CLI commands
     */
    protected function setupCli(): void
    {
        $commands = [
            'WorkermanStartCommand',
            'WorkermanStopCommand',
            'WorkermanRestartCommand', 
            'WorkermanStatusCommand',
            'WorkermanCacheCommand',
            'WorkermanLogsCommand',
            'SslSetupCommand',
            'SslRenewalCommand'
        ];
        
        $locator = $this->grav['locator'];
        $path = $locator->findResource('plugin://workerman-server/cli');
        
        if ($path && is_dir($path)) {
            foreach ($commands as $command) {
                $file = $path . '/' . $command . '.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        }
    }
}