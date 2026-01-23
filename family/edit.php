<?php
/**
 * Family Registry - Edit Family
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
               registry_number_encrypted, cfo_classification, district_code, purok, grupo
        FROM tarheta_control WHERE id = ?
    ");
    $stmtPangulo->execute([$family['pangulo_id']]);
    $pangulo = $stmtPangulo->fetch();
    
    $panguloData = null;
    if ($pangulo) {
        $firstName = Encryption::decrypt($pangulo['first_name_encrypted'], $pangulo['district_code']);
        $lastName = Encryption::decrypt($pangulo['last_name_encrypted'], $pangulo['district_code']);
        $middleName = $pangulo['middle_name_encrypted'] ? Encryption::decrypt($pangulo['middle_name_encrypted'], $pangulo['district_code']) : '';
        $registryNumber = Encryption::decrypt($pangulo['registry_number_encrypted'], $pangulo['district_code']);
        
        $panguloData = [
            'id' => $pangulo['id'],
            'full_name' => trim("$firstName $middleName $lastName"),
            'registry_number' => $registryNumber,
            'cfo_classification' => $pangulo['cfo_classification'],
            'purok' => $pangulo['purok'],
            'grupo' => $pangulo['grupo'],
            'purok_grupo' => ($pangulo['purok'] ?: '') . ($pangulo['grupo'] ? '-' . $pangulo['grupo'] : '')
        ];
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
        ORDER BY fm.created_at ASC
    ");
    $stmtMembers->execute([$familyId]);
    $members = $stmtMembers->fetchAll();
    
    // Prepare members for JavaScript
    $membersForJs = [];
    foreach ($members as $member) {
        if ($member['member_type'] === 'pangulo') continue; // Skip pangulo
        
        $name = 'Unknown';
        if ($member['tarheta_id'] && $member['t_first']) {
            $first = Encryption::decrypt($member['t_first'], $member['t_district']);
            $last = Encryption::decrypt($member['t_last'], $member['t_district']);
            $middle = $member['t_middle'] ? Encryption::decrypt($member['t_middle'], $member['t_district']) : '';
            $name = trim("$first $middle $last");
        } elseif ($member['first_name_encrypted']) {
            $first = Encryption::decrypt($member['first_name_encrypted'], $family['district_code']);
            $last = Encryption::decrypt($member['last_name_encrypted'], $family['district_code']);
            $name = trim("$first $last");
        }
        
        $membersForJs[] = [
            'id' => $member['id'],
            'source' => $member['kapisanan'] ?: 'Tarheta',
            'source_id' => $member['tarheta_id'] ?? $member['hdb_id'] ?? $member['pnk_id'],
            'name' => $name,
            'relasyon' => $member['relasyon'],
            'relasyon_specify' => $member['relasyon_specify'],
            'kapisanan' => $member['kapisanan']
        ];
    }

} catch (Exception $e) {
    error_log("Family edit error: " . $e->getMessage());
    header('Location: index.php?error=load_failed');
    exit;
}

$pageTitle = 'Edit Family - ' . $family['family_code'];

ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6" x-data="editFamilyForm()" x-init="init()">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center gap-3 mb-4">
            <a href="profile.php?id=<?php echo $familyId; ?>" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                ← Back to Family Profile
            </a>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
            <i class="fa-solid fa-edit text-indigo-600 mr-2"></i>
            Edit Family: <?php echo htmlspecialchars($family['family_code']); ?>
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Update household information and members</p>
    </div>

    <!-- Family Info -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
            <i class="fa-solid fa-house text-indigo-600 mr-2"></i>
            Family Information
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Family Code</label>
                <input type="text" x-model="familyCode" 
                       class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                <select x-model="status" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="transferred">Transferred</option>
                </select>
            </div>
        </div>
        
        <!-- Pangulo Info (Read-only) -->
        <div class="bg-indigo-50 dark:bg-indigo-900/30 rounded-lg p-4 mb-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-indigo-600 rounded-full flex items-center justify-center text-white font-bold">
                    <i class="fa-solid fa-crown"></i>
                </div>
                <div>
                    <div class="text-sm text-indigo-600 dark:text-indigo-400">Pangulo ng Sambahayan</div>
                    <div class="font-semibold text-gray-900 dark:text-gray-100" x-text="pangulo?.full_name"></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Registry: <span x-text="pangulo?.registry_number"></span></div>
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Purok</label>
                <input type="text" x-model="purok" 
                       class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500"
                       placeholder="e.g., 1, 2, 3">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Grupo</label>
                <input type="text" x-model="grupo" 
                       class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500"
                       placeholder="e.g., A, B, C">
            </div>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes</label>
            <textarea x-model="notes" rows="2" 
                      class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500"
                      placeholder="Any additional notes..."></textarea>
        </div>
    </div>

    <!-- Family Members -->
    <div id="members" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
            <i class="fa-solid fa-users text-indigo-600 mr-2"></i>
            Family Members (<span x-text="members.length"></span>)
        </h2>

        <!-- Members List -->
        <div class="space-y-2 mb-6">
            <template x-for="(member, index) in members" :key="index">
                <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-medium"
                             :class="{
                                 'bg-pink-500': member.kapisanan === 'Buklod',
                                 'bg-blue-500': member.kapisanan === 'Kadiwa',
                                 'bg-green-500': member.kapisanan === 'Binhi',
                                 'bg-purple-500': member.kapisanan === 'PNK',
                                 'bg-yellow-500': member.kapisanan === 'HDB',
                                 'bg-gray-400': !member.kapisanan || member.kapisanan === 'None'
                             }">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div>
                            <div class="font-medium text-gray-900 dark:text-gray-100" x-text="member.name"></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                <span x-text="member.relasyon"></span>
                                <span x-show="member.relasyon_specify" x-text="' (' + member.relasyon_specify + ')'"></span>
                                • <span x-text="member.kapisanan || 'No Classification'"></span>
                            </div>
                        </div>
                    </div>
                    <button @click="removeMember(index)" class="text-red-600 hover:text-red-700 p-2" title="Remove member">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </template>
            
            <div x-show="members.length === 0" class="text-center py-4 text-gray-500 dark:text-gray-400">
                No additional family members added.
            </div>
        </div>

        <!-- Add Member -->
        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
                <i class="fa-solid fa-user-plus text-green-600 mr-2"></i>
                Add New Member
            </h3>

            <div class="relative mb-4">
                <input type="text" 
                       x-model="memberSearch" 
                       @input.debounce.300ms="searchMember()"
                       @focus="showMemberResults = true"
                       placeholder="Search by name from HDB, PNK, or Tarheta..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                <i class="fa-solid fa-search absolute left-3 top-2.5 text-gray-400"></i>
            </div>

            <div x-show="showMemberResults && memberResults.length > 0" 
                 @click.away="showMemberResults = false"
                 class="border border-gray-200 dark:border-gray-600 rounded-lg max-h-48 overflow-y-auto mb-4 bg-white dark:bg-gray-800">
                <template x-for="person in memberResults" :key="person.id + '-' + person.source">
                    <div @click="selectMember(person)" 
                         class="px-4 py-2 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-gray-100" x-text="person.full_name"></div>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  :class="{
                                      'bg-pink-100 text-pink-700': person.source === 'Buklod',
                                      'bg-blue-100 text-blue-700': person.source === 'Kadiwa',
                                      'bg-green-100 text-green-700': person.source === 'Binhi',
                                      'bg-purple-100 text-purple-700': person.source === 'PNK',
                                      'bg-yellow-100 text-yellow-700': person.source === 'HDB'
                                  }"
                                  x-text="person.source"></span>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="newMember.name" class="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                <div class="flex items-center justify-between mb-3">
                    <div class="font-medium text-gray-900 dark:text-gray-100" x-text="newMember.name"></div>
                    <button @click="clearNewMember()" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Relasyon sa Pangulo</label>
                        <select x-model="newMember.relasyon"
                                class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <option value="">-- Select --</option>
                            <option value="Asawa">Asawa</option>
                            <option value="Anak">Anak</option>
                            <option value="Apo">Apo</option>
                            <option value="Magulang">Magulang</option>
                            <option value="Kapatid">Kapatid</option>
                            <option value="Pamangkin">Pamangkin</option>
                            <option value="Indibidwal">Indibidwal</option>
                            <option value="Iba pa">Iba pa (Specify)</option>
                        </select>
                    </div>
                    <div x-show="newMember.relasyon === 'Iba pa'">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Specify</label>
                        <input type="text" x-model="newMember.relasyon_specify" 
                               class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                </div>

                <button @click="addMember()" :disabled="!newMember.relasyon"
                        class="mt-3 w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors text-sm font-medium">
                    <i class="fa-solid fa-plus mr-2"></i> Add Member
                </button>
            </div>
        </div>
    </div>

    <!-- Error/Success Messages -->
    <div x-show="errorMessage" class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-xl p-4">
        <p class="text-red-700 dark:text-red-300" x-text="errorMessage"></p>
    </div>
    
    <div x-show="successMessage" class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-xl p-4">
        <p class="text-green-700 dark:text-green-300" x-text="successMessage"></p>
    </div>

    <!-- Actions -->
    <div class="flex justify-between">
        <a href="profile.php?id=<?php echo $familyId; ?>" 
           class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-medium">
            Cancel
        </a>
        <button @click="saveFamily()" :disabled="saving"
                class="px-8 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:bg-gray-400 transition-colors font-medium">
            <i x-show="!saving" class="fa-solid fa-save mr-2"></i>
            <i x-show="saving" class="fa-solid fa-spinner fa-spin mr-2"></i>
            <span x-text="saving ? 'Saving...' : 'Save Changes'"></span>
        </button>
    </div>
</div>

<script>
function editFamilyForm() {
    return {
        familyId: <?php echo $familyId; ?>,
        familyCode: <?php echo json_encode($family['family_code']); ?>,
        status: <?php echo json_encode($family['status']); ?>,
        purok: <?php echo json_encode($family['purok'] ?? ''); ?>,
        grupo: <?php echo json_encode($family['grupo'] ?? ''); ?>,
        notes: <?php echo json_encode($family['notes'] ?? ''); ?>,
        pangulo: <?php echo json_encode($panguloData); ?>,
        members: <?php echo json_encode($membersForJs); ?>,
        
        memberSearch: '',
        memberResults: [],
        showMemberResults: false,
        newMember: { id: null, source: null, source_id: null, name: '', relasyon: '', relasyon_specify: '', kapisanan: '' },
        
        saving: false,
        errorMessage: '',
        successMessage: '',

        init() {},

        async searchMember() {
            if (this.memberSearch.length < 2) {
                this.memberResults = [];
                return;
            }
            
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/search-members.php?q=' + encodeURIComponent(this.memberSearch) + '&source=all');
                const data = await response.json();
                if (data.success) {
                    // Filter out pangulo and already added members
                    this.memberResults = data.results.filter(r => {
                        if (r.source === 'Tarheta' && r.id === this.pangulo?.id) return false;
                        return !this.members.some(m => m.source === r.source && m.source_id === r.id);
                    });
                    this.showMemberResults = true;
                }
            } catch (e) {
                console.error('Error:', e);
            }
        },

        selectMember(person) {
            this.newMember = {
                id: null,
                source: person.source,
                source_id: person.id,
                name: person.full_name,
                relasyon: '',
                relasyon_specify: '',
                kapisanan: person.kapisanan || person.source
            };
            this.showMemberResults = false;
            this.memberSearch = '';
        },

        clearNewMember() {
            this.newMember = { id: null, source: null, source_id: null, name: '', relasyon: '', relasyon_specify: '', kapisanan: '' };
        },

        addMember() {
            if (!this.newMember.name || !this.newMember.relasyon) return;
            this.members.push({...this.newMember});
            this.clearNewMember();
        },

        removeMember(index) {
            this.members.splice(index, 1);
        },

        async saveFamily() {
            this.saving = true;
            this.errorMessage = '';
            this.successMessage = '';
            
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/update-family.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: this.familyId,
                        family_code: this.familyCode,
                        status: this.status,
                        purok: this.purok,
                        grupo: this.grupo,
                        notes: this.notes,
                        members: this.members
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.successMessage = 'Family updated successfully!';
                    setTimeout(() => {
                        window.location.href = 'profile.php?id=' + this.familyId;
                    }, 1000);
                } else {
                    this.errorMessage = data.error || 'Failed to update family';
                }
            } catch (e) {
                console.error('Error:', e);
                this.errorMessage = 'An error occurred while saving';
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
