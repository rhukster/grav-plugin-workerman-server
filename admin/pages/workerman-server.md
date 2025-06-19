# Workerman Server Management

## Overview

Manage the Workerman-based Server-Sent Events daemon for real-time communication in Grav.

The Workerman daemon provides:
- **Non-blocking SSE connections** - No server lockups
- **High performance** - Multiple worker processes
- **Better resource management** - Dedicated memory per worker
- **Production ready** - Battle-tested Workerman foundation

## Getting Started

1. **Install Workerman**: Run `composer install` in the plugin directory to install dependencies
2. **Configure Settings**: Use the Workerman SSE tab in plugin configuration
3. **Start Daemon**: Use the CLI commands below to start the daemon
4. **Enable in Config**: Set `workerman.enabled: true` in plugin configuration

## Current Status

The daemon status will be displayed here when the plugin is fully integrated.

## CLI Commands

All commands should be run from the Grav root directory:

### Start Daemon
```bash
bin/plugin workerman-server start -d
```

### Stop Daemon
```bash
bin/plugin workerman-server stop
```

### Check Status
```bash
bin/plugin workerman-server status
```

### Cache Handlers
```bash
bin/plugin workerman-server cache
```

### Restart Daemon
```bash
bin/plugin workerman-server restart -d
```

## Production Deployment

For production environments, consider using systemd to manage the daemon:

1. Generate systemd service file (this will be available via admin interface)
2. Install the service: `sudo systemctl enable workerman-server`
3. Start the service: `sudo systemctl start workerman-server`

## Troubleshooting

### Common Issues

1. **Port already in use**: Change the port in configuration
2. **Permission denied**: Ensure the user has permissions to bind to the port
3. **Workerman not found**: Run `composer install` in the plugin directory
4. **Memory issues**: Reduce worker count or connection limits

### Log Files

Daemon logs are stored in `logs/workerman.log` within the plugin directory.

### Performance Tuning

- **Worker Count**: Increase for more concurrent connections
- **Check Interval**: Reduce for faster updates, increase for less CPU usage
- **Connection Timeout**: Adjust based on your server resources
- **Max Connections per IP**: Prevent abuse while allowing legitimate users