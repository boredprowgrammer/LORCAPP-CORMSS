<?php
/**
 * Seminar Date Management API
 * Handles adding and removing seminar dates for officer requests
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

// Require authentication
Security::requireLogin();

// Set JSON header
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$requestId = $input['request_id'] ?? null;
$action = $input['action'] ?? null;

if (!$requestId || !$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get the request and check permissions
    $stmt = $db->prepare("
        SELECT r.*, u.district_code, u.local_code, u.role 
        FROM officer_requests r
        JOIN users u ON r.requested_by = u.user_id
        WHERE r.request_id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        exit;
    }
    
    // Check if user has permission to manage this request
    $canManage = false;
    $currentUser = getCurrentUser();
    
    if ($currentUser['role'] === 'admin') {
        $canManage = true;
    } elseif ($currentUser['role'] === 'district' && $currentUser['district_code'] === $request['district_code']) {
        $canManage = true;
    } elseif ($currentUser['role'] === 'local' && $currentUser['local_code'] === $request['local_code']) {
        $canManage = true;
    }
    
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to manage this request']);
        exit;
    }
    
    // Check if request has a request_class
    if (empty($request['request_class'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This request does not have a seminar requirement']);
        exit;
    }
    
    // Get current seminar dates
    $seminarDates = !empty($request['seminar_dates']) ? json_decode($request['seminar_dates'], true) : [];
    if (!is_array($seminarDates)) {
        $seminarDates = [];
    }
    
    // Get seminar days required
    $seminarDaysRequired = $request['seminar_days_required'] ?? 0;
    
    if ($action === 'add') {
        // Add a new seminar date
        $date = $input['date'] ?? null;
        $topic = $input['topic'] ?? '';
        $notes = $input['notes'] ?? '';
        
        if (!$date) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Seminar date is required']);
            exit;
        }
        
        // Validate date format
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid date format']);
            exit;
        }
        
        // Add the new seminar date
        $seminarDates[] = [
            'date' => $date,
            'topic' => $topic,
            'notes' => $notes,
            'added_at' => date('Y-m-d H:i:s'),
            'attended' => false,
            'auto_generated' => false
        ];
        
        // Sort by date
        usort($seminarDates, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        // Count attended dates
        $attendedCount = count(array_filter($seminarDates, function($d) { return !empty($d['attended']); }));
        
        // Update the database
        $stmt = $db->prepare("
            UPDATE officer_requests 
            SET seminar_dates = ?, 
                seminar_days_completed = ?
            WHERE request_id = ?
        ");
        $stmt->execute([
            json_encode($seminarDates),
            $attendedCount,
            $requestId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Seminar date added successfully',
            'days_completed' => $attendedCount,
            'days_required' => $seminarDaysRequired
        ]);
        
    } elseif ($action === 'edit') {
        // Edit an existing seminar date
        $index = $input['index'] ?? null;
        $date = $input['date'] ?? null;
        $topic = $input['topic'] ?? '';
        $notes = $input['notes'] ?? '';
        
        if ($index === null || !isset($seminarDates[$index])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid seminar date index']);
            exit;
        }
        
        if (!$date) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Seminar date is required']);
            exit;
        }
        
        // Validate date format
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid date format']);
            exit;
        }
        
        // Update the date while preserving attendance status
        $seminarDates[$index]['date'] = $date;
        $seminarDates[$index]['topic'] = $topic;
        $seminarDates[$index]['notes'] = $notes;
        $seminarDates[$index]['updated_at'] = date('Y-m-d H:i:s');
        
        // Sort by date
        usort($seminarDates, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        // Count attended dates
        $attendedCount = count(array_filter($seminarDates, function($d) { return !empty($d['attended']); }));
        
        // Update the database
        $stmt = $db->prepare("
            UPDATE officer_requests 
            SET seminar_dates = ?, 
                seminar_days_completed = ?
            WHERE request_id = ?
        ");
        $stmt->execute([
            json_encode($seminarDates),
            $attendedCount,
            $requestId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Seminar date updated successfully',
            'days_completed' => $attendedCount,
            'days_required' => $seminarDaysRequired
        ]);
        
    } elseif ($action === 'mark_attendance') {
        // Mark attendance for a seminar date
        $index = $input['index'] ?? null;
        $attended = $input['attended'] ?? false;
        
        if ($index === null || !isset($seminarDates[$index])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid seminar date index']);
            exit;
        }
        
        // Update attendance status
        $seminarDates[$index]['attended'] = (bool)$attended;
        $seminarDates[$index]['attendance_marked_at'] = date('Y-m-d H:i:s');
        
        // Count attended dates
        $attendedCount = count(array_filter($seminarDates, function($d) { return !empty($d['attended']); }));
        
        // Update the database
        $stmt = $db->prepare("
            UPDATE officer_requests 
            SET seminar_dates = ?, 
                seminar_days_completed = ?
            WHERE request_id = ?
        ");
        $stmt->execute([
            json_encode($seminarDates),
            $attendedCount,
            $requestId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => $attended ? 'Attendance marked' : 'Attendance unmarked',
            'days_completed' => $attendedCount,
            'days_required' => $seminarDaysRequired
        ]);
        
    } elseif ($action === 'delete') {
        // Remove a seminar date
        $index = $input['index'] ?? null;
        
        if ($index === null || !isset($seminarDates[$index])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid seminar date index']);
            exit;
        }
        
        // Remove the date at the specified index
        array_splice($seminarDates, $index, 1);
        
        // Count attended dates
        $attendedCount = count(array_filter($seminarDates, function($d) { return !empty($d['attended']); }));
        
        // Update the database
        $stmt = $db->prepare("
            UPDATE officer_requests 
            SET seminar_dates = ?, 
                seminar_days_completed = ?
            WHERE request_id = ?
        ");
        $stmt->execute([
            json_encode($seminarDates),
            $attendedCount,
            $requestId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Seminar date removed successfully',
            'days_completed' => $attendedCount,
            'days_required' => $request['seminar_days_required'] ?? 0
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Seminar update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred while updating seminar dates']);
}
