<?php
/**
 * Auto-Initialize Seminar Dates
 * Automatically generates seminar date placeholders when seminar starts
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

// Require authentication
Security::requireLogin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$requestId = $input['request_id'] ?? null;

if (!$requestId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Request ID required']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get the request
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
    
    // Check if already initialized
    if (!empty($request['seminar_dates'])) {
        echo json_encode([
            'success' => true, 
            'already_initialized' => true,
            'message' => 'Seminar dates already initialized'
        ]);
        exit;
    }
    
    // Check if request has seminar requirement
    if (empty($request['request_class']) || empty($request['seminar_days_required'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No seminar requirement for this request']);
        exit;
    }
    
    // Auto-generate seminar dates (DAILY intervals starting from approved_at or today)
    $startDate = !empty($request['district_approved_at']) 
        ? new DateTime($request['district_approved_at'])
        : new DateTime();
    
    $daysRequired = $request['seminar_days_required'];
    $seminarDates = [];
    
    // Generate dates at DAILY intervals (consecutive days)
    for ($i = 0; $i < $daysRequired; $i++) {
        $currentDate = clone $startDate;
        $currentDate->modify("+$i days");
        
        $seminarDates[] = [
            'date' => $currentDate->format('Y-m-d'),
            'topic' => '',
            'notes' => 'Auto-generated - Update attendance as needed',
            'added_at' => date('Y-m-d H:i:s'),
            'auto_generated' => true,
            'attended' => false
        ];
    }
    
    // Update the database
    $stmt = $db->prepare("
        UPDATE officer_requests 
        SET seminar_dates = ?, 
            seminar_days_completed = 0
        WHERE request_id = ?
    ");
    $stmt->execute([
        json_encode($seminarDates),
        $requestId
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => "Generated $daysRequired seminar dates (daily schedule)",
        'dates_generated' => $daysRequired,
        'seminar_dates' => $seminarDates
    ]);
    
} catch (Exception $e) {
    error_log("Auto-initialize seminar error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to initialize seminar dates']);
}
