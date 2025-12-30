<?php
/**
 * Bulk Update Officers
 * Update Purok and Grupo for multiple officers at once
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_edit_officers');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $officerIds = $_POST['officer_ids'] ?? [];
        $purok = Security::sanitizeInput($_POST['purok'] ?? '');
        $grupo = Security::sanitizeInput($_POST['grupo'] ?? '');
        $kapisanan = Security::sanitizeInput($_POST['kapisanan'] ?? '');
        $controlNumber = Security::sanitizeInput($_POST['control_number'] ?? '');
        $updatePurok = isset($_POST['update_purok']);
        $updateGrupo = isset($_POST['update_grupo']);
        $updateKapisanan = isset($_POST['update_kapisanan']);
        $updateControlNumber = isset($_POST['update_control_number']);
        
        if (empty($officerIds)) {
            $error = 'No officers selected.';
        } elseif (!$updatePurok && !$updateGrupo && !$updateKapisanan && !$updateControlNumber) {
            $error = 'Please select at least one field to update.';
        } else {
            try {
                $db->beginTransaction();
                
                $updatedCount = 0;
                $skippedCount = 0;
                
                foreach ($officerIds as $officerUuid) {
                    // Verify officer exists and check permissions
                    $stmt = $db->prepare("
                        SELECT officer_id, district_code, local_code 
                        FROM officers 
                        WHERE officer_uuid = ?
                    ");
                    $stmt->execute([$officerUuid]);
                    $officer = $stmt->fetch();
                    
                    if (!$officer) {
                        $skippedCount++;
                        continue;
                    }
                    
                    // Check access
                    if (!hasLocalAccess($officer['local_code'])) {
                        $skippedCount++;
                        continue;
                    }
                    
                    // Build update query dynamically based on selected fields
                    $updateFields = [];
                    $params = [];
                    
                    if ($updatePurok) {
                        $updateFields[] = "purok = ?";
                        $params[] = !empty($purok) ? $purok : null;
                    }
                    
                    if ($updateGrupo) {
                        $updateFields[] = "grupo = ?";
                        $params[] = !empty($grupo) ? $grupo : null;
                    }
                    
                    if ($updateKapisanan) {
                        $updateFields[] = "kapisanan = ?";
                        $params[] = !empty($kapisanan) ? $kapisanan : null;
                    }
                    
                    if ($updateControlNumber) {
                        $updateFields[] = "control_number = ?";
                        $params[] = !empty($controlNumber) ? $controlNumber : null;
                    }
                    
                    if (!empty($updateFields)) {
                        $params[] = $officerUuid;
                        
                        $sql = "UPDATE officers SET " . implode(', ', $updateFields) . " WHERE officer_uuid = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                        $updatedCount++;
                    }
                }
                
                $db->commit();
                
                $successMsg = "Successfully updated $updatedCount officer(s).";
                if ($skippedCount > 0) {
                    $successMsg .= " Skipped $skippedCount officer(s) due to permissions or not found.";
                }
                
                setFlashMessage('success', $successMsg);
                redirect(getBaseUrl() . '/officers/list.php');
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Bulk update error: " . $e->getMessage());
                $error = 'Error updating officers: ' . $e->getMessage();
            }
        }
    }
}

// Get officers for selection
$officers = [];
try {
    if ($currentUser['role'] === 'admin') {
        $stmt = $db->query("
            SELECT o.officer_uuid, o.officer_id, o.last_name_encrypted, o.first_name_encrypted, 
                   o.middle_initial_encrypted, o.district_code, o.local_code, o.purok, o.grupo, 
                   o.control_number, d.district_name, lc.local_name
            FROM officers o
            JOIN districts d ON o.district_code = d.district_code
            JOIN local_congregations lc ON o.local_code = lc.local_code
            WHERE o.is_active = 1
            ORDER BY d.district_name, lc.local_name
        ");
    } elseif ($currentUser['role'] === 'district') {
        $stmt = $db->prepare("
            SELECT o.officer_uuid, o.officer_id, o.last_name_encrypted, o.first_name_encrypted, 
                   o.middle_initial_encrypted, o.district_code, o.local_code, o.purok, o.grupo, 
                   o.control_number, d.district_name, lc.local_name
            FROM officers o
            JOIN districts d ON o.district_code = d.district_code
            JOIN local_congregations lc ON o.local_code = lc.local_code
            WHERE o.district_code = ? AND o.is_active = 1
            ORDER BY lc.local_name
        ");
        $stmt->execute([$currentUser['district_code']]);
    } else {
        $stmt = $db->prepare("
            SELECT o.officer_uuid, o.officer_id, o.last_name_encrypted, o.first_name_encrypted, 
                   o.middle_initial_encrypted, o.district_code, o.local_code, o.purok, o.grupo, 
                   o.control_number, d.district_name, lc.local_name
            FROM officers o
            JOIN districts d ON o.district_code = d.district_code
            JOIN local_congregations lc ON o.local_code = lc.local_code
            WHERE o.local_code = ? AND o.is_active = 1
            ORDER BY o.officer_id
        ");
        $stmt->execute([$currentUser['local_code']]);
    }
    
    $officers = $stmt->fetchAll();
    
    // Decrypt names
    foreach ($officers as &$officer) {
        $decrypted = Encryption::decryptOfficerName(
            $officer['last_name_encrypted'],
            $officer['first_name_encrypted'],
            $officer['middle_initial_encrypted'],
            $officer['district_code']
        );
        $officer['full_name'] = trim($decrypted['last_name'] . ', ' . $decrypted['first_name'] . 
                                     (!empty($decrypted['middle_initial']) ? ' ' . $decrypted['middle_initial'] . '.' : ''));
    }
    
} catch (Exception $e) {
    error_log("Load officers error: " . $e->getMessage());
    $error = 'Error loading officers.';
}

$pageTitle = 'Bulk Update Officers';
ob_start();
?>

<div class="container mx-auto px-4 py-6">
    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Bulk Update Officers</h1>
                        <p class="text-sm text-gray-500">Update Purok, Grupo, or Control Number for multiple officers</p>
                    </div>
                </div>
                <a href="<?php echo getBaseUrl(); ?>/officers/list.php" 
                   class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                    Back to List
                </a>
            </div>
        </div>
        
        <form method="POST" class="p-6" id="bulkUpdateForm">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            
            <!-- Field Selection -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-semibold text-blue-900 mb-3">Select Fields to Update</h3>
                <p class="text-xs text-blue-700 mb-4">Check which fields you want to update, then set their values below:</p>
                
                <div class="space-y-3">
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="update_purok" id="update_purok" class="w-4 h-4 text-blue-600 rounded">
                        <span class="text-sm font-medium text-gray-700">Update Purok</span>
                    </label>
                    
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="update_grupo" id="update_grupo" class="w-4 h-4 text-blue-600 rounded">
                        <span class="text-sm font-medium text-gray-700">Update Grupo</span>
                    </label>
                    
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="update_kapisanan" id="update_kapisanan" class="w-4 h-4 text-blue-600 rounded">
                        <span class="text-sm font-medium text-gray-700">Update Kapisanan</span>
                    </label>
                    
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="update_control_number" id="update_control_number" class="w-4 h-4 text-blue-600 rounded">
                        <span class="text-sm font-medium text-gray-700">Update Control Number</span>
                    </label>
                </div>
            </div>
            
            <!-- Values to Set -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Purok Value</label>
                    <input 
                        type="text" 
                        name="purok" 
                        id="purok_value"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                        placeholder="Enter purok"
                        disabled
                    >
                    <p class="text-xs text-gray-500 mt-1">Leave empty to clear existing value</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Grupo Value</label>
                    <input 
                        type="text" 
                        name="grupo" 
                        id="grupo_value"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                        placeholder="Enter grupo"
                        disabled
                    >
                    <p class="text-xs text-gray-500 mt-1">Leave empty to clear existing value</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Kapisanan Value</label>
                    <select 
                        name="kapisanan" 
                        id="kapisanan_value"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                        disabled>
                        <option value="">Select Kapisanan</option>
                        <option value="Buklod">Buklod</option>
                        <option value="Kadiwa">Kadiwa</option>
                        <option value="Binhi">Binhi</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Leave empty to clear existing value</p>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-1 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Control Number Value</label>
                    <input 
                        type="text" 
                        name="control_number" 
                        id="control_number_value"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                        placeholder="Enter control number"
                        disabled
                    >
                    <p class="text-xs text-gray-500 mt-1">Leave empty to clear existing value</p>
                </div>
            </div>
            
            <!-- Officer Selection -->
            <div class="mb-6">
                <div class="flex items-center justify-between mb-3">
                    <label class="block text-sm font-medium text-gray-700">
                        Select Officers <span class="text-red-600">*</span>
                    </label>
                    <div class="space-x-2">
                        <button type="button" onclick="selectAll()" class="text-sm text-blue-600 hover:text-blue-800">Select All</button>
                        <button type="button" onclick="selectNone()" class="text-sm text-blue-600 hover:text-blue-800">Select None</button>
                    </div>
                </div>
                
                <div class="border border-gray-300 rounded-lg max-h-96 overflow-y-auto">
                    <?php if (empty($officers)): ?>
                        <div class="p-4 text-center text-gray-500">
                            No officers found.
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-200">
                            <?php foreach ($officers as $officer): ?>
                                <label class="flex items-center p-3 hover:bg-gray-50 cursor-pointer">
                                    <input 
                                        type="checkbox" 
                                        name="officer_ids[]" 
                                        value="<?php echo Security::escape($officer['officer_uuid']); ?>"
                                        class="officer-checkbox w-4 h-4 text-blue-600 rounded mr-3"
                                    >
                                    <div class="flex-1">
                                        <div class="font-medium text-gray-900">
                                            <?php echo Security::escape($officer['full_name']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo Security::escape($officer['district_name']); ?> - 
                                            <?php echo Security::escape($officer['local_name']); ?>
                                            <?php if (!empty($officer['purok'])): ?>
                                                | Purok: <?php echo Security::escape($officer['purok']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($officer['grupo'])): ?>
                                                | Grupo: <?php echo Security::escape($officer['grupo']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($officer['control_number'])): ?>
                                                | Control #: <?php echo Security::escape($officer['control_number']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-gray-500 mt-2">
                    <span id="selected-count">0</span> officer(s) selected
                </p>
            </div>
            
            <!-- Submit Button -->
            <div class="flex items-center justify-end space-x-3">
                <a href="<?php echo getBaseUrl(); ?>/officers/list.php" 
                   class="px-6 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                    Cancel
                </a>
                <button 
                    type="submit" 
                    class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                    Update Officers
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Enable/disable input fields based on checkbox selection
document.getElementById('update_purok').addEventListener('change', function() {
    document.getElementById('purok_value').disabled = !this.checked;
});

document.getElementById('update_grupo').addEventListener('change', function() {
    document.getElementById('grupo_value').disabled = !this.checked;
});

document.getElementById('update_kapisanan').addEventListener('change', function() {
    document.getElementById('kapisanan_value').disabled = !this.checked;
});

document.getElementById('update_control_number').addEventListener('change', function() {
    document.getElementById('control_number_value').disabled = !this.checked;
});

// Select all officers
function selectAll() {
    document.querySelectorAll('.officer-checkbox').forEach(cb => cb.checked = true);
    updateSelectedCount();
}

// Deselect all officers
function selectNone() {
    document.querySelectorAll('.officer-checkbox').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

// Update selected count
function updateSelectedCount() {
    const count = document.querySelectorAll('.officer-checkbox:checked').length;
    document.getElementById('selected-count').textContent = count;
}

// Listen for checkbox changes
document.querySelectorAll('.officer-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

// Form validation
document.getElementById('bulkUpdateForm').addEventListener('submit', function(e) {
    const selectedOfficers = document.querySelectorAll('.officer-checkbox:checked').length;
    const updatePurok = document.getElementById('update_purok').checked;
    const updateGrupo = document.getElementById('update_grupo').checked;
    const updateControlNumber = document.getElementById('update_control_number').checked;
    
    if (selectedOfficers === 0) {
        e.preventDefault();
        alert('Please select at least one officer to update.');
        return false;
    }
    
    if (!updatePurok && !updateGrupo && !updateControlNumber) {
        e.preventDefault();
        alert('Please select at least one field to update.');
        return false;
    }
    
    if (!confirm(`Are you sure you want to update ${selectedOfficers} officer(s)?`)) {
        e.preventDefault();
        return false;
    }
});
</script>

<?php
// Close the layout
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
