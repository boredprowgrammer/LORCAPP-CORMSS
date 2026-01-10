<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';
// Check if user is authenticated and has permission
Security::requireLogin();

$currentUser = getCurrentUser();

// Check if user has permission to track users
if ($currentUser['role'] !== 'admin' && empty($currentUser['can_track_users'])) {
    header('Location: ' . BASE_URL . '/dashboard.php?error=insufficient_permissions');
    exit;
}

$pageTitle = 'User Locations';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white flex items-center">
                    <i class="fa-solid fa-map-location-dot mr-3 text-blue-600"></i>
                    User Locations
                </h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    Real-time location tracking of users (Active: &lt;10 min, Recent: &lt;3 hours)
                </p>
            </div>
            <div class="flex items-center space-x-4">
                <button onclick="refreshLocations()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    <i class="fa-solid fa-rotate mr-2"></i>
                    Refresh
                </button>
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <i class="fa-solid fa-clock mr-1"></i>
                    Auto-refresh: <span id="countdown">60</span>s
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-users text-2xl text-green-600 dark:text-green-400"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Users</p>
                    <p id="activeUsers" class="text-2xl font-bold text-gray-900 dark:text-white">0</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-gray-100 dark:bg-gray-900 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-user-clock text-2xl text-gray-600 dark:text-gray-400"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Recent Users</p>
                    <p id="recentUsers" class="text-2xl font-bold text-gray-900 dark:text-white">0</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-location-dot text-2xl text-blue-600 dark:text-blue-400"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">With GPS</p>
                    <p id="gpsCount" class="text-2xl font-bold text-gray-900 dark:text-white">0</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-network-wired text-2xl text-orange-600 dark:text-orange-400"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">IP Only</p>
                    <p id="ipOnlyCount" class="text-2xl font-bold text-gray-900 dark:text-white">0</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-clock text-2xl text-purple-600 dark:text-purple-400"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Last Update</p>
                    <p id="lastUpdate" class="text-sm font-bold text-gray-900 dark:text-white">--</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Map and User List -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Map -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                        <i class="fa-solid fa-map mr-2 text-blue-600"></i>
                        Live Map
                    </h2>
                </div>
                <div class="p-4">
                    <div id="map" class="w-full h-[600px] rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-700"></div>
                </div>
            </div>
        </div>

        <!-- User List -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                        <i class="fa-solid fa-list mr-2 text-blue-600"></i>
                        Active Users
                    </h2>
                </div>
                <div id="userList" class="p-4 space-y-3 max-h-[600px] overflow-y-auto">
                    <!-- User list will be populated here -->
                    <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                        <i class="fa-solid fa-spinner fa-spin text-3xl mb-2"></i>
                        <p>Loading user locations...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
let map;
let markers = {};
let userLocations = [];
let countdownTimer;

// Initialize map
function initMap() {
    // Default center (Philippines)
    map = L.map('map').setView([12.8797, 121.7740], 6);
    
    // Add tile layer
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
}

// Create custom icon based on role and status
function createIcon(role, status = 'active') {
    const colors = {
        'admin': '#ef4444',      // red
        'overseer': '#8b5cf6',   // purple
        'district': '#3b82f6',   // blue
        'local': '#10b981'       // green
    };
    
    const color = colors[role] || '#6b7280';
    const opacity = status === 'recent' ? '0.5' : '1';
    const borderColor = status === 'recent' ? '#9ca3af' : 'white';
    
    return L.divIcon({
        className: 'custom-marker',
        html: `<div style="background-color: ${color}; width: 32px; height: 32px; border-radius: 50%; border: 3px solid ${borderColor}; box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; opacity: ${opacity};">
                <i class="fa-solid fa-user" style="color: white; font-size: 14px;"></i>
              </div>`,
        iconSize: [32, 32],
        iconAnchor: [16, 16]
    });
}

// Update user locations
function updateLocations(data) {
    const locations = data.locations || [];
    userLocations = locations;
    
    // Update stats
    document.getElementById('activeUsers').textContent = data.active_count || 0;
    document.getElementById('recentUsers').textContent = data.recent_count || 0;
    
    let gpsCount = 0;
    let ipOnlyCount = 0;
    locations.forEach(loc => {
        if (loc.latitude && loc.longitude) {
            gpsCount++;
        } else {
            ipOnlyCount++;
        }
    });
    
    document.getElementById('gpsCount').textContent = gpsCount;
    document.getElementById('ipOnlyCount').textContent = ipOnlyCount;
    
    // Add warning color if there are users without GPS
    const ipOnlyCard = document.getElementById('ipOnlyCount').closest('.bg-white');
    if (ipOnlyCount > 0) {
        ipOnlyCard.classList.add('ring-2', 'ring-red-500');
    } else {
        ipOnlyCard.classList.remove('ring-2', 'ring-red-500');
    }
    
    document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
    
    // Clear existing markers
    Object.values(markers).forEach(marker => marker.remove());
    markers = {};
    
    // Add markers for users with GPS coordinates
    let bounds = [];
    locations.forEach(location => {
        if (location.latitude && location.longitude) {
            const marker = L.marker([location.latitude, location.longitude], {
                icon: createIcon(location.role, location.status)
            }).addTo(map);
            
            // Create popup content
            const popupContent = `
                <div class="p-2">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-bold text-lg">${location.first_name} ${location.last_name}</h3>
                        <span class="${location.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'} px-2 py-1 rounded text-xs font-medium">
                            ${location.status === 'active' ? 'Active' : 'Recent'}
                        </span>
                    </div>
                    <div class="space-y-1 text-sm">
                        <p><strong>Username:</strong> ${location.username}</p>
                        <p><strong>Role:</strong> <span class="capitalize">${location.role}</span></p>
                        <p><strong>Local:</strong> ${location.local_name || 'N/A'}</p>
                        ${location.latitude && location.longitude ? `<p class="text-green-600"><strong>GPS:</strong> ${parseFloat(location.latitude).toFixed(6)}, ${parseFloat(location.longitude).toFixed(6)}</p>` : ''}
                        ${location.address ? `<p><strong>Address:</strong> ${location.address}</p>` : ''}
                        ${location.city ? `<p><strong>City:</strong> ${location.city}</p>` : ''}
                        ${location.country ? `<p><strong>Country:</strong> ${location.country}</p>` : ''}
                        <p><strong>IP Address:</strong> ${location.ip_address}</p>
                        <p><strong>Accuracy:</strong> ${location.accuracy ? Math.round(location.accuracy) + 'm' : 'N/A'}</p>
                        <p><strong>Source:</strong> <span class="text-blue-600">${location.location_source || 'browser'}</span></p>
                        <p><strong>Updated:</strong> ${location.time_ago}</p>
                    </div>
                </div>
            `;
            
            marker.bindPopup(popupContent);
            markers[location.user_id] = marker;
            bounds.push([location.latitude, location.longitude]);
        }
    });
    
    // Fit map to show all markers
    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [50, 50] });
    }
    
    // Update user list
    updateUserList(locations);
}

