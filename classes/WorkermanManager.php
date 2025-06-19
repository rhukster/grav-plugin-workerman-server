<?php
namespace Grav\Plugin\WorkermanServer;

use Grav\Common\Grav;
use Symfony\Component\Process\Process;

/**
 * Manager class for Workerman daemon operations
 */
class WorkermanManager
{
    protected array $config;
    protected string $binPath;
    protected string $pidFile;
    protected string $logFile;
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        
        $gravRoot = defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();
        $this->binPath = $gravRoot . '/user/plugins/workerman-server/bin/workerman-server.php';
        $this->pidFile = $gravRoot . '/logs/workerman.pid';
        $this->logFile = $gravRoot . '/logs/workerman.log';
    }
    
    /**
     * Check if Workerman is available
     */
    public function isWorkermanAvailable(): bool
    {
        // Check if binary exists
        if (!file_exists($this->binPath)) {
            return false;
        }
        
        // Check if Workerman library is available
        return class_exists('\\Workerman\\Worker');
    }
    
    /**
     * Check if daemon is running
     */
    public function isRunning(): bool
    {
        if (!file_exists($this->pidFile)) {
            return false;
        }
        
        $pid = trim(file_get_contents($this->pidFile));
        if (empty($pid)) {
            return false;
        }
        
        // Check if process is running
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['tasklist', '/FI', "PID eq $pid"]);
            $process->run();
            return $process->isSuccessful() && strpos($process->getOutput(), $pid) !== false;
        } else {
            return posix_kill((int)$pid, 0);
        }
    }
    
    /**
     * Start the daemon
     */
    public function start(): array
    {
        if (!$this->isWorkermanAvailable()) {
            return ['success' => false, 'message' => 'Workerman is not available'];
        }
        
        if ($this->isRunning()) {
            return ['success' => false, 'message' => 'Daemon is already running'];
        }
        
        $process = new Process([PHP_BINARY, $this->binPath, 'start', '-d']);
        $process->setTimeout(30);
        $process->run();
        
        // Wait a moment for daemon to start
        sleep(2);
        
        if ($this->isRunning()) {
            return ['success' => true, 'message' => 'Daemon started successfully', 'output' => $process->getOutput()];
        } else {
            return ['success' => false, 'message' => 'Failed to start daemon', 'output' => $process->getOutput() . $process->getErrorOutput()];
        }
    }
    
    /**
     * Stop the daemon
     */
    public function stop(): array
    {
        if (!$this->isRunning()) {
            return ['success' => false, 'message' => 'Daemon is not running'];
        }
        
        $process = new Process([PHP_BINARY, $this->binPath, 'stop']);
        $process->setTimeout(30);
        $process->run();
        
        // Wait for daemon to stop
        sleep(2);
        
        if (!$this->isRunning()) {
            return ['success' => true, 'message' => 'Daemon stopped successfully', 'output' => $process->getOutput()];
        } else {
            return ['success' => false, 'message' => 'Failed to stop daemon', 'output' => $process->getOutput() . $process->getErrorOutput()];
        }
    }
    
    /**
     * Restart the daemon
     */
    public function restart(): array
    {
        $stopResult = $this->stop();
        if ($stopResult['success'] || !$this->isRunning()) {
            return $this->start();
        }
        
        return $stopResult;
    }
    
    /**
     * Reload daemon configuration
     */
    public function reload(): array
    {
        if (!$this->isRunning()) {
            return ['success' => false, 'message' => 'Daemon is not running'];
        }
        
        $process = new Process([PHP_BINARY, $this->binPath, 'reload']);
        $process->setTimeout(30);
        $process->run();
        
        return ['success' => true, 'message' => 'Daemon reloaded', 'output' => $process->getOutput()];
    }
    
    /**
     * Get daemon status
     */
    public function getStatus(): array
    {
        $status = [
            'running' => $this->isRunning(),
            'available' => $this->isWorkermanAvailable(),
            'pid' => null,
            'connections' => 0,
            'memory' => null,
            'uptime' => null
        ];
        
        if ($status['running'] && file_exists($this->pidFile)) {
            $status['pid'] = trim(file_get_contents($this->pidFile));
            
            // Try to get detailed status
            $process = new Process([PHP_BINARY, $this->binPath, 'status']);
            $process->setTimeout(10);
            $process->run();
            $output = $process->getOutput();
            $status['details'] = $output;
            
            // Parse workers and uptime from command output
            if ($output) {
                // Extract worker count: "1 workers       4 processes"
                if (preg_match('/(\d+)\s+workers\s+(\d+)\s+processes/', $output, $matches)) {
                    $status['workers'] = (int)$matches[2]; // Use process count as it's more accurate
                }
                
                // Extract uptime: "run 0 days 0 hours"
                if (preg_match('/run\s+(\d+)\s+days?\s+(\d+)\s+hours?/', $output, $matches)) {
                    $days = (int)$matches[1];
                    $hours = (int)$matches[2];
                    $status['uptime'] = $days > 0 ? "{$days}d {$hours}h" : "{$hours}h";
                }
                
                // Extract start time: "start time:2025-06-19 20:56:36"
                if (preg_match('/start time:(\S+\s+\S+)/', $output, $matches)) {
                    $status['start_time'] = $matches[1];
                }
            }
            
            // Try to get stats from the server (if available)
            $statsUrl = "http://{$this->config['host']}:{$this->config['port']}/stats";
            $context = stream_context_create(['http' => ['timeout' => 2]]);
            $stats = @file_get_contents($statsUrl, false, $context);
            
            if ($stats) {
                $statsData = json_decode($stats, true);
                if ($statsData) {
                    $status['connections'] = $statsData['total_connections'] ?? 0;
                    $status['handlers'] = $statsData['handlers'] ?? [];
                }
            }
        }
        
        return $status;
    }
    
    /**
     * Get connections information
     */
    public function getConnections(): array
    {
        if (!$this->isRunning()) {
            return ['success' => false, 'message' => 'Daemon is not running'];
        }
        
        $process = new Process([PHP_BINARY, $this->binPath, 'connections']);
        $process->setTimeout(10);
        $process->run();
        
        return ['success' => true, 'output' => $process->getOutput()];
    }
    
    /**
     * Generate systemd service file
     */
    public function generateSystemdService(): array
    {
        $grav = Grav::instance();
        $user = get_current_user();
        $gravRoot = GRAV_ROOT;
        $phpBin = PHP_BINARY;
        
        $service = <<<SERVICE
[Unit]
Description=Workerman SSE Server for Grav
After=network.target

[Service]
Type=forking
User={$user}
Group={$user}
WorkingDirectory={$gravRoot}
ExecStart={$phpBin} {$this->binPath} start -d
ExecReload={$phpBin} {$this->binPath} reload
ExecStop={$phpBin} {$this->binPath} stop
Restart=on-failure
RestartSec=5
StandardOutput=append:{$this->logFile}
StandardError=append:{$this->logFile}

[Install]
WantedBy=multi-user.target
SERVICE;

        $filename = '/tmp/workerman-server.service';
        file_put_contents($filename, $service);
        
        return [
            'success' => true,
            'message' => "Service file generated at: {$filename}",
            'filename' => $filename,
            'content' => $service
        ];
    }
    
    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}