<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\WorkermanServer\WorkermanManager;
use Grav\Common\Grav;

/**
 * CLI Command to check Workerman Server status
 */
class WorkermanStatusCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->setDescription('Check Workerman SSE server status')
            ->setHelp('Check the status of the Workerman SSE server daemon');
    }

    protected function serve()
    {
        // Include the plugin autoloader
        include __DIR__ . '/../vendor/autoload.php';
        
        $grav = Grav::instance();
        $config = $grav['config']->get('plugins.workerman-server');
        $manager = new WorkermanManager($config);
        
        if (!$manager->isWorkermanAvailable()) {
            $this->output->writeln('<error>Workerman is not available. Please run composer install in the plugin directory.</error>');
            return 1;
        }
        
        $status = $manager->getStatus();
        $this->displayStatus($status);
        
        return 0;
    }
    
    /**
     * Display status information
     */
    protected function displayStatus(array $status)
    {
        $this->output->writeln('<info>Workerman Server Status</info>');
        $this->output->writeln('------------------------');
        
        $running = $status['running'] ? '<info>Running</info>' : '<error>Stopped</error>';
        $this->output->writeln('Status: ' . $running);
        
        if ($status['running']) {
            $this->output->writeln('PID: ' . ($status['pid'] ?? 'Unknown'));
            $this->output->writeln('Connections: ' . $status['connections']);
            
            if ($status['uptime']) {
                $hours = floor($status['uptime'] / 3600);
                $minutes = floor(($status['uptime'] % 3600) / 60);
                $this->output->writeln("Uptime: {$hours}h {$minutes}m");
            }
            
            if (!empty($status['handlers'])) {
                $this->output->writeln("\nActive handlers:");
                foreach ($status['handlers'] as $handler => $count) {
                    $this->output->writeln("  - {$handler}: {$count} connections");
                }
            }
        }
        
        if (isset($status['details'])) {
            $this->output->writeln("\nDetailed status:");
            $this->output->writeln($status['details']);
        }
    }
}