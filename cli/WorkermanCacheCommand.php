<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Common\Grav;

/**
 * CLI Command to cache Workerman handlers
 */
class WorkermanCacheCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('cache')
            ->setDescription('Cache Workerman handlers')
            ->setHelp('Cache registered handlers for daemon use');
    }

    protected function serve()
    {
        // Include the plugin autoloader
        include __DIR__ . '/../vendor/autoload.php';
        
        $grav = Grav::instance();
        
        // Initialize plugins
        $this->initializePlugins();
        
        // Initialize plugins to trigger handler registration
        $grav->fireEvent('onPluginsInitialized');
        
        // Get registry
        $registry = $grav['workerman_registry'] ?? null;
        if (!$registry) {
            $this->output->writeln('<error>Workerman registry not found. Is the plugin enabled?</error>');
            return 1;
        }
        
        // Fire event to allow plugins to register
        $event = new \RocketTheme\Toolbox\Event\Event(['registry' => $registry]);
        $grav->fireEvent('onWorkermanInitialized', $event);
        
        // Get all handlers
        $handlers = $registry->getHandlers();
        
        if (empty($handlers)) {
            $this->output->writeln('<warning>No handlers registered.</warning>');
        } else {
            $this->output->writeln('<info>Registered handlers:</info>');
            foreach ($handlers as $name => $handler) {
                $this->output->writeln("  - {$name}: {$handler['class']}");
            }
        }
        
        // Cache handlers
        $cacheFile = GRAV_ROOT . '/cache/workerman-handlers.php';
        $cacheDir = dirname($cacheFile);
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $export = "<?php\nreturn " . var_export($handlers, true) . ";\n";
        file_put_contents($cacheFile, $export);
        
        $this->output->writeln("<info>Handler cache saved to: {$cacheFile}</info>");
        return 0;
    }
}