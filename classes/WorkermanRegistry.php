<?php
namespace Grav\Plugin\WorkermanServer;

/**
 * Registry for Workerman handlers
 * 
 * Allows other plugins to register their handlers for real-time functionality
 */
class WorkermanRegistry
{
    /** @var array Registered handlers */
    protected array $handlers = [];
    
    /** @var array Handler instances cache */
    protected array $instances = [];
    
    /**
     * Register a handler
     * 
     * @param string $name Unique handler name
     * @param string $class Handler class name
     * @param array $config Optional handler configuration
     * @return void
     * @throws \InvalidArgumentException If handler already registered
     */
    public function registerHandler(string $name, string $class, array $config = []): void
    {
        if (isset($this->handlers[$name])) {
            throw new \InvalidArgumentException("Handler '{$name}' is already registered");
        }
        
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Handler class '{$class}' does not exist");
        }
        
        if (!in_array(WorkermanHandlerInterface::class, class_implements($class))) {
            throw new \InvalidArgumentException("Handler class must implement WorkermanHandlerInterface");
        }
        
        $this->handlers[$name] = [
            'class' => $class,
            'config' => $config
        ];
    }
    
    /**
     * Unregister a handler
     * 
     * @param string $name Handler name
     * @return bool True if handler was unregistered
     */
    public function unregisterHandler(string $name): bool
    {
        if (isset($this->handlers[$name])) {
            unset($this->handlers[$name]);
            unset($this->instances[$name]);
            return true;
        }
        return false;
    }
    
    /**
     * Get a handler instance
     * 
     * @param string $name Handler name
     * @return WorkermanHandlerInterface|null
     */
    public function getHandler(string $name): ?WorkermanHandlerInterface
    {
        if (!isset($this->handlers[$name])) {
            return null;
        }
        
        if (!isset($this->instances[$name])) {
            $handler = $this->handlers[$name];
            $class = $handler['class'];
            $this->instances[$name] = new $class($handler['config']);
        }
        
        return $this->instances[$name];
    }
    
    /**
     * Get all registered handlers
     * 
     * @return array
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }
    
    /**
     * Check if a handler is registered
     * 
     * @param string $name Handler name
     * @return bool
     */
    public function hasHandler(string $name): bool
    {
        return isset($this->handlers[$name]);
    }
    
    /**
     * Get all handler instances
     * 
     * @return WorkermanHandlerInterface[]
     */
    public function getAllHandlerInstances(): array
    {
        $instances = [];
        
        foreach ($this->handlers as $name => $handler) {
            $instances[$name] = $this->getHandler($name);
        }
        
        return $instances;
    }
}