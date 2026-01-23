<?php
/**
 * Family Learning System v2.0
 * Advanced behavioral learning to achieve 98% suggestion accuracy
 * 
 * Tracks:
 * - Suggestion acceptance/rejection rates
 * - Relationship corrections (suggested vs actual)
 * - Match type success rates
 * - Naming pattern correlations
 * - Kapisanan-relationship tendencies
 * - User behavior patterns
 */

class FamilyLearning {
    private static $learningsFile = null;
    
    private static function getFilePath(): string {
        if (self::$learningsFile === null) {
            self::$learningsFile = __DIR__ . '/../data/family_learnings.json';
        }
        return self::$learningsFile;
    }
    
    /**
     * Initialize the learnings file if it doesn't exist
     */
    public static function init(): void {
        $filePath = self::getFilePath();
        $dir = dirname($filePath);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if (!file_exists($filePath)) {
            $initialData = self::getEmptyStructure();
            file_put_contents($filePath, json_encode($initialData, JSON_PRETTY_PRINT));
        }
    }
    
    private static function getEmptyStructure(): array {
        return [
            'version' => '2.0',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            
            // Core statistics
            'statistics' => [
                'total_families' => 0,
                'total_members' => 0,
                'total_suggestions_shown' => 0,
                'total_suggestions_accepted' => 0,
                'total_suggestions_rejected' => 0,
                'total_suggestions_modified' => 0,
                'overall_accuracy' => 0,
                'total_patterns' => 0
            ],
            
            // Match type performance tracking
            'match_type_stats' => [
                // 'match_type' => ['shown' => X, 'accepted' => Y, 'rejected' => Z, 'modified' => W, 'accuracy' => %]
            ],
            
            // Relationship suggestion accuracy
            'relationship_accuracy' => [
                // 'suggested_relasyon' => ['correct' => X, 'incorrect' => Y, 'corrections' => ['actual' => count]]
            ],
            
            // Naming patterns with success rates
            'naming_patterns' => [
                // 'pattern_key' => [
                //   'pangulo_lastname', 'asawa_lastname', 'member_lastname', 'member_middlename',
                //   'relationships' => ['relasyon' => count],
                //   'success_count', 'total_count', 'accuracy'
                // ]
            ],
            
            // Kapisanan-relationship correlations
            'kapisanan_patterns' => [
                // 'kapisanan' => ['relasyon' => ['count' => X, 'success' => Y]]
            ],
            
            // Confirmed exact relationships (person to person)
            'confirmed_relationships' => [
                // md5(pangulo|member) => ['relasyon', 'confirmed_count']
            ],
            
            // Behavior tracking - what users do with suggestions
            'behavior_log' => [
                // Recent 1000 entries of user actions
            ],
            
            // Correction patterns - learn from user fixes
            'correction_patterns' => [
                // 'suggested_relasyon|actual_relasyon' => count
            ],
            
            // High confidence rules derived from learning
            'derived_rules' => [
                // Auto-generated rules based on patterns
            ]
        ];
    }
    
    /**
     * Load all learnings
     */
    public static function load(): array {
        self::init();
        $content = file_get_contents(self::getFilePath());
        $data = json_decode($content, true) ?? [];
        
        // Migrate from v1 if needed
        if (($data['version'] ?? '1.0') === '1.0') {
            $data = self::migrateFromV1($data);
        }
        
        return $data;
    }
    
