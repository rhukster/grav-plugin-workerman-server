<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * CLI command for setting up SSL certificates for Workerman server
 */
class SslSetupCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ssl-setup')
            ->setDescription('Set up Let\'s Encrypt SSL certificates for Workerman server')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name for the certificate')
            ->addArgument('email', InputArgument::OPTIONAL, 'Email address for Let\'s Encrypt')
            ->addOption('webroot', 'w', InputOption::VALUE_OPTIONAL, 'Webroot path for certificate validation')
            ->addOption('standalone', 's', InputOption::VALUE_NONE, 'Use standalone mode (requires stopping web server)')
            ->setHelp('This command obtains and configures Let\'s Encrypt SSL certificates for the Workerman SSE server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $grav = Grav::instance();

        $domain = $input->getArgument('domain');
        $email = $input->getArgument('email');
        $webroot = $input->getOption('webroot');
        $standalone = $input->getOption('standalone');

        $io->title('Let\'s Encrypt SSL Setup for Workerman SSE');

        if (!$domain) {
            $domain = $io->askQuestion(new Question('Enter <yellow>Domain</yellow>', GRAV_ROOT));
        }

        if (!$email) {
            $email = $io->askQuestion(new Question('Enter <yellow>Email</yellow>'));
        }

        if (!$webroot && !$standalone) {
            $webroot = $io->askQuestion(new Question('Enter <yellow>Webroot</yellow>'));
        }

        // Validate inputs
        if (!filter_var("http://{$domain}", FILTER_VALIDATE_URL)) {
            $io->error('Invalid domain name format');
            return 1;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address format');
            return 1;
        }

        // Check prerequisites
        if (!$this->checkPrerequisites($io)) {
            return 1;
        }

        // Create SSL directory
        $sslDir = GRAV_ROOT . '/ssl';
        if (!is_dir($sslDir)) {
            mkdir($sslDir, 0755, true);
            $io->success("Created SSL directory: {$sslDir}");
        }

        // Obtain certificate
        if ($standalone) {
            $success = $this->obtainCertificateStandalone($domain, $email, $io);
        } else {
            $success = $this->obtainCertificateWebroot($domain, $email, $webroot, $io);
        }

        if (!$success) {
            return 1;
        }

        // Copy and configure certificates
        if (!$this->configureCertificates($domain, $sslDir, $io)) {
            return 1;
        }

        // Update Grav configuration
        $this->updateGravConfig($io);

        $this->displaySuccess($domain, $io);

        return 0;
    }

    private function checkPrerequisites(SymfonyStyle $io): bool
    {
        $io->section('Checking prerequisites');

        // Check if certbot is available
        $process = new Process(['which', 'certbot']);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error('certbot is not installed.');
            $io->note([
                'Please install certbot first:',
                '  Ubuntu/Debian: sudo apt-get install certbot',
                '  CentOS/RHEL: sudo yum install certbot',
                '  macOS: brew install certbot'
            ]);
            return false;
        }

        // Check if running as root (required for certbot)
        if (posix_getuid() !== 0) {
            $io->warning('This command should be run as root (sudo) for certbot to work properly.');
            if (!$io->confirm('Continue anyway?', true)) {
                return false;
            }
        }

        $io->success('Prerequisites check passed');
        return true;
    }

    private function obtainCertificateWebroot(string $domain, string $email, string $webroot, SymfonyStyle $io): bool
    {
        $io->section('Obtaining Let\'s Encrypt Certificate (Webroot Mode)');

        $process = new Process([
            'certbot', 'certonly',
            '--webroot',
            '-w', $webroot,
            '-d', $domain,
            '--email', $email,
            '--agree-tos',
            '--non-interactive'
        ]);

        $process->setTimeout(300); // 5 minutes timeout
        $io->note("Running: {$process->getCommandLine()}");

        $process->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });

        if ($process->isSuccessful()) {
            $io->success('Certificate obtained successfully!');
            return true;
        } else {
            $io->error('Failed to obtain certificate using webroot method.');
            if ($io->confirm('Try standalone method? (This will temporarily stop your web server)', false)) {
                return $this->obtainCertificateStandalone($domain, $email, $io);
            }
            return false;
        }
    }

    private function obtainCertificateStandalone(string $domain, string $email, SymfonyStyle $io): bool
    {
        $io->section('Obtaining Let\'s Encrypt Certificate (Standalone Mode)');
        $io->warning('This method requires stopping your web server temporarily.');

        if (!$io->confirm('Continue?', false)) {
            return false;
        }

        $process = new Process([
            'certbot', 'certonly',
            '--standalone',
            '-d', $domain,
            '--email', $email,
            '--agree-tos',
            '--non-interactive'
        ]);

        $process->setTimeout(300); // 5 minutes timeout
        $io->note("Running: {$process->getCommandLine()}");

        $process->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });

        return $process->isSuccessful();
    }

    private function configureCertificates(string $domain, string $sslDir, SymfonyStyle $io): bool
    {
        $io->section('Configuring Certificates');

        if (PHP_OS_FAMILY === 'Darwin') {
            $certbot_dir = '/opt/homebrew/etc/certbot/certs/live';// macOS detected
        } else {
            $certbot_dir = '/etc/letsencrypt/live';
        }
        $letsencryptDir = "{$certbot_dir}/{$domain}";
        $certFile = "{$sslDir}/workerman.crt";
        $keyFile = "{$sslDir}/workerman.key";

        // Copy certificates
        if (file_exists("{$letsencryptDir}/fullchain.pem")) {
            copy("{$letsencryptDir}/fullchain.pem", $certFile);
            $io->text("Copied certificate to: {$certFile}");
        } else {
            $io->error("Certificate file not found at {$letsencryptDir}/fullchain.pem");
            return false;
        }

        if (file_exists("{$letsencryptDir}/privkey.pem")) {
            copy("{$letsencryptDir}/privkey.pem", $keyFile);
            $io->text("Copied private key to: {$keyFile}");
        } else {
            $io->error("Private key file not found at {$letsencryptDir}/privkey.pem");
            return false;
        }

        // Set proper permissions
        chmod($certFile, 0644);
        chmod($keyFile, 0600);

        // Change ownership to web server user if possible
        $webUser = $this->detectWebUser();
        if ($webUser && posix_getuid() === 0) {
            $uid = posix_getpwnam($webUser)['uid'];
            $gid = posix_getpwnam($webUser)['gid'];
            chown($certFile, $uid);
            chgrp($certFile, $gid);
            chown($keyFile, $uid);
            chgrp($keyFile, $gid);
            $io->text("Changed ownership to: {$webUser}");
        }

        $io->success('Certificate files configured successfully');
        return true;
    }

    private function updateGravConfig(SymfonyStyle $io): void
    {
        $io->section('Updating Grav Configuration');

        $configFile = GRAV_ROOT . '/user/config/plugins/workerman-server.yaml';

        if (file_exists($configFile)) {
            $config = Yaml::parse($configFile);
        } else {
            $config = [];
        }

        // Update SSL configuration
        $config['ssl'] = [
            'enabled' => true,
            'cert_file' => 'ssl/workerman.crt',
            'key_file' => 'ssl/workerman.key',
            'verify_peer' => true
        ];

        // Save configuration
        $yamlContent = Yaml::dump($config, YAML_UTF8_ENCODING);
        file_put_contents($configFile, $yamlContent);
        
        $io->success("Updated plugin configuration: {$configFile}");
        $io->text('SSL has been enabled in the Workerman configuration');
    }
    
    private function displaySuccess(string $domain, SymfonyStyle $io): void
    {
        $io->section('Success!');
        $io->success('Let\'s Encrypt SSL has been configured for Workerman server');
        
        $io->note([
            'Next steps:',
            '1. Restart the Workerman server:',
            '   bin/plugin workerman-server restart',
            '',
            '2. Update your DNS to point ' . $domain . ' to this server',
            '',
            '3. Test the SSL connection:',
            '   curl https://' . $domain . ':8080/stats',
            '',
            '4. Set up automatic renewal:',
            '   bin/plugin workerman-server ssl-renewal-setup'
        ]);
        
        $io->table(
            ['Setting', 'Value'],
            [
                ['Domain', $domain],
                ['Certificate', 'ssl/workerman.crt'],
                ['Private Key', 'ssl/workerman.key'],
                ['Expires', date('Y-m-d', strtotime('+90 days')) . ' (auto-renewal recommended)']
            ]
        );
    }
    
    private function detectWebUser(): ?string
    {
        $possibleUsers = ['www-data', 'apache', 'nginx', 'httpd'];
        
        foreach ($possibleUsers as $user) {
            if (posix_getpwnam($user)) {
                return $user;
            }
        }
        
        return null;
    }
}