<?php
/**
 * Tarheta Control - CSV Import
 * Import legacy registry data from CSV files
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_add_officers'); // Only users who can add officers can import

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';
$importResults = null;
$csvHeaders = null;
$csvPreview = null;
$uploadedFile = null;

// Step 1: Upload CSV and show mapper
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && !isset($_POST['column_mapping'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken, 'tarheta_import')) {
        $error = 'Invalid security token.';
    } else {
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        
        // Validation
        if (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (!hasDistrictAccess($districtCode) || !hasLocalAccess($localCode)) {
            $error = 'You do not have access to this district/local.';
        } elseif ($_FILES['csv_file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['csv_file']['error'] === UPLOAD_ERR_FORM_SIZE) {
            $error = 'File is too large. Maximum upload size is ' . ini_get('upload_max_filesize') . '. Your file may be truncated. Please increase PHP upload limits or split your CSV file.';
        } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error uploading file (Code: ' . $_FILES['csv_file']['error'] . ').';
        } else {
            try {
                $file = $_FILES['csv_file']['tmp_name'];
                $fileSize = $_FILES['csv_file']['size'];
                $postMaxSize = ini_get('post_max_size');
                
                // Log file size for debugging
                error_log("CSV Upload - File size: " . round($fileSize / 1024 / 1024, 2) . " MB, post_max_size: $postMaxSize");
                
                $handle = fopen($file, 'r');
                
                if ($handle === false) {
                    throw new Exception('Could not open CSV file.');
                }
                
                // Read header row
                $headers = fgetcsv($handle, 0, ',', '"', '\\');
                
                if ($headers === false) {
                    throw new Exception('CSV file is empty or invalid.');
                }
                
                // Read first 3 rows for preview
                $preview = [];
                for ($i = 0; $i < 3; $i++) {
                    $row = fgetcsv($handle, 0, ',', '"', '\\');
                    if ($row !== false) {
                        $preview[] = $row;
                    }
                }
                
                fclose($handle);
                
                // Store file temporarily (copy to temp location)
                $tempFile = sys_get_temp_dir() . '/tarheta_import_' . uniqid() . '.csv';
                copy($_FILES['csv_file']['tmp_name'], $tempFile);
                
                $csvHeaders = $headers;
                $csvPreview = $preview;
                $uploadedFile = $tempFile;
                
                // Store in session for next step
                $_SESSION['tarheta_import'] = [
                    'file' => $tempFile,
                    'headers' => $headers,
                    'district_code' => $districtCode,
                    'local_code' => $localCode,
                    'uploaded_at' => time()
                ];
                
            } catch (Exception $e) {
                $error = 'Error reading CSV: ' . $e->getMessage();
            }
        }
    }
}

// Step 2: Process import with column mapping
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['column_mapping'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken, 'tarheta_import')) {
        $error = 'Invalid security token.';
    } elseif (!isset($_SESSION['tarheta_import'])) {
        $error = 'Session expired. Please upload the file again.';
    } else {
        $importData = $_SESSION['tarheta_import'];
        
        // Check if session is too old (30 minutes)
        if (time() - $importData['uploaded_at'] > 1800) {
            $error = 'Session expired. Please upload the file again.';
            unset($_SESSION['tarheta_import']);
        } else {
            $districtCode = $importData['district_code'];
            $localCode = $importData['local_code'];
            $file = $importData['file'];
            
            // Get column mapping
            $mapping = $_POST['column_mapping'] ?? [];
            
            // Validate required mappings
            $requiredFields = ['last_name', 'first_name', 'registry_number'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (empty($mapping[$field]) || $mapping[$field] === '-1') {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                $error = 'Please map the following required fields: ' . implode(', ', $missingFields);
                $csvHeaders = $importData['headers'];
            } else {
                try {
                    // Set longer execution time for large imports
                    set_time_limit(300); // 5 minutes
                    ini_set('memory_limit', '512M'); // Increase memory limit
                    
                    $handle = fopen($file, 'r');
                    
                    if ($handle === false) {
                        throw new Exception('Could not open CSV file.');
                    }
                    
                    // Skip header row
                    fgetcsv($handle, 10000, ',', '"', '\\');
                    
                    $db->beginTransaction();
                    
                    $batchId = date('YmdHis') . '_' . uniqid();
                    $imported = 0;
                    $skipped = 0;
                    $duplicates = 0;
                    $emptyRows = 0;
                    $errors = [];
                    $rowNumber = 1; // Track row numbers for better error messages
                    $maxRows = 0; // Track total rows read
                    
                    while (($row = fgetcsv($handle, 10000, ',', '"', '\\')) !== false) {
                        $rowNumber++;
                        $maxRows++;
                        
                        try {
                            // Skip empty rows
                            if (empty(array_filter($row))) {
                                $emptyRows++;
                                continue;
                            }
                            
                            // Extract data using mapping
                            $lastName = isset($row[$mapping['last_name']]) ? trim($row[$mapping['last_name']]) : '';
                            $firstName = isset($row[$mapping['first_name']]) ? trim($row[$mapping['first_name']]) : '';
                            $middleName = !empty($mapping['middle_name']) && $mapping['middle_name'] !== '-1' && isset($row[$mapping['middle_name']]) 
                                ? trim($row[$mapping['middle_name']]) : '';
                            $husbandsSurname = !empty($mapping['husbands_surname']) && $mapping['husbands_surname'] !== '-1' && isset($row[$mapping['husbands_surname']]) 
                                ? trim($row[$mapping['husbands_surname']]) : '';
                            $registryNumber = isset($row[$mapping['registry_number']]) ? trim($row[$mapping['registry_number']]) : '';
                            
                            // Validate required fields
                            if (empty($lastName) || empty($firstName) || empty($registryNumber)) {
                                $skipped++;
                                $errors[] = "Row $rowNumber skipped: Missing required data - Last: '" . substr($lastName, 0, 20) . "', First: '" . substr($firstName, 0, 20) . "', Reg: '" . substr($registryNumber, 0, 30) . "'";
                                continue;
                            }
                            
                            // Create hash for duplicate detection (more robust)
                            $registryNumberHash = hash('sha256', strtolower(trim($registryNumber)));
                            $nameHash = hash('sha256', strtolower(trim($lastName . $firstName . $middleName)));
                            
                            // Check for duplicates by registry number (across entire database)
                            $stmt = $db->prepare("
                                SELECT id, district_code, local_code 
                                FROM tarheta_control 
                                WHERE registry_number_hash = ?
                            ");
                            $stmt->execute([$registryNumberHash]);
                            $existing = $stmt->fetch();
                            
                            if ($existing) {
                                $duplicates++;
                                $errors[] = "Row $rowNumber duplicate: Registry number '$registryNumber' already exists (ID: {$existing['id']}, District: {$existing['district_code']}, Local: {$existing['local_code']})";
                                continue;
                            }
                            
                            // Additional check: decrypt and compare names in same district/local (removed, hash is sufficient)
                            // The registry_number_hash check above is sufficient for duplicate detection
                            
                            // Encrypt data with error handling
                            try {
                                $lastNameEnc = Encryption::encrypt($lastName, $districtCode);
                                $firstNameEnc = Encryption::encrypt($firstName, $districtCode);
                                $middleNameEnc = !empty($middleName) ? Encryption::encrypt($middleName, $districtCode) : null;
                                $husbandsSurnameEnc = !empty($husbandsSurname) ? Encryption::encrypt($husbandsSurname, $districtCode) : null;
                                $registryNumberEnc = Encryption::encrypt($registryNumber, $districtCode);
                            } catch (Exception $e) {
                                $skipped++;
                                $errors[] = "Row $rowNumber encryption error: " . $e->getMessage();
                                continue;
                            }
                            
                            // Insert record with error handling
                            try {
                                $stmt = $db->prepare("
                                    INSERT INTO tarheta_control (
                                        last_name_encrypted, first_name_encrypted, middle_name_encrypted,
                                        husbands_surname_encrypted, registry_number_encrypted, registry_number_hash,
                                        district_code, local_code, import_batch, imported_by
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                
                                $stmt->execute([
                                    $lastNameEnc,
                                    $firstNameEnc,
                                    $middleNameEnc,
                                    $husbandsSurnameEnc,
                                    $registryNumberEnc,
                                    $registryNumberHash,
                                    $districtCode,
                                    $localCode,
                                    $batchId,
                                    $currentUser['user_id']
                                ]);
                                
                                $imported++;
                            } catch (Exception $e) {
                                $skipped++;
                                $errors[] = "Row $rowNumber database error: " . $e->getMessage();
                            }
                            
                        } catch (Exception $e) {
                            $errors[] = "Row $rowNumber unexpected error: " . $e->getMessage();
                            $skipped++;
                        }
                    }
                    
                    fclose($handle);
                    @unlink($file); // Delete temp file
                    unset($_SESSION['tarheta_import']);
                    
                    $db->commit();
                    
                    // Log summary
                    error_log("Tarheta Import Summary - Batch: $batchId, Rows Read: $maxRows, Imported: $imported, Duplicates: $duplicates, Empty: $emptyRows, Errors: $skipped");
                    
                    $importResults = [
                        'imported' => $imported,
                        'skipped' => $skipped,
                        'duplicates' => $duplicates,
                        'empty_rows' => $emptyRows,
                        'errors' => $errors,
                        'batch_id' => $batchId,
                        'total_rows' => $rowNumber - 1,
                        'max_rows_read' => $maxRows
                    ];
                    
                    if ($imported > 0) {
                        $success = "Import completed! $imported records imported";
                        if ($duplicates > 0) {
                            $success .= ", $duplicates duplicates skipped";
                        }
                        if ($emptyRows > 0) {
                            $success .= ", $emptyRows empty rows skipped";
                        }
                        if ($skipped > 0) {
                            $success .= ", $skipped other rows skipped";
                        }
                        $success .= ".";
                    } else {
                        $error = "No records imported. All rows were skipped or duplicates.";
                    }
                    
                } catch (Exception $e) {
                    if (isset($handle) && $handle) {
                        fclose($handle);
                    }
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = 'Import error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get districts for filter
$districts = [];
try {
    if ($currentUser['role'] === 'admin') {
        $stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_name");
    } else {
        $stmt = $db->prepare("SELECT district_code, district_name FROM districts WHERE district_code = ? ORDER BY district_name");
        $stmt->execute([$currentUser['district_code']]);
    }
    $districts = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Load districts error: " . $e->getMessage());
}

$pageTitle = 'Import Tarheta Control Data';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Import Tarheta Control Data</h1>
                <p class="text-sm text-gray-500 mt-1">Upload CSV file with legacy registry records</p>
            </div>
            <a href="list.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to List
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <!-- CSV Format Instructions -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="text-sm font-semibold text-blue-900 mb-2">CSV Format Requirements:</h3>
        <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
            <li><strong>Required columns:</strong> last_name, first_name, registry_number</li>
            <li><strong>Optional columns:</strong> middle_name, husbands_surname (or husband_surname)</li>
            <li>Column names are case-insensitive</li>
            <li>First row must contain column headers</li>
            <li>UTF-8 encoding recommended for special characters (ñ, etc.)</li>
        </ul>
        <div class="mt-3 p-3 bg-white rounded border border-blue-300">
            <p class="text-xs font-mono text-gray-700">Example CSV:</p>
            <pre class="text-xs font-mono text-gray-600 mt-1">last_name,first_name,middle_name,husbands_surname,registry_number
Dela Cruz,Juan,Santos,,R2024-001
Garcia,Maria,Lopez,Reyes,R2024-002</pre>
        </div>
    </div>
    
    <!-- Upload Limits Warning -->
    <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-orange-600 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
            <div>
                <p class="text-sm font-semibold text-orange-900">Upload Size Limit: <?php echo ini_get('post_max_size'); ?></p>
                <p class="text-xs text-orange-800 mt-1">
                    <strong>⚠️ If your CSV file is larger than <?php echo ini_get('post_max_size'); ?>, it will be truncated during upload!</strong>
                </p>
                <p class="text-xs text-orange-700 mt-2">
                    <strong>Solutions:</strong><br>
                    1. Increase PHP limits: Edit php.ini and set <code class="bg-orange-100 px-1 rounded">post_max_size = 50M</code> and <code class="bg-orange-100 px-1 rounded">upload_max_filesize = 50M</code>, then restart PHP<br>
                    2. Split your CSV into smaller files (e.g., 2000-3000 rows per file) and import separately
                </p>
            </div>
        </div>
    </div>
    
    <!-- File Size Warning -->
    <?php
    $postMaxSize = ini_get('post_max_size');
    $uploadMaxSize = ini_get('upload_max_filesize');
    $maxSizeBytes = min(
        $postMaxSize ? parse_size($postMaxSize) : PHP_INT_MAX,
        $uploadMaxSize ? parse_size($uploadMaxSize) : PHP_INT_MAX
    );
    $maxSizeMB = round($maxSizeBytes / 1024 / 1024, 1);
    
    function parse_size($size) {
        $unit = strtoupper(substr($size, -1));
        $value = (int)$size;
        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return $value;
        }
    }
    ?>
    <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-yellow-800">File Size Limit: <?php echo $maxSizeMB; ?> MB</p>
                <p class="text-xs text-yellow-700 mt-1">
                    If your CSV file is larger than <?php echo $maxSizeMB; ?> MB, it will be truncated during upload. 
                    Split large files into smaller batches or contact your administrator to increase the upload limit.
                </p>
            </div>
        </div>
    </div>

    <!-- Column Mapper (Step 2) -->
    <?php if ($csvHeaders && !$importResults): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Map CSV Columns</h3>
        <p class="text-sm text-gray-600 mb-6">Please map your CSV columns to the required fields:</p>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('tarheta_import'); ?>">
            <input type="hidden" name="column_mapping" value="1">
            
            <!-- Preview Table -->
            <div class="overflow-x-auto mb-6">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php foreach ($csvHeaders as $header): ?>
                                <th class="px-3 py-2 text-left font-medium text-gray-700 uppercase tracking-wider">
                                    <?php echo Security::escape($header); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <?php foreach ($csvPreview as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td class="px-3 py-2 text-gray-600">
                                        <?php echo Security::escape(substr($cell, 0, 50)); ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Column Mapping -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Last Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Last Name <span class="text-red-600">*</span>
                    </label>
                    <select name="column_mapping[last_name]" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="-1">-- Select Column --</option>
                        <?php foreach ($csvHeaders as $index => $header): 
                            $selected = (stripos($header, 'last') !== false && stripos($header, 'name') !== false) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                <?php echo Security::escape($header); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- First Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        First Name <span class="text-red-600">*</span>
                    </label>
                    <select name="column_mapping[first_name]" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="-1">-- Select Column --</option>
                        <?php foreach ($csvHeaders as $index => $header): 
                            $selected = (stripos($header, 'first') !== false && stripos($header, 'name') !== false) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                <?php echo Security::escape($header); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Middle Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Middle Name <span class="text-gray-400">(Optional)</span>
                    </label>
                    <select name="column_mapping[middle_name]" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="-1">-- Skip This Field --</option>
                        <?php foreach ($csvHeaders as $index => $header): 
                            $selected = (stripos($header, 'middle') !== false) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                <?php echo Security::escape($header); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Husband's Surname -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Husband's Surname <span class="text-gray-400">(Optional)</span>
                    </label>
                    <select name="column_mapping[husbands_surname]" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="-1">-- Skip This Field --</option>
                        <?php foreach ($csvHeaders as $index => $header): 
                            $selected = (stripos($header, 'husband') !== false) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                <?php echo Security::escape($header); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Registry Number -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Registry Number <span class="text-red-600">*</span>
                    </label>
                    <select name="column_mapping[registry_number]" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="-1">-- Select Column --</option>
                        <?php foreach ($csvHeaders as $index => $header): 
                            $selected = (stripos($header, 'registry') !== false || stripos($header, 'number') !== false || stripos($header, 'reg') !== false) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                <?php echo Security::escape($header); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    Proceed with Import
                </button>
                <a href="import.php" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                    Cancel
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Import Form (Step 1) -->
    <?php if (!$csvHeaders): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('tarheta_import'); ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        District <span class="text-red-600">*</span>
                    </label>
                    <select name="district_code" id="district_code" required onchange="loadLocals(this.value)" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select District</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>">
                                <?php echo Security::escape($district['district_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Local Congregation <span class="text-red-600">*</span>
                    </label>
                    <select name="local_code" id="local_code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Local First</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    CSV File <span class="text-red-600">*</span>
                </label>
                <input 
                    type="file" 
                    name="csv_file" 
                    accept=".csv,text/csv" 
                    required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                <p class="text-xs text-gray-500 mt-1">Upload a CSV file with registry data</p>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    Upload and Preview
                </button>
                <a href="list.php" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                    Cancel
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Import Results -->
    <?php if ($importResults): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Import Results</h3>
        
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
            <div class="bg-green-50 rounded-lg p-4">
                <p class="text-sm text-green-700 font-medium">Successfully Imported</p>
                <p class="text-2xl font-bold text-green-900"><?php echo $importResults['imported']; ?></p>
            </div>
            <div class="bg-red-50 rounded-lg p-4">
                <p class="text-sm text-red-700 font-medium">Duplicates Skipped</p>
                <p class="text-2xl font-bold text-red-900"><?php echo $importResults['duplicates'] ?? 0; ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-700 font-medium">Empty Rows</p>
                <p class="text-2xl font-bold text-gray-900"><?php echo $importResults['empty_rows'] ?? 0; ?></p>
            </div>
            <div class="bg-yellow-50 rounded-lg p-4">
                <p class="text-sm text-yellow-700 font-medium">Other Errors</p>
                <p class="text-2xl font-bold text-yellow-900"><?php echo $importResults['skipped']; ?></p>
            </div>
            <div class="bg-blue-50 rounded-lg p-4">
                <p class="text-sm text-blue-700 font-medium">Total Rows</p>
                <p class="text-2xl font-bold text-blue-900"><?php echo $importResults['total_rows'] ?? 'N/A'; ?></p>
            </div>
        </div>
        
        <div class="bg-gray-50 rounded-lg p-4 mb-4">
            <p class="text-sm text-gray-700 font-medium">Batch ID</p>
            <p class="text-sm font-mono text-gray-900"><?php echo Security::escape($importResults['batch_id']); ?></p>
        </div>
        
        <?php if (isset($importResults['max_rows_read']) && $importResults['max_rows_read'] < 10000): ?>
        <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-4">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-orange-600 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    <p class="text-sm font-medium text-orange-800">Note: Only <?php echo $importResults['max_rows_read']; ?> rows were read from CSV</p>
                    <p class="text-xs text-orange-700 mt-1">If your CSV has more rows, there may be a file reading issue. Check CSV encoding (should be UTF-8) and formatting.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($importResults['errors'])): ?>
        <div>
            <?php 
            // Separate duplicates from other errors
            $duplicateErrors = array_filter($importResults['errors'], function($err) {
                return strpos($err, 'duplicate') !== false;
            });
            $otherErrors = array_filter($importResults['errors'], function($err) {
                return strpos($err, 'duplicate') === false;
            });
            ?>
            
            <?php if (!empty($otherErrors)): ?>
            <div class="mb-4">
                <h4 class="text-sm font-semibold text-yellow-900 mb-2 flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    Other Errors (<?php echo count($otherErrors); ?>):
                </h4>
                <div class="bg-yellow-50 rounded-lg p-3 max-h-60 overflow-y-auto border border-yellow-200">
                    <ul class="text-xs text-yellow-900 space-y-1 font-mono">
                        <?php foreach (array_slice($otherErrors, 0, 200) as $err): ?>
                            <li class="bg-yellow-100 p-1 rounded">
                                <?php echo Security::escape($err); ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($otherErrors) > 200): ?>
                            <li class="text-yellow-700 italic">... and <?php echo count($otherErrors) - 200; ?> more errors</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($duplicateErrors)): ?>
            <div>
                <details class="cursor-pointer">
                    <summary class="text-sm font-medium text-gray-700 mb-2 hover:text-gray-900">
                        Duplicate Records (<?php echo count($duplicateErrors); ?>) - Click to expand
                    </summary>
                    <div class="bg-gray-50 rounded-lg p-3 max-h-60 overflow-y-auto mt-2">
                        <ul class="text-xs text-red-700 space-y-1 font-mono">
                            <?php foreach (array_slice($duplicateErrors, 0, 100) as $err): ?>
                                <li>
                                    <?php echo Security::escape($err); ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($duplicateErrors) > 100): ?>
                                <li class="text-gray-500 italic">... and <?php echo count($duplicateErrors) - 100; ?> more duplicates</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </details>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Load locals based on district
async function loadLocals(districtCode) {
    const localSelect = document.getElementById('local_code');
    
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">Select District First</option>';
        return;
    }
    
    try {
        const response = await fetch('../api/get-locals.php?district=' + districtCode);
        const data = await response.json();
        
        let html = '<option value="">Select Local Congregation</option>';
        
        if (Array.isArray(data)) {
            data.forEach(local => {
                html += `<option value="${local.local_code}">${local.local_name}</option>`;
            });
        }
        
        localSelect.innerHTML = html;
    } catch (error) {
        console.error('Error loading locals:', error);
        localSelect.innerHTML = '<option value="">Error loading locals</option>';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
