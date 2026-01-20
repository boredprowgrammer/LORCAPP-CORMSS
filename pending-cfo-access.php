<?php
/**
 * Pending CFO Access Requests
 * For Senior Accounts to review and approve access requests
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Only local and admin accounts can access this page
if ($currentUser['role'] !== 'local' && $currentUser['role'] !== 'admin') {
    $_SESSION['error'] = "You do not have permission to view CFO access requests.";
    header('Location: ' . BASE_URL . '/launchpad.php');
    exit;
}

$pageTitle = 'Pending CFO Access Requests';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">CFO Access Requests</h1>
                <p class="text-sm text-gray-500 mt-1">Review and approve access requests from Local CFO accounts</p>
            </div>
            <a href="pending-actions.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Pending Actions
            </a>
        </div>
    </div>

    <!-- Pending Requests -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Pending Requests</h2>
        </div>
        <div class="p-6">
            <div id="requestsList" class="space-y-4">
                <!-- Requests loaded via JavaScript -->
                <div class="text-center text-gray-500 py-8">
                    <svg class="animate-spin h-8 w-8 mx-auto mb-3 text-blue-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading requests...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div id="approvalModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-lg bg-white">
        <div class="flex items-center justify-between pb-3 border-b">
            <h3 class="text-lg font-semibold text-gray-900">Approve Access Request</h3>
            <button onclick="closeApprovalModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <div class="mt-4 space-y-4">
            <input type="hidden" id="approveRequestId">
            
            <div id="requestDetails" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <!-- Request details will be populated via JS -->
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Upload PDF Document</label>
                <input type="file" 
                       id="pdfFile" 
                       accept=".pdf"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">The PDF will be automatically watermarked with "CONFIDENTIAL".</p>
            </div>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-yellow-800 mb-2">⏱️ Document Expiration Policy</h4>
                <ul class="text-xs text-yellow-700 space-y-1 list-disc list-inside">
                    <li>Document will be accessible for <strong>30 days</strong></li>
                    <li>After <strong>first viewing</strong>, countdown begins</li>
                    <li>Document <strong>locks after 7 days</strong> from first view</li>
                    <li>Document <strong>auto-deletes after 30 days</strong></li>
                    <li>All views are logged with IP and timestamp</li>
                </ul>
            </div>
        </div>
        
        <div class="mt-6 flex gap-3 justify-end border-t pt-4">
            <button onclick="closeApprovalModal()" 
                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
            <button id="approveBtn"
                    onclick="approveRequest()" 
                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Approve & Upload
            </button>
        </div>
    </div>
</div>

<script>
let allRequests = [];

// Load all requests
async function loadRequests() {
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/get-cfo-access-requests.php?action=pending');
        const data = await response.json();
        
        if (data.success) {
            allRequests = data.data || data.requests;
            displayRequests(allRequests);
        } else {
            document.getElementById('requestsList').innerHTML = `
                <div class="text-center text-red-600 py-8">
                    <svg class="w-12 h-12 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="font-medium">${data.error || 'Failed to load requests'}</p>
                </div>
            `;
        }
    } catch (error) {
        document.getElementById('requestsList').innerHTML = `
            <div class="text-center text-red-600 py-8">
                <svg class="w-12 h-12 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="font-medium">Network error loading requests</p>
            </div>
        `;
    }
}

function displayRequests(requests) {
    const container = document.getElementById('requestsList');
    
    if (requests.length === 0) {
        container.innerHTML = `
            <div class="text-center text-gray-500 py-12">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p class="text-lg font-medium">No pending requests</p>
                <p class="text-sm mt-1">All requests have been processed</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = requests.map(request => `
        <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2">
                        <h3 class="text-lg font-semibold text-gray-900">${escapeHtml(request.requester_name)}</h3>
                        <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium">${escapeHtml(request.cfo_type)}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-600">Username:</span>
                            <span class="font-medium text-gray-900 ml-1">${escapeHtml(request.requester_username)}</span>
                        </div>
                        <div>
                            <span class="text-gray-600">Local:</span>
                            <span class="font-medium text-gray-900 ml-1">${escapeHtml(request.local_name)}</span>
                        </div>
                        <div>
                            <span class="text-gray-600">District:</span>
                            <span class="font-medium text-gray-900 ml-1">${escapeHtml(request.district_name)}</span>
                        </div>
                        <div>
                            <span class="text-gray-600">Requested:</span>
                            <span class="font-medium text-gray-900 ml-1">${formatDate(request.request_date)}</span>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2 ml-4">
                    <button onclick="openApprovalModal(${request.id})" 
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Approve
                    </button>
                    <button onclick="denyRequest(${request.id})" 
                            class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Deny
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

function openApprovalModal(requestId) {
    const request = allRequests.find(r => r.id === requestId);
    if (!request) return;
    
    document.getElementById('approveRequestId').value = requestId;
    document.getElementById('requestDetails').innerHTML = `
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
                <span class="text-blue-700 font-medium">Requester:</span>
                <span class="text-blue-900 ml-1">${escapeHtml(request.requester_name)}</span>
            </div>
            <div>
                <span class="text-blue-700 font-medium">CFO Type:</span>
                <span class="text-blue-900 ml-1">${escapeHtml(request.cfo_type)}</span>
            </div>
            <div>
                <span class="text-blue-700 font-medium">Local:</span>
                <span class="text-blue-900 ml-1">${escapeHtml(request.local_name)}</span>
            </div>
            <div>
                <span class="text-blue-700 font-medium">Requested:</span>
                <span class="text-blue-900 ml-1">${formatDate(request.request_date)}</span>
            </div>
        </div>
    `;
    
    document.getElementById('pdfFile').value = '';
    document.getElementById('approvalModal').classList.remove('hidden');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
}

async function approveRequest() {
    const requestId = document.getElementById('approveRequestId').value;
    const pdfFile = document.getElementById('pdfFile').files[0];
    const approveBtn = document.getElementById('approveBtn');
    
    if (!pdfFile) {
        alert('Please select a PDF file to upload.');
        return;
    }
    
    if (pdfFile.type !== 'application/pdf') {
        alert('Please upload a valid PDF file.');
        return;
    }
    
    approveBtn.disabled = true;
    approveBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
    
    const formData = new FormData();
    formData.append('request_id', requestId);
    formData.append('pdf_file', pdfFile);
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approve-cfo-access.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Access request approved successfully!');
            closeApprovalModal();
            loadRequests();
        } else {
            alert('❌ ' + (data.error || 'Failed to approve request'));
        }
    } catch (error) {
        alert('❌ Network error: ' + error.message);
    } finally {
        approveBtn.disabled = false;
        approveBtn.innerHTML = '<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Approve & Upload';
    }
}

async function denyRequest(requestId) {
    if (!confirm('Are you sure you want to deny this access request?')) {
        return;
    }
    
    // TODO: Implement deny functionality
    alert('Deny functionality will be implemented soon.');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { 
        month: 'short', 
        day: 'numeric', 
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

// Load requests on page load
document.addEventListener('DOMContentLoaded', loadRequests);

// Refresh every 30 seconds
setInterval(loadRequests, 30000);
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
