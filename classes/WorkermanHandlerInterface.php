<?php
namespace Grav\Plugin\WorkermanServer;

/**
 * Interface for Workerman handlers
 * 
 * Plugins must implement this interface to register handlers with the Workerman server
 */
interface WorkermanHandlerInterface
{
    /**
     * Get the paths this handler should watch for changes
     * 
     * @param string $route The page route being watched
     * @return array Array of file paths to watch
     */
    public function getWatchPaths(string $route): array;
    
    /**
     * Check for updates on the watched paths
     * 
     * @param string $route The page route being checked
     * @param int $lastModified Last known modification time
     * @return array|null Update data if changes detected, null otherwise
     */
    public function checkForUpdates(string $route, int $lastModified): ?array;
    
    /**
     * Get the event type this handler emits
     * 
     * @return string Event type name (e.g., 'comments', 'pages', 'media')
     */
    public function getEventType(): string;
    
    /**
     * Handle a client event/message
     * 
     * @param string $event Event name
     * @param array $data Event data
     * @param string $route Page route
     * @return array|null Response data or null
     */
    public function handleClientEvent(string $event, array $data, string $route): ?array;
    
    /**
     * Get handler configuration
     * 
     * @return array
     */
    public function getConfig(): array;
}