// Update user list
function updateUserList(locations) {
    const userList = document.getElementById('userList');
    
    if (locations.length === 0) {
        userList.innerHTML = `
            <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                <i class="fa-solid fa-users-slash text-3xl mb-2"></i>
                <p>No active users found</p>
            </div>
        `;
        return;
    }
    
    const roleColors = {
        'admin': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'overseer': 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'district': 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'local': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    };
    
    userList.innerHTML = locations.map(location => {
        const roleColor = roleColors[location.role] || 'bg-gray-100 text-gray-800';
        const hasGPS = location.latitude && location.longitude;
        const onclickHandler = hasGPS ? `focusMarker(${location.user_id}, ${location.latitude}, ${location.longitude})` : '';
        
        return `
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition cursor-pointer"
                 ${hasGPS ? `onclick="${onclickHandler}"` : ''}>
                <div class="flex items-start justify-between mb-2">
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900 dark:text-white">${location.first_name} ${location.last_name}</h3>
                        <p class="text-xs text-gray-600 dark:text-gray-400">${location.username}</p>
                    </div>
                    <div class="flex flex-col items-end space-y-1">
                        <span class="${roleColor} px-2 py-1 rounded text-xs font-medium capitalize">
                            ${location.role}
                        </span>
                        <span class="${location.status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'} px-2 py-1 rounded text-xs font-medium">
                            ${location.status === 'active' ? 'Active' : 'Recent'}
                        </span>
                    </div>
                </div>
                <div class="space-y-1 text-xs text-gray-600 dark:text-gray-400">
                    ${hasGPS ? `
                    <div class="flex items-center text-green-600 dark:text-green-400 font-medium">
                        <i class="fa-solid fa-location-dot w-4 mr-2"></i>
                        <span>GPS: ${parseFloat(location.latitude).toFixed(6)}, ${parseFloat(location.longitude).toFixed(6)}</span>
                    </div>
                    ` : `
                    <div class="flex items-center text-orange-600 dark:text-orange-400 font-medium">
                        <i class="fa-solid fa-exclamation-triangle w-4 mr-2"></i>
                        <span>No GPS Data</span>
                    </div>
                    `}
                    ${location.address ? `
                    <div class="flex items-start">
                        <i class="fa-solid fa-map-marker-alt w-4 mr-2 mt-0.5"></i>
                        <span class="flex-1">${location.address}</span>
                    </div>
                    ` : ''}
                    ${location.city || location.country ? `
                    <div class="flex items-center">
                        <i class="fa-solid fa-globe w-4 mr-2"></i>
                        <span>${location.city && location.country ? location.city + ', ' + location.country : location.city || location.country}</span>
                    </div>
                    ` : ''}
                    <div class="flex items-center">
                        <i class="fa-solid fa-network-wired w-4 mr-2"></i>
                        <span>IP: ${location.ip_address}</span>
                    </div>
                    ${location.location_source ? `
                    <div class="flex items-center text-blue-600 dark:text-blue-400">
                        <i class="fa-solid fa-satellite-dish w-4 mr-2"></i>
                        <span>Source: ${location.location_source}</span>
                    </div>
                    ` : ''}
                    <div class="flex items-center">
                        <i class="fa-solid fa-clock w-4 mr-2"></i>
                        <span>${location.time_ago}</span>
                    </div>
                    ${location.local_name ? `
                    <div class="flex items-center">
                        <i class="fa-solid fa-church w-4 mr-2"></i>
                        <span>${location.local_name}</span>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

// Focus on marker
function focusMarker(userId, lat, lng) {
    if (markers[userId]) {
        map.setView([lat, lng], 15);
        markers[userId].openPopup();
    }
}

// Refresh locations
function refreshLocations() {
    fetch('<?php echo BASE_URL; ?>/api/get-user-locations.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateLocations(data);
                resetCountdown();
            }
        })
        .catch(error => {
            console.error('Error fetching locations:', error);
        });
}

// Countdown timer
function resetCountdown() {
    clearInterval(countdownTimer);
    let seconds = 60;
    document.getElementById('countdown').textContent = seconds;
    
    countdownTimer = setInterval(() => {
        seconds--;
        document.getElementById('countdown').textContent = seconds;
        
        if (seconds <= 0) {
            refreshLocations();
        }
    }, 1000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initMap();
    refreshLocations();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
