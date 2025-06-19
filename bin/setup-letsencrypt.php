#!/usr/bin/env php
<?php
/**
 * Let's Encrypt SSL Certificate Setup for Workerman SSE
 * 
 * This script automates the process of obtaining and configuring
 * Let's Encrypt SSL certificates for the Workerman SSE server.
 */

// Ensure script is run from Grav root
if (!file_exists('user/plugins/workerman-server')) {
    echo "Error: This script must be run from the Grav root directory.\n";
    exit(1);
}

// Include Grav
define('GRAV_CLI', true);
define('GRAV_ROOT', __DIR__ . '/../../../../');

require_once GRAV_ROOT . 'vendor/autoload.php';

use Grav\Common\Grav;
use Grav\Common\Config\Config;

class LetsEncryptSetup 
{
    private $domain;
    private $email;
    private $webroot;
    private $sslDir;
    private $config;
    private $gravRoot;
    
    public function __construct()
    {
        $this->gravRoot = GRAV_ROOT;
        $this->sslDir = $this->gravRoot . 'ssl';
        $this->webroot = $this->gravRoot . 'user/pages'; // Default webroot
    }
    
    public function run()
    {
        echo "=== Let's Encrypt SSL Setup for Workerman SSE ===\n\n";
        
        // Check prerequisites
        if (!$this->checkPrerequisites()) {
            exit(1);
        }
        
        // Get user input
        $this->getUserInput();
        
        // Create SSL directory
        $this->createSSLDirectory();
        
        // Get certificate
        if ($this->obtainCertificate()) {
            $this->copyAndConfigureCertificates();
            $this->updateGravConfig();
            $this->displaySuccess();
        } else {
            echo "Failed to obtain Let's Encrypt certificate.\n";
            exit(1);
        }
    }
    
    private function checkPrerequisites()
    {
        echo "Checking prerequisites...\n";
        
        // Check if certbot is installed
        $output = shell_exec('which certbot 2>/dev/null');
        if (empty(trim($output))) {
            echo "Error: certbot is not installed.\n";
            echo "Please install certbot first:\n";
            echo "  Ubuntu/Debian: sudo apt-get install certbot\n";
            echo "  CentOS/RHEL: sudo yum install certbot\n";
            echo "  macOS: brew install certbot\n";
            return false;
        }
        
        // Check if running as root (required for certbot)
        if (posix_getuid() !== 0) {
            echo "Warning: This script should be run as root (sudo) for certbot to work properly.\n";
            echo "You may need to run: sudo php " . __FILE__ . "\n";
            
            $response = $this->askYesNo("Continue anyway? (y/n): ");
            if (!$response) {
                return false;
            }
        }
        
        echo "Prerequisites check passed.\n\n";
        return true;
    }
    
    private function getUserInput()
    {
        echo "=== Configuration ===\n";
        
        // Get domain
        do {
            $this->domain = $this->ask("Enter your domain name (e.g., example.com): ");
            if (empty($this->domain)) {
                echo "Domain name is required.\n";
            } elseif (!filter_var("http://" . $this->domain, FILTER_VALIDATE_URL)) {
                echo "Invalid domain name format.\n";
                $this->domain = '';
            }
        } while (empty($this->domain));
        
        // Get email
        do {
            $this->email = $this->ask("Enter your email address for Let's Encrypt: ");
            if (empty($this->email)) {
                echo "Email address is required.\n";
            } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
                echo "Invalid email address format.\n";
                $this->email = '';
            }
        } while (empty($this->email));
        
        // Get webroot
        $defaultWebroot = $this->gravRoot;
        $this->webroot = $this->ask("Enter webroot path (default: {$defaultWebroot}): ", $defaultWebroot);
        
        if (!is_dir($this->webroot)) {
            echo "Error: Webroot directory does not exist: {$this->webroot}\n";
            exit(1);
        }
        
        echo "\nConfiguration:\n";
        echo "  Domain: {$this->domain}\n";
        echo "  Email: {$this->email}\n";
        echo "  Webroot: {$this->webroot}\n\n";
        
