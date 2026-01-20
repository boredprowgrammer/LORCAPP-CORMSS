<?php
/**
 * UI Components Library
 * Reusable UI components for consistent design
 */

// Page Header Component
function renderPageHeader($title, $subtitle = '', $actionButton = null) {
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($title); ?></h1>
                <?php if ($subtitle): ?>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?php echo Security::escape($subtitle); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($actionButton): ?>
                <?php echo $actionButton; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Card Component
function renderCard($content, $title = '', $icon = '', $padding = 'p-5') {
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 <?php echo $padding; ?>">
        <?php if ($title): ?>
            <div class="flex items-center space-x-2 mb-4">
                <?php if ($icon): ?>
                    <?php echo $icon; ?>
                <?php endif; ?>
                <h3 class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($title); ?></h3>
            </div>
        <?php endif; ?>
        <?php echo $content; ?>
    </div>
    <?php
}

// Input Field Component
function renderInput($name, $label, $value = '', $type = 'text', $required = false, $placeholder = '', $attributes = '') {
    $requiredAttr = $required ? 'required' : '';
    $placeholderAttr = $placeholder ? 'placeholder="' . Security::escape($placeholder) . '"' : '';
    ?>
    <div>
        <label for="<?php echo $name; ?>" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            <?php echo Security::escape($label); ?>
            <?php if ($required): ?>
                <span class="text-red-500 dark:text-red-400">*</span>
            <?php endif; ?>
        </label>
        <input 
            type="<?php echo $type; ?>"
            id="<?php echo $name; ?>"
            name="<?php echo $name; ?>"
            value="<?php echo Security::escape($value); ?>"
            class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
            <?php echo $requiredAttr; ?>
            <?php echo $placeholderAttr; ?>
            <?php echo $attributes; ?>
        >
    </div>
    <?php
}

// Select Field Component
function renderSelect($name, $label, $options, $selected = '', $required = false, $attributes = '') {
    $requiredAttr = $required ? 'required' : '';
    ?>
    <div>
        <label for="<?php echo $name; ?>" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            <?php echo Security::escape($label); ?>
            <?php if ($required): ?>
                <span class="text-red-500 dark:text-red-400">*</span>
            <?php endif; ?>
        </label>
        <select 
            id="<?php echo $name; ?>"
            name="<?php echo $name; ?>"
            class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
            <?php echo $requiredAttr; ?>
            <?php echo $attributes; ?>
        >
            <?php foreach ($options as $value => $label): ?>
                <option value="<?php echo Security::escape($value); ?>" <?php echo $selected == $value ? 'selected' : ''; ?>>
                    <?php echo Security::escape($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
}

// Textarea Component
function renderTextarea($name, $label, $value = '', $required = false, $rows = 3, $attributes = '') {
    $requiredAttr = $required ? 'required' : '';
    ?>
    <div>
        <label for="<?php echo $name; ?>" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            <?php echo Security::escape($label); ?>
            <?php if ($required): ?>
                <span class="text-red-500 dark:text-red-400">*</span>
            <?php endif; ?>
        </label>
        <textarea 
            id="<?php echo $name; ?>"
            name="<?php echo $name; ?>"
            rows="<?php echo $rows; ?>"
            class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
            <?php echo $requiredAttr; ?>
            <?php echo $attributes; ?>
        ><?php echo Security::escape($value); ?></textarea>
    </div>
    <?php
}

// Button Component
function renderButton($text, $type = 'button', $style = 'primary', $icon = '', $attributes = '') {
    $styles = [
        'primary' => 'bg-blue-500 text-white hover:bg-blue-600',
        'secondary' => 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600',
        'success' => 'bg-green-500 text-white hover:bg-green-600',
        'danger' => 'bg-red-500 text-white hover:bg-red-600',
        'warning' => 'bg-yellow-500 text-white hover:bg-yellow-600',
    ];
    $styleClass = $styles[$style] ?? $styles['primary'];
    ?>
    <button 
        type="<?php echo $type; ?>"
        class="inline-flex items-center justify-center px-4 py-2 rounded-lg shadow-sm text-sm font-medium transition-colors <?php echo $styleClass; ?>"
        <?php echo $attributes; ?>
    >
        <?php if ($icon): ?>
            <?php echo $icon; ?>
        <?php endif; ?>
        <?php echo Security::escape($text); ?>
    </button>
    <?php
}

// Badge Component
function renderBadge($text, $color = 'blue') {
    $colors = [
        'blue' => 'bg-blue-100 text-blue-800',
        'green' => 'bg-green-100 text-green-800',
        'red' => 'bg-red-100 text-red-800',
        'yellow' => 'bg-yellow-100 text-yellow-800',
        'gray' => 'bg-gray-100 text-gray-800',
        'purple' => 'bg-purple-100 text-purple-800',
        'cyan' => 'bg-cyan-100 text-cyan-800',
    ];
    $colorClass = $colors[$color] ?? $colors['blue'];
    ?>
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $colorClass; ?>">
        <?php echo Security::escape($text); ?>
    </span>
    <?php
}

// Alert Component
function renderAlert($message, $type = 'info') {
    $types = [
        'success' => [
            'bg' => 'bg-green-50',
            'border' => 'border-green-200',
            'text' => 'text-green-800',
            'icon' => '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>'
        ],
        'error' => [
            'bg' => 'bg-red-50',
            'border' => 'border-red-200',
            'text' => 'text-red-800',
            'icon' => '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>'
        ],
        'warning' => [
            'bg' => 'bg-yellow-50',
            'border' => 'border-yellow-200',
            'text' => 'text-yellow-800',
            'icon' => '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>'
        ],
        'info' => [
            'bg' => 'bg-blue-50',
            'border' => 'border-blue-200',
            'text' => 'text-blue-800',
            'icon' => '<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>'
        ],
    ];
    $style = $types[$type] ?? $types['info'];
    ?>
    <div class="rounded-lg p-4 <?php echo $style['bg']; ?> border <?php echo $style['border']; ?>">
        <div class="flex items-center">
            <svg class="w-5 h-5 mr-3 flex-shrink-0 <?php echo $style['text']; ?>" fill="currentColor" viewBox="0 0 20 20">
                <?php echo $style['icon']; ?>
            </svg>
            <span class="font-medium <?php echo $style['text']; ?>"><?php echo Security::escape($message); ?></span>
        </div>
    </div>
    <?php
}

// Empty State Component
function renderEmptyState($message, $icon = '', $action = '') {
    ?>
    <div class="text-center py-12">
        <?php if ($icon): ?>
            <?php echo $icon; ?>
        <?php else: ?>
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
            </svg>
        <?php endif; ?>
        <p class="text-sm text-gray-500 mb-4"><?php echo Security::escape($message); ?></p>
        <?php if ($action): ?>
            <?php echo $action; ?>
        <?php endif; ?>
    </div>
    <?php
}

// Stat Card Component
function renderStatCard($title, $value, $icon, $color = 'blue', $trend = '') {
    $colors = [
        'blue' => ['bg' => 'bg-blue-100 dark:bg-blue-900', 'text' => 'text-blue-600 dark:text-blue-400'],
        'green' => ['bg' => 'bg-green-100 dark:bg-green-900', 'text' => 'text-green-600 dark:text-green-400'],
        'red' => ['bg' => 'bg-red-100 dark:bg-red-900', 'text' => 'text-red-600 dark:text-red-400'],
        'yellow' => ['bg' => 'bg-yellow-100 dark:bg-yellow-900', 'text' => 'text-yellow-600 dark:text-yellow-400'],
        'purple' => ['bg' => 'bg-purple-100 dark:bg-purple-900', 'text' => 'text-purple-600 dark:text-purple-400'],
        'cyan' => ['bg' => 'bg-cyan-100 dark:bg-cyan-900', 'text' => 'text-cyan-600 dark:text-cyan-400'],
    ];
    $style = $colors[$color] ?? $colors['blue'];
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md transition-shadow">
        <div class="flex items-center space-x-3">
            <div class="w-12 h-12 rounded-lg <?php echo $style['bg']; ?> flex items-center justify-center flex-shrink-0">
                <?php echo $icon; ?>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?php echo $value; ?></p>
                <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo Security::escape($title); ?></p>
                <?php if ($trend): ?>
                    <p class="text-xs <?php echo $trend > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?> mt-1">
                        <?php echo $trend > 0 ? '↑' : '↓'; ?> <?php echo abs($trend); ?>%
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// Loading Spinner Component
function renderLoadingSpinner($size = 'md') {
    $sizes = [
        'sm' => 'w-4 h-4',
        'md' => 'w-8 h-8',
        'lg' => 'w-12 h-12',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
    ?>
    <div class="flex items-center justify-center">
        <svg class="animate-spin <?php echo $sizeClass; ?> text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
    </div>
    <?php
}

// Officer Details Modal Component
function renderOfficerDetailsModal() {
    ?>
    <!-- Officer Details Modal -->
    <div id="officerDetailsModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="OfficerDetailsModal.close()"></div>

            <!-- Modal panel -->
            <div class="relative inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full">
                <!-- Modal Header -->
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100" id="modalOfficerName">Officer Details</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400" id="modalOfficerUuid"></p>
                        </div>
                    </div>
                    <button type="button" onclick="OfficerDetailsModal.close()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="px-6 py-4 max-h-[70vh] overflow-y-auto">
                    <!-- Loading State -->
                    <div id="modalLoadingState" class="py-12 text-center">
                        <?php renderLoadingSpinner('lg'); ?>
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Loading officer details...</p>
                    </div>

                    <!-- Error State -->
                    <div id="modalErrorState" class="hidden py-12 text-center">
                        <svg class="w-12 h-12 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Failed to load officer details.</p>
                        <button onclick="OfficerDetailsModal.close()" class="mt-4 text-blue-600 hover:text-blue-800 text-sm font-medium">Close</button>
                    </div>

                    <!-- Content -->
                    <div id="modalContentArea" class="hidden space-y-6">
                        <!-- Personal Information -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wide mb-3">Personal Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Last Name</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalLastName">-</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">First Name</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalFirstName">-</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Middle Initial</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalMiddleInitial">-</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
                                    <p class="font-semibold" id="modalStatus">-</p>
                                </div>
                            </div>
                        </div>

                        <!-- Location Information -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wide mb-3">Location</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">District</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalDistrict">-</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Local Congregation</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalLocal">-</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Purok</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalPurok">-</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Grupo</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalGrupo">-</p>
                                </div>
                            </div>
                        </div>

                        <!-- Registry Information -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wide mb-3">Registry Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Registry Number</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalRegistryNumber">-</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Control Number</p>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="modalControlNumber">-</p>
                                </div>
                            </div>
                        </div>

                        <!-- Departments -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wide mb-3">Departments</h4>
                            <div id="modalDepartments" class="space-y-2">
                                <p class="text-sm text-gray-500 dark:text-gray-400">No departments assigned</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <a id="modalViewFullPageLink" href="#" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                        View Full Page
                    </a>
                    <button type="button" onclick="OfficerDetailsModal.close()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
}
