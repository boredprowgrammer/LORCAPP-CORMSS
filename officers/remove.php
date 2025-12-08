<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $action = Security::sanitizeInput($_POST['action'] ?? '');
        
        // Delete removal request
        if ($action === 'delete_removal') {
            $removalId = Security::sanitizeInput($_POST['removal_id'] ?? '');
            
            if (empty($removalId)) {
                $error = 'Invalid removal ID.';
            } else {
                try {
                    // Get removal request details
                    $stmt = $db->prepare("
                        SELECT r.*, o.district_code, o.local_code, o.officer_uuid
                        FROM officer_removals r
                        JOIN officers o ON r.officer_id = o.officer_id
                        WHERE r.removal_id = ?
                    ");
                    $stmt->execute([$removalId]);
                    $removal = $stmt->fetch();
                    
                    if (!$removal) {
                        $error = 'Removal request not found.';
                    } elseif (!hasDistrictAccess($removal['district_code']) && !hasLocalAccess($removal['local_code'])) {
                        $error = 'You do not have access to delete this removal request.';
                    } else {
                        // Delete the removal request
                        $stmt = $db->prepare("DELETE FROM officer_removals WHERE removal_id = ?");
                        $stmt->execute([$removalId]);
                        
                        // Log audit
                        $stmt = $db->prepare("
                            INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, ip_address, user_agent)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currentUser['user_id'],
                            'delete_removal_request',
                            'officer_removals',
                            $removalId,
                            json_encode(['removal_id' => $removalId, 'status' => $removal['status']]),
                            $_SERVER['REMOTE_ADDR'],
                            $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);
                        
                        setFlashMessage('success', 'Removal request deleted successfully.');
                        redirect(BASE_URL . '/officers/removal-requests.php');
                    }
                } catch (Exception $e) {
                    error_log("Delete removal error: " . $e->getMessage());
                    $error = 'An error occurred while deleting the removal request.';
                }
            }
        }
        
        // Stage 1: Local deliberates and creates removal request (CODE D - Lipat Kapisanan doesn't require deliberation)
        elseif ($action === 'deliberate') {
            $officerUuid = Security::sanitizeInput($_POST['officer_uuid'] ?? '');
            $removalCode = Security::sanitizeInput($_POST['removal_code'] ?? '');
            $removalDate = Security::sanitizeInput($_POST['removal_date'] ?? date('Y-m-d'));
            $reason = Security::sanitizeInput($_POST['reason'] ?? '');
            
            if (empty($officerUuid)) {
                $error = 'Please select an officer.';
            } elseif (empty($removalCode) || !in_array($removalCode, ['A', 'B', 'C', 'D'])) {
                $error = 'Please select a valid removal code.';
            } elseif ($currentUser['role'] !== 'local') {
                $error = 'Only local administrators can process officer removals.';
            } else {
                try {
                    // Get officer details
                    $stmt = $db->prepare("SELECT * FROM officers WHERE officer_uuid = ? AND is_active = 1");
                    $stmt->execute([$officerUuid]);
                    $officer = $stmt->fetch();
                    
                    if (!$officer) {
                        $error = 'Officer not found or already inactive.';
                    } elseif (!hasLocalAccess($officer['local_code'])) {
                        $error = 'You do not have access to this officer.';
                    } else {
                        // Check if user is local_limited and needs approval
                        require_once __DIR__ . '/../includes/pending-actions.php';
                        
                        if (shouldPendAction()) {
                            // Create pending action instead of executing immediately
                            // Get officer name for description
                            $decrypted = Encryption::decryptOfficerName(
                                $officer['last_name_encrypted'],
                                $officer['first_name_encrypted'],
                                $officer['middle_initial_encrypted'],
                                $officer['district_code']
                            );
                            $officerName = $decrypted['last_name'] . ', ' . $decrypted['first_name'];
                            
                            $actionData = [
                                'officer_uuid' => $officerUuid,
                                'removal_code' => $removalCode,
                                'removal_date' => $removalDate,
                                'reason' => $reason,
                            ];
                            
                            $actionId = createPendingRemoveOfficer($officer['officer_id'], $actionData, $officerName);
                            
                            if ($actionId) {
                                $_SESSION['success'] = getPendingActionMessage('remove officer request');
                                header('Location: ' . BASE_URL . '/officers/removal-requests.php');
                                exit;
                            } else {
                                $error = 'Failed to submit action for approval. Please try again.';
                            }
                        } else {
                            // Execute action normally for non-limited users
                        $db->beginTransaction();
                        
                        $weekInfo = getWeekDateRange(null, date('Y', strtotime($removalDate)));
                        
                        // CODE D (Lipat Kapisanan) - Process immediately without deliberation workflow
                        if ($removalCode === 'D') {
                            // Record removal as completed immediately
                            $stmt = $db->prepare("
                                INSERT INTO officer_removals (
                                    officer_id, removal_code, removal_date, week_number, year, reason, 
                                    status, processed_by, completed_at
                                ) VALUES (?, ?, ?, ?, ?, ?, 'approved_by_district', ?, NOW())
                            ");
                            
                            $stmt->execute([
                                $officer['officer_id'],
                                $removalCode,
                                $removalDate,
                                $weekInfo['week'],
                                $weekInfo['year'],
                                $reason,
                                $currentUser['user_id']
                            ]);
                            
                            $removalId = $db->lastInsertId();
                            
                            // Deactivate officer
                            $stmt = $db->prepare("UPDATE officers SET is_active = 0 WHERE officer_id = ?");
                            $stmt->execute([$officer['officer_id']]);
                            
                            // Deactivate all departments
                            $stmt = $db->prepare("UPDATE officer_departments SET is_active = 0, removed_at = NOW() WHERE officer_id = ?");
                            $stmt->execute([$officer['officer_id']]);
                            
                            // Update headcount (-1)
                            $stmt = $db->prepare("
                                UPDATE headcount 
                                SET total_count = GREATEST(0, total_count - 1) 
                                WHERE district_code = ? AND local_code = ?
                            ");
                            $stmt->execute([$officer['district_code'], $officer['local_code']]);
                            
                            // Log audit
                            $stmt = $db->prepare("
                                INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $currentUser['user_id'],
                                'remove_officer_code_d',
                                'officer_removals',
                                $removalId,
                                json_encode(['removal_code' => 'D', 'reason' => 'Lipat Kapisanan (Transfer)']),
                                $_SERVER['REMOTE_ADDR'],
                                $_SERVER['HTTP_USER_AGENT'] ?? ''
                            ]);
                            
                            $db->commit();
                            
                            setFlashMessage('success', "Officer transferred (CODE D - Lipat Kapisanan). Officer deactivated and headcount updated (-1).");
                            redirect(BASE_URL . '/officers/view.php?id=' . $officerUuid);
                            
                        } else {
                            // CODE A, B, C - Require deliberation workflow
                            // Create removal request at "deliberated_by_local" stage
                            $stmt = $db->prepare("
                                INSERT INTO officer_removals (
                                    officer_id, removal_code, removal_date, week_number, year, reason, 
                                    status, deliberated_by, deliberated_at, processed_by
                                ) VALUES (?, ?, ?, ?, ?, ?, 'deliberated_by_local', ?, NOW(), ?)
                            ");
                            
                            $stmt->execute([
                                $officer['officer_id'],
                                $removalCode,
                                $removalDate,
                                $weekInfo['week'],
                                $weekInfo['year'],
                                $reason,
                                $currentUser['user_id'],
                                $currentUser['user_id']
                            ]);
                            
                            $removalId = $db->lastInsertId();
                            
                                // Log audit
                            $stmt = $db->prepare("
                                INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $currentUser['user_id'],
                                'deliberate_removal',
                                'officer_removals',
                                $removalId,
                                json_encode(['removal_code' => $removalCode, 'status' => 'deliberated_by_local']),
                                $_SERVER['REMOTE_ADDR'],
                                $_SERVER['HTTP_USER_AGENT'] ?? ''
                            ]);
                            
                            $db->commit();
                            
                            setFlashMessage('success', "Removal deliberated by local. Waiting to be requested to district for approval.");
                            redirect(BASE_URL . '/officers/removal-requests.php');
                        }
                        } // End of non-limited user execution
                    }
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Deliberate removal error: " . $e->getMessage());
                    $error = 'An error occurred during processing.';
                }
            }
        }
        
        // Stage 2: Local/Admin requests to district
        elseif ($action === 'request_to_district') {
            $removalId = Security::sanitizeInput($_POST['removal_id'] ?? '');
            $notes = Security::sanitizeInput($_POST['notes'] ?? '');
            
            if (empty($removalId)) {
                $error = 'Invalid removal ID.';
            } else {
                try {
                    $stmt = $db->prepare("
                        SELECT r.*, o.district_code, o.local_code 
                        FROM officer_removals r
                        JOIN officers o ON r.officer_id = o.officer_id
                        WHERE r.removal_id = ? AND r.status = 'deliberated_by_local'
                    ");
                    $stmt->execute([$removalId]);
                    $removal = $stmt->fetch();
                    
                    if (!$removal) {
                        $error = 'Removal request not found or already processed.';
                    } elseif (!hasLocalAccess($removal['local_code']) && $currentUser['role'] !== 'admin') {
                        $error = 'You do not have access to this removal request.';
                    } else {
                        $stmt = $db->prepare("
                            UPDATE officer_removals 
                            SET status = 'requested', 
                                requested_by = ?, 
                                requested_at = NOW(),
                                notes = ?
                            WHERE removal_id = ?
                        ");
                        $stmt->execute([$currentUser['user_id'], $notes, $removalId]);
                        
                        setFlashMessage('success', 'Removal requested to district for approval.');
                        redirect(BASE_URL . '/officers/removal-requests.php');
                    }
                } catch (Exception $e) {
                    error_log("Request to district error: " . $e->getMessage());
                    $error = 'An error occurred while requesting to district.';
                }
            }
        }
        
        // Stage 3: District approves
        elseif ($action === 'approve_removal') {
            $removalId = Security::sanitizeInput($_POST['removal_id'] ?? '');
            $notes = Security::sanitizeInput($_POST['notes'] ?? '');
            
            if (empty($removalId)) {
                $error = 'Invalid removal ID.';
            } elseif (!in_array($currentUser['role'], ['admin', 'district', 'local'])) {
                $error = 'You do not have permission to approve removals.';
            } else {
                try {
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("
                        SELECT r.*, o.district_code, o.local_code, o.officer_id, o.officer_uuid
                        FROM officer_removals r
                        JOIN officers o ON r.officer_id = o.officer_id
                        WHERE r.removal_id = ? AND r.status = 'requested'
                    ");
                    $stmt->execute([$removalId]);
                    $removal = $stmt->fetch();
                    
                    if (!$removal) {
                        $error = 'Removal request not found or already processed.';
                    } elseif (!hasDistrictAccess($removal['district_code']) && !hasLocalAccess($removal['local_code'])) {
                        $error = 'You do not have access to this removal request.';
                    } else {
                        // Check if this is a department-specific removal or full officer removal
                        $isDepartmentRemoval = !empty($removal['department_id']);
                        
                        // Update removal status to approved and completed
                        $stmt = $db->prepare("
                            UPDATE officer_removals 
                            SET status = 'approved_by_district',
                                approved_by = ?,
                                approved_at = NOW(),
                                completed_at = NOW(),
                                notes = CONCAT(COALESCE(notes, ''), '\n\nDistrict approval: ', ?)
                            WHERE removal_id = ?
                        ");
                        $stmt->execute([$currentUser['user_id'], $notes, $removalId]);
                        
                        if ($isDepartmentRemoval) {
                            // Department-specific removal: Only deactivate the specific department
                            $stmt = $db->prepare("UPDATE officer_departments SET is_active = 0, removed_at = NOW() WHERE id = ?");
                            $stmt->execute([$removal['department_id']]);
                            
                            // Check if officer has any remaining active departments
                            $stmt = $db->prepare("SELECT COUNT(*) as active_count FROM officer_departments WHERE officer_id = ? AND is_active = 1");
                            $stmt->execute([$removal['officer_id']]);
                            $activeCount = $stmt->fetch()['active_count'];
                            
                            // If no active departments remain, deactivate the officer
                            if ($activeCount == 0) {
                                $stmt = $db->prepare("UPDATE officers SET is_active = 0 WHERE officer_id = ?");
                                $stmt->execute([$removal['officer_id']]);
                                
                                // Update headcount (-1) only when officer is fully deactivated
                                $stmt = $db->prepare("
                                    UPDATE headcount 
                                    SET total_count = GREATEST(0, total_count - 1) 
                                    WHERE district_code = ? AND local_code = ?
                                ");
                                $stmt->execute([$removal['district_code'], $removal['local_code']]);
                            }
                            
                            $successMessage = "Department removal approved! Department deactivated." . 
                                            ($activeCount == 0 ? " Officer has no remaining active departments and was deactivated." : "");
                        } else {
                            // Full officer removal: Deactivate officer and all departments
                            $stmt = $db->prepare("UPDATE officers SET is_active = 0 WHERE officer_id = ?");
                            $stmt->execute([$removal['officer_id']]);
                            
                            // Deactivate all departments
                            $stmt = $db->prepare("UPDATE officer_departments SET is_active = 0, removed_at = NOW() WHERE officer_id = ?");
                            $stmt->execute([$removal['officer_id']]);
                            
                            // Update headcount (-1)
                            $stmt = $db->prepare("
                                UPDATE headcount 
                                SET total_count = GREATEST(0, total_count - 1) 
                                WHERE district_code = ? AND local_code = ?
                            ");
                            $stmt->execute([$removal['district_code'], $removal['local_code']]);
                            
                            $successMessage = "Officer removal approved! Officer deactivated and headcount updated (-1).";
                        }
                        
                        // Log audit
                        $stmt = $db->prepare("
                            INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currentUser['user_id'],
                            $isDepartmentRemoval ? 'approve_department_removal' : 'approve_officer_removal',
                            'officer_removals',
                            $removalId,
                            json_encode(['status' => 'approved_by_district', 'department_removal' => $isDepartmentRemoval]),
                            $_SERVER['REMOTE_ADDR'],
                            $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);
                        
                        $db->commit();
                        
                        setFlashMessage('success', $successMessage);
                        redirect(BASE_URL . '/officers/view.php?id=' . $removal['officer_uuid']);
                    }
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Approve removal error: " . $e->getMessage());
                    $error = 'An error occurred during approval.';
                }
            }
        }
    }
}

$removalCodes = getRemovalCodes();

$pageTitle = 'Remove Officer';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-semibold text-gray-900">Remove Officer</h2>
        </div>
        
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-red-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-red-800">Three-Stage Removal Process</p>
                    <p class="text-sm text-red-700 mt-1">
                        <strong>Stage 1:</strong> Local deliberates and creates removal request<br>
                        <strong>Stage 2:</strong> Request sent to district for review<br>
                        <strong>Stage 3:</strong> District approves and officer is deactivated
                    </p>
                    <p class="text-sm text-red-700 mt-2">This ensures proper oversight and accountability in the removal process.</p>
                </div>
            </div>
        </div>
        
        <?php if ($currentUser['role'] !== 'local'): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm font-medium text-yellow-800">Only local administrators can initiate officer removals. <a href="removal-requests.php" class="underline">View removal requests</a></span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm font-medium text-red-800"><?php echo Security::escape($error); ?></span>
                </div>
            </div>
        <?php endif; ?>
            
            <?php if ($currentUser['role'] === 'local'): ?>
            <form method="POST" action="" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="deliberate">
                <input type="hidden" name="officer_uuid" id="officer_uuid">
                
                <!-- Selected Officer Display -->
                <div id="selectedOfficerDisplay" class="bg-blue-50 border border-blue-200 rounded-lg p-4 hidden">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-blue-900" id="selectedOfficerName"></p>
                                <p class="text-xs text-blue-700" id="selectedOfficerLocation"></p>
                            </div>
                        </div>
                        <button type="button" onclick="clearOfficerSelection()" class="text-blue-600 hover:text-blue-800">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <!-- Select Officer -->
                <div class="border-b border-gray-200 pb-2 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Select Officer</h3>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Search Officer <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="officer-search"
                        placeholder="Type officer name to search..." 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                        autocomplete="off"
                        @input="officerSelected = false"
                    >
                    <div id="search-results" class="mt-2"></div>
                </div>
                
                <div x-show="officerSelected" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="text-sm font-medium text-blue-800">Officer selected. Continue with removal details below.</span>
                    </div>
                </div>
                
                <!-- Removal Code -->
                <div class="border-b border-gray-200 pb-2 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Removal Details</h3>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">
                        Removal Code <span class="text-red-600">*</span>
                    </label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($removalCodes as $code => $description): ?>
                            <label class="relative flex items-start p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-red-300 hover:bg-red-50 transition-colors">
                                <input 
                                    type="radio" 
                                    name="removal_code" 
                                    value="<?php echo $code; ?>" 
                                    class="h-4 w-4 text-red-600 focus:ring-2 focus:ring-red-500 border-gray-300 mt-1"
                                    required
                                >
                                <div class="ml-3">
                                    <span class="block font-semibold text-gray-900">CODE <?php echo $code; ?></span>
                                    <p class="text-sm text-gray-600 mt-0.5"><?php echo Security::escape($description); ?></p>
                                    <?php if ($code === 'D'): ?>
                                    <p class="text-xs text-blue-600 font-medium mt-1">✓ No deliberation required - Immediate processing</p>
                                    <?php else: ?>
                                    <p class="text-xs text-orange-600 font-medium mt-1">⚠ Requires 3-stage approval workflow</p>
                                    <?php endif; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <p class="text-xs text-blue-800">
                            <strong>Note:</strong> CODE D (Lipat Kapisanan/Transfer) will be processed immediately. 
                            Other codes (A, B, C) require approval from the district level before completion.
                        </p>
                    </div>
                </div>
                
                <!-- Selected Code Display -->
                <div x-show="selectedCode" 
                     :class="{
                         'bg-red-50 border-red-200': selectedCode === 'A',
                         'bg-yellow-50 border-yellow-200': selectedCode === 'B' || selectedCode === 'D',
                         'bg-blue-50 border-blue-200': selectedCode === 'C'
                     }"
                     class="border rounded-lg p-4">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-3 flex-shrink-0" 
                             :class="{
                                 'text-red-600': selectedCode === 'A',
                                 'text-yellow-600': selectedCode === 'B' || selectedCode === 'D',
                                 'text-blue-600': selectedCode === 'C'
                             }"
                             fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="text-sm font-medium"
                              :class="{
                                  'text-red-800': selectedCode === 'A',
                                  'text-yellow-800': selectedCode === 'B' || selectedCode === 'D',
                                  'text-blue-800': selectedCode === 'C'
                              }">Selected: <strong x-text="'CODE ' + selectedCode"></strong></span>
                    </div>
                </div>
                
                <!-- Removal Date and Reason -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Removal Date <span class="text-red-600">*</span>
                        </label>
                        <input 
                            type="date" 
                            name="removal_date" 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                            value="<?php echo date('Y-m-d'); ?>"
                            max="<?php echo date('Y-m-d'); ?>"
                            required
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Week Info
                        </label>
                        <div class="w-full px-4 py-2 border border-gray-200 rounded-lg bg-gray-50 flex items-center text-gray-700">
                            <svg class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            Week <?php echo getCurrentWeekNumber(); ?>, <?php echo date('Y'); ?>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Reason / Additional Information
                    </label>
                    <textarea 
                        name="reason" 
                        placeholder="Provide additional details about this removal (optional but recommended)..." 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none h-24"
                    ></textarea>
                </div>
                
                <!-- Warning -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-yellow-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                        <div>
                            <p class="font-semibold text-yellow-800">Important</p>
                            <p class="text-sm text-yellow-700 mt-1">This action will deactivate the officer and reduce headcount. Make sure you have selected the correct officer and removal code.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <div class="flex items-center justify-end space-x-3 pt-4">
                    <a href="<?php echo BASE_URL; ?>/officers/list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Cancel
                    </a>
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Process Removal
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-8 text-center">
                <svg class="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
                <p class="text-gray-600 mb-4">Only local administrators can initiate officer removals.</p>
                <a href="removal-requests.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    View Removal Requests
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Officer search with autocomplete
let searchTimeout;
const searchInput = document.getElementById('officer-search');
const searchResults = document.getElementById('search-results');

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        searchResults.innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch('<?php echo BASE_URL; ?>/api/search-officers.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                if (data.length === 0) {
                    searchResults.innerHTML = '<p class="text-sm text-center py-4 opacity-70">No active officers found</p>';
                    return;
                }
                
                let html = '<div class="border border-base-300 rounded-lg divide-y divide-base-300 max-h-60 overflow-y-auto">';
                data.forEach(officer => {
                    html += `
                        <div class="p-3 hover:bg-base-300 cursor-pointer" onclick="selectOfficer('${officer.id}', '${officer.name}', '${officer.location}')">
                            <p class="font-semibold">${officer.name}</p>
                            <p class="text-xs opacity-70">${officer.location}</p>
                        </div>
                    `;
                });
                html += '</div>';
                
                searchResults.innerHTML = html;
            })
            .catch(error => {
                console.error('Search error:', error);
                searchResults.innerHTML = '<p class="text-sm text-error">Error searching officers</p>';
            });
    }, 300);
});

function selectOfficer(uuid, name, location) {
    document.getElementById('officer_uuid').value = uuid;
    document.getElementById('selectedOfficerName').textContent = name;
    document.getElementById('selectedOfficerLocation').textContent = location;
    document.getElementById('selectedOfficerDisplay').classList.remove('hidden');
    
    searchInput.value = '';
    searchResults.innerHTML = '';
}

function clearOfficerSelection() {
    document.getElementById('officer_uuid').value = '';
    document.getElementById('selectedOfficerDisplay').classList.add('hidden');
    searchInput.value = '';
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
