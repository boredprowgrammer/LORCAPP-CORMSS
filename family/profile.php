<?php
/**
 * Family Profile - View family details
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check access
if (!in_array($currentUser['role'], ['admin', 'district', 'local', 'local_cfo'])) {
    header('Location: ' . BASE_URL . '/launchpad.php');
    exit;
}

$familyId = intval($_GET['id'] ?? 0);
$justCreated = isset($_GET['created']);

if ($familyId <= 0) {
    header('Location: index.php');
    exit;
}

// Get family data
try {
    $stmt = $db->prepare("
        SELECT f.*, d.district_name, lc.local_name
        FROM families f
        LEFT JOIN districts d ON f.district_code = d.district_code
        LEFT JOIN local_congregations lc ON f.local_code = lc.local_code
        WHERE f.id = ? AND f.deleted_at IS NULL
    ");
    $stmt->execute([$familyId]);
    $family = $stmt->fetch();
    
    if (!$family) {
        header('Location: index.php?error=not_found');
        exit;
    }
    
    // Get pangulo details
    $stmtPangulo = $db->prepare("
        SELECT id, last_name_encrypted, first_name_encrypted, middle_name_encrypted,
               registry_number_encrypted, cfo_classification, district_code
        FROM tarheta_control WHERE id = ?
    ");
    $stmtPangulo->execute([$family['pangulo_id']]);
    $pangulo = $stmtPangulo->fetch();
    
    if ($pangulo) {
        $panguloName = Encryption::decrypt($pangulo['first_name_encrypted'], $pangulo['district_code']) . ' ' .
                       Encryption::decrypt($pangulo['last_name_encrypted'], $pangulo['district_code']);
        $panguloRegistry = Encryption::decrypt($pangulo['registry_number_encrypted'], $pangulo['district_code']);
    } else {
        $panguloName = 'Unknown';
        $panguloRegistry = '-';
    }
    
    // Get family members
    $stmtMembers = $db->prepare("
        SELECT fm.*, 
               tc.last_name_encrypted as t_last, tc.first_name_encrypted as t_first, 
               tc.middle_name_encrypted as t_middle, tc.registry_number_encrypted as t_registry,
               tc.cfo_classification as t_cfo, tc.district_code as t_district
        FROM family_members fm
        LEFT JOIN tarheta_control tc ON fm.tarheta_id = tc.id
        WHERE fm.family_id = ? AND fm.is_active = 1
        ORDER BY 
            CASE fm.relasyon 
                WHEN 'Pangulo' THEN 1 
                WHEN 'Asawa' THEN 2 
                WHEN 'Anak' THEN 3 
                WHEN 'Apo' THEN 4
                ELSE 5 
            END,
            fm.created_at ASC
    ");
    $stmtMembers->execute([$familyId]);
    $members = $stmtMembers->fetchAll();
    
    // Decrypt member names
    $decryptedMembers = [];
    foreach ($members as $member) {
        $name = 'Unknown';
        $registry = '';
        
        if ($member['tarheta_id'] && $member['t_first']) {
            $first = Encryption::decrypt($member['t_first'], $member['t_district']);
            $last = Encryption::decrypt($member['t_last'], $member['t_district']);
            $middle = $member['t_middle'] ? Encryption::decrypt($member['t_middle'], $member['t_district']) : '';
            $name = trim("$first $middle $last");
            $registry = $member['t_registry'] ? Encryption::decrypt($member['t_registry'], $member['t_district']) : '';
        } elseif ($member['first_name_encrypted']) {
            $first = Encryption::decrypt($member['first_name_encrypted'], $family['district_code']);
            $last = Encryption::decrypt($member['last_name_encrypted'], $family['district_code']);
            $name = trim("$first $last");
        }
        
        $decryptedMembers[] = [
            'id' => $member['id'],
            'name' => $name,
            'registry' => $registry,
            'relasyon' => $member['relasyon'],
            'relasyon_specify' => $member['relasyon_specify'],
            'kapisanan' => $member['kapisanan'],
            'member_type' => $member['member_type']
        ];
    }

} catch (Exception $e) {
    error_log("Family profile error: " . $e->getMessage());
    header('Location: index.php?error=load_failed');
    exit;
}

$pageTitle = 'Family Profile - ' . $family['family_code'];

ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Success Banner -->
    <?php if ($justCreated): ?>
    <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-xl p-4 flex items-center gap-3">
        <i class="fa-solid fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
        <div>
            <p class="font-medium text-green-800 dark:text-green-200">Family Created Successfully!</p>
            <p class="text-sm text-green-600 dark:text-green-400">The family has been registered in the system.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center gap-3 mb-4">
            <a href="index.php" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                ← Back to Family List
            </a>
        </div>
        
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-people-roof text-white text-2xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?php echo htmlspecialchars($family['family_code']); ?></h1>
                    <p class="text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($family['local_name'] ?? $family['district_name']); ?></p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="edit.php?id=<?php echo $familyId; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    <i class="fa-solid fa-edit mr-2"></i> Edit Family
                </a>
                <a href="print-r203.php?family_id=<?php echo $familyId; ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium" title="Print R2-03 Form">
                    <i class="fa-solid fa-file-alt mr-2"></i> R2-03
                </a>
                <button onclick="printProfile()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    <i class="fa-solid fa-print mr-2"></i> Print
                </button>
                <button onclick="showDeleteModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium">
                    <i class="fa-solid fa-trash mr-2"></i> Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Family Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 text-center">
            <div class="text-3xl font-bold text-gray-900 dark:text-gray-100"><?php echo count($decryptedMembers); ?></div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Total Members</div>
        </div>
        <div class="bg-pink-50 dark:bg-pink-900/30 rounded-xl border border-pink-200 dark:border-pink-800 p-4 text-center">
            <div class="text-3xl font-bold text-pink-600 dark:text-pink-400"><?php echo count(array_filter($decryptedMembers, fn($m) => $m['kapisanan'] === 'Buklod')); ?></div>
            <div class="text-sm text-pink-600 dark:text-pink-400">Buklod</div>
        </div>
        <div class="bg-blue-50 dark:bg-blue-900/30 rounded-xl border border-blue-200 dark:border-blue-800 p-4 text-center">
            <div class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo count(array_filter($decryptedMembers, fn($m) => $m['kapisanan'] === 'Kadiwa')); ?></div>
            <div class="text-sm text-blue-600 dark:text-blue-400">Kadiwa</div>
        </div>
        <div class="bg-green-50 dark:bg-green-900/30 rounded-xl border border-green-200 dark:border-green-800 p-4 text-center">
            <div class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo count(array_filter($decryptedMembers, fn($m) => $m['kapisanan'] === 'Binhi')); ?></div>
            <div class="text-sm text-green-600 dark:text-green-400">Binhi</div>
        </div>
        <div class="bg-purple-50 dark:bg-purple-900/30 rounded-xl border border-purple-200 dark:border-purple-800 p-4 text-center">
            <div class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo count(array_filter($decryptedMembers, fn($m) => in_array($m['kapisanan'], ['PNK', 'HDB']))); ?></div>
            <div class="text-sm text-purple-600 dark:text-purple-400">PNK/HDB</div>
        </div>
    </div>

    <!-- Pangulo Information -->
    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center gap-2 mb-4">
            <i class="fa-solid fa-crown"></i>
            <h2 class="font-semibold">Pangulo ng Sambahayan</h2>
        </div>
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-white/20 rounded-full flex items-center justify-center">
                <i class="fa-solid fa-user text-2xl"></i>
            </div>
            <div>
                <div class="text-xl font-bold"><?php echo htmlspecialchars($panguloName); ?></div>
                <div class="text-indigo-100">Registry: <?php echo htmlspecialchars($panguloRegistry); ?></div>
                <div class="text-indigo-100">Classification: <?php echo htmlspecialchars($pangulo['cfo_classification'] ?? 'N/A'); ?></div>
            </div>
        </div>
        <div class="mt-4 pt-4 border-t border-white/20 grid grid-cols-2 gap-4 text-sm">
            <div>
                <span class="text-indigo-200">Purok-Grupo:</span>
                <span class="font-medium"><?php echo htmlspecialchars(($family['purok'] ?? '-') . '-' . ($family['grupo'] ?? '-')); ?></span>
            </div>
            <div>
                <span class="text-indigo-200">Status:</span>
                <span class="font-medium"><?php echo ucfirst($family['status']); ?></span>
            </div>
        </div>
    </div>

    <!-- Family Members List -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                <i class="fa-solid fa-users text-indigo-600 mr-2"></i>
                Family Members
            </h2>
            <a href="edit.php?id=<?php echo $familyId; ?>#members" class="text-sm text-indigo-600 hover:text-indigo-700">
                <i class="fa-solid fa-plus mr-1"></i> Add Member
            </a>
        </div>
        
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            <?php foreach ($decryptedMembers as $member): ?>
            <div class="p-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center text-white font-bold
                        <?php 
                        echo match($member['kapisanan']) {
                            'Buklod' => 'bg-pink-500',
                            'Kadiwa' => 'bg-blue-500',
                            'Binhi' => 'bg-green-500',
                            'PNK' => 'bg-purple-500',
                            'HDB' => 'bg-yellow-500',
                            default => 'bg-gray-400'
                        };
                        ?>">
                        <?php if ($member['member_type'] === 'pangulo'): ?>
                        <i class="fa-solid fa-crown"></i>
                        <?php else: ?>
                        <i class="fa-solid fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="font-medium text-gray-900 dark:text-gray-100">
                            <?php echo htmlspecialchars($member['name']); ?>
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            <?php 
                            echo htmlspecialchars($member['relasyon']);
                            if ($member['relasyon_specify']) echo ' (' . htmlspecialchars($member['relasyon_specify']) . ')';
                            ?>
                            <?php if ($member['registry']): ?>
                            <span class="text-gray-400 mx-1">•</span>
                            <span class="font-mono text-xs"><?php echo htmlspecialchars($member['registry']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-3 py-1 rounded-full text-xs font-medium
                        <?php 
                        echo match($member['kapisanan']) {
                            'Buklod' => 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300',
                            'Kadiwa' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                            'Binhi' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                            'PNK' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
                            'HDB' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
                        };
                        ?>">
                        <?php echo htmlspecialchars($member['kapisanan'] ?: 'N/A'); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($decryptedMembers)): ?>
            <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                <i class="fa-solid fa-users text-3xl text-gray-300 dark:text-gray-600 mb-2"></i>
                <p>No family members added yet.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Family Notes -->
    <?php if (!empty($family['notes'])): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
            <i class="fa-solid fa-sticky-note text-yellow-500 mr-2"></i>
            Notes
        </h3>
        <p class="text-gray-600 dark:text-gray-400"><?php echo nl2br(htmlspecialchars($family['notes'])); ?></p>
    </div>
    <?php endif; ?>

    <!-- Family Info -->
    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 text-xs text-gray-500 dark:text-gray-400">
        <div class="flex flex-wrap gap-4">
            <span>Created: <?php echo date('M d, Y h:i A', strtotime($family['created_at'])); ?></span>
            <span>•</span>
            <span>Last Updated: <?php echo date('M d, Y h:i A', strtotime($family['updated_at'])); ?></span>
            <span>•</span>
            <span>District: <?php echo htmlspecialchars($family['district_name']); ?></span>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50" onclick="hideDeleteModal()">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="text-center mb-4">
            <div class="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-exclamation-triangle text-red-600 dark:text-red-400 text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Delete Family?</h3>
            <p class="text-gray-600 dark:text-gray-400">
                Are you sure you want to delete <strong><?php echo htmlspecialchars($family['family_code']); ?></strong>?
            </p>
            <p class="text-sm text-red-600 dark:text-red-400 mt-2">
                This will remove the family and all member associations. Members will NOT be deleted from their original registries.
            </p>
        </div>
        <div class="flex gap-3 justify-center">
            <button onclick="hideDeleteModal()" class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                Cancel
            </button>
            <button onclick="deleteFamily()" id="deleteBtn" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium">
                <i class="fa-solid fa-trash mr-2"></i> Delete Family
            </button>
        </div>
    </div>
</div>

<script>
function printProfile() {
    window.print();
}

function showDeleteModal() {
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}

function hideDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
}

async function deleteFamily() {
    const btn = document.getElementById('deleteBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Deleting...';
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/family/delete-family.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ family_id: <?php echo $familyId; ?> })
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.location.href = 'index.php?deleted=1';
        } else {
            alert(data.error || 'Failed to delete family');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash mr-2"></i> Delete Family';
        }
    } catch (e) {
        console.error('Error:', e);
        alert('An error occurred while deleting');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash mr-2"></i> Delete Family';
    }
}
</script>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white; }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
