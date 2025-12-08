<?php
/**
 * Generate Call-Up Slip PDF
 * Returns JSON with PDF data
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get slip ID
$slipId = intval($_POST['slip_id'] ?? 0);

if (!$slipId) {
    echo json_encode(['error' => 'Invalid call-up slip ID.']);
    exit;
}

// Fetch call-up slip details
$stmt = $db->prepare("
    SELECT 
        c.*,
        o.officer_uuid,
        o.last_name_encrypted,
        o.first_name_encrypted,
        o.middle_initial_encrypted,
        o.district_code as officer_district,
        d.district_name,
        l.local_name,
        u.full_name as prepared_by_name
    FROM call_up_slips c
    JOIN officers o ON c.officer_id = o.officer_id
    LEFT JOIN districts d ON c.district_code = d.district_code
    LEFT JOIN local_congregations l ON c.local_code = l.local_code
    LEFT JOIN users u ON c.prepared_by = u.user_id
    WHERE c.slip_id = ?
");

$stmt->execute([$slipId]);
$slip = $stmt->fetch();

if (!$slip) {
    echo json_encode(['error' => 'Call-up slip not found.']);
    exit;
}

// Check access rights
if ($currentUser['role'] === 'local' && $slip['local_code'] !== $currentUser['local_code']) {
    echo json_encode(['error' => 'Access denied.']);
    exit;
} elseif ($currentUser['role'] === 'district' && $slip['district_code'] !== $currentUser['district_code']) {
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

// Decrypt officer name
$decrypted = Encryption::decryptOfficerName(
    $slip['last_name_encrypted'],
    $slip['first_name_encrypted'],
    $slip['middle_initial_encrypted'],
    $slip['officer_district']
);

$officerFullName = trim(
    $decrypted['first_name'] . ' ' . 
    ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
    $decrypted['last_name']
);

// Format dates (Filipino month names)
$filipinoMonths = [
    1 => 'ENERO', 2 => 'PEBRERO', 3 => 'MARSO', 4 => 'ABRIL',
    5 => 'MAYO', 6 => 'HUNYO', 7 => 'HULYO', 8 => 'AGOSTO',
    9 => 'SETYEMBRE', 10 => 'OKTUBRE', 11 => 'NOBYEMBRE', 12 => 'DISYEMBRE'
];

$issueDateFil = strtoupper($filipinoMonths[(int)date('n', strtotime($slip['issue_date']))]) . ' ' . 
                date('d, Y', strtotime($slip['issue_date']));

$deadlineDateFil = strtoupper($filipinoMonths[(int)date('n', strtotime($slip['deadline_date']))]) . ' ' . 
                   date('d, Y', strtotime($slip['deadline_date']));

// Use destinado from database or default
$destinadoName = $slip['destinado'] ?? 'DESTINADO NG LOKAL';

// Return data for PDF generation
echo json_encode([
    'success' => true,
    'data' => [
        'district' => strtoupper($slip['district_name']),
        'local' => strtoupper($slip['local_name']),
        'officer_name' => strtoupper($officerFullName),
        'department' => strtoupper($slip['department']),
        'file_number' => $slip['file_number'],
        'issue_date' => $issueDateFil,
        'reason' => strtoupper($slip['reason']),
        'deadline_date' => $deadlineDateFil,
        'prepared_by' => strtoupper($slip['prepared_by_name']),
        'destinado' => strtoupper($destinadoName)
    ]
]);
?>
