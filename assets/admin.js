// Workerman Server Admin JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh status every 30 seconds
    if (document.getElementById('workerman-status-container')) {
        setInterval(refreshWorkermanStatus, 30000);
    }
});

function workermanControl(action) {
    const url = `${window.location.origin}${window.location.pathname}?task=workerman-${action}`;
    
    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message || `${action} completed successfully`, 'success');
            setTimeout(refreshWorkermanStatus, 1000);
        } else {
            showNotification(data.message || `${action} failed`, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification(`Failed to ${action} Workerman server`, 'error');
    });
}

function refreshWorkermanStatus() {
    const container = document.getElementById('workerman-status-container');
    if (!container) return;
    
    const url = `${window.location.origin}${window.location.pathname}?task=workerman-status`;
    
    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.status) {
            updateStatusDisplay(data.status);
        }
    })
    .catch(error => {
        console.error('Error refreshing status:', error);
    });
}

function updateStatusDisplay(status) {
    const container = document.getElementById('workerman-status-container');
    if (!container) return;
    
    const isRunning = status.running;
    const statusClass = isRunning ? 'running' : 'stopped';
    const statusText = isRunning ? 'Running' : 'Stopped';
    
    let html = `
        <div class="workerman-status ${statusClass}">
            <h4>Status: ${statusText}</h4>
    `;
    
    if (isRunning) {
        html += `
            <div class="workerman-stats">
                <div class="workerman-stat">
                    <div class="workerman-stat-value">${status.connections || 0}</div>
                    <div class="workerman-stat-label">Active Connections</div>
                </div>
                <div class="workerman-stat">
                    <div class="workerman-stat-value">${status.pid || 'N/A'}</div>
                    <div class="workerman-stat-label">Process ID</div>
                </div>
        `;
        
        if (status.uptime) {
            const hours = Math.floor(status.uptime / 3600);
            const minutes = Math.floor((status.uptime % 3600) / 60);
            html += `
                <div class="workerman-stat">
                    <div class="workerman-stat-value">${hours}h ${minutes}m</div>
                    <div class="workerman-stat-label">Uptime</div>
                </div>
            `;
        }
        
        html += '</div>';
        
        if (status.handlers) {
            html += '<h5>Active Handlers:</h5><ul>';
            for (const [handler, count] of Object.entries(status.handlers)) {
                html += `<li>${handler}: ${count} connections</li>`;
            }
            html += '</ul>';
        }
    }
    
    html += '</div>';
    container.innerHTML = html;
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        max-width: 400px;
        padding: 15px;
        border-radius: 4px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    `;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 5000);
}