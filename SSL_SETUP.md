# Workerman SSE SSL/HTTPS Setup Guide

This guide explains how to configure SSL/HTTPS for the Workerman SSE server to resolve mixed content security issues when your Grav site runs over HTTPS.

## Problem: Mixed Content Security Error

When your Grav site is served over HTTPS (e.g., `https://yourdomain.com`), browsers block HTTP connections to the Workerman SSE server (`http://127.0.0.1:8080`), resulting in errors like:

```
[blocked] The page at https://yourdomain.com requested insecure content from http://127.0.0.1:8080/sse/path
EventSource cannot load http://127.0.0.1:8080/sse/path due to access control checks.
```

## Solution: Enable SSL for Workerman

### Method 1: Self-Signed Certificate (Development/Local)

#### Step 1: Generate Self-Signed Certificate

```bash
# Navigate to Grav root directory
cd /path/to/your/grav/site

# Create SSL directory
mkdir -p ssl

# Generate private key
openssl genrsa -out ssl/workerman.key 2048

# Generate self-signed certificate (valid for 1 year)
openssl req -new -x509 -key ssl/workerman.key -out ssl/workerman.crt -days 365 -subj "/C=US/ST=State/L=City/O=Organization/CN=127.0.0.1"
```

#### Step 2: Configure Workerman SSL

Edit `user/config/plugins/comments-pro.yaml`:

```yaml
workerman:
  enabled: true
  host: 127.0.0.1
  port: 8080
  ssl:
    enabled: true
    cert_file: ssl/workerman.crt
    key_file: ssl/workerman.key
    verify_peer: false
```

Or configure via the Admin Panel:
1. Go to **Plugins** → **Comments Pro**
2. Navigate to the **Workerman SSE** tab
3. Scroll to **SSL/HTTPS Configuration**
4. Enable **SSL/HTTPS**
5. Set **SSL Certificate File**: `ssl/workerman.crt`
6. Set **SSL Private Key File**: `ssl/workerman.key`
7. Disable **Verify SSL Peers** (for self-signed certificates)
8. Save configuration

#### Step 3: Restart Workerman Server

```bash
# Stop existing daemon
php user/plugins/comments-pro/bin/workerman-sse-server.php stop

# Start with SSL enabled
php user/plugins/comments-pro/bin/workerman-sse-server.php start -d
```

#### Step 4: Accept Self-Signed Certificate in Browser

1. Navigate to `https://127.0.0.1:8080` in your browser
2. You'll see a security warning about the self-signed certificate
3. Click "Advanced" → "Proceed to 127.0.0.1 (unsafe)" or similar
4. This allows the browser to accept the self-signed certificate for SSE connections

### Method 2: Let's Encrypt Certificate (Recommended)

Let's Encrypt provides free, valid SSL certificates that eliminate browser warnings completely.

#### Automated Setup (Recommended)

Use the automated setup script for a complete Let's Encrypt configuration:

```bash
# Run the automated Let's Encrypt setup
sudo php user/plugins/workerman-server/bin/setup-letsencrypt.php
```

This script will:
- Check prerequisites (certbot installation)
- Prompt for domain and email
- Obtain Let's Encrypt certificate
- Configure Workerman SSL settings
- Set proper file permissions

#### Manual Let's Encrypt Setup

If you prefer manual setup:

##### Step 1: Install Certbot

```bash
# Ubuntu/Debian
sudo apt-get update && sudo apt-get install certbot

# CentOS/RHEL 8
sudo dnf install certbot

# CentOS/RHEL 7
sudo yum install certbot

# macOS
brew install certbot
```

##### Step 2: Obtain Certificate

**Option A: Webroot method (recommended if web server is running)**
```bash
# Replace yourdomain.com with your actual domain
sudo certbot certonly --webroot -w /path/to/your/grav/root -d yourdomain.com --email your@email.com --agree-tos
```

**Option B: Standalone method (if no web server or different port)**
```bash
# This will temporarily start its own web server on port 80
sudo certbot certonly --standalone -d yourdomain.com --email your@email.com --agree-tos
```

##### Step 3: Copy Certificates

