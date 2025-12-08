<?php
/**
 * Link Officer Requests to LORCAPP Records
 * Connect existing officer requests with LORCAPP R-201 records
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
    $_SESSION['error'] = 'You do not have permission to link officers.';
    header('Location: list.php');
    exit;
}

// Handle link action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'link') {
    $request_id = $_POST['request_id'];
    $lorcapp_id = $_POST['lorcapp_id'];
    
    try {
        $stmt = $db->prepare("UPDATE officer_requests SET lorcapp_id = ? WHERE request_id = ?");
        $stmt->execute([$lorcapp_id, $request_id]);
        
        $_SESSION['success'] = 'Successfully linked officer request to LORCAPP record.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error linking record: ' . $e->getMessage();
    }
    
    header('Location: link-to-lorcapp.php');
    exit;
}

// Handle unlink action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unlink') {
    $request_id = $_POST['request_id'];
    
    try {
        $stmt = $db->prepare("UPDATE officer_requests SET lorcapp_id = NULL WHERE request_id = ?");
        $stmt->execute([$request_id]);
        
        $_SESSION['success'] = 'Successfully unlinked LORCAPP record.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error unlinking record: ' . $e->getMessage();
    }
    
    header('Location: link-to-lorcapp.php');
    exit;
}

// Get officer requests without LORCAPP link
$query = "SELECT 
    r.request_id,
    r.last_name_encrypted,
    r.first_name_encrypted,
    r.middle_initial_encrypted,
    r.record_code,
    r.existing_officer_uuid,
    r.district_code,
    r.local_code,
    r.requested_department,
    r.requested_duty,
    r.status,
    r.lorcapp_id,
    d.district_name,
    l.local_name,
    o.last_name_encrypted as existing_last_name,
    o.first_name_encrypted as existing_first_name,
    o.middle_initial_encrypted as existing_middle_initial
FROM officer_requests r
LEFT JOIN districts d ON r.district_code = d.district_code
LEFT JOIN local_congregations l ON r.local_code = l.local_code
LEFT JOIN officers o ON r.existing_officer_uuid = o.officer_uuid
WHERE 1=1";

$params = [];

// Role-based filtering
if ($user['role'] === 'district') {
    $query .= " AND r.district_code = ?";
    $params[] = $user['district_code'];
} elseif ($user['role'] === 'local') {
    $query .= " AND r.local_code = ?";
    $params[] = $user['local_code'];
}

$query .= " ORDER BY r.lorcapp_id IS NULL DESC, r.requested_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch ALL LORCAPP records for searching
$lorcapp_conn = getDbConnection();
$lorcapp_sql = "SELECT id, given_name, father_surname, mother_surname, birth_date 
                FROM r201_members 
                ORDER BY created_at DESC";
$lorcapp_stmt = $lorcapp_conn->prepare($lorcapp_sql);
$lorcapp_stmt->execute();
$lorcapp_result = $lorcapp_stmt->get_result();

$lorcapp_records = [];
while ($row = $lorcapp_result->fetch_assoc()) {
    // Decrypt each record
    $decrypted = decryptRecordNames($row);
    $lorcapp_records[] = $decrypted;
}

// Convert to JSON for JavaScript use
$lorcapp_records_json = json_encode($lorcapp_records);

$pageTitle = 'Link to LORCAPP';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Link Officer Requests to LORCAPP</h1>
                <p class="text-gray-600 mt-1">Connect officer requests with existing LORCAPP R-201 records</p>
            </div>
            <div class="flex items-center space-x-3">
                <a href="import-from-lorcapp.php" class="px-4 py-2 border border-green-300 text-green-700 rounded-lg hover:bg-green-50 transition-colors">
                    Import from LORCAPP
                </a>
                <a href="list.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
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

    <!-- Info Box -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-600 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div class="flex-1">
                <p class="text-sm text-blue-800 font-medium mb-1">About Linking</p>
                <p class="text-sm text-blue-700">
                    Link officer requests to existing LORCAPP R-201 records to enable R-201 certificate printing. 
                    Enter the LORCAPP Record ID to establish the connection.
                </p>
            </div>
        </div>
    </div>

    <!-- Requests List -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="p-4 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-semibold text-gray-900">Officer Requests</h2>
            <p class="text-sm text-gray-600 mt-1">
                <span class="font-medium text-gray-900"><?= count(array_filter($requests, fn($r) => empty($r['lorcapp_id']))) ?></span> unlinked / 
                <span class="font-medium text-green-600"><?= count(array_filter($requests, fn($r) => !empty($r['lorcapp_id']))) ?></span> linked
            </p>
        </div>
        
        <div class="divide-y divide-gray-200">
            <?php if (empty($requests)): ?>
            <div class="p-8 text-center text-gray-500">
                <p>No officer requests found.</p>
            </div>
            <?php else: ?>
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
                    } else {
                        $decrypted = Encryption::decryptOfficerName(
                            $request['last_name_encrypted'],
                            $request['first_name_encrypted'],
                            $request['middle_initial_encrypted'],
                            $request['district_code']
                        );
                    }
                    
                    $lastName = $decrypted['last_name'];
                    $firstName = $decrypted['first_name'];
                    $middleInitial = $decrypted['middle_initial'];
                    $fullName = trim("$firstName " . ($middleInitial ? $middleInitial . '. ' : '') . "$lastName");
                    $isLinked = !empty($request['lorcapp_id']);
                ?>
                <div class="p-4 hover:bg-gray-50 <?= $isLinked ? 'bg-green-50' : '' ?>">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <div class="flex items-center space-x-3">
                                <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($fullName) ?></h3>
                                <?php if ($isLinked): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    Linked: <?= htmlspecialchars($request['lorcapp_id']) ?>
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                    Not Linked
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-600 mt-1">
                                <?= htmlspecialchars($request['requested_duty']) ?> - <?= htmlspecialchars($request['requested_department']) ?>
                            </p>
                            <div class="flex items-center space-x-3 mt-1 text-xs text-gray-500">
                                <span><?= htmlspecialchars($request['district_name']) ?></span>
                                <?php if ($request['local_name']): ?>
                                <span>•</span>
                                <span><?= htmlspecialchars($request['local_name']) ?></span>
                                <?php endif; ?>
                                <span>•</span>
                                <span class="px-2 py-0.5 bg-blue-100 text-blue-800 rounded"><?= $request['record_code'] ?></span>
                            </div>
                        </div>
                        
                        <div class="ml-4 flex items-center space-x-2">
                            <?php if ($isLinked): ?>
                                <a href="../lorcapp/view.php?id=<?= htmlspecialchars($request['lorcapp_id']) ?>" 
                                   target="_blank"
                                   class="px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                    View R-201
                                </a>
                                <form method="POST" class="inline" onsubmit="return confirm('Unlink this LORCAPP record?')">
                                    <input type="hidden" name="action" value="unlink">
                                    <input type="hidden" name="request_id" value="<?= $request['request_id'] ?>">
                                    <button type="submit" class="px-3 py-1.5 text-sm border border-red-300 text-red-700 rounded-lg hover:bg-red-50 transition-colors">
                                        Unlink
                                    </button>
                                </form>
                            <?php else: ?>
                                <button onclick="openLinkModal(<?= $request['request_id'] ?>, '<?= htmlspecialchars($fullName, ENT_QUOTES) ?>')"
                                        class="px-3 py-1.5 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                                    Link to LORCAPP
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Link Modal -->
<div id="linkModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeLinkModal()"></div>
        
        <div class="inline-block w-full max-w-2xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Link to LORCAPP Record</h3>
            
            <p class="text-sm text-gray-600 mb-4">
                Linking officer: <span class="font-semibold text-gray-900" id="modal_officer_name"></span>
            </p>
            
            <!-- Search Box -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Search LORCAPP Records
                </label>
                <input type="text" 
                       id="search_lorcapp"
                       placeholder="Search by name..."
                       oninput="searchLorcappRecords()"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Search Results -->
            <div id="search_results" class="mb-4 max-h-64 overflow-y-auto border border-gray-200 rounded-lg">
                <div class="p-4 text-center text-gray-500 text-sm">
                    Type to search for LORCAPP records
                </div>
            </div>
            
            <!-- Selected Record Form -->
            <form method="POST" id="link_form">
                <input type="hidden" name="action" value="link">
                <input type="hidden" name="request_id" id="modal_request_id">
                <input type="hidden" name="lorcapp_id" id="selected_lorcapp_id">
                
                <div id="selected_record" class="hidden mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                    <div class="text-sm font-medium text-gray-700">Selected Record:</div>
                    <div class="text-lg font-bold text-gray-900" id="selected_record_name"></div>
                    <div class="text-sm text-gray-600" id="selected_record_id"></div>
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeLinkModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" id="link_button" disabled class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        Link Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let searchTimeout;
// Load LORCAPP records from server-side PHP
const lorcappRecords = <?= $lorcapp_records_json ?>;

function openLinkModal(requestId, officerName) {
    document.getElementById('modal_request_id').value = requestId;
    document.getElementById('modal_officer_name').textContent = officerName;
    document.getElementById('search_lorcapp').value = '';
    document.getElementById('selected_lorcapp_id').value = '';
    document.getElementById('selected_record').classList.add('hidden');
    document.getElementById('link_button').disabled = true;
    
    // Show all LORCAPP records initially
    displayAllRecords();
    
    document.getElementById('linkModal').classList.remove('hidden');
}

function displayAllRecords() {
    const resultsDiv = document.getElementById('search_results');
    
    if (lorcappRecords.length === 0) {
        resultsDiv.innerHTML = '<div class="p-4 text-center text-gray-500 text-sm">No LORCAPP records available</div>';
        return;
    }
    
    // Display all records
    let html = '<div class="divide-y divide-gray-200">';
    lorcappRecords.forEach(record => {
        const fullName = record.given_name + ' ' + record.father_surname;
        const surnames = [record.father_surname, record.mother_surname].filter(s => s).join(' / ');
        
        html += `
            <div class="p-3 hover:bg-gray-50 cursor-pointer transition-colors" onclick="selectRecord('${record.id}', '${fullName.replace(/'/g, "\\'")}', '${record.given_name.replace(/'/g, "\\'")}', '${surnames.replace(/'/g, "\\'")}')">
                <div class="font-semibold text-gray-900">${fullName}</div>
                <div class="text-sm text-gray-600">${surnames}</div>
                ${record.birth_date ? '<div class="text-xs text-gray-500">Born: ' + formatDate(record.birth_date) + '</div>' : ''}
                <div class="text-xs text-blue-600 font-mono mt-1">${record.id}</div>
            </div>
        `;
    });
    html += '</div>';
    
    resultsDiv.innerHTML = html;
}

function closeLinkModal() {
    document.getElementById('linkModal').classList.add('hidden');
}

function searchLorcappRecords() {
    const query = document.getElementById('search_lorcapp').value.trim();
    const resultsDiv = document.getElementById('search_results');
    
    // Clear previous timeout
    clearTimeout(searchTimeout);
    
    if (query.length < 2) {
        resultsDiv.innerHTML = '<div class="p-4 text-center text-gray-500 text-sm">Type at least 2 characters to search</div>';
        return;
    }
    
    // Show loading state
    resultsDiv.innerHTML = '<div class="p-4 text-center text-gray-500 text-sm">Searching...</div>';
    
    // Debounce search
    searchTimeout = setTimeout(() => {
        // Search through client-side data
        const queryLower = query.toLowerCase();
        const filteredRecords = lorcappRecords.filter(record => {
            const givenName = (record.given_name || '').toLowerCase();
            const fatherSurname = (record.father_surname || '').toLowerCase();
            const motherSurname = (record.mother_surname || '').toLowerCase();
            
            return givenName.includes(queryLower) || 
                   fatherSurname.includes(queryLower) || 
                   motherSurname.includes(queryLower);
        });
        
        if (filteredRecords.length === 0) {
            resultsDiv.innerHTML = '<div class="p-4 text-center text-gray-500 text-sm">No records found</div>';
            return;
        }
        
        // Display results
        let html = '<div class="divide-y divide-gray-200">';
        filteredRecords.forEach(record => {
            const fullName = record.given_name + ' ' + record.father_surname;
            const surnames = [record.father_surname, record.mother_surname].filter(s => s).join(' / ');
            
            html += `
                <div class="p-3 hover:bg-gray-50 cursor-pointer transition-colors" onclick="selectRecord('${record.id}', '${fullName.replace(/'/g, "\\'")}', '${record.given_name.replace(/'/g, "\\'")}', '${surnames.replace(/'/g, "\\'")}')">
                    <div class="font-semibold text-gray-900">${fullName}</div>
                    <div class="text-sm text-gray-600">${surnames}</div>
                    ${record.birth_date ? '<div class="text-xs text-gray-500">Born: ' + formatDate(record.birth_date) + '</div>' : ''}
                    <div class="text-xs text-blue-600 font-mono mt-1">${record.id}</div>
                </div>
            `;
        });
        html += '</div>';
        
        resultsDiv.innerHTML = html;
    }, 300);
}

function selectRecord(id, fullName, givenName, surnames) {
    document.getElementById('selected_lorcapp_id').value = id;
    document.getElementById('selected_record_name').textContent = fullName;
    document.getElementById('selected_record_id').textContent = 'ID: ' + id + ' • ' + surnames;
    document.getElementById('selected_record').classList.remove('hidden');
    document.getElementById('link_button').disabled = false;
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
}

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeLinkModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
