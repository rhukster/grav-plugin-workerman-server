<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;

/**
 * CLI Command to stop Workerman Server
 */
class WorkermanStopCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('stop')
            ->setDescription('Stop Workerman SSE server')
            ->setHelp('Stop the Workerman SSE server daemon');
    }

    protected function serve()
    {
        $gravRoot = getcwd();
        $scriptPath = $gravRoot . '/user/plugins/workerman-server/bin/workerman-server.php';
        
        $cmd = 'php ' . escapeshellarg($scriptPath) . ' stop';
        $this->output->writeln('<info>Stopping Workerman server...</info>');
        passthru($cmd);
        
        return 0;
    }
}