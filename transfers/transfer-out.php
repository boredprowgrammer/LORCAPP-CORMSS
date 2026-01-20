<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_transfer_out');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $officerUuid = Security::sanitizeInput($_POST['officer_uuid'] ?? '');
        $toLocalName = Security::sanitizeInput($_POST['to_local_name'] ?? '');
        $toDistrict = Security::sanitizeInput($_POST['to_district'] ?? '');
        $transferDate = Security::sanitizeInput($_POST['transfer_date'] ?? date('Y-m-d'));
        $notes = Security::sanitizeInput($_POST['notes'] ?? '');
        
        if (empty($officerUuid)) {
            $error = 'Please select an officer.';
        } elseif (empty($toLocalName) || empty($toDistrict)) {
            $error = 'Destination local congregation and district are required.';
        } else {
            try {
                // Get officer details
                $stmt = $db->prepare("SELECT * FROM officers WHERE officer_uuid = ? AND is_active = 1");
                $stmt->execute([$officerUuid]);
                $officer = $stmt->fetch();
                
                if (!$officer) {
                    $error = 'Officer not found or inactive.';
                } elseif (!hasDistrictAccess($officer['district_code']) || !hasLocalAccess($officer['local_code'])) {
                    $error = 'You do not have access to this officer.';
                } else {
                    $db->beginTransaction();
                    
                    // Calculate week number from transfer date
                    $transferDateObj = new DateTime($transferDate);
                    $weekNumber = (int)$transferDateObj->format('W');
                    $year = (int)$transferDateObj->format('Y');
                    $weekInfo = getWeekDateRange($weekNumber, $year);
                    
                    // Get officer's current departments
                    $stmt = $db->prepare("SELECT department, duty FROM officer_departments WHERE officer_id = ? AND is_active = 1 LIMIT 1");
                    $stmt->execute([$officer['officer_id']]);
                    $dept = $stmt->fetch();
                    
                    // Record transfer out
                    $stmt = $db->prepare("
                        INSERT INTO transfers (
                            officer_id, transfer_type, from_local_code, from_district_code,
                            to_local_code, to_district_code, department, duty,
                            transfer_date, week_number, year, processed_by, notes
                        ) VALUES (?, 'out', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $officer['officer_id'],
                        $officer['local_code'],
                        $officer['district_code'],
                        $toLocalName,
                        $toDistrict,
                        $dept['department'] ?? '',
                        $dept['duty'] ?? '',
                        $transferDate,
                        $weekInfo['week'],
                        $weekInfo['year'],
                        $currentUser['user_id'],
                        $notes ?: "Transfer out to $toLocalName, $toDistrict"
                    ]);
                    
                    // Deactivate officer
                    $stmt = $db->prepare("UPDATE officers SET is_active = 0 WHERE officer_id = ?");
                    $stmt->execute([$officer['officer_id']]);
                    
                    // Deactivate all departments
                    $stmt = $db->prepare("UPDATE officer_departments SET is_active = 0, removed_at = NOW() WHERE officer_id = ?");
                    $stmt->execute([$officer['officer_id']]);
                    
                    // Update headcount (-1)
                    $stmt = $db->prepare("
                        UPDATE headcount 
                        SET total_count = GREATEST(0, total_count - 1) 
                        WHERE district_code = ? AND local_code = ?
                    ");
                    $stmt->execute([$officer['district_code'], $officer['local_code']]);
                    
                    // Log audit
                    $stmt = $db->prepare("
                        INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $currentUser['user_id'],
                        'transfer_out',
                        'officers',
                        $officer['officer_id'],
                        json_encode(['from' => $officer['local_code'], 'to' => "$toLocalName, $toDistrict"]),
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $db->commit();
                    
                    setFlashMessage('success', 'Officer transferred out successfully! Headcount -1');
                    redirect(BASE_URL . '/officers/view.php?id=' . $officerUuid);
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Transfer out error: " . $e->getMessage());
                $error = 'An error occurred during the transfer.';
            }
        }
    }
}

$pageTitle = 'Transfer Out Officer';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Transfer Out Officer</h2>
        </div>
        
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-800 dark:text-yellow-300">Transfer Out Process</p>
                    <p class="text-sm text-yellow-700 dark:text-yellow-400 mt-1">This will mark the officer as inactive and decrease headcount by -1. Week number will be auto-generated based on transfer date.</p>
                </div>
            </div>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm font-medium text-red-800 dark:text-red-300"><?php echo Security::escape($error); ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="space-y-6" x-data="{ officerSelected: false, selectedOfficer: null }">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="officer_uuid" x-model="selectedOfficer">
            
            <!-- Select Officer -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-2 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Select Officer</h3>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Search Officer <span class="text-red-600">*</span>
                </label>
                <input 
                    type="text" 
                    id="officer-search"
                    placeholder="Type officer name to search..." 
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                    autocomplete="off"
                    @input="officerSelected = false"
                >
                <div id="search-results" class="mt-2"></div>
            </div>
            
            <div x-show="officerSelected" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm font-medium text-blue-800 dark:text-blue-300">Officer selected. Continue with transfer details below.</span>
                </div>
            </div>
            
            <!-- Destination Information -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-2 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Destination (Where officer is transferring to)</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Destination Local Congregation <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="to_local_name" 
                        placeholder="e.g., Manila Local" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Destination District <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="to_district" 
                        placeholder="e.g., District 3" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        required
                    >
                </div>
            </div>
            
            <!-- Transfer Details -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-2 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Transfer Details</h3>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Transfer Date <span class="text-red-600">*</span>
                </label>
                <input 
                    type="date" 
                    name="transfer_date" 
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                    value="<?php echo date('Y-m-d'); ?>"
                    max="<?php echo date('Y-m-d'); ?>"
                    required
                >
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Notes (Optional)
                </label>
                <textarea 
                    name="notes" 
                    placeholder="Add any additional notes about this transfer..." 
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors resize-none h-24"
                ></textarea>
            </div>
            
            <!-- Week Info Display -->
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-600 dark:text-green-400 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span class="text-sm font-medium text-green-800 dark:text-green-300">Current Week: <strong>Week <?php echo getCurrentWeekNumber(); ?>, <?php echo date('Y'); ?></strong></span>
                </div>
            </div>
            
            <!-- Submit Button -->
            <div class="flex items-center justify-end space-x-3 pt-4">
                <a href="<?php echo BASE_URL; ?>/officers/list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Cancel
                </a>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" x-bind:disabled="!officerSelected">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    Transfer Out Officer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Officer search with autocomplete
let searchTimeout;
const searchInput = document.getElementById('officer-search');
const searchResults = document.getElementById('search-results');

// Initialize Alpine.js listener for custom events
document.addEventListener('alpine:init', () => {
    window.addEventListener('officer-selected', (event) => {
        const form = document.querySelector('form');
        if (form && form.__x && form.__x.$data) {
            form.__x.$data.officerSelected = event.detail.selected;
            form.__x.$data.selectedOfficer = event.detail.uuid;
        }
    });
});

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        searchResults.innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch('<?php echo BASE_URL; ?>/api/search-officers.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                if (data.length === 0) {
                    searchResults.innerHTML = '<p class="text-sm text-center py-4 opacity-70">No officers found</p>';
                    return;
                }
                
                let html = '<div class="border border-base-300 dark:border-gray-600 rounded-lg divide-y divide-base-300 dark:divide-gray-600 max-h-60 overflow-y-auto bg-white dark:bg-gray-800">';
                data.forEach(officer => {
                    html += `
                        <div class="p-3 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer text-gray-900 dark:text-gray-100" onclick="selectOfficer('${officer.uuid}', '${officer.name.replace(/'/g, "\\'")}', '${officer.full_name.replace(/'/g, "\\'")}', '${officer.location.replace(/'/g, "\\'")}')">
                            <p class="font-semibold cursor-pointer name-mono" title="${officer.full_name}" ondblclick="this.textContent='${officer.full_name}'">${officer.name}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">${officer.location}</p>
                        </div>
                    `;
                });
                html += '</div>';
                
                searchResults.innerHTML = html;
            })
            .catch(error => {
                console.error('Search error:', error);
                searchResults.innerHTML = '<p class="text-sm text-error">Error searching officers</p>';
            });
    }, 300);
});

function selectOfficer(uuid, name, fullName, location) {
    searchInput.value = name + ' - ' + location;
    searchInput.title = fullName;
    searchInput.ondblclick = function() { this.value = fullName + ' - ' + location; };
    searchResults.innerHTML = '';
    
    // Update hidden input
    const hiddenInput = document.querySelector('input[name="officer_uuid"]');
    if (hiddenInput) {
        hiddenInput.value = uuid;
    }
    
    // Update Alpine.js data directly via x-model binding
    const form = document.querySelector('form');
    if (form && form.__x && form.__x.$data) {
        form.__x.$data.officerSelected = true;
        form.__x.$data.selectedOfficer = uuid;
    } else {
        // Fallback: dispatch custom event to trigger Alpine update
        window.dispatchEvent(new CustomEvent('officer-selected', { detail: { uuid: uuid, selected: true } }));
    }
    
    // Also manually update the submit button state
    const submitBtn = document.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
