# Palasumpaan Generator - PHPWord Implementation Summary

## Changes Made

### 1. Dependencies Added
- **File Created**: `composer.json` in root directory
- **Packages Installed**:
  - `phpoffice/phpword` ^1.2 - For DOCX template processing
  - `dompdf/dompdf` ^2.0 - For PDF conversion

### 2. Main Generator Rewritten
- **File Modified**: `generate-palasumpaan.php`
- **Old Backup**: `generate-palasumpaan-old.php`

#### Key Changes:
- Removed HTML/JavaScript-based rendering approach
- Implemented PHPWord template processor
- Added PDF conversion using dompdf
- Maintained all existing functionality (permissions, encryption, date formatting)

### 3. Template System
The new system uses a DOCX template file with placeholders:
- **Required File**: `PALASUMPAAN_template.docx` (in root directory)
- **Placeholders**: 
  - `${FULLNAME}` - Officer's full name
  - `${LOCAL_NAME}` - Local congregation name
  - `${DISTRICT_NAME}` - District name (defaults to "PAMPANGA EAST")
  - `${DUTY}` - Officer's duty/position
  - `${DAY}`, `${MONTH}`, `${YEAR}` - Oath date components
  - `${OATH_LOKAL}`, `${OATH_DISTRITO}` - Oath location

### 4. Documentation Created
- **File**: `documentation/PALASUMPAAN_TEMPLATE_GUIDE.md`
- Contains detailed instructions for creating the DOCX template

## How It Works

### Preview Mode
When called with `?preview=1`:
1. Loads the DOCX template
2. Replaces placeholders with actual data
3. Downloads the processed DOCX file
4. Shows `[TO BE FILLED]` for unspecified oath locations

### Generate Mode (Default)
1. Loads the DOCX template
2. Replaces all placeholders with actual data
3. Converts DOCX to HTML using PHPWord
4. Converts HTML to PDF using dompdf
5. Displays PDF in browser

## Next Steps

### Required Action: Create Template File
You need to create `PALASUMPAAN_template.docx` in the root directory. This file should:

1. **Contain the oath text** in Tagalog
2. **Use placeholders** where dynamic content should appear
3. **Include formatting** (bold, underline, fonts, etc.)

#### Example Template Structure:
```
PANUNUMPA SA PAGTANGGAP NG TUNGKULIN

Akong si ${FULLNAME}, kaanib sa Iglesia Ni Cristo sa lokal ng 
${LOCAL_NAME}, Distrito ng ${DISTRICT_NAME} ay nagpapahayag 
na aking tinatanggap ang tungkuling ${DUTY}...

[Full oath content here]

${FULLNAME}
Nanumpa

Sinumpaan ngayong ika - ${DAY} ng ${MONTH} taong ${YEAR} sa 
Lokal ng ${OATH_LOKAL}, Distrito Eklesiastiko ng ${OATH_DISTRITO}
```

### Testing the Implementation

Once you create the template:

1. **Test Preview Mode**:
   ```
   https://yoursite.com/generate-palasumpaan.php?request_id=XX&preview=1
   ```
   Should download a DOCX file with filled data

2. **Test Generate Mode**:
   ```
   https://yoursite.com/generate-palasumpaan.php?request_id=XX&oath_date=2025-12-24&oath_lokal=San%20Fernando&oath_distrito=Pampanga%20East
   ```
   Should display a PDF in the browser

## Benefits of New Approach

1. **Professional Output**: PDF files are more professional than HTML screenshots
2. **Better Formatting**: Word template preserves formatting better
3. **Easier Updates**: Non-technical users can update the template in Word
4. **Consistent Layout**: Template ensures consistency across all certificates
5. **No JavaScript Dependencies**: Server-side processing is more reliable
6. **Smaller File Sizes**: Native PDF generation produces smaller files

## File Locations

```
/home/personal1/CORegistry and CORTracker/
├── composer.json (NEW)
├── vendor/ (NEW - Composer packages)
├── generate-palasumpaan.php (MODIFIED)
├── generate-palasumpaan-old.php (BACKUP)
├── PALASUMPAAN_template.docx (TO BE CREATED)
└── documentation/
    └── PALASUMPAAN_TEMPLATE_GUIDE.md (NEW)
```

## Compatibility

- **PHP Version**: Requires PHP 7.4 or higher
- **Extensions**: No special PHP extensions required
- **Browser**: Works with all modern browsers
- **Mobile**: PDF viewing works on mobile devices

## Troubleshooting

If you encounter issues:

1. **Check template exists**: Ensure `PALASUMPAAN_template.docx` is in the root directory
2. **Check permissions**: Ensure PHP can read the template file
3. **Check logs**: Error messages are logged via `error_log()`
4. **Verify Composer**: Run `composer install` if packages are missing
5. **Test placeholders**: Ensure placeholders in template match exactly

## Support

For detailed template creation instructions, see:
`documentation/PALASUMPAAN_TEMPLATE_GUIDE.md`
