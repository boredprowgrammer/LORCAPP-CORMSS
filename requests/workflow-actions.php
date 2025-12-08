<?php
/**
 * Workflow Actions Component
 * Displays appropriate action forms based on current request status
 */

// This file is included in view.php and has access to $request, $currentStep, etc.

switch ($currentStep) {
    case 'pending':
        // Approve for Seminar
        ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="approve_seminar">
            
            <div>
                <label class="block text-sm font-medium text-blue-900 mb-2">Seminar Date</label>
                <input type="date" name="seminar_date" class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                Approve for Seminar
            </button>
        </form>
        <?php
        break;
        
    case 'requested_to_seminar':
        // Mark as In Seminar
        ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="mark_in_seminar">
            
            <p class="text-sm text-blue-800 mb-4">Mark this applicant as currently attending the seminar/circular.</p>
            
            <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                Mark In Seminar
            </button>
        </form>
        <?php
        break;
        
    case 'in_seminar':
        // Complete Seminar
        ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="complete_seminar">
            
            <div>
                <label class="block text-sm font-medium text-blue-900 mb-2">Completion Date *</label>
                <input type="date" name="completion_date" required class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-blue-900 mb-2">Certificate Number</label>
                <input type="text" name="certificate_number" placeholder="e.g., CERT-2025-001" class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                Complete Seminar
            </button>
        </form>
        <?php
        break;
        
    case 'seminar_completed':
        // Approve for Oath
        ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="approve_oath">
            
            <p class="text-sm text-blue-800 mb-4">Approve this applicant to proceed to oath taking ceremony.</p>
            
            <button type="submit" class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors font-medium">
                Approve for Oath
            </button>
        </form>
        <?php
        break;
        
    case 'requested_to_oath':
        // Mark as Ready to Oath
        ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="mark_ready_oath">
            
            <p class="text-sm text-blue-800 mb-4">Mark this applicant as ready and prepared for the oath taking ceremony.</p>
            
            <button type="submit" class="w-full px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors font-medium">
                Mark Ready to Oath
            </button>
        </form>
        <?php
        break;
        
    case 'ready_to_oath':
        // Complete Oath - Final Step
        ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="complete_oath">
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-yellow-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                    <div class="text-sm text-yellow-800">
                        <p class="font-semibold">Final Step</p>
                        <p>This will create an official officer record in the system.</p>
                    </div>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-blue-900 mb-2">Actual Oath Date *</label>
                <input type="date" name="actual_oath_date" required max="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <p class="text-xs text-blue-700 mt-1">Date when the oath was actually taken</p>
            </div>
            
            <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                Complete Oath & Create Officer
            </button>
        </form>
        <?php
        break;
}
?>
