<?php
/**
 * Legacy Officers - CSV Import
 * Import legacy control numbers from CSV files
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_add_officers');

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
    
    if (!Security::validateCSRFToken($csrfToken, 'legacy_import')) {
        $error = 'Invalid security token.';
    } else {
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        
        if (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (!hasDistrictAccess($districtCode) || !hasLocalAccess($localCode)) {
            $error = 'You do not have access to this district/local.';
        } elseif ($_FILES['csv_file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['csv_file']['error'] === UPLOAD_ERR_FORM_SIZE) {
            $error = 'File is too large. Maximum upload size is ' . ini_get('upload_max_filesize') . '.';
        } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error uploading file (Code: ' . $_FILES['csv_file']['error'] . ').';
        } else {
            try {
                $file = $_FILES['csv_file']['tmp_name'];
                $fileSize = $_FILES['csv_file']['size'];
                
                error_log("Legacy CSV Upload - File size: " . round($fileSize / 1024 / 1024, 2) . " MB");
                
                $handle = fopen($file, 'r');
                
                if ($handle === false) {
                    throw new Exception('Could not open CSV file.');
                }
                
                $headers = fgetcsv($handle, 0, ',', '"', '\\');
                
                if ($headers === false) {
                    throw new Exception('CSV file is empty or invalid.');
                }
                
                $preview = [];
                for ($i = 0; $i < 3; $i++) {
                    $row = fgetcsv($handle, 0, ',', '"', '\\');
                    if ($row !== false) {
                        $preview[] = $row;
                    }
                }
                
                fclose($handle);
                
                $tempFile = sys_get_temp_dir() . '/legacy_import_' . uniqid() . '.csv';
                copy($_FILES['csv_file']['tmp_name'], $tempFile);
                
                $csvHeaders = $headers;
                $csvPreview = $preview;
                $uploadedFile = $tempFile;
                
                $_SESSION['legacy_import'] = [
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
    
    if (!Security::validateCSRFToken($csrfToken, 'legacy_import')) {
        $error = 'Invalid security token.';
    } elseif (!isset($_SESSION['legacy_import'])) {
        $error = 'Session expired. Please upload the file again.';
    } else {
        $importData = $_SESSION['legacy_import'];
        
        if (time() - $importData['uploaded_at'] > 1800) {
            $error = 'Session expired. Please upload the file again.';
            unset($_SESSION['legacy_import']);
        } else {
            $districtCode = $importData['district_code'];
            $localCode = $importData['local_code'];
            $file = $importData['file'];
            
            $mapping = $_POST['column_mapping'] ?? [];
            
            // Debug logging
            error_log("Legacy Import - Column Mapping Submitted: " . print_r($mapping, true));
            
            $requiredFields = ['name', 'control_number'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                // Fix: Don't use empty() because it treats '0' as empty, but 0 is a valid column index
                if (!isset($mapping[$field]) || $mapping[$field] === '-1' || $mapping[$field] === '') {
                    $missingFields[] = $field;
                    error_log("Legacy Import - Missing/Invalid field: $field, value: " . ($mapping[$field] ?? 'not set'));
                }
            }
            
            if (!empty($missingFields)) {
                $error = 'Please map the following required fields: ' . implode(', ', $missingFields);
                $csvHeaders = $importData['headers'];
                
                // Restore preview data and rebuild preview
                try {
                    $handle = fopen($file, 'r');
                    if ($handle !== false) {
                        fgetcsv($handle, 0, ',', '"', '\\'); // Skip header
                        $preview = [];
                        for ($i = 0; $i < 3; $i++) {
                            $row = fgetcsv($handle, 0, ',', '"', '\\');
                            if ($row !== false) {
                                $preview[] = $row;
                            }
                        }
                        fclose($handle);
                        $csvPreview = $preview;
                    }
                } catch (Exception $e) {
                    // Preview not critical, continue
                }
            } else {
                try {
                    $handle = fopen($file, 'r');
                    
                    if ($handle === false) {
                        throw new Exception('Could not open CSV file.');
                    }
                    
                    fgetcsv($handle, 0, ',', '"', '\\'); // Skip header
                    
                    $db->beginTransaction();
                    
                    $batchId = date('YmdHis') . '_' . uniqid();
                    $imported = 0;
                    $skipped = 0;
                    $duplicates = 0;
                    $emptyRows = 0;
                    $errors = [];
                    $rowNumber = 1;
                    $maxRows = 0;
                    
                    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                        $rowNumber++;
                        $maxRows++;
                        
                        try {
                            if (empty(array_filter($row))) {
                                $emptyRows++;
                                continue;
                            }
                            
                            $name = isset($row[$mapping['name']]) ? trim($row[$mapping['name']]) : '';
                            $controlNumber = isset($row[$mapping['control_number']]) ? trim($row[$mapping['control_number']]) : '';
                            
                            if (empty($name) || empty($controlNumber)) {
                                $skipped++;
                                $errors[] = "Row $rowNumber skipped: Missing required data - Name: '" . substr($name, 0, 20) . "', Control#: '" . substr($controlNumber, 0, 30) . "'";
                                continue;
                            }
                            
                            $controlNumberHash = hash('sha256', strtolower(trim($controlNumber)));
                            
                            // Check for duplicates
                            $stmt = $db->prepare("
                                SELECT id, district_code, local_code 
                                FROM legacy_officers 
                                WHERE control_number_hash = ?
                            ");
                            $stmt->execute([$controlNumberHash]);
                            $existing = $stmt->fetch();
                            
                            if ($existing) {
                                $duplicates++;
                                $errors[] = "Row $rowNumber duplicate: Control number '$controlNumber' already exists (ID: {$existing['id']}, District: {$existing['district_code']}, Local: {$existing['local_code']})";
                                continue;
                            }
                            
                            // Encrypt data
                            try {
                                $nameEnc = Encryption::encrypt($name, $districtCode);
                                $controlNumberEnc = Encryption::encrypt($controlNumber, $districtCode);
                            } catch (Exception $e) {
                                $skipped++;
                                $errors[] = "Row $rowNumber encryption error: " . $e->getMessage();
                                continue;
                            }
                            
                            // Insert record
                            try {
                                $stmt = $db->prepare("
                                    INSERT INTO legacy_officers (
                                        name_encrypted, control_number_encrypted, control_number_hash,
                                        district_code, local_code, import_batch, imported_by
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                                ");
                                
                                $stmt->execute([
                                    $nameEnc,
                                    $controlNumberEnc,
                                    $controlNumberHash,
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
                    @unlink($file);
                    unset($_SESSION['legacy_import']);
                    
                    $db->commit();
                    
                    error_log("Legacy Import Summary - Batch: $batchId, Rows Read: $maxRows, Imported: $imported, Duplicates: $duplicates, Empty: $emptyRows, Errors: $skipped");
                    
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

// Get districts
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

$pageTitle = 'Import Legacy Control Numbers';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Import Legacy Control Numbers</h1>
                <p class="text-sm text-gray-500 mt-1">Upload CSV file with legacy officer control numbers</p>
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
            <li><strong>Required columns:</strong> name, control_number</li>
            <li>Column names are case-insensitive</li>
            <li>First row must contain column headers</li>
            <li>UTF-8 encoding recommended</li>
        </ul>
        <div class="mt-3 p-3 bg-white rounded border border-blue-300">
            <p class="text-xs font-mono text-gray-700">Example CSV:</p>
            <pre class="text-xs font-mono text-gray-600 mt-1">name,control_number
Dela Cruz Juan Santos,C2024-001
Garcia Maria Lopez,C2024-002</pre>
        </div>
    </div>

    <!-- Column Mapper (Step 2) -->
    <?php if ($csvHeaders && !$importResults): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Map CSV Columns</h3>
        <p class="text-sm text-gray-600 mb-6">Please map your CSV columns to the required fields:</p>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('legacy_import'); ?>">
            <input type="hidden" name="column_mapping" value="1">
            
            <!-- Preview Table -->
            <div class="overflow-x-auto mb-6">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php if (!empty($csvHeaders)): ?>
                                <?php foreach ($csvHeaders as $header): ?>
                                    <th class="px-3 py-2 text-left font-medium text-gray-700 uppercase tracking-wider">
                                        <?php echo Security::escape($header); ?>
                                    </th>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <?php if (!empty($csvPreview)): ?>
                            <?php foreach ($csvPreview as $row): ?>
                                <tr>
                                    <?php foreach ($row as $cell): ?>
                                        <td class="px-3 py-2 text-gray-600">
                                            <?php echo Security::escape(substr($cell, 0, 50)); ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Column Mapping -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Name <span class="text-red-600">*</span>
                    </label>
                    <select name="column_mapping[name]" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="-1">-- Select Column --</option>
                        <?php if (!empty($csvHeaders)): ?>
                            <?php 
                            $submittedMapping = $_POST['column_mapping'] ?? [];
                            $nameMapping = $submittedMapping['name'] ?? null;
                            foreach ($csvHeaders as $index => $header): 
                                // Preserve user's selection if form was resubmitted, otherwise auto-select
                                if ($nameMapping !== null) {
                                    $selected = ($nameMapping == $index) ? 'selected' : '';
                                } else {
                                    $selected = (stripos($header, 'name') !== false) ? 'selected' : '';
                                }
                            ?>
                                <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                    <?php echo Security::escape($header); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Control Number <span class="text-red-600">*</span>
                    </label>
                    <select name="column_mapping[control_number]" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="-1">-- Select Column --</option>
                        <?php if (!empty($csvHeaders)): ?>
                            <?php 
                            $controlMapping = $submittedMapping['control_number'] ?? null;
                            foreach ($csvHeaders as $index => $header): 
                                // Preserve user's selection if form was resubmitted, otherwise auto-select
                                if ($controlMapping !== null) {
                                    $selected = ($controlMapping == $index) ? 'selected' : '';
                                } else {
                                    $selected = (stripos($header, 'control') !== false || stripos($header, 'number') !== false) ? 'selected' : '';
                                }
                            ?>
                                <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                    <?php echo Security::escape($header); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('legacy_import'); ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        District <span class="text-red-600">*</span>
                    </label>
                    <select name="district_code" id="district_code" required onchange="loadLocals(this.value)" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select District</option>
                        <?php if (!empty($districts)): ?>
                            <?php foreach ($districts as $district): ?>
                                <option value="<?php echo Security::escape($district['district_code']); ?>">
                                    <?php echo Security::escape($district['district_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
                <p class="text-xs text-gray-500 mt-1">Upload a CSV file with legacy control numbers</p>
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

        <?php if (!empty($importResults['errors'])): ?>
        <div>
            <?php 
            $duplicateErrors = array_filter($importResults['errors'], function($err) {
                return strpos($err, 'duplicate') !== false;
            });
            $otherErrors = array_filter($importResults['errors'], function($err) {
                return strpos($err, 'duplicate') === false;
            });
            ?>
            
            <?php if (!empty($otherErrors)): ?>
            <div class="mb-4">
                <h4 class="text-sm font-semibold text-yellow-900 mb-2">Other Errors (<?php echo count($otherErrors); ?>):</h4>
                <div class="bg-yellow-50 rounded-lg p-3 max-h-60 overflow-y-auto border border-yellow-200">
                    <ul class="text-xs text-yellow-900 space-y-1 font-mono">
                        <?php foreach (array_slice($otherErrors, 0, 200) as $err): ?>
                            <li class="bg-yellow-100 p-1 rounded"><?php echo Security::escape($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($duplicateErrors)): ?>
            <details class="cursor-pointer">
                <summary class="text-sm font-medium text-gray-700 mb-2 hover:text-gray-900">
                    Duplicate Records (<?php echo count($duplicateErrors); ?>) - Click to expand
                </summary>
                <div class="bg-gray-50 rounded-lg p-3 max-h-60 overflow-y-auto mt-2">
                    <ul class="text-xs text-red-700 space-y-1 font-mono">
                        <?php foreach (array_slice($duplicateErrors, 0, 100) as $err): ?>
                            <li><?php echo Security::escape($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
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
