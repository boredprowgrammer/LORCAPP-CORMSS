<?php
/**
 * Officer Details Modal - Usage Example
 * 
 * This file demonstrates how to use the reusable officer details modal
 * in your pages.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ui-components.php';

Security::requireLogin();

$pageTitle = 'Officer Details Modal Example';
ob_start();
?>

<div class="max-w-6xl mx-auto p-6">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">Officer Details Modal</h1>
        <p class="text-gray-600 dark:text-gray-400">Reusable component for displaying officer information</p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">How to Use</h2>
        
        <div class="space-y-4">
            <div class="bg-blue-50 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
                <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">Step 1: Include Required Files</h3>
                <pre class="bg-white dark:bg-gray-800 rounded p-3 text-xs overflow-x-auto"><code>&lt;?php
require_once __DIR__ . '/includes/ui-components.php';
?&gt;</code></pre>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
                <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">Step 2: Include JavaScript in Layout</h3>
                <pre class="bg-white dark:bg-gray-800 rounded p-3 text-xs overflow-x-auto"><code>&lt;script src="&lt;?php echo BASE_URL; ?&gt;/assets/js/officer-details-modal.js"&gt;&lt;/script&gt;</code></pre>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
                <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">Step 3: Render Modal Component</h3>
                <pre class="bg-white dark:bg-gray-800 rounded p-3 text-xs overflow-x-auto"><code>&lt;?php
// Add this before closing your content
renderOfficerDetailsModal();
?&gt;</code></pre>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
                <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">Step 4: Trigger Modal from Your Code</h3>
                <pre class="bg-white dark:bg-gray-800 rounded p-3 text-xs overflow-x-auto"><code>&lt;!-- As a button --&gt;
&lt;button onclick="OfficerDetailsModal.open('officer-uuid-here')" 
        class="text-blue-600 hover:text-blue-800"&gt;
    View Details
&lt;/button&gt;

&lt;!-- As a link --&gt;
&lt;a href="#" 
   onclick="event.preventDefault(); OfficerDetailsModal.open('officer-uuid-here');"
   class="text-blue-600 hover:text-blue-800"&gt;
    View Officer
&lt;/a&gt;</code></pre>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Example Buttons</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Click a button below to test the modal (will fail with actual UUID needed)</p>
        
        <div class="flex flex-wrap gap-3">
            <button onclick="OfficerDetailsModal.open('example-uuid-12345')" 
                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                </svg>
                View Officer Details
            </button>

            <button onclick="OfficerDetailsModal.open('another-example-uuid')" 
                    class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Quick View
            </button>
        </div>
    </div>
</div>

<?php
// Render the modal component
renderOfficerDetailsModal();

$content = ob_get_clean();

// Add the JavaScript file to the page
$extraScripts = '<script src="' . BASE_URL . '/assets/js/officer-details-modal.js"></script>';

include __DIR__ . '/includes/layout.php';
?>
