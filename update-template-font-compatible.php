<?php
/**
 * Update PALASUMPAAN template with better font support for PDF conversion
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;

$templatePath = __DIR__ . '/PALASUMPAAN_template.docx';
$backupPath = __DIR__ . '/PALASUMPAAN_template_backup_font2_' . date('YmdHis') . '.docx';

if (!file_exists($templatePath)) {
    die("Error: Template file not found at $templatePath\n");
}

try {
    // Backup original template
    copy($templatePath, $backupPath);
    echo "✓ Backed up original template to: $backupPath\n";
    
    // Load the template
    $phpWord = IOFactory::load($templatePath);
    
    // Use Times New Roman with fallback - better supported in PDF converters
    // Or use Liberation Serif which is open source and widely available
    $fontName = 'Times New Roman'; // Alternative: 'Liberation Serif', 'DejaVu Serif'
    
    $phpWord->setDefaultFontName($fontName);
    $phpWord->setDefaultFontSize(12);
    
    // Iterate through all sections and update fonts
    foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $element) {
            // Handle text runs in paragraphs
            if (method_exists($element, 'getElements')) {
                foreach ($element->getElements() as $textRun) {
                    if (method_exists($textRun, 'getFontStyle')) {
                        $fontStyle = $textRun->getFontStyle();
                        if (is_object($fontStyle)) {
                            $fontStyle->setName($fontName);
                        }
                    }
                }
            }
        }
    }
    
    // Save the modified template
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($templatePath);
    
    echo "✓ Successfully updated template to use $fontName font\n";
    echo "✓ This font has better PDF conversion support\n";
    echo "✓ Original template backed up\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    
    // Restore backup if something went wrong
    if (file_exists($backupPath)) {
        copy($backupPath, $templatePath);
        echo "✓ Restored original template from backup\n";
    }
    exit(1);
}
