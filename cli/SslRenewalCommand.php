<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * CLI command for setting up automatic SSL certificate renewal
 */
class SslRenewalCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ssl-renewal-setup')
            ->setDescription('Set up automatic SSL certificate renewal for Workerman server')
            ->addOption('cron', 'c', InputOption::VALUE_NONE, 'Set up cron job for renewal')
            ->addOption('systemd', 's', InputOption::VALUE_NONE, 'Set up systemd timer for renewal')
            ->setHelp('This command sets up automatic renewal of Let\'s Encrypt certificates for the Workerman SSE server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $grav = Grav::instance();
        
        $setupCron = $input->getOption('cron');
        $setupSystemd = $input->getOption('systemd');
        
        $io->title('SSL Certificate Renewal Setup for Workerman SSE');
        
        // Get domain from existing configuration
        $domain = $this->detectDomain($io);
        if (!$domain) {
            return 1;
        }
        
        $io->text("Setting up automatic SSL renewal for domain: {$domain}");
        
        // Create renewal script
        $this->createRenewalScript($domain, $io);
        
        // Set up cron job if requested
        if ($setupCron || (!$setupCron && !$setupSystemd)) {
            $this->setupCronJob($io);
        }
        
        // Set up systemd timer if requested
        if ($setupSystemd) {
            $this->createSystemdTimer($io);
        }
        
        $this->displayInstructions($domain, $io);
        
        return 0;
    }
    
    private function detectDomain(SymfonyStyle $io): ?string
    {
        $configFile = GRAV_ROOT . '/user/config/plugins/workerman-server.yaml';
        
        if (!file_exists($configFile)) {
            $io->error('Workerman server configuration file not found.');
            $io->note('Please run "bin/grav workerman:ssl:setup" first.');
            return null;
        }
        
        $config = yaml_parse_file($configFile);
        
        if (!isset($config['ssl']['enabled']) || !$config['ssl']['enabled']) {
            $io->error('SSL is not enabled in workerman-server configuration.');
            $io->note('Please run "bin/grav workerman:ssl:setup" first.');
            return null;
        }
        
        // Try to detect domain from certificate
        $certFile = GRAV_ROOT . '/ssl/workerman.crt';
        
        if (file_exists($certFile)) {
            $certInfo = openssl_x509_parse(file_get_contents($certFile));
            if (isset($certInfo['subject']['CN'])) {
                return $certInfo['subject']['CN'];
            }
        }
        
        // Ask user for domain
        $io->warning('Could not detect domain from certificate.');
        return $io->ask('Enter your domain name');
    }
    
    private function createRenewalScript(string $domain, SymfonyStyle $io): void
    {
        $io->section('Creating renewal script');
        
        $scriptPath = GRAV_ROOT . '/user/plugins/workerman-server/bin/renew-ssl.sh';
        $gravRoot = GRAV_ROOT;
        $phpBinary = PHP_BINARY;
        
        $script = <<<SCRIPT
#!/bin/bash
#
# SSL Certificate Renewal Script for Workerman SSE
# This script renews Let's Encrypt certificates and restarts Workerman
#

set -e

# Configuration
DOMAIN="{$domain}"
GRAV_ROOT="{$gravRoot}"
SSL_DIR="\${GRAV_ROOT}/ssl"
LOG_FILE="\${GRAV_ROOT}/logs/ssl-renewal.log"
PHP_BINARY="{$phpBinary}"

# Ensure log directory exists
mkdir -p "\$(dirname "\$LOG_FILE")"

# Function to log messages
log() {
    echo "[\$(date '+%Y-%m-%d %H:%M:%S')] \$1" | tee -a "\$LOG_FILE"
}

log "Starting SSL certificate renewal for domain: \$DOMAIN"

# Check if renewal is needed (certificates expire in less than 30 days)
if ! certbot certificates 2>/dev/null | grep -A 10 "\$DOMAIN" | grep -q "VALID.*([2-9][0-9]|[1-9][0-9][0-9]) days"; then
    log "Certificate renewal needed"
    
    # Renew certificate
    if certbot renew --domain "\$DOMAIN" --quiet; then
        log "Certificate renewed successfully"
        
        # Copy new certificates
        if [ -f "/etc/letsencrypt/live/\$DOMAIN/fullchain.pem" ]; then
            cp "/etc/letsencrypt/live/\$DOMAIN/fullchain.pem" "\$SSL_DIR/workerman.crt"
            cp "/etc/letsencrypt/live/\$DOMAIN/privkey.pem" "\$SSL_DIR/workerman.key"
            
            # Set proper permissions
            chmod 644 "\$SSL_DIR/workerman.crt"
            chmod 600 "\$SSL_DIR/workerman.key"
            
            # Detect and set web user ownership
            for user in www-data apache nginx httpd; do
                if id "\$user" &>/dev/null; then
                    chown "\$user:\$user" "\$SSL_DIR/workerman.crt" "\$SSL_DIR/workerman.key"
                    log "Set ownership to \$user"
                    break
                fi
            done
            
            log "Certificate files updated"
            
            # Restart Workerman SSE server using plugin CLI
            cd "\$GRAV_ROOT"
            if "\$PHP_BINARY" bin/plugin workerman-server status | grep -q "running"; then
                log "Restarting Workerman SSE server"
                "\$PHP_BINARY" bin/plugin workerman-server restart
                log "Workerman SSE server restarted"
            else
                log "Workerman SSE server not running, skipping restart"
            fi
            
        else
            log "ERROR: Renewed certificate files not found"
            exit 1
        fi
    else
        log "ERROR: Certificate renewal failed"
        exit 1
    fi
else
    log "Certificate renewal not needed (more than 30 days remaining)"
fi

log "SSL renewal process completed"
SCRIPT;

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);
        
        $io->success("Created renewal script: {$scriptPath}");
    }
    
    private function setupCronJob(SymfonyStyle $io): void
    {
        $io->section('Setting up cron job');
        
        $scriptPath = GRAV_ROOT . '/user/plugins/workerman-server/bin/renew-ssl.sh';
        $cronEntry = "0 3 * * 1 root {$scriptPath} >/dev/null 2>&1";
        $cronFile = '/etc/cron.d/workerman-ssl-renewal';
        
        if (posix_getuid() === 0) {
            // Running as root, can install cron job directly
            file_put_contents($cronFile, $cronEntry . "\n");
            $io->success("Installed cron job: {$cronFile}");
            $io->text('SSL certificates will be checked weekly (Mondays at 3 AM)');
        } else {
            $io->note([
                'To set up automatic renewal, add this to your system crontab:',
                '  sudo crontab -e',
                '',
                'Add this line:',
                "  {$cronEntry}",
                '',
                'Or create the file manually:',
                "  sudo tee {$cronFile} <<< '{$cronEntry}'"
            ]);
        }
    }
    
    private function createSystemdTimer(SymfonyStyle $io): void
    {
        $io->section('Creating systemd timer');
        
        $gravRoot = GRAV_ROOT;
        
        $timerContent = <<<TIMER
[Unit]
Description=SSL Certificate Renewal for Workerman Server
Requires=workerman-ssl-renewal.service

[Timer]
OnCalendar=weekly
Persistent=true

[Install]
WantedBy=timers.target
TIMER;

        $serviceContent = <<<SERVICE
[Unit]
Description=SSL Certificate Renewal for Workerman Server
After=network.target

[Service]
Type=oneshot
ExecStart={$gravRoot}/user/plugins/workerman-server/bin/renew-ssl.sh
User=root
SERVICE;

        $timerPath = '/tmp/workerman-ssl-renewal.timer';
        $servicePath = '/tmp/workerman-ssl-renewal.service';
        
        file_put_contents($timerPath, $timerContent);
        file_put_contents($servicePath, $serviceContent);
        
        $io->success('Created systemd service files');
        $io->listing([
            $servicePath,
            $timerPath
        ]);
        
        $io->note([
            'To install systemd timer:',
            "  sudo cp {$servicePath} /etc/systemd/system/",
            "  sudo cp {$timerPath} /etc/systemd/system/",
            '  sudo systemctl daemon-reload',
            '  sudo systemctl enable workerman-ssl-renewal.timer',
            '  sudo systemctl start workerman-ssl-renewal.timer'
        ]);
    }
    
    private function displayInstructions(string $domain, SymfonyStyle $io): void
    {
        $io->section('Setup Complete');
        $io->success("Automatic renewal has been configured for domain: {$domain}");
        
        $io->table(
            ['File/Setting', 'Value'],
            [
                ['Renewal script', 'user/plugins/workerman-server/bin/renew-ssl.sh'],
                ['Systemd files', '/tmp/workerman-ssl-renewal.*'],
                ['Check schedule', 'Weekly (Mondays at 3 AM)'],
                ['Renewal threshold', '< 30 days remaining']
            ]
        );
        
        $io->note([
            'Manual commands:',
            '• Test renewal: sudo ' . GRAV_ROOT . '/user/plugins/workerman-server/bin/renew-ssl.sh',
            '• Check status: sudo certbot certificates',
            '• View logs: bin/plugin workerman-server logs',
            '',
            'Important notes:',
            '• Certificates are checked weekly and renewed when < 30 days remain',
            '• Workerman server is automatically restarted after renewal',
            '• Check logs regularly to ensure renewals are working',
            '• Let\'s Encrypt certificates expire every 90 days'
        ]);
    }
}