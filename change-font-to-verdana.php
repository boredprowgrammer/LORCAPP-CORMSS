<?php
/**
 * Change font to Verdana in PALASUMPAAN template
 * Using direct XML manipulation to preserve formatting
 */

$templatePath = __DIR__ . '/PALASUMPAAN_template.docx';
$backupPath = __DIR__ . '/PALASUMPAAN_template_backup_verdana_' . date('YmdHis') . '.docx';

if (!file_exists($templatePath)) {
    die("Error: Template file not found at $templatePath\n");
}

try {
    // Backup original template
    copy($templatePath, $backupPath);
    echo "✓ Backed up original template to: $backupPath\n";
    
    // Create temp directory
    $tempDir = sys_get_temp_dir() . '/palasumpaan_font_' . time();
    mkdir($tempDir);
    
    // Extract DOCX (it's just a ZIP file)
    $zip = new ZipArchive();
    if ($zip->open($templatePath) !== TRUE) {
        throw new Exception("Failed to open template as ZIP");
    }
    
    $zip->extractTo($tempDir);
    $zip->close();
    
    echo "Extracted template to temp directory\n";
    
    // Modify document.xml to change fonts
    $documentXml = $tempDir . '/word/document.xml';
    if (file_exists($documentXml)) {
        $xml = file_get_contents($documentXml);
        
        // Replace font names in the XML
        // Look for w:rFonts tags and replace font names
        $xml = preg_replace(
            '/<w:rFonts[^>]*w:ascii="[^"]*"/',
            '<w:rFonts w:ascii="Verdana"',
            $xml
        );
        $xml = preg_replace(
            '/<w:rFonts[^>]*w:hAnsi="[^"]*"/',
            '<w:rFonts w:hAnsi="Verdana"',
            $xml
        );
        $xml = preg_replace(
            '/<w:rFonts[^>]*w:cs="[^"]*"/',
            '<w:rFonts w:cs="Verdana"',
            $xml
        );
        
        // Also add Verdana if w:rFonts doesn't have font attributes
        $xml = preg_replace(
            '/<w:rFonts\s*\/>/',
            '<w:rFonts w:ascii="Verdana" w:hAnsi="Verdana" w:cs="Verdana"/>',
            $xml
        );
        
        file_put_contents($documentXml, $xml);
        echo "✓ Modified document.xml font references\n";
    }
    
    // Modify styles.xml to change default fonts
    $stylesXml = $tempDir . '/word/styles.xml';
    if (file_exists($stylesXml)) {
        $xml = file_get_contents($stylesXml);
        
        // Replace font names
        $xml = preg_replace(
            '/<w:rFonts[^>]*w:ascii="[^"]*"/',
            '<w:rFonts w:ascii="Verdana"',
            $xml
        );
        $xml = preg_replace(
            '/<w:rFonts[^>]*w:hAnsi="[^"]*"/',
            '<w:rFonts w:hAnsi="Verdana"',
            $xml
        );
        $xml = preg_replace(
            '/<w:rFonts[^>]*w:cs="[^"]*"/',
            '<w:rFonts w:cs="Verdana"',
            $xml
        );
        
        file_put_contents($stylesXml, $xml);
        echo "✓ Modified styles.xml font references\n";
    }
    
    // Repackage as DOCX
    $newZip = new ZipArchive();
    if ($newZip->open($templatePath, ZipArchive::OVERWRITE) !== TRUE) {
        throw new Exception("Failed to create new DOCX");
    }
    
    // Add all files back
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($tempDir) + 1);
            $newZip->addFile($filePath, $relativePath);
        }
    }
    
    $newZip->close();
    
    echo "✓ Repackaged template with Verdana font\n";
    
    // Clean up temp directory
    function deleteDirectory($dir) {
        if (!file_exists($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
    deleteDirectory($tempDir);
    
    echo "✓ Successfully changed font to Verdana\n";
    echo "✓ Formatting preserved\n";
    echo "✓ Backup saved at: $backupPath\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    
    // Restore backup
    if (file_exists($backupPath)) {
        copy($backupPath, $templatePath);
        echo "✓ Restored original template from backup\n";
    }
    
    // Clean up temp directory if exists
    if (isset($tempDir) && file_exists($tempDir)) {
        deleteDirectory($tempDir);
    }
    
    exit(1);
}
