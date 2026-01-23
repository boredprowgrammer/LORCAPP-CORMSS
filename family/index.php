<?php
/**
 * Family Registry - List all families (Sambahayan)
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

$pageTitle = 'Family Registry';
$noLoadingOverlay = true;

ob_start();
?>

<div class="space-y-6">
    <!-- Delete Success Banner -->
    <?php if (isset($_GET['deleted'])): ?>
    <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-xl p-4 flex items-center gap-3">
        <i class="fa-solid fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
        <div>
            <p class="font-medium text-green-800 dark:text-green-200">Family Deleted Successfully!</p>
            <p class="text-sm text-green-600 dark:text-green-400">The family has been removed from the registry.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <a href="<?php echo BASE_URL; ?>/launchpad.php" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        ← Launchpad
                    </a>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">
                    <i class="fa-solid fa-people-roof text-indigo-600 mr-2"></i>
                    Family Registry
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage household registrations and family members</p>
            </div>
            <div class="flex gap-2">
                <a href="print-r203.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium" title="Print R2-03 Form">
                    <i class="fa-solid fa-print mr-2"></i>
                    Print R2-03
                </a>
                <a href="add.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                    <i class="fa-solid fa-plus mr-2"></i>
                    Add Family
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4" x-data="familyStats()" x-init="loadStats()">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-house-user text-indigo-600 dark:text-indigo-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Families</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="stats.total">-</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-users text-green-600 dark:text-green-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Members</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="stats.members">-</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-pink-100 dark:bg-pink-900/30 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-heart text-pink-600 dark:text-pink-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Buklod Members</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="stats.buklod">-</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-child text-blue-600 dark:text-blue-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Children (Binhi/HDB)</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="stats.children">-</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Family List -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden"
         x-data="familyTable()" x-init="init()">
        
        <!-- Table Header -->
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Families List</h2>
                <div class="flex flex-col sm:flex-row gap-3">
                    <!-- Search -->
                    <div class="relative">
                        <input type="text" 
                               x-model="searchQuery" 
                               @input.debounce.200ms="search()"
                               @keydown.enter="search()"
                               placeholder="Search families..."
                               class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                        <i class="fa-solid fa-search absolute left-3 top-2.5 text-gray-400"></i>
                    </div>
                    <!-- Filter by Purok -->
                    <select x-model="filterPurok" @change="applyFilters()" 
                            class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">All Purok</option>
                        <template x-for="p in availablePuroks" :key="p">
                            <option :value="p" x-text="'Purok ' + p"></option>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        <!-- Loading -->
        <div x-show="loading" class="flex items-center justify-center py-12">
            <i class="fa-solid fa-spinner fa-spin text-3xl text-indigo-600 mr-3"></i>
            <span class="text-gray-600 dark:text-gray-400">Loading families...</span>
        </div>

        <!-- Table -->
        <div x-show="!loading" class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Family Code</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Pangulo ng Sambahayan</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Purok-Grupo</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Members</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Buklod</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Kadiwa</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Binhi</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-for="family in families" :key="family.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-4 py-3">
                                <span class="font-mono text-sm font-medium text-indigo-600 dark:text-indigo-400" x-text="family.family_code"></span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-gray-100" x-text="family.pangulo_name"></div>
                                <div class="text-xs text-gray-500" x-text="family.local_name"></div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-sm text-gray-700 dark:text-gray-300" x-text="family.purok_grupo || '-'"></span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-800 dark:text-gray-200" x-text="family.member_count"></span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span x-show="family.buklod_count > 0" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-pink-100 dark:bg-pink-900/30 text-pink-800 dark:text-pink-300" x-text="family.buklod_count"></span>
                                <span x-show="!family.buklod_count" class="text-gray-400">-</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span x-show="family.kadiwa_count > 0" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300" x-text="family.kadiwa_count"></span>
                                <span x-show="!family.kadiwa_count" class="text-gray-400">-</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span x-show="family.binhi_count > 0" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300" x-text="family.binhi_count"></span>
                                <span x-show="!family.binhi_count" class="text-gray-400">-</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a :href="'profile.php?id=' + family.id" class="p-2 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded-lg transition-colors" title="View Profile">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <a :href="'edit.php?id=' + family.id" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition-colors" title="Edit">
                                        <i class="fa-solid fa-edit"></i>
                                    </a>
                                    <a :href="'print-r203.php?family_id=' + family.id" target="_blank" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition-colors" title="Print R2-03">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </template>
                    
                    <!-- Empty State -->
                    <tr x-show="families.length === 0 && !loading">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <i class="fa-solid fa-house-circle-xmark text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-gray-500 dark:text-gray-400">No families found</p>
                            <a href="add.php" class="mt-4 inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors text-sm">
                                <i class="fa-solid fa-plus mr-2"></i> Add First Family
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div x-show="!loading && totalRecords > 0" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Showing <span class="font-medium" x-text="((currentPage - 1) * pageSize) + 1"></span> to 
                    <span class="font-medium" x-text="Math.min(currentPage * pageSize, totalRecords)"></span> of 
                    <span class="font-medium" x-text="totalRecords"></span> families
                </div>
                <div class="flex items-center gap-2">
                    <button @click="goToPage(currentPage - 1)" :disabled="currentPage === 1" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span>
                    </span>
                    <button @click="goToPage(currentPage + 1)" :disabled="currentPage >= totalPages" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function familyStats() {
    return {
        stats: { total: 0, members: 0, buklod: 0, children: 0 },
        async loadStats() {
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/get-stats.php');
                const data = await response.json();
                if (data.success) {
                    this.stats = data.stats;
                }
            } catch (e) {
                console.error('Error loading stats:', e);
            }
        }
    };
}

function familyTable() {
    return {
        families: [],
        loading: true,
        searchQuery: '',
        filterPurok: '',
        availablePuroks: [],
        currentPage: 1,
        pageSize: 25,
        totalRecords: 0,
        totalPages: 0,

        init() {
            this.fetchData();
            this.loadPuroks();
        },

        async fetchData() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    page: this.currentPage,
                    limit: this.pageSize,
                    search: this.searchQuery,
                    purok: this.filterPurok
                });
                
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/get-families.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    this.families = data.families;
                    this.totalRecords = data.total;
                    this.totalPages = Math.ceil(data.total / this.pageSize);
                }
            } catch (e) {
                console.error('Error:', e);
            } finally {
                this.loading = false;
            }
        },

        async loadPuroks() {
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/get-puroks.php');
                const data = await response.json();
                if (data.success) {
                    this.availablePuroks = data.puroks;
                }
            } catch (e) {
                console.error('Error loading puroks:', e);
            }
        },

        search() {
            this.currentPage = 1;
            this.fetchData();
        },

        applyFilters() {
            this.currentPage = 1;
            this.fetchData();
        },

        goToPage(page) {
            if (page < 1 || page > this.totalPages) return;
            this.currentPage = page;
            this.fetchData();
        }
    };
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
