<?php
/**
 * LORCAPP
 * Settings Configuration
 * System-wide settings for district, locale, and officers
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

// ==================== LOCALE AND DISTRICT SETTINGS ====================

// District Information
define('DISTRICT_NAME', 'PAMPANGA EAST');
define('DISTRICT_CODE', '01114');

// Locale Information
define('LOCALE_NAME', 'STO. TOMAS');
define('LOCALE_CODE', '058');

// Combined Codes
define('LOCALE_DISTRICT_CODE', 'Lcode-' . LOCALE_CODE . ', Dcode-' . DISTRICT_CODE);
define('LOCALE_DISTRICT_FULL', LOCALE_NAME . ', ' . DISTRICT_NAME);

// ==================== CHURCH OFFICERS SETTINGS ====================

// Kumuha ng Tala (Record Keeper / Secretary)
define('KUMUHA_NG_TALA_NAME', 'JAN ANDREI P. FERNANDO');  // Example: 'Bro. Juan Dela Cruz'
define('KUMUHA_NG_TALA_TITLE', 'Kumuha ng Tala');

// Pangulong Kalihim (Head Deacon Secretary / Head Executive Secretary)
define('PANGULONG_KALIHIM_NAME', 'MARY JANE M. BATALIRAN');  // Example: 'Bro. Pedro Santos'
define('PANGULONG_KALIHIM_TITLE', 'Pangulong Kalihim');

// Pangulong Diakono (Head Deacon)
define('PANGULONG_DIAKONO_NAME', 'ABEL M. LACSINA');  // Example: 'Bro. Jose Reyes'
define('PANGULONG_DIAKONO_TITLE', 'Pangulong Diakono');

// Destinado (Resident Minister / Assigned Minister)
define('DESTINADO_NAME', 'AIVAN JADE G. CADIGAL');  // Example: 'Bro. Miguel Garcia'
define('DESTINADO_TITLE', 'Destinado');

// ==================== HELPER FUNCTIONS ====================

/**
 * Get all locale and district information
 * @return array Associative array with locale/district details
 */
function getLocaleDistrictInfo() {
    return [
        'district_name' => DISTRICT_NAME,
        'district_code' => DISTRICT_CODE,
        'locale_name' => LOCALE_NAME,
        'locale_code' => LOCALE_CODE,
        'locale_district_code' => LOCALE_DISTRICT_CODE,
        'locale_district_full' => LOCALE_DISTRICT_FULL
    ];
}

/**
 * Get all church officers information
 * @return array Associative array with officer details
 */
function getChurchOfficers() {
    return [
        'kumuha_ng_tala' => [
            'name' => KUMUHA_NG_TALA_NAME,
            'title' => KUMUHA_NG_TALA_TITLE
        ],
        'pangulong_kalihim' => [
            'name' => PANGULONG_KALIHIM_NAME,
            'title' => PANGULONG_KALIHIM_TITLE
        ],
        'pangulong_diakono' => [
            'name' => PANGULONG_DIAKONO_NAME,
            'title' => PANGULONG_DIAKONO_TITLE
        ],
        'destinado' => [
            'name' => DESTINADO_NAME,
            'title' => DESTINADO_TITLE
        ]
    ];
}

/**
 * Get formatted locale and district string
 * @param string $format Format type: 'full', 'code', 'name'
 * @return string Formatted string
 */
function getLocaleDistrictString($format = 'full') {
    switch ($format) {
        case 'code':
            return LOCALE_DISTRICT_CODE;
        case 'name':
            return LOCALE_DISTRICT_FULL;
        case 'full':
        default:
            return LOCALE_DISTRICT_FULL . ' (' . LOCALE_DISTRICT_CODE . ')';
    }
}

/**
 * Get officer name by position
 * @param string $position Officer position key
 * @return string Officer name or empty string
 */
function getOfficerName($position) {
    $officers = getChurchOfficers();
    return isset($officers[$position]['name']) ? $officers[$position]['name'] : '';
}

/**
 * Get officer title by position
 * @param string $position Officer position key
 * @return string Officer title or empty string
 */
function getOfficerTitle($position) {
    $officers = getChurchOfficers();
    return isset($officers[$position]['title']) ? $officers[$position]['title'] : '';
}

// ==================== SYSTEM SETTINGS ====================

// Form Settings
define('FORM_TITLE', 'LORCAPP');
define('FORM_SUBTITLE', 'Iglesia Ni Cristo - ' . LOCALE_DISTRICT_FULL);

// Date Format
define('DATE_FORMAT', 'F d, Y');  // Example: October 22, 2025
define('DATE_FORMAT_SHORT', 'm/d/Y');  // Example: 10/22/2025

// Photo Settings (from existing implementation)
define('PHOTO_MAX_SIZE', 5 * 1024 * 1024);  // 5MB
define('PHOTO_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);
define('PHOTO_UPLOAD_DIR', __DIR__ . '/../uploads/photos/');

// Rate Limit Settings (from existing implementation)
define('RATE_LIMIT_FORM_SUBMISSION', 6);  // Max submissions per hour
define('RATE_LIMIT_RECORD_VIEW', 30);  // Max views per minute
define('RATE_LIMIT_RECORD_PRINT', 12);  // Max prints per 5 minutes
define('RATE_LIMIT_LOGIN_ATTEMPT', 5);  // Max login attempts per 15 minutes
