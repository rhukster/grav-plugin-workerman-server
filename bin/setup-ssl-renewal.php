#!/usr/bin/env php
<?php
/**
 * SSL Certificate Renewal Setup for Workerman SSE
 * 
 * This script sets up automatic renewal of Let's Encrypt certificates
 * and ensures the Workerman SSE server uses the updated certificates.
 */

// Ensure script is run from Grav root
if (!file_exists('user/plugins/workerman-server')) {
    echo "Error: This script must be run from the Grav root directory.\n";
    exit(1);
}

// Include Grav
define('GRAV_CLI', true);
define('GRAV_ROOT', __DIR__ . '/../../../../');

class SSLRenewalSetup 
{
    private $gravRoot;
    private $domain;
    private $configFile;
    
    public function __construct()
    {
        $this->gravRoot = GRAV_ROOT;
        $this->configFile = $this->gravRoot . 'user/config/plugins/workerman-server.yaml';
    }
    
    public function run()
    {
        echo "=== SSL Certificate Renewal Setup ===\n\n";
        
        // Get domain from config
        if (!$this->loadDomain()) {
            exit(1);
        }
        
        echo "Setting up automatic SSL renewal for domain: {$this->domain}\n\n";
        
        // Create renewal script
        $this->createRenewalScript();
        
        // Set up cron job
        $this->setupCronJob();
        
        // Create systemd timer (alternative)
        $this->createSystemdTimer();
        
        $this->displayInstructions();
    }
    
    private function loadDomain()
    {
        if (!file_exists($this->configFile)) {
            echo "Error: Plugin configuration file not found.\n";
            echo "Please run setup-letsencrypt.php first.\n";
            return false;
        }
        
        $config = yaml_parse_file($this->configFile);
        
        if (!isset($config['ssl']['enabled']) || !$config['ssl']['enabled']) {
            echo "Error: SSL is not enabled in plugin configuration.\n";
            echo "Please run setup-letsencrypt.php first.\n";
            return false;
        }
        
        // Try to detect domain from certificate
        $certFile = $this->gravRoot . ($config['ssl']['cert_file'] ?? 'ssl/workerman.crt');
        
        if (file_exists($certFile)) {
            $certInfo = openssl_x509_parse(file_get_contents($certFile));
            if (isset($certInfo['subject']['CN'])) {
                $this->domain = $certInfo['subject']['CN'];
                return true;
            }
        }
        
        // Ask user for domain
        echo "Could not detect domain from certificate.\n";
        do {
            $this->domain = $this->ask("Enter your domain name: ");
        } while (empty($this->domain));
        
        return true;
    }
    
    private function createRenewalScript()
    {
        $scriptPath = $this->gravRoot . 'user/plugins/workerman-server/bin/renew-ssl.sh';
        
        $script = <<<'SCRIPT'
#!/bin/bash
#
# SSL Certificate Renewal Script for Workerman SSE
# This script renews Let's Encrypt certificates and restarts Workerman
#

set -e

# Configuration
DOMAIN="{DOMAIN}"
GRAV_ROOT="{GRAV_ROOT}"
SSL_DIR="${GRAV_ROOT}/ssl"
LOG_FILE="${GRAV_ROOT}/logs/ssl-renewal.log"

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

# Function to log messages
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "Starting SSL certificate renewal for domain: $DOMAIN"

# Check if renewal is needed (certificates expire in less than 30 days)
if ! certbot certificates 2>/dev/null | grep -A 10 "$DOMAIN" | grep -q "VALID.*([2-9][0-9]|[1-9][0-9][0-9]) days"; then
    log "Certificate renewal needed"
    
    # Renew certificate
    if certbot renew --domain "$DOMAIN" --quiet; then
        log "Certificate renewed successfully"
        
        # Copy new certificates
        if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
            cp "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "$SSL_DIR/workerman.crt"
            cp "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$SSL_DIR/workerman.key"
            
            # Set proper permissions
            chmod 644 "$SSL_DIR/workerman.crt"
            chmod 600 "$SSL_DIR/workerman.key"
            
            # Detect and set web user ownership
            for user in www-data apache nginx httpd; do
                if id "$user" &>/dev/null; then
                    chown "$user:$user" "$SSL_DIR/workerman.crt" "$SSL_DIR/workerman.key"
                    log "Set ownership to $user"
                    break
                fi
            done
            
            log "Certificate files updated"
            
            # Restart Workerman server
            cd "$GRAV_ROOT"
            if php user/plugins/workerman-server/bin/workerman-server.php status | grep -q "running"; then
                log "Restarting Workerman server"
                php user/plugins/workerman-server/bin/workerman-server.php restart
                log "Workerman server restarted"
            else
                log "Workerman server not running, skipping restart"
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

        // Replace placeholders
        $script = str_replace('{DOMAIN}', $this->domain, $script);
        $script = str_replace('{GRAV_ROOT}', $this->gravRoot, $script);
        
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);
        
