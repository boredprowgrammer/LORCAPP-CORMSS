<?php
/**
 * Import Purok-Grupo Data Only
 * Updates existing CFO records with purok-grupo information
 */

require_once 'config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';
$previewData = null;
$columnMappingData = null;

/**
 * Clean and normalize purok-grupo value
 * Handles various formats and character encodings
 */
function cleanPurokGrupoValue($value) {
    if (empty($value)) {
        return '';
    }
    
    // Trim whitespace and convert encoding if needed
    $value = trim($value);
    
    // Remove any non-printable characters
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    
    // Normalize whitespace
    $value = preg_replace('/\s+/', ' ', $value);
    
    return $value;
}

// Process import
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '', 'import_purok_grupo')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = Security::sanitizeInput($_POST['action'] ?? '');
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        
        // Get column mapping if provided
        $columnMapping = null;
        if (!empty($_POST['column_mapping'])) {
            $columnMapping = json_decode($_POST['column_mapping'], true);
        }
        
        // Role-based validation
        if ($currentUser['role'] === 'district' && $districtCode !== $currentUser['district_code']) {
            $error = 'You can only import for your assigned district.';
        } elseif (($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') && $localCode !== $currentUser['local_code']) {
            $error = 'You can only import for your assigned local.';
        }
        
        if (!$error && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            try {
                $file = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($file, 'r');
                
                if (!$handle) {
                    throw new Exception('Failed to open CSV file');
                }
                
                // Detect delimiter
                $firstLine = fgets($handle);
                rewind($handle);
                $tabCount = substr_count($firstLine, "\t");
                $commaCount = substr_count($firstLine, ",");
                $delimiter = ($tabCount > $commaCount) ? "\t" : ",";
                
                // Read header
                $header = fgetcsv($handle, 0, $delimiter, '"', '');
                if (!$header) {
                    throw new Exception('Invalid CSV file - no header found');
                }
                
                // Apply column mapping or auto-detect
                $regNumIndex = null;
                $purokGrupoIndex = null;
                
                if ($columnMapping) {
                    // Use user-provided mapping
                    foreach ($header as $index => $columnName) {
                        if (trim($columnName) === $columnMapping['registry_number']) {
                            $regNumIndex = $index;
                        }
                        if (trim($columnName) === $columnMapping['purok_grupo']) {
                            $purokGrupoIndex = $index;
                        }
                    }
                } else {
                    // Auto-detect columns
                    $headerMap = [];
                    foreach ($header as $index => $columnName) {
                        // Clean column name: remove BOM, trim, lowercase
                        $cleanName = strtolower(trim($columnName));
                        $cleanName = str_replace("\xEF\xBB\xBF", '', $cleanName); // Remove UTF-8 BOM
                        $headerMap[$cleanName] = $index;
                    }
                    
                    // Detect registry number column (more variations)
                    foreach (['registry_number', 'registrynumber', 'registry number', 'registry no', 'registry#', 'control_number', 'controlnumber', 'control number', 'reg_num', 'reg num', 'regno', 'reg no', 'reg_no', 'reg#'] as $key) {
                        if (isset($headerMap[$key])) {
                            $regNumIndex = $headerMap[$key];
                            break;
                        }
                    }
                    
                    // Detect purok-grupo column (more variations)
                    foreach (['purok-grupo', 'purok_grupo', 'purokgrupo', 'purok grupo', 'purok/grupo', 'prk-grp', 'prk_grp', 'prkgrp', 'prk grp', 'prk/grp', 'p-g', 'pg'] as $key) {
                        if (isset($headerMap[$key])) {
                            $purokGrupoIndex = $headerMap[$key];
                            break;
                        }
                    }
                }
                
                // If columns not detected and no mapping provided, show mapping screen
                if (!$columnMapping && ($regNumIndex === null || $purokGrupoIndex === null)) {
                    fclose($handle);
                    $columnMappingData = [
                        'district_code' => $districtCode,
                        'local_code' => $localCode,
                        'csv_columns' => $header,
                        'file_name' => $_FILES['csv_file']['name']
                    ];
                    goto showMappingScreen;
                }
                
                if ($regNumIndex === null) {
                    throw new Exception('Registry Number column not found or not mapped correctly.');
                }
                
                if ($purokGrupoIndex === null) {
                    throw new Exception('Purok-Grupo column not found or not mapped correctly.');
                }
                
                // Debug: Log header mapping
                error_log("=== Purok-Grupo Import Column Mapping ===");
                error_log("CSV Headers: " . implode(", ", $header));
                error_log("registry_number maps to column index: " . ($regNumIndex ?? 'NOT FOUND'));
                error_log("purok_grupo maps to column index: " . ($purokGrupoIndex ?? 'NOT FOUND'));
                
                // Parse CSV records
                $csvRecords = [];
                $skippedRows = 0;
                $emptyRegistryRows = 0;
                $isMergedFormat = false;
                $rowNum = 1; // Header is row 1, data starts at row 2
                
                while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    $rowNum++;
                    
                    // Skip completely empty rows
                    if (count(array_filter($row, function($val) { return !empty(trim($val)); })) === 0) {
                        $skippedRows++;
                        continue;
                    }
                    
                    // Read registry number and purok-grupo
                    $registryNumber = isset($row[$regNumIndex]) ? trim($row[$regNumIndex]) : '';
                    
                    // If registry column is empty but first column has data with registry pattern, parse it
                    // Patterns: PPE0581000250 (3 letters + 10 digits) OR LGW0051002139 (3 letters + 10 digits) OR 1785224100020 (13 digits)
                    if (empty($registryNumber) && !empty($row[0]) && preg_match('/[A-Z]{3}\d{10}|\d{13}/', $row[0])) {
                        // Parse merged format: "ROW#    REGISTRY    NAME"
                        if (preg_match('/^\s*\d+\s+([A-Z]{3}\d{10}|\d{13})/', $row[0], $matches)) {
                            $registryNumber = trim($matches[1]);
                            
                            if (!$isMergedFormat) {
                                $isMergedFormat = true;
                                error_log("⚠️ DETECTED MERGED FORMAT at row $rowNum: Parsing registry from first column");
                            }
                        }
                    }
                    
                    $purokGrupoValue = isset($row[$purokGrupoIndex]) ? cleanPurokGrupoValue($row[$purokGrupoIndex]) : '';
                    
                    // Skip only if registry number is completely missing
                    if (empty($registryNumber)) {
                        $emptyRegistryRows++;
                        $skippedRows++;
                        // Debug: Show first few skipped rows
                        if ($emptyRegistryRows <= 5) {
                            error_log("Row $rowNum: Skipped - missing registry number. Row data: " . json_encode(array_slice($row, 0, 5)));
                            error_log("  -> Tried to read from column index: $regNumIndex");
                        }
                        continue;
                    }
                    
                    // Allow empty purok-grupo values (we'll track them separately)
                    // Normalize registry number
                    $registryNumberNormalized = preg_replace('/[^0-9]/', '', $registryNumber);
                    
                    // Parse purok-grupo (format: "1-7" → purok=1, grupo=7)
                    $purok = null;
                    $grupo = null;
                    if (!empty($purokGrupoValue)) {
                        // Split by various separators (hyphen, slash, comma, space)
                        $parts = preg_split('/[-–—\/,\s]+/', $purokGrupoValue, 2);
                        if (count($parts) >= 1 && trim($parts[0]) !== '') {
                            $purok = trim($parts[0]);
                        }
                        if (count($parts) >= 2 && trim($parts[1]) !== '') {
                            $grupo = trim($parts[1]);
                        }
                        
                        // If no separator found but value exists, treat entire value as purok
                        if ($purok === null && trim($purokGrupoValue) !== '') {
                            $purok = trim($purokGrupoValue);
                        }
                    }
                    
                    $csvRecords[] = [
                        'registry_number' => $registryNumber,
                        'registry_number_normalized' => $registryNumberNormalized,
                        'purok' => $purok,
                        'grupo' => $grupo,
                        'purok_grupo_display' => $purokGrupoValue,
                        'row_num' => $rowNum
                    ];
                }
                
                fclose($handle);
                
                // Debug final counts
                error_log("=== Import Parsing Complete ===");
                error_log("Total rows parsed: " . count($csvRecords));
                error_log("Skipped rows: $skippedRows (Empty registry: $emptyRegistryRows)");
                
                if (empty($csvRecords)) {
                    throw new Exception('No valid records found in CSV file. Skipped ' . $skippedRows . ' rows (empty registry: ' . $emptyRegistryRows . '). Please check your CSV format and column mapping.');
                }
                
                // Get existing records from database
                $whereConditions = [];
                $params = [];
                
                if ($currentUser['role'] === 'district') {
                    $whereConditions[] = 'district_code = ?';
                    $params[] = $districtCode;
                } elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
                    $whereConditions[] = 'local_code = ?';
                    $params[] = $localCode;
                }
                
                $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
                
                $stmt = $db->prepare("
                    SELECT 
                        id,
                        registry_number_encrypted,
                        registry_number_hash,
                        district_code,
                        local_code,
                        purok,
                        grupo,
                        first_name_encrypted,
                        last_name_encrypted
                    FROM tarheta_control
                    $whereClause
                ");
                $stmt->execute($params);
                $existingRecords = $stmt->fetchAll();
                
                // Create a map of normalized registry numbers to record IDs
                $registryMap = [];
                foreach ($existingRecords as $record) {
                    try {
                        $regNum = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
                        $normalized = preg_replace('/[^0-9]/', '', $regNum);
                        $registryMap[$normalized] = $record;
                    } catch (Exception $e) {
                        error_log("Failed to decrypt registry number: " . $e->getMessage());
                    }
                }
                
                // Match CSV records with database records
                $toUpdate = [];
                $notFound = [];
                $unchanged = [];
                $skipped = [];
                
                foreach ($csvRecords as $csvRecord) {
                    if (isset($registryMap[$csvRecord['registry_number_normalized']])) {
                        $dbRecord = $registryMap[$csvRecord['registry_number_normalized']];
                        
                        // Normalize null and empty string for comparison
                        $oldPurok = $dbRecord['purok'] ?: null;
                        $oldGrupo = $dbRecord['grupo'] ?: null;
                        $newPurok = $csvRecord['purok'] ?: null;
                        $newGrupo = $csvRecord['grupo'] ?: null;
                        
                        // Skip if already has both purok and grupo set (don't overwrite complete data)
                        if (!empty($oldPurok) && !empty($oldGrupo)) {
                            $skipped[] = $csvRecord;
                            continue;
                        }
                        
                        // Check if update is needed
                        $needsUpdate = ($oldPurok !== $newPurok) || ($oldGrupo !== $newGrupo);
                        
                        if ($needsUpdate) {
                            $firstName = Encryption::decrypt($dbRecord['first_name_encrypted'], $dbRecord['district_code']);
                            $lastName = Encryption::decrypt($dbRecord['last_name_encrypted'], $dbRecord['district_code']);
                            
                            $toUpdate[] = [
                                'id' => $dbRecord['id'],
                                'registry_number' => $csvRecord['registry_number'],
                                'name' => trim($firstName . ' ' . $lastName),
                                'old_purok' => $dbRecord['purok'],
                                'old_grupo' => $dbRecord['grupo'],
                                'new_purok' => $csvRecord['purok'],
                                'new_grupo' => $csvRecord['grupo'],
                                'purok_grupo_display' => $csvRecord['purok_grupo_display'],
                                'row_num' => $csvRecord['row_num']
                            ];
                        } else {
                            $unchanged[] = $csvRecord;
                        }
                    } else {
                        $notFound[] = $csvRecord;
                    }
                }
                
                // Preview or execute
                if ($action === 'preview') {
                    // Find records with incomplete data (only purok or only grupo)
                    $incompleteData = [];
                    foreach ($toUpdate as $record) {
                        $hasPurok = !empty($record['new_purok']);
                        $hasGrupo = !empty($record['new_grupo']);
                        if (($hasPurok && !$hasGrupo) || (!$hasPurok && $hasGrupo)) {
                            $incompleteData[] = $record;
                        }
                    }
                    
                    $previewData = [
                        'total_csv' => count($csvRecords),
                        'to_update' => $toUpdate,
                        'not_found' => $notFound,
                        'unchanged' => $unchanged,
                        'skipped' => $skipped,
                        'incomplete' => $incompleteData,
                        'skipped_rows' => $skippedRows,
                        'empty_registry_rows' => $emptyRegistryRows,
                        'district_code' => $districtCode,
                        'local_code' => $localCode,
                        'column_mapping' => $columnMapping
                    ];
                } elseif ($action === 'confirm') {
                    // Execute updates
                    $db->beginTransaction();
                    
                    $updatedCount = 0;
                    foreach ($toUpdate as $record) {
                        $stmt = $db->prepare("
                            UPDATE tarheta_control
                            SET 
                                purok = ?,
                                grupo = ?,
                                cfo_updated_at = NOW(),
                                cfo_updated_by = ?
                            WHERE id = ?
                        ");
                        
                        $stmt->execute([
                            !empty($record['new_purok']) ? $record['new_purok'] : null,
                            !empty($record['new_grupo']) ? $record['new_grupo'] : null,
                            $currentUser['user_id'],
                            $record['id']
                        ]);
                        
                        $updatedCount++;
                    }
                    
                    $db->commit();
                    
                    $success = "Successfully updated $updatedCount record(s) with purok-grupo data!";
                }
                
                showMappingScreen:
                
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Import failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Import Purok-Grupo';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Import Purok-Grupo Data</h1>
                <p class="text-sm text-gray-500 mt-1">Update existing CFO records with purok-grupo information</p>
            </div>
            <a href="cfo-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Registry
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <strong>Error:</strong> <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            <strong>Success:</strong> <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($columnMappingData): ?>
        <!-- Column Mapping Screen -->
        <div class="bg-white rounded-lg shadow-sm border border-purple-300 border-2 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-purple-900">🔗 Map CSV Columns</h2>
                <span class="px-3 py-1 bg-purple-100 text-purple-800 text-sm font-semibold rounded-full">STEP 1: MAPPING</span>
            </div>
            
            <div class="bg-purple-50 border border-purple-200 p-4 rounded-lg mb-6">
                <p class="text-sm text-purple-900">
                    <strong>Map your CSV columns to the system fields.</strong> This ensures your data is imported correctly.
                </p>
                <p class="text-xs text-purple-700 mt-2">
                    File: <strong><?php echo Security::escape($columnMappingData['file_name']); ?></strong>
                </p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="mappingForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('import_purok_grupo'); ?>">
                <input type="hidden" name="action" value="preview">
                <input type="hidden" name="district_code" value="<?php echo Security::escape($columnMappingData['district_code']); ?>">
                <input type="hidden" name="local_code" value="<?php echo Security::escape($columnMappingData['local_code']); ?>">
                <input type="hidden" name="column_mapping" id="column_mapping_hidden" value="">
                
                <!-- Re-upload file -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Re-upload CSV File <span class="text-red-500">*</span>
                    </label>
                    <input type="file" name="csv_file" id="mapping_csv_file" accept=".csv" required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Registry Number -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Registry Number <span class="text-red-500">*</span>
                        </label>
                        <select name="map_registry_number" id="map_registry_number" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Select CSV Column --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Purok-Grupo -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Purok-Grupo <span class="text-red-500">*</span>
                        </label>
                        <select name="map_purok_grupo" id="map_purok_grupo" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Select CSV Column --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Format example: 1-7 (Purok 1, Grupo 7)</p>
                    </div>
                </div>
                
                <div class="flex justify-between items-center border-t border-gray-300 pt-6">
                    <a href="cfo-import-purok-grupo.php" class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-semibold transition-colors">
                        Cancel
                    </a>
                    <button type="submit" class="px-8 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-semibold transition-colors shadow-lg">
                        Continue to Preview →
                    </button>
                </div>
            </form>
        </div>
        
        <script>
        // Store column mapping in hidden field before submit
        document.getElementById('mappingForm').addEventListener('submit', function() {
            const mapping = {
                'registry_number': document.querySelector('[name="map_registry_number"]').value,
                'purok_grupo': document.querySelector('[name="map_purok_grupo"]').value
            };
            document.getElementById('column_mapping_hidden').value = JSON.stringify(mapping);
        });
        </script>
        
    <?php elseif ($previewData): ?>
        <!-- Preview -->
        <div class="bg-white rounded-lg shadow-sm border border-blue-300 border-2 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-blue-900">📋 Import Preview</h2>
                <span class="px-3 py-1 bg-blue-100 text-blue-800 text-sm font-semibold rounded-full">PREVIEW MODE</span>
            </div>

            <!-- Summary Stats -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                    <p class="text-sm text-blue-600 mb-1">Total in CSV</p>
                    <?php if (!empty($previewData['skipped_rows'])): ?>
                    <p class="text-xs text-blue-500 mt-1">Skipped: <?php echo $previewData['skipped_rows']; ?> rows</p>
                    <?php if (!empty($previewData['empty_registry_rows'])): ?>
                    <p class="text-xs text-blue-400">(<?php echo $previewData['empty_registry_rows']; ?> empty registry)</p>
                    <?php endif; ?>
                    <?php endif; ?>
                    <p class="text-2xl font-bold text-blue-900"><?php echo number_format($previewData['total_csv']); ?></p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 border border-green-200">
                    <p class="text-sm text-green-600 mb-1">Will Update</p>
                    <p class="text-2xl font-bold text-green-900"><?php echo number_format(count($previewData['to_update'])); ?></p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
                    <p class="text-sm text-purple-600 mb-1">Already Set</p>
                    <p class="text-2xl font-bold text-purple-900"><?php echo number_format(count($previewData['skipped'] ?? [])); ?></p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-4 border border-yellow-200">
                    <p class="text-sm text-yellow-600 mb-1">Not Found</p>
                    <p class="text-2xl font-bold text-yellow-900"><?php echo number_format(count($previewData['not_found'])); ?></p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <p class="text-sm text-gray-600 mb-1">Unchanged</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format(count($previewData['unchanged'])); ?></p>
                </div>
            </div>

            <!-- Records to Update -->
            <?php if (!empty($previewData['to_update'])): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-green-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Records to Update (<?php echo count($previewData['to_update']); ?>)
                    </h3>
                    <div class="bg-green-50 rounded-lg border border-green-200 overflow-hidden">
                        <div class="max-h-96 overflow-y-auto">
                            <table class="min-w-full divide-y divide-green-200">
                                <thead class="bg-green-100 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-green-900">Name</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-green-900">Registry #</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-green-900">Current</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-green-900">New Value</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-green-100">
                                    <?php foreach ($previewData['to_update'] as $record): ?>
                                        <tr class="hover:bg-green-100 transition-colors">
                                            <td class="px-4 py-2 text-sm text-gray-900"><?php echo Security::escape($record['name']); ?></td>
                                            <td class="px-4 py-2 text-sm font-mono text-gray-700"><?php echo Security::escape($record['registry_number']); ?></td>
                                            <td class="px-4 py-2 text-sm">
                                                <?php 
                                                $current = '';
                                                if (!empty($record['old_purok']) && !empty($record['old_grupo'])) {
                                                    $current = $record['old_purok'] . '-' . $record['old_grupo'];
                                                } elseif (!empty($record['old_purok'])) {
                                                    $current = $record['old_purok'];
                                                } else {
                                                    $current = '<span class="text-gray-400">-</span>';
                                                }
                                                echo $current;
                                                ?>
                                            </td>
                                            <td class="px-4 py-2 text-sm">
                                                <span class="px-2 py-1 bg-indigo-100 text-indigo-700 rounded font-medium">
                                                    <?php 
                                                    $newDisplay = '';
                                                    if (!empty($record['new_purok']) && !empty($record['new_grupo'])) {
                                                        $newDisplay = $record['new_purok'] . '-' . $record['new_grupo'];
                                                    } elseif (!empty($record['new_purok'])) {
                                                        $newDisplay = 'Purok: ' . $record['new_purok'];
                                                    } elseif (!empty($record['new_grupo'])) {
                                                        $newDisplay = 'Grupo: ' . $record['new_grupo'];
                                                    } else {
                                                        $newDisplay = '<span class="text-gray-400">Empty</span>';
                                                    }
                                                    echo $newDisplay;
                                                    ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Skipped Records (Already Set) -->
            <?php if (!empty($previewData['skipped'])): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-purple-900 mb-3 flex items-center">
                        <i class="fa-solid fa-check-double mr-2"></i>
                        Already Set - Skipped (<?php echo count($previewData['skipped']); ?>)
                    </h3>
                    <div class="bg-purple-50 rounded-lg border border-purple-200 p-4">
                        <p class="text-sm text-purple-800 mb-3">
                            <i class="fa-solid fa-info-circle mr-1"></i>
                            These records already have both Purok and Grupo set and were skipped to avoid overwriting existing data:
                        </p>
                        <div class="max-h-48 overflow-y-auto">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                <?php foreach ($previewData['skipped'] as $record): ?>
                                    <div class="px-3 py-1 bg-purple-100 rounded text-sm text-purple-900">
                                        <span class="font-mono"><?php echo Security::escape($record['registry_number']); ?></span>
                                        <span class="text-xs text-purple-600 ml-1">(<?php echo Security::escape($record['purok_grupo_display']); ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Incomplete Data Warning -->
            <?php if (!empty($previewData['incomplete'])): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-blue-900 mb-3 flex items-center">
                        <i class="fa-solid fa-circle-info mr-2"></i>
                        Incomplete Data (<?php echo count($previewData['incomplete']); ?>)
                    </h3>
                    <div class="bg-blue-50 rounded-lg border border-blue-200 p-4">
                        <p class="text-sm text-blue-800 mb-3">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                            These records have only Purok or only Grupo (missing the other value). This is allowed but you may want to verify:
                        </p>
                        <div class="max-h-64 overflow-y-auto">
                            <table class="min-w-full divide-y divide-blue-200">
                                <thead class="bg-blue-100">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-blue-900">Name</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-blue-900">Registry #</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-blue-900">Purok</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-blue-900">Grupo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-blue-100">
                                    <?php foreach ($previewData['incomplete'] as $record): ?>
                                        <tr class="hover:bg-blue-100">
                                            <td class="px-3 py-2 text-sm"><?php echo Security::escape($record['name']); ?></td>
                                            <td class="px-3 py-2 text-sm font-mono"><?php echo Security::escape($record['registry_number']); ?></td>
                                            <td class="px-3 py-2 text-sm">
                                                <?php echo $record['new_purok'] ? Security::escape($record['new_purok']) : '<span class="text-gray-400">-</span>'; ?>
                                            </td>
                                            <td class="px-3 py-2 text-sm">
                                                <?php echo $record['new_grupo'] ? Security::escape($record['new_grupo']) : '<span class="text-gray-400">-</span>'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Not Found -->
            <?php if (!empty($previewData['not_found'])): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-yellow-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        Registry Numbers Not Found (<?php echo count($previewData['not_found']); ?>)
                    </h3>
                    <div class="bg-yellow-50 rounded-lg border border-yellow-200 p-4">
                        <p class="text-sm text-yellow-800 mb-2">These registry numbers don't exist in your database:</p>
                        <div class="max-h-48 overflow-y-auto">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                <?php foreach ($previewData['not_found'] as $record): ?>
                                    <div class="px-3 py-1 bg-yellow-100 rounded font-mono text-sm text-yellow-900">
                                        <?php echo Security::escape($record['registry_number']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Confirmation Form -->
            <form method="POST" enctype="multipart/form-data" class="border-t border-gray-300 pt-6">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('import_purok_grupo'); ?>">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="district_code" value="<?php echo Security::escape($previewData['district_code']); ?>">
                <input type="hidden" name="local_code" value="<?php echo Security::escape($previewData['local_code']); ?>">
                <?php if (!empty($previewData['column_mapping'])): ?>
                <input type="hidden" name="column_mapping" value="<?php echo Security::escape(json_encode($previewData['column_mapping'])); ?>">
                <?php endif; ?>
                
                <!-- Re-upload file -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Re-upload CSV File <span class="text-red-500">*</span>
                    </label>
                    <input type="file" name="csv_file" accept=".csv" required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex justify-between items-center">
                    <a href="cfo-import-purok-grupo.php" class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-semibold transition-colors">
                        Cancel
                    </a>
                    <?php if (!empty($previewData['to_update'])): ?>
                        <button type="submit" class="px-8 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold transition-colors shadow-lg">
                            ✓ Confirm & Update <?php echo count($previewData['to_update']); ?> Record(s)
                        </button>
                    <?php else: ?>
                        <p class="text-yellow-600 font-semibold">No records to update</p>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- Upload Form -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Upload CSV File</h2>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-semibold text-blue-900 mb-2">📄 CSV Format Requirements:</h3>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>• <strong>Registry Number</strong> column (required)</li>
                    <li>• <strong>Purok-Grupo</strong> column with format: <code class="bg-blue-100 px-2 py-0.5 rounded">1-7</code></li>
                    <li>• Delimiter: Comma (,) or Tab</li>
                    <li>• Example: <code class="bg-blue-100 px-2 py-0.5 rounded">registry_number,purok-grupo</code></li>
                </ul>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('import_purok_grupo'); ?>">
                <input type="hidden" name="action" value="preview">

                <div class="space-y-6">
                    <!-- District Selection (Admin only) -->
                    <?php if ($currentUser['role'] === 'admin'): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                District <span class="text-red-500">*</span>
                            </label>
                            <select name="district_code" id="district_code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Select District --</option>
                                <?php
                                $stmt = $db->prepare("SELECT district_code, district_name FROM districts ORDER BY district_name");
                                $stmt->execute();
                                while ($row = $stmt->fetch()):
                                ?>
                                    <option value="<?php echo Security::escape($row['district_code']); ?>">
                                        <?php echo Security::escape($row['district_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Local <span class="text-red-500">*</span>
                            </label>
                            <select name="local_code" id="local_code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Select Local --</option>
                            </select>
                        </div>
                    <?php elseif ($currentUser['role'] === 'district'): ?>
                        <input type="hidden" name="district_code" value="<?php echo Security::escape($currentUser['district_code']); ?>">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Local <span class="text-red-500">*</span>
                            </label>
                            <select name="local_code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Select Local --</option>
                                <?php
                                $stmt = $db->prepare("SELECT local_code, local_name FROM local_congregations WHERE district_code = ? ORDER BY local_name");
                                $stmt->execute([$currentUser['district_code']]);
                                while ($row = $stmt->fetch()):
                                ?>
                                    <option value="<?php echo Security::escape($row['local_code']); ?>">
                                        <?php echo Security::escape($row['local_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="district_code" value="<?php echo Security::escape($currentUser['district_code']); ?>">
                        <input type="hidden" name="local_code" value="<?php echo Security::escape($currentUser['local_code']); ?>">
                    <?php endif; ?>

                    <!-- CSV File Upload -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            CSV File <span class="text-red-500">*</span>
                        </label>
                        <input type="file" name="csv_file" accept=".csv" required 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="px-8 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold transition-colors shadow-lg">
                            Preview Import →
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($currentUser['role'] === 'admin'): ?>
<script>
// Dynamic local loading for admin
document.getElementById('district_code').addEventListener('change', function() {
    const districtCode = this.value;
    const localSelect = document.getElementById('local_code');
    
    localSelect.innerHTML = '<option value="">-- Loading... --</option>';
    
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">-- Select District First --</option>';
        return;
    }
    
    fetch(`api/get-locals.php?district_code=${encodeURIComponent(districtCode)}`)
        .then(response => response.json())
        .then(data => {
            localSelect.innerHTML = '<option value="">-- Select Local --</option>';
            data.forEach(local => {
                const option = document.createElement('option');
                option.value = local.local_code;
                option.textContent = local.local_name;
                localSelect.appendChild(option);
            });
        })
        .catch(error => {
            localSelect.innerHTML = '<option value="">-- Error Loading Locals --</option>';
            console.error('Error loading locals:', error);
        });
});
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once 'includes/layout.php';
