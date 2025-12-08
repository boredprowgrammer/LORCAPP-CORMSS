<?php
/**
 * LORCAPP
 * Photo Upload Helper Functions
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

// Load encryption functions
require_once __DIR__ . '/encryption.php';

/**
 * Process and validate uploaded photo
 * @param array $file The $_FILES['photo'] array
 * @return array ['success' => bool, 'filename' => string|null, 'error' => string|null, 'base64' => string|null]
 */
function processPhotoUpload($file) {
    // Check if file was uploaded
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'filename' => null, 'error' => null, 'base64' => null]; // Photo is optional
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'filename' => null, 'error' => 'File upload error: ' . $file['error'], 'base64' => null];
    }
    
    // Validate filename for double extensions and dangerous patterns
    $filename = basename($file['name']);
    if (preg_match('/\.(php|phtml|php3|php4|php5|phps|pht|phar|exe|js|sh|bat|cmd)\.?/i', $filename)) {
        return ['success' => false, 'filename' => null, 'error' => 'Dangerous file type detected in filename', 'base64' => null];
    }
    
    // Validate file extension
    $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($fileExtension, ['jpg', 'jpeg', 'png'])) {
        return ['success' => false, 'filename' => null, 'error' => 'Invalid file extension. Only JPG, JPEG, and PNG allowed.', 'base64' => null];
    }
    
    // Validate MIME type using finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'filename' => null, 'error' => 'Invalid file type. Only JPEG and PNG images allowed.', 'base64' => null];
    }
    
    // Verify it's actually an image by reading it
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return ['success' => false, 'filename' => null, 'error' => 'File is not a valid image.', 'base64' => null];
    }
    
    // Validate image dimensions (prevent decompression bombs)
    if ($imageInfo[0] > 10000 || $imageInfo[1] > 10000) {
        return ['success' => false, 'filename' => null, 'error' => 'Image dimensions are too large (max 10000x10000).', 'base64' => null];
    }
    
    // Validate file size (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'filename' => null, 'error' => 'File too large. Maximum size is 5MB.', 'base64' => null];
    }
    
    // Verify it's actually an image by reading it
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return ['success' => false, 'filename' => null, 'error' => 'File is not a valid image.', 'base64' => null];
    }
    
    // Validate image dimensions (prevent decompression bombs)
    if ($imageInfo[0] > 10000 || $imageInfo[1] > 10000) {
        return ['success' => false, 'filename' => null, 'error' => 'Image dimensions too large.', 'base64' => null];
    }
    
    // Generate unique filename for reference
    $timestamp = time();
    $randomString = bin2hex(random_bytes(8));
    $newFilename = "photo_{$timestamp}_{$randomString}.{$fileExtension}";
    
    // Resize and optimize image, get base64
    $optimized = resizeAndOptimizeImageToBase64($file['tmp_name'], $mimeType);
    if (!$optimized['success']) {
        return ['success' => false, 'filename' => null, 'error' => $optimized['error'], 'base64' => null];
    }
    
    // Encrypt the base64 photo data for secure storage
    $encryptedPhoto = encryptValue($optimized['base64']);
    if ($encryptedPhoto === false) {
        return ['success' => false, 'filename' => null, 'error' => 'Failed to encrypt photo data.', 'base64' => null];
    }
    
    // Log the upload
    logSecurityEvent('PHOTO_UPLOADED', [
        'filename' => $newFilename,
        'size' => $file['size'],
        'mime_type' => $mimeType,
        'storage' => 'database_base64_encrypted'
    ]);
    
    return [
        'success' => true, 
        'filename' => $newFilename, 
        'error' => null,
        'base64' => $encryptedPhoto, // Returns encrypted base64
        'mime_type' => $mimeType
    ];
}

/**
 * Resize and optimize image to standard size and return base64
 * @param string $filePath Path to the image file
 * @param string $mimeType MIME type of the image
 * @return array ['success' => bool, 'error' => string|null, 'base64' => string|null]
 */
