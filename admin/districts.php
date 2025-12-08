<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_manage_districts');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Handle district creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_district') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $districtCode = strtoupper(Security::sanitizeInput($_POST['district_code'] ?? ''));
        $districtName = Security::sanitizeInput($_POST['district_name'] ?? '');
        
        if (empty($districtCode) || empty($districtName)) {
            $error = 'District code and name are required.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO districts (district_code, district_name) VALUES (?, ?)");
                $stmt->execute([$districtCode, $districtName]);
                
                // Initialize headcount for district
                $stmt = $db->prepare("INSERT INTO headcount (district_code, local_code, total_count) VALUES (?, 'DISTRICT_TOTAL', 0)");
                $stmt->execute([$districtCode]);
                
                $success = 'District created successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'District code already exists.';
                } else {
                    error_log("Create district error: " . $e->getMessage());
                    $error = 'An error occurred while creating the district.';
                }
            }
        }
    }
}

// Handle local creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_local') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $localCode = strtoupper(Security::sanitizeInput($_POST['local_code'] ?? ''));
        $localName = Security::sanitizeInput($_POST['local_name'] ?? '');
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        
        if (empty($localCode) || empty($localName) || empty($districtCode)) {
            $error = 'All fields are required.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO local_congregations (local_code, local_name, district_code) VALUES (?, ?, ?)");
                $stmt->execute([$localCode, $localName, $districtCode]);
                
                // Initialize headcount
                $stmt = $db->prepare("INSERT INTO headcount (district_code, local_code, total_count) VALUES (?, ?, 0)");
                $stmt->execute([$districtCode, $localCode]);
                
                $success = 'Local congregation created successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Local code already exists.';
                } else {
                    error_log("Create local error: " . $e->getMessage());
                    $error = 'An error occurred while creating the local congregation.';
                }
            }
        }
    }
}

// Get all districts and locals
try {
    $stmt = $db->query("SELECT * FROM districts ORDER BY district_name");
    $districts = $stmt->fetchAll();
    
    $stmt = $db->query("
        SELECT lc.*, d.district_name, 
               (SELECT COUNT(*) FROM officers WHERE local_code = lc.local_code AND is_active = 1) as officer_count
        FROM local_congregations lc
        JOIN districts d ON lc.district_code = d.district_code
        ORDER BY d.district_name, lc.local_name
    ");
    $locals = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Load districts error: " . $e->getMessage());
    $districts = [];
    $locals = [];
}

$pageTitle = 'Districts & Local Congregations';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-3xl font-bold text-gray-900">Districts & Local Congregations</h2>
            <p class="text-sm text-gray-500">Manage organizational structure</p>
        </div>
        <div class="flex gap-2">
            <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'createDistrictModal' }))" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add District
            </button>
            <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'createLocalModal' }))" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add Local
            </button>
        </div>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-red-800"><?php echo Security::escape($error); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-green-800"><?php echo Security::escape($success); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Districts -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center gap-2 mb-4">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
            </svg>
            <h3 class="text-lg font-bold text-gray-900">Districts</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($districts as $district): ?>
                <?php
                // Count locals in district
                $localCount = count(array_filter($locals, function($l) use ($district) {
                    return $l['district_code'] === $district['district_code'];
                }));
                ?>
                <div class="bg-gray-50 rounded-lg shadow-sm border border-gray-200 p-4">
                    <h4 class="text-lg font-bold text-gray-900 mb-1"><?php echo Security::escape($district['district_name']); ?></h4>
                    <p class="text-xs text-gray-500 mb-3">Code: <?php echo Security::escape($district['district_code']); ?></p>
                    <div class="border-t border-gray-200 my-2"></div>
                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                        <div class="text-xs text-gray-500 mb-1">Local Congregations</div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo $localCount; ?></div>
                    </div>
                    <div class="flex items-center text-xs text-gray-500 mt-2">
                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Created: <?php echo formatDate($district['created_at']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($districts)): ?>
                <div class="col-span-full text-center py-8">
                    <p class="text-gray-500">No districts yet. Create one to get started.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Local Congregations -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center gap-2 mb-4">
            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <h3 class="text-lg font-bold text-gray-900">Local Congregations</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Local Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">District</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Active Officers</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($locals)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-8">
                                <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                </svg>
                                <p class="text-sm text-gray-500">No local congregations yet</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($locals as $local): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-gray-900"><?php echo Security::escape($local['local_name']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"><?php echo Security::escape($local['local_code']); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?php echo Security::escape($local['district_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo Security::escape($local['district_code']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-sm font-semibold bg-blue-100 text-blue-800">
                                        <?php echo number_format($local['officer_count']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?php echo formatDate($local['created_at']); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create District Modal -->
<div x-data="{ show: false }" 
     @open-modal.window="show = ($event.detail === 'createDistrictModal')"
     @keydown.escape.window="show = false"
     x-show="show"
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div @click="show = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div @click.stop class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-6 transform transition-all"
             x-show="show"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Create New District</h3>
                <button @click="show = false" type="button" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="create_district">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">District Code *</label>
                <input 
                    type="text" 
                    name="district_code" 
                    placeholder="e.g., DST001" 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                    maxlength="20"
                    required
                >
                <p class="text-xs text-gray-500 mt-1">Use uppercase letters and numbers</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">District Name *</label>
                <input 
                    type="text" 
                    name="district_name" 
                    placeholder="e.g., District 1" 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                    required
                >
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <button type="button" @click="show = false" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">Create District</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Create Local Modal -->
<div x-data="{ show: false }" 
     @open-modal.window="show = ($event.detail === 'createLocalModal')"
     @keydown.escape.window="show = false"
     x-show="show"
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div @click="show = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div @click.stop class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-6 transform transition-all"
             x-show="show"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Create New Local Congregation</h3>
                <button @click="show = false" type="button" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="create_local">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Local Code *</label>
                <input 
                    type="text" 
                    name="local_code" 
                    placeholder="e.g., LCL001" 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                    maxlength="20"
                    required
                >
                <p class="text-xs text-gray-500 mt-1">Use uppercase letters and numbers</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Local Name *</label>
                <input 
                    type="text" 
                    name="local_name" 
                    placeholder="e.g., San Juan Local" 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                    required
                >
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">District *</label>
                <select name="district_code" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                    <option value="">Select District</option>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?php echo Security::escape($district['district_code']); ?>">
                            <?php echo Security::escape($district['district_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <button type="button" @click="show = false" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-colors">Create Local</button>
            </div>
        </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