        echo "Created renewal script: {$scriptPath}\n";
    }
    
    private function setupCronJob()
    {
        $scriptPath = $this->gravRoot . 'user/plugins/workerman-server/bin/renew-ssl.sh';
        $cronEntry = "0 3 * * 1 root {$scriptPath} >/dev/null 2>&1";
        $cronFile = '/etc/cron.d/workerman-server-ssl-renewal';
        
        echo "\n=== Cron Job Setup ===\n";
        
        if (posix_getuid() === 0) {
            // Running as root, can install cron job directly
            file_put_contents($cronFile, $cronEntry . "\n");
            echo "Installed cron job: {$cronFile}\n";
            echo "SSL certificates will be checked weekly (Mondays at 3 AM)\n";
        } else {
            echo "To set up automatic renewal, add this to your system crontab:\n";
            echo "  sudo crontab -e\n\n";
            echo "Add this line:\n";
            echo "  {$cronEntry}\n\n";
            echo "Or create the file manually:\n";
            echo "  sudo tee {$cronFile} <<< '{$cronEntry}'\n";
        }
    }
    
    private function createSystemdTimer()
    {
        $timerContent = <<<TIMER
[Unit]
Description=SSL Certificate Renewal for Comments Pro
Requires=comments-pro-ssl-renewal.service

[Timer]
OnCalendar=weekly
Persistent=true

[Install]
WantedBy=timers.target
TIMER;

        $serviceContent = <<<SERVICE
[Unit]
Description=SSL Certificate Renewal for Comments Pro
After=network.target

[Service]
Type=oneshot
ExecStart={$this->gravRoot}user/plugins/workerman-server/bin/renew-ssl.sh
User=root
SERVICE;

        $timerPath = '/tmp/workerman-server-ssl-renewal.timer';
        $servicePath = '/tmp/workerman-server-ssl-renewal.service';
        
        file_put_contents($timerPath, $timerContent);
        file_put_contents($servicePath, $serviceContent);
        
        echo "\n=== Systemd Timer (Alternative to Cron) ===\n";
        echo "Created systemd service files:\n";
        echo "  {$servicePath}\n";
        echo "  {$timerPath}\n\n";
        
        echo "To install systemd timer:\n";
        echo "  sudo cp {$servicePath} /etc/systemd/system/\n";
        echo "  sudo cp {$timerPath} /etc/systemd/system/\n";
        echo "  sudo systemctl daemon-reload\n";
        echo "  sudo systemctl enable comments-pro-ssl-renewal.timer\n";
        echo "  sudo systemctl start comments-pro-ssl-renewal.timer\n";
    }
    
    private function displayInstructions()
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SSL RENEWAL SETUP COMPLETE\n";
        echo str_repeat("=", 60) . "\n\n";
        
        echo "Automatic renewal has been configured for domain: {$this->domain}\n\n";
        
        echo "Files created:\n";
        echo "  • Renewal script: user/plugins/workerman-server/bin/renew-ssl.sh\n";
        echo "  • Systemd files: /tmp/workerman-server-ssl-renewal.*\n\n";
        
        echo "Manual renewal test:\n";
        echo "  sudo {$this->gravRoot}user/plugins/workerman-server/bin/renew-ssl.sh\n\n";
        
        echo "Check renewal status:\n";
        echo "  sudo certbot certificates\n\n";
        
        echo "View renewal logs:\n";
        echo "  tail -f logs/ssl-renewal.log\n\n";
        
        echo "IMPORTANT NOTES:\n";
        echo "• Certificates are checked weekly and renewed when < 30 days remain\n";
        echo "• Workerman SSE server is automatically restarted after renewal\n";
        echo "• Check logs regularly to ensure renewals are working\n";
        echo "• Let's Encrypt certificates expire every 90 days\n\n";
        
        echo "Your SSL certificates will now renew automatically!\n";
    }
    
    private function ask($question)
    {
        echo $question;
        $handle = fopen("php://stdin", "r");
        $input = trim(fgets($handle));
        fclose($handle);
        return $input;
    }
}

// Run the setup
$setup = new SSLRenewalSetup();
$setup->run();