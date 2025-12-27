<?php
/**
 * Update PALASUMPAAN template to use Times New Roman (universally supported)
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;

$templatePath = __DIR__ . '/PALASUMPAAN_template.docx';
$backupPath = __DIR__ . '/PALASUMPAAN_template_backup_timesnewroman_' . date('YmdHis') . '.docx';

if (!file_exists($templatePath)) {
    die("Error: Template file not found at $templatePath\n");
}

try {
    // Backup original template
    copy($templatePath, $backupPath);
    echo "✓ Backed up original template to: $backupPath\n";
    
    // Load the template
    $phpWord = IOFactory::load($templatePath);
    
    // Set default font to Times New Roman (universally supported)
    $phpWord->setDefaultFontName('Times New Roman');
    $phpWord->setDefaultFontSize(12);
    
    // Iterate through all sections and paragraphs to change font
    foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $element) {
            // Handle text runs in paragraphs
            if (method_exists($element, 'getElements')) {
                foreach ($element->getElements() as $textRun) {
                    if (method_exists($textRun, 'getFontStyle')) {
                        $fontStyle = $textRun->getFontStyle();
                        if (is_object($fontStyle)) {
                            $fontStyle->setName('Times New Roman');
                        }
                    }
                }
            }
        }
    }
    
    // Save the modified template
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($templatePath);
    
    echo "✓ Successfully updated template to use Times New Roman font\n";
    echo "✓ This font is universally supported and will render properly in PDFs\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    
    // Restore backup if something went wrong
    if (file_exists($backupPath)) {
        copy($backupPath, $templatePath);
        echo "✓ Restored original template from backup\n";
    }
    exit(1);
}
