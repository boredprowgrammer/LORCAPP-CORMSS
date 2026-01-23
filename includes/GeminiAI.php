<?php
/**
 * Gemini AI Integration for intelligent family member suggestions
 * Uses Google's Gemini API for relationship analysis
 */

class GeminiAI {
    private static $apiKey = null;
    private static $apiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    
    // Models to try in order (fallback support)
    private static $models = [
        'gemini-1.5-flash',      // More stable, better quota
        'gemini-2.0-flash-exp',  // Experimental
        'gemini-1.5-pro',        // Pro tier
    ];
    
    // Rate limit tracking
    private static $rateLimitedUntil = null;
    private static $rateLimitFile = null;
    
    /**
     * Initialize API key from environment
     */
    private static function getApiKey() {
        if (self::$apiKey === null) {
            self::$apiKey = getenv('GEMINI_API_KEY') ?: $_ENV['GEMINI_API_KEY'] ?? null;
        }
        return self::$apiKey;
    }
    
    /**
     * Get rate limit file path
     */
    private static function getRateLimitFile() {
        if (self::$rateLimitFile === null) {
            self::$rateLimitFile = sys_get_temp_dir() . '/gemini_rate_limit.txt';
        }
        return self::$rateLimitFile;
    }
    
    /**
     * Check if we're currently rate limited
     */
    private static function isRateLimited() {
        $file = self::getRateLimitFile();
        if (file_exists($file)) {
            $until = (int)file_get_contents($file);
            if (time() < $until) {
                return true;
            }
            // Rate limit expired, remove file
            @unlink($file);
        }
        return false;
    }
    
    /**
     * Set rate limit (don't try again for X seconds)
     */
    private static function setRateLimited($seconds = 60) {
        $file = self::getRateLimitFile();
        file_put_contents($file, time() + $seconds);
    }
    
    /**
     * Check if Gemini AI is available
     */
    public static function isAvailable() {
        // Check if rate limited first
        if (self::isRateLimited()) {
            return false;
        }
        return !empty(self::getApiKey());
    }
    
    /**
     * Analyze potential family relationships using AI
     * 
     * @param array $pangulo - Pangulo information (name, kapisanan, etc.)
     * @param array $candidates - Array of potential family members
     * @return array - Candidates with AI-suggested relationships
     */
    public static function analyzeFamilyRelationships($pangulo, $candidates) {
        $apiKey = self::getApiKey();
        if (!$apiKey || empty($candidates) || self::isRateLimited()) {
            return $candidates;
        }
        
        // Build context for AI
        $panguloInfo = sprintf(
            "Pangulo (Head of Household): %s, Kapisanan: %s",
            $pangulo['full_name'],
            $pangulo['kapisanan'] ?? 'Unknown'
        );
        
        // Format candidates for AI
        $candidatesList = [];
        foreach ($candidates as $index => $candidate) {
            $info = [
                'index' => $index,
                'name' => $candidate['full_name'],
                'kapisanan' => $candidate['kapisanan'] ?? 'Unknown',
                'match_type' => $candidate['match_type'] ?? 'lastname'
            ];
            
            // Add parent info if available
            if (!empty($candidate['father_name'])) {
                $info['father_name'] = $candidate['father_name'];
            }
            if (!empty($candidate['mother_name'])) {
                $info['mother_name'] = $candidate['mother_name'];
            }
            
            $candidatesList[] = $info;
        }
        
        $prompt = self::buildPrompt($panguloInfo, $candidatesList, $pangulo);
        
        try {
            $response = self::callGeminiAPI($prompt);
            if ($response) {
                return self::parseAIResponse($response, $candidates);
            }
        } catch (Exception $e) {
            error_log("Gemini AI Error: " . $e->getMessage());
        }
        
        return $candidates;
    }
    
