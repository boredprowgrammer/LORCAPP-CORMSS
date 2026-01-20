<?php
/**
 * CFO Import from CSV
 * Import CFO members, transfer out those not in CSV, add new ones
 * Smart registry number recognition (handles spacing variations)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_add_officers'); // Need add permission to import

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';
$importResults = null;
$previewData = null;
$isPreview = false;
$columnMappingData = null; // For showing column mapping screen

/**
 * Normalize registry number for comparison
 * Removes all spaces and converts to uppercase
 * Example: "PPE 058 1 000 058" -> "PPE0581000058"
 */
function normalizeRegistryNumber($registryNumber) {
    return strtoupper(str_replace(' ', '', trim($registryNumber)));
}

/**
 * Format registry number to system format
 * Example: "PPE0581000058" -> "PPE 058 1 000 058"
 */
function formatRegistryNumber($registryNumber) {
    $normalized = normalizeRegistryNumber($registryNumber);
    
    // Check if it matches the expected pattern (e.g., PPE0581000058)
    // Format: PPE + 3 digits + 1 digit + 3 digits + 3 digits
    if (preg_match('/^([A-Z]{3})(\d{3})(\d{1})(\d{3})(\d{3})$/', $normalized, $matches)) {
        return $matches[1] . ' ' . $matches[2] . ' ' . $matches[3] . ' ' . $matches[4] . ' ' . $matches[5];
    }
    
    // Return normalized if pattern doesn't match
    return $normalized;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken, 'import_cfo')) {
        $error = 'Invalid security token.';
    } else {
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        $action = Security::sanitizeInput($_POST['action'] ?? 'preview');
        
        // Check if we have column mapping from previous step
        $columnMapping = isset($_POST['column_mapping']) ? json_decode($_POST['column_mapping'], true) : null;
        
        // Validation
        if (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (!hasDistrictAccess($districtCode) || !hasLocalAccess($localCode)) {
            $error = 'You do not have access to this district/local.';
        } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a valid CSV file.';
        } else {
            try {
                // Parse CSV file
                $csvFile = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($csvFile, 'r');
                
                if (!$handle) {
                    throw new Exception('Could not read CSV file.');
                }
                
                // Auto-detect delimiter (tab or comma)
                $firstLine = fgets($handle);
                rewind($handle);
                
                $delimiter = ',';
                if (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
                    $delimiter = "\t";
                    error_log("Detected tab-separated file (TSV)");
                } else {
                    error_log("Detected comma-separated file (CSV)");
                }
                
                // Read CSV header
                $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
                if (!$header) {
                    throw new Exception('CSV file is empty.');
                }
                
                // Map header columns (case-insensitive)
                $headerMap = [];
                foreach ($header as $index => $columnName) {
                    $headerMap[strtolower(trim($columnName))] = $index;
                }
                
                // If we have column mapping, apply it
                if ($columnMapping) {
                    // Create new header map based on user's mapping
                    $mappedHeaderMap = [];
                    foreach ($columnMapping as $systemField => $csvColumn) {
                        if (!empty($csvColumn) && $csvColumn !== 'skip') {
                            // Find the index of the CSV column
                            foreach ($header as $index => $headerName) {
                                if (trim($headerName) === $csvColumn) {
                                    $mappedHeaderMap[$systemField] = $index;
                                    break;
                                }
                            }
                        }
                    }
                    $headerMap = $mappedHeaderMap;
                } else {
                    // Auto-detect columns with case-insensitive matching
                    $autoHeaderMap = [];
                    foreach ($header as $index => $columnName) {
                        $autoHeaderMap[strtolower(trim($columnName))] = $index;
                    }
                    $headerMap = $autoHeaderMap;
                }
                
                // Normalize purok-grupo column variations
                if (isset($headerMap['prk-grp']) || isset($headerMap['prk_grp']) || isset($headerMap['prkgrp'])) {
                    $purokGrupoIndex = $headerMap['prk-grp'] ?? $headerMap['prk_grp'] ?? $headerMap['prkgrp'];
                    $headerMap['purok_grupo'] = $purokGrupoIndex;
                } elseif (isset($headerMap['purok-grupo']) || isset($headerMap['purok_grupo']) || isset($headerMap['purokgrupo'])) {
                    $purokGrupoIndex = $headerMap['purok-grupo'] ?? $headerMap['purok_grupo'] ?? $headerMap['purokgrupo'];
                    $headerMap['purok_grupo'] = $purokGrupoIndex;
                } elseif (isset($headerMap['purok grupo'])) {
                    $headerMap['purok_grupo'] = $headerMap['purok grupo'];
                }
                
                // Check if we need to show column mapping screen
                if (!$columnMapping && $action === 'preview') {
                    // Check for required columns - either full_name or separate names
                    $hasSeparateNames = isset($headerMap['last_name']) && isset($headerMap['first_name']);
                    $hasFullName = isset($headerMap['full_name']) || isset($headerMap['fullname']) || isset($headerMap['name']);
                    $hasRegistryNumber = isset($headerMap['registry_number']) || isset($headerMap['registrynumber']) || isset($headerMap['control_number']);
                    
                    // If required columns are not auto-detected, show mapping screen
                    if (!$hasRegistryNumber || (!$hasSeparateNames && !$hasFullName)) {
                        fclose($handle);
                        $columnMappingData = [
                            'district_code' => $districtCode,
                            'local_code' => $localCode,
                            'csv_columns' => $header,
                            'file_name' => $_FILES['csv_file']['name']
                        ];
                        // Don't process further, show mapping screen
                        goto showMappingScreen;
                    }
                }
                
                // Check for required columns - either full_name or separate names
                $hasSeparateNames = isset($headerMap['last_name']) && isset($headerMap['first_name']);
                $hasFullName = isset($headerMap['full_name']) || isset($headerMap['fullname']) || isset($headerMap['name']);
                
                if (!isset($headerMap['registry_number']) && !isset($headerMap['registrynumber']) && !isset($headerMap['control_number'])) {
                    throw new Exception("CSV missing required column: registry_number (please use column mapping)");
                }
                
                // Normalize registry_number key
                if (!isset($headerMap['registry_number'])) {
                    if (isset($headerMap['registrynumber'])) {
                        $headerMap['registry_number'] = $headerMap['registrynumber'];
                    } elseif (isset($headerMap['control_number'])) {
                        $headerMap['registry_number'] = $headerMap['control_number'];
                    }
                }
                
                if (!$hasSeparateNames && !$hasFullName) {
                    throw new Exception("CSV must have either 'full_name' column or separate 'last_name' and 'first_name' columns");
                }
                
                // Normalize full_name column key
                $fullNameKey = null;
                if (isset($headerMap['full_name'])) $fullNameKey = 'full_name';
                elseif (isset($headerMap['fullname'])) $fullNameKey = 'fullname';
                elseif (isset($headerMap['name'])) $fullNameKey = 'name';
                
                // Debug: Log header mapping
                error_log("=== Column Mapping ===");
                error_log("CSV Headers: " . implode(", ", $header));
                error_log("registry_number maps to column index: " . ($headerMap['registry_number'] ?? 'NOT FOUND'));
                if ($fullNameKey) {
                    error_log("full_name key: $fullNameKey maps to column index: " . ($headerMap[$fullNameKey] ?? 'NOT FOUND'));
                } else {
                    error_log("Using separate name columns - last_name: " . ($headerMap['last_name'] ?? 'NOT FOUND') . ", first_name: " . ($headerMap['first_name'] ?? 'NOT FOUND'));
                }
                
                // Parse all CSV records
                $csvRecords = [];
                $csvRegistryNumbers = []; // Normalized registry numbers from CSV
                $lineNumber = 1;
                $totalRowsRead = 0;
                $skippedEmptyRows = 0;
                $skippedNoRegistryNumber = 0;
                $skippedNoName = 0;
                
                // Check if data is merged in first column (malformed CSV)
                $isMergedFormat = false;
                
                while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                    $lineNumber++;
                    $totalRowsRead++;
                    
                    if (empty($row) || count($row) < 2) {
                        $skippedEmptyRows++;
                        continue; // Skip empty rows
                    }
                    
                    $registryNumber = trim($row[$headerMap['registry_number']] ?? '');
                    
                    // If registry column is empty but first column has data with registry pattern, parse it
                    // Patterns: PPE0581000250 (3 letters + 10 digits) OR 1785224100020 (13 digits)
                    if (empty($registryNumber) && !empty($row[0]) && preg_match('/\s+(?:[A-Z]{3}\d{10}|\d{13})\s+/', $row[0])) {
                        // Parse merged format: "ROW#    REGISTRY    NAME"
                        if (preg_match('/^\s*\d+\s+([A-Z]{3}\d{10}|\d{13})\s+(.+)$/', $row[0], $matches)) {
                            $registryNumber = trim($matches[1]);
                            $fullNameFromMerged = trim($matches[2]);
                            
                            // Override the row with parsed values
                            $row[$headerMap['registry_number']] = $registryNumber;
                            if ($fullNameKey && isset($headerMap[$fullNameKey])) {
                                $row[$headerMap[$fullNameKey]] = $fullNameFromMerged;
                            }
                            
                            if (!$isMergedFormat) {
                                $isMergedFormat = true;
                                error_log("⚠️ DETECTED MERGED FORMAT at line $lineNumber: Parsing data from first column");
                            }
                        } else {
                            // Couldn't parse merged format
                            $skippedNoRegistryNumber++;
                            if ($skippedNoRegistryNumber <= 3) {
                                error_log("Line $lineNumber: Failed to parse merged format: " . $row[0]);
                            }
                            continue;
                        }
                    }
                    
                    $registryNumber = trim($row[$headerMap['registry_number']] ?? '');
                    
                    if (empty($registryNumber)) {
                        $skippedNoRegistryNumber++;
                        // Debug: Show first few skipped rows with full row data
                        if ($skippedNoRegistryNumber <= 5) {
                            error_log("Line $lineNumber: Skipped - missing registry number. Row data: " . json_encode($row));
                            error_log("  -> Tried to read from column index: " . ($headerMap['registry_number'] ?? 'UNDEFINED'));
                        }
                        continue; // Skip rows with missing registry number
                    }
                    
                    // Parse name - either from separate columns or from full_name
                    $lastName = '';
                    $firstName = '';
                    $middleName = '';
                    $husbandsSurname = '';
                    
                    if ($fullNameKey && isset($headerMap[$fullNameKey])) {
                        // Parse full_name: "LAST NAME, FIRST NAME, MIDDLE NAME, HUSBANDS SURNAME"
                        // Can be 2-4 parts: LAST, FIRST or LAST, FIRST, MIDDLE or LAST, FIRST, MIDDLE, HUSBAND
                        $fullName = trim($row[$headerMap[$fullNameKey]] ?? '');
                        if (!empty($fullName)) {
                            $nameParts = array_map('trim', explode(',', $fullName));
                            // Filter out empty parts
                            $nameParts = array_filter($nameParts, function($part) {
                                return !empty($part) && $part !== '-';
                            });
                            $nameParts = array_values($nameParts); // Re-index array
                            
                            $lastName = $nameParts[0] ?? '';
                            $firstName = $nameParts[1] ?? '';
                            $middleName = $nameParts[2] ?? '';
                            $husbandsSurname = $nameParts[3] ?? '';
                            
                            // Clean up husband's surname - treat empty, dash, or N/A as no husband surname
                            if (in_array(strtolower($husbandsSurname), ['', '-', 'n/a', 'na', 'none', 'null'])) {
                                $husbandsSurname = '';
                            }
                        }
                    } else {
                        // Use separate columns
                        $lastName = trim($row[$headerMap['last_name']] ?? '');
                        $firstName = trim($row[$headerMap['first_name']] ?? '');
                        $middleName = trim($row[$headerMap['middle_name']] ?? '');
                        $husbandsSurname = trim($row[$headerMap['husbands_surname']] ?? '');
                        
                        // Clean up husband's surname
                        if (in_array(strtolower($husbandsSurname), ['', '-', 'n/a', 'na', 'none', 'null'])) {
                            $husbandsSurname = '';
                        }
                    }
                    
                    if (empty($lastName) || empty($firstName)) {
                        $skippedNoName++;
                        error_log("Line $lineNumber: Skipped - missing name data (Last: '$lastName', First: '$firstName')");
                        continue; // Skip rows with missing name data
                    }
                    
                    $normalizedRegNum = normalizeRegistryNumber($registryNumber);
                    
                    $csvRecords[] = [
                        'registry_number' => formatRegistryNumber($registryNumber),
                        'registry_number_normalized' => $normalizedRegNum,
                        'last_name' => $lastName,
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'husbands_surname' => $husbandsSurname,
                        'birthday' => isset($headerMap['birthday']) ? trim($row[$headerMap['birthday']] ?? '') : '',
                        'cfo_classification' => isset($headerMap['cfo_classification']) ? trim($row[$headerMap['cfo_classification']] ?? '') : '',
                        'line_number' => $lineNumber
                    ];
                    
                    $csvRegistryNumbers[] = $normalizedRegNum;
                }
                
                fclose($handle);
                
                // Debug: Log CSV parsing results
                error_log("=== CSV Parsing Summary ===");
                error_log("Total rows read from CSV: $totalRowsRead");
                error_log("Skipped empty rows: $skippedEmptyRows");
                error_log("Skipped (no registry number): $skippedNoRegistryNumber");
                error_log("Skipped (no name): $skippedNoName");
                error_log("Valid CSV records parsed: " . count($csvRecords));
                error_log("CSV registry numbers collected: " . count($csvRegistryNumbers));
                if (count($csvRegistryNumbers) > 0) {
                    error_log("First 5 CSV registry numbers: " . implode(", ", array_slice($csvRegistryNumbers, 0, 5)));
                }
                
                if (empty($csvRecords)) {
                    throw new Exception('No valid records found in CSV file.');
                }
                
                // Get current CFO registry for this district/local
                $stmt = $db->prepare("
                    SELECT 
                        id,
                        registry_number_encrypted,
                        district_code
                    FROM tarheta_control
                    WHERE district_code = ? 
                    AND local_code = ?
                    AND cfo_status = 'active'
                ");
                $stmt->execute([$districtCode, $localCode]);
                $currentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Build map of current records with normalized registry numbers
                $currentRecordsMap = [];
                $currentNormalizedNumbers = [];
                foreach ($currentRecords as $record) {
                    $decryptedRegNum = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
                    $normalizedRegNum = normalizeRegistryNumber($decryptedRegNum);
                    $currentRecordsMap[$normalizedRegNum] = $record['id'];
                    $currentNormalizedNumbers[] = $normalizedRegNum; // For debugging
                }
                
                // Debug: Log the counts
                error_log("CSV Records: " . count($csvRegistryNumbers));
                error_log("Current Active Records: " . count($currentRecordsMap));
                error_log("Sample CSV Numbers: " . implode(", ", array_slice($csvRegistryNumbers, 0, 3)));
                error_log("Sample DB Numbers: " . implode(", ", array_slice($currentNormalizedNumbers, 0, 3)));
                
                // Determine which records to transfer out (in current but not in CSV)
                $toTransferOut = array_diff(array_keys($currentRecordsMap), $csvRegistryNumbers);
                
                // Determine which records to add (in CSV but not in current)
                $toAdd = array_diff($csvRegistryNumbers, array_keys($currentRecordsMap));
                
                // Debug: Log comparison results
                error_log("To Transfer Out: " . count($toTransferOut));
                error_log("To Add: " . count($toAdd));
                if (!empty($toTransferOut)) {
                    error_log("Sample Transfer Out: " . implode(", ", array_slice($toTransferOut, 0, 3)));
                }
                if (!empty($toAdd)) {
                    error_log("Sample To Add: " . implode(", ", array_slice($toAdd, 0, 3)));
                }
                
                // Get details of records to transfer out for preview
                $transferOutDetails = [];
                if (!empty($toTransferOut)) {
                    $idsToTransferOut = array_values(array_intersect_key($currentRecordsMap, array_flip($toTransferOut)));
                    $placeholders = implode(',', array_fill(0, count($idsToTransferOut), '?'));
                    $stmt = $db->prepare("
                        SELECT id, last_name_encrypted, first_name_encrypted, registry_number_encrypted, district_code
                        FROM tarheta_control
                        WHERE id IN ($placeholders)
                    ");
                    $stmt->execute($idsToTransferOut);
                    $transferOutRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($transferOutRecords as $record) {
                        $transferOutDetails[] = [
                            'id' => $record['id'],
                            'registry_number' => Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']),
                            'last_name' => Encryption::decrypt($record['last_name_encrypted'], $record['district_code']),
                            'first_name' => Encryption::decrypt($record['first_name_encrypted'], $record['district_code'])
                        ];
                    }
                }
                
                // Separate records by action for preview
                $recordsToAdd = [];
                $recordsAlreadyActive = [];
                $recordsSkipped = [];
                $errors = [];
                
                foreach ($csvRecords as $csvRecord) {
                    if (in_array($csvRecord['registry_number_normalized'], $toAdd)) {
                        // Check if registry number already exists (duplicate check)
                        $registryNumberHash = hash('sha256', strtolower($csvRecord['registry_number_normalized']));
                        $stmt = $db->prepare("SELECT id FROM tarheta_control WHERE registry_number_hash = ?");
                        $stmt->execute([$registryNumberHash]);
                        
                        if ($stmt->fetch()) {
                            $errors[] = "Line {$csvRecord['line_number']}: Registry number {$csvRecord['registry_number']} already exists in another district/local";
                            $recordsSkipped[] = $csvRecord;
                            continue;
                        }
                        
                        // Auto-classify if not provided in CSV
                        $cfoClassification = $csvRecord['cfo_classification'];
                        $cfoClassificationAuto = false;
                        $age = null;
                        
                        if (!empty($csvRecord['birthday'])) {
                            try {
                                $birthdayDate = new DateTime($csvRecord['birthday']);
                                $today = new DateTime();
                                $age = $today->diff($birthdayDate)->y;
                            } catch (Exception $e) {
                                // Invalid birthday
                            }
                        }
                        
                        if (empty($cfoClassification) || !in_array($cfoClassification, ['Buklod', 'Kadiwa', 'Binhi'])) {
                            if (!empty($csvRecord['birthday'])) {
                                // Priority: Married (Buklod) > Age-based (Kadiwa/Binhi)
                                if (!empty($csvRecord['husbands_surname']) && trim($csvRecord['husbands_surname']) !== '' && trim($csvRecord['husbands_surname']) !== '-') {
                                    $cfoClassification = 'Buklod';
                                    $cfoClassificationAuto = true;
                                } elseif ($age !== null) {
                                    if ($age < 13) {
                                        $cfoClassification = 'Binhi';
                                        $cfoClassificationAuto = true;
                                    } elseif ($age >= 13 && $age <= 35) {
                                        $cfoClassification = 'Kadiwa';
                                        $cfoClassificationAuto = true;
                                    } else {
                                        // Over 35, not married - default to Buklod
                                        $cfoClassification = 'Buklod';
                                        $cfoClassificationAuto = true;
                                    }
                                } else {
                                    // No age, but has husband surname - Buklod
                                    $cfoClassification = 'Buklod';
                                    $cfoClassificationAuto = true;
                                }
                            } else {
                                // No birthday - default to Buklod
                                $cfoClassification = 'Buklod';
                                $cfoClassificationAuto = true;
                            }
                        }
                        
                        $csvRecord['cfo_classification'] = $cfoClassification;
                        $csvRecord['cfo_classification_auto'] = $cfoClassificationAuto;
                        $csvRecord['age'] = $age;
                        
                        // Parse purok-grupo (format: "1-7" → purok=1, grupo=7)
                        $csvRecord['purok'] = '';
                        $csvRecord['grupo'] = '';
                        if (isset($headerMap['purok_grupo'])) {
                            $purokGrupoValue = trim($row[$headerMap['purok_grupo']] ?? '');
                            if (!empty($purokGrupoValue)) {
                                // Split on hyphen or dash
                                $parts = preg_split('/[-–—]/', $purokGrupoValue, 2);
                                if (count($parts) >= 1) {
                                    $csvRecord['purok'] = trim($parts[0]);
                                }
                                if (count($parts) >= 2) {
                                    $csvRecord['grupo'] = trim($parts[1]);
                                }
                            }
                        }
                        
                        $recordsToAdd[] = $csvRecord;
                        
                    } elseif (isset($currentRecordsMap[$csvRecord['registry_number_normalized']])) {
                        $recordsAlreadyActive[] = $csvRecord;
                    }
                }
                
                // If this is just a preview, show the preview
                if ($action === 'preview') {
                    $isPreview = true;
                    $previewData = [
                        'district_code' => $districtCode,
                        'local_code' => $localCode,
                        'total_csv' => count($csvRecords),
                        'total_rows_read' => $totalRowsRead,
                        'skipped_empty_rows' => $skippedEmptyRows,
                        'skipped_no_registry' => $skippedNoRegistryNumber,
                        'skipped_no_name' => $skippedNoName,
                        'records_to_add' => $recordsToAdd,
                        'records_transfer_out' => $transferOutDetails,
                        'records_already_active' => $recordsAlreadyActive,
                        'records_skipped' => $recordsSkipped,
                        'errors' => $errors
                    ];
                } else {
                    // Execute the import
                    $db->beginTransaction();
                    
                    $transferredCount = 0;
                    if (!empty($toTransferOut)) {
                        $idsToTransferOut = array_values(array_intersect_key($currentRecordsMap, array_flip($toTransferOut)));
                        $placeholders = implode(',', array_fill(0, count($idsToTransferOut), '?'));
                        $stmt = $db->prepare("
                            UPDATE tarheta_control 
                            SET 
                                cfo_status = 'transferred-out',
                                cfo_updated_at = NOW(),
                                cfo_updated_by = ?
                            WHERE id IN ($placeholders)
                        ");
                        $params = array_merge([$currentUser['user_id']], $idsToTransferOut);
                        $stmt->execute($params);
                        $transferredCount = $stmt->rowCount();
                    }
                    
                    // Add new records
                    $addedCount = 0;
                    $importBatch = 'CSV_' . date('Ymd_His');
                    
                    foreach ($recordsToAdd as $csvRecord) {
                        $lastNameEnc = Encryption::encrypt($csvRecord['last_name'], $districtCode);
                        $firstNameEnc = Encryption::encrypt($csvRecord['first_name'], $districtCode);
                        $middleNameEnc = !empty($csvRecord['middle_name']) ? Encryption::encrypt($csvRecord['middle_name'], $districtCode) : null;
                        $husbandsSurnameEnc = !empty($csvRecord['husbands_surname']) ? Encryption::encrypt($csvRecord['husbands_surname'], $districtCode) : null;
                        $registryNumberEnc = Encryption::encrypt($csvRecord['registry_number'], $districtCode);
                        $registryNumberHash = hash('sha256', strtolower($csvRecord['registry_number_normalized']));
                        
                        $birthdayEnc = null;
                        if (!empty($csvRecord['birthday'])) {
                            try {
                                $birthdayDate = new DateTime($csvRecord['birthday']);
                                $birthdayEnc = Encryption::encrypt($birthdayDate->format('Y-m-d'), $districtCode);
                            } catch (Exception $e) {
                                // Skip
                            }
                        }
                        
                        $stmt = $db->prepare("
                            INSERT INTO tarheta_control (
                                last_name_encrypted, first_name_encrypted, middle_name_encrypted,
                                husbands_surname_encrypted, registry_number_encrypted, registry_number_hash,
                                district_code, local_code, birthday_encrypted, cfo_classification, 
                                cfo_classification_auto, cfo_status, purok, grupo, import_batch, imported_at, imported_by,
                                cfo_updated_at, cfo_updated_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, NOW(), ?, NOW(), ?)
                        ");
                        
                        $stmt->execute([
                            $lastNameEnc, $firstNameEnc, $middleNameEnc,
                            $husbandsSurnameEnc, $registryNumberEnc, $registryNumberHash,
                            $districtCode, $localCode, $birthdayEnc, $csvRecord['cfo_classification'],
                            $csvRecord['cfo_classification_auto'] ? 1 : 0, 
                            !empty($csvRecord['purok']) ? $csvRecord['purok'] : null,
                            !empty($csvRecord['grupo']) ? $csvRecord['grupo'] : null,
                            $importBatch, $currentUser['user_id'], $currentUser['user_id']
                        ]);
                        
                        $addedCount++;
                    }
                    
                    $db->commit();
                    
                    $importResults = [
                        'total_csv' => count($csvRecords),
                        'added' => $addedCount,
                        'updated' => count($recordsAlreadyActive),
                        'transferred_out' => $transferredCount,
                        'skipped' => count($recordsSkipped),
                        'errors' => $errors
                    ];
                    
                    $success = "Import completed successfully! Added: $addedCount, Transferred Out: $transferredCount, Already Active: " . count($recordsAlreadyActive);
                }
                
                showMappingScreen:
                
            } catch (Exception $e) {
                if ($action === 'confirm') {
                    $db->rollBack();
                }
                $error = 'Import failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Import CFO Members';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Import CFO Members</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Import from CSV with smart registry number recognition</p>
            </div>
            <a href="cfo-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
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
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-purple-300 dark:border-purple-700 border-2 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-purple-900 dark:text-purple-300">🔗 Map CSV Columns</h2>
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
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('import_cfo'); ?>">
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
                    
                    <!-- Full Name (if single column format) -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Full Name <span class="text-gray-500">(Optional - if using single column)</span>
                        </label>
                        <select name="map_full_name" id="map_full_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Format: LAST NAME, FIRST NAME, MIDDLE NAME, HUSBANDS SURNAME</p>
                    </div>
                    
                    <!-- Last Name -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            Last Name <span class="text-gray-500">(Required if not using Full Name)</span>
                        </label>
                        <select name="map_last_name" id="map_last_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- First Name -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">
                            First Name <span class="text-gray-500">(Required if not using Full Name)</span>
                        </label>
                        <select name="map_first_name" id="map_first_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Middle Name -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Middle Name <span class="text-gray-500">(Optional)</span>
                        </label>
                        <select name="map_middle_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Husband's Surname -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Husband's Surname <span class="text-gray-500">(Optional)</span>
                        </label>
                        <select name="map_husbands_surname" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Birthday -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Birthday <span class="text-gray-500">(Optional)</span>
                        </label>
                        <select name="map_birthday" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- CFO Classification -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            CFO Classification <span class="text-gray-500">(Optional - auto-classified if empty)</span>
                        </label>
                        <select name="map_cfo_classification" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Purok-Grupo -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Purok-Grupo <span class="text-gray-500">(Optional - format: 1-7)</span>
                        </label>
                        <select name="map_purok_grupo" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">-- Skip this field --</option>
                            <?php foreach ($columnMappingData['csv_columns'] as $col): ?>
                                <option value="<?php echo Security::escape($col); ?>"><?php echo Security::escape($col); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Format example: 1-7 (Purok 1, Grupo 7)</p>
                    </div>
                </div>
                
                <div class="flex justify-between items-center border-t border-gray-300 pt-6">
                    <a href="cfo-import.php" class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-semibold transition-colors">
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
                'full_name': document.querySelector('[name="map_full_name"]').value,
                'last_name': document.querySelector('[name="map_last_name"]').value,
                'first_name': document.querySelector('[name="map_first_name"]').value,
                'middle_name': document.querySelector('[name="map_middle_name"]').value,
                'husbands_surname': document.querySelector('[name="map_husbands_surname"]').value,
                'birthday': document.querySelector('[name="map_birthday"]').value,
                'cfo_classification': document.querySelector('[name="map_cfo_classification"]').value,
                'purok_grupo': document.querySelector('[name="map_purok_grupo"]').value
            };
            document.getElementById('column_mapping_hidden').value = JSON.stringify(mapping);
        });
        
        // Validate that either full_name OR (first_name AND last_name) is selected
        document.getElementById('mappingForm').addEventListener('submit', function(e) {
            const fullName = document.querySelector('[name="map_full_name"]').value;
            const firstName = document.querySelector('[name="map_first_name"]').value;
            const lastName = document.querySelector('[name="map_last_name"]').value;
            
            if (!fullName && (!firstName || !lastName)) {
                e.preventDefault();
                alert('Please map either:\n• Full Name column, OR\n• Both First Name and Last Name columns');
                return false;
            }
        });
        </script>
        
    <?php elseif ($previewData): ?>
        <!-- Preview Import -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-blue-300 dark:border-blue-700 border-2 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-blue-900 dark:text-blue-100">📋 Import Preview</h2>
                <span class="px-3 py-1 bg-blue-100 text-blue-800 text-sm font-semibold rounded-full">PREVIEW MODE</span>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 p-4 rounded-lg mb-6">
                <p class="text-sm text-blue-900">
                    <strong>Review the changes below before proceeding.</strong> No changes have been made to the database yet.
                </p>
                <?php 
                $totalParsing = ($previewData['skipped_empty_rows'] ?? 0) + ($previewData['skipped_no_registry'] ?? 0) + ($previewData['skipped_no_name'] ?? 0);
                if ($totalParsing > 0): 
                ?>
                <div class="mt-3 p-3 bg-yellow-50 border border-yellow-300 rounded">
                    <p class="text-xs text-yellow-900 font-semibold">⚠️ Parsing Summary:</p>
                    <ul class="text-xs text-yellow-800 mt-1 space-y-0.5">
                        <li>• Total rows in CSV file: <strong><?php echo $previewData['total_rows_read'] ?? 0; ?></strong></li>
                        <li>• Valid records parsed: <strong><?php echo $previewData['total_csv']; ?></strong></li>
                        <?php if (($previewData['skipped_empty_rows'] ?? 0) > 0): ?>
                        <li>• Skipped empty rows: <strong><?php echo $previewData['skipped_empty_rows']; ?></strong></li>
                        <?php endif; ?>
                        <?php if (($previewData['skipped_no_registry'] ?? 0) > 0): ?>
                        <li>• Skipped (missing registry number): <strong><?php echo $previewData['skipped_no_registry']; ?></strong></li>
                        <?php endif; ?>
                        <?php if (($previewData['skipped_no_name'] ?? 0) > 0): ?>
                        <li>• Skipped (missing name): <strong><?php echo $previewData['skipped_no_name']; ?></strong></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Summary Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                    <p class="text-sm text-green-600 font-medium">Will Add</p>
                    <p class="text-3xl font-bold text-green-900"><?php echo count($previewData['records_to_add']); ?></p>
                </div>
                <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                    <p class="text-sm text-yellow-600 font-medium">Already Active</p>
                    <p class="text-3xl font-bold text-yellow-900"><?php echo count($previewData['records_already_active']); ?></p>
                </div>
                <div class="bg-orange-50 p-4 rounded-lg border border-orange-200">
                    <p class="text-sm text-orange-600 font-medium">Will Transfer Out</p>
                    <p class="text-3xl font-bold text-orange-900"><?php echo count($previewData['records_transfer_out']); ?></p>
                </div>
                <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                    <p class="text-sm text-red-600 font-medium">Skipped/Errors</p>
                    <p class="text-3xl font-bold text-red-900"><?php echo count($previewData['records_skipped']); ?></p>
                </div>
            </div>
            
            <!-- Records to Add -->
            <?php if (!empty($previewData['records_to_add'])): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-green-900 mb-3">✅ Records to Add (<?php echo count($previewData['records_to_add']); ?>)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-green-50 border-b border-green-200">
                                <tr>
                                    <th class="px-4 py-2 text-left text-green-900">Registry Number</th>
                                    <th class="px-4 py-2 text-left text-green-900">Name</th>
                                    <th class="px-4 py-2 text-left text-green-900">Birthday</th>
                                    <th class="px-4 py-2 text-left text-green-900">Classification</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach (array_slice($previewData['records_to_add'], 0, 20) as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 font-mono text-xs"><?php echo Security::escape($record['registry_number']); ?></td>
                                        <td class="px-4 py-2">
                                            <?php 
                                            $name = Security::escape($record['last_name']) . ', ' . Security::escape($record['first_name']);
                                            if (!empty($record['middle_name'])) $name .= ' ' . Security::escape($record['middle_name']);
                                            echo $name;
                                            ?>
                                        </td>
                                        <td class="px-4 py-2"><?php echo Security::escape($record['birthday'] ?: '-'); ?></td>
                                        <td class="px-4 py-2">
                                            <span class="px-2 py-1 rounded text-xs font-medium <?php 
                                                echo $record['cfo_classification'] === 'Buklod' ? 'bg-purple-100 text-purple-800' : 
                                                     ($record['cfo_classification'] === 'Kadiwa' ? 'bg-blue-100 text-blue-800' : 
                                                     ($record['cfo_classification'] === 'Binhi' ? 'bg-pink-100 text-pink-800' : 'bg-gray-100 text-gray-800')); 
                                            ?>">
                                                <?php echo Security::escape($record['cfo_classification'] ?: 'Unclassified'); ?>
                                                <?php if ($record['cfo_classification_auto']): ?>
                                                    <span title="Auto-classified">*</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($previewData['records_to_add']) > 20): ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-3 text-center text-gray-500 bg-gray-50">
                                            ... and <?php echo count($previewData['records_to_add']) - 20; ?> more
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Records to Transfer Out -->
            <?php if (!empty($previewData['records_transfer_out'])): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-orange-900 mb-3">⚠️ Records to Transfer Out (<?php echo count($previewData['records_transfer_out']); ?>)</h3>
                    <div class="bg-orange-50 border border-orange-200 p-3 rounded-lg mb-3">
                        <p class="text-sm text-orange-800">These members are currently active but not in the CSV file. They will be marked as transferred out.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-orange-50 border-b border-orange-200">
                                <tr>
                                    <th class="px-4 py-2 text-left text-orange-900">Registry Number</th>
                                    <th class="px-4 py-2 text-left text-orange-900">Name</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach (array_slice($previewData['records_transfer_out'], 0, 20) as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 font-mono text-xs"><?php echo Security::escape($record['registry_number']); ?></td>
                                        <td class="px-4 py-2"><?php echo Security::escape($record['last_name']) . ', ' . Security::escape($record['first_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($previewData['records_transfer_out']) > 20): ?>
                                    <tr>
                                        <td colspan="2" class="px-4 py-3 text-center text-gray-500 bg-gray-50">
                                            ... and <?php echo count($previewData['records_transfer_out']) - 20; ?> more
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Errors -->
            <?php if (!empty($previewData['errors'])): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-red-900 mb-3">❌ Errors (<?php echo count($previewData['errors']); ?>)</h3>
                    <div class="bg-red-50 border border-red-200 p-4 rounded-lg">
                        <ul class="list-disc list-inside text-sm text-red-800 space-y-1">
                            <?php foreach ($previewData['errors'] as $err): ?>
                                <li><?php echo Security::escape($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Confirm Import Form -->
            <form method="POST" enctype="multipart/form-data" class="border-t border-gray-300 pt-6">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('import_cfo'); ?>">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="district_code" value="<?php echo Security::escape($previewData['district_code']); ?>">
                <input type="hidden" name="local_code" value="<?php echo Security::escape($previewData['local_code']); ?>">
                <?php if (isset($_POST['column_mapping'])): ?>
                <input type="hidden" name="column_mapping" value="<?php echo Security::escape($_POST['column_mapping']); ?>">
                <?php endif; ?>
                
                <!-- Re-upload note -->
                <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg mb-4">
                    <p class="text-sm text-yellow-800">
                        <strong>Please re-upload the CSV file to confirm the import.</strong> This ensures the latest version is processed.
                    </p>
                </div>
                
                <!-- File Upload -->
                <div class="mb-4">
                    <label for="confirm_csv_file" class="block text-sm font-medium text-gray-700 mb-2">
                        CSV File <span class="text-red-500">*</span>
                    </label>
                    <input type="file" name="csv_file" id="confirm_csv_file" accept=".csv" required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                </div>
                
                <div class="flex justify-between items-center">
                    <a href="cfo-import.php" class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-semibold transition-colors">
                        Cancel
                    </a>
                    <button type="submit" class="px-8 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold transition-colors shadow-lg">
                        ✓ Confirm and Execute Import
                    </button>
                </div>
            </form>
        </div>
    <?php elseif ($importResults): ?>
        <!-- Import Results -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Import Results</h2>
            
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <p class="text-sm text-blue-600 font-medium">Total in CSV</p>
                    <p class="text-2xl font-bold text-blue-900"><?php echo $importResults['total_csv']; ?></p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <p class="text-sm text-green-600 font-medium">Added</p>
                    <p class="text-2xl font-bold text-green-900"><?php echo $importResults['added']; ?></p>
                </div>
                <div class="bg-yellow-50 p-4 rounded-lg">
                    <p class="text-sm text-yellow-600 font-medium">Already Active</p>
                    <p class="text-2xl font-bold text-yellow-900"><?php echo $importResults['updated']; ?></p>
                </div>
                <div class="bg-orange-50 p-4 rounded-lg">
                    <p class="text-sm text-orange-600 font-medium">Transferred Out</p>
                    <p class="text-2xl font-bold text-orange-900"><?php echo $importResults['transferred_out']; ?></p>
                </div>
                <div class="bg-red-50 p-4 rounded-lg">
                    <p class="text-sm text-red-600 font-medium">Skipped</p>
                    <p class="text-2xl font-bold text-red-900"><?php echo $importResults['skipped']; ?></p>
                </div>
            </div>
            
            <?php if (!empty($importResults['errors'])): ?>
                <div class="mt-4">
                    <h3 class="text-sm font-semibold text-red-900 mb-2">Errors:</h3>
                    <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                        <?php foreach ($importResults['errors'] as $err): ?>
                            <li><?php echo Security::escape($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- How it Works -->
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-blue-800">How Import Works</h3>
                <ul class="text-xs text-blue-700 mt-2 space-y-1">
                    <li>• <strong>Preview First:</strong> Review all changes before executing the import</li>
                    <li>• <strong>Smart Recognition:</strong> Handles registry numbers with or without spaces (e.g., "PPE 058 1 000 058" or "PPE0581000058")</li>
                    <li>• <strong>Transfer Out:</strong> Members not in CSV are automatically transferred out</li>
                    <li>• <strong>Add New:</strong> Members in CSV but not in registry are added</li>
                    <li>• <strong>Keep Active:</strong> Members in both CSV and registry remain active</li>
                    <li>• <strong>Auto-Classification:</strong> Automatically classifies as Buklod/Kadiwa/Binhi based on birthday and marital status</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- CSV Format Instructions -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">CSV File Format</h2>
        
        <div class="bg-blue-50 border border-blue-200 p-4 rounded-lg mb-4">
            <p class="text-sm text-blue-900 font-semibold mb-2">✅ Recommended Format (Single Column):</p>
            <ul class="list-disc list-inside text-sm text-blue-800 space-y-1">
                <li><code class="bg-blue-100 px-2 py-0.5 rounded">registry_number</code> - Registry/Control number (with or without spaces)</li>
                <li><code class="bg-blue-100 px-2 py-0.5 rounded">full_name</code> - Format: <strong>LAST NAME, FIRST NAME, MIDDLE NAME, HUSBANDS SURNAME</strong></li>
            </ul>
            <p class="text-xs text-blue-700 mt-2">
                Example: <code class="bg-blue-100 px-2 py-0.5 rounded">DELA CRUZ, MARIA, SANTOS, REYES</code>
            </p>
        </div>
        
        <div class="bg-gray-50 p-4 rounded-lg mb-4">
            <p class="text-sm text-gray-700 mb-2"><strong>Alternative Format (Separate Columns):</strong></p>
            <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                <li><code class="bg-gray-200 px-2 py-0.5 rounded">registry_number</code> - Registry/Control number</li>
                <li><code class="bg-gray-200 px-2 py-0.5 rounded">last_name</code> - Last name</li>
                <li><code class="bg-gray-200 px-2 py-0.5 rounded">first_name</code> - First name</li>
                <li><code class="bg-gray-200 px-2 py-0.5 rounded">middle_name</code> - Middle name (optional)</li>
                <li><code class="bg-gray-200 px-2 py-0.5 rounded">husbands_surname</code> - Husband's surname (optional)</li>
            </ul>
        </div>
        
        <div class="bg-gray-50 p-4 rounded-lg mb-4">
            <p class="text-sm text-gray-700 mb-2"><strong>Additional Optional Columns:</strong></p>
            <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                <li><code class="bg-gray-200 px-2 py-0.5 rounded">birthday</code> - Birthday in YYYY-MM-DD format</li>
                <li><code class="bg-gray-200 px-2 py-0.5 rounded">cfo_classification</code> - Buklod, Kadiwa, or Binhi (auto-classified if empty)</li>
            </ul>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 p-3 rounded-lg">
            <p class="text-xs text-yellow-800">
                <strong>Note:</strong> Column names are case-insensitive. Registry numbers can be in any format (with or without spaces). 
                The system will automatically parse the full_name field if provided.
            </p>
        </div>
    </div>

    <!-- Upload Form -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Upload CSV File</h2>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('import_cfo'); ?>">
            <input type="hidden" name="action" value="preview">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- District Selection -->
                <div>
                    <label for="district_code" class="block text-sm font-medium text-gray-700 mb-2">
                        District <span class="text-red-500">*</span>
                    </label>
                    <select name="district_code" id="district_code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select District</option>
                        <?php
                        // Get districts based on user role
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
                        
                        foreach ($districts as $district) {
                            $selected = '';
                            if ($currentUser['role'] === 'district' && $district['district_code'] === $currentUser['district_code']) {
                                $selected = 'selected';
                            }
                            echo '<option value="' . Security::escape($district['district_code']) . '" ' . $selected . '>' 
                                . Security::escape($district['district_name']) . ' (' . Security::escape($district['district_code']) . ')</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <!-- Local Selection -->
                <div>
                    <label for="local_code" class="block text-sm font-medium text-gray-700 mb-2">
                        Local Congregation <span class="text-red-500">*</span>
                    </label>
                    <select name="local_code" id="local_code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Local First</option>
                    </select>
                </div>
            </div>
            
            <!-- File Upload -->
            <div>
                <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">
                    CSV File <span class="text-red-500">*</span>
                </label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>
            
            <!-- Warning -->
            <div class="bg-red-50 border border-red-200 p-4 rounded-lg">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-red-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <h4 class="text-sm font-semibold text-red-900">Important Warning</h4>
                        <p class="text-xs text-red-700 mt-1">
                            This import will transfer out all CFO members that are NOT in the CSV file. 
                            Make sure your CSV contains all current active members before proceeding.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Submit Button -->
            <div class="flex justify-end gap-3">
                <a href="cfo-registry.php" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    Preview Import
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Dynamic local congregation loading
document.getElementById('district_code').addEventListener('change', function() {
    const districtCode = this.value;
    const localSelect = document.getElementById('local_code');
    
    localSelect.innerHTML = '<option value="">Loading...</option>';
    
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">Select District First</option>';
        return;
    }
    
    fetch(`/api/get-locals.php?district=${encodeURIComponent(districtCode)}`)
        .then(response => response.json())
        .then(data => {
            localSelect.innerHTML = '<option value="">Select Local Congregation</option>';
            
            if (Array.isArray(data)) {
                data.forEach(local => {
                    const option = document.createElement('option');
                    option.value = local.local_code;
                    option.textContent = `${local.local_name} (${local.local_code})`;
                    localSelect.appendChild(option);
                });
            } else {
                localSelect.innerHTML = '<option value="">Error loading locals</option>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            localSelect.innerHTML = '<option value="">Error loading locals</option>';
        });
});

// Auto-load locals if district is pre-selected
if (document.getElementById('district_code').value) {
    document.getElementById('district_code').dispatchEvent(new Event('change'));
}

// File validation
document.querySelector('form').addEventListener('submit', function(e) {
    const fileInput = document.getElementById('csv_file');
    const file = fileInput.files[0];
    
    if (file) {
        if (!file.name.toLowerCase().endsWith('.csv')) {
            e.preventDefault();
            alert('Please select a CSV file.');
            return;
        }
        
        if (file.size > 10 * 1024 * 1024) { // 10MB limit
            e.preventDefault();
            alert('File size must be less than 10MB.');
            return;
        }
    }
    
    // Confirm before importing
    if (!confirm('Are you sure you want to import this CSV?\n\nMembers not in the CSV will be transferred out.')) {
        e.preventDefault();
    }
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
?>
