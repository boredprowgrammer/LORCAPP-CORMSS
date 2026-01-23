<?php
/**
 * Family Profile Page
 * INC Family App - View and edit family details
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$familyId = intval($_GET['id'] ?? 0);

if ($familyId <= 0) {
    header('Location: families.php?error=' . urlencode('Invalid family ID'));
    exit;
}

$pageTitle = 'Family Profile';
$currentUser = getCurrentUser();

ob_start();
?>

<div class="max-w-5xl mx-auto" x-data="familyProfile(<?= $familyId ?>)">
    <!-- Loading State -->
    <template x-if="loading">
        <div class="flex items-center justify-center py-20">
            <div class="text-center">
                <svg class="animate-spin h-10 w-10 text-blue-600 mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400">Loading family profile...</p>
            </div>
        </div>
    </template>
    
    <!-- Error State -->
    <template x-if="error">
        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-xl p-6 text-center">
            <span class="text-4xl mb-4 block">❌</span>
            <h2 class="text-xl font-bold text-red-800 dark:text-red-200 mb-2">Error Loading Family</h2>
            <p class="text-red-600 dark:text-red-400 mb-4" x-text="error"></p>
            <a href="families.php" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                ← Back to Families
            </a>
        </div>
    </template>
    
    <!-- Family Profile Content -->
    <template x-if="!loading && !error && family">
        <div>
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <a href="families.php" class="text-gray-500 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition-colors">
                            ← Back to Families
                        </a>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-3">
                        <span class="text-3xl">👨‍👩‍👧‍👦</span>
                        <span x-text="'Pamilya ' + family.family_name"></span>
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        <span x-text="family.local_name"></span> • 
                        <span x-text="family.district_name"></span>
                    </p>
                </div>
                
                <div class="flex gap-2">
                    <button @click="printFamily()" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        Print
                    </button>
                    <button @click="editMode = !editMode" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        <span x-text="editMode ? 'Cancel' : 'Edit'"></span>
                    </button>
                </div>
            </div>
            
            <!-- Family Card -->
            <div class="bg-gradient-to-br from-blue-600 to-purple-700 rounded-2xl shadow-xl p-6 mb-6 text-white" id="family-card">
                <div class="flex flex-col md:flex-row md:items-center gap-6">
                    <!-- Family Icon -->
                    <div class="w-24 h-24 bg-white bg-opacity-20 rounded-2xl flex items-center justify-center">
                        <span class="text-5xl">👨‍👩‍👧‍👦</span>
                    </div>
                    
                    <!-- Family Info -->
                    <div class="flex-1">
                        <h2 class="text-3xl font-bold mb-1" x-text="'Pamilya ' + family.family_name"></h2>
                        <div class="flex flex-wrap gap-4 text-blue-100">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                <span>Purok <span x-text="family.purok || '-'"></span>-<span x-text="family.grupo || '-'"></span></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                                <span x-text="family.members.length + ' Members'"></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded-full text-sm font-medium"
                                      :class="{
                                          'bg-green-400 text-green-900': family.status === 'active',
                                          'bg-gray-400 text-gray-900': family.status === 'inactive',
                                          'bg-yellow-400 text-yellow-900': family.status === 'transferred'
                                      }"
                                      x-text="family.status.charAt(0).toUpperCase() + family.status.slice(1)">
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div class="bg-white bg-opacity-20 rounded-lg p-3">
                            <p class="text-2xl font-bold" x-text="getMemberCountByKapisanan('Buklod')">0</p>
                            <p class="text-xs text-blue-100">Buklod</p>
                        </div>
                        <div class="bg-white bg-opacity-20 rounded-lg p-3">
                            <p class="text-2xl font-bold" x-text="getMemberCountByKapisanan('Kadiwa')">0</p>
                            <p class="text-xs text-blue-100">Kadiwa</p>
                        </div>
                        <div class="bg-white bg-opacity-20 rounded-lg p-3">
                            <p class="text-2xl font-bold" x-text="getMemberCountByKapisanan('Binhi')">0</p>
                            <p class="text-xs text-blue-100">Binhi</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contact Info -->
            <template x-if="family.address || family.contact">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-if="family.address">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Address</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="family.address"></p>
                                </div>
                            </div>
                        </template>
                        <template x-if="family.contact">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Contact</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="family.contact"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            
            <!-- Family Members -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <span>👥</span>
                        Family Members
                    </h3>
                    <button x-show="editMode" @click="showAddMember = true" class="text-sm px-3 py-1 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        + Add Member
                    </button>
                </div>
                
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-for="member in family.members" :key="member.id">
                        <div class="p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <!-- Member Avatar -->
                                    <div class="w-12 h-12 rounded-full flex items-center justify-center"
                                         :class="{
                                             'bg-green-100 dark:bg-green-900': member.is_head,
                                             'bg-blue-100 dark:bg-blue-900': !member.is_head
                                         }">
                                        <span class="text-2xl" x-text="member.is_head ? '👤' : getRelationshipEmoji(member.relationship)"></span>
                                    </div>
                                    
                                    <!-- Member Info -->
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <p class="font-medium text-gray-900 dark:text-white" x-text="member.name"></p>
                                            <template x-if="member.is_head">
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Pangulo</span>
                                            </template>
                                        </div>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span class="capitalize" x-text="member.is_head ? 'Head of Family' : member.relationship_display"></span>
                                            <template x-if="member.birthday">
                                                <span> • Born <span x-text="formatDate(member.birthday)"></span></span>
                                            </template>
                                        </p>
                                    </div>
                                </div>
                                
                                <!-- Kapisanan Badge -->
                                <div class="flex items-center gap-3">
                                    <span class="px-3 py-1 text-xs font-medium rounded-full"
                                          :class="{
                                              'bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-200': member.kapisanan === 'Buklod',
                                              'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200': member.kapisanan === 'Kadiwa',
                                              'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200': member.kapisanan === 'Binhi',
                                              'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200': member.kapisanan === 'PNK',
                                              'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200': member.kapisanan === 'HDB',
                                              'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200': !member.kapisanan
                                          }"
                                          x-text="member.kapisanan || 'N/A'">
                                    </span>
                                    
                                    <span class="px-2 py-0.5 text-xs rounded-full uppercase"
                                          :class="{
                                              'bg-green-100 text-green-800': member.status === 'active',
                                              'bg-gray-100 text-gray-800': member.status === 'deceased',
                                              'bg-yellow-100 text-yellow-800': member.status === 'transferred'
                                          }"
                                          x-text="member.source_type">
                                    </span>
                                    
                                    <template x-if="editMode && !member.is_head">
                                        <button @click="removeMember(member.id)" class="p-1 text-red-500 hover:bg-red-100 dark:hover:bg-red-900/30 rounded transition-colors">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            
            <!-- Notes -->
            <template x-if="family.notes">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Notes</h3>
                    <p class="text-gray-600 dark:text-gray-400" x-text="family.notes"></p>
                </div>
            </template>
            
            <!-- Metadata -->
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 text-sm text-gray-500 dark:text-gray-400">
                <div class="flex flex-wrap gap-4">
                    <span>Created by <span class="font-medium" x-text="family.created_by_name"></span></span>
                    <span>on <span class="font-medium" x-text="formatDate(family.created_at)"></span></span>
                    <template x-if="family.updated_at">
                        <span>• Last updated <span class="font-medium" x-text="formatDate(family.updated_at)"></span></span>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function familyProfile(familyId) {
    return {
        familyId: familyId,
        family: null,
        loading: true,
        error: null,
        editMode: false,
        showAddMember: false,
        
        init() {
            this.loadFamily();
        },
        
        async loadFamily() {
            this.loading = true;
            this.error = null;
            
            try {
                const response = await fetch(`api/get-families.php?action=get&id=${this.familyId}`);
                const result = await response.json();
                
                if (result.success) {
                    this.family = result.data;
                } else {
                    this.error = result.error || 'Failed to load family';
                }
            } catch (error) {
                console.error('Error loading family:', error);
                this.error = 'Error loading family data';
            } finally {
                this.loading = false;
            }
        },
        
        getMemberCountByKapisanan(kapisanan) {
            if (!this.family || !this.family.members) return 0;
            return this.family.members.filter(m => m.kapisanan === kapisanan).length;
        },
        
        getRelationshipEmoji(relationship) {
            const emojis = {
                'asawa': '💑',
                'anak': '👶',
                'pamangkin': '👦',
                'apo': '👶',
                'magulang': '👴',
                'kapatid': '🧑',
                'indibidwal': '👤',
                'others': '👥'
            };
            return emojis[relationship] || '👤';
        },
        
        formatDate(dateStr) {
            if (!dateStr) return '-';
            try {
                const date = new Date(dateStr);
                return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            } catch (e) {
                return dateStr;
            }
        },
        
        printFamily() {
            window.print();
        },
        
        async removeMember(memberId) {
            if (!confirm('Are you sure you want to remove this member from the family?')) return;
            
            // TODO: Implement remove member API
            alert('Remove member feature coming soon!');
        }
    };
}
</script>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #family-card, #family-card * {
        visibility: visible;
    }
    #family-card {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
