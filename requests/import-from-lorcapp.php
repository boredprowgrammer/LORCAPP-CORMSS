<?php
/**
 * Import Officers from LORCAPP
 * Import officer records from LORCAPP R-201 database
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

// Also load LORCAPP config to access its database
require_once __DIR__ . '/../lorcapp/includes/config.php';
require_once __DIR__ . '/../lorcapp/includes/encryption.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Check permissions - admin and district users only
$canManage = in_array($user['role'], ['admin', 'district', 'local']);

if (!$canManage) {
    $_SESSION['error'] = 'You do not have permission to import officers.';
    header('Location: list.php');
    exit;
}

// Get LORCAPP connection
$lorcapp_conn = getDbConnection();

// Handle import action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    $lorcapp_ids = $_POST['selected_officers'] ?? [];
    $district_code = $_POST['district_code'];
    $local_code = $_POST['local_code'] ?? null;
    $department = $_POST['department'];
    $duty = $_POST['duty'];
    
    if (empty($lorcapp_ids) || empty($district_code) || empty($department) || empty($duty)) {
        $_SESSION['error'] = 'Please select officers and fill all required fields.';
    } else {
        $imported_count = 0;
        $errors = [];
        
        foreach ($lorcapp_ids as $lorcapp_id) {
            try {
                // Get officer data from LORCAPP
                $stmt = $lorcapp_conn->prepare("SELECT * FROM r201_members WHERE id = ?");
                $stmt->bind_param("s", $lorcapp_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    $errors[] = "Officer with ID $lorcapp_id not found in LORCAPP";
                    continue;
                }
                
                $officer = $result->fetch_assoc();
                
                // Decrypt names if encrypted
                $officer = decryptRecordNames($officer);
                
                // Encrypt names for CORegistry
                $encrypted = Encryption::encryptOfficerName(
                    $officer['father_surname'] ?? '',
                    $officer['given_name'] ?? '',
                    $officer['middle_name'] ? substr($officer['middle_name'], 0, 1) : '',
                    $district_code
                );
                
                // Check if already imported
                $check_stmt = $db->prepare("SELECT request_id FROM officer_requests WHERE lorcapp_id = ?");
                $check_stmt->execute([$lorcapp_id]);
                
                if ($check_stmt->rowCount() > 0) {
                    $errors[] = "Officer {$officer['given_name']} {$officer['father_surname']} already imported";
                    continue;
                }
                
                // Insert into officer_requests
                $insert_stmt = $db->prepare("
                    INSERT INTO officer_requests (
                        last_name_encrypted,
                        first_name_encrypted,
                        middle_initial_encrypted,
                        district_code,
                        local_code,
                        record_code,
                        requested_department,
                        requested_duty,
                        status,
                        requested_by,
                        requested_at,
                        lorcapp_id,
                        is_imported
                    ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 'pending', ?, NOW(), ?, 1)
                ");
                
                $insert_stmt->execute([
                    $encrypted['last_name_encrypted'],
                    $encrypted['first_name_encrypted'],
                    $encrypted['middle_initial_encrypted'],
                    $district_code,
                    $local_code,
                    $department,
                    $duty,
                    $user['user_id'],
                    $lorcapp_id
                ]);
                
                $imported_count++;
                
            } catch (Exception $e) {
                $errors[] = "Error importing officer: " . $e->getMessage();
            }
        }
        
        if ($imported_count > 0) {
            $_SESSION['success'] = "Successfully imported $imported_count officer(s).";
        }
        
        if (!empty($errors)) {
            $_SESSION['error'] = implode('<br>', $errors);
        }
        
        header('Location: import-from-lorcapp.php');
        exit;
    }
}

// Get search parameters
$searchQuery = $_GET['search'] ?? '';
$districtFilter = $_GET['district'] ?? '';

// Fetch ALL records from LORCAPP (since there are only ~24 records)
// This ensures we can search properly on decrypted names
$lorcapp_query = "SELECT id, given_name, father_surname, mother_surname, birth_date 
                  FROM r201_members 
                  ORDER BY created_at DESC";

$lorcapp_stmt = $lorcapp_conn->prepare($lorcapp_query);
$lorcapp_stmt->execute();
$lorcapp_result = $lorcapp_stmt->get_result();

// Decrypt all records first
$all_records = [];
while ($row = $lorcapp_result->fetch_assoc()) {
    $all_records[] = decryptRecordNames($row);
}

// Check which officers are already linked
$linked_officers = [];
if (!empty($all_records)) {
    $lorcapp_ids = array_column($all_records, 'id');
    
    // Validate and sanitize IDs to prevent injection
    $lorcapp_ids = array_filter($lorcapp_ids, function($id) {
        return is_numeric($id) || (is_string($id) && preg_match('/^[a-zA-Z0-9_-]+$/', $id));
    });
    
    if (!empty($lorcapp_ids)) {
        $placeholders = implode(',', array_fill(0, count($lorcapp_ids), '?'));
        $check_stmt = $db->prepare("SELECT lorcapp_id FROM officer_requests WHERE lorcapp_id IN ($placeholders)");
        $check_stmt->execute($lorcapp_ids);
        $linked_results = $check_stmt->fetchAll(PDO::FETCH_COLUMN);
        $linked_officers = array_flip($linked_results); // Use as a set for O(1) lookup
    }
}

// If we have a search query, filter decrypted records
$lorcapp_officers = [];
if (!empty($searchQuery)) {
    $query_lower = strtolower($searchQuery);
    foreach ($all_records as $record) {
        $given_name_lower = strtolower($record['given_name'] ?? '');
        $father_surname_lower = strtolower($record['father_surname'] ?? '');
        $mother_surname_lower = strtolower($record['mother_surname'] ?? '');
        
        // Check if query matches any name field
        if (strpos($given_name_lower, $query_lower) !== false ||
            strpos($father_surname_lower, $query_lower) !== false ||
            strpos($mother_surname_lower, $query_lower) !== false) {
            $lorcapp_officers[] = $record;
        }
    }
} else {
    $lorcapp_officers = $all_records;
}

// Get districts
$districts_stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_name");
$districts = $districts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments using the function
$departments = getDepartments();

$pageTitle = 'Import from LORCAPP';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Import Officers from LORCAPP</h1>
                <p class="text-gray-600 mt-1">Import officer records from LORCAPP R-201 database</p>
            </div>
            <div class="flex items-center space-x-3">
                <a href="link-to-lorcapp.php" class="px-4 py-2 border border-blue-300 text-blue-700 rounded-lg hover:bg-blue-50 transition-colors">
                    Link Existing Requests
                </a>
                <a href="list.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
                    ← Back to List
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="mb-4 bg-green-50 border border-green-200 rounded-lg p-4">
        <p class="text-green-800"><?= $_SESSION['success'] ?></p>
    </div>
    <?php unset($_SESSION['success']); endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-4 bg-red-50 border border-red-200 rounded-lg p-4">
        <p class="text-red-800"><?= $_SESSION['error'] ?></p>
    </div>
    <?php unset($_SESSION['error']); endif; ?>

    <!-- Search Form -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <form method="GET" class="flex gap-4">
            <div class="flex-1">
                <input 
                    type="text" 
                    name="search" 
                    value="<?= htmlspecialchars($searchQuery) ?>"
                    placeholder="Search by name..." 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                Search
            </button>
        </form>
    </div>

    <!-- Import Form -->
    <form method="POST" x-data="importForm()" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200">
        <input type="hidden" name="action" value="import">
        
        <!-- Import Configuration -->
        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Import Configuration</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        District <span class="text-red-500">*</span>
                    </label>
                    <?php if ($user['role'] === 'local'): ?>
                        <input type="text" value="<?php 
                            foreach ($districts as $district) {
                                if ($district['district_code'] === $user['district_code']) {
                                    echo htmlspecialchars($district['district_name']);
                                    break;
                                }
                            }
                        ?>" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100">
                        <input type="hidden" name="district_code" value="<?= htmlspecialchars($user['district_code']) ?>">
                    <?php else: ?>
                        <select name="district_code" 
                                x-model="districtCode"
                                @change="loadLocals()"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                id="district-select">
                            <option value="">Select District</option>
                            <?php foreach ($districts as $district): ?>
                            <option value="<?= $district['district_code'] ?>"><?= htmlspecialchars($district['district_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Local Congregation
                    </label>
                    <?php if ($user['role'] === 'local'): ?>
                        <?php
                        // Get local name for display
                        $stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
                        $stmt->execute([$user['local_code']]);
                        $localInfo = $stmt->fetch();
                        ?>
                        <input type="text" value="<?= htmlspecialchars($localInfo['local_name'] ?? $user['local_code']) ?>" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100">
                        <input type="hidden" name="local_code" value="<?= htmlspecialchars($user['local_code']) ?>">
                    <?php else: ?>
                        <div class="relative">
                            <input 
                                type="text" 
                                id="local-display"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
                                placeholder="Select Local Congregation"
                                readonly
                                onclick="openLocalModal()"
                                value=""
                            >
                            <input type="hidden" name="local_code" id="local-value" x-model="localCode">
                            <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Department <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="department-display"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
                            placeholder="Select Department"
                            readonly
                            onclick="openDepartmentModal()"
                            value=""
                        >
                        <input type="hidden" name="department" id="department-value" required>
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Duty <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           name="duty" 
                           required
                           placeholder="e.g., Secretary"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
            </div>
        </div>

        <!-- Selection Controls -->
        <div class="p-4 bg-white dark:bg-gray-800 border-b border-gray-200 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <label class="flex items-center">
                    <input type="checkbox" 
                           @change="toggleAll($event.target.checked)"
                           :checked="selected.length === <?= count($lorcapp_officers) ?> && <?= count($lorcapp_officers) ?> > 0"
                           class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="ml-2 text-sm font-medium text-gray-700">Select All</span>
                </label>
                <span class="text-sm text-gray-600" x-text="`${selected.length} selected`"></span>
            </div>
            
            <button type="submit" 
                    :disabled="selected.length === 0"
                    :class="selected.length === 0 ? 'bg-gray-300 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700'"
                    class="px-6 py-2 text-white rounded-lg transition-colors font-medium">
                Import <span x-text="selected.length"></span> Officer<span x-show="selected.length !== 1">s</span>
            </button>
        </div>

        <!-- Officers List -->
        <div class="divide-y divide-gray-200">
            <?php if (empty($lorcapp_officers)): ?>
            <div class="p-8 text-center text-gray-500">
                <p>No officers found. Try adjusting your search.</p>
            </div>
            <?php else: ?>
                <?php foreach ($lorcapp_officers as $officer): ?>
                <?php $isLinked = isset($linked_officers[$officer['id']]); ?>
                <label class="flex items-center p-4 hover:bg-gray-50 cursor-pointer <?= $isLinked ? 'bg-green-50' : '' ?>">
                    <input type="checkbox" 
                           name="selected_officers[]"
                           value="<?= $officer['id'] ?>"
                           @change="toggleOfficer('<?= $officer['id'] ?>')"
                           :checked="selected.includes('<?= $officer['id'] ?>')"
                           <?= $isLinked ? 'disabled' : '' ?>
                           class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 <?= $isLinked ? 'opacity-50 cursor-not-allowed' : '' ?>">
                    
                    <div class="ml-4 flex-1">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        <?= htmlspecialchars($officer['given_name'] . ' ' . $officer['father_surname']) ?>
                                    </h3>
                                    <?php if ($isLinked): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium text-green-800 bg-green-100 rounded-full">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 101.414-1.414 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z" clip-rule="evenodd"></path>
                                        </svg>
                                        Linked
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($officer['mother_surname'])): ?>
                                <p class="text-xs text-gray-600">Mother's Surname: <?= htmlspecialchars($officer['mother_surname']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($officer['birth_date'])): ?>
                                <p class="text-xs text-gray-500">Born: <?= date('M d, Y', strtotime($officer['birth_date'])) ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                <?= htmlspecialchars($officer['id']) ?>
                            </span>
                        </div>
                    </div>
                </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
function importForm() {
    return {
        selected: [],
        districtCode: '<?= $user['role'] === 'local' ? htmlspecialchars($user['district_code']) : '' ?>',
        localCode: '<?= $user['role'] === 'local' ? htmlspecialchars($user['local_code']) : '' ?>',
        locals: [],
        
        toggleAll(checked) {
            if (checked) {
                this.selected = <?= json_encode(array_column($lorcapp_officers, 'id')) ?>;
            } else {
                this.selected = [];
            }
        },
        
        toggleOfficer(id) {
            const index = this.selected.indexOf(id);
            if (index > -1) {
                this.selected.splice(index, 1);
            } else {
                this.selected.push(id);
            }
        },
        
        async loadLocals() {
            if (!this.districtCode) {
                this.locals = [];
                return;
            }
            
            try {
                const response = await fetch(`../api/get-locals.php?district=${this.districtCode}`);
                const data = await response.json();
                this.locals = data;
                loadLocalsForModal(this.districtCode);
            } catch (error) {
                console.error('Error loading locals:', error);
                this.locals = [];
            }
        }
    }
}

// Modal functions
let currentLocals = [];

document.getElementById('district-select')?.addEventListener('change', function() {
    const districtCode = this.value;
    const localDisplay = document.getElementById('local-display');
    const localValue = document.getElementById('local-value');
    
    if (!districtCode) {
        localDisplay.value = '';
        localValue.value = '';
        return;
    }
    
    // Clear current selection
    localDisplay.value = '';
    localValue.value = '';
});

function loadLocalsForModal(districtCode) {
    fetch('../api/get-locals.php?district=' + districtCode)
        .then(response => response.json())
        .then(data => {
            currentLocals = data;
        })
        .catch(error => console.error('Error loading locals:', error));
}

function openLocalModal() {
    const districtCode = document.getElementById('district-select').value;
    if (!districtCode) {
        alert('Please select a district first');
        return;
    }
    
    const modal = document.getElementById('local-modal');
    const listContainer = document.getElementById('local-list');
    
    // Populate list
    listContainer.innerHTML = '';
    currentLocals.forEach(local => {
        const div = document.createElement('div');
        div.className = 'local-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100';
        div.textContent = local.local_name;
        div.onclick = () => selectLocal(local.local_code, local.local_name);
        listContainer.appendChild(div);
    });
    
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('local-search').focus();
}

function closeLocalModal() {
    const modal = document.getElementById('local-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('local-search').value = '';
    filterLocals();
}

function selectLocal(code, name) {
    document.getElementById('local-value').value = code;
    document.getElementById('local-display').value = name;
    closeLocalModal();
}

function filterLocals() {
    const search = document.getElementById('local-search').value.toLowerCase();
    const items = document.querySelectorAll('.local-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}

// Department Modal
function openDepartmentModal() {
    const modal = document.getElementById('department-modal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('department-search').focus();
}

function closeDepartmentModal() {
    const modal = document.getElementById('department-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('department-search').value = '';
    filterDepartments();
}

function selectDepartment(value) {
    document.getElementById('department-value').value = value;
    document.getElementById('department-display').value = value;
    closeDepartmentModal();
}

function filterDepartments() {
    const search = document.getElementById('department-search').value.toLowerCase();
    const items = document.querySelectorAll('.department-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}
</script>

<!-- Department Modal -->
<div id="department-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeDepartmentModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Select Department</h3>
                <button type="button" onclick="closeDepartmentModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Search -->
            <div class="p-4 border-b">
                <input 
                    type="text" 
                    id="department-search"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Search departments..."
                    oninput="filterDepartments()"
                >
            </div>
            
            <!-- List -->
            <div class="overflow-y-auto flex-1">
                <?php foreach ($departments as $dept): ?>
                    <div class="department-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100"
                         onclick="selectDepartment('<?= htmlspecialchars($dept) ?>')">
                        <span class="text-gray-900"><?= htmlspecialchars($dept) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Local Modal -->
<div id="local-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeLocalModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Select Local Congregation</h3>
                <button type="button" onclick="closeLocalModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Search -->
            <div class="p-4 border-b">
                <input 
                    type="text" 
                    id="local-search"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Search local congregations..."
                    oninput="filterLocals()"
                >
            </div>
            
            <!-- List -->
            <div id="local-list" class="overflow-y-auto flex-1">
                <!-- Will be populated dynamically -->
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