```bash
# Create SSL directory
mkdir -p ssl

# Copy certificates
sudo cp /etc/letsencrypt/live/yourdomain.com/fullchain.pem ssl/workerman.crt
sudo cp /etc/letsencrypt/live/yourdomain.com/privkey.pem ssl/workerman.key

# Set proper permissions
sudo chmod 644 ssl/workerman.crt
sudo chmod 600 ssl/workerman.key
sudo chown www-data:www-data ssl/workerman.*
```

#### Automatic Renewal Setup

Let's Encrypt certificates expire every 90 days. Set up automatic renewal:

```bash
# Run the renewal setup script
sudo php user/plugins/workerman-server/bin/setup-ssl-renewal.php
```

This will:
- Create renewal script with Workerman restart
- Set up weekly cron job to check for renewal
- Create systemd timer as alternative
- Configure logging

**Manual renewal test:**
```bash
# Test the renewal process
sudo certbot renew --dry-run

# Force renewal (for testing)
sudo /path/to/grav/user/plugins/workerman-server/bin/renew-ssl.sh
```

### Method 3: Existing Valid Certificate

If you already have valid SSL certificates for your domain:

```bash
# Copy your existing certificates to Grav
cp /path/to/your/domain.crt ssl/workerman.crt
cp /path/to/your/domain.key ssl/workerman.key
chmod 644 ssl/workerman.crt
chmod 600 ssl/workerman.key
```

#### Step 2: Configure Workerman SSL

```yaml
workerman:
  enabled: true
  host: 0.0.0.0  # Listen on all interfaces for production
  port: 8443     # Use different port for HTTPS
  ssl:
    enabled: true
    cert_file: ssl/workerman.crt
    key_file: ssl/workerman.key
    verify_peer: true
```

#### Step 3: Configure Firewall

```bash
# Allow HTTPS port through firewall
sudo ufw allow 8443/tcp
```

#### Step 4: Update Domain Configuration

If using a production domain, update your DNS/proxy configuration to allow connections to port 8443.

## Configuration Options

### SSL Configuration Parameters

| Parameter | Description | Default | Notes |
|-----------|-------------|---------|-------|
| `ssl.enabled` | Enable/disable SSL | `false` | Must be `true` for HTTPS sites |
| `ssl.cert_file` | Path to certificate file | `` | Relative to Grav root or absolute path |
| `ssl.key_file` | Path to private key file | `` | Relative to Grav root or absolute path |
| `ssl.verify_peer` | Verify SSL peers | `false` | Set to `false` for self-signed certificates |

### Recommended Port Configuration

| Environment | HTTP Port | HTTPS Port | Host |
|-------------|-----------|------------|------|
| Development | 8080 | 8080 | 127.0.0.1 |
| Production | N/A | 8443 | 0.0.0.0 |

## Troubleshooting

### Certificate File Not Found

**Error**: `SSL certificate file not found: ssl/workerman.crt`

**Solution**: Ensure certificate files exist and paths are correct:

```bash
# Check if files exist
ls -la ssl/workerman.*

# Use absolute paths if needed
/full/path/to/grav/ssl/workerman.crt
```

### Permission Denied

**Error**: `Permission denied reading certificate files`

**Solution**: Fix file permissions:

```bash
# Make certificates readable by web server
sudo chown www-data:www-data ssl/workerman.*
sudo chmod 644 ssl/workerman.crt
sudo chmod 600 ssl/workerman.key
```

### Browser Still Shows Mixed Content Warning

**Solutions**:
1. **Clear browser cache** and refresh the page
2. **Check certificate acceptance**: Visit `https://127.0.0.1:8080` directly
3. **Verify SSL is enabled**: Check Workerman logs for SSL startup messages
4. **Use browser developer tools**: Check Network tab for SSE connection status

### Certificate Verification Failed

**Error**: `stream_socket_enable_crypto(): SSL operation failed`

**Solutions**:
1. **For self-signed certificates**: Set `verify_peer: false`
2. **For valid certificates**: Ensure certificate chain is complete
3. **Check certificate validity**: `openssl x509 -in ssl/workerman.crt -text -noout`

