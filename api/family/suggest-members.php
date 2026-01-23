<?php
/**
 * Suggest potential family members based on Pangulo's information
 * Uses intelligent matching criteria:
 * 1. Spouse from Buklod registry
 * 2. Matching last name
 * 3. Matching father's/mother's name (for HDB/PNK children)
 * 4. Middle name matching mother's maiden name
 * 5. Local learning from confirmed family patterns
 * 6. AI for relationship analysis
 */

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../includes/GeminiAI.php';
    require_once __DIR__ . '/../../includes/FamilyLearning.php';
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Load learning statistics for response
    $learningStats = FamilyLearning::getStatisticsForAI();

    $panguloId = intval($_GET['pangulo_id'] ?? 0);
    $useAI = ($_GET['use_ai'] ?? '1') === '1'; // Enable AI by default if available
    $asawaId = intval($_GET['asawa_id'] ?? 0); // Optional: selected asawa from Tarheta
    $asawaNameInput = trim($_GET['asawa_name'] ?? ''); // Optional: manually entered spouse/mother name
    
    if (!$panguloId) {
        echo json_encode(['success' => true, 'suggestions' => [], 'ai_enabled' => false]);
        exit;
    }
    
    $suggestions = [];
    
    // Build WHERE conditions based on user role
    $whereConditions = [];
    $params = [];
    
    if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        $whereConditions[] = 'local_code = ?';
        $params[] = $currentUser['local_code'];
    } elseif ($currentUser['role'] === 'district') {
        $whereConditions[] = 'district_code = ?';
        $params[] = $currentUser['district_code'];
    }
    
    $baseWhere = !empty($whereConditions) ? ' AND ' . implode(' AND ', $whereConditions) : '';
    
    // First, get the Pangulo's information
    $stmt = $db->prepare("
        SELECT id, first_name_encrypted, middle_name_encrypted, last_name_encrypted,
               registry_number_encrypted, cfo_classification, district_code, purok, grupo
        FROM tarheta_control 
        WHERE id = ?
    ");
    $stmt->execute([$panguloId]);
    $pangulo = $stmt->fetch();
    
    if (!$pangulo) {
        echo json_encode(['success' => true, 'suggestions' => [], 'ai_enabled' => false]);
        exit;
    }
    
    $panguloLastName = Encryption::decrypt($pangulo['last_name_encrypted'], $pangulo['district_code']);
    $panguloFirstName = Encryption::decrypt($pangulo['first_name_encrypted'], $pangulo['district_code']);
    $panguloMiddleName = $pangulo['middle_name_encrypted'] 
        ? Encryption::decrypt($pangulo['middle_name_encrypted'], $pangulo['district_code']) 
        : '';
    $panguloFullName = trim("$panguloFirstName $panguloMiddleName $panguloLastName");
    $panguloLastNameLower = mb_strtolower($panguloLastName, 'UTF-8');
    $panguloFirstNameLower = mb_strtolower($panguloFirstName, 'UTF-8');
    $panguloDistrict = $pangulo['district_code'];
    $panguloPurok = $pangulo['purok'];
    $panguloGrupo = $pangulo['grupo'];
    
    // Parse asawa name - either from Tarheta lookup (asawa_id) or manual input
    $asawaInputFirstName = '';
    $asawaInputLastName = '';
    
    // If asawa_id is provided, lookup from Tarheta for accurate name matching
    if ($asawaId > 0) {
        $stmt = $db->prepare("
            SELECT first_name_encrypted, last_name_encrypted, district_code
            FROM tarheta_control WHERE id = ?
        ");
        $stmt->execute([$asawaId]);
        $asawaRecord = $stmt->fetch();
        
        if ($asawaRecord) {
            $asawaInputFirstName = mb_strtolower(
                Encryption::decrypt($asawaRecord['first_name_encrypted'], $asawaRecord['district_code']), 
                'UTF-8'
            );
            $asawaInputLastName = mb_strtolower(
                Encryption::decrypt($asawaRecord['last_name_encrypted'], $asawaRecord['district_code']), 
                'UTF-8'
            );
        }
    } elseif (!empty($asawaNameInput)) {
        // Fallback to manual name parsing if no asawa_id
        $nameParts = preg_split('/\s+/', $asawaNameInput);
        if (count($nameParts) >= 2) {
            $asawaInputFirstName = mb_strtolower($nameParts[0], 'UTF-8');
            $asawaInputLastName = mb_strtolower(end($nameParts), 'UTF-8');
        } else {
            $asawaInputFirstName = mb_strtolower($nameParts[0], 'UTF-8');
        }
    }
    
    // Get spouse info for parent name matching
    $spouseFirstName = '';
    $spouseLastName = '';
    
    // ===== 1. Look for spouse in Buklod registry (if pangulo is Buklod) =====
    if ($pangulo['cfo_classification'] === 'Buklod') {
        try {
            $stmt = $db->prepare("
                SELECT id, husband_tarheta_id, wife_tarheta_id, registry_number_encrypted, district_code
                FROM buklod_registry 
                WHERE (husband_tarheta_id = ? OR wife_tarheta_id = ?)
                AND (status = 'active' OR status IS NULL)
            ");
            $stmt->execute([$panguloId, $panguloId]);
            $buklodRecord = $stmt->fetch();
            
            if ($buklodRecord) {
                $spouseId = ($buklodRecord['husband_tarheta_id'] == $panguloId) 
                    ? $buklodRecord['wife_tarheta_id'] 
                    : $buklodRecord['husband_tarheta_id'];
                
                if ($spouseId) {
                    $stmt = $db->prepare("
                        SELECT id, first_name_encrypted, middle_name_encrypted, last_name_encrypted,
                               registry_number_encrypted, cfo_classification, district_code
                        FROM tarheta_control 
                        WHERE id = ?
                    ");
                    $stmt->execute([$spouseId]);
                    $spouse = $stmt->fetch();
                    
                    if ($spouse) {
                        $spouseFirstName = Encryption::decrypt($spouse['first_name_encrypted'], $spouse['district_code']);
                        $spouseMiddleName = $spouse['middle_name_encrypted'] 
                            ? Encryption::decrypt($spouse['middle_name_encrypted'], $spouse['district_code']) 
                            : '';
                        $spouseLastName = Encryption::decrypt($spouse['last_name_encrypted'], $spouse['district_code']);
                        $spouseRegistry = Encryption::decrypt($spouse['registry_number_encrypted'], $spouse['district_code']);
                        
                        $suggestions[] = [
                            'id' => $spouse['id'],
                            'full_name' => trim("$spouseFirstName $spouseMiddleName $spouseLastName"),
                            'registry_number' => $spouseRegistry,
                            'kapisanan' => $spouse['cfo_classification'],
                            'source' => $spouse['cfo_classification'] ?: 'Tarheta',
                            'suggested_relasyon' => 'Asawa',
                            'match_type' => 'spouse',
                            'confidence' => 'high'
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // buklod_registry might not exist
        }
    }
    
    // ===== 1b. Add selected asawa from Tarheta (if provided and not already in suggestions) =====
    if ($asawaId > 0) {
        // Check if asawa is not already added as spouse from Buklod
        $alreadyAdded = false;
        foreach ($suggestions as $s) {
            if ($s['id'] == $asawaId && $s['source'] === 'Tarheta') {
                $alreadyAdded = true;
                break;
            }
        }
        
        if (!$alreadyAdded) {
            $stmt = $db->prepare("
                SELECT id, first_name_encrypted, middle_name_encrypted, last_name_encrypted,
                       registry_number_encrypted, cfo_classification, district_code
                FROM tarheta_control WHERE id = ?
            ");
            $stmt->execute([$asawaId]);
            $asawaRecord = $stmt->fetch();
            
            if ($asawaRecord) {
                $asawaFirst = Encryption::decrypt($asawaRecord['first_name_encrypted'], $asawaRecord['district_code']);
                $asawaMiddle = $asawaRecord['middle_name_encrypted'] 
                    ? Encryption::decrypt($asawaRecord['middle_name_encrypted'], $asawaRecord['district_code']) 
                    : '';
                $asawaLast = Encryption::decrypt($asawaRecord['last_name_encrypted'], $asawaRecord['district_code']);
                $asawaRegistry = Encryption::decrypt($asawaRecord['registry_number_encrypted'], $asawaRecord['district_code']);
                
                // Also set spouse name for parent matching
                $spouseFirstName = $asawaFirst;
                $spouseLastName = $asawaLast;
                
                $suggestions[] = [
                    'id' => $asawaRecord['id'],
                    'full_name' => trim("$asawaFirst $asawaMiddle $asawaLast"),
                    'registry_number' => $asawaRegistry,
                    'kapisanan' => $asawaRecord['cfo_classification'],
                    'source' => $asawaRecord['cfo_classification'] ?: 'Tarheta',
                    'suggested_relasyon' => 'Asawa',
                    'match_type' => 'selected_asawa',
                    'confidence' => 'high'
                ];
            }
        }
    }
    
    // Get list of tarheta IDs already in families (to exclude from suggestions)
    $existingFamilyMemberIds = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT tarheta_id FROM family_members 
            WHERE tarheta_id IS NOT NULL 
            AND family_id IN (SELECT id FROM families WHERE deleted_at IS NULL)
        ");
        $stmt->execute();
        $existingFamilyMemberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Build exclusion list (pangulo + asawa + existing family members)
    $excludeIds = array_merge([$panguloId], $existingFamilyMemberIds);
    if ($asawaId > 0) {
        $excludeIds[] = $asawaId;
    }
    $excludeIds = array_unique($excludeIds);
    
    // ===== 2. Find Tarheta members with matching criteria =====
    // Only get high-confidence matches
    $stmt = $db->prepare("
        SELECT id, first_name_encrypted, middle_name_encrypted, last_name_encrypted,
               registry_number_encrypted, cfo_classification, district_code, purok, grupo
        FROM tarheta_control 
        WHERE id != ? 
        AND (cfo_status = 'active' OR cfo_status IS NULL)
        $baseWhere
    ");
    $execParams = array_merge([$panguloId], $params);
    $stmt->execute($execParams);
    $tarhetaRecords = $stmt->fetchAll();
    
    foreach ($tarhetaRecords as $record) {
        // Skip if already in a family or is the selected asawa
        if (in_array($record['id'], $excludeIds)) {
            continue;
        }
        
        try {
            $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
            $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
            $middleName = $record['middle_name_encrypted'] 
                ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) 
                : '';
            $lastNameLower = mb_strtolower($lastName, 'UTF-8');
            $firstNameLower = mb_strtolower($firstName, 'UTF-8');
            $middleNameLower = mb_strtolower($middleName, 'UTF-8');
            
            $matchType = null;
            $suggestedRelasyon = '';
            $confidence = 'medium';
            
            // Check if this person matches the entered Asawa name (high confidence only)
            if ($asawaInputFirstName && $asawaInputLastName) {
                // Require BOTH first and last name match for high confidence
                if ($firstNameLower === $asawaInputFirstName && $lastNameLower === $asawaInputLastName) {
                    $matchType = 'asawa_input_match';
                    $suggestedRelasyon = 'Asawa';
                    $confidence = 'high';
                }
            }
            
            // Check if middle name matches asawa's last name (mother's maiden name convention)
            // RULE: Must ALSO have father's surname - otherwise likely from mother's side (not direct child)
            // If middle name = mother's maiden BUT lastname ≠ father's surname → EXCLUDE
            if (!$matchType && $asawaInputLastName && $middleNameLower) {
                if ($middleNameLower === $asawaInputLastName || str_contains($middleNameLower, $asawaInputLastName)) {
                    // CRITICAL: Only include if lastname matches Pangulo's (father's) surname
                    // Someone with mother's maiden name as middle BUT different lastname is likely:
                    // - Mother's niece/nephew (pamangkin from mother's side)
                    // - Mother's sibling's child
                    // - Unrelated person who happens to share mother's maiden name
                    $sameLastName = ($lastNameLower === $panguloLastNameLower);
                    
                    if ($sameLastName) {
                        $matchType = 'middle_name_mother_match';
                        $suggestedRelasyon = ''; // AI will determine - could be Anak, Apo, etc.
                        
                        // Consider location for confidence
                        $sameLocation = ($record['purok'] === $panguloPurok && $record['grupo'] === $panguloGrupo);
                        
                        if ($sameLocation) {
                            $confidence = 'high'; // Same lastname + mother's maiden + same location
                        } else {
                            $confidence = 'medium'; // Same lastname + mother's maiden name
                        }
                    }
                    // ELSE: Don't set matchType - this person will be excluded
                    // (has mother's maiden as middle name but different surname)
                }
            }
            
            // Check if last name matches Pangulo - STRICT: lastname alone is NOT enough
            // Same lastname does NOT mean family member - many unrelated people share surnames
            if (!$matchType && $lastNameLower === $panguloLastNameLower) {
                // ONLY include if there's ADDITIONAL evidence beyond just lastname:
                // 1. Same purok/grupo AND same classification (likely same household)
                // 2. Binhi AND same purok/grupo (likely child in same area)
                // 3. Middle name matches asawa's lastname (already handled above)
                
                // For Binhi in same purok/grupo - could be Anak, Apo, Pamangkin
                // DON'T assume Anak - let AI decide based on all context
                if ($record['cfo_classification'] === 'Binhi' && 
                    $record['purok'] === $panguloPurok && $record['grupo'] === $panguloGrupo) {
                    $matchType = 'lastname_same_location';
                    $suggestedRelasyon = '';  // AI will determine - could be Anak, Apo, Pamangkin
                    $confidence = 'medium'; // Needs AI verification
                }
                // For Kadiwa in exact same purok/grupo - could be Anak, kapatid, etc.
                elseif ($record['cfo_classification'] === 'Kadiwa' && 
                        $record['purok'] === $panguloPurok && $record['grupo'] === $panguloGrupo) {
                    $matchType = 'lastname_same_location';
                    $suggestedRelasyon = '';  // Let AI decide - could be sibling, child, etc.
                    $confidence = 'medium';
                }
                // DO NOT include Buklod with only lastname match - likely sibling, not household member
                // DO NOT include anyone with only lastname match but different location
            }
            
            // Only add if there's strong evidence (not just lastname match)
            // High confidence matches OR specific match types that need AI verification
            $validMatchTypes = [
                'spouse', 'selected_asawa', 'asawa_input_match',  // Spouse matches
                'father_match', 'mother_match', 'mother_asawa_input', 'father_asawa_input',  // Parent matches (HDB/PNK)
                'middle_name_mother_match',  // Filipino naming convention
                'lastname_same_location'  // Same location adds credibility - let AI decide
            ];
            
            if ($matchType && (in_array($matchType, $validMatchTypes) || $confidence === 'high')) {
                $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
                
                // Skip if already added (spouse from buklod)
                $alreadyAdded = false;
                foreach ($suggestions as $s) {
                    if (isset($s['id']) && $s['id'] == $record['id'] && $s['source'] !== 'HDB' && $s['source'] !== 'PNK') {
                        $alreadyAdded = true;
                        break;
                    }
                }
                if ($alreadyAdded) continue;
                
                // Check for learned relationship pattern (enhanced v2.0)
                $learnedSuggestion = FamilyLearning::suggestRelationship(
                    $panguloLastName,
                    $asawaInputLastName ?: null,
                    $lastName,
                    $middleName,
                    $record['cfo_classification'],
                    $matchType  // Pass match type for better accuracy learning
                );
                
                // Use learned suggestion if available and more confident
                $finalRelasyon = $suggestedRelasyon;
                $learnedReason = null;
                $learnedConfidence = 0;
                if ($learnedSuggestion && $learnedSuggestion['confidence'] > 0.6) {
                    $finalRelasyon = $learnedSuggestion['suggested_relasyon'];
                    $learnedReason = $learnedSuggestion['reason'];
                    $learnedConfidence = $learnedSuggestion['confidence'];
                    if ($learnedSuggestion['confidence'] > 0.8) {
                        $confidence = 'high';
                    }
                }
                
                $suggestions[] = [
                    'id' => $record['id'],
                    'full_name' => trim("$firstName $middleName $lastName"),
                    'registry_number' => $registryNumber,
                    'kapisanan' => $record['cfo_classification'],
                    'source' => $record['cfo_classification'] ?: 'Tarheta',
                    'purok' => $record['purok'],
                    'grupo' => $record['grupo'],
                    'suggested_relasyon' => $finalRelasyon,
                    'match_type' => $matchType,
                    'confidence' => $confidence,
                    'learned_confidence' => $learnedConfidence > 0 ? round($learnedConfidence * 100) : null,
                    'learned_reason' => $learnedReason
                ];
            }
        } catch (Exception $e) {
            continue;
        }
    }
    
    // ===== 3. Find HDB children - matching parent names (high confidence only) =====
    // Get HDB IDs already in families
    $existingHdbIds = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT hdb_id FROM family_members 
            WHERE hdb_id IS NOT NULL 
            AND family_id IN (SELECT id FROM families WHERE deleted_at IS NULL)
        ");
        $stmt->execute();
        $existingHdbIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
    
    try {
        $stmt = $db->prepare("
            SELECT id, child_first_name_encrypted, child_middle_name_encrypted, child_last_name_encrypted,
                   registry_number_encrypted, district_code,
                   father_first_name_encrypted, father_middle_name_encrypted, father_last_name_encrypted,
                   mother_first_name_encrypted, mother_middle_name_encrypted, mother_maiden_name_encrypted, mother_married_name_encrypted
            FROM hdb_registry 
            WHERE 1=1 $baseWhere
        ");
        $stmt->execute($params);
        $hdbRecords = $stmt->fetchAll();
        
        foreach ($hdbRecords as $record) {
            // Skip if already in a family
            if (in_array($record['id'], $existingHdbIds)) {
                continue;
            }
            
            try {
                $childLastName = Encryption::decrypt($record['child_last_name_encrypted'], $record['district_code']);
                $childLastNameLower = mb_strtolower($childLastName, 'UTF-8');
                
                // Decrypt parent names for matching
                $fatherFirstName = $record['father_first_name_encrypted'] 
                    ? Encryption::decrypt($record['father_first_name_encrypted'], $record['district_code']) 
                    : '';
                $fatherLastName = $record['father_last_name_encrypted'] 
                    ? Encryption::decrypt($record['father_last_name_encrypted'], $record['district_code']) 
                    : '';
                $motherFirstName = $record['mother_first_name_encrypted'] 
                    ? Encryption::decrypt($record['mother_first_name_encrypted'], $record['district_code']) 
                    : '';
                $motherMaidenName = $record['mother_maiden_name_encrypted'] 
                    ? Encryption::decrypt($record['mother_maiden_name_encrypted'], $record['district_code']) 
                    : '';
                
                // Check multiple matching criteria
                $matchType = null;
                $confidence = 'medium';
                
                // Criteria 1: Father's name matches Pangulo
                $fatherFirstLower = mb_strtolower($fatherFirstName, 'UTF-8');
                $fatherLastLower = mb_strtolower($fatherLastName, 'UTF-8');
                
                if ($fatherFirstLower && $fatherLastLower && 
                    $fatherFirstLower === $panguloFirstNameLower && 
                    $fatherLastLower === $panguloLastNameLower) {
                    $matchType = 'father_match';
                    $confidence = 'high';
                }
                
                // Criteria 2: Mother's name matches Pangulo
                $motherFirstLower = mb_strtolower($motherFirstName, 'UTF-8');
                $motherMaidenLower = mb_strtolower($motherMaidenName, 'UTF-8');
                
                if (!$matchType && $motherFirstLower && $motherMaidenLower && 
                    $motherFirstLower === $panguloFirstNameLower && 
                    $motherMaidenLower === $panguloLastNameLower) {
                    $matchType = 'mother_match';
                    $confidence = 'high';
                }
                
                // Criteria 3: Father's name matches Spouse (if we have spouse info)
                if (!$matchType && $spouseFirstName && $spouseLastName) {
                    $spouseFirstLower = mb_strtolower($spouseFirstName, 'UTF-8');
                    $spouseLastLower = mb_strtolower($spouseLastName, 'UTF-8');
                    
                    if ($fatherFirstLower && $fatherLastLower && 
                        $fatherFirstLower === $spouseFirstLower && 
                        $fatherLastLower === $spouseLastLower) {
                        $matchType = 'father_spouse_match';
                        $confidence = 'high';
                    }
                    
                    // Or Mother matches Spouse
                    if (!$matchType && $motherFirstLower && 
                        $motherFirstLower === $spouseFirstLower) {
                        $matchType = 'mother_spouse_match';
                        $confidence = 'high';
                    }
                }
                
                // Criteria 4: Mother's name matches manually entered Asawa name
                if (!$matchType && $asawaInputFirstName) {
                    // Check if mother matches the manually entered asawa name
                    if ($motherFirstLower && $motherFirstLower === $asawaInputFirstName) {
                        // If we also have last name, verify it too
                        if ($asawaInputLastName) {
                            if ($motherMaidenLower === $asawaInputLastName || 
                                mb_strtolower($record['mother_married_name_encrypted'] 
                                    ? Encryption::decrypt($record['mother_married_name_encrypted'], $record['district_code']) 
                                    : '', 'UTF-8') === $asawaInputLastName) {
                                $matchType = 'mother_asawa_input';
                                $confidence = 'high';
                            }
                        } else {
                            // First name only match
                            $matchType = 'mother_asawa_input';
                            $confidence = 'medium';
                        }
                    }
                    // Also check if father matches (in case pangulo is the mother)
                    if (!$matchType && $fatherFirstLower && $fatherFirstLower === $asawaInputFirstName) {
                        if ($asawaInputLastName && $fatherLastLower === $asawaInputLastName) {
                            $matchType = 'father_asawa_input';
                            $confidence = 'high';
                        } else if (!$asawaInputLastName) {
                            $matchType = 'father_asawa_input';
                            $confidence = 'medium';
                        }
                    }
                }
                
                // REMOVED: Criteria 5 (lastname only) - too low confidence, too many false positives
                // Only parent name matches are included for higher accuracy
                
                // Add if high-confidence match found (parent name matches only)
                if ($matchType && $confidence === 'high') {
                    $childFirstName = Encryption::decrypt($record['child_first_name_encrypted'], $record['district_code']);
                    $childMiddleName = $record['child_middle_name_encrypted'] 
                        ? Encryption::decrypt($record['child_middle_name_encrypted'], $record['district_code']) 
                        : '';
                    $registryNumber = $record['registry_number_encrypted'] 
                        ? Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']) 
                        : '';
                    
                    $suggestions[] = [
                        'id' => $record['id'],
                        'full_name' => trim("$childFirstName $childMiddleName $childLastName"),
                        'registry_number' => $registryNumber,
                        'kapisanan' => 'HDB',
                        'source' => 'HDB',
                        'suggested_relasyon' => 'Anak',
                        'match_type' => $matchType,
                        'confidence' => $confidence,
                        'father_name' => trim("$fatherFirstName $fatherLastName"),
                        'mother_name' => trim("$motherFirstName $motherMaidenName")
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }
    } catch (Exception $e) {
        // HDB table might not exist or missing columns
    }
    
    // ===== 4. Find PNK children - matching parent names (high confidence only) =====
    // Get PNK IDs already in families
    $existingPnkIds = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT pnk_id FROM family_members 
            WHERE pnk_id IS NOT NULL 
            AND family_id IN (SELECT id FROM families WHERE deleted_at IS NULL)
        ");
        $stmt->execute();
        $existingPnkIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
    
    try {
        $stmt = $db->prepare("
            SELECT id, child_first_name_encrypted, child_middle_name_encrypted, child_last_name_encrypted,
                   registry_number_encrypted, district_code,
                   father_first_name_encrypted, father_middle_name_encrypted, father_last_name_encrypted,
                   mother_first_name_encrypted, mother_middle_name_encrypted, mother_maiden_name_encrypted, mother_married_name_encrypted
            FROM pnk_registry 
            WHERE 1=1 $baseWhere
        ");
        $stmt->execute($params);
        $pnkRecords = $stmt->fetchAll();
        
        foreach ($pnkRecords as $record) {
            // Skip if already in a family
            if (in_array($record['id'], $existingPnkIds)) {
                continue;
            }
            
            try {
                $childLastName = Encryption::decrypt($record['child_last_name_encrypted'], $record['district_code']);
                $childLastNameLower = mb_strtolower($childLastName, 'UTF-8');
                
                // Decrypt parent names
                $fatherFirstName = $record['father_first_name_encrypted'] 
                    ? Encryption::decrypt($record['father_first_name_encrypted'], $record['district_code']) 
                    : '';
                $fatherLastName = $record['father_last_name_encrypted'] 
                    ? Encryption::decrypt($record['father_last_name_encrypted'], $record['district_code']) 
                    : '';
                $motherFirstName = $record['mother_first_name_encrypted'] 
                    ? Encryption::decrypt($record['mother_first_name_encrypted'], $record['district_code']) 
                    : '';
                $motherMaidenName = $record['mother_maiden_name_encrypted'] 
                    ? Encryption::decrypt($record['mother_maiden_name_encrypted'], $record['district_code']) 
                    : '';
                
                $matchType = null;
                $confidence = 'medium';
                
                // Check father match
                $fatherFirstLower = mb_strtolower($fatherFirstName, 'UTF-8');
                $fatherLastLower = mb_strtolower($fatherLastName, 'UTF-8');
                
                if ($fatherFirstLower && $fatherLastLower && 
                    $fatherFirstLower === $panguloFirstNameLower && 
                    $fatherLastLower === $panguloLastNameLower) {
                    $matchType = 'father_match';
                    $confidence = 'high';
                }
                
                // Check mother match
                $motherFirstLower = mb_strtolower($motherFirstName, 'UTF-8');
                $motherMaidenLower = mb_strtolower($motherMaidenName, 'UTF-8');
                
                if (!$matchType && $motherFirstLower && $motherMaidenLower && 
                    $motherFirstLower === $panguloFirstNameLower && 
                    $motherMaidenLower === $panguloLastNameLower) {
                    $matchType = 'mother_match';
                    $confidence = 'high';
                }
                
                // Check spouse matches
                if (!$matchType && $spouseFirstName && $spouseLastName) {
                    $spouseFirstLower = mb_strtolower($spouseFirstName, 'UTF-8');
                    $spouseLastLower = mb_strtolower($spouseLastName, 'UTF-8');
                    
                    if ($fatherFirstLower && $fatherLastLower && 
                        $fatherFirstLower === $spouseFirstLower && 
                        $fatherLastLower === $spouseLastLower) {
                        $matchType = 'father_spouse_match';
                        $confidence = 'high';
                    }
                    
                    if (!$matchType && $motherFirstLower && 
                        $motherFirstLower === $spouseFirstLower) {
                        $matchType = 'mother_spouse_match';
                        $confidence = 'high';
                    }
                }
                
                // Check manually entered asawa name
                if (!$matchType && $asawaInputFirstName) {
                    if ($motherFirstLower && $motherFirstLower === $asawaInputFirstName) {
                        if ($asawaInputLastName) {
                            if ($motherMaidenLower === $asawaInputLastName || 
                                mb_strtolower($record['mother_married_name_encrypted'] 
                                    ? Encryption::decrypt($record['mother_married_name_encrypted'], $record['district_code']) 
                                    : '', 'UTF-8') === $asawaInputLastName) {
                                $matchType = 'mother_asawa_input';
                                $confidence = 'high';
                            }
                        } else {
                            $matchType = 'mother_asawa_input';
                            $confidence = 'medium';
                        }
                    }
                    if (!$matchType && $fatherFirstLower && $fatherFirstLower === $asawaInputFirstName) {
                        if ($asawaInputLastName && $fatherLastLower === $asawaInputLastName) {
                            $matchType = 'father_asawa_input';
                            $confidence = 'high';
                        } else if (!$asawaInputLastName) {
                            $matchType = 'father_asawa_input';
                            $confidence = 'medium';
                        }
                    }
                }
                
                // REMOVED: Last name only match - too many false positives
                // Only parent name matches included for higher accuracy
                
                // Add only high-confidence matches
                if ($matchType && $confidence === 'high') {
                    $childFirstName = Encryption::decrypt($record['child_first_name_encrypted'], $record['district_code']);
                    $childMiddleName = $record['child_middle_name_encrypted'] 
                        ? Encryption::decrypt($record['child_middle_name_encrypted'], $record['district_code']) 
                        : '';
                    $registryNumber = $record['registry_number_encrypted'] 
                        ? Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']) 
                        : '';
                    
                    $suggestions[] = [
                        'id' => $record['id'],
                        'full_name' => trim("$childFirstName $childMiddleName $childLastName"),
                        'registry_number' => $registryNumber,
                        'kapisanan' => 'PNK',
                        'source' => 'PNK',
                        'suggested_relasyon' => 'Anak',
                        'match_type' => $matchType,
                        'confidence' => $confidence,
                        'father_name' => trim("$fatherFirstName $fatherLastName"),
                        'mother_name' => trim("$motherFirstName $motherMaidenName")
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }
    } catch (Exception $e) {
        // PNK table might not exist
    }
    
    // ===== 5. Use Gemini AI for intelligent relationship analysis =====
    $aiEnabled = false;
    if ($useAI && GeminiAI::isAvailable() && !empty($suggestions)) {
        try {
            $panguloData = [
                'full_name' => $panguloFullName,
                'kapisanan' => $pangulo['cfo_classification'],
                'father_name' => '', // Could be added if available
                'mother_name' => ''
            ];
            
            $suggestions = GeminiAI::analyzeFamilyRelationships($panguloData, $suggestions);
            $aiEnabled = true;
        } catch (Exception $e) {
            error_log("Gemini AI analysis failed: " . $e->getMessage());
        }
    }
    
    // Sort: spouse/selected_asawa first, then middle name mother match, then parent matches, then by confidence
    usort($suggestions, function($a, $b) {
        // Spouse from buklod or selected asawa first
        $spouseTypes = ['spouse', 'selected_asawa'];
        $aIsSpouse = in_array($a['match_type'], $spouseTypes);
        $bIsSpouse = in_array($b['match_type'], $spouseTypes);
        if ($aIsSpouse && !$bIsSpouse) return -1;
        if ($bIsSpouse && !$aIsSpouse) return 1;
        
        // Asawa input match (manual entry match) second
        if ($a['match_type'] === 'asawa_input_match' && $b['match_type'] !== 'asawa_input_match') return -1;
        if ($b['match_type'] === 'asawa_input_match' && $a['match_type'] !== 'asawa_input_match') return 1;
        
        // Middle name matches mother's maiden name (high confidence child)
        if ($a['match_type'] === 'middle_name_mother_match' && $b['match_type'] !== 'middle_name_mother_match') return -1;
        if ($b['match_type'] === 'middle_name_mother_match' && $a['match_type'] !== 'middle_name_mother_match') return 1;
        
        // Parent matches (child records where parent name matches)
        $parentMatches = ['father_match', 'mother_match', 'father_spouse_match', 'mother_spouse_match', 'mother_asawa_input', 'father_asawa_input'];
        $aIsParent = in_array($a['match_type'], $parentMatches);
        $bIsParent = in_array($b['match_type'], $parentMatches);
        if ($aIsParent && !$bIsParent) return -1;
        if ($bIsParent && !$aIsParent) return 1;
        
        // Then by confidence
        $confOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        return ($confOrder[$a['confidence']] ?? 2) - ($confOrder[$b['confidence']] ?? 2);
    });
    
    echo json_encode([
        'success' => true,
        'pangulo_last_name' => $panguloLastName,
        'pangulo_full_name' => $panguloFullName,
        'suggestions' => $suggestions,
        'ai_enabled' => $aiEnabled,
        'learning_stats' => [
            'total_families' => $learningStats['statistics']['total_families'] ?? 0,
            'total_members' => $learningStats['statistics']['total_members'] ?? 0,
            'overall_accuracy' => $learningStats['statistics']['overall_accuracy'] ?? 0,
            'total_suggestions_shown' => $learningStats['statistics']['total_suggestions_shown'] ?? 0,
            'total_suggestions_accepted' => $learningStats['statistics']['total_suggestions_accepted'] ?? 0,
            'top_match_types' => $learningStats['top_match_types'] ?? [],
            'derived_rules_count' => $learningStats['derived_rules_count'] ?? []
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
