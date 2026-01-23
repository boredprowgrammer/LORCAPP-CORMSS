<?php
/**
 * Family Registry - Add New Family
 * Uses Puter AI for intelligent family member suggestions
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

// Get district/local for the user
$districtCode = $currentUser['district_code'] ?? '';
$localCode = $currentUser['local_code'] ?? '';

$pageTitle = 'Add Family';

ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6" x-data="addFamilyForm()" x-init="init()">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center gap-3 mb-4">
            <a href="index.php" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                ← Back to Family List
            </a>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
            <i class="fa-solid fa-house-circle-plus text-indigo-600 mr-2"></i>
            Add New Family
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Register a new household and its members</p>
    </div>

    <!-- Step Indicator -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
        <div class="flex items-center justify-center">
            <div class="flex items-center">
                <div :class="step >= 1 ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300'" class="w-8 h-8 rounded-full flex items-center justify-center font-bold">1</div>
                <span class="ml-2 text-sm font-medium" :class="step >= 1 ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500'">Pangulo ng Sambahayan</span>
            </div>
            <div class="w-16 h-0.5 mx-4" :class="step >= 2 ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-600'"></div>
            <div class="flex items-center">
                <div :class="step >= 2 ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300'" class="w-8 h-8 rounded-full flex items-center justify-center font-bold">2</div>
                <span class="ml-2 text-sm font-medium" :class="step >= 2 ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500'">Add Members</span>
            </div>
            <div class="w-16 h-0.5 mx-4" :class="step >= 3 ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-600'"></div>
            <div class="flex items-center">
                <div :class="step >= 3 ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300'" class="w-8 h-8 rounded-full flex items-center justify-center font-bold">3</div>
                <span class="ml-2 text-sm font-medium" :class="step >= 3 ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500'">Review & Save</span>
            </div>
        </div>
    </div>

    <!-- Step 1: Select Pangulo ng Sambahayan -->
    <div x-show="step === 1" x-transition class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
            <i class="fa-solid fa-user-tie text-indigo-600 mr-2"></i>
            Select Pangulo ng Sambahayan
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Search and select the head of household from the Tarheta registry</p>

        <!-- Search Box -->
        <div class="relative mb-4">
            <input type="text" 
                   x-model="panguloSearch" 
                   @input.debounce.300ms="searchPangulo()"
                   @focus="showPanguloResults = true"
                   placeholder="Search by name or registry number..."
                   class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            <i class="fa-solid fa-search absolute left-3 top-3.5 text-gray-400"></i>
            <i x-show="searchingPangulo" class="fa-solid fa-spinner fa-spin absolute right-3 top-3.5 text-gray-400"></i>
        </div>

        <!-- Search Results -->
        <div x-show="showPanguloResults && panguloResults.length > 0" 
             @click.away="showPanguloResults = false"
             class="border border-gray-200 dark:border-gray-600 rounded-lg max-h-64 overflow-y-auto mb-4">
            <template x-for="person in panguloResults" :key="person.id">
                <div @click="selectPangulo(person)" 
                     class="px-4 py-3 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-medium text-gray-900 dark:text-gray-100" x-text="person.full_name"></div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                <span x-text="person.registry_number"></span> • 
                                <span x-text="person.cfo_classification || 'No Classification'"></span>
                            </div>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-600 text-gray-600 dark:text-gray-300" x-text="person.purok_grupo || 'No Purok'"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Selected Pangulo -->
        <div x-show="selectedPangulo" class="bg-indigo-50 dark:bg-indigo-900/30 rounded-lg p-4 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-indigo-600 rounded-full flex items-center justify-center text-white font-bold text-lg">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-900 dark:text-gray-100" x-text="selectedPangulo?.full_name"></div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Registry: <span x-text="selectedPangulo?.registry_number"></span>
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Purok-Grupo: <span x-text="selectedPangulo?.purok_grupo || '-'"></span>
                        </div>
                    </div>
                </div>
                <button @click="clearPangulo()" class="text-red-600 hover:text-red-700 p-2">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Family Code -->
        <div x-show="selectedPangulo" class="mb-6">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Family Code</label>
            <input type="text" x-model="familyCode" 
                   class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500"
                   placeholder="Auto-generated or enter custom code">
            <p class="text-xs text-gray-500 mt-1">Leave blank to auto-generate based on registry number</p>
        </div>

        <!-- Optional: Asawa/Mother Name for better suggestions -->
        <div x-show="selectedPangulo" class="mb-6">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                <i class="fa-solid fa-heart text-pink-500 mr-1"></i>
                Asawa / Ina ng Sambahayan (Optional)
            </label>
            <div class="relative">
                <input type="text" x-model="asawaSearch" 
                       @input.debounce.300ms="searchAsawa()"
                       @focus="showAsawaResults = true"
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-pink-500"
                       placeholder="Search name or registry number...">
                <i class="fa-solid fa-search absolute left-3 top-2.5 text-gray-400"></i>
                <i x-show="searchingAsawa" class="fa-solid fa-spinner fa-spin absolute right-3 top-2.5 text-gray-400"></i>
            </div>
            
            <!-- Asawa Search Results -->
            <div x-show="showAsawaResults && asawaResults.length > 0" 
                 @click.away="showAsawaResults = false"
                 class="mt-1 border border-gray-200 dark:border-gray-600 rounded-lg max-h-48 overflow-y-auto bg-white dark:bg-gray-800 shadow-lg z-10 relative">
                <template x-for="person in asawaResults" :key="person.id">
                    <div @click="selectAsawa(person)" 
                         class="px-4 py-2 hover:bg-pink-50 dark:hover:bg-pink-900/30 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-gray-100" x-text="person.full_name"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400" x-text="person.registry_number || ''"></div>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  :class="{
                                      'bg-pink-100 text-pink-700': person.cfo_classification === 'Buklod',
                                      'bg-blue-100 text-blue-700': person.cfo_classification === 'Kadiwa',
                                      'bg-green-100 text-green-700': person.cfo_classification === 'Binhi'
                                  }"
                                  x-text="person.cfo_classification || 'Tarheta'"></span>
                        </div>
                    </div>
                </template>
            </div>
            
            <!-- Selected Asawa -->
            <div x-show="selectedAsawa" class="mt-2 bg-pink-50 dark:bg-pink-900/30 rounded-lg p-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-pink-500 rounded-full flex items-center justify-center text-white">
                            <i class="fa-solid fa-heart"></i>
                        </div>
                        <div>
                            <div class="font-medium text-gray-900 dark:text-gray-100 text-sm" x-text="selectedAsawa?.full_name"></div>
                            <div class="text-xs text-gray-500" x-text="selectedAsawa?.registry_number || ''"></div>
                        </div>
                    </div>
                    <button @click="clearAsawa()" class="text-red-500 hover:text-red-600 p-1">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
            </div>
            
            <p class="text-xs text-gray-500 mt-1">
                <i class="fa-solid fa-info-circle mr-1"></i>
                Search and select the spouse/mother to find HDB/PNK/Tarheta children matching their name
            </p>
        </div>

        <div class="flex justify-end">
            <button @click="goToStep(2)" :disabled="!selectedPangulo" 
                    class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors font-medium">
                Next: Add Members <i class="fa-solid fa-arrow-right ml-2"></i>
            </button>
        </div>
    </div>

    <!-- Step 2: Add Family Members -->
    <div x-show="step === 2" x-transition class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
            <i class="fa-solid fa-users text-indigo-600 mr-2"></i>
            Add Family Members (Kaanib)
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Add members of this household from HDB, PNK, or Tarheta registry</p>

        <!-- Intelligent Suggestions -->
        <div x-show="suggestedMembers.length > 0 || loadingSuggestions" class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                    <i class="fa-solid fa-wand-magic-sparkles text-yellow-500 mr-2"></i>
                    Suggested Family Members
                    <span x-show="aiEnabled" class="ml-2 px-2 py-0.5 text-xs bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300 rounded-full">
                        <i class="fa-solid fa-robot mr-1"></i>AI Enhanced
                    </span>
                    <span x-show="learningStats && learningStats.total_families > 0" 
                          class="ml-2 px-2 py-0.5 text-xs rounded-full cursor-help"
                          :class="learningStats.overall_accuracy >= 90 ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 
                                  learningStats.overall_accuracy >= 70 ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' : 
                                  'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300'"
                          :title="'Learned from ' + learningStats.total_families + ' families, ' + learningStats.total_members + ' members. ' + learningStats.total_suggestions_accepted + '/' + learningStats.total_suggestions_shown + ' suggestions accepted.'">
                        <i class="fa-solid fa-brain mr-1"></i>
                        <span x-text="learningStats.overall_accuracy + '% accuracy'"></span>
                    </span>
                </h3>
                <button @click="showSuggestions = !showSuggestions" 
                        class="text-xs text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                    <span x-text="showSuggestions ? 'Hide' : 'Show'"></span>
                </button>
            </div>
            
            <div x-show="loadingSuggestions" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>Finding potential family members...</span>
            </div>
            
            <div x-show="aiAnalyzing && !loadingSuggestions" class="flex items-center gap-2 text-sm text-purple-600 dark:text-purple-400 p-3 bg-purple-50 dark:bg-purple-900/30 rounded-lg mb-3">
                <i class="fa-solid fa-robot fa-bounce"></i>
                <span>AI is analyzing relationships...</span>
            </div>
            
            <div x-show="showSuggestions && !loadingSuggestions && suggestedMembers.length > 0" 
                 class="bg-gradient-to-r from-yellow-50 to-orange-50 dark:from-yellow-900/20 dark:to-orange-900/20 rounded-lg p-4 border border-yellow-200 dark:border-yellow-800">
                <p class="text-xs text-yellow-700 dark:text-yellow-300 mb-3">
                    <i class="fa-solid fa-lightbulb mr-1"></i>
                    Found <span x-text="suggestedMembers.length"></span> potential family member(s) based on name matching<span x-show="learningStats && learningStats.total_families > 0">, learned patterns</span> and parent records. Click to add:
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <template x-for="suggestion in suggestedMembers" :key="suggestion.id + '-' + suggestion.source">
                        <div @click="!isSuggestionAdded(suggestion) && addSuggestedMember(suggestion)"
                             class="flex items-center justify-between bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm border transition-all"
                             :class="[
                                 isSuggestionAdded(suggestion) ? 'opacity-50 cursor-not-allowed border-gray-200' : 'cursor-pointer hover:border-yellow-300 hover:shadow-md',
                                 ['father_match', 'mother_match', 'mother_asawa_input', 'father_asawa_input', 'middle_name_mother_match'].includes(suggestion.match_type) ? 'border-green-300 dark:border-green-700' : 'border-yellow-100 dark:border-yellow-900'
                             ]">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-medium"
                                     :class="{
                                         'bg-pink-500': suggestion.kapisanan === 'Buklod',
                                         'bg-blue-500': suggestion.kapisanan === 'Kadiwa',
                                         'bg-green-500': suggestion.kapisanan === 'Binhi',
                                         'bg-purple-500': suggestion.kapisanan === 'PNK',
                                         'bg-yellow-500': suggestion.kapisanan === 'HDB',
                                         'bg-gray-400': !suggestion.kapisanan
                                     }">
                                    <i class="fa-solid" :class="{
                                        'fa-heart': suggestion.match_type === 'spouse' || suggestion.match_type === 'asawa_input_match' || suggestion.match_type === 'selected_asawa',
                                        'fa-child': ['father_match', 'mother_match', 'mother_asawa_input', 'father_asawa_input', 'middle_name_mother_match'].includes(suggestion.match_type),
                                        'fa-user': !['spouse', 'selected_asawa', 'asawa_input_match', 'father_match', 'mother_match', 'mother_asawa_input', 'father_asawa_input', 'middle_name_mother_match'].includes(suggestion.match_type)
                                    }"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100 text-sm" x-text="suggestion.full_name"></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        <span x-text="suggestion.suggested_relasyon || 'Member'"></span> •
                                        <span x-text="suggestion.kapisanan"></span>
                                        <span x-show="suggestion.ai_confidence === 'high' || suggestion.confidence === 'high'" class="ml-1 text-green-600">
                                            <i class="fa-solid fa-check-circle"></i>
                                        </span>
                                        <span x-show="suggestion.ai_decided" class="ml-1 text-purple-600" title="AI approved this member">
                                            <i class="fa-solid fa-robot"></i> AI
                                        </span>
                                    </div>
                                    <!-- Show match reason for parent matches and asawa input -->
                                    <div x-show="['father_match', 'mother_match', 'mother_asawa_input', 'father_asawa_input', 'asawa_input_match', 'middle_name_mother_match', 'selected_asawa'].includes(suggestion.match_type) || suggestion.ai_reason" 
                                         class="text-xs text-green-600 dark:text-green-400 mt-0.5">
                                        <template x-if="suggestion.match_type === 'father_match'">
                                            <span><i class="fa-solid fa-link mr-1"></i>Father matches Pangulo</span>
                                        </template>
                                        <template x-if="suggestion.match_type === 'mother_match'">
                                            <span><i class="fa-solid fa-link mr-1"></i>Mother matches Pangulo</span>
                                        </template>
                                        <template x-if="suggestion.match_type === 'mother_asawa_input'">
                                            <span><i class="fa-solid fa-heart mr-1"></i>Mother matches Asawa</span>
                                        </template>
                                        <template x-if="suggestion.match_type === 'father_asawa_input'">
                                            <span><i class="fa-solid fa-heart mr-1"></i>Father matches Asawa</span>
                                        </template>
                                        <template x-if="suggestion.match_type === 'asawa_input_match'">
                                            <span><i class="fa-solid fa-heart mr-1"></i>Matches Asawa name</span>
                                        </template>
                                        <template x-if="suggestion.match_type === 'selected_asawa'">
                                            <span><i class="fa-solid fa-heart mr-1"></i>Selected Asawa</span>
                                        </template>
                                        <template x-if="suggestion.match_type === 'middle_name_mother_match'">
                                            <span><i class="fa-solid fa-dna mr-1"></i>Middle name matches mother's maiden name</span>
                                        </template>
                                        <template x-if="suggestion.learned_reason">
                                            <span class="text-blue-600 dark:text-blue-400"><i class="fa-solid fa-brain mr-1"></i><span x-text="suggestion.learned_reason"></span></span>
                                        </template>
                                        <template x-if="suggestion.ai_reason && !suggestion.learned_reason && !['father_match', 'mother_match', 'mother_asawa_input', 'father_asawa_input', 'asawa_input_match', 'middle_name_mother_match', 'selected_asawa'].includes(suggestion.match_type)">
                                            <span><i class="fa-solid fa-robot mr-1"></i><span x-text="suggestion.ai_reason"></span></span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span x-show="suggestion.learned_confidence" 
                                      class="text-xs px-1.5 py-0.5 rounded-full"
                                      :class="suggestion.learned_confidence >= 90 ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 
                                              suggestion.learned_confidence >= 70 ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' : 
                                              'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300'"
                                      :title="'Confidence: ' + suggestion.learned_confidence + '%'">
                                    <i class="fa-solid fa-brain mr-0.5"></i><span x-text="suggestion.learned_confidence + '%'"></span>
                                </span>
                                <span x-show="isSuggestionAdded(suggestion)" class="text-xs text-green-600 dark:text-green-400">
                                    <i class="fa-solid fa-check"></i> Added
                                </span>
                                <span x-show="!isSuggestionAdded(suggestion)" class="text-indigo-600 dark:text-indigo-400">
                                    <i class="fa-solid fa-plus"></i>
                                </span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Added Members List -->
        <div x-show="members.length > 0" class="mb-6">
            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Added Members (<span x-text="members.length"></span>)</h3>
            <div class="space-y-2">
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
                        <button @click="removeMember(index)" class="text-red-600 hover:text-red-700 p-2">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Add Member Form -->
        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-6">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
                <i class="fa-solid fa-user-plus text-green-600 mr-2"></i>
                Add New Member
            </h3>

            <!-- Search for Existing Member -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search Member (from HDB, PNK, Tarheta)</label>
                <div class="relative">
                    <input type="text" 
                           x-model="memberSearch" 
                           @input.debounce.300ms="searchMember()"
                           @focus="showMemberResults = true"
                           placeholder="Search by name..."
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    <i class="fa-solid fa-search absolute left-3 top-2.5 text-gray-400"></i>
                    <i x-show="searchingMember" class="fa-solid fa-spinner fa-spin absolute right-3 top-2.5 text-gray-400"></i>
                </div>
            </div>

            <!-- Member Search Results -->
            <div x-show="showMemberResults && memberResults.length > 0" 
                 @click.away="showMemberResults = false"
                 class="border border-gray-200 dark:border-gray-600 rounded-lg max-h-48 overflow-y-auto mb-4 bg-white dark:bg-gray-800">
                <template x-for="person in memberResults" :key="person.id + '-' + person.source">
                    <div @click="selectMember(person)" 
                         class="px-4 py-2 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-gray-100" x-text="person.full_name"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400" x-text="person.registry_number || ''"></div>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  :class="{
                                      'bg-pink-100 text-pink-700': person.source === 'Buklod',
                                      'bg-blue-100 text-blue-700': person.source === 'Kadiwa',
                                      'bg-green-100 text-green-700': person.source === 'Binhi',
                                      'bg-purple-100 text-purple-700': person.source === 'PNK',
                                      'bg-yellow-100 text-yellow-700': person.source === 'HDB',
                                      'bg-gray-100 text-gray-700': !person.source
                                  }"
                                  x-text="person.source"></span>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Selected/New Member -->
            <div x-show="newMember.name" class="mb-4 p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                <div class="flex items-center justify-between mb-3">
                    <div class="font-medium text-gray-900 dark:text-gray-100" x-text="newMember.name"></div>
                    <button @click="clearNewMember()" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Relasyon sa Pangulo</label>
                        <select x-model="newMember.relasyon" @change="if(newMember.relasyon !== 'Iba pa') newMember.relasyon_specify = ''"
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
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Kapisanan</label>
                        <input type="text" x-model="newMember.kapisanan" readonly
                               class="w-full px-3 py-2 text-sm border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                    </div>
                </div>

                <button @click="addMember()" :disabled="!newMember.relasyon || (newMember.relasyon === 'Iba pa' && !newMember.relasyon_specify)"
                        class="mt-3 w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors text-sm font-medium">
                    <i class="fa-solid fa-plus mr-2"></i> Add to Family
                </button>
            </div>
        </div>

        <div class="flex justify-between">
            <button @click="goToStep(1)" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-medium">
                <i class="fa-solid fa-arrow-left mr-2"></i> Back
            </button>
            <button @click="goToStep(3)" 
                    class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                Next: Review <i class="fa-solid fa-arrow-right ml-2"></i>
            </button>
        </div>
    </div>

    <!-- Step 3: Review & Save -->
    <div x-show="step === 3" x-transition class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
            <i class="fa-solid fa-clipboard-check text-indigo-600 mr-2"></i>
            Review Family Information
        </h2>

        <!-- Family Summary -->
        <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-6 text-white mb-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-people-roof text-3xl"></i>
                </div>
                <div>
                    <div class="text-sm opacity-75">Family Code</div>
                    <div class="text-2xl font-bold" x-text="familyCode || 'Auto-generated'"></div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="opacity-75">Pangulo ng Sambahayan:</span>
                    <div class="font-semibold" x-text="selectedPangulo?.full_name"></div>
                </div>
                <div>
                    <span class="opacity-75">Purok-Grupo:</span>
                    <div class="font-semibold" x-text="selectedPangulo?.purok_grupo || '-'"></div>
                </div>
                <div>
                    <span class="opacity-75">Total Members:</span>
                    <div class="font-semibold" x-text="members.length + 1"></div>
                </div>
                <div>
                    <span class="opacity-75">Local:</span>
                    <div class="font-semibold"><?php echo htmlspecialchars($currentUser['local_name'] ?? $currentUser['district_name'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Members List -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Family Members</h3>
            <div class="space-y-2">
                <!-- Pangulo -->
                <div class="flex items-center gap-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg p-3">
                    <div class="w-10 h-10 bg-indigo-600 rounded-full flex items-center justify-center text-white font-bold">
                        <i class="fa-solid fa-crown"></i>
                    </div>
                    <div>
                        <div class="font-medium text-gray-900 dark:text-gray-100" x-text="selectedPangulo?.full_name"></div>
                        <div class="text-xs text-indigo-600 dark:text-indigo-400">Pangulo ng Sambahayan • <span x-text="selectedPangulo?.cfo_classification || 'N/A'"></span></div>
                    </div>
                </div>
                
                <!-- Other Members -->
                <template x-for="(member, index) in members" :key="index">
                    <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold"
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
                                • <span x-text="member.kapisanan || 'N/A'"></span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Notes -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes (Optional)</label>
            <textarea x-model="notes" rows="2" 
                      class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500"
                      placeholder="Any additional notes about this family..."></textarea>
        </div>

        <!-- Error Message -->
        <div x-show="errorMessage" class="mb-4 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg">
            <p class="text-red-700 dark:text-red-300 text-sm" x-text="errorMessage"></p>
        </div>

        <div class="flex justify-between">
            <button @click="goToStep(2)" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-medium">
                <i class="fa-solid fa-arrow-left mr-2"></i> Back
            </button>
            <button @click="saveFamily()" :disabled="saving"
                    class="px-8 py-2.5 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-400 transition-colors font-medium">
                <i x-show="!saving" class="fa-solid fa-save mr-2"></i>
                <i x-show="saving" class="fa-solid fa-spinner fa-spin mr-2"></i>
                <span x-text="saving ? 'Saving...' : 'Save Family'"></span>
            </button>
        </div>
    </div>
</div>

<!-- Puter AI for intelligent suggestions -->
<script src="https://js.puter.com/v2/"></script>

<script>
function addFamilyForm() {
    return {
        step: 1,
        
        // Step 1 - Pangulo
        panguloSearch: '',
        panguloResults: [],
        showPanguloResults: false,
        searchingPangulo: false,
        selectedPangulo: null,
        familyCode: '',
        
        // Asawa search
        asawaSearch: '',
        asawaResults: [],
        showAsawaResults: false,
        searchingAsawa: false,
        selectedAsawa: null,
        
        // Step 2 - Members
        members: [],
        memberSearch: '',
        memberResults: [],
        showMemberResults: false,
        searchingMember: false,
        
        // Intelligent Suggestions
        suggestedMembers: [],
        loadingSuggestions: false,
        showSuggestions: true,
        aiEnabled: false,
        aiAnalyzing: false,
        learningStats: null,
        
        // Behavioral Tracking for 98% accuracy
        suggestionsShown: [],  // All suggestions shown to user
        suggestionsAccepted: [], // Suggestions user added
        suggestionsModified: [], // Suggestions with changed relasyon
        
        newMember: {
            id: null,
            source: null,
            source_id: null,
            name: '',
            relasyon: '',
            relasyon_specify: '',
            kapisanan: ''
        },
        
        // Step 3 - Review
        notes: '',
        saving: false,
        errorMessage: '',

        init() {
            // Auto-focus search on load
        },

        goToStep(s) {
            this.step = s;
        },

        async searchPangulo() {
            if (this.panguloSearch.length < 2) {
                this.panguloResults = [];
                return;
            }
            
            this.searchingPangulo = true;
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/search-members.php?q=' + encodeURIComponent(this.panguloSearch) + '&source=tarheta');
                const data = await response.json();
                if (data.success) {
                    this.panguloResults = data.results;
                    this.showPanguloResults = true;
                }
            } catch (e) {
                console.error('Error:', e);
            } finally {
                this.searchingPangulo = false;
            }
        },

        selectPangulo(person) {
            this.selectedPangulo = person;
            this.showPanguloResults = false;
            this.panguloSearch = '';
            
            // Auto-generate family code from registry number
            if (person.registry_number) {
                this.familyCode = 'FAM-' + person.registry_number.replace(/[^0-9]/g, '').slice(-6);
            }
            
            // Fetch suggested members based on last name
            this.fetchSuggestedMembers(person.id);
        },
        
        // Asawa search functions
        async searchAsawa() {
            if (this.asawaSearch.length < 2) {
                this.asawaResults = [];
                return;
            }
            
            this.searchingAsawa = true;
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/search-members.php?q=' + encodeURIComponent(this.asawaSearch) + '&source=tarheta');
                const data = await response.json();
                if (data.success) {
                    // Filter out the pangulo
                    this.asawaResults = data.results.filter(r => r.id !== this.selectedPangulo?.id);
                    this.showAsawaResults = true;
                }
            } catch (e) {
                console.error('Error:', e);
            } finally {
                this.searchingAsawa = false;
            }
        },
        
        selectAsawa(person) {
            this.selectedAsawa = person;
            this.showAsawaResults = false;
            this.asawaSearch = '';
            
            // Automatically add asawa to members list
            const alreadyAdded = this.members.some(m => m.source === 'Tarheta' && m.source_id === person.id);
            if (!alreadyAdded) {
                this.members.push({
                    id: null,
                    source: person.cfo_classification || 'Tarheta',
                    source_id: person.id,
                    name: person.full_name,
                    relasyon: 'Asawa',
                    relasyon_specify: '',
                    kapisanan: person.cfo_classification || 'Tarheta'
                });
            }
            
            // Re-fetch suggestions with the selected asawa
            if (this.selectedPangulo) {
                this.fetchSuggestedMembers(this.selectedPangulo.id);
            }
        },
        
        clearAsawa() {
            // Remove asawa from members list if present
            if (this.selectedAsawa) {
                this.members = this.members.filter(m => 
                    !(m.source_id === this.selectedAsawa.id && m.relasyon === 'Asawa')
                );
            }
            
            this.selectedAsawa = null;
            this.asawaSearch = '';
            
            // Re-fetch suggestions without asawa
            if (this.selectedPangulo) {
                this.fetchSuggestedMembers(this.selectedPangulo.id);
            }
        },
        
        async fetchSuggestedMembers(panguloId) {
            this.loadingSuggestions = true;
            this.suggestedMembers = [];
            this.suggestionsShown = []; // Reset tracking for new pangulo
            this.aiEnabled = false;
            this.aiAnalyzing = false;
            this.learningStats = null;
            try {
                let url = '<?php echo BASE_URL; ?>/api/family/suggest-members.php?pangulo_id=' + panguloId + '&use_ai=0';
                if (this.selectedAsawa) {
                    url += '&asawa_id=' + this.selectedAsawa.id;
                    url += '&asawa_name=' + encodeURIComponent(this.selectedAsawa.full_name);
                }
                const response = await fetch(url);
                const data = await response.json();
                if (data.success) {
                    this.suggestedMembers = data.suggestions;
                    this.learningStats = data.learning_stats || null;
                    
                    // Track all suggestions shown to user for behavioral learning
                    this.suggestionsShown = data.suggestions.map(s => ({
                        id: s.id,
                        source: s.source,
                        full_name: s.full_name,
                        match_type: s.match_type,
                        suggested_relasyon: s.suggested_relasyon,
                        confidence: s.confidence || 'medium',
                        learned: !!s.learned_reason
                    }));
                    
                    // Use Puter AI to analyze and enhance suggestions
                    if (this.suggestedMembers.length > 0 && typeof puter !== 'undefined') {
                        this.analyzeWithPuterAI(data.pangulo_full_name);
                    }
                }
            } catch (e) {
                console.error('Error fetching suggestions:', e);
            } finally {
                this.loadingSuggestions = false;
            }
        },
        
        async analyzeWithPuterAI(panguloName) {
            if (this.suggestedMembers.length === 0) return;
            
            this.aiAnalyzing = true;
            this.aiEnabled = true;
            
            try {
                // Extract last names for matching
                const panguloLastName = panguloName.split(' ').pop();
                const asawaLastName = this.selectedAsawa ? this.selectedAsawa.full_name.split(' ').pop() : null;
                
                const membersInfo = this.suggestedMembers.slice(0, 20).map(m => {
                    const nameParts = m.full_name.split(' ');
                    return {
                        name: m.full_name,
                        first_name: nameParts[0],
                        middle_name: nameParts.length > 2 ? nameParts.slice(1, -1).join(' ') : null,
                        last_name: nameParts[nameParts.length - 1],
                        kapisanan: m.kapisanan || m.source,
                        purok: m.purok || null,
                        grupo: m.grupo || null,
                        match_type: m.match_type,
                        confidence: m.confidence,
                        current_relasyon: m.suggested_relasyon,
                        learned_reason: m.learned_reason || null,
                        learned_confidence: m.learned_confidence || null
                    };
                });
                
                // Get pangulo's location for comparison
                const panguloLocation = this.selectedPangulo ? 
                    `${this.selectedPangulo.purok || ''}-${this.selectedPangulo.grupo || ''}` : '';
                
                // Build learning context for AI
                let learningContext = '';
                if (this.learningStats && this.learningStats.total_families > 0) {
                    learningContext = `
SYSTEM LEARNING DATA (from ${this.learningStats.total_families} families, accuracy: ${this.learningStats.overall_accuracy || 0}%):
- Members with "learned_reason" have been matched to patterns from successful registrations
- learned_confidence shows how confident the system is (0-100%)
- Trust suggestions with learned_confidence >= 80%
`;
                }
                
                const prompt = `You are the AI decision-maker for a Filipino household registry. Your job is to:
1. DECIDE which people are ACTUALLY family members (include: true/false)
2. DETERMINE their relationship (relasyon) to the Pangulo
3. FILTER OUT false positives (people with same surname but NOT family)

⚠️ CRITICAL RULES - DO NOT ASSUME ⚠️
1. SAME LAST NAME ≠ FAMILY MEMBER
   - Many unrelated people share surnames (common in Filipino communities)
   - Must have ADDITIONAL EVIDENCE beyond just lastname match

2. MIDDLE NAME MATCH ≠ ANAK (CHILD)
   - Middle name matching mother's maiden name is just a HINT, not proof
   - Could be: Anak, Apo (grandchild), Pamangkin (niece/nephew), or even unrelated
   - The mother could have multiple siblings with children sharing her maiden name

FILIPINO NAMING CONVENTIONS (use as HINTS, not proof):
- Children OFTEN have mother's maiden surname as middle name
- Children OFTEN inherit father's surname as last name
- BUT these are not guarantees - verify with additional evidence
${learningContext}
HEAD OF HOUSEHOLD:
- Pangulo ng Sambahayan: ${panguloName}
- Pangulo's Last Name: ${panguloLastName}
- Pangulo's Purok-Grupo: ${this.selectedPangulo?.purok || 'unknown'}-${this.selectedPangulo?.grupo || 'unknown'}
${this.selectedAsawa ? `- Asawa/Ina ng Sambahayan: ${this.selectedAsawa.full_name}
- Asawa's Last Name (Maiden Name): ${asawaLastName}` : '(No Asawa specified - be VERY strict, hard to verify children without mother info)'}

CANDIDATES TO EVALUATE (note their purok-grupo compared to Pangulo's):
${JSON.stringify(membersInfo, null, 2)}

EVIDENCE WEIGHT SYSTEM:
🟢 STRONG EVIDENCE (high confidence):
- match_type is "spouse" or "selected_asawa" → Asawa
- match_type is "father_match" or "mother_match" (HDB/PNK parent match) → likely Anak
- learned_confidence >= 85%
- SAME purok AND grupo as Pangulo (live together)

🟡 MODERATE EVIDENCE (needs more verification):
- middle_name matches Asawa's lastname (could be Anak, Apo, Pamangkin)
- Same purok OR grupo (nearby, but not same household)
- learned_confidence 60-85%

🔴 WEAK EVIDENCE (likely exclude):
- ONLY lastname matches (NOT family)
- Different purok/grupo AND only lastname match
- Buklod with only lastname match (pangulo's sibling)

DECISION MATRIX:
✅ INCLUDE if: Strong evidence OR (Moderate evidence + same location)
❌ EXCLUDE if: Only weak evidence OR different location with no strong evidence

RELATIONSHIP DETERMINATION (don't assume!):
- Spouse from Buklod registry → Asawa
- HDB/PNK with parent match → Anak (verified)
- Middle name = mother's lastname + SAME location → likely Anak (verify age)
- Middle name = mother's lastname + DIFFERENT location → could be Apo, Pamangkin (verify)
- Same lastname only → DO NOT ASSUME any relationship

RESPOND with JSON array:
[{
  "name": "Full Name",
  "include": true/false,
  "suggested_relasyon": "Anak/Asawa/Apo/Kapatid/etc",
  "reason": "brief reason for decision",
  "confidence": "high/medium/low"
}]

Be STRICT. When in doubt, EXCLUDE. False positives hurt accuracy more than missing someone.`;

                const response = await puter.ai.chat(prompt, { model: 'gpt-4o-mini' });
                const aiText = typeof response === 'string' ? response : response.message?.content || response.toString();
                
                // Parse AI response
                const jsonMatch = aiText.match(/\[.*\]/s);
                if (jsonMatch) {
                    const aiDecisions = JSON.parse(jsonMatch[0]);
                    
                    // Filter and enhance suggestions based on AI decisions
                    this.suggestedMembers = this.suggestedMembers
                        .map(member => {
                            const aiDecision = aiDecisions.find(ai => 
                                ai.name.toLowerCase() === member.full_name.toLowerCase() ||
                                member.full_name.toLowerCase().includes(ai.name.toLowerCase()) ||
                                ai.name.toLowerCase().includes(member.full_name.toLowerCase())
                            );
                            
                            if (aiDecision) {
                                return {
                                    ...member,
                                    ai_include: aiDecision.include,
                                    suggested_relasyon: aiDecision.suggested_relasyon || member.suggested_relasyon,
                                    ai_reason: aiDecision.reason || null,
                                    ai_confidence: aiDecision.confidence || 'medium',
                                    ai_decided: true
                                };
                            }
                            // If AI didn't evaluate, keep but mark as uncertain
                            return { ...member, ai_include: null, ai_decided: false };
                        })
                        // Filter: keep only AI-approved members, or those AI didn't evaluate (with learned patterns)
                        .filter(member => {
                            if (member.ai_decided) {
                                return member.ai_include === true;
                            }
                            // Keep if has high learned confidence
                            return member.learned_confidence >= 80 || member.confidence === 'high';
                        })
                        // Sort by confidence
                        .sort((a, b) => {
                            const confOrder = { high: 1, medium: 2, low: 3 };
                            const aConf = confOrder[a.ai_confidence] || confOrder[a.confidence] || 2;
                            const bConf = confOrder[b.ai_confidence] || confOrder[b.confidence] || 2;
                            return aConf - bConf;
                        });
                }
            } catch (e) {
                console.error('Puter AI analysis error:', e);
                // Silently fail - suggestions still work without AI
            } finally {
                this.aiAnalyzing = false;
            }
        },
        
        addSuggestedMember(suggestion) {
            // Check if already added
            if (this.members.some(m => m.source === suggestion.source && m.source_id === suggestion.id)) {
                return;
            }
            
            const originalSuggestedRelasyon = suggestion.suggested_relasyon || '';
            
            this.newMember = {
                id: null,
                source: suggestion.source,
                source_id: suggestion.id,
                name: suggestion.full_name,
                relasyon: suggestion.suggested_relasyon || '',
                relasyon_specify: '',
                kapisanan: suggestion.kapisanan || suggestion.source,
                // Track original suggestion for learning
                _originalSuggestedRelasyon: originalSuggestedRelasyon,
                _fromSuggestion: true,
                _suggestionMatchType: suggestion.match_type
            };
            
            // If relasyon is pre-filled, auto-add to members
            if (this.newMember.relasyon) {
                const memberToAdd = {...this.newMember};
                this.members.push(memberToAdd);
                
                // Track that this suggestion was accepted
                this.suggestionsAccepted.push({
                    id: suggestion.id,
                    source: suggestion.source,
                    full_name: suggestion.full_name,
                    suggested_relasyon: originalSuggestedRelasyon,
                    accepted_relasyon: this.newMember.relasyon,
                    match_type: suggestion.match_type
                });
                
                this.clearNewMember();
                
                // Remove from suggestions
                this.suggestedMembers = this.suggestedMembers.filter(s => s.id !== suggestion.id || s.source !== suggestion.source);
            }
        },
        
        isSuggestionAdded(suggestion) {
            return this.members.some(m => m.source === suggestion.source && m.source_id === suggestion.id);
        },

        clearPangulo() {
            this.selectedPangulo = null;
            this.familyCode = '';
            this.asawaName = '';
            this.selectedAsawa = null;
            this.asawaSearch = '';
            this.asawaResults = [];
            this.suggestedMembers = [];
            this.members = [];
            this.aiEnabled = false;
        },

        async searchMember() {
            if (this.memberSearch.length < 2) {
                this.memberResults = [];
                return;
            }
            
            this.searchingMember = true;
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/search-members.php?q=' + encodeURIComponent(this.memberSearch) + '&source=all');
                const data = await response.json();
                if (data.success) {
                    // Filter out already added members and the pangulo
                    this.memberResults = data.results.filter(r => {
                        if (r.source === 'Tarheta' && r.id === this.selectedPangulo?.id) return false;
                        return !this.members.some(m => m.source === r.source && m.source_id === r.id);
                    });
                    this.showMemberResults = true;
                }
            } catch (e) {
                console.error('Error:', e);
            } finally {
                this.searchingMember = false;
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
            this.newMember = {
                id: null,
                source: null,
                source_id: null,
                name: '',
                relasyon: '',
                relasyon_specify: '',
                kapisanan: ''
            };
        },

        addMember() {
            if (!this.newMember.name || !this.newMember.relasyon) return;
            if (this.newMember.relasyon === 'Iba pa' && !this.newMember.relasyon_specify) return;
            
            const memberToAdd = {...this.newMember};
            this.members.push(memberToAdd);
            
            // Track if this was from a suggestion
            if (this.newMember._fromSuggestion) {
                this.suggestionsAccepted.push({
                    id: this.newMember.source_id,
                    source: this.newMember.source,
                    full_name: this.newMember.name,
                    suggested_relasyon: this.newMember._originalSuggestedRelasyon || '',
                    accepted_relasyon: this.newMember.relasyon,
                    match_type: this.newMember._suggestionMatchType
                });
                
                // Track modification if relasyon was changed
                if (this.newMember._originalSuggestedRelasyon && 
                    this.newMember._originalSuggestedRelasyon !== this.newMember.relasyon) {
                    this.suggestionsModified.push({
                        id: this.newMember.source_id,
                        source: this.newMember.source,
                        suggested_relasyon: this.newMember._originalSuggestedRelasyon,
                        actual_relasyon: this.newMember.relasyon
                    });
                }
            }
            
            this.clearNewMember();
        },

        removeMember(index) {
            this.members.splice(index, 1);
        },

        async saveFamily() {
            if (!this.selectedPangulo) {
                this.errorMessage = 'Please select a Pangulo ng Sambahayan';
                return;
            }
            
            this.saving = true;
            this.errorMessage = '';
            
            // Build modification tracking: detect if user changed the suggested relasyon
            const modificationsTracked = [];
            for (const member of this.members) {
                if (member._fromSuggestion && member._originalSuggestedRelasyon) {
                    if (member.relasyon !== member._originalSuggestedRelasyon) {
                        modificationsTracked.push({
                            id: member.source_id,
                            source: member.source,
                            full_name: member.name,
                            suggested_relasyon: member._originalSuggestedRelasyon,
                            actual_relasyon: member.relasyon,
                            match_type: member._suggestionMatchType
                        });
                    }
                }
            }
            
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/family/save-family.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        family_code: this.familyCode,
                        pangulo_id: this.selectedPangulo.id,
                        pangulo_name: this.selectedPangulo.full_name,
                        purok: this.selectedPangulo.purok,
                        grupo: this.selectedPangulo.grupo,
                        notes: this.notes,
                        members: this.members.map(m => ({
                            source: m.source,
                            source_id: m.source_id,
                            name: m.name,
                            relasyon: m.relasyon,
                            relasyon_specify: m.relasyon_specify,
                            kapisanan: m.kapisanan
                        })),
                        // Include asawa for learning
                        asawa_id: this.selectedAsawa?.id || null,
                        asawa_name: this.selectedAsawa?.full_name || null,
                        
                        // Behavioral tracking data for 98% accuracy learning
                        suggestions_shown: this.suggestionsShown,
                        suggestions_accepted: this.suggestionsAccepted,
                        suggestions_modified: modificationsTracked
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = 'profile.php?id=' + data.family_id + '&created=1';
                } else {
                    this.errorMessage = data.error || 'Failed to save family';
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
