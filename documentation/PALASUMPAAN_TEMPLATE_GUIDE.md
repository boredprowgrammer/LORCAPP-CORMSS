# Palasumpaan Template Guide

## Overview
The palasumpaan generator has been updated to use PHPWord and convert documents to PDF. This provides better formatting control and professional output.

## Required Template File
- **File Name**: `PALASUMPAAN_template.docx`
- **Location**: Root directory of the project

## Template Placeholders

The DOCX template should contain the following placeholders that will be automatically replaced:

### Dynamic Content Placeholders

| Placeholder | Description | Example Output |
|------------|-------------|----------------|
| `${FULLNAME}` | Officer's full name (uppercase) | JUAN DELA CRUZ |
| `${LOCAL_NAME}` | Name of the local congregation (uppercase) | SAN FERNANDO |
| `${DISTRICT_NAME}` | District name (uppercase) | PAMPANGA EAST |
| `${DUTY}` | Officer's duty/position (uppercase) | DEACON |
| `${DAY}` | Day of oath | 24 |
| `${MONTH}` | Month in Tagalog (uppercase) | DISYEMBRE |
| `${YEAR}` | Year | 2025 |
| `${OATH_LOKAL}` | Local where oath was taken (uppercase) | SAN FERNANDO |
| `${OATH_DISTRITO}` | District where oath was taken (uppercase) | PAMPANGA EAST |

## How to Create the Template

1. **Open Microsoft Word** and create a new document with your palasumpaan layout

2. **Insert placeholders** exactly as shown above, including the `${}` syntax:
   - Type `${FULLNAME}` where the officer's name should appear
   - Type `${LOCAL_NAME}` where the local congregation name should appear
   - And so on for all other placeholders

3. **Format the document** as desired:
   - Apply bold, underline, italic formatting to text including placeholders
   - Set fonts, sizes, colors
   - Add borders, tables, or other formatting
   - PHPWord will preserve most formatting when generating the PDF

4. **Save the file** as `PALASUMPAAN_template.docx` in the root project directory

## Example Template Content

```
PANUNUMPA SA PAGTANGGAP NG TUNGKULIN

Akong si ${FULLNAME}, kaanib sa Iglesia Ni Cristo sa lokal ng ${LOCAL_NAME}, 
Distrito ng ${DISTRICT_NAME} ay nagpapahayag na aking tinatanggap ang tungkuling 
${DUTY}, udyok ng pananampalataya at malinis na budhi...

[rest of the oath content...]

${FULLNAME}
Nanumpa

Sinumpaan ngayong ika - ${DAY} ng ${MONTH} taong ${YEAR} sa 
Lokal ng ${OATH_LOKAL}, Distrito Eklesiastiko ng ${OATH_DISTRITO}
```

## Preview vs Generate Modes

### Preview Mode (`preview=1`)
- Generates a DOCX file for preview
- Shows `[TO BE FILLED]` for oath location if not specified
- Useful for reviewing the template before finalizing

### Generate Mode (default)
- Converts the DOCX to PDF
- Requires all oath location information
- Opens PDF in browser for immediate viewing

## Technical Details

### How It Works
1. PHPWord loads the `PALASUMPAAN_template.docx` template
2. Replaces all `${PLACEHOLDER}` values with actual data
3. Saves the processed document temporarily
4. Converts DOCX to HTML using PHPWord's HTML writer
5. Uses Dompdf to convert HTML to PDF
6. Streams the PDF to the browser

### File Processing
- Temporary files are created during conversion and automatically cleaned up
- PDF is streamed directly to browser (not saved to disk)
- Preview mode generates downloadable DOCX files

## Troubleshooting

### "Template file not found" Error
- Ensure `PALASUMPAAN_template.docx` exists in the root directory
- Check file name spelling and extension

### Placeholders Not Being Replaced
- Verify placeholders are typed exactly as shown: `${PLACEHOLDER}`
- Ensure there are no extra spaces inside the braces
- Don't split placeholders across multiple text runs in Word

### Formatting Issues in PDF
- PHPWord's HTML conversion may not preserve all complex formatting
- Test your template and adjust as needed
- Simpler layouts generally convert better

### PDF Display Issues
- Some fonts may not be available in the PDF renderer
- Default font is DejaVu Sans
- Bold and italic should work correctly

## Dependencies

The following Composer packages are used:
- `phpoffice/phpword` ^1.2 - For DOCX template processing
- `dompdf/dompdf` ^2.0 - For PDF conversion

These are automatically installed via `composer install`.
