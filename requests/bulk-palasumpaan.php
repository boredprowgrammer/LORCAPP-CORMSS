<?php
/**
 * Bulk Palasumpaan Generator
 * Generate multiple oath certificates at once
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Check permissions - admin, district, and local users can access
$canManage = in_array($user['role'], ['admin', 'district', 'local']);

if (!$canManage) {
    $_SESSION['error'] = 'You do not have permission to bulk generate certificates.';
    header('Location: list.php');
    exit;
}

// Get all oath_taken requests
$query = "SELECT 
    r.request_id,
    r.last_name_encrypted,
    r.first_name_encrypted,
    r.middle_initial_encrypted,
    r.record_code,
    r.existing_officer_uuid,
    r.district_code,
    r.requested_department,
    r.requested_duty,
    r.oath_actual_date,
    r.status,
    d.district_name,
    l.local_name,
    o.last_name_encrypted as existing_last_name,
    o.first_name_encrypted as existing_first_name,
    o.middle_initial_encrypted as existing_middle_initial
FROM officer_requests r
LEFT JOIN districts d ON r.district_code = d.district_code
LEFT JOIN local_congregations l ON r.local_code = l.local_code
LEFT JOIN officers o ON r.existing_officer_uuid = o.officer_uuid
WHERE r.status IN ('ready_to_oath', 'oath_taken')";

$params = [];

// Role-based filtering
if ($user['role'] === 'district') {
    $query .= " AND r.district_code = ?";
    $params[] = $user['district_code'];
} elseif ($user['role'] === 'local') {
    $query .= " AND r.local_code = ?";
    $params[] = $user['local_code'];
}

$query .= " ORDER BY r.oath_actual_date DESC, r.requested_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Bulk Palasumpaan Generator';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Bulk Palasumpaan Generator</h1>
                <p class="text-gray-600 mt-1">Generate oath certificates for multiple officers at once</p>
            </div>
            <a href="list.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
                ← Back to List
            </a>
        </div>
    </div>

    <?php if (empty($requests)): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
        <svg class="w-12 h-12 text-yellow-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        <h3 class="text-lg font-semibold text-yellow-900 mb-2">No Certificates Available</h3>
        <p class="text-yellow-700">There are no officers with completed oaths (oath_taken status) to generate certificates for.</p>
    </div>
    <?php else: ?>
    
    <!-- Bulk Actions Form -->
    <div x-data="bulkGenerator()" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200">
        <!-- Oath Details Section -->
        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Common Oath Details</h2>
            <p class="text-sm text-gray-600 mb-4">These details will be applied to all selected certificates</p>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Oath Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" 
                           x-model="commonOathDate"
                           required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Lokal ng: <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           x-model="commonLokal"
                           required
                           placeholder="e.g., San Fernando"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Distrito Eklesiastiko ng: <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           x-model="commonDistrito"
                           required
                           placeholder="e.g., Pampanga East"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                </div>
            </div>
        </div>

        <!-- Selection Controls -->
        <div class="p-4 bg-white dark:bg-gray-800 border-b border-gray-200 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <label class="flex items-center">
                    <input type="checkbox" 
                           @change="toggleAll($event.target.checked)"
                           :checked="selectedRequests.length === totalRequests && totalRequests > 0"
                           class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                    <span class="ml-2 text-sm font-medium text-gray-700">Select All</span>
                </label>
                <span class="text-sm text-gray-600" x-text="`${selectedRequests.length} of ${totalRequests} selected`"></span>
            </div>
            
            <button @click="generateBulk()" 
                    :disabled="selectedRequests.length === 0 || !commonOathDate || !commonLokal || !commonDistrito"
                    :class="selectedRequests.length === 0 || !commonOathDate || !commonLokal || !commonDistrito ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700'"
                    class="px-6 py-2 text-white rounded-lg transition-colors font-medium">
                <span x-show="!generating">Generate <span x-text="selectedRequests.length"></span> Certificate<span x-show="selectedRequests.length !== 1">s</span></span>
                <span x-show="generating">Generating...</span>
            </button>
        </div>

        <!-- Requests List -->
        <div class="divide-y divide-gray-200">
            <?php foreach ($requests as $request): ?>
            <?php
                // Decrypt names
                if ($request['record_code'] === 'CODE D' && !empty($request['existing_officer_uuid'])) {
                    $decrypted = Encryption::decryptOfficerName(
                        $request['existing_last_name'],
                        $request['existing_first_name'],
                        $request['existing_middle_initial'],
                        $request['district_code']
                    );
                    $lastName = $decrypted['last_name'];
                    $firstName = $decrypted['first_name'];
                    $middleInitial = $decrypted['middle_initial'];
                } else {
                    $decrypted = Encryption::decryptOfficerName(
                        $request['last_name_encrypted'],
                        $request['first_name_encrypted'],
                        $request['middle_initial_encrypted'],
                        $request['district_code']
                    );
                    $lastName = $decrypted['last_name'];
                    $firstName = $decrypted['first_name'];
                    $middleInitial = $decrypted['middle_initial'];
                }
                
                $fullName = trim("$firstName " . ($middleInitial ? $middleInitial . '. ' : '') . "$lastName");
            ?>
            <label class="flex items-center p-4 hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" 
                       value="<?= $request['request_id'] ?>"
                       @change="toggleRequest(<?= $request['request_id'] ?>)"
                       :checked="selectedRequests.includes(<?= $request['request_id'] ?>)"
                       class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                
                <div class="ml-4 flex-1">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($fullName) ?></h3>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($request['requested_duty']) ?> - <?= htmlspecialchars($request['requested_department']) ?></p>
                            <div class="flex items-center space-x-3 mt-1">
                                <span class="text-xs text-gray-500"><?= htmlspecialchars($request['district_name']) ?></span>
                                <?php if ($request['local_name']): ?>
                                <span class="text-xs text-gray-400">•</span>
                                <span class="text-xs text-gray-500"><?= htmlspecialchars($request['local_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($request['oath_actual_date']): ?>
                                <span class="text-xs text-gray-400">•</span>
                                <span class="text-xs text-gray-500">Oath: <?= date('M d, Y', strtotime($request['oath_actual_date'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium text-green-800 bg-green-100 rounded-full">
                            <?= $request['record_code'] ?>
                        </span>
                    </div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function bulkGenerator() {
    return {
        selectedRequests: [],
        totalRequests: <?= count($requests) ?>,
        commonOathDate: '',
        commonLokal: '',
        commonDistrito: '',
        generating: false,
        
        toggleAll(checked) {
            if (checked) {
                this.selectedRequests = <?= json_encode(array_column($requests, 'request_id')) ?>;
            } else {
                this.selectedRequests = [];
            }
        },
        
        toggleRequest(requestId) {
            const index = this.selectedRequests.indexOf(requestId);
            if (index > -1) {
                this.selectedRequests.splice(index, 1);
            } else {
                this.selectedRequests.push(requestId);
            }
        },
        
        generateBulk() {
            if (this.selectedRequests.length === 0) {
                alert('Please select at least one request');
                return;
            }
            
            if (!this.commonOathDate || !this.commonLokal || !this.commonDistrito) {
                alert('Please fill in all oath details');
                return;
            }
            
            this.generating = true;
            
            // Open each certificate in a new tab with a delay
            let delay = 0;
            this.selectedRequests.forEach((requestId, index) => {
                setTimeout(() => {
                    const url = `../generate-palasumpaan.php?request_id=${requestId}&oath_date=${this.commonOathDate}&oath_lokal=${encodeURIComponent(this.commonLokal)}&oath_distrito=${encodeURIComponent(this.commonDistrito)}`;
                    window.open(url, '_blank');
                    
                    // Reset generating state after last one
                    if (index === this.selectedRequests.length - 1) {
                        setTimeout(() => {
                            this.generating = false;
                            alert(`Successfully opened ${this.selectedRequests.length} certificate(s) in new tabs`);
                        }, 500);
                    }
                }, delay);
                delay += 1000; // 1 second delay between each
            });
        }
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
