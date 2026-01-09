<?php
/**
 * CFO Access Requests Management
 * For admin and senior local accounts to approve CFO registry access requests
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Only admin and local (senior) can access this page
if ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'local') {
    $_SESSION['error'] = "You do not have permission to view CFO access requests.";
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pageTitle = 'CFO Access Requests';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">CFO Access Requests</h1>
                <p class="text-sm text-gray-500 mt-1">Review and approve access requests from Local CFO accounts</p>
            </div>
            <a href="cfo-registry.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Registry
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <!-- Pending Requests Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Pending Access Requests</h2>
        </div>
        <div class="p-4">
            <div class="overflow-x-auto">
                <table id="requestsTable" class="display nowrap" style="width:100%">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Requester</th>
                            <th>Local</th>
                            <th>CFO Type</th>
                            <th>Request Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-gradient-to-r from-green-600 to-green-700 p-6 rounded-t-xl z-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">Approve Access Request</h3>
                        <p class="text-green-100 text-sm">Upload CFO registry PDF</p>
                    </div>
                </div>
                <button onclick="closeApproveModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="p-6 space-y-4">
            <input type="hidden" id="approve_request_id">
            
            <!-- Request Details -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h4 class="font-semibold text-blue-900 mb-2">Request Details</h4>
                <div class="text-sm text-blue-800 space-y-1">
                    <p><strong>Requester:</strong> <span id="approve_requester_name"></span></p>
                    <p><strong>Local:</strong> <span id="approve_local_name"></span></p>
                    <p><strong>CFO Type:</strong> <span id="approve_cfo_type"></span></p>
                    <p><strong>Request Date:</strong> <span id="approve_request_date"></span></p>
                </div>
            </div>

            <?php if ($currentUser['role'] === 'admin'): ?>
            <!-- Senior Account Selector (Admin only) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Assign Senior Approver <span class="text-red-500">*</span>
                </label>
                <select id="approve_senior_user_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" required>
                    <option value="">Select a senior account...</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Choose which senior local account will approve this request</p>
            </div>
            <?php endif; ?>
            
            <!-- PDF Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Upload CFO Registry PDF <span class="text-red-500">*</span>
                </label>
                <input type="file" 
                       id="approve_pdf_file" 
                       accept=".pdf,application/pdf"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                       required>
                <p class="text-xs text-gray-500 mt-1">PDF will be automatically watermarked for security</p>
            </div>
            
            <!-- Approval Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Approval Notes (Optional)</label>
                <textarea id="approve_notes" 
                          rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                          placeholder="Add any notes or instructions..."></textarea>
            </div>
            
            <!-- Info Box -->
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                <p class="text-xs text-gray-700">
                    <strong>📋 What happens next:</strong>
                </p>
                <ul class="text-xs text-gray-600 mt-2 space-y-1 list-disc list-inside">
                    <li>PDF will be watermarked with "CONFIDENTIAL"</li>
                    <li>Document will be accessible for 30 days</li>
                    <li>After 7 days from first viewing, it will lock</li>
                    <li>All access is logged for security</li>
                </ul>
            </div>
        </div>
        
        <div class="p-6 bg-gray-50 border-t border-gray-200 flex gap-3 justify-end rounded-b-xl">
            <button onclick="closeApproveModal()" 
                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
            <button id="approveBtn"
                    onclick="submitApproval()" 
                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Approve & Upload
            </button>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full shadow-2xl">
        <div class="sticky top-0 bg-gradient-to-r from-red-600 to-red-700 p-6 rounded-t-xl">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">Reject Access Request</h3>
                        <p class="text-red-100 text-sm">Provide reason for rejection</p>
                    </div>
                </div>
                <button onclick="closeRejectModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="p-6 space-y-4">
            <input type="hidden" id="reject_request_id">
            
            <!-- Rejection Reason -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Reason for Rejection <span class="text-red-500">*</span>
                </label>
                <textarea id="reject_reason" 
                          rows="4"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"
                          placeholder="Explain why this request is being rejected..."
                          required></textarea>
            </div>
        </div>
        
        <div class="p-6 bg-gray-50 border-t border-gray-200 flex gap-3 justify-end rounded-b-xl">
            <button onclick="closeRejectModal()" 
                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
            <button id="rejectBtn"
                    onclick="submitRejection()" 
                    class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                Reject Request
            </button>
        </div>
    </div>
</div>

<script>
let table;

$(document).ready(function() {
    // Initialize DataTable
    table = $('#requestsTable').DataTable({
        processing: true,
        ajax: {
            url: '<?php echo BASE_URL; ?>/api/get-cfo-access-requests.php?action=pending',
            dataSrc: 'data',
            error: function(xhr, error, code) {
                console.error('DataTable error:', error);
                alert('Failed to load requests. Please refresh the page.');
            }
        },
        columns: [
            { data: 'id' },
            { 
                data: null,
                render: function(data, type, row) {
                    return `<div>
                        <div class="font-medium text-gray-900">${row.requester_name}</div>
                        <div class="text-xs text-gray-500">${row.requester_username}</div>
                    </div>`;
                }
            },
            { data: 'local_name' },
            { 
                data: 'cfo_type',
                render: function(data) {
                    const colors = {
                        'Buklod': 'bg-blue-100 text-blue-700',
                        'Kadiwa': 'bg-green-100 text-green-700',
                        'Binhi': 'bg-orange-100 text-orange-700',
                        'All': 'bg-purple-100 text-purple-700'
                    };
                    return `<span class="px-2 py-1 ${colors[data] || 'bg-gray-100 text-gray-700'} rounded text-xs font-medium">${data}</span>`;
                }
            },
            { 
                data: 'request_date',
                render: function(data) {
                    const date = new Date(data);
                    return date.toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            },
            {
                data: 'status',
                render: function(data) {
                    return '<span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium">⏳ Pending</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    return `
                        <div class="flex gap-2">
                            <button onclick="openApproveModal(${row.id})" 
                                    class="inline-flex items-center px-3 py-1 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition-colors text-sm">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Approve
                            </button>
                            <button onclick="openRejectModal(${row.id})" 
                                    class="inline-flex items-center px-3 py-1 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors text-sm">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                                Reject
                            </button>
                        </div>
                    `;
                }
            }
        ],
        order: [[4, 'desc']], // Sort by request date, newest first
        pageLength: 25,
        responsive: true,
        language: {
            emptyTable: "No pending access requests",
            processing: '<div class="text-blue-600"><i class="fas fa-spinner fa-spin mr-2"></i>Loading requests...</div>'
        }
    });
});

// Approve Modal Functions
async function openApproveModal(requestId) {
    // Get request details
    const row = table.rows().data().toArray().find(r => r.id === requestId);
    if (!row) return;
    
    document.getElementById('approve_request_id').value = requestId;
    document.getElementById('approve_requester_name').textContent = row.requester_name;
    document.getElementById('approve_local_name').textContent = row.local_name;
    document.getElementById('approve_cfo_type').textContent = row.cfo_type;
    document.getElementById('approve_request_date').textContent = new Date(row.request_date).toLocaleString();
    document.getElementById('approve_pdf_file').value = '';
    document.getElementById('approve_notes').value = '';
    
    <?php if ($currentUser['role'] === 'admin'): ?>
    // Load senior approvers for this local
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/get-senior-approvers.php?local=' + row.requester_local_code);
        const seniors = await response.json();
        
        const select = document.getElementById('approve_senior_user_id');
        select.innerHTML = '<option value="">Select a senior account...</option>';
        
        seniors.forEach(senior => {
            const option = document.createElement('option');
            option.value = senior.user_id;
            option.textContent = `${senior.full_name} (${senior.username})`;
            select.appendChild(option);
        });
    } catch (error) {
        console.error('Error loading senior approvers:', error);
    }
    <?php endif; ?>
    
    document.getElementById('approveModal').classList.remove('hidden');
}

function closeApproveModal() {
    document.getElementById('approveModal').classList.add('hidden');
}

async function submitApproval() {
    const requestId = document.getElementById('approve_request_id').value;
    const pdfFile = document.getElementById('approve_pdf_file').files[0];
    const notes = document.getElementById('approve_notes').value;
    const approveBtn = document.getElementById('approveBtn');
    
    <?php if ($currentUser['role'] === 'admin'): ?>
    const seniorUserId = document.getElementById('approve_senior_user_id').value;
    if (!seniorUserId) {
        alert('Please select a senior approver');
        return;
    }
    <?php endif; ?>
    
    if (!pdfFile) {
        alert('Please select a PDF file to upload');
        return;
    }
    
    if (pdfFile.type !== 'application/pdf') {
        alert('Please upload a valid PDF file');
        return;
    }
    
    const formData = new FormData();
    formData.append('request_id', requestId);
    formData.append('pdf_file', pdfFile);
    formData.append('approval_notes', notes);
    <?php if ($currentUser['role'] === 'admin'): ?>
    formData.append('senior_user_id', seniorUserId);
    <?php endif; ?>
    
    approveBtn.disabled = true;
    approveBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approve-cfo-access.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Access request approved successfully! PDF has been watermarked and stored.');
            closeApproveModal();
            table.ajax.reload();
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

// Reject Modal Functions
function openRejectModal(requestId) {
    document.getElementById('reject_request_id').value = requestId;
    document.getElementById('reject_reason').value = '';
    document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
}

async function submitRejection() {
    const requestId = document.getElementById('reject_request_id').value;
    const reason = document.getElementById('reject_reason').value.trim();
    const rejectBtn = document.getElementById('rejectBtn');
    
    if (!reason) {
        alert('Please provide a reason for rejection');
        return;
    }
    
    rejectBtn.disabled = true;
    rejectBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approve-cfo-access.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                request_id: requestId,
                action: 'reject',
                rejection_reason: reason
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Access request rejected.');
            closeRejectModal();
            table.ajax.reload();
        } else {
            alert('❌ ' + (data.error || 'Failed to reject request'));
        }
    } catch (error) {
        alert('❌ Network error: ' + error.message);
    } finally {
        rejectBtn.disabled = false;
        rejectBtn.innerHTML = '<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Reject Request';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
