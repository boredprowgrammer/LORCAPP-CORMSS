<?php
/**
 * Fix font rendering in PALASUMPAAN template
 * Ensure font is properly set with all attributes
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;

$templatePath = __DIR__ . '/PALASUMPAAN_template.docx';
$backupPath = __DIR__ . '/PALASUMPAAN_template_backup_fontfix_' . date('YmdHis') . '.docx';

if (!file_exists($templatePath)) {
    die("Error: Template file not found at $templatePath\n");
}

try {
    // Backup original template
    copy($templatePath, $backupPath);
    echo "✓ Backed up original template to: $backupPath\n";
    
    // Load the template
    $phpWord = IOFactory::load($templatePath);
    
    // Set default font explicitly
    $phpWord->setDefaultFontName('Gentium Book Basic');
    $phpWord->setDefaultFontSize(12);
    
    echo "Processing sections and elements...\n";
    
    // Iterate through all sections
    $sectionCount = 0;
    $elementCount = 0;
    
    foreach ($phpWord->getSections() as $section) {
        $sectionCount++;
        
        foreach ($section->getElements() as $element) {
            $elementCount++;
            $elementClass = get_class($element);
            
            // Handle different element types
            if (method_exists($element, 'getElements')) {
                // This is likely a TextRun or similar container
                foreach ($element->getElements() as $childElement) {
                    if (method_exists($childElement, 'getFontStyle')) {
                        $fontStyle = $childElement->getFontStyle();
                        
                        if (is_object($fontStyle)) {
                            // Set font name explicitly
                            $fontStyle->setName('Gentium Book Basic');
                            
                            // Preserve other styles (bold, italic, etc)
                            if (!$fontStyle->getSize()) {
                                $fontStyle->setSize(12);
                            }
                        } elseif (is_string($fontStyle)) {
                            // Style is a string reference, modify the text element
                            if (method_exists($childElement, 'setFontStyle')) {
                                $childElement->setFontStyle(['name' => 'Gentium Book Basic', 'size' => 12]);
                            }
                        }
                    }
                }
            }
        }
    }
    
    echo "  Processed $sectionCount sections and $elementCount elements\n";
    
    // Save the modified template
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($templatePath);
    
    echo "✓ Successfully fixed font rendering in template\n";
    echo "✓ Font: Gentium Book Basic with explicit attributes\n";
    echo "✓ Backup saved at: $backupPath\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  Stack trace: " . $e->getTraceAsString() . "\n";
    
    // Restore backup if something went wrong
    if (file_exists($backupPath)) {
        copy($backupPath, $templatePath);
        echo "✓ Restored original template from backup\n";
    }
    exit(1);
}
