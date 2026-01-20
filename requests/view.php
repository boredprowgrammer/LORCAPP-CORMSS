<?php
/**
 * View and Manage Officer Request - Modern Standalone UI
 * View details and progress request through workflow
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

$requestId = $_GET['id'] ?? 0;
$success = '';
$error = '';

// Get request details
$stmt = $db->prepare("
    SELECT r.*, 
           d.district_name, d.district_code,
           l.local_name, l.local_code,
           u1.full_name as requested_by_name,
           u2.full_name as reviewed_by_name,
           u3.full_name as seminar_approved_by_name,
           u4.full_name as oath_approved_by_name,
           u5.full_name as completed_by_name,
           o.officer_uuid,
           existing_o.last_name_encrypted as existing_last_name,
           existing_o.first_name_encrypted as existing_first_name,
           existing_o.middle_initial_encrypted as existing_middle_initial,
           existing_o.district_code as existing_district_code
    FROM officer_requests r
    LEFT JOIN districts d ON r.district_code = d.district_code
    LEFT JOIN local_congregations l ON r.local_code = l.local_code
    LEFT JOIN users u1 ON r.requested_by = u1.user_id
    LEFT JOIN users u2 ON r.reviewed_by = u2.user_id
    LEFT JOIN users u3 ON r.approved_seminar_by = u3.user_id
    LEFT JOIN users u4 ON r.approved_oath_by = u4.user_id
    LEFT JOIN users u5 ON r.completed_by = u5.user_id
    LEFT JOIN officers o ON r.officer_id = o.officer_id
    LEFT JOIN officers existing_o ON r.existing_officer_uuid = existing_o.officer_uuid
    WHERE r.request_id = ?
");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    header('Location: list.php');
    exit;
}

// Check permissions
$canManage = $user['role'] === 'admin' || 
             ($user['role'] === 'district' && $user['district_code'] === $request['district_code']) ||
             ($user['role'] === 'local' && $user['local_code'] === $request['local_code']);

// Decrypt personal information
try {
    if ($request['record_code'] === 'D' && $request['existing_officer_uuid']) {
        $districtCodeForDecryption = $request['existing_district_code'] ?? $request['district_code'];
        if (empty($request['existing_last_name']) || empty($request['existing_first_name'])) {
            throw new Exception("Missing encrypted name data for existing officer");
        }
        $lastName = Encryption::decrypt($request['existing_last_name'], $districtCodeForDecryption);
        $firstName = Encryption::decrypt($request['existing_first_name'], $districtCodeForDecryption);
        $middleInitial = $request['existing_middle_initial'] ? Encryption::decrypt($request['existing_middle_initial'], $districtCodeForDecryption) : '';
    } else {
        $lastName = Encryption::decrypt($request['last_name_encrypted'], $request['district_code']);
        $firstName = Encryption::decrypt($request['first_name_encrypted'], $request['district_code']);
        $middleInitial = $request['middle_initial_encrypted'] ? Encryption::decrypt($request['middle_initial_encrypted'], $request['district_code']) : '';
    }
    $fullName = "$lastName, $firstName" . ($middleInitial ? " $middleInitial." : "");
} catch (Exception $e) {
    error_log("Request decryption error: " . $e->getMessage());
    $fullName = '[DECRYPT ERROR]';
    $lastName = $firstName = $middleInitial = '';
}

// Seminar tracking variables
$seminarDates = !empty($request['seminar_dates']) ? json_decode($request['seminar_dates'], true) : [];
$daysRequired = $request['seminar_days_required'] ?? 0;
$daysCompleted = 0;
foreach ($seminarDates as $seminar) {
    if (!empty($seminar['attended'])) $daysCompleted++;
}

// Handle AJAX attendance marking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    Security::validateCSRFToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    
    // Check if it's an AJAX request
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    try {
        switch ($action) {
            case 'mark_attendance':
                $index = (int)($_POST['seminar_index'] ?? -1);
                $attended = (bool)($_POST['attended'] ?? 0);
                
                if ($index >= 0 && isset($seminarDates[$index])) {
                    $seminarDates[$index]['attended'] = $attended;
                    
                    $daysCompleted = 0;
                    foreach ($seminarDates as $s) {
                        if (!empty($s['attended'])) $daysCompleted++;
                    }
                    
                    $stmt = $db->prepare("UPDATE officer_requests SET seminar_dates = ?, seminar_days_completed = ? WHERE request_id = ?");
                    $stmt->execute([json_encode($seminarDates), $daysCompleted, $requestId]);
                    
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'days_completed' => $daysCompleted, 'days_required' => $daysRequired]);
                        exit;
                    }
                    $success = "Attendance updated successfully.";
                }
                break;
                
            case 'set_code':
                $recordCode = $_POST['record_code'] ?? '';
                $existingOfficerUuid = $_POST['existing_officer_uuid'] ?? null;
                
                if ($recordCode === 'A') {
                    $stmt = $db->prepare("UPDATE officer_requests SET record_code = 'A' WHERE request_id = ?");
                    $stmt->execute([$requestId]);
                    $success = "Record code set to CODE A (New Officer).";
                } elseif ($recordCode === 'D' && $existingOfficerUuid) {
                    $stmt = $db->prepare("UPDATE officer_requests SET record_code = 'D', existing_officer_uuid = ? WHERE request_id = ?");
                    $stmt->execute([$existingOfficerUuid, $requestId]);
                    $success = "Record code set to CODE D (Linked to existing officer).";
                }
                
                // Refresh page data
                header("Location: view.php?id=$requestId&success=code_set");
                exit;
                break;
                
            case 'approve_seminar':
                $seminarDate = $_POST['seminar_date'] ?? null;
                $seminarLocation = $_POST['seminar_location'] ?? null;
                
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'requested_to_seminar',
                        seminar_date = ?,
                        seminar_location = ?,
                        approved_seminar_by = ?,
                        seminar_approved_at = NOW()
                    WHERE request_id = ?
                ");
                $stmt->execute([$seminarDate, $seminarLocation, $user['user_id'], $requestId]);
                $success = "Request approved for seminar!";
                break;
                
            case 'mark_in_seminar':
                $stmt = $db->prepare("UPDATE officer_requests SET status = 'in_seminar' WHERE request_id = ?");
                $stmt->execute([$requestId]);
                $success = "Marked as in seminar.";
                break;
                
            case 'complete_seminar':
                $completionDate = $_POST['completion_date'] ?? date('Y-m-d');
                $certificateNumber = $_POST['certificate_number'] ?? null;
                
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'seminar_completed',
                        seminar_completion_date = ?,
                        seminar_certificate_number = ?
                    WHERE request_id = ?
                ");
                $stmt->execute([$completionDate, $certificateNumber, $requestId]);
                $success = "Seminar marked as completed!";
                break;
                
            case 'approve_oath':
                $oathDate = $_POST['oath_date'] ?? null;
                $oathLocation = $_POST['oath_location'] ?? null;
                
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'requested_to_oath',
                        oath_scheduled_date = ?,
                        oath_location = ?,
                        approved_oath_by = ?,
                        oath_approved_at = NOW()
                    WHERE request_id = ?
                ");
                $stmt->execute([$oathDate, $oathLocation, $user['user_id'], $requestId]);
                $success = "Request approved for oath!";
                break;
                
            case 'mark_ready_oath':
                $stmt = $db->prepare("UPDATE officer_requests SET status = 'ready_to_oath' WHERE request_id = ?");
                $stmt->execute([$requestId]);
                $success = "Marked as ready for oath.";
                break;
                
            case 'complete_oath':
                $actualOathDate = $_POST['actual_oath_date'] ?? date('Y-m-d');
                
                if ($request['record_code'] === 'D' && $request['existing_officer_uuid']) {
                    // CODE D: Update existing officer
                    $stmt = $db->prepare("SELECT officer_id FROM officers WHERE officer_uuid = ?");
                    $stmt->execute([$request['existing_officer_uuid']]);
                    $existingOfficer = $stmt->fetch();
                    
                    if ($existingOfficer) {
                        $stmt = $db->prepare("
                            INSERT INTO officer_departments (officer_id, department, duty, oath_date, is_active)
                            VALUES (?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([
                            $existingOfficer['officer_id'],
                            $request['requested_department'],
                            $request['requested_duty'],
                            $actualOathDate
                        ]);
                        
                        $stmt = $db->prepare("
                            UPDATE officer_requests 
                            SET status = 'oath_taken', oath_actual_date = ?, officer_id = ?,
                                completed_by = ?, completed_at = NOW()
                            WHERE request_id = ?
                        ");
                        $stmt->execute([$actualOathDate, $existingOfficer['officer_id'], $user['user_id'], $requestId]);
                        
                        $success = "Oath completed! Existing officer updated (CODE D).";
                    }
                } else {
                    // CODE A: Create new officer
                    $officerUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    
                    $stmt = $db->prepare("
                        INSERT INTO officers (officer_uuid, last_name_encrypted, first_name_encrypted, middle_initial_encrypted,
                            district_code, local_code, record_code, is_imported, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, 'A', ?, 1, ?)
                    ");
                    $stmt->execute([
                        $officerUuid, $request['last_name_encrypted'], $request['first_name_encrypted'],
                        $request['middle_initial_encrypted'], $request['district_code'], $request['local_code'],
                        $request['is_imported'] ?? 0, $user['user_id']
                    ]);
                    
                    $officerId = $db->lastInsertId();
                    
                    $stmt = $db->prepare("
                        INSERT INTO officer_departments (officer_id, department, duty, oath_date, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$officerId, $request['requested_department'], $request['requested_duty'], $actualOathDate]);
                    
                    $stmt = $db->prepare("
                        UPDATE officer_requests 
                        SET status = 'oath_taken', oath_actual_date = ?, officer_id = ?,
                            completed_by = ?, completed_at = NOW()
                        WHERE request_id = ?
                    ");
                    $stmt->execute([$actualOathDate, $officerId, $user['user_id'], $requestId]);
                    
                    // Update headcount
                    $stmt = $db->prepare("
                        INSERT INTO headcount (district_code, local_code, total_count) VALUES (?, ?, 1)
                        ON DUPLICATE KEY UPDATE total_count = total_count + 1
                    ");
                    $stmt->execute([$request['district_code'], $request['local_code']]);
                    
                    $success = "Oath completed! Officer record created (CODE A).";
                }
                break;
                
            case 'reject':
                $reason = $_POST['rejection_reason'] ?? '';
                $stmt = $db->prepare("
                    UPDATE officer_requests SET status = 'rejected', status_reason = ?,
                        reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?
                ");
                $stmt->execute([$reason, $user['user_id'], $requestId]);
                $success = "Request rejected.";
                break;
                
            case 'delete':
                $stmt = $db->prepare("DELETE FROM officer_requests WHERE request_id = ?");
                $stmt->execute([$requestId]);
                header('Location: list.php?deleted=1');
                exit;
        }
        
        // Refresh request data after action
        $stmt = $db->prepare("SELECT * FROM officer_requests WHERE request_id = ?");
        $stmt->execute([$requestId]);
        $request = array_merge($request, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        
    } catch (Exception $e) {
        error_log("Error updating request: " . $e->getMessage());
        $error = "Failed to update request. Please try again.";
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] === 'code_set') {
    $success = "Record code has been set successfully.";
}

// Workflow configuration
$workflowSteps = [
    'pending' => ['icon' => 'clock', 'color' => 'yellow', 'label' => 'Pending'],
    'requested_to_seminar' => ['icon' => 'academic-cap', 'color' => 'blue', 'label' => 'Approved for Seminar'],
    'in_seminar' => ['icon' => 'book-open', 'color' => 'indigo', 'label' => 'In Seminar'],
    'seminar_completed' => ['icon' => 'check-badge', 'color' => 'teal', 'label' => 'Seminar Completed'],
    'requested_to_oath' => ['icon' => 'hand-raised', 'color' => 'purple', 'label' => 'Approved for Oath'],
    'ready_to_oath' => ['icon' => 'sparkles', 'color' => 'pink', 'label' => 'Ready for Oath'],
    'oath_taken' => ['icon' => 'check-circle', 'color' => 'green', 'label' => 'Oath Taken'],
    'rejected' => ['icon' => 'x-circle', 'color' => 'red', 'label' => 'Rejected'],
    'cancelled' => ['icon' => 'ban', 'color' => 'gray', 'label' => 'Cancelled']
];

$currentStep = $request['status'];
$currentStepConfig = $workflowSteps[$currentStep] ?? ['icon' => 'question-mark-circle', 'color' => 'gray', 'label' => 'Unknown'];
$canProgress = $canManage && !in_array($currentStep, ['oath_taken', 'rejected', 'cancelled']);

// Get initials for avatar
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
if ($initials === '' || $initials === '[D') $initials = '??';
?>
<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request #<?= $requestId ?> - <?= htmlspecialchars($fullName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        .glass { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen font-sans transition-colors duration-200">

<!-- Header -->
<header class="bg-gradient-to-r from-rose-600 via-pink-600 to-fuchsia-600 text-white sticky top-0 z-50 shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <!-- Left: Navigation -->
            <div class="flex items-center gap-4">
                <a href="../launchpad.php" class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/10 hover:bg-white/20 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                    <span class="hidden sm:inline font-medium">Launchpad</span>
                </a>
                
                <a href="list.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-white/10 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    <span class="hidden sm:inline">Back to List</span>
                </a>
            </div>
            
            <!-- Center: App Title -->
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div class="hidden md:block">
                    <h1 class="font-bold text-lg">Request Details</h1>
                    <p class="text-xs text-white/70">#<?= $requestId ?></p>
                </div>
            </div>
            
            <!-- Right: Actions -->
            <div class="flex items-center gap-3">
                <button @click="darkMode = !darkMode; localStorage.setItem('darkMode', darkMode)" 
                        class="p-2 rounded-lg bg-white/10 hover:bg-white/20 transition-all">
                    <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                    </svg>
                    <svg x-show="darkMode" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </button>
                
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white/10">
                    <div class="w-7 h-7 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold">
                        <?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <span class="hidden sm:inline text-sm font-medium"><?= htmlspecialchars($user['full_name'] ?? 'User') ?></span>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
    
    <!-- Alert Messages -->
    <?php if ($success): ?>
    <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-xl p-4 flex items-start gap-3" x-data="{ show: true }" x-show="show">
        <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-800 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="flex-1">
            <p class="text-sm font-medium text-green-800 dark:text-green-200"><?= htmlspecialchars($success) ?></p>
        </div>
        <button @click="show = false" class="text-green-500 hover:text-green-700">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        </button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-800 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
        </div>
        <p class="text-sm font-medium text-red-800 dark:text-red-200"><?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Profile Hero Card -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="h-24 bg-gradient-to-r from-rose-500 via-pink-500 to-fuchsia-500"></div>
        <div class="px-6 pb-6">
            <div class="flex flex-col sm:flex-row sm:items-end gap-4 -mt-12">
                <!-- Avatar -->
                <div class="w-24 h-24 rounded-2xl bg-gradient-to-br from-rose-400 to-pink-500 border-4 border-white dark:border-gray-800 flex items-center justify-center text-white text-2xl font-bold shadow-lg">
                    <?= $initials ?>
                </div>
                
                <!-- Name & Info -->
                <div class="flex-1 pb-2">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($fullName) ?></h2>
                    <p class="text-gray-500 dark:text-gray-400"><?= htmlspecialchars($request['requested_department']) ?><?= $request['requested_duty'] ? ' • ' . htmlspecialchars($request['requested_duty']) : '' ?></p>
                </div>
                
                <!-- Status Badge -->
                <div class="flex items-center gap-2 flex-wrap">
                    <?php if (!empty($request['is_imported'])): ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-lg bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300 text-xs font-semibold">
                        <svg class="w-3.5 h-3.5 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/>
                            <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/>
                            <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/>
                        </svg>
                        LORCAPP
                    </span>
                    <?php if (!empty($request['record_code'])): ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-lg <?= $request['record_code'] === 'A' ? 'bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300' : 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300' ?> text-xs font-semibold">
                        CODE <?= $request['record_code'] ?>
                    </span>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <span class="inline-flex items-center px-4 py-2 rounded-xl bg-<?= $currentStepConfig['color'] ?>-100 dark:bg-<?= $currentStepConfig['color'] ?>-900/50 text-<?= $currentStepConfig['color'] ?>-700 dark:text-<?= $currentStepConfig['color'] ?>-300 text-sm font-semibold">
                        <?= $currentStepConfig['label'] ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Record Code Setter for LORCAPP imports -->
    <?php if ($canManage && empty($request['record_code']) && !empty($request['is_imported'])): ?>
    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl p-6" x-data="{ searchQuery: '', results: [], selected: null, loading: false }">
        <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-800 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-bold text-amber-900 dark:text-amber-100 mb-2">Record Code Required</h3>
                <p class="text-sm text-amber-800 dark:text-amber-200 mb-4">
                    This request was imported from LORCAPP. Please set the record code before proceeding.
                </p>
                
                <div class="grid sm:grid-cols-2 gap-4">
                    <!-- CODE A -->
                    <form method="POST" class="bg-white dark:bg-gray-800 rounded-xl p-4 border-2 border-transparent hover:border-green-400 transition-all">
                        <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="set_code">
                        <input type="hidden" name="record_code" value="A">
                        
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-900 flex items-center justify-center">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">CODE A</p>
                                <p class="text-xs text-gray-500">New Officer</p>
                            </div>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">Creates a new officer record in the system.</p>
                        <button type="submit" class="w-full py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors">
                            Set CODE A
                        </button>
                    </form>
                    
                    <!-- CODE D -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border-2 border-transparent hover:border-blue-400 transition-all">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">CODE D</p>
                                <p class="text-xs text-gray-500">Link to Existing</p>
                            </div>
                        </div>
                        
                        <div class="space-y-3">
                            <input type="text" x-model="searchQuery" 
                                   @input.debounce.300ms="if(searchQuery.length >= 2) { loading = true; fetch('../api/search-officers.php?q=' + encodeURIComponent(searchQuery)).then(r => r.json()).then(d => { results = d; loading = false; }); } else { results = []; }"
                                   placeholder="Search officer name..."
                                   class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                            
                            <div x-show="loading" class="text-xs text-gray-500 text-center py-2">Searching...</div>
                            
                            <div x-show="results.length > 0 && !selected" class="max-h-32 overflow-y-auto space-y-1">
                                <template x-for="officer in results" :key="officer.uuid">
                                    <button type="button" @click="selected = officer" class="w-full text-left px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-sm">
                                        <p class="font-medium text-gray-900 dark:text-white" x-text="officer.name"></p>
                                        <p class="text-xs text-gray-500" x-text="officer.location"></p>
                                    </button>
                                </template>
                            </div>
                            
                            <div x-show="selected" class="p-3 bg-blue-50 dark:bg-blue-900/50 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-blue-900 dark:text-blue-100" x-text="selected?.name"></p>
                                        <p class="text-xs text-blue-700 dark:text-blue-300" x-text="selected?.location"></p>
                                    </div>
                                    <button type="button" @click="selected = null" class="text-blue-600 hover:text-blue-800">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                    </button>
                                </div>
                            </div>
                            
                            <form method="POST" x-show="selected">
                                <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="set_code">
                                <input type="hidden" name="record_code" value="D">
                                <input type="hidden" name="existing_officer_uuid" :value="selected?.uuid">
                                <button type="submit" class="w-full py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                                    Set CODE D
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Applicant Information -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-rose-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                    </svg>
                    Applicant Information
                </h3>
                
                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Full Name</p>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($fullName) ?></p>
                    </div>
                    
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Department</p>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($request['requested_department']) ?></p>
                    </div>
                    
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">District</p>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($request['district_name']) ?></p>
                    </div>
                    
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Local Congregation</p>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($request['local_name']) ?></p>
                    </div>
                    
                    <?php if ($request['requested_duty']): ?>
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl sm:col-span-2">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Specific Duty</p>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($request['requested_duty']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Seminar Progress -->
            <?php if (!empty($request['request_class']) && in_array($request['status'], ['requested_to_seminar', 'in_seminar', 'seminar_completed', 'requested_to_oath', 'ready_to_oath', 'oath_taken'])): ?>
            <?php 
            $progressPercent = $daysRequired > 0 ? ($daysCompleted / $daysRequired) * 100 : 0;
            $isComplete = $daysCompleted >= $daysRequired && $daysRequired > 0;
            ?>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/>
                            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/>
                        </svg>
                        Seminar Progress
                    </h3>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        <?= htmlspecialchars($request['request_class'] === '33_lessons' ? '33 Lessons' : '8 Lessons') ?>
                    </span>
                </div>
                
                <!-- Progress Bar -->
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            <?= $daysCompleted ?> of <?= $daysRequired ?> days completed
                        </span>
                        <span class="text-sm font-bold <?= $isComplete ? 'text-green-600' : 'text-purple-600' ?>">
                            <?= number_format($progressPercent, 0) ?>%
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 overflow-hidden">
                        <div class="<?= $isComplete ? 'bg-green-500' : 'bg-purple-500' ?> h-3 rounded-full transition-all duration-500" 
                             style="width: <?= min($progressPercent, 100) ?>%"></div>
                    </div>
                    <?php if ($isComplete): ?>
                    <div class="mt-2 flex items-center text-sm text-green-600 dark:text-green-400 font-medium">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        All required seminar days completed!
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Seminar Dates -->
                <?php if (!empty($seminarDates)): ?>
                <div class="space-y-2 max-h-80 overflow-y-auto scrollbar-hide">
                    <?php foreach ($seminarDates as $index => $seminar): ?>
                    <?php 
                    $isAttended = !empty($seminar['attended']);
                    $isPast = strtotime($seminar['date']) < strtotime('today');
                    $isToday = date('Y-m-d', strtotime($seminar['date'])) === date('Y-m-d');
                    ?>
                    <div class="flex items-center gap-3 p-3 rounded-xl <?= $isAttended ? 'bg-green-50 dark:bg-green-900/20' : 'bg-gray-50 dark:bg-gray-700/50' ?>">
                        <div class="w-8 h-8 rounded-full <?= $isAttended ? 'bg-green-500' : 'bg-purple-500' ?> text-white flex items-center justify-center text-sm font-bold flex-shrink-0">
                            <?php if ($isAttended): ?>
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <?php else: ?>
                            <?= $index + 1 ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-gray-900 dark:text-white text-sm">
                                    <?= date('M j, Y', strtotime($seminar['date'])) ?>
                                </span>
                                <?php if ($isToday): ?>
                                <span class="px-2 py-0.5 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs font-semibold rounded">TODAY</span>
                                <?php endif; ?>
                                <?php if ($isAttended): ?>
                                <span class="px-2 py-0.5 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 text-xs font-semibold rounded">ATTENDED</span>
                                <?php elseif ($isPast): ?>
                                <span class="px-2 py-0.5 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 text-xs font-semibold rounded">MISSED</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($seminar['topic'])): ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?= htmlspecialchars($seminar['topic']) ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($canManage): ?>
                        <form method="POST" class="flex-shrink-0">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="mark_attendance">
                            <input type="hidden" name="seminar_index" value="<?= $index ?>">
                            <input type="hidden" name="attended" value="<?= $isAttended ? '0' : '1' ?>">
                            <button type="submit" class="p-2 rounded-lg <?= $isAttended ? 'text-yellow-600 hover:bg-yellow-100 dark:hover:bg-yellow-900/30' : 'text-green-600 hover:bg-green-100 dark:hover:bg-green-900/30' ?> transition-colors">
                                <?php if ($isAttended): ?>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                <?php else: ?>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <?php endif; ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                    <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="text-sm">No seminar dates recorded yet</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Oath Details -->
            <?php if (in_array($request['status'], ['requested_to_oath', 'ready_to_oath', 'oath_taken'])): ?>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Oath Taking Details
                </h3>
                
                <div class="grid sm:grid-cols-2 gap-4">
                    <?php if ($request['oath_scheduled_date']): ?>
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Scheduled Date</p>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= date('F j, Y', strtotime($request['oath_scheduled_date'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['oath_actual_date']): ?>
                    <div class="p-4 bg-green-50 dark:bg-green-900/30 rounded-xl">
                        <p class="text-xs text-green-600 dark:text-green-400 uppercase tracking-wider mb-1">Actual Oath Date</p>
                        <p class="font-semibold text-green-700 dark:text-green-300"><?= date('F j, Y', strtotime($request['oath_actual_date'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['oath_location']): ?>
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl sm:col-span-2">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Location</p>
                        <p class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($request['oath_location']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($request['officer_uuid']): ?>
                <div class="mt-4 p-4 bg-green-50 dark:bg-green-900/30 rounded-xl border border-green-200 dark:border-green-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-green-700 dark:text-green-300">Officer Record Created</p>
                            <p class="text-xs font-mono text-green-600 dark:text-green-400"><?= htmlspecialchars($request['officer_uuid']) ?></p>
                        </div>
                        <a href="../officers/view.php?id=<?= $request['officer_id'] ?>" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors">
                            View Officer →
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Sidebar -->
        <div class="space-y-6">
            
            <!-- Workflow Actions -->
            <?php if ($canProgress): ?>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-wider">Next Action</h3>
                
                <?php
                // Inline workflow actions
                switch ($request['status']) {
                    case 'pending':
                        ?>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="approve_seminar">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Seminar Date</label>
                                <input type="date" name="seminar_date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location</label>
                                <input type="text" name="seminar_location" placeholder="Where will seminar be held?" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            </div>
                            
                            <button type="submit" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                                Approve for Seminar
                            </button>
                        </form>
                        <?php
                        break;
                        
                    case 'requested_to_seminar':
                        ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="mark_in_seminar">
                            <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors">
                                Mark In Seminar
                            </button>
                        </form>
                        <?php
                        break;
                        
                    case 'in_seminar':
                        ?>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="complete_seminar">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Completion Date</label>
                                <input type="date" name="completion_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Certificate Number</label>
                                <input type="text" name="certificate_number" placeholder="Optional" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            </div>
                            
                            <button type="submit" class="w-full py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg font-medium transition-colors">
                                Complete Seminar
                            </button>
                        </form>
                        <?php
                        break;
                        
                    case 'seminar_completed':
                        ?>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="approve_oath">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Oath Date</label>
                                <input type="date" name="oath_date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location</label>
                                <input type="text" name="oath_location" placeholder="Where will oath be taken?" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            </div>
                            
                            <button type="submit" class="w-full py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition-colors">
                                Approve for Oath
                            </button>
                        </form>
                        <?php
                        break;
                        
                    case 'requested_to_oath':
                        ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="mark_ready_oath">
                            <button type="submit" class="w-full py-2.5 bg-pink-600 hover:bg-pink-700 text-white rounded-lg font-medium transition-colors">
                                Mark Ready for Oath
                            </button>
                        </form>
                        <?php
                        break;
                        
                    case 'ready_to_oath':
                        ?>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="complete_oath">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Actual Oath Date</label>
                                <input type="date" name="actual_oath_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            </div>
                            
                            <button type="submit" class="w-full py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors">
                                Complete Oath
                            </button>
                        </form>
                        <?php
                        break;
                }
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Certificates -->
            <?php if (in_array($request['status'], ['ready_to_oath', 'oath_taken']) || ($daysCompleted >= $daysRequired && $daysRequired > 0)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-wider">Certificates</h3>
                
                <div class="space-y-3">
                    <?php if ($daysCompleted >= $daysRequired && $daysRequired > 0): ?>
                    <a href="../generate-r513-html.php?request_id=<?= $requestId ?>&preview=1" target="_blank"
                       class="flex items-center gap-3 p-3 rounded-xl bg-purple-50 dark:bg-purple-900/30 hover:bg-purple-100 dark:hover:bg-purple-900/50 transition-colors">
                        <div class="w-10 h-10 rounded-lg bg-purple-100 dark:bg-purple-800 flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="font-medium text-purple-700 dark:text-purple-300">R5-13 Certificate</p>
                            <p class="text-xs text-purple-600 dark:text-purple-400">Seminar completion certificate</p>
                        </div>
                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                    
                    <?php if (in_array($request['status'], ['ready_to_oath', 'oath_taken'])): ?>
                    <a href="../generate-palasumpaan.php?request_id=<?= $requestId ?>&preview=1" target="_blank"
                       class="flex items-center gap-3 p-3 rounded-xl bg-green-50 dark:bg-green-900/30 hover:bg-green-100 dark:hover:bg-green-900/50 transition-colors">
                        <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-800 flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="font-medium text-green-700 dark:text-green-300">Palasumpaan</p>
                            <p class="text-xs text-green-600 dark:text-green-400">Oath certificate</p>
                        </div>
                        <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                    
                    <?php if (!empty($request['lorcapp_id'])): ?>
                    <a href="../lorcapp/view.php?id=<?= htmlspecialchars($request['lorcapp_id']) ?>" target="_blank"
                       class="flex items-center gap-3 p-3 rounded-xl bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition-colors">
                        <div class="w-10 h-10 rounded-lg bg-indigo-100 dark:bg-indigo-800 flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/>
                                <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/>
                                <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="font-medium text-indigo-700 dark:text-indigo-300">R-201 Record</p>
                            <p class="text-xs text-indigo-600 dark:text-indigo-400">LORCAPP linked</p>
                        </div>
                        <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Timeline -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-wider">Timeline</h3>
                
                <div class="space-y-4">
                    <div class="flex gap-3">
                        <div class="w-2 h-2 bg-rose-500 rounded-full mt-2 flex-shrink-0"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Request Submitted</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= date('M j, Y g:i A', strtotime($request['requested_at'])) ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">By: <?= htmlspecialchars($request['requested_by_name']) ?></p>
                        </div>
                    </div>
                    
                    <?php if ($request['seminar_approved_at']): ?>
                    <div class="flex gap-3">
                        <div class="w-2 h-2 bg-blue-500 rounded-full mt-2 flex-shrink-0"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Approved for Seminar</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= date('M j, Y g:i A', strtotime($request['seminar_approved_at'])) ?></p>
                            <?php if ($request['seminar_approved_by_name']): ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400">By: <?= htmlspecialchars($request['seminar_approved_by_name']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['oath_approved_at']): ?>
                    <div class="flex gap-3">
                        <div class="w-2 h-2 bg-purple-500 rounded-full mt-2 flex-shrink-0"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Approved for Oath</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= date('M j, Y g:i A', strtotime($request['oath_approved_at'])) ?></p>
                            <?php if ($request['oath_approved_by_name']): ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400">By: <?= htmlspecialchars($request['oath_approved_by_name']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['completed_at']): ?>
                    <div class="flex gap-3">
                        <div class="w-2 h-2 bg-green-500 rounded-full mt-2 flex-shrink-0"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Oath Completed</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= date('M j, Y g:i A', strtotime($request['completed_at'])) ?></p>
                            <?php if ($request['completed_by_name']): ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400">By: <?= htmlspecialchars($request['completed_by_name']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Admin Actions -->
            <?php if ($canManage && !in_array($request['status'], ['oath_taken', 'rejected', 'cancelled'])): ?>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-wider">Admin Actions</h3>
                
                <div class="space-y-3">
                    <button onclick="document.getElementById('rejectModal').classList.remove('hidden')"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl hover:bg-red-100 dark:hover:bg-red-900/50 transition-colors text-sm font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Reject Request
                    </button>
                    
                    <button onclick="document.getElementById('deleteModal').classList.remove('hidden')"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors text-sm font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Delete Request
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Reject Modal -->
<?php if ($canManage && !in_array($request['status'], ['oath_taken', 'rejected', 'cancelled'])): ?>
<div id="rejectModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('rejectModal').classList.add('hidden')"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Reject Request</h3>
                <button onclick="document.getElementById('rejectModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                <input type="hidden" name="action" value="reject">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Rejection *</label>
                    <textarea name="rejection_reason" rows="4" required
                              class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500 resize-none"
                              placeholder="Explain why this request is being rejected..."></textarea>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="document.getElementById('rejectModal').classList.add('hidden')" 
                            class="flex-1 px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl font-medium transition-colors">
                        Reject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Modal -->
<?php if ($canManage && !in_array($request['status'], ['oath_taken'])): ?>
<div id="deleteModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('deleteModal').classList.add('hidden')"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Delete Request</h3>
                <button onclick="document.getElementById('deleteModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/30 rounded-xl border border-red-200 dark:border-red-800">
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-red-800 dark:text-red-200">This action cannot be undone</p>
                        <p class="text-xs text-red-700 dark:text-red-300 mt-1">All data associated with this request will be permanently deleted.</p>
                    </div>
                </div>
            </div>
            
            <p class="text-sm text-gray-700 dark:text-gray-300 mb-4">
                Are you sure you want to delete the request for <strong><?= htmlspecialchars($fullName) ?></strong>?
            </p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCSRFToken() ?>">
                <input type="hidden" name="action" value="delete">
                
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('deleteModal').classList.add('hidden')" 
                            class="flex-1 px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl font-medium transition-colors">
                        Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>

