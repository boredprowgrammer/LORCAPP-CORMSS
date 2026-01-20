<?php
/**
 * LORCAPP
 * Print Record using official R2-01 HTML template
 * Accessible from both LORCAPP and CORegistry with proper authentication
 */

// Start output buffering to catch any errors
ob_start();

// Suppress all errors in production
error_reporting(0);
ini_set('display_errors', 0);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/encryption.php';
require_once 'includes/photo.php';
require_once 'includes/settings.php';

// Check if accessed from CORegistry (has CORegistry session)
$fromCORegistry = false;
if (isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    $fromCORegistry = true;
    // Verify user is authenticated
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        die('Unauthorized access. Please log in.');
    }
    // Load security functions needed for record validation
    require_once 'includes/security.php';
} else {
    // Use LORCAPP authentication
    require_once 'includes/security.php';
    require_once 'includes/auth.php';
    
    startSecureSession();
    
    // Require authentication to access this page
    requireAuth();
}

// Rate limit printing to prevent mass data extraction (max 12 prints per 5 minutes)
if (!$fromCORegistry && !checkRateLimit('record_print', 12, 300)) {
    logSecurityEvent('RATE_LIMIT_EXCEEDED', ['action' => 'record_print', 'user' => $_SESSION['admin_username'] ?? 'unknown']);
    die('
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Rate Limit Exceeded</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-red-50 min-h-screen flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-2xl p-8 max-w-md w-full text-center border-t-4 border-red-500">
            <h1 class="text-3xl font-bold text-gray-900 mb-4">Too Many Print Requests</h1>
            <p class="text-gray-600 mb-6">You are printing records too quickly. Please wait a few minutes before trying again.</p>
            <a href="dashboard.php" class="inline-block bg-red-500 hover:bg-red-600 text-white font-semibold py-3 px-6 rounded-lg transition">
                Back to Dashboard
            </a>
        </div>
    </body>
    </html>
    ');
}

$conn = getDbConnection();
$id = isset($_GET['id']) ? sanitize($_GET['id']) : '';

// Validate record ID to prevent enumeration attacks
if (!validateRecordId($id)) {
    logSecurityEvent('RECORD_PRINT_DENIED', [
        'reason' => 'invalid_id',
        'id' => $id,
        'user' => $_SESSION['admin_username'] ?? 'unknown'
    ]);
    die("Invalid record ID");
}

// No need to cast to int - ID is now VARCHAR

// Check if record exists before proceeding
if (!recordExists($conn, $id)) {
    logSecurityEvent('RECORD_PRINT_DENIED', [
        'reason' => 'record_not_found',
        'id' => $id,
        'user' => $_SESSION['admin_username'] ?? 'unknown'
    ]);
    die("Record not found");
}

$query = "SELECT * FROM r201_members WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $id); // Changed from "i" to "s" for VARCHAR
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Record not found");
}

$record = $result->fetch_assoc();

// Decrypt encrypted name fields
$record = decryptRecordNames($record);

// Log data access for audit trail (GDPR compliance)
logDataAccess('RECORD_PRINTED', $id, $_SESSION['admin_id'] ?? null, [
    'record_name' => $record['given_name'] ?? 'Unknown',
    'access_type' => 'print_preview'
]);

// Helper functions
function val($value) {
    return !empty($value) ? htmlspecialchars($value) : '';
}

function formatDate($date) {
    return !empty($date) ? date('m/d/Y', strtotime($date)) : '';
}

// Load the R2-01 HTML template
$templatePath = 'R2-01/input-html.html';
if (!file_exists($templatePath)) {
    die("Template file not found: $templatePath");
}

$html = file_get_contents($templatePath);

// Fix image paths to be relative to the admin folder
$html = str_replace('src="target001.png"', 'src="R2-01/target001.png"', $html);
$html = str_replace('src="target002.png"', 'src="R2-01/target002.png"', $html);

