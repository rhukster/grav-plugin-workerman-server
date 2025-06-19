#!/usr/bin/env php
<?php
/**
 * Grav SSE Server Daemon
 * 
 * Usage:
 *   php workerman-server.php start [-d]
 *   php workerman-server.php stop
 *   php workerman-server.php restart [-d]
 *   php workerman-server.php reload
 *   php workerman-server.php status
 *   php workerman-server.php connections
 */

// Override script name for cleaner status display  
$_SERVER['SCRIPT_NAME'] = 'grav-sse-server';
$argv[0] = 'grav-sse-server';
$_SERVER['argv'][0] = 'grav-sse-server';

// Define Grav root
define('GRAV_ROOT', realpath(__DIR__ . '/../../../../') . '/');
define('GRAV_WORKERMAN_DAEMON', true);

// Load Grav
require_once GRAV_ROOT . 'vendor/autoload.php';

// Load plugin autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use Grav\Common\Grav;
use Grav\Plugin\WorkermanServer\WorkermanSSEServer;
use Grav\Plugin\WorkermanServer\WorkermanRegistry;

// Initialize minimal Grav instance
$grav = Grav::instance([
    'loader' => new \Composer\Autoload\ClassLoader(),
]);

// Load configuration
$grav['config']->init();
$config = $grav['config']->get('plugins.workerman-server');

if (!$config || !$config['enabled']) {
    echo "Workerman Server plugin is not enabled in configuration.\n";
    exit(1);
}

// Create registry and allow plugins to register handlers
$registry = new WorkermanRegistry();

// Fire event to allow plugins to register handlers
// First try to load from cache for better performance
$cacheFile = GRAV_ROOT . '/cache/workerman-handlers.php';
if (file_exists($cacheFile)) {
    $handlers = include $cacheFile;
    foreach ($handlers as $name => $handler) {
        try {
            $registry->registerHandler($name, $handler['class'], $handler['config'] ?? []);
            echo "Loaded cached handler: {$name}\n";
        } catch (\Exception $e) {
            echo "Failed to load cached handler {$name}: {$e->getMessage()}\n";
        }
    }
} else {
    // Fallback: trigger event to register handlers dynamically
    echo "No handler cache found, registering handlers dynamically...\n";
    try {
        $event = new \RocketTheme\Toolbox\Event\Event(['registry' => $registry]);
        $grav->fireEvent('onWorkermanInitialized', $event);
        echo "Dynamic handler registration completed.\n";
        echo "Tip: Run 'bin/plugin workerman-server cache' to cache handlers for faster startup.\n";
    } catch (\Exception $e) {
        echo "Warning: Dynamic handler registration failed: {$e->getMessage()}\n";
    }
}

// Set up paths
$config['pid_file'] = GRAV_ROOT . 'logs/workerman.pid';
$config['log_file'] = GRAV_ROOT . 'logs/workerman.log';

// Create server instance
$server = new WorkermanSSEServer($config, $registry);

// Initialize the server
try {
    $server->initialize();
} catch (\Exception $e) {
    echo "Failed to initialize server: {$e->getMessage()}\n";
    exit(1);
}

// Check if this is a status command and add cleaner output
if (isset($argv[1]) && $argv[1] === 'status') {
    echo "=== Grav SSE Server Status ===\n";
}

// Start based on command
$server->start();