    /**
     * Build the prompt for family relationship analysis
     */
    private static function buildPrompt($panguloInfo, $candidatesList, $pangulo) {
        $candidatesJson = json_encode($candidatesList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $panguloContext = "";
        if (!empty($pangulo['father_name'])) {
            $panguloContext .= "\nPangulo's Father: " . $pangulo['father_name'];
        }
        if (!empty($pangulo['mother_name'])) {
            $panguloContext .= "\nPangulo's Mother: " . $pangulo['mother_name'];
        }
        
        return <<<PROMPT
You are an expert at analyzing Filipino family relationships for a church registry system.

Context about Kapisanan (Church Organizations):
- Buklod: Married couples organization
- Kadiwa: Young adults organization (typically 18-35 years old, unmarried or young married)
- Binhi: Children's organization (typically under 18)
- HDB: Handog sa Diyos na Bata - Infant/child dedication registry
- PNK: Pagtatalaga ng mga Kabataan - Youth dedication registry

{$panguloInfo}{$panguloContext}

Potential Family Members to analyze:
{$candidatesJson}

For each candidate, determine the most likely relationship to the Pangulo based on:
1. If their father's name matches the Pangulo's name → they are likely "Anak" (child)
2. If their mother's name matches the Pangulo's name → they are likely "Anak" (child)
3. If match_type is "spouse" → they are "Asawa" (spouse)
4. If they share the same parents as the Pangulo → they are "Kapatid" (sibling)
5. If their kapisanan is Binhi/HDB/PNK and shares last name → likely "Anak" or "Apo"
6. If their kapisanan is Buklod and shares last name → could be "Asawa", "Kapatid", or "Anak"
7. Age inference from kapisanan: Binhi/HDB = children, Kadiwa = young adult, Buklod = married adult

Respond ONLY with a valid JSON array in this exact format (no markdown, no explanation):
[
  {"index": 0, "relasyon": "Anak", "confidence": "high", "reason": "Father's name matches Pangulo"},
  {"index": 1, "relasyon": "Asawa", "confidence": "high", "reason": "Spouse from Buklod registry"}
]

Valid relasyon values: Asawa, Anak, Apo, Magulang, Kapatid, Pamangkin, Indibidwal
Confidence levels: high, medium, low
PROMPT;
    }
    
    /**
     * Call Gemini API with fallback models
     */
    private static function callGeminiAPI($prompt) {
        $apiKey = self::getApiKey();
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 2048,
                'responseMimeType' => 'application/json'
            ]
        ];
        
        $lastError = null;
        
        // Try each model until one works
        foreach (self::$models as $model) {
            $url = self::$apiBaseUrl . $model . ':generateContent?key=' . $apiKey;
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                $lastError = "CURL Error: $error";
                continue;
            }
            
            // Handle rate limiting
            if ($httpCode === 429) {
                // Parse retry delay if available
                $result = json_decode($response, true);
                $retryAfter = 60; // default 60 seconds
                if (isset($result['error']['details'])) {
                    foreach ($result['error']['details'] as $detail) {
                        if (isset($detail['retryDelay'])) {
                            $retryAfter = intval($detail['retryDelay']);
                            break;
                        }
                    }
                }
                self::setRateLimited($retryAfter);
                $lastError = "Rate limited. Will retry after {$retryAfter}s";
                error_log("Gemini AI: Rate limited on model $model, trying next...");
                continue;
            }
            
            if ($httpCode !== 200) {
                $lastError = "API Error: HTTP $httpCode on model $model";
                error_log("Gemini AI: Error on model $model: HTTP $httpCode");
                continue;
            }
            
            $result = json_decode($response, true);
            
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Gemini AI: Success with model $model");
                return $result['candidates'][0]['content']['parts'][0]['text'];
            }
        }
        
        // All models failed
        if ($lastError) {
            error_log("Gemini AI: All models failed. Last error: $lastError");
        }
        
        return null;
    }
    
    /**
     * Parse AI response and update candidates
     */
    private static function parseAIResponse($response, $candidates) {
        // Clean the response - remove markdown code blocks if present
        $response = trim($response);
        $response = preg_replace('/^```json\s*/', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);
        
        $aiSuggestions = json_decode($response, true);
        
        if (!is_array($aiSuggestions)) {
            return $candidates;
        }
        
        foreach ($aiSuggestions as $suggestion) {
            $index = $suggestion['index'] ?? null;
            if ($index !== null && isset($candidates[$index])) {
                // Update with AI suggestions
                if (!empty($suggestion['relasyon'])) {
                    $candidates[$index]['suggested_relasyon'] = $suggestion['relasyon'];
                    $candidates[$index]['ai_suggested'] = true;
                }
                if (!empty($suggestion['confidence'])) {
                    $candidates[$index]['confidence'] = $suggestion['confidence'];
                }
                if (!empty($suggestion['reason'])) {
                    $candidates[$index]['ai_reason'] = $suggestion['reason'];
                }
            }
        }
        
        return $candidates;
    }
}