    private static function migrateFromV1(array $oldData): array {
        $newData = self::getEmptyStructure();
        
        // Migrate statistics
        $newData['statistics']['total_families'] = $oldData['statistics']['total_families'] ?? 0;
        $newData['statistics']['total_members'] = $oldData['statistics']['total_members'] ?? 0;
        $newData['statistics']['total_patterns'] = $oldData['statistics']['total_patterns'] ?? 0;
        
        // Migrate naming patterns
        if (isset($oldData['naming_patterns'])) {
            foreach ($oldData['naming_patterns'] as $key => $pattern) {
                $newData['naming_patterns'][$key] = [
                    'pangulo_lastname' => $pattern['pangulo_lastname'] ?? '',
                    'asawa_lastname' => $pattern['asawa_lastname'] ?? '',
                    'member_lastname' => $pattern['member_lastname'] ?? '',
                    'member_middlename' => $pattern['member_middlename'] ?? '',
                    'relationships' => $pattern['relationships'] ?? [],
                    'success_count' => $pattern['count'] ?? 0,
                    'total_count' => $pattern['count'] ?? 0,
                    'accuracy' => 100
                ];
            }
        }
        
        // Migrate kapisanan patterns
        if (isset($oldData['kapisanan_patterns'])) {
            foreach ($oldData['kapisanan_patterns'] as $key => $pattern) {
                $kap = $pattern['kapisanan'] ?? '';
                $rel = $pattern['relasyon'] ?? '';
                if ($kap && $rel) {
                    if (!isset($newData['kapisanan_patterns'][$kap])) {
                        $newData['kapisanan_patterns'][$kap] = [];
                    }
                    $newData['kapisanan_patterns'][$kap][$rel] = [
                        'count' => $pattern['count'] ?? 0,
                        'success' => $pattern['count'] ?? 0
                    ];
                }
            }
        }
        
        // Migrate confirmed relationships
        $newData['confirmed_relationships'] = $oldData['confirmed_relationships'] ?? [];
        
        return $newData;
    }
    