## Testing SSL Configuration

### 1. Verify SSL Server Startup

Check Workerman logs for SSL confirmation:

```bash
# Check if SSL is mentioned in startup
php user/plugins/comments-pro/bin/workerman-sse-server.php start

# Look for output like:
# CommentsPro SSE Worker #0 started on 127.0.0.1:8080 (SSL enabled)
```

### 2. Test SSL Connection

```bash
# Test SSL connection (replace port/host as needed)
openssl s_client -connect 127.0.0.1:8080 -servername 127.0.0.1

# Should show certificate details and "SSL handshake has read" messages
```

### 3. Browser Developer Tools

1. Open browser developer tools (F12)
2. Go to **Network** tab
3. Refresh the page
4. Look for SSE connection to `https://127.0.0.1:8080/sse/...`
5. Should show status 200 and "text/event-stream" content type

## Security Notes

### Self-Signed Certificates
- **Development only**: Self-signed certificates are suitable for development and local testing
- **Security warning**: Browsers will show security warnings that users must accept
- **Not for production**: Don't use self-signed certificates in production environments

### Production Certificates
- **Domain validation**: Use certificates issued for your actual domain
- **Certificate renewal**: Set up automatic renewal for Let's Encrypt certificates
- **Firewall rules**: Only open necessary ports and restrict access as needed

### File Permissions
- **Certificate files**: Should be readable by web server user (644)
- **Private key files**: Should be readable only by web server user (600)
- **Directory permissions**: SSL directory should be 755

## Quick Reference

### Development Setup (Self-Signed)
```bash
# Generate certificate
openssl genrsa -out ssl/workerman.key 2048
openssl req -new -x509 -key ssl/workerman.key -out ssl/workerman.crt -days 365 -subj "/CN=127.0.0.1"

# Configure in comments-pro.yaml
workerman.ssl.enabled: true
workerman.ssl.cert_file: ssl/workerman.crt
workerman.ssl.key_file: ssl/workerman.key
workerman.ssl.verify_peer: false

# Restart daemon
php user/plugins/comments-pro/bin/workerman-sse-server.php restart

# Accept certificate in browser at https://127.0.0.1:8080
```

### Production Setup (Let's Encrypt - Automated)
```bash
# One-command setup with automation
sudo php user/plugins/workerman-server/bin/setup-letsencrypt.php

# Set up automatic renewal
sudo php user/plugins/workerman-server/bin/setup-ssl-renewal.php

# Restart Workerman
php user/plugins/workerman-server/bin/workerman-server.php restart
```

### Production Setup (Let's Encrypt - Manual)
```bash
# Install certbot
sudo apt-get install certbot

# Get certificate
sudo certbot certonly --webroot -w /path/to/grav -d yourdomain.com --email your@email.com --agree-tos

# Copy certificates
sudo cp /etc/letsencrypt/live/yourdomain.com/fullchain.pem ssl/workerman.crt
sudo cp /etc/letsencrypt/live/yourdomain.com/privkey.pem ssl/workerman.key
sudo chown www-data:www-data ssl/workerman.*

# Configure for production
workerman.ssl.enabled: true
workerman.ssl.cert_file: ssl/workerman.crt
workerman.ssl.key_file: ssl/workerman.key
workerman.ssl.verify_peer: true
workerman.host: 0.0.0.0
workerman.port: 8443

# Set up renewal cron job
echo "0 3 * * 1 root /path/to/grav/user/plugins/workerman-server/bin/renew-ssl.sh" | sudo tee /etc/cron.d/workerman-server-ssl-renewal

# Allow through firewall
sudo ufw allow 8443/tcp
```

### Let's Encrypt Benefits
- ✅ **Free certificates** - No cost for SSL certificates
- ✅ **Automatic renewal** - Certificates auto-renew before expiration
- ✅ **No browser warnings** - Fully trusted by all major browsers
- ✅ **Domain validation** - Proper SSL validation without user intervention
- ✅ **90-day validity** - More secure with frequent renewal
- ✅ **Wildcard support** - Can obtain wildcard certificates for subdomains