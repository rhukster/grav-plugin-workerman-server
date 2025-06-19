<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Grav\Plugin\WorkermanServer\WorkermanManager;

/**
 * CLI Command for Workerman Server management
 */
class WorkermanStartCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('start')
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run in daemon mode'
            )
            ->setDescription('Start Workerman SSE server')
            ->setHelp('Start the Workerman SSE server daemon');
    }

    protected function serve()
    {
        $daemon = $this->input->getOption('daemon');
        
        // Get correct Grav root path
        $gravRoot = getcwd();
        $scriptPath = $gravRoot . '/user/plugins/workerman-server/bin/workerman-server.php';
        
        $cmd = 'php ' . escapeshellarg($scriptPath) . ' start';
        if ($daemon) {
            $cmd .= ' -d';
        }
        
        $this->output->writeln('<info>Starting Workerman server...</info>');
        passthru($cmd);
        
        return 0;
    }
}