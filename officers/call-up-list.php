<?php
/**
 * Call-Up Slips List
 * View and manage all call-up slips
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf-storage.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Security::validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $slipId = intval($_POST['slip_id'] ?? 0);
    $action = $_POST['action'];
    
    try {
        if ($action === 'generate_pdf') {
            // Clear the auto-generation flag immediately to prevent loops
            unset($_SESSION['generate_pdf_slip_id']);
            
            // Fetch call-up slip details for PDF generation
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
                LEFT JOIN officers o ON c.officer_id = o.officer_id
                LEFT JOIN districts d ON c.district_code = d.district_code
                LEFT JOIN local_congregations l ON c.local_code = l.local_code
                LEFT JOIN users u ON c.prepared_by = u.user_id
                WHERE c.slip_id = ?
            ");
            
            $stmt->execute([$slipId]);
            $slip = $stmt->fetch();
            
            if ($slip) {
                // Check access rights
                if ($currentUser['role'] === 'local' && $slip['local_code'] !== $currentUser['local_code']) {
                    throw new Exception('Access denied.');
                } elseif ($currentUser['role'] === 'district' && $slip['district_code'] !== $currentUser['district_code']) {
                    throw new Exception('Access denied.');
                }
                
                // Get officer name (either from database or manual entry)
                if (!empty($slip['manual_officer_name'])) {
                    $officerFullName = $slip['manual_officer_name'];
                } else {
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
                }
                
                // Format dates
                $filipinoMonths = [
                    1 => 'ENERO', 2 => 'PEBRERO', 3 => 'MARSO', 4 => 'ABRIL',
                    5 => 'MAYO', 6 => 'HUNYO', 7 => 'HULYO', 8 => 'AGOSTO',
                    9 => 'SETYEMBRE', 10 => 'OKTUBRE', 11 => 'NOBYEMBRE', 12 => 'DISYEMBRE'
                ];
                
                $issueDateFil = strtoupper($filipinoMonths[(int)date('n', strtotime($slip['issue_date']))]) . ' ' . 
                                date('d, Y', strtotime($slip['issue_date']));
                
                $deadlineDateFil = strtoupper($filipinoMonths[(int)date('n', strtotime($slip['deadline_date']))]) . ' ' . 
                                   date('d, Y', strtotime($slip['deadline_date']));
                
                $destinadoName = $slip['destinado'] ?? 'DESTINADO NG LOKAL';
                
                // Load template
                $templatePath = __DIR__ . '/../Call-UpForm_Template.html';
                if (file_exists($templatePath)) {
                    $html = file_get_contents($templatePath);
                    
                    // Replace all values
                    $html = str_replace('DISTRITO NG PAMPANGA EAST', 
                                        'DISTRITO NG ' . strtoupper(htmlspecialchars($slip['district_name'], ENT_QUOTES, 'UTF-8')), 
                                        $html);
                    
                    $html = str_replace('LOKAL NG STO. TOMAS', 
                                        'LOKAL NG ' . strtoupper(htmlspecialchars($slip['local_name'], ENT_QUOTES, 'UTF-8')), 
                                        $html);
                    
                    $html = str_replace('PANGALAN: <span class="s2">VICTOR G. VIGAN</span>', 
                                        'PANGALAN: <span class="s2">' . strtoupper(htmlspecialchars($officerFullName, ENT_QUOTES, 'UTF-8')) . '</span>', 
                                        $html);
                    
                    $html = str_replace('KAGAWARAN/KAPISANAN: <span class="s2">BUKLOD</span>', 
                                        'KAGAWARAN/KAPISANAN: <span class="s2">' . strtoupper(htmlspecialchars($slip['department'], ENT_QUOTES, 'UTF-8')) . '</span>', 
                                        $html);
                    
                    $html = str_replace('CALL-UP FILE #: BUK-2025-001', 
                                        'CALL-UP FILE #: ' . htmlspecialchars($slip['file_number'], ENT_QUOTES, 'UTF-8'), 
                                        $html);
                    
                    $html = str_replace('PETSA: <span class="s2">AGOSTO 06, 2025</span>', 
                                        'PETSA: <span class="s2">' . htmlspecialchars($issueDateFil, ENT_QUOTES, 'UTF-8') . '</span>', 
                                        $html);
                    
                    $html = str_replace('HINDI PO PAGSUMITE NG <span class="s3">R7-02 </span>NOONG AGOSTO 03, 2025', 
                                        strtoupper(htmlspecialchars($slip['reason'], ENT_QUOTES, 'UTF-8')), 
                                        $html);
                    
                    $html = str_replace('AGOSTO 09, 2025', 
                                        htmlspecialchars($deadlineDateFil, ENT_QUOTES, 'UTF-8'), 
                                        $html);
                    
                    $html = str_replace('NAGHANDA: <b>JAN ANDREI P. FERNANDO</b>', 
                                        'NAGHANDA: <b>' . strtoupper(htmlspecialchars($slip['prepared_by_name'], ENT_QUOTES, 'UTF-8')) . '</b>', 
                                        $html);
                    
                    $html = str_replace('AIVAN JADE G. CADIGAL', 
                                        strtoupper(htmlspecialchars($destinadoName, ENT_QUOTES, 'UTF-8')), 
                                        $html);
                    
                    // Output PDF generation page
                    ?>
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <title>Generating PDF...</title>
                        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
                        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
                        <style>
                            body { 
                                margin: 0; 
                                padding: 0; 
                                font-family: Arial, sans-serif;
                            }
                            #loading { 
                                position: fixed;
                                top: 0;
                                left: 0;
                                right: 0;
                                bottom: 0;
                                background: rgba(0, 0, 0, 0.7);
                                backdrop-filter: blur(4px);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                z-index: 9999;
                            }
                            #loading-content {
                                text-align: center; 
                                background: white; 
                                padding: 40px 60px; 
                                border-radius: 12px; 
                                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                            }
                            .spinner { 
                                border: 4px solid #f3f3f3; 
                                border-top: 4px solid #3498db; 
                                border-radius: 50%; 
                                width: 50px; 
                                height: 50px; 
                                animation: spin 1s linear infinite; 
                                margin: 0 auto 20px; 
                            }
                            @keyframes spin { 
                                0% { transform: rotate(0deg); } 
                                100% { transform: rotate(360deg); } 
                            }
                            #loading-content h3 {
                                margin: 0 0 10px 0;
                                color: #333;
                                font-size: 20px;
                            }
                            #loading-content p {
                                margin: 0;
                                color: #666;
                                font-size: 14px;
                            }
                            #content { 
                                position: absolute;
                                left: -9999px;
                                top: -9999px;
                                background: white; 
                                padding: 20mm; 
                                width: 210mm;
                            }
                        </style>
                    </head>
                    <body>
                        <div id="loading">
                            <div id="loading-content">
                                <div class="spinner"></div>
                                <h3>Generating PDF...</h3>
                                <p>Please wait while we prepare your document.</p>
                            </div>
                        </div>
                        
                        <div id="content">
                            <?php echo $html; ?>
                        </div>

                        <script>
                            window.onload = function() {
                                const content = document.getElementById('content');
                                const loading = document.getElementById('loading');
                                
                                // Wait for fonts and rendering
                                setTimeout(function() {
                                    html2canvas(content, {
                                        scale: 2,
                                        useCORS: true,
                                        logging: false,
                                        backgroundColor: '#ffffff',
                                        windowWidth: content.scrollWidth,
                                        windowHeight: content.scrollHeight
                                    }).then(function(canvas) {
                                        const { jsPDF } = window.jspdf;
                                        const pdf = new jsPDF({
                                            orientation: 'portrait',
                                            unit: 'mm',
                                            format: 'a4'
                                        });
                                        
                                        const imgData = canvas.toDataURL('image/png', 1.0);
                                        const imgWidth = 210;
                                        const pageHeight = 297;
                                        const imgHeight = (canvas.height * imgWidth) / canvas.width;
                                        
                                        pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
                                        
                                        // Generate PDF blob
                                        const pdfBlob = pdf.output('blob');
                                        
                                        // Convert blob to base64 for transmission
                                        const reader = new FileReader();
                                        reader.readAsDataURL(pdfBlob);
                                        reader.onloadend = function() {
                                            const base64data = reader.result;
                                            
                                            // Send PDF to server for encrypted storage
                                            loading.querySelector('#loading-content').innerHTML = '<div class="spinner"></div><h3>Storing PDF...</h3><p>Encrypting and saving to database...</p>';
                                            
                                            fetch('<?php echo getBaseUrl(); ?>/api/store-pdf.php', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                },
                                                body: JSON.stringify({
                                                    pdf_data: base64data,
                                                    slip_id: <?php echo $slipId; ?>,
                                                    file_number: '<?php echo Security::escape($slip['file_number']); ?>',
                                                    csrf_token: '<?php echo Security::generateCSRFToken(); ?>'
                                                })
                                            })
                                            .then(response => response.json())
                                            .then(data => {
                                                if (data.success) {
                                                    // Open PDF in new tab using form submission (bypasses popup blockers)
                                                    const form = document.createElement('form');
                                                    form.method = 'GET';
                                                    form.action = '<?php echo getBaseUrl(); ?>/api/get-pdf.php';
                                                    form.target = '_blank';
                                                    
                                                    const pdfIdInput = document.createElement('input');
                                                    pdfIdInput.type = 'hidden';
                                                    pdfIdInput.name = 'pdf_id';
                                                    pdfIdInput.value = data.pdf_id;
                                                    
                                                    const tokenInput = document.createElement('input');
                                                    tokenInput.type = 'hidden';
                                                    tokenInput.name = 'token';
                                                    tokenInput.value = '<?php echo Security::generateCSRFToken(); ?>';
                                                    
                                                    form.appendChild(pdfIdInput);
                                                    form.appendChild(tokenInput);
                                                    document.body.appendChild(form);
                                                    form.submit();
                                                    document.body.removeChild(form);
                                                    
                                                    // Remove loading overlay immediately
                                                    loading.style.display = 'none';
                                                    
                                                    // Redirect back to list page
                                                    setTimeout(function() {
                                                        window.location.href = '<?php echo getBaseUrl(); ?>/officers/call-up-list.php';
                                                    }, 500);
                                                } else {
                                                    throw new Error(data.message || 'Failed to store PDF');
                                                }
                                            })
                                            .catch(error => {
                                                console.error('Error storing PDF:', error);
                                                loading.querySelector('#loading-content').innerHTML = '<svg style="width:60px;height:60px;margin:0 auto 20px;color:#e74c3c;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg><h3 style="color:#e74c3c;">Error Storing PDF</h3><p>Could not save PDF to database. Please try again.</p><br><button onclick="window.location.reload()" style="padding:10px 20px;background:#666;color:white;border:none;border-radius:5px;cursor:pointer;">Go Back</button>';
                                            });
                                        }
                                    }).catch(function(error) {
                                        console.error('Error generating PDF:', error);
                                        loading.querySelector('#loading-content').innerHTML = '<svg style="width:60px;height:60px;margin:0 auto 20px;color:#e74c3c;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg><h3 style="color:#e74c3c;">Error Generating PDF</h3><p>An error occurred. Please try again.</p><br><button onclick="window.location.reload()" style="padding:10px 20px;background:#666;color:white;border:none;border-radius:5px;cursor:pointer;">Go Back</button>';
                                    });
                                }, 800);
                            };
                        </script>
                    </body>
                    </html>
                    <?php
                    exit;
                }
            }
        } elseif ($action === 'mark_responded') {
            $responseNotes = Security::sanitizeInput($_POST['response_notes'] ?? '');
            $stmt = $db->prepare("
                UPDATE call_up_slips 
                SET status = 'responded', 
                    response_date = CURRENT_DATE,
                    response_notes = ?
                WHERE slip_id = ?
            ");
            $stmt->execute([$responseNotes, $slipId]);
            setFlashMessage('success', 'Call-up marked as responded.');
            
        } elseif ($action === 'cancel') {
            $stmt = $db->prepare("UPDATE call_up_slips SET status = 'cancelled' WHERE slip_id = ?");
            $stmt->execute([$slipId]);
            setFlashMessage('success', 'Call-up cancelled.');
        } elseif ($action === 'delete') {
            // Only admin can delete
            if ($currentUser['role'] === 'admin') {
                $stmt = $db->prepare("DELETE FROM call_up_slips WHERE slip_id = ?");
                $stmt->execute([$slipId]);
                setFlashMessage('success', 'Call-up slip deleted successfully.');
            } else {
                setFlashMessage('error', 'Only administrators can delete call-up slips.');
            }
        }
        
        header('Location: call-up-list.php');
        exit;
        
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Get filters
$filterStatus = Security::sanitizeInput($_GET['status'] ?? 'all');
$searchQuery = Security::sanitizeInput($_GET['search'] ?? '');

// Build query
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'local') {
    $whereConditions[] = 'c.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'c.district_code = ?';
    $params[] = $currentUser['district_code'];
}

if ($filterStatus !== 'all') {
    $whereConditions[] = 'c.status = ?';
    $params[] = $filterStatus;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get call-up slips
$query = "
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
    LEFT JOIN officers o ON c.officer_id = o.officer_id
    LEFT JOIN districts d ON c.district_code = d.district_code
    LEFT JOIN local_congregations l ON c.local_code = l.local_code
    LEFT JOIN users u ON c.prepared_by = u.user_id
    $whereClause
    ORDER BY c.issue_date DESC, c.created_at DESC
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$allSlips = $stmt->fetchAll();

// Filter by search if needed
$slips = [];
if (!empty($searchQuery)) {
    foreach ($allSlips as $slip) {
        // Get officer name (either from database or manual entry)
        if (!empty($slip['manual_officer_name'])) {
            $fullName = $slip['manual_officer_name'];
        } else {
            $decrypted = Encryption::decryptOfficerName(
                $slip['last_name_encrypted'],
                $slip['first_name_encrypted'],
                $slip['middle_initial_encrypted'],
                $slip['officer_district']
            );
            
            $fullName = trim($decrypted['first_name'] . ' ' . 
                            ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
                            $decrypted['last_name']);
        }
        
        if (stripos($fullName, $searchQuery) !== false || 
            stripos($slip['file_number'], $searchQuery) !== false) {
            $slips[] = $slip;
        }
    }
} else {
    $slips = $allSlips;
}

// Get status counts
$statusCounts = [
    'all' => count($allSlips),
    'issued' => 0,
    'responded' => 0,
    'expired' => 0,
    'cancelled' => 0
];

foreach ($allSlips as $slip) {
    if (isset($statusCounts[$slip['status']])) {
        $statusCounts[$slip['status']]++;
    }
}

$pageTitle = "Call-Up Slips";
ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Call-Up Slips</h1>
                    <p class="text-sm text-gray-500 mt-1">Tawag-Pansin Management</p>
                </div>
            </div>
            <a href="call-up.php" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white text-sm font-medium rounded-lg hover:bg-blue-600 transition-colors shadow-sm">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Create Call-Up
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Status Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-800" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>
                        All (<?php echo $statusCounts['all']; ?>)
                    </option>
                    <option value="issued" <?php echo $filterStatus === 'issued' ? 'selected' : ''; ?>>
                        Issued (<?php echo $statusCounts['issued']; ?>)
                    </option>
                    <option value="responded" <?php echo $filterStatus === 'responded' ? 'selected' : ''; ?>>
                        Responded (<?php echo $statusCounts['responded']; ?>)
                    </option>
                    <option value="expired" <?php echo $filterStatus === 'expired' ? 'selected' : ''; ?>>
                        Expired (<?php echo $statusCounts['expired']; ?>)
                    </option>
                    <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>
                        Cancelled (<?php echo $statusCounts['cancelled']; ?>)
                    </option>
                </select>
            </div>

            <!-- Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <input type="text" 
                    name="search" 
                    value="<?php echo Security::escape($searchQuery); ?>"
                    placeholder="Search by officer name or file #..."
                    class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white text-sm font-medium rounded-lg hover:bg-blue-600 transition-colors shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Search
                </button>
                <?php if (!empty($searchQuery) || $filterStatus !== 'all'): ?>
                    <a href="call-up-list.php" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                        Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Call-Up Slips List -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <?php if (empty($slips)): ?>
            <div class="text-center py-12 px-4">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">No Call-Up Slips Found</h3>
                <p class="text-sm text-gray-500 mb-4">Create your first call-up slip to get started.</p>
                <a href="call-up.php" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white text-sm font-medium rounded-lg hover:bg-blue-600 transition-colors shadow-sm">
                    Create Call-Up
                </a>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">File #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Officer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Issue Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Deadline</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200">
                        <?php foreach ($slips as $slip): 
                            // Get officer name (either from database or manual entry)
                            if (!empty($slip['manual_officer_name'])) {
                                $officerName = $slip['manual_officer_name'];
                                $officerFullName = $slip['manual_officer_name'];
                            } else {
                                $decrypted = Encryption::decryptOfficerName(
                                    $slip['last_name_encrypted'],
                                    $slip['first_name_encrypted'],
                                    $slip['middle_initial_encrypted'],
                                    $slip['officer_district']
                                );
                                
                                // Format officer names (abbreviated and full)
                                $officerName = trim($decrypted['first_name'] . ' ' . 
                                                ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
                                                substr($decrypted['last_name'], 0, 1) . '.');
                                
                                $officerFullName = trim($decrypted['first_name'] . ' ' . 
                                                ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
                                                $decrypted['last_name']);
                            }
                            
                            // Check if expired
                            $isExpired = $slip['status'] === 'issued' && 
                                        strtotime($slip['deadline_date']) < time();
                            
                            if ($isExpired && $slip['status'] === 'issued') {
                                // Auto-update to expired
                                $stmt = $db->prepare("UPDATE call_up_slips SET status = 'expired' WHERE slip_id = ?");
                                $stmt->execute([$slip['slip_id']]);
                                $slip['status'] = 'expired';
                            }
                            
                            // Status badges
                            $statusColors = [
                                'issued' => 'bg-yellow-100 text-yellow-800',
                                'responded' => 'bg-green-100 text-green-800',
                                'expired' => 'bg-red-100 text-red-800',
                                'cancelled' => 'bg-gray-100 text-gray-800'
                            ];
                            $statusBadge = $statusColors[$slip['status']] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="font-mono text-sm text-gray-900">
                                    <?php echo Security::escape($slip['file_number']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900 name-mono cursor-pointer" 
                                    title="<?php echo Security::escape($officerFullName); ?>"
                                    ondblclick="this.textContent='<?php echo Security::escape($officerFullName); ?>'">
                                    <?php echo Security::escape($officerName); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo Security::escape($slip['local_name']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo Security::escape($slip['department']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('M j, Y', strtotime($slip['issue_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('M j, Y', strtotime($slip['deadline_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $statusBadge; ?>">
                                    <?php echo ucfirst($slip['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <div class="flex items-center gap-2">
                                    <!-- Generate/View PDF -->
                                    <?php if (!empty($slip['pdf_file_id'])): ?>
                                        <!-- View Stored PDF -->
                                        <a href="<?php echo getBaseUrl(); ?>/api/get-pdf.php?pdf_id=<?php echo (int)$slip['pdf_file_id']; ?>&token=<?php echo urlencode(Security::generateCSRFToken()); ?>" 
                                            target="_blank"
                                            class="inline-flex items-center justify-center w-8 h-8 text-blue-600 hover:text-blue-700 hover:bg-blue-50 rounded-lg transition-colors"
                                            title="View PDF">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>
                                        <!-- Regenerate PDF -->
                                        <form method="POST" style="display: inline;" onsubmit="showInlineLoader(this, event)">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="generate_pdf">
                                            <input type="hidden" name="slip_id" value="<?php echo $slip['slip_id']; ?>">
                                            <button type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-orange-600 hover:bg-orange-50 rounded-lg transition-colors"
                                                title="Regenerate PDF">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <!-- Generate PDF for first time -->
                                        <form method="POST" style="display: inline;" onsubmit="showInlineLoader(this, event)">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="generate_pdf">
                                            <input type="hidden" name="slip_id" value="<?php echo $slip['slip_id']; ?>">
                                            <button type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                                title="Generate PDF">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <!-- Mark as Responded -->
                                    <?php if ($slip['status'] === 'issued'): ?>
                                    <button onclick="markResponded(<?php echo $slip['slip_id']; ?>)" 
                                        class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors"
                                        title="Mark as Responded">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- Cancel -->
                                    <?php if ($slip['status'] === 'issued'): ?>
                                    <form method="POST" class="inline" 
                                        onsubmit="return confirm('Cancel this call-up slip?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="slip_id" value="<?php echo $slip['slip_id']; ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <button type="submit" 
                                            class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" 
                                            title="Cancel">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <!-- Delete (Admin only) -->
                                    <?php if ($currentUser['role'] === 'admin'): ?>
                                    <form method="POST" class="inline" 
                                        onsubmit="return confirm('⚠️ WARNING: This will permanently delete this call-up slip. This action cannot be undone. Are you sure?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="slip_id" value="<?php echo $slip['slip_id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" 
                                            class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-red-700 hover:bg-red-100 rounded-lg transition-colors" 
                                            title="Delete (Admin Only)">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Mark Responded Modal -->
<div id="respondModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Mark as Responded</h3>
        </div>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="slip_id" id="respondSlipId">
            <input type="hidden" name="action" value="mark_responded">
            
            <div class="px-6 py-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Response Notes (Optional)
                </label>
                <textarea name="response_notes" 
                    class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" 
                    rows="4"
                    placeholder="Enter any notes about the response..."></textarea>
            </div>
            
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end gap-2 rounded-b-lg">
                <button type="button" 
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                    onclick="closeRespondModal()">
                    Cancel
                </button>
                <button type="submit" 
                    class="inline-flex items-center px-4 py-2 bg-green-500 text-white text-sm font-medium rounded-lg hover:bg-green-600 transition-colors shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Show inline loading spinner in the button row
function showInlineLoader(form, event) {
    const button = form.querySelector('button[type="submit"]');
    const originalContent = button.innerHTML;
    
    // Replace button content with spinner
    button.innerHTML = `
        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
    `;
    button.disabled = true;
    button.classList.add('opacity-50', 'cursor-not-allowed');
    button.title = 'Generating PDF...';
    
    // Store original content for potential restoration
    button.dataset.originalContent = originalContent;
}

function markResponded(slipId) {
    document.getElementById('respondSlipId').value = slipId;
    const modal = document.getElementById('respondModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeRespondModal() {
    const modal = document.getElementById('respondModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Close modal on outside click
document.getElementById('respondModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeRespondModal();
    }
});

// Auto-trigger PDF generation if redirected from creation
<?php if (isset($_SESSION['generate_pdf_slip_id'])): ?>
const slipId = <?php echo intval($_SESSION['generate_pdf_slip_id']); ?>;
<?php unset($_SESSION['generate_pdf_slip_id']); ?>

// Auto-submit the PDF form
window.addEventListener('load', function() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.onsubmit = function(e) { showInlineLoader(this, e); };
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?php echo Security::generateCSRFToken(); ?>';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'generate_pdf';
    
    const slipIdInput = document.createElement('input');
    slipIdInput.type = 'hidden';
    slipIdInput.name = 'slip_id';
    slipIdInput.value = slipId;
    
    const submitButton = document.createElement('button');
    submitButton.type = 'submit';
    submitButton.style.display = 'none';
    
    form.appendChild(csrfInput);
    form.appendChild(actionInput);
    form.appendChild(slipIdInput);
    form.appendChild(submitButton);
    document.body.appendChild(form);
    submitButton.click();
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