// Replace placeholder content with actual data
// We'll inject data using JavaScript after page load to maintain the exact HTML structure
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>R2-01 Print - Record <?php echo htmlspecialchars($id); ?></title>
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 0.5in; size: A4; }
        }
        .print-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            background: #ffffff;
            padding: 16px;
            border-radius: 0;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.1);
            display: flex;
            gap: 8px;
            backdrop-filter: blur(10px);
        }
        .print-btn {
            background: #000000;
            color: #ffffff;
            border: none;
            padding: 14px 28px;
            border-radius: 0;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .print-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        .print-btn:hover::before {
            left: 100%;
        }
        .print-btn:hover {
            background: #333333;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
        }
        .print-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .print-btn:disabled {
            background: #e0e0e0;
            color: #999999;
            cursor: not-allowed;
            opacity: 0.5;
            box-shadow: none;
        }
        .print-btn:disabled:hover {
            transform: none;
            box-shadow: none;
            background: #e0e0e0;
        }
        .print-btn:disabled::before {
            display: none;
        }
        /* Style for editable fields */
        .editable-field {
            background: #ffffcc;
            border-bottom: 1px solid #000;
            padding: 2px 5px;
            min-height: 16px;
            display: inline-block;
            min-width: 100px;
        }
        @media print {
            .editable-field {
                background: transparent;
            }
            
            /* Ensure pages print correctly */
            body {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Page breaks for back-to-back printing - Page 2 prints first */
            #page2-div {
                page-break-after: always;
                page-break-inside: avoid;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            #page1-div {
                page-break-before: always;
                page-break-after: avoid;
                page-break-inside: avoid;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Ensure overlaid text is visible */
            #page1-div p, #page2-div p {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            
            /* Set proper page size to fit 918x1404 exactly - ONLY 2 pages */
            @page {
                size: 918px 1404px;
                margin: 0;
            }
            
            /* Ensure only 2 pages print */
            html, body {
                width: 918px;
                height: auto;
                overflow: visible;
            }
            
            /* Force page break between pages */
            #page1-div {
                page-break-after: always;
            }
            
            #page2-div {
                page-break-after: avoid;
            }
            
            /* Hide anything that's not the two pages */
            body > *:not(#page1-div):not(#page2-div) {
                display: none !important;
            }
        }
        
        /* Positioning adjustments to match form fields */
        #page1-div, #page2-div {
            position: relative;
            width: 918px;
            height: 1404px;
            margin: 0 auto;
            padding: 0;
            overflow: hidden;
            background: white;
        }
        
        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }
        
        @media screen {
            #page1-div {
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <button class="print-btn" id="printBtn" onclick="showPrintGuide()" disabled>Loading...</button>
        <button class="print-btn" id="pdfBtn" onclick="savePDF()" disabled>Save PDF</button>
        <button class="print-btn" onclick="window.close()">Close</button>
        <button class="print-btn" onclick="window.location.href='../launchpad.php'">Back to Launchpad</button>
    </div>

    <!-- Print Guide Modal -->
    <div id="printGuideModal">
        <div class="print-guide-content">
            <!-- Step 1: Print Page 1 -->
            <div id="step1">
                <div class="step-indicator">Step 1 of 3</div>
                <h2>Print Page 1 (Front)</h2>
                <p>Click the button below to print the first page of the R2-01 form.</p>
                <button class="guide-button" onclick="printPage1()">Print Page 1</button>
                <button class="guide-button secondary" onclick="closePrintGuide()">Cancel</button>
            </div>

            <!-- Step 2: Flip Instructions -->
            <div id="step2" style="display: none;">
                <div class="step-indicator">Step 2 of 3</div>
                <h2>Flip the Paper</h2>
                <p>Take the printed page from your printer and flip it over.<br>
                Place it back in the paper tray with the printed side facing down.</p>
                <button class="guide-button" onclick="showStep3()">I've Flipped the Paper</button>
                <button class="guide-button secondary" onclick="showStep1()">Back</button>
                <button class="guide-button secondary" onclick="closePrintGuide()">Cancel</button>
            </div>

            <!-- Step 3: Print Page 2 -->
            <div id="step3" style="display: none;">
                <div class="step-indicator">Step 3 of 3</div>
                <h2>Print Page 2 (Back)</h2>
                <p>Now print the second page on the back of the paper.</p>
                <button class="guide-button" onclick="printPage2()">Print Page 2</button>
                <button class="guide-button secondary" onclick="showStep2()">Back</button>
                <button class="guide-button secondary" onclick="closePrintGuide()">Cancel</button>
            </div>

            <!-- Completion Step -->
            <div id="stepComplete" style="display: none;">
                <div class="step-indicator">Complete</div>
                <h2>Success!</h2>
                <p>Your R2-01 form has been printed on both sides.<br>
                Check that both pages are correctly aligned.</p>
                <button class="guide-button" onclick="closePrintGuide()">Done</button>
                <button class="guide-button secondary" onclick="showStep1()">Print Again</button>
            </div>
        </div>
    </div>

    <style>
        #printGuideModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }

        .print-guide-content {
            background: #ffffff;
            border-radius: 0;
            padding: 48px;
            max-width: 540px;
            width: 90%;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(0, 0, 0, 0.1);
            text-align: center;
            animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .print-guide-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: #000000;
        }

        .print-guide-content h2 {
            color: #000000;
            margin-bottom: 16px;
            font-size: 32px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            line-height: 1.2;
        }

        .step-indicator {
            background: #000000;
            color: #ffffff;
            padding: 10px 24px;
            border-radius: 0;
            display: inline-block;
            margin-bottom: 24px;
            font-weight: 800;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .print-guide-content p {
            color: #444444;
            font-size: 16px;
            line-height: 1.8;
            margin-bottom: 36px;
            font-weight: 400;
        }

        .guide-button {
            background: #000000;
            color: #ffffff;
            border: none;
            padding: 16px 42px;
            border-radius: 0;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            margin: 6px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }

        .guide-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .guide-button:hover::before {
            left: 100%;
        }

        .guide-button:hover {
            background: #333333;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .guide-button:active {
            transform: translateY(0);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
        }

        .guide-button.secondary {
            background: #f5f5f5;
            color: #000000;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .guide-button.secondary:hover {
            background: #e8e8e8;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

    <!-- Load the R2-01 HTML Template - SWAPPED FOR BACK-TO-BACK PRINTING -->
    <?php 
    // Parse HTML to swap page order for back-to-back printing
    // Extract page2-div and page1-div, then output page2 first
    if (preg_match('/<div[^>]*id="page2-div"[^>]*>.*?<\/div>\s*<\/div>/s', $html, $page2Match) && 
        preg_match('/<div[^>]*id="page1-div"[^>]*>.*?<\/div>\s*<\/div>/s', $html, $page1Match)) {
        // Output Page 2 first, then Page 1 (for back-to-back printing)
        echo $page2Match[0];
        echo $page1Match[0];
    } else {
        // Fallback: output as-is if parsing fails
        echo $html;
    }
    ?>

    <script>
        // PHP data as JavaScript object
        const recordData = {
            // Personal Information
            given_name: <?php echo json_encode(val($record['given_name'])); ?>,
            mother_surname: <?php echo json_encode(val($record['mother_surname'])); ?>,
            father_surname: <?php echo json_encode(val($record['father_surname'])); ?>,
            husband_surname: <?php echo json_encode(val($record['husband_surname'])); ?>,
            birth_date: <?php echo json_encode(formatDate($record['birth_date'])); ?>,
            birth_place: <?php echo json_encode(val($record['birth_place'])); ?>,
            gender: <?php echo json_encode(val($record['gender'])); ?>,
            blood_type: <?php echo json_encode(val($record['blood_type'])); ?>,
            civil_status: <?php echo json_encode(val($record['civil_status'])); ?>,
            ethnic_origin: <?php echo json_encode(val($record['ethnic_origin'])); ?>,
            citizenship: <?php echo json_encode(val($record['citizenship'])); ?>,
            languages_spoken: <?php echo json_encode(val($record['languages_spoken'])); ?>,
            present_address: <?php echo json_encode(val($record['present_address'])); ?>,
            other_address: <?php echo json_encode(val($record['other_address'])); ?>,
            
            // Contact
            landline_numbers: <?php echo json_encode(val($record['landline_numbers'])); ?>,
            mobile_numbers: <?php echo json_encode(val($record['mobile_numbers'])); ?>,
            email_accounts: <?php echo json_encode(val($record['email_accounts'])); ?>,
            facebook: <?php echo json_encode(val($record['facebook'])); ?>,
            twitter: <?php echo json_encode(val($record['twitter'])); ?>,
            instagram: <?php echo json_encode(val($record['instagram'])); ?>,
            linkedin: <?php echo json_encode(val($record['linkedin'])); ?>,
            tumblr: <?php echo json_encode(val($record['tumblr'])); ?>,
            other_social_media: <?php echo json_encode(val($record['other_social_media'])); ?>,
            
            // Family
            father_name: <?php echo json_encode(val($record['father_name'])); ?>,
            father_address: <?php echo json_encode(val($record['father_address'])); ?>,
            father_religion: <?php echo json_encode(val($record['father_religion'])); ?>,
            father_church_office: <?php echo json_encode(val($record['father_church_office'])); ?>,
            mother_name: <?php echo json_encode(val($record['mother_name'])); ?>,
            mother_address: <?php echo json_encode(val($record['mother_address'])); ?>,
            mother_religion: <?php echo json_encode(val($record['mother_religion'])); ?>,
            mother_church_office: <?php echo json_encode(val($record['mother_church_office'])); ?>,
            siblings: <?php echo json_encode(jsonDecode($record['siblings']) ?? []); ?>,
            
            // Household
            spouse: <?php echo json_encode(jsonDecode($record['spouse']) ?? []); ?>,
            children: <?php echo json_encode(jsonDecode($record['children']) ?? []); ?>,
            
            // Education
            highest_educational_attainment: <?php echo json_encode(val($record['highest_educational_attainment'])); ?>,
            education: <?php echo json_encode(jsonDecode($record['education']) ?? []); ?>,
            
            // Employment
            work_nature: <?php echo json_encode(val($record['work_nature'])); ?>,
            company_name: <?php echo json_encode(val($record['company_name'])); ?>,
            position: <?php echo json_encode(val($record['position'])); ?>,
            work_address: <?php echo json_encode(val($record['work_address'])); ?>,
            work_contact_numbers: <?php echo json_encode(val($record['work_contact_numbers'])); ?>,
            
            // References
            character_references: <?php echo json_encode(jsonDecode($record['character_references']) ?? []); ?>,
            
            // Religious
            membership_category: <?php echo json_encode(val($record['membership_category'])); ?>,
            evangelist: <?php echo json_encode(val($record['evangelist'])); ?>,
            baptism_date: <?php echo json_encode(formatDate($record['baptism_date'])); ?>,
            baptism_place: <?php echo json_encode(val($record['baptism_place'])); ?>,
            first_locale_district: <?php echo json_encode(val($record['first_locale_district'])); ?>,
            former_religion: <?php echo json_encode(val($record['former_religion'])); ?>,
            former_religion_offices: <?php echo json_encode(val($record['former_religion_offices'])); ?>,
            church_offices: <?php echo json_encode(jsonDecode($record['church_offices']) ?? []); ?>,
            
            // Photo (base64) - Decrypt for printing
            photo_base64: <?php 
                $decrypted_photo = '';
                if (!empty($record['photo_data'])) {
                    $decrypted_photo = decryptPhotoBase64($record['photo_data']);
                    if ($decrypted_photo === false) {
                        $decrypted_photo = '';
                    }
                }
                echo json_encode($decrypted_photo);
            ?>,
            photo_mime_type: <?php echo json_encode($record['photo_mime_type'] ?? 'image/jpeg'); ?>,
            
            // ID Number - Decrypt for printing
            id_number: <?php 
                $decrypted_id = null;
                if (!empty($record['id_number_encrypted'])) {
                    $decrypted_id = decryptValue($record['id_number_encrypted']);
                }
                echo json_encode($decrypted_id ?? '');
            ?>,
            record_id: <?php echo json_encode($id); ?>,
            
            // Settings - District, Locale, Officers
            district_name: <?php echo json_encode(DISTRICT_NAME); ?>,
            district_code: <?php echo json_encode(DISTRICT_CODE); ?>,
            locale_name: <?php echo json_encode(LOCALE_NAME); ?>,
            locale_code: <?php echo json_encode(LOCALE_CODE); ?>,
            kumuha_ng_tala: <?php echo json_encode(KUMUHA_NG_TALA_NAME); ?>,
            pangulong_kalihim: <?php echo json_encode(PANGULONG_KALIHIM_NAME); ?>,
            pangulong_diakono: <?php echo json_encode(PANGULONG_DIAKONO_NAME); ?>,
            destinado: <?php echo json_encode(DESTINADO_NAME); ?>
        };

        // Function to add text overlay at specific position
        function addTextOverlay(pageId, top, left, text, fontSize = '11px', fontWeight = 'normal') {
            if (!text) return;
            
            const page = document.getElementById(pageId);
            if (!page) return;
            
            const overlay = document.createElement('p');
            overlay.style.cssText = `
                position: absolute;
                top: ${top}px;
                left: ${left}px;
                white-space: nowrap;
                font-size: ${fontSize};
                font-weight: ${fontWeight};
                font-family: Times, serif;
                color: #000;
                margin: 0;
                padding: 0;
            `;
            overlay.textContent = text;
            page.appendChild(overlay);
        }
        
        // Function to add centered text overlay at specific position
        function addCenteredTextOverlay(pageId, top, centerX, width, text, fontSize = '11px', fontWeight = 'normal') {
            if (!text) return;
            
            const page = document.getElementById(pageId);
            if (!page) return;
            
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position: absolute;
                top: ${top}px;
                left: ${centerX - (width/2)}px;
                width: ${width}px;
                text-align: center;
                font-size: ${fontSize};
                font-weight: ${fontWeight};
                font-family: Times, serif;
                color: #000;
                margin: 0;
                padding: 0;
            `;
            overlay.textContent = text;
            page.appendChild(overlay);
        }
        
        // Function to add photo overlay
        function addPhotoOverlay(pageId, top, left, base64Data, mimeType, width = 120, height = 120) {
            if (!base64Data) return;
            
            const page = document.getElementById(pageId);
            if (!page) return;
            
            const photoContainer = document.createElement('div');
            photoContainer.style.cssText = `
                position: absolute;
                top: ${top}px;
                left: ${left}px;
                width: ${width}px;
                height: ${height}px;
                border: 2px solid #000;
                overflow: hidden;
                background: #fff;
            `;
            
            const img = document.createElement('img');
            // Create data URL from base64
            img.src = `data:${mimeType};base64,${base64Data}`;
            img.style.cssText = `
                width: 100%;
                height: 100%;
                object-fit: cover;
            `;
            img.onerror = function() {
                // If image fails to load, show placeholder
                photoContainer.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; font-size: 10px; color: #999;">No Photo</div>';
            };
            
            photoContainer.appendChild(img);
            page.appendChild(photoContainer);
        }

        // Wait for DOM to load
        document.addEventListener('DOMContentLoaded', function() {
            
            // Add 2x2 Photo to Page 1 - Fit within the box with allowance
            // The label is at top:138px;left:714px
            // Photo box: 135px x 135px (smaller to fit nicely with border allowance)
            // Positioned with allowance from the box edges
            if (recordData.photo_base64) {
                addPhotoOverlay('page1-div', 82, 687, recordData.photo_base64, recordData.photo_mime_type, 135, 135);
            }
            
            // ID NUMBER - Display ID Number character by character in individual boxes
            // Exact positions from HTML template at top:272px (adjusted to 280px)
            // Left positions: 567, 589, 610, 632, 654, 675, 697, 718, 740, 762, 783, 805, 826
            if (recordData.id_number) {
                const idBoxPositions = [567, 589, 610, 632, 654, 675, 697, 718, 740, 762, 783, 805, 826];
                const idString = String(recordData.id_number).toUpperCase(); // Convert to uppercase
                const startPos = Math.max(0, 13 - idString.length); // Right-align the ID
                
                for (let i = 0; i < idString.length && i < 13; i++) {
                    const boxIndex = startPos + i;
                    addTextOverlay('page1-div', 280, idBoxPositions[boxIndex], idString[i], '14px', 'normal');
                }
            }
            
            // PAGE 1 - District and Locale Information (Top Section)
            // Distrito (District) - below the label at 236px
            addTextOverlay('page1-div', 252, 86, recordData.district_name, '10px', 'bold');
            
            // Dcode - below the label at 236px
            addTextOverlay('page1-div', 252, 432, recordData.district_code, '10px', 'bold');
            
            // Lokal (Locale) - below the label at 273px
            addTextOverlay('page1-div', 289, 86, recordData.locale_name, '10px', 'bold');
            
            // Lcode - below the label at 273px
            addTextOverlay('page1-div', 289, 432, recordData.locale_code, '10px', 'bold');
            
            // PAGE 1 - Personal Information Overlays
            // Pangalan (Given Name) - around line 344px
            addTextOverlay('page1-div', 360, 86, recordData.given_name, '11px', 'bold');
            
            // Apelyido sa Ina - around line 344px
            addTextOverlay('page1-div', 360, 467, recordData.mother_surname, '11px', 'bold');
            
            // Apelyido sa Ama - around line 390px
            addTextOverlay('page1-div', 406, 86, recordData.father_surname, '11px', 'bold');
            
            // Apelyido sa Asawa - around line 390px
            addTextOverlay('page1-div', 406, 467, recordData.husband_surname, '11px', 'bold');
            
            // Petsa ng Kapanganakan - around line 437px
            addTextOverlay('page1-div', 453, 86, recordData.birth_date, '10px');
            
            // Dako ng Kapanganakan - around line 437px
            addTextOverlay('page1-div', 453, 304, recordData.birth_place, '11px');
            
            // Blood Type - around line 437px
            addTextOverlay('page1-div', 453, 790, recordData.blood_type, '11px');
            
            // Lahi - around line 566px
            addTextOverlay('page1-div', 582, 86, recordData.ethnic_origin, '11px');
            
            // Pagkamamamayan - around line 566px
            addTextOverlay('page1-div', 582, 338, recordData.citizenship, '11px');
            
            // Wikang Nalalaman - around line 566px
            addTextOverlay('page1-div', 582, 587, recordData.languages_spoken, '11px');
            
            // Kasalukuyang Tirahan - around line 612px
            addTextOverlay('page1-div', 628, 86, recordData.present_address, '11px');
            
            // Iba pang Tirahan - around line 612px
            addTextOverlay('page1-div', 628, 467, recordData.other_address, '11px');
            
            // Contact Information - starting around line 693px
            addTextOverlay('page1-div', 709, 86, recordData.landline_numbers, '11px');
            addTextOverlay('page1-div', 709, 283, recordData.mobile_numbers, '11px');
            addTextOverlay('page1-div', 709, 467, recordData.email_accounts, '11px');
            
            // Social Media - starting around line 759px
            addTextOverlay('page1-div', 775, 86, recordData.facebook, '11px');
            addTextOverlay('page1-div', 775, 467, recordData.twitter, '11px');
            addTextOverlay('page1-div', 808, 86, recordData.instagram, '11px');
            addTextOverlay('page1-div', 808, 467, recordData.linkedin, '11px');
            addTextOverlay('page1-div', 843, 86, recordData.tumblr, '11px');
            addTextOverlay('page1-div', 843, 467, recordData.other_social_media, '11px');
            
            // Family Background - starting around line 893px
            addTextOverlay('page1-div', 909, 86, recordData.father_name, '11px');
            addTextOverlay('page1-div', 909, 467, recordData.mother_name, '11px');
            addTextOverlay('page1-div', 959, 86, recordData.father_address, '11px');
            addTextOverlay('page1-div', 959, 467, recordData.mother_address, '11px');
            addTextOverlay('page1-div', 1006, 86, recordData.father_religion, '11px');
            addTextOverlay('page1-div', 1006, 277, recordData.father_church_office, '11px');
            addTextOverlay('page1-div', 1006, 467, recordData.mother_religion, '11px');
            addTextOverlay('page1-div', 1006, 664, recordData.mother_church_office, '11px');
            
            // Siblings table - starting around line 1108px
            if (recordData.siblings && recordData.siblings.length > 0) {
                let siblingTop = 1108;
                recordData.siblings.slice(0, 8).forEach((sibling, index) => {
                    addTextOverlay('page1-div', siblingTop, 152, sibling.name || '', '10px');
                    addTextOverlay('page1-div', siblingTop, 337, sibling.religion || '', '10px');
                    addTextOverlay('page1-div', siblingTop, 481, sibling.locale_district || '', '10px');
                    addTextOverlay('page1-div', siblingTop, 678, sibling.tungkulin || '', '10px');
                    siblingTop += 26;
                });
            }
            
            // ==================== PAGE 2 OVERLAYS ====================
            
            // F. SPOUSE INFORMATION (if married) - starting at 127px
            if (recordData.spouse && Object.keys(recordData.spouse).length > 0) {
                // Pangalan (Name) - 127px
                addTextOverlay('page2-div', 145, 86, recordData.spouse.name || '', '10px');
                // Relihiyon (Religion) - 127px 
                addTextOverlay('page2-div', 145, 467, recordData.spouse.religion || '', '10px');
                // Tungkulin (Church Office) - 127px
                addTextOverlay('page2-div', 145, 656, recordData.spouse.tungkulin || '', '10px');
                // Kasalukuyang Tirahan (Present Address) - 160px
                addTextOverlay('page2-div', 178, 86, recordData.spouse.address || '', '10px');
                // Petsa ng Kasal (Date of Marriage) - 194px
                addTextOverlay('page2-div', 212, 86, recordData.spouse.marriage_date || '', '10px');
                // Saan Ikinasal (Place of Marriage) - 194px
                addTextOverlay('page2-div', 212, 280, recordData.spouse.marriage_place || '', '10px');
                // Pangalan ng nagkasal (Officiating minister) - 194px
                addTextOverlay('page2-div', 212, 513, recordData.spouse.officiating_minister || '', '9px');
                // Iba pang Impormasyon (Other Information) - 241px
                addTextOverlay('page2-div', 259, 86, recordData.spouse.other_info || '', '10px');
            }
            
            // G. CHILDREN (table starting around 340px) - Children table rows
            if (recordData.children && recordData.children.length > 0) {
                let childTop = 340;
                recordData.children.slice(0, 5).forEach((child, index) => {
                    // Pangalan (Name) - column starts at 152px
                    addTextOverlay('page2-div', childTop, 152, child.name || '', '9px');
                    // Relihiyon (Religion) - column at 337px
                    addTextOverlay('page2-div', childTop, 337, child.religion || '', '9px');
                    // Kinatatalaang Lokal/Distrito - column at 481px
                    addTextOverlay('page2-div', childTop, 481, child.locale_district || '', '9px');
                    // Tungkulin (Church Office) - column at 678px
                    addTextOverlay('page2-div', childTop, 678, child.tungkulin || '', '9px');
                    childTop += 22; // Row spacing
                });
            }
            
            // H. EDUCATIONAL BACKGROUND - section starts at 460px
            // Pinakamataas na Inabot na Pinag-aralan (Highest Educational Attainment) - 488px
            // Input line at 503px, left: 349px
            // Note: This field might be left blank if the data is in the level column instead
            addTextOverlay('page2-div', 503, 349, '', '10px');
            
            // Education details section - Right side vertical layout
            // Paaralan (School) - Label at 482px, input below around 500px
            // Mga Kurso (Courses) - Label at 519px, input below around 537px  
            // Antas (Level) - Label at 553px, input below around 571px
            // Taon (Year) - Label at 553px (same row as Level), input at 571px
            if (recordData.education && recordData.education.length > 0) {
                // Get first education entry
                let edu = recordData.education[0];
                
                // Paaralan (School) - below label at 482px, positioned at 467px
                addTextOverlay('page2-div', 500, 467, edu.school || edu.school_name || '', '9px');
                
                // Mga Kurso (Courses) - below label at 519px, positioned at 467px
                addTextOverlay('page2-div', 537, 467, edu.courses || edu.degree_course || '', '9px');
                
                // Antas (Level) - Use highest_educational_attainment if level is empty
                // This is where "Grade 12" should appear
                let levelText = edu.level || recordData.highest_educational_attainment || '';
                addTextOverlay('page2-div', 571, 467, levelText, '9px');
                
                // Taon (Year) - same row as Level, positioned at 659px (right column)
                addTextOverlay('page2-div', 571, 659, edu.year || edu.inclusive_years || '', '9px');
            }
            
            // I. EMPLOYMENT BACKGROUND - section starts at 598px
            // Employment type checkboxes - Left column starting at 630px, left: 90px
 
            
            // Uri ng Hanapbuhay (Nature of Work) - 620px
            addTextOverlay('page2-div', 638, 304, recordData.work_nature, '10px');
            // Pangalan ng Kompanya (Name of Company) - 654px
            addTextOverlay('page2-div', 672, 304, recordData.company_name, '10px');
            // Posisyon (Position) - 654px right side
            addTextOverlay('page2-div', 672, 635, recordData.position, '10px');
            // Dako (Address) - 687px
            addTextOverlay('page2-div', 705, 304, recordData.work_address, '10px');
            // Contact Number/s - 687px right side
            addTextOverlay('page2-div', 705, 635, recordData.work_contact_numbers, '10px');
            
            // J. CHARACTER REFERENCES - section starts at 733px, table at 758px
            if (recordData.character_references && recordData.character_references.length > 0) {
                let refTop = 782; // First row at 782px
                recordData.character_references.slice(0, 3).forEach((ref, index) => {
                    // Pangalan (Name) - column at 157px
                    addTextOverlay('page2-div', refTop, 157, ref.name || '', '9px');
                    // Tirahan (Address) - column at 437px
                    addTextOverlay('page2-div', refTop, 437, ref.address || '', '9px');
                    // Contact Number/s - column at 690px
                    addTextOverlay('page2-div', refTop, 690, ref.contact || '', '9px');
                    refTop += 22; // Row spacing
                });
            }
            
            // K. RELIGIOUS BACKGROUND - section starts at 856px
            // Uri ng Kaanib (Membership Category) checkboxes
            
            // Then add checkmark for selected category
            const membershipMap = {
                'Handog': [911, 97],                        // Line 908px - Handog
                'Handog - Nakatala': [911, 97],             // Line 908px - Handog – Nakatala sa Iglesia
                'Handog - Di nakatala': [937, 97],          // Line 934px - Handog – Hindi Nakatala
                'Hindi Handog': [963, 97]                   // Line 960px - Hindi Handog
            };
            if (recordData.membership_category && membershipMap[recordData.membership_category]) {
                const [top, left] = membershipMap[recordData.membership_category];
                addTextOverlay('page2-div', top, left + 2, '✓', '11px', 'bold');
            }
            
            // Nagdoktrina (Evangelist) - 881px
            addTextOverlay('page2-div', 899, 321, recordData.evangelist, '10px');
            // Petsa ng Bautismo (Date of Baptism) - 881px
            addTextOverlay('page2-div', 899, 605, recordData.baptism_date, '10px');
            // Dako ng Bautismo (Place of Baptism) - 930px
            addTextOverlay('page2-div', 948, 321, recordData.baptism_place, '10px');
            // Unang Kinatalaang Lokal at Distrito - below the label, centered under it
            addTextOverlay('page2-div', 961, 605, recordData.first_locale_district, '10px');
            // Dating Relihiyon (Former Religion) - 984px
            addTextOverlay('page2-div', 1002, 83, recordData.former_religion, '10px');
            // Mga Tungkulin sa Dating Relihiyon - 984px
            addTextOverlay('page2-div', 1002, 423, recordData.former_religion_offices, '10px');
            
            // L. CHURCH OFFICES HELD - section starts at 1026px, table at 1050px
            // This appears to be a 2-column layout: Tungkulin/Taon | Tungkulin/Taon
            if (recordData.church_offices && recordData.church_offices.length > 0) {
                let officeTop = 1073; // First row at 1073px
                let leftColumn = true;
                
                recordData.church_offices.slice(0, 8).forEach((office, index) => {
                    if (leftColumn) {
                        // Left column - Tungkulin at 149px, Taon at 353px
                        addTextOverlay('page2-div', officeTop, 149, office.office || '', '9px');
                        addTextOverlay('page2-div', officeTop, 353, office.inclusive_years || '', '9px');
                        leftColumn = false;
                    } else {
                        // Right column - Tungkulin at 542px, Taon at 752px
                        addTextOverlay('page2-div', officeTop, 542, office.office || '', '9px');
                        addTextOverlay('page2-div', officeTop, 752, office.inclusive_years || '', '9px');
                        leftColumn = true;
                        officeTop += 24; // Move to next row after filling both columns
                    }
                });
            }
            
            // M. OFFICER SIGNATURES - Bottom of Page 2 (around 1260-1289px)
            // Names should be centered above their respective titles
            
            // Kumuha ng Tala (Registering Officer) - centered at ~165px with 150px width
            addCenteredTextOverlay('page2-div', 1265, 165, 150, recordData.kumuha_ng_tala, '10px', 'bold');
            
            // Pangulong Kalihim (Head Secretary) - centered at ~360px with 150px width
            addCenteredTextOverlay('page2-div', 1265, 360, 150, recordData.pangulong_kalihim, '10px', 'bold');
            
            // Pangulong Diakono/KSP (Head Deacon) - centered at ~555px with 170px width
            addCenteredTextOverlay('page2-div', 1265, 555, 170, recordData.pangulong_diakono, '10px', 'bold');
            
            // Pastor/Destinado (Resident Minister) - centered at ~740px with 150px width
            addCenteredTextOverlay('page2-div', 1265, 740, 150, recordData.destinado, '10px', 'bold');
            
            // Enable buttons once overlay is complete
            const printBtn = document.getElementById('printBtn');
            const pdfBtn = document.getElementById('pdfBtn');
            printBtn.disabled = false;
            printBtn.textContent = '🖨️ Print';
            pdfBtn.disabled = false;
        });
        
        // Show print guide modal
        function showPrintGuide() {
            document.getElementById('printGuideModal').style.display = 'flex';
            showStep1();
        }

        // Close print guide modal
        function closePrintGuide() {
            document.getElementById('printGuideModal').style.display = 'none';
            showStep1(); // Reset to step 1 for next time
        }

        // Navigation functions
        function showStep1() {
            document.getElementById('step1').style.display = 'block';
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step3').style.display = 'none';
            document.getElementById('stepComplete').style.display = 'none';
        }

        function showStep2() {
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'block';
            document.getElementById('step3').style.display = 'none';
            document.getElementById('stepComplete').style.display = 'none';
        }

        function showStep3() {
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step3').style.display = 'block';
            document.getElementById('stepComplete').style.display = 'none';
        }

        function showStepComplete() {
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step3').style.display = 'none';
            document.getElementById('stepComplete').style.display = 'block';
        }

        // Print Page 1 only
        function printPage1() {
            // Hide page 2 temporarily
            const page2 = document.getElementById('page2-div');
            const originalDisplay = page2.style.display;
            page2.style.display = 'none';
            
            setTimeout(function() {
                window.print();
                // Restore page 2 visibility
                page2.style.display = originalDisplay;
                // Move to step 2 after print dialog closes
                setTimeout(showStep2, 500);
            }, 100);
        }

        // Print Page 2 only
        function printPage2() {
            // Hide page 1 temporarily
            const page1 = document.getElementById('page1-div');
            const originalDisplay = page1.style.display;
            page1.style.display = 'none';
            
            setTimeout(function() {
                window.print();
                // Restore page 1 visibility
                page1.style.display = originalDisplay;
                // Show completion message after print dialog closes
                setTimeout(showStepComplete, 500);
            }, 100);
        }
        
        // Legacy print function (prints both pages at once)
        function printPage() {
            setTimeout(function() {
                window.print();
            }, 100);
        }
        
        // Save as PDF function - Using html2canvas for high quality capture
        async function savePDF() {
            const pdfBtn = document.getElementById('pdfBtn');
            const originalText = pdfBtn.innerHTML;
            
            try {
                pdfBtn.disabled = true;
                pdfBtn.innerHTML = '⏳ Loading libraries...';
                
                // Load html2canvas first
                if (!window.html2canvas) {
                    const script1 = document.createElement('script');
                    script1.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                    document.head.appendChild(script1);
                    
                    await new Promise((resolve, reject) => {
                        script1.onload = () => {
                            console.log('html2canvas loaded');
                            resolve();
                        };
                        script1.onerror = () => reject(new Error('Failed to load html2canvas'));
                    });
                }
                
                // Load jsPDF
                if (!window.jspdf) {
                    const script2 = document.createElement('script');
                    script2.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
                    document.head.appendChild(script2);
                    
                    await new Promise((resolve, reject) => {
                        script2.onload = () => {
                            console.log('jsPDF loaded');
                            resolve();
                        };
                        script2.onerror = () => reject(new Error('Failed to load jsPDF'));
                    });
                }
                
                const { jsPDF } = window.jspdf;
                
                // Get member name for filename
                const memberName = recordData.given_name || 'Member';
                const recordId = <?php echo json_encode($id); ?>;
                const today = new Date().toISOString().split('T')[0].replace(/-/g, '');
                
                // Create PDF
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'px',
                    format: [918, 1404],
                    compress: true
                });
                
                pdfBtn.innerHTML = '⏳ Capturing Page 1...';
                
                // Capture Page 1
                const page1 = document.getElementById('page1-div');
                const canvas1 = await html2canvas(page1, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: true,
                    backgroundColor: '#ffffff',
                    width: 918,
                    height: 1404,
                    logging: false
                });
                
                const imgData1 = canvas1.toDataURL('image/jpeg', 0.95);
                pdf.addImage(imgData1, 'JPEG', 0, 0, 918, 1404);
                
                pdfBtn.innerHTML = '⏳ Capturing Page 2...';
                
                // Capture Page 2
                pdf.addPage([918, 1404]);
                const page2 = document.getElementById('page2-div');
                const canvas2 = await html2canvas(page2, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: true,
                    backgroundColor: '#ffffff',
                    width: 918,
                    height: 1404,
                    logging: false
                });
                
                const imgData2 = canvas2.toDataURL('image/jpeg', 0.95);
                pdf.addImage(imgData2, 'JPEG', 0, 0, 918, 1404);
                
                // Save the PDF
                pdfBtn.innerHTML = '⏳ Saving...';
                const filename = `R2-01_${memberName.replace(/[^a-zA-Z0-9]/g, '_')}_${recordId}_${today}.pdf`;
                pdf.save(filename);
                
                pdfBtn.innerHTML = '✅ PDF Saved!';
                setTimeout(() => {
                    pdfBtn.innerHTML = originalText;
                    pdfBtn.disabled = false;
                }, 2000);
                
            } catch (error) {
                console.error('PDF generation error:', error);
                alert('Error generating PDF: ' + error.message);
                pdfBtn.innerHTML = originalText;
                pdfBtn.disabled = false;
            }
        }
        
        // Auto-print option (uncomment if you want automatic printing)
        // window.addEventListener('load', function() {
        //     setTimeout(printPage, 500);
        // });
    </script>
</body>
</html>
