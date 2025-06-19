<?php

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * CLI command for viewing Workerman logs
 */
class WorkermanLogsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('logs')
            ->setDescription('View Workerman server logs')
            ->addOption('tail', 't', InputOption::VALUE_NONE, 'Follow log output (tail -f)')
            ->addOption('lines', 'l', InputOption::VALUE_OPTIONAL, 'Number of lines to show', 50)
            ->setHelp('View or tail the Workerman server logs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tail = $input->getOption('tail');
        $lines = (int) $input->getOption('lines');
        
        $logFile = GRAV_ROOT . '/logs/workerman.log';
        
        if (!file_exists($logFile)) {
            $io->error("Log file not found: {$logFile}");
            $io->note('Make sure Workerman server has been started at least once.');
            return 1;
        }
        
        if ($tail) {
            $io->title('Following Workerman logs (Press Ctrl+C to stop)');
            $process = new Process(['tail', '-f', $logFile]);
            $process->setTimeout(null); // No timeout for tail -f
            
            // Use the simpler run method with real-time callback
            $process->run(function ($type, $buffer) {
                echo $buffer;
            });
            
            return $process->getExitCode();
        } else {
            $io->title("Last {$lines} lines of Workerman logs");
            $process = new Process(['tail', '-n', (string)$lines, $logFile]);
            $process->run();
            $output->write($process->getOutput());
            return $process->getExitCode();
        }
    }
}