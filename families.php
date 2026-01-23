<?php
/**
 * Families List Page
 * INC Family App - Registry of Families (Sambahayan)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$pageTitle = 'Family Registry';
$currentUser = getCurrentUser();

ob_start();
?>

<div class="max-w-7xl mx-auto" x-data="familyRegistry()">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="text-gray-500 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition-colors">
                    ← Launchpad
                </a>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mt-2 flex items-center gap-3">
                <span class="text-3xl">👨‍👩‍👧‍👦</span>
                Family Registry
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage family units (Sambahayan)</p>
        </div>
        
        <div class="flex gap-2">
            <a href="family-add.php" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add Family
            </a>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                    <span class="text-xl">👨‍👩‍👧‍👦</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.total">0</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Families</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                    <span class="text-xl">✅</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.active">0</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Active</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                    <span class="text-xl">👥</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.totalMembers">0</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Members</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                    <span class="text-xl">📊</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.avgMembers.toFixed(1)">0</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Avg Members/Family</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters and Search -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex flex-col md:flex-row gap-4">
                <!-- Search -->
                <div class="flex-1">
                    <div class="relative">
                        <input type="text" 
                               x-model="searchQuery"
                               @input.debounce.150ms="fetchFamilies()"
                               @keydown.enter="fetchFamilies()"
                               placeholder="Search by family name..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <svg class="w-5 h-5 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                </div>
                
                <!-- Purok Filter -->
                <div class="w-full md:w-40">
                    <select x-model="filterPurok" @change="fetchFamilies()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        <option value="">All Purok</option>
                        <template x-for="purok in purokList" :key="purok">
                            <option :value="purok" x-text="'Purok ' + purok"></option>
                        </template>
                    </select>
                </div>
                
                <!-- Status Filter -->
                <div class="w-full md:w-40">
                    <select x-model="filterStatus" @change="fetchFamilies()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="transferred">Transferred</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Family Name</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Purok-Grupo</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Members</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Location</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-if="loading">
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <svg class="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span class="text-gray-500 dark:text-gray-400">Loading...</span>
                                </div>
                            </td>
                        </tr>
                    </template>
                    
                    <template x-if="!loading && families.length === 0">
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                <div class="flex flex-col items-center gap-2">
                                    <span class="text-4xl">👨‍👩‍👧‍👦</span>
                                    <p>No families found</p>
                                    <a href="family-add.php" class="text-blue-600 hover:underline">Add your first family →</a>
                                </div>
                            </td>
                        </tr>
                    </template>
                    
                    <template x-for="family in families" :key="family.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors cursor-pointer" @click="viewFamily(family.id)">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                        <span class="text-blue-600 dark:text-blue-400 font-semibold" x-text="family.family_name.charAt(0).toUpperCase()"></span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white" x-text="family.family_name"></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="'ID: ' + family.id"></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200" x-text="family.purok_grupo"></span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-400 font-semibold" x-text="family.member_count"></span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-sm text-gray-900 dark:text-white" x-text="family.local_name"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="family.district_name"></p>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                      :class="{
                                          'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200': family.status === 'active',
                                          'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200': family.status === 'inactive',
                                          'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200': family.status === 'transferred'
                                      }"
                                      x-text="family.status.charAt(0).toUpperCase() + family.status.slice(1)">
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button @click.stop="viewFamily(family.id)" class="p-2 text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-900/50 rounded-lg transition-colors" title="View Family">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Showing <span x-text="families.length"></span> of <span x-text="pagination.total"></span> families
            </div>
            <div class="flex gap-2">
                <button @click="prevPage()" :disabled="pagination.page <= 1" class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-lg text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50 dark:hover:bg-gray-700">
                    Previous
                </button>
                <span class="px-3 py-1 text-sm text-gray-600 dark:text-gray-400">
                    Page <span x-text="pagination.page"></span> of <span x-text="pagination.pages"></span>
                </span>
                <button @click="nextPage()" :disabled="pagination.page >= pagination.pages" class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-lg text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50 dark:hover:bg-gray-700">
                    Next
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function familyRegistry() {
    return {
        families: [],
        loading: true,
        searchQuery: '',
        filterPurok: '',
        filterStatus: '',
        purokList: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'],
        stats: {
            total: 0,
            active: 0,
            totalMembers: 0,
            avgMembers: 0
        },
        pagination: {
            page: 1,
            limit: 25,
            total: 0,
            pages: 1
        },
        
        init() {
            this.fetchFamilies();
        },
        
        async fetchFamilies() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    page: this.pagination.page,
                    limit: this.pagination.limit,
                    search: this.searchQuery,
                    purok: this.filterPurok,
                    status: this.filterStatus
                });
                
                const response = await fetch(`api/get-families.php?${params}`);
                const result = await response.json();
                
                if (result.success) {
                    this.families = result.data;
                    this.pagination = result.pagination;
                    this.calculateStats();
                }
            } catch (error) {
                console.error('Error fetching families:', error);
            } finally {
                this.loading = false;
            }
        },
        
        calculateStats() {
            this.stats.total = this.pagination.total;
            this.stats.active = this.families.filter(f => f.status === 'active').length;
            this.stats.totalMembers = this.families.reduce((sum, f) => sum + parseInt(f.member_count), 0);
            this.stats.avgMembers = this.stats.total > 0 ? this.stats.totalMembers / this.families.length : 0;
        },
        
        viewFamily(id) {
            window.location.href = `family-profile.php?id=${id}`;
        },
        
        prevPage() {
            if (this.pagination.page > 1) {
                this.pagination.page--;
                this.fetchFamilies();
            }
        },
        
        nextPage() {
            if (this.pagination.page < this.pagination.pages) {
                this.pagination.page++;
                this.fetchFamilies();
            }
        }
    };
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