        if (!$this->askYesNo("Is this correct? (y/n): ")) {
            echo "Aborted.\n";
            exit(0);
        }
    }
    
    private function createSSLDirectory()
    {
        if (!is_dir($this->sslDir)) {
            mkdir($this->sslDir, 0755, true);
            echo "Created SSL directory: {$this->sslDir}\n";
        }
    }
    
    private function obtainCertificate()
    {
        echo "\n=== Obtaining Let's Encrypt Certificate ===\n";
        
        // Build certbot command
        $cmd = sprintf(
            'certbot certonly --webroot -w "%s" -d "%s" --email "%s" --agree-tos --non-interactive',
            escapeshellarg($this->webroot),
            escapeshellarg($this->domain),
            escapeshellarg($this->email)
        );
        
        echo "Running: {$cmd}\n";
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        foreach ($output as $line) {
            echo "  {$line}\n";
        }
        
        if ($returnCode === 0) {
            echo "Certificate obtained successfully!\n";
            return true;
        } else {
            echo "Failed to obtain certificate. Return code: {$returnCode}\n";
            
            // Try alternative method with standalone mode if webroot failed
            if ($this->askYesNo("Try alternative standalone method? (This will temporarily stop your web server) (y/n): ")) {
                return $this->obtainCertificateStandalone();
            }
            
            return false;
        }
    }
    
    private function obtainCertificateStandalone()
    {
        echo "\n=== Trying Standalone Method ===\n";
        echo "Note: This method requires stopping your web server temporarily.\n";
        
        if (!$this->askYesNo("Continue? (y/n): ")) {
            return false;
        }
        
        // Build standalone certbot command
        $cmd = sprintf(
            'certbot certonly --standalone -d "%s" --email "%s" --agree-tos --non-interactive',
            escapeshellarg($this->domain),
            escapeshellarg($this->email)
        );
        
        echo "Running: {$cmd}\n";
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        foreach ($output as $line) {
            echo "  {$line}\n";
        }
        
        return $returnCode === 0;
    }
    
    private function copyAndConfigureCertificates()
    {
        echo "\n=== Configuring Certificates ===\n";
        
        $letsencryptDir = "/etc/letsencrypt/live/{$this->domain}";
        $certFile = "{$this->sslDir}/workerman.crt";
        $keyFile = "{$this->sslDir}/workerman.key";
        
        // Copy certificates
        if (file_exists("{$letsencryptDir}/fullchain.pem")) {
            copy("{$letsencryptDir}/fullchain.pem", $certFile);
            echo "Copied certificate to: {$certFile}\n";
        } else {
            echo "Error: Certificate file not found at {$letsencryptDir}/fullchain.pem\n";
            exit(1);
        }
        
        if (file_exists("{$letsencryptDir}/privkey.pem")) {
            copy("{$letsencryptDir}/privkey.pem", $keyFile);
            echo "Copied private key to: {$keyFile}\n";
        } else {
            echo "Error: Private key file not found at {$letsencryptDir}/privkey.pem\n";
            exit(1);
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
            echo "Changed ownership to: {$webUser}\n";
        }
        
        echo "Certificate files configured successfully.\n";
    }
    
    private function updateGravConfig()
    {
        echo "\n=== Updating Grav Configuration ===\n";
        
        $configFile = $this->gravRoot . 'user/config/plugins/workerman-server.yaml';
        
        if (file_exists($configFile)) {
            $config = yaml_parse_file($configFile);
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
        $yamlContent = yaml_emit($config, YAML_UTF8_ENCODING);
        file_put_contents($configFile, $yamlContent);
        
        echo "Updated plugin configuration: {$configFile}\n";
        echo "SSL has been enabled in the Workerman Server configuration.\n";
    }
    
    private function displaySuccess()
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SUCCESS! Let's Encrypt SSL has been configured.\n";
        echo str_repeat("=", 60) . "\n\n";
        
        echo "Next steps:\n";
        echo "1. Restart the Workerman server:\n";
        echo "   php user/plugins/workerman-server/bin/workerman-server.php restart\n\n";
        
        echo "2. Update your DNS to point {$this->domain} to this server\n\n";
        
        echo "3. Test the SSL connection:\n";
        echo "   curl https://{$this->domain}:8080/stats\n\n";
        
        echo "4. Set up automatic renewal (recommended):\n";
        echo "   php user/plugins/workerman-server/bin/setup-ssl-renewal.php\n\n";
        
        echo "Certificate details:\n";
        echo "  Domain: {$this->domain}\n";
        echo "  Certificate: ssl/workerman.crt\n";
        echo "  Private Key: ssl/workerman.key\n";
        echo "  Expires: " . date('Y-m-d', strtotime('+90 days')) . " (auto-renewal recommended)\n\n";
        
        echo "The Workerman SSE server will now use valid SSL certificates.\n";
        echo "No browser warnings will appear for SSL connections.\n";
    }
    
    private function detectWebUser()
    {
        $possibleUsers = ['www-data', 'apache', 'nginx', 'httpd'];
        
        foreach ($possibleUsers as $user) {
            if (posix_getpwnam($user)) {
                return $user;
            }
        }
        
        return null;
    }
    
    private function ask($question, $default = '')
    {
        echo $question;
        $handle = fopen("php://stdin", "r");
        $input = trim(fgets($handle));
        fclose($handle);
        
        return empty($input) ? $default : $input;
    }
    
    private function askYesNo($question)
    {
        do {
            $response = strtolower($this->ask($question));
        } while (!in_array($response, ['y', 'yes', 'n', 'no']));
        
        return in_array($response, ['y', 'yes']);
    }
}

// Run the setup
$setup = new LetsEncryptSetup();
$setup->run();