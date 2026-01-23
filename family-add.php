<?php
/**
 * Add Family Page
 * INC Family App - Create new family (Sambahayan)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

// Check permission
if (!hasPermission('can_add_officers')) {
    header('Location: families.php?error=' . urlencode('You do not have permission to add families.'));
    exit;
}

$pageTitle = 'Add Family';
$currentUser = getCurrentUser();

ob_start();
?>

<div class="max-w-4xl mx-auto" x-data="addFamily()">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3 mb-2">
            <a href="families.php" class="text-gray-500 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition-colors">
                ← Back to Families
            </a>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-3">
            <span class="text-3xl">👨‍👩‍👧‍👦</span>
            Add New Family
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Create a new family unit (Sambahayan)</p>
    </div>
    
    <!-- Progress Steps -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2" :class="step >= 1 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'">
                <div class="w-8 h-8 rounded-full flex items-center justify-center" :class="step >= 1 ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700'">1</div>
                <span class="font-medium hidden sm:inline">Select Head</span>
            </div>
            <div class="flex-1 h-1 mx-4 bg-gray-200 dark:bg-gray-700">
                <div class="h-1 bg-blue-600 transition-all" :style="{ width: step >= 2 ? '100%' : '0%' }"></div>
            </div>
            <div class="flex items-center gap-2" :class="step >= 2 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'">
                <div class="w-8 h-8 rounded-full flex items-center justify-center" :class="step >= 2 ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700'">2</div>
                <span class="font-medium hidden sm:inline">Add Members</span>
            </div>
            <div class="flex-1 h-1 mx-4 bg-gray-200 dark:bg-gray-700">
                <div class="h-1 bg-blue-600 transition-all" :style="{ width: step >= 3 ? '100%' : '0%' }"></div>
            </div>
            <div class="flex items-center gap-2" :class="step >= 3 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'">
                <div class="w-8 h-8 rounded-full flex items-center justify-center" :class="step >= 3 ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700'">3</div>
                <span class="font-medium hidden sm:inline">Review & Save</span>
            </div>
        </div>
    </div>
    
    <!-- Step 1: Select Pangulo ng Sambahayan -->
    <div x-show="step === 1" x-transition class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-4">
            <h2 class="text-lg font-bold text-white flex items-center gap-2">
                <span>👤</span>
                Step 1: Select Pangulo ng Sambahayan
            </h2>
            <p class="text-blue-100 text-sm">Search and select the head of the family from existing records</p>
        </div>
        
        <div class="p-6">
            <!-- Search Box -->
            <div class="relative mb-4">
                <input type="text" 
                       x-model="headSearch"
                       @input.debounce.200ms="searchHead()"
                       @focus="showHeadResults = true"
                       placeholder="Search by name or registry number..."
                       class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg">
                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <div x-show="searchingHead" class="absolute right-3 top-4">
                    <svg class="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
            </div>
            
            <!-- Search Results -->
            <div x-show="showHeadResults && headResults.length > 0" 
                 @click.away="showHeadResults = false"
                 class="border border-gray-200 dark:border-gray-700 rounded-lg max-h-80 overflow-y-auto mb-4">
                <template x-for="result in headResults" :key="result.source_key">
                    <div @click="selectHead(result)" 
                         class="p-3 hover:bg-blue-50 dark:hover:bg-blue-900/30 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0 transition-colors">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white" x-text="result.name"></p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    <span x-text="result.registry_number"></span> • 
                                    <span class="capitalize" x-text="result.source"></span> •
                                    <span x-text="result.kapisanan || 'N/A'"></span>
                                </p>
                            </div>
                            <span class="text-xs px-2 py-1 rounded-full" 
                                  :class="{
                                      'bg-pink-100 text-pink-800': result.kapisanan === 'Buklod',
                                      'bg-blue-100 text-blue-800': result.kapisanan === 'Kadiwa',
                                      'bg-green-100 text-green-800': result.kapisanan === 'Binhi',
                                      'bg-purple-100 text-purple-800': result.kapisanan === 'PNK',
                                      'bg-yellow-100 text-yellow-800': result.kapisanan === 'HDB'
                                  }"
                                  x-text="result.kapisanan || 'Unknown'">
                            </span>
                        </div>
                    </div>
                </template>
            </div>
            
            <!-- Selected Head -->
            <template x-if="selectedHead">
                <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-xl p-4 mb-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center">
                                <span class="text-white text-xl">👤</span>
                            </div>
                            <div>
                                <p class="font-bold text-green-800 dark:text-green-200 text-lg" x-text="selectedHead.name"></p>
                                <p class="text-sm text-green-600 dark:text-green-400">
                                    <span x-text="selectedHead.registry_number"></span> • 
                                    <span x-text="selectedHead.kapisanan || 'N/A'"></span> •
                                    Purok <span x-text="selectedHead.purok || '-'"></span>-<span x-text="selectedHead.grupo || '-'"></span>
                                </p>
                            </div>
                        </div>
                        <button @click="clearHead()" class="text-red-500 hover:text-red-700 p-2 hover:bg-red-100 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </template>
            
            <!-- Next Button -->
            <div class="flex justify-end">
                <button @click="step = 2" 
                        :disabled="!selectedHead"
                        class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors font-medium flex items-center gap-2">
                    Next: Add Members
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Step 2: Add Family Members -->
    <div x-show="step === 2" x-transition class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-purple-700 p-4">
            <h2 class="text-lg font-bold text-white flex items-center gap-2">
                <span>👥</span>
                Step 2: Add Kaanib ng Sambahayan
            </h2>
            <p class="text-purple-100 text-sm">Add family members and set their relationships</p>
        </div>
        
        <div class="p-6">
            <!-- Head Summary -->
            <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg p-3 mb-4">
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    <strong>Pangulo:</strong> <span x-text="selectedHead?.name"></span>
                    <span class="mx-2">•</span>
                    <strong>Purok-Grupo:</strong> <span x-text="(selectedHead?.purok || '-') + '-' + (selectedHead?.grupo || '-')"></span>
                </p>
            </div>
            
            <!-- Add Member Section -->
            <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 mb-4">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Add Member</h3>
                
                <!-- Search Member -->
                <div class="relative mb-3">
                    <input type="text" 
                           x-model="memberSearch"
                           @input.debounce.200ms="searchMember()"
                           @focus="showMemberResults = true"
                           placeholder="Search by name (from HDB, PNK, Tarheta)..."
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                
                <!-- Member Search Results -->
                <div x-show="showMemberResults && memberResults.length > 0" 
                     @click.away="showMemberResults = false"
                     class="border border-gray-200 dark:border-gray-700 rounded-lg max-h-60 overflow-y-auto mb-3">
                    <template x-for="result in memberResults" :key="result.source_key">
                        <div @click="selectMemberToAdd(result)" 
                             class="p-3 hover:bg-purple-50 dark:hover:bg-purple-900/30 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0 transition-colors">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="result.name"></p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        <span class="uppercase" x-text="result.source"></span> • 
                                        <span x-text="result.kapisanan || 'N/A'"></span>
                                    </p>
                                </div>
                                <span class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-700" x-text="result.source.toUpperCase()"></span>
                            </div>
                        </div>
                    </template>
                </div>
                
                <!-- Selected Member to Add -->
                <template x-if="pendingMember">
                    <div class="bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 mb-3">
                        <div class="flex items-center justify-between mb-3">
                            <p class="font-medium text-yellow-800 dark:text-yellow-200" x-text="pendingMember.name"></p>
                            <button @click="pendingMember = null" class="text-red-500 hover:text-red-700">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <!-- Relationship Selection -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Relasyon sa Pangulo</label>
                                <select x-model="pendingRelationship" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                                    <option value="">-- Select --</option>
                                    <option value="asawa">Asawa</option>
                                    <option value="anak">Anak</option>
                                    <option value="pamangkin">Pamangkin</option>
                                    <option value="apo">Apo</option>
                                    <option value="magulang">Magulang</option>
                                    <option value="kapatid">Kapatid</option>
                                    <option value="indibidwal">Indibidwal</option>
                                    <option value="others">Others (Specify)</option>
                                </select>
                            </div>
                            <div x-show="pendingRelationship === 'others'">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Specify</label>
                                <input type="text" x-model="pendingRelationshipSpecify" placeholder="Specify relationship..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                            </div>
                        </div>
                        
                        <div class="flex justify-end mt-3">
                            <button @click="addMemberToFamily()" 
                                    :disabled="!pendingRelationship || (pendingRelationship === 'others' && !pendingRelationshipSpecify)"
                                    class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-sm font-medium">
                                Add to Family
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            
            <!-- Added Members List -->
            <div class="mb-6">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                    <span>👨‍👩‍👧‍👦</span>
                    Family Members (<span x-text="members.length + 1"></span>)
                </h3>
                
                <!-- Head (always first) -->
                <div class="border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/30 rounded-lg p-3 mb-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">👤</span>
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white" x-text="selectedHead?.name"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-semibold text-green-600">Pangulo ng Sambahayan</span> • 
                                    <span x-text="selectedHead?.kapisanan || 'N/A'"></span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Other Members -->
                <template x-for="(member, index) in members" :key="index">
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 mb-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="text-2xl" x-text="getRelationshipEmoji(member.relationship)"></span>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="member.name"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        <span class="font-semibold capitalize" x-text="member.relationship === 'others' ? member.relationship_specify : member.relationship"></span> • 
                                        <span x-text="member.kapisanan || 'N/A'"></span>
                                    </p>
                                </div>
                            </div>
                            <button @click="removeMember(index)" class="text-red-500 hover:text-red-700 p-2 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </template>
                
                <template x-if="members.length === 0">
                    <div class="text-center py-4 text-gray-500 dark:text-gray-400 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg">
                        <p>No additional members added yet</p>
                        <p class="text-sm">Search above to add family members</p>
                    </div>
                </template>
            </div>
            
            <!-- Navigation Buttons -->
            <div class="flex justify-between">
                <button @click="step = 1" class="px-6 py-3 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-medium flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    Back
                </button>
                <button @click="step = 3" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center gap-2">
                    Next: Review
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Step 3: Review & Save -->
    <div x-show="step === 3" x-transition class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="bg-gradient-to-r from-green-600 to-green-700 p-4">
            <h2 class="text-lg font-bold text-white flex items-center gap-2">
                <span>✅</span>
                Step 3: Review & Save Family
            </h2>
            <p class="text-green-100 text-sm">Review the family information and save</p>
        </div>
        
        <div class="p-6">
            <!-- Family Summary Card -->
            <div class="bg-gradient-to-br from-blue-50 to-purple-50 dark:from-blue-900/30 dark:to-purple-900/30 border border-blue-200 dark:border-blue-800 rounded-xl p-6 mb-6">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-16 h-16 bg-blue-500 rounded-full flex items-center justify-center">
                        <span class="text-3xl">👨‍👩‍👧‍👦</span>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white" x-text="'Pamilya ' + (selectedHead?.last_name || 'Unknown')"></h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            <span x-text="members.length + 1"></span> members • 
                            Purok <span x-text="selectedHead?.purok || '-'"></span>-<span x-text="selectedHead?.grupo || '-'"></span>
                        </p>
                    </div>
                </div>
                
                <!-- Family Tree Preview -->
                <div class="space-y-2">
                    <div class="flex items-center gap-2 p-2 bg-white dark:bg-gray-800 rounded-lg">
                        <span class="text-xl">👤</span>
                        <div class="flex-1">
                            <span class="font-medium" x-text="selectedHead?.name"></span>
                            <span class="text-xs text-green-600 ml-2">(Pangulo)</span>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full bg-pink-100 text-pink-800" x-text="selectedHead?.kapisanan || 'N/A'"></span>
                    </div>
                    <template x-for="(member, index) in members" :key="index">
                        <div class="flex items-center gap-2 p-2 bg-white dark:bg-gray-800 rounded-lg ml-6">
                            <span class="text-xl" x-text="getRelationshipEmoji(member.relationship)"></span>
                            <div class="flex-1">
                                <span class="font-medium" x-text="member.name"></span>
                                <span class="text-xs text-gray-500 ml-2" x-text="'(' + (member.relationship === 'others' ? member.relationship_specify : member.relationship) + ')'"></span>
                            </div>
                            <span class="text-xs px-2 py-1 rounded-full"
                                  :class="{
                                      'bg-pink-100 text-pink-800': member.kapisanan === 'Buklod',
                                      'bg-blue-100 text-blue-800': member.kapisanan === 'Kadiwa',
                                      'bg-green-100 text-green-800': member.kapisanan === 'Binhi',
                                      'bg-purple-100 text-purple-800': member.kapisanan === 'PNK',
                                      'bg-yellow-100 text-yellow-800': member.kapisanan === 'HDB',
                                      'bg-gray-100 text-gray-800': !member.kapisanan
                                  }"
                                  x-text="member.kapisanan || 'N/A'">
                            </span>
                        </div>
                    </template>
                </div>
            </div>
            
            <!-- Optional Fields -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address (Optional)</label>
                    <input type="text" x-model="familyAddress" placeholder="Family address..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contact Number (Optional)</label>
                    <input type="text" x-model="familyContact" placeholder="Contact number..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes (Optional)</label>
                <textarea x-model="familyNotes" rows="2" placeholder="Additional notes about the family..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"></textarea>
            </div>
            
            <!-- Navigation Buttons -->
            <div class="flex justify-between">
                <button @click="step = 2" class="px-6 py-3 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-medium flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    Back
                </button>
                <button @click="saveFamily()" 
                        :disabled="saving"
                        class="px-8 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors font-medium flex items-center gap-2">
                    <template x-if="!saving">
                        <span class="flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Save Family
                        </span>
                    </template>
                    <template x-if="saving">
                        <span class="flex items-center gap-2">
                            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Saving...
                        </span>
                    </template>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div x-show="showSuccess" x-transition class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full p-6 text-center">
        <div class="w-20 h-20 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-10 h-10 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>
        <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Family Created!</h3>
        <p class="text-gray-600 dark:text-gray-400 mb-6">The family has been successfully registered.</p>
        <div class="flex gap-3 justify-center">
            <a href="families.php" class="px-6 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors font-medium">
                View All Families
            </a>
            <a :href="'family-profile.php?id=' + createdFamilyId" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                View Family Profile
            </a>
        </div>
    </div>
</div>

<script>
function addFamily() {
    return {
        step: 1,
        
        // Head selection
        headSearch: '',
        headResults: [],
        searchingHead: false,
        showHeadResults: false,
        selectedHead: null,
        
        // Member addition
        memberSearch: '',
        memberResults: [],
        showMemberResults: false,
        pendingMember: null,
        pendingRelationship: '',
        pendingRelationshipSpecify: '',
        members: [],
        
        // Family info
        familyAddress: '',
        familyContact: '',
        familyNotes: '',
        
        // State
        saving: false,
        showSuccess: false,
        createdFamilyId: null,
        
        async searchHead() {
            if (this.headSearch.length < 2) {
                this.headResults = [];
                return;
            }
            
            this.searchingHead = true;
            try {
                const response = await fetch(`api/search-all-members.php?q=${encodeURIComponent(this.headSearch)}&source=tarheta&limit=10`);
                const result = await response.json();
                if (result.success) {
                    this.headResults = result.data;
                    this.showHeadResults = true;
                }
            } catch (error) {
                console.error('Search error:', error);
            } finally {
                this.searchingHead = false;
            }
        },
        
        selectHead(result) {
            this.selectedHead = result;
            this.headSearch = '';
            this.headResults = [];
            this.showHeadResults = false;
        },
        
        clearHead() {
            this.selectedHead = null;
            this.members = [];
        },
        
        async searchMember() {
            if (this.memberSearch.length < 2) {
                this.memberResults = [];
                return;
            }
            
            // Build exclude list
            const excludeList = [this.selectedHead?.source_key];
            this.members.forEach(m => excludeList.push(m.source_key));
            
            try {
                const response = await fetch(`api/search-all-members.php?q=${encodeURIComponent(this.memberSearch)}&limit=15&exclude=${encodeURIComponent(JSON.stringify(excludeList))}`);
                const result = await response.json();
                if (result.success) {
                    this.memberResults = result.data;
                    this.showMemberResults = true;
                }
            } catch (error) {
                console.error('Search error:', error);
            }
        },
        
        selectMemberToAdd(result) {
            this.pendingMember = result;
            this.pendingRelationship = '';
            this.pendingRelationshipSpecify = '';
            this.memberSearch = '';
            this.memberResults = [];
            this.showMemberResults = false;
        },
        
        addMemberToFamily() {
            if (!this.pendingMember || !this.pendingRelationship) return;
            
            this.members.push({
                ...this.pendingMember,
                relationship: this.pendingRelationship,
                relationship_specify: this.pendingRelationshipSpecify
            });
            
            this.pendingMember = null;
            this.pendingRelationship = '';
            this.pendingRelationshipSpecify = '';
        },
        
        removeMember(index) {
            this.members.splice(index, 1);
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
        
        async saveFamily() {
            if (!this.selectedHead) return;
            
            this.saving = true;
            
            try {
                const response = await fetch('api/add-family.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        head: this.selectedHead,
                        members: this.members,
                        address: this.familyAddress,
                        contact: this.familyContact,
                        notes: this.familyNotes
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    this.createdFamilyId = result.family_id;
                    this.showSuccess = true;
                } else {
                    alert('Error: ' + (result.error || 'Failed to create family'));
                }
            } catch (error) {
                console.error('Save error:', error);
                alert('Error saving family. Please try again.');
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
