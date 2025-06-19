<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI Command to restart Workerman Server
 */
class WorkermanRestartCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('restart')
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run in daemon mode'
            )
            ->setDescription('Restart Workerman SSE server')
            ->setHelp('Restart the Workerman SSE server daemon');
    }

    protected function serve()
    {
        $daemon = $this->input->getOption('daemon');
        
        $gravRoot = getcwd();
        $scriptPath = $gravRoot . '/user/plugins/workerman-server/bin/workerman-server.php';
        
        $cmd = 'php ' . escapeshellarg($scriptPath) . ' restart';
        if ($daemon) {
            $cmd .= ' -d';
        }
        
        $this->output->writeln('<info>Restarting Workerman server...</info>');
        passthru($cmd);
        
        return 0;
    }
}