function resizeAndOptimizeImageToBase64($filePath, $mimeType) {
    // Target dimensions (2x2 inch at 300 DPI = 600x600 pixels, but we'll use 400x400 for web)
    $targetWidth = 400;
    $targetHeight = 400;
    
    // Create image resource based on type
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            $sourceImage = imagecreatefromjpeg($filePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($filePath);
            break;
        default:
            return ['success' => false, 'error' => 'Unsupported image type for resizing.', 'base64' => null];
    }
    
    if (!$sourceImage) {
        return ['success' => false, 'error' => 'Failed to create image resource.', 'base64' => null];
    }
    
    // Get original dimensions
    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);
    
    // Calculate crop dimensions (center crop to square)
    $cropSize = min($sourceWidth, $sourceHeight);
    $cropX = ($sourceWidth - $cropSize) / 2;
    $cropY = ($sourceHeight - $cropSize) / 2;
    
    // Create new true color image
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    
    // Preserve transparency for PNG
    if ($mimeType === 'image/png') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
        imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    }
    
    // Resize and crop
    imagecopyresampled(
        $targetImage, $sourceImage,
        0, 0, $cropX, $cropY,
        $targetWidth, $targetHeight, $cropSize, $cropSize
    );
    
    // Convert to base64
    ob_start();
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            imagejpeg($targetImage, null, 85); // 85% quality
            break;
        case 'image/png':
            imagepng($targetImage, null, 6); // Compression level 6
            break;
    }
    $imageData = ob_get_contents();
    ob_end_clean();
    
    // Free memory
    imagedestroy($sourceImage);
    imagedestroy($targetImage);
    
    if (!$imageData) {
        return ['success' => false, 'error' => 'Failed to generate image data.', 'base64' => null];
    }
    
    // Convert to base64
    $base64 = base64_encode($imageData);
    
    return ['success' => true, 'error' => null, 'base64' => $base64];
}

/**
 * Resize and optimize image to standard size (LEGACY - for file system storage)
 * @param string $filePath Path to the image file
 * @param string $mimeType MIME type of the image
 * @return array ['success' => bool, 'error' => string|null]
 */
function resizeAndOptimizeImage($filePath, $mimeType) {
    // Target dimensions (2x2 inch at 300 DPI = 600x600 pixels, but we'll use 400x400 for web)
    $targetWidth = 400;
    $targetHeight = 400;
    
    // Create image resource based on type
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            $sourceImage = imagecreatefromjpeg($filePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($filePath);
            break;
        default:
            return ['success' => false, 'error' => 'Unsupported image type for resizing.'];
    }
    
    if (!$sourceImage) {
        return ['success' => false, 'error' => 'Failed to create image resource.'];
    }
    
    // Get original dimensions
    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);
    
    // Calculate crop dimensions (center crop to square)
    $cropSize = min($sourceWidth, $sourceHeight);
    $cropX = ($sourceWidth - $cropSize) / 2;
    $cropY = ($sourceHeight - $cropSize) / 2;
    
    // Create new true color image
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    
    // Preserve transparency for PNG
    if ($mimeType === 'image/png') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
        imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    }
    
    // Resize and crop
    imagecopyresampled(
        $targetImage, $sourceImage,
        0, 0, $cropX, $cropY,
        $targetWidth, $targetHeight, $cropSize, $cropSize
    );
    
    // Save optimized image
    $success = false;
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            $success = imagejpeg($targetImage, $filePath, 85); // 85% quality
            break;
        case 'image/png':
            $success = imagepng($targetImage, $filePath, 6); // Compression level 6
            break;
    }
    
    // Free memory
    imagedestroy($sourceImage);
    imagedestroy($targetImage);
    
    if (!$success) {
        return ['success' => false, 'error' => 'Failed to save optimized image.'];
    }
    
    return ['success' => true, 'error' => null];
}

/**
 * Decrypt base64 photo data for display
 * @param string $encryptedBase64 Encrypted base64 photo data
 * @return string|false Decrypted base64 data or false on failure
 */
function decryptPhotoBase64($encryptedBase64) {
    if (empty($encryptedBase64)) {
        return false;
    }
    
    $decrypted = decryptValue($encryptedBase64);
    
    // Log decryption for security audit trail
    if ($decrypted !== false) {
        logSecurityEvent('PHOTO_DECRYPTED', [
            'success' => true,
            'data_length' => strlen($decrypted)
        ]);
    } else {
        logSecurityEvent('PHOTO_DECRYPT_FAILED', [
            'success' => false
        ]);
    }
    
    return $decrypted;
}

/**
 * Delete photo file
 * @param string $filename Filename to delete
 * @return bool Success status
 */
function deletePhoto($filename) {
    if (empty($filename)) {
        return true;
    }
    
    $filePath = __DIR__ . '/../uploads/photos/' . $filename;
    
    if (file_exists($filePath)) {
        $deleted = unlink($filePath);
        if ($deleted) {
            logSecurityEvent('PHOTO_DELETED', ['filename' => $filename]);
        }
        return $deleted;
    }
    
    return true; // File doesn't exist, consider it deleted
}

/**
 * Get photo URL for display
 * @param string $filename Photo filename
 * @return string Full URL to photo
 */
function getPhotoUrl($filename) {
    if (empty($filename)) {
        return '';
    }
    
    return 'uploads/photos/' . $filename;
}

/**
 * Validate that photo file exists and is accessible
 * @param string $filename Photo filename
 * @return bool True if file exists
 */
function photoExists($filename) {
    if (empty($filename)) {
        return false;
    }
    
    $filePath = __DIR__ . '/../uploads/photos/' . $filename;
    return file_exists($filePath) && is_readable($filePath);
}
?>