    /**
     * Save learnings
     */
    private static function save(array $data): bool {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return file_put_contents(self::getFilePath(), json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Track suggestions that were shown to user
     * Called when suggestions are displayed
     */
    public static function trackSuggestionsShown(array $suggestions, int $panguloId, ?int $asawaId = null): string {
        $learnings = self::load();
        
        // Generate a session ID for this suggestion batch
        $sessionId = uniqid('sugg_', true);
        
        // Track each suggestion
        foreach ($suggestions as $suggestion) {
            $matchType = $suggestion['match_type'] ?? 'unknown';
            
            if (!isset($learnings['match_type_stats'][$matchType])) {
                $learnings['match_type_stats'][$matchType] = [
                    'shown' => 0,
                    'accepted' => 0,
                    'rejected' => 0,
                    'modified' => 0,
                    'accuracy' => 0
                ];
            }
            $learnings['match_type_stats'][$matchType]['shown']++;
            $learnings['statistics']['total_suggestions_shown']++;
        }
        
        // Store session for later tracking
        $learnings['behavior_log'][] = [
            'session_id' => $sessionId,
            'pangulo_id' => $panguloId,
            'asawa_id' => $asawaId,
            'suggestions_shown' => count($suggestions),
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'pending'
        ];
        
        // Keep only last 1000 behavior logs
        if (count($learnings['behavior_log']) > 1000) {
            $learnings['behavior_log'] = array_slice($learnings['behavior_log'], -1000);
        }
        
        self::save($learnings);
        return $sessionId;
    }
    
    /**
     * Learn from user's final family submission
     * This is the main learning function - tracks what user actually did
     */
    public static function learnFromFamilySave(
        array $panguloInfo,
        array $members,
        ?array $asawaInfo,
        array $suggestionsShown,
        array $suggestionsAccepted,
        array $suggestionsModified
    ): void {
        $learnings = self::load();
        
        $panguloName = $panguloInfo['full_name'] ?? '';
        $panguloNames = self::parseFullName($panguloName);
        $panguloLastName = mb_strtolower($panguloNames['last_name'], 'UTF-8');
        
        $asawaLastName = null;
        if ($asawaInfo && isset($asawaInfo['full_name'])) {
            $asawaNames = self::parseFullName($asawaInfo['full_name']);
            $asawaLastName = mb_strtolower($asawaNames['last_name'], 'UTF-8');
        }
        
        // Track suggestion outcomes
        $acceptedIds = array_column($suggestionsAccepted, 'id');
        $modifiedMap = [];
        foreach ($suggestionsModified as $mod) {
            $modifiedMap[$mod['id']] = $mod;
        }
        
        foreach ($suggestionsShown as $suggestion) {
            $suggId = $suggestion['id'] ?? null;
            $matchType = $suggestion['match_type'] ?? 'unknown';
            $suggestedRelasyon = $suggestion['suggested_relasyon'] ?? '';
            
            if (!isset($learnings['match_type_stats'][$matchType])) {
                $learnings['match_type_stats'][$matchType] = [
                    'shown' => 0, 'accepted' => 0, 'rejected' => 0, 'modified' => 0, 'accuracy' => 0
                ];
            }
            
            if (in_array($suggId, $acceptedIds)) {
                // Check if it was modified
                if (isset($modifiedMap[$suggId])) {
                    $actualRelasyon = $modifiedMap[$suggId]['actual_relasyon'] ?? '';
                    if ($actualRelasyon && $actualRelasyon !== $suggestedRelasyon) {
                        // Modified - user corrected the relationship
                        $learnings['match_type_stats'][$matchType]['modified']++;
                        $learnings['statistics']['total_suggestions_modified']++;
                        
                        // Track the correction
                        $correctionKey = $suggestedRelasyon . '|' . $actualRelasyon;
                        if (!isset($learnings['correction_patterns'][$correctionKey])) {
                            $learnings['correction_patterns'][$correctionKey] = 0;
                        }
                        $learnings['correction_patterns'][$correctionKey]++;
                        
                        // Track relationship accuracy
                        self::trackRelationshipAccuracy($learnings, $suggestedRelasyon, $actualRelasyon, false);
                    } else {
                        // Accepted as-is
                        $learnings['match_type_stats'][$matchType]['accepted']++;
                        $learnings['statistics']['total_suggestions_accepted']++;
                        self::trackRelationshipAccuracy($learnings, $suggestedRelasyon, $suggestedRelasyon, true);
                    }
                } else {
                    // Accepted without modification
                    $learnings['match_type_stats'][$matchType]['accepted']++;
                    $learnings['statistics']['total_suggestions_accepted']++;
                    self::trackRelationshipAccuracy($learnings, $suggestedRelasyon, $suggestedRelasyon, true);
                }
            } else {
                // Rejected - not added to family
                $learnings['match_type_stats'][$matchType]['rejected']++;
                $learnings['statistics']['total_suggestions_rejected']++;
            }
            
            // Update match type accuracy
            $stats = $learnings['match_type_stats'][$matchType];
            $total = $stats['accepted'] + $stats['rejected'] + $stats['modified'];
            if ($total > 0) {
                // Accepted = 100%, Modified = 50%, Rejected = 0%
                $score = ($stats['accepted'] * 100 + $stats['modified'] * 50) / $total;
                $learnings['match_type_stats'][$matchType]['accuracy'] = round($score, 1);
            }
        }
        
        // Learn from all final members
        foreach ($members as $member) {
            $memberNames = self::parseFullName($member['name'] ?? '');
            $memberLastName = mb_strtolower($memberNames['last_name'], 'UTF-8');
            $memberMiddleName = mb_strtolower($memberNames['middle_name'], 'UTF-8');
            $relasyon = $member['relasyon'] ?? '';
            $kapisanan = $member['kapisanan'] ?? '';
            
            if (empty($relasyon)) continue;
            
            // Learn naming pattern with success tracking
            $patternKey = self::generatePatternKey($panguloLastName, $asawaLastName, $memberLastName, $memberMiddleName);
            
            if (!isset($learnings['naming_patterns'][$patternKey])) {
                $learnings['naming_patterns'][$patternKey] = [
                    'pangulo_lastname' => $panguloLastName,
                    'asawa_lastname' => $asawaLastName,
                    'member_lastname' => $memberLastName,
                    'member_middlename' => $memberMiddleName,
                    'relationships' => [],
                    'success_count' => 0,
                    'total_count' => 0,
                    'accuracy' => 0
                ];
            }
            
            if (!isset($learnings['naming_patterns'][$patternKey]['relationships'][$relasyon])) {
                $learnings['naming_patterns'][$patternKey]['relationships'][$relasyon] = 0;
            }
            $learnings['naming_patterns'][$patternKey]['relationships'][$relasyon]++;
            $learnings['naming_patterns'][$patternKey]['total_count']++;
            $learnings['naming_patterns'][$patternKey]['success_count']++;
            
            // Calculate pattern accuracy (how often this pattern leads to successful suggestions)
            $pattern = $learnings['naming_patterns'][$patternKey];
            $learnings['naming_patterns'][$patternKey]['accuracy'] = 
                round(($pattern['success_count'] / max(1, $pattern['total_count'])) * 100, 1);
            
            // Learn kapisanan patterns
            if ($kapisanan) {
                if (!isset($learnings['kapisanan_patterns'][$kapisanan])) {
                    $learnings['kapisanan_patterns'][$kapisanan] = [];
                }
                if (!isset($learnings['kapisanan_patterns'][$kapisanan][$relasyon])) {
                    $learnings['kapisanan_patterns'][$kapisanan][$relasyon] = ['count' => 0, 'success' => 0];
                }
                $learnings['kapisanan_patterns'][$kapisanan][$relasyon]['count']++;
                $learnings['kapisanan_patterns'][$kapisanan][$relasyon]['success']++;
            }
            
            // Store confirmed relationship
            $confirmedKey = md5($panguloName . '|' . $member['name']);
            $learnings['confirmed_relationships'][$confirmedKey] = [
                'pangulo' => $panguloName,
                'member' => $member['name'],
                'relasyon' => $relasyon,
                'kapisanan' => $kapisanan,
                'confirmed_count' => ($learnings['confirmed_relationships'][$confirmedKey]['confirmed_count'] ?? 0) + 1,
                'last_confirmed' => date('Y-m-d H:i:s')
            ];
        }
        
        // Update statistics
        $learnings['statistics']['total_families']++;
        $learnings['statistics']['total_members'] += count($members);
        $learnings['statistics']['total_patterns'] = count($learnings['naming_patterns']);
        
        // Calculate overall accuracy
        $totalShown = $learnings['statistics']['total_suggestions_shown'];
        $totalAccepted = $learnings['statistics']['total_suggestions_accepted'];
        $totalModified = $learnings['statistics']['total_suggestions_modified'];
        if ($totalShown > 0) {
            $learnings['statistics']['overall_accuracy'] = 
                round((($totalAccepted * 100 + $totalModified * 50) / $totalShown), 1);
        }
        
        // Generate derived rules based on patterns
        self::generateDerivedRules($learnings);
        
        self::save($learnings);
    }
    
    private static function trackRelationshipAccuracy(array &$learnings, string $suggested, string $actual, bool $correct): void {
        if (!isset($learnings['relationship_accuracy'][$suggested])) {
            $learnings['relationship_accuracy'][$suggested] = [
                'correct' => 0,
                'incorrect' => 0,
                'corrections' => []
            ];
        }
        
        if ($correct) {
            $learnings['relationship_accuracy'][$suggested]['correct']++;
        } else {
            $learnings['relationship_accuracy'][$suggested]['incorrect']++;
            if (!isset($learnings['relationship_accuracy'][$suggested]['corrections'][$actual])) {
                $learnings['relationship_accuracy'][$suggested]['corrections'][$actual] = 0;
            }
            $learnings['relationship_accuracy'][$suggested]['corrections'][$actual]++;
        }
    }
    
    /**
     * Generate high-confidence rules from learned patterns
     */
    private static function generateDerivedRules(array &$learnings): void {
        $rules = [];
        
        // Rule 1: High-accuracy naming patterns (>90% accuracy, >3 occurrences)
        foreach ($learnings['naming_patterns'] as $key => $pattern) {
            if ($pattern['accuracy'] >= 90 && $pattern['total_count'] >= 3) {
                arsort($pattern['relationships']);
                $topRelasyon = array_key_first($pattern['relationships']);
                $rules[] = [
                    'type' => 'naming_pattern',
                    'pattern_key' => $key,
                    'suggested_relasyon' => $topRelasyon,
                    'accuracy' => $pattern['accuracy'],
                    'occurrences' => $pattern['total_count'],
                    'confidence' => 'high'
                ];
            }
        }
        
        // Rule 2: High-accuracy match types (>85% accuracy, >10 occurrences)
        foreach ($learnings['match_type_stats'] as $matchType => $stats) {
            $total = $stats['accepted'] + $stats['rejected'] + $stats['modified'];
            if ($stats['accuracy'] >= 85 && $total >= 10) {
                $rules[] = [
                    'type' => 'match_type',
                    'match_type' => $matchType,
                    'accuracy' => $stats['accuracy'],
                    'occurrences' => $total,
                    'confidence' => 'high'
                ];
            }
        }
        
        // Rule 3: Kapisanan-relationship correlations (>80% correlation)
        foreach ($learnings['kapisanan_patterns'] as $kap => $relations) {
            $total = array_sum(array_column($relations, 'count'));
            foreach ($relations as $rel => $data) {
                $ratio = $total > 0 ? ($data['count'] / $total) * 100 : 0;
                if ($ratio >= 80 && $data['count'] >= 5) {
                    $rules[] = [
                        'type' => 'kapisanan_relation',
                        'kapisanan' => $kap,
                        'suggested_relasyon' => $rel,
                        'correlation' => round($ratio, 1),
                        'occurrences' => $data['count'],
                        'confidence' => 'high'
                    ];
                }
            }
        }
        
        // Rule 4: Common corrections (if we often correct X to Y, suggest Y instead)
        foreach ($learnings['correction_patterns'] as $correctionKey => $count) {
            if ($count >= 3) {
                [$suggested, $actual] = explode('|', $correctionKey);
                $rules[] = [
                    'type' => 'correction_rule',
                    'original_suggestion' => $suggested,
                    'better_suggestion' => $actual,
                    'correction_count' => $count,
                    'confidence' => 'medium'
                ];
            }
        }
        
        $learnings['derived_rules'] = $rules;
    }
    
    /**
     * Get suggested relationship based on all learned patterns
     * Returns the best suggestion with confidence score
     */
    public static function suggestRelationship(
        string $panguloLastName, 
        ?string $asawaLastName, 
        string $memberLastName, 
        string $memberMiddleName,
        ?string $kapisanan = null,
        ?string $matchType = null
    ): ?array {
        $learnings = self::load();
        
        $suggestions = [];
        
        // 1. Check derived rules first (highest priority)
        foreach ($learnings['derived_rules'] ?? [] as $rule) {
            if ($rule['type'] === 'naming_pattern' && $rule['confidence'] === 'high') {
                $patternKey = self::generatePatternKey(
                    mb_strtolower($panguloLastName, 'UTF-8'),
                    $asawaLastName ? mb_strtolower($asawaLastName, 'UTF-8') : null,
                    mb_strtolower($memberLastName, 'UTF-8'),
                    mb_strtolower($memberMiddleName, 'UTF-8')
                );
                
                if ($rule['pattern_key'] === $patternKey) {
                    $suggestions[] = [
                        'relasyon' => $rule['suggested_relasyon'],
                        'confidence' => $rule['accuracy'] / 100,
                        'reason' => "Learned pattern ({$rule['occurrences']} times, {$rule['accuracy']}% accurate)",
                        'source' => 'derived_rule'
                    ];
                }
            }
            
            // Check correction rules - if we're about to suggest X, but users often correct to Y
            if ($rule['type'] === 'correction_rule') {
                // This will be applied later to adjust suggestions
            }
            
            // Kapisanan-based rule
            if ($rule['type'] === 'kapisanan_relation' && $kapisanan === $rule['kapisanan']) {
                $suggestions[] = [
                    'relasyon' => $rule['suggested_relasyon'],
                    'confidence' => $rule['correlation'] / 100,
                    'reason' => "{$kapisanan} members are usually {$rule['suggested_relasyon']} ({$rule['correlation']}%)",
                    'source' => 'kapisanan_rule'
                ];
            }
            
            // Match type rule
            if ($rule['type'] === 'match_type' && $matchType === $rule['match_type']) {
                $suggestions[] = [
                    'relasyon' => null, // Just boost confidence
                    'confidence_boost' => $rule['accuracy'] / 100,
                    'reason' => "Match type '{$matchType}' has {$rule['accuracy']}% accuracy",
                    'source' => 'match_type_rule'
                ];
            }
        }
        
        // 2. Check exact naming pattern match
        $patternKey = self::generatePatternKey(
            mb_strtolower($panguloLastName, 'UTF-8'),
            $asawaLastName ? mb_strtolower($asawaLastName, 'UTF-8') : null,
            mb_strtolower($memberLastName, 'UTF-8'),
            mb_strtolower($memberMiddleName, 'UTF-8')
        );
        
        if (isset($learnings['naming_patterns'][$patternKey])) {
            $pattern = $learnings['naming_patterns'][$patternKey];
            if ($pattern['total_count'] >= 1) {
                arsort($pattern['relationships']);
                $topRelasyon = array_key_first($pattern['relationships']);
                $topCount = $pattern['relationships'][$topRelasyon];
                $patternAccuracy = $pattern['accuracy'] / 100;
                
                $suggestions[] = [
                    'relasyon' => $topRelasyon,
                    'confidence' => min(0.95, 0.5 + ($topCount / 10) + ($patternAccuracy * 0.3)),
                    'reason' => "Learned from {$pattern['total_count']} similar case(s) ({$pattern['accuracy']}% accurate)",
                    'source' => 'naming_pattern'
                ];
            }
        }
        
        // 3. Check for middle name = asawa lastname pattern (Filipino naming convention)
        if ($asawaLastName && $memberMiddleName) {
            $asawaLower = mb_strtolower($asawaLastName, 'UTF-8');
            $middleLower = mb_strtolower($memberMiddleName, 'UTF-8');
            $panguloLower = mb_strtolower($panguloLastName, 'UTF-8');
            $lastLower = mb_strtolower($memberLastName, 'UTF-8');
            
            if ($asawaLower === $middleLower || str_contains($middleLower, $asawaLower)) {
                if ($lastLower === $panguloLower) {
                    $suggestions[] = [
                        'relasyon' => 'Anak',
                        'confidence' => 0.95,
                        'reason' => "Middle name matches mother's maiden name, last name matches father",
                        'source' => 'naming_convention'
                    ];
                } else {
                    $suggestions[] = [
                        'relasyon' => 'Anak',
                        'confidence' => 0.7,
                        'reason' => "Middle name matches mother's maiden name",
                        'source' => 'naming_convention'
                    ];
                }
            }
        }
        
        // 4. Kapisanan-based suggestion (lower priority)
        if ($kapisanan && isset($learnings['kapisanan_patterns'][$kapisanan])) {
            $kapPatterns = $learnings['kapisanan_patterns'][$kapisanan];
            $totalKap = 0;
            foreach ($kapPatterns as $data) {
                $totalKap += $data['count'];
            }
            
            if ($totalKap >= 3) {
                uasort($kapPatterns, fn($a, $b) => $b['count'] - $a['count']);
                $topRel = array_key_first($kapPatterns);
                $topData = $kapPatterns[$topRel];
                $ratio = $topData['count'] / $totalKap;
                
                if ($ratio >= 0.5) {
                    $suggestions[] = [
                        'relasyon' => $topRel,
                        'confidence' => min(0.7, 0.4 + $ratio * 0.3),
                        'reason' => "{$kapisanan} members are often {$topRel}",
                        'source' => 'kapisanan'
                    ];
                }
            }
        }
        
        // Return the highest confidence suggestion
        if (empty($suggestions)) {
            return null;
        }
        
        usort($suggestions, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
        $best = $suggestions[0];
        
        // Apply correction rules - if this suggestion is often corrected, use the correction
        foreach ($learnings['derived_rules'] ?? [] as $rule) {
            if ($rule['type'] === 'correction_rule' && 
                $rule['original_suggestion'] === $best['relasyon'] &&
                $rule['correction_count'] >= 3) {
                return [
                    'suggested_relasyon' => $rule['better_suggestion'],
                    'confidence' => min(0.9, $best['confidence']),
                    'reason' => "Corrected from '{$rule['original_suggestion']}' (users corrected {$rule['correction_count']} times)",
                    'source' => 'correction_learning'
                ];
            }
        }
        
        return [
            'suggested_relasyon' => $best['relasyon'],
            'confidence' => $best['confidence'],
            'reason' => $best['reason'],
            'source' => $best['source']
        ];
    }
    
    /**
     * Get learning statistics for AI context and display
     */
    public static function getStatisticsForAI(): array {
        $learnings = self::load();
        
        // Get top performing match types
        $topMatchTypes = [];
        foreach ($learnings['match_type_stats'] ?? [] as $type => $stats) {
            $total = $stats['accepted'] + $stats['rejected'] + $stats['modified'];
            if ($total >= 5) {
                $topMatchTypes[$type] = [
                    'accuracy' => $stats['accuracy'],
                    'total' => $total
                ];
            }
        }
        arsort($topMatchTypes);
        $topMatchTypes = array_slice($topMatchTypes, 0, 5, true);
        
        // Get derived rules count by confidence
        $rulesByConfidence = ['high' => 0, 'medium' => 0];
        foreach ($learnings['derived_rules'] ?? [] as $rule) {
            $conf = $rule['confidence'] ?? 'medium';
            $rulesByConfidence[$conf] = ($rulesByConfidence[$conf] ?? 0) + 1;
        }
        
        return [
            'statistics' => $learnings['statistics'] ?? [],
            'top_match_types' => $topMatchTypes,
            'derived_rules_count' => $rulesByConfidence,
            'kapisanan_patterns' => array_keys($learnings['kapisanan_patterns'] ?? [])
        ];
    }
    
    /**
     * Check if we have a confirmed relationship between two people
     */
    public static function getConfirmedRelationship(string $panguloName, string $memberName): ?array {
        $learnings = self::load();
        $key = md5($panguloName . '|' . $memberName);
        
        return $learnings['confirmed_relationships'][$key] ?? null;
    }
    
    /**
     * Get accuracy report for debugging/display
     */
    public static function getAccuracyReport(): array {
        $learnings = self::load();
        
        return [
            'overall_accuracy' => $learnings['statistics']['overall_accuracy'] ?? 0,
            'total_suggestions_shown' => $learnings['statistics']['total_suggestions_shown'] ?? 0,
            'total_accepted' => $learnings['statistics']['total_suggestions_accepted'] ?? 0,
            'total_modified' => $learnings['statistics']['total_suggestions_modified'] ?? 0,
            'total_rejected' => $learnings['statistics']['total_suggestions_rejected'] ?? 0,
            'match_type_performance' => $learnings['match_type_stats'] ?? [],
            'relationship_accuracy' => $learnings['relationship_accuracy'] ?? [],
            'top_patterns' => array_slice($learnings['naming_patterns'] ?? [], 0, 10, true),
            'derived_rules' => $learnings['derived_rules'] ?? []
        ];
    }
    
    /**
     * Parse a full name into parts
     */
    private static function parseFullName(string $fullName): array {
        $parts = preg_split('/\s+/', trim($fullName));
        
        if (count($parts) >= 3) {
            return [
                'first_name' => $parts[0],
                'middle_name' => implode(' ', array_slice($parts, 1, -1)),
                'last_name' => end($parts)
            ];
        } elseif (count($parts) === 2) {
            return [
                'first_name' => $parts[0],
                'middle_name' => '',
                'last_name' => $parts[1]
            ];
        } else {
            return [
                'first_name' => $fullName,
                'middle_name' => '',
                'last_name' => ''
            ];
        }
    }
    
    /**
     * Generate a pattern key for matching
     */
    private static function generatePatternKey(?string $panguloLast, ?string $asawaLast, ?string $memberLast, ?string $memberMiddle): string {
        $lastMatch = ($panguloLast && $memberLast && $panguloLast === $memberLast) ? 'L' : '_';
        $middleMatch = ($asawaLast && $memberMiddle && ($asawaLast === $memberMiddle || str_contains($memberMiddle, $asawaLast))) ? 'M' : '_';
        $asawaMatch = ($asawaLast && $memberLast && $asawaLast === $memberLast) ? 'A' : '_';
        
        return "{$lastMatch}{$middleMatch}{$asawaMatch}";
    }
    
    /**
     * Export learnings for backup
     */
    public static function export(): string {
        return file_get_contents(self::getFilePath());
    }
    
    /**
     * Import learnings from backup
     */
    public static function import(string $jsonData): bool {
        $data = json_decode($jsonData, true);
        if (!$data || !isset($data['version'])) {
            return false;
        }
        return self::save($data);
    }
    
    /**
     * Reset learnings (for testing)
     */
    public static function reset(): bool {
        return self::save(self::getEmptyStructure());
    }
}
