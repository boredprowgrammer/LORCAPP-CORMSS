<?php
/**
 * View PNK Member Record
 * Display details of a member in PNK Registry (Pagsamba ng Kabataan)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get record ID
$recordId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$recordId) {
    $_SESSION['error'] = 'Invalid record ID.';
    header('Location: pnk-registry.php');
    exit;
}

// Fetch record
$stmt = $db->prepare("SELECT * FROM pnk_registry WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$recordId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['error'] = 'Record not found.';
    header('Location: pnk-registry.php');
    exit;
}

// Check access to district
if (!hasDistrictAccess($record['district_code'])) {
    $_SESSION['error'] = 'You do not have access to view this record.';
    header('Location: pnk-registry.php');
    exit;
}

// Decrypt sensitive data
try {
    $districtCode = $record['district_code'];
    
    $firstName = Encryption::decrypt($record['first_name_encrypted'], $districtCode);
    $middleName = Encryption::decrypt($record['middle_name_encrypted'], $districtCode);
    $lastName = Encryption::decrypt($record['last_name_encrypted'], $districtCode);
    $birthday = Encryption::decrypt($record['birthday_encrypted'], $districtCode);
    
    // Decrypt address if exists
    $address = '';
    if (!empty($record['address_encrypted'])) {
        $address = Encryption::decrypt($record['address_encrypted'], $districtCode);
    }
    
    // Decrypt dako (chapter/group) if exists
    $dako = '';
    if (!empty($record['dako_encrypted'])) {
        $dako = Encryption::decrypt($record['dako_encrypted'], $districtCode);
    }
    
    // Decrypt registry number
    $registryNumber = $record['registry_number'];
    if (strpos($registryNumber, 'PNK-') !== 0) {
        try {
            $registryNumber = Encryption::decrypt($registryNumber, $districtCode);
        } catch (Exception $e) {
            // Use as-is if decryption fails
        }
    }
    
    // Decrypt parent/guardian names from combined field
    $fatherFullName = 'N/A';
    $motherFullName = 'N/A';
    
    if (!empty($record['parent_guardian_encrypted'])) {
        $parentGuardian = Encryption::decrypt($record['parent_guardian_encrypted'], $districtCode);
        // Parse "Father Name / Mother Name" format
        if (strpos($parentGuardian, ' / ') !== false) {
            $parts = explode(' / ', $parentGuardian, 2);
            $fatherFullName = trim($parts[0]) ?: 'N/A';
            $motherFullName = isset($parts[1]) ? trim($parts[1]) : 'N/A';
        } else {
            // Single parent/guardian
            $fatherFullName = trim($parentGuardian) ?: 'N/A';
        }
    }
    
} catch (Exception $e) {
    error_log("Error decrypting PNK record: " . $e->getMessage());
    $_SESSION['error'] = 'Unable to decrypt record data.';
    header('Location: pnk-registry.php');
    exit;
}

// Build full name
$fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);

// Calculate age
$age = null;
$canEnrollR301 = false;
if ($birthday) {
    try {
        $birthDate = new DateTime($birthday);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
        
        // Can enroll in R3-01 if 12 years or older and status is active
        $canEnrollR301 = (
            $age >= 12 && 
            $record['baptism_status'] === 'active' && 
            $record['attendance_status'] === 'active'
        );
    } catch (Exception $e) {
        error_log("Error calculating age: " . $e->getMessage());
    }
}

// Get district and local names
$stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
$stmt->execute([$record['district_code']]);
$districtInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$districtName = $districtInfo ? $districtInfo['district_name'] : $record['district_code'];

$stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
$stmt->execute([$record['local_code']]);
$localInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$localName = $localInfo ? $localInfo['local_name'] : $record['local_code'];

// Status configuration for PNK
// Statuses: Active, R3-01 (candidate), Transferred-Out, Baptized
$statusConfig = [
    'active' => ['color' => 'green', 'label' => 'Active', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    'not_baptized' => ['color' => 'green', 'label' => 'Active', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    'candidate' => ['color' => 'blue', 'label' => 'R3-01', 'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
    'baptized' => ['color' => 'purple', 'label' => 'Baptized', 'icon' => 'M5 13l4 4L19 7'],
    'transferred-out' => ['color' => 'gray', 'label' => 'Transferred Out', 'icon' => 'M17 8l4 4m0 0l-4 4m4-4H3'],
    'inactive' => ['color' => 'yellow', 'label' => 'Inactive', 'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z']
];

// Determine effective status
$effectiveStatus = $record['baptism_status'];
if ($record['attendance_status'] === 'transferred-out') {
    $effectiveStatus = 'transferred-out';
} elseif ($record['attendance_status'] === 'inactive') {
    $effectiveStatus = 'inactive';
}

$statusInfo = $statusConfig[$effectiveStatus] ?? ['color' => 'gray', 'label' => 'Unknown', 'icon' => ''];

$pageTitle = 'View PNK Member - ' . $fullName;
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <a href="pnk-registry.php" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($fullName); ?></h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">PNK Registry Record</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-<?php echo $statusInfo['color']; ?>-100 dark:bg-<?php echo $statusInfo['color']; ?>-900/30 text-<?php echo $statusInfo['color']; ?>-800 dark:text-<?php echo $statusInfo['color']; ?>-400">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $statusInfo['icon']; ?>"></path>
                    </svg>
                    <?php echo $statusInfo['label']; ?>
                </span>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-2">
            <?php if ($canEnrollR301 && $record['baptism_status'] === 'active'): ?>
                <button onclick="enrollR301()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Enroll in R3-01
                </button>
            <?php endif; ?>

            <?php if ($record['baptism_status'] === 'r301'): ?>
                <button onclick="baptizeMember()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Baptize & Add to Tarheta
                </button>
            <?php endif; ?>

            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                <?php if ($record['attendance_status'] !== 'transferred-out'): ?>
                    <button onclick="showTransferOutModal()" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                        </svg>
                        Transfer Out
                    </button>
                <?php endif; ?>
                <button onclick="showDeleteModal()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Delete
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Member Details -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Member Information</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Registry Number</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($registryNumber); ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Full Name</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($fullName); ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Date of Birth</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($birthday ? date('M j, Y', strtotime($birthday)) : 'N/A'); ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Age</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo $age !== null ? $age . ' years old' : 'N/A'; ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Father's Name</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($fatherFullName); ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Mother's Name</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($motherFullName); ?></p>
            </div>
            
            <?php if ($address): ?>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Address</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($address); ?></p>
            </div>
            <?php endif; ?>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">District</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($districtName); ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Local Congregation</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($localName); ?></p>
            </div>
            
            <?php if ($dako): ?>
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Dako (Chapter/Group)</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($dako); ?></p>
            </div>
            <?php endif; ?>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Registration Date</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo date('M j, Y', strtotime($record['created_at'])); ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Last Updated</label>
                <p class="text-gray-900 dark:text-gray-100"><?php echo date('M j, Y', strtotime($record['updated_at'])); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex items-center gap-4 mb-4">
            <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center">
                <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Delete Record</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">This action cannot be undone</p>
            </div>
        </div>
        <p class="text-gray-700 dark:text-gray-300 mb-6">
            Are you sure you want to permanently delete the PNK record for <strong><?php echo Security::escape($fullName); ?></strong>?
        </p>
        <div class="flex justify-end gap-3">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </button>
            <button type="button" onclick="confirmDelete()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                Delete Permanently
            </button>
        </div>
    </div>
</div>

<!-- Transfer Out Modal -->
<div id="transferOutModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full p-6 shadow-xl">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
            <svg class="w-6 h-6 mr-2 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
            Transfer PNK Member Out
        </h3>
        <form id="transferOutForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer To (Destination Local) *</label>
                <input type="text" id="transferToLocal" required 
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-yellow-500"
                       placeholder="e.g., Local 2, Manila">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer To District</label>
                <input type="text" id="transferToDistrict" 
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-yellow-500"
                       placeholder="e.g., Manila District">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Transfer *</label>
                <textarea id="transferReason" rows="3" required 
                          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-yellow-500"
                          placeholder="e.g., Family relocation, Transferred to another congregation"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                    Transfer Out
                </button>
                <button type="button" onclick="closeTransferOutModal()" class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Enroll in R3-01
function enrollR301() {
    if (confirm('Enroll this member in R3-01 (Baptismal Preparation)?')) {
        fetch('api/promote-to-r301.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pnk_id: <?php echo $recordId; ?> })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Successfully enrolled in R3-01!');
                location.reload();
            } else {
                alert(data.error || 'Failed to enroll in R3-01');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error enrolling in R3-01');
        });
    }
}

// Baptize member
function baptizeMember() {
    if (confirm('Baptize this member and add to Tarheta Registry?')) {
        fetch('api/baptize-and-promote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pnk_id: <?php echo $recordId; ?> })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Successfully baptized! Member added to Tarheta Registry.');
                window.location.href = 'pnk-registry.php';
            } else {
                alert(data.error || 'Failed to baptize member');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error baptizing member');
        });
    }
}

// Transfer Out Modal Functions
function showTransferOutModal() {
    document.getElementById('transferOutModal').classList.remove('hidden');
}

function closeTransferOutModal() {
    document.getElementById('transferOutModal').classList.add('hidden');
    document.getElementById('transferOutForm').reset();
}

// Handle Transfer Out Form Submission
document.getElementById('transferOutForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const transferTo = document.getElementById('transferToLocal').value;
    const transferToDistrict = document.getElementById('transferToDistrict').value;
    const transferReason = document.getElementById('transferReason').value;
    
    try {
        const response = await fetch('api/transfer-pnk-out.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                pnk_id: <?php echo $recordId; ?>,
                transfer_to: transferTo,
                transfer_to_district: transferToDistrict,
                transfer_reason: transferReason
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message || 'PNK member transferred out successfully!');
            location.reload();
        } else {
            alert(result.error || 'Failed to transfer member');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error transferring member');
    }
});

// Close modal on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeTransferOutModal();
        closeDeleteModal();
    }
});

// Delete Modal Functions
function showDeleteModal() {
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

async function confirmDelete() {
    try {
        const response = await fetch('api/delete-pnk.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: <?php echo $recordId; ?>,
                csrf_token: '<?php echo Security::generateCSRFToken(); ?>'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message || 'Record deleted successfully!');
            window.location.href = 'pnk-registry.php';
        } else {
            alert('Error: ' + (data.message || 'Failed to delete record'));
            closeDeleteModal();
        }
    } catch (error) {
        console.error('Delete error:', error);
        alert('An error occurred while deleting the record');
        closeDeleteModal();
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>