# R5-13 Form 513 Implementation Guide

## Overview
R5-13 is a certificate for seminar attendance for church officers (PATOTOO NG PAGDALO SA SEMINAR AT PAGSASANAY).

## Database Structure

### New Columns in `officer_requests` table:
- `request_class` - ENUM('R5-04', 'R5-15')
  - R5-04: Requires 30 days of seminar
  - R5-15: Requires 8 days of seminar
- `seminar_days_required` - INT (30 or 8)
- `seminar_days_completed` - INT (count of dates in seminar_dates)
- `seminar_dates` - JSON array of objects: `[{"date": "2025-12-24", "topic": "Leadership", "notes": "Completed"}]`
- `r513_generated_at` - TIMESTAMP (when certificate was generated)
- `r513_pdf_file_id` - INT (FK to pdf_files table)

## Template Creation

### Source File
- Located at: `R5-13(Seminar_Form)/513.pdf-1.html`
- Very complex HTML with absolute positioning
- Contains 8 lesson rows with date/signature columns

### Creating R5-13_template.docx

**Option 1: Manual Creation (Recommended)**
1. Open the original PDF or a sample R5-13 form
2. Recreate in Microsoft Word with proper formatting
3. Add placeholders:
   - `${DISTRICT_NAME}` - District name
   - `${DISTRICT_CODE}` - District code (DCODE)
   - `${LOCAL_NAME}` - Local congregation name
   - `${LOCAL_CODE}` - Local code (LCODE)
   - `${FULLNAME}` - Officer's full name
   - `${DUTY}` - Officer's duty/position
   - `${BUWAN}`, `${ARAW}`, `${TAON}` - Month, Day, Year (top right)
   - `${SEMINAR1_DATE}` through `${SEMINAR8_DATE}` - Individual seminar dates
4. Save as `R5-13_template.docx` in the project root

**Option 2: Convert HTML to DOCX**
1. Use a tool like Pandoc or an online converter
2. Clean up the converted document
3. Add placeholders as above

### Lesson List (for reference)
The form includes 8 main lessons:
1. Ang Katangian Hinahanap ng Diyos Sa Pinipili Niyang Mangasiwa
2. Dapat Matupad Ang Layon Ng Diyos Sa Paglalagay Ng Mga Maytungkulin
3. Ang Paghahatid Sa Iglesia Sa Kasakdalan O Kabanalan
4. Ang Pangangalaga Sa Iglesia
5. Ang Banal Na Layunin Ng Mga Tagapag-alaga sa Iglesia
6. Mahalaga Ang Pananampalataya Sa Pagtupad Ng Tungkulin
7. Uliran Ng Iglesia Ang Mga Tagapag-alaga
8. Ang Pagpapasakop Sa Pamamahala Ng Iglesia

Plus 3 "PAKSA" sections with multiple topics each.

## Usage Flow

### 1. Create Request with Class
- When creating an officer request, select R5-04 or R5-15
- System automatically sets `seminar_days_required` (30 or 8)

### 2. Track Seminar Dates
- In `requests/view.php`, add UI to input seminar dates
- Each date entry includes:
  - Date attended
  - Topic/lesson covered
  - Optional notes
- Store in `seminar_dates` JSON field
- Update `seminar_days_completed` count

### 3. Generate R5-13 Certificate
- Button appears when `seminar_days_completed >= seminar_days_required`
- Calls `generate-r513.php` via AJAX
- Uses PHPWord to populate template
- Converts to PDF via Stirling PDF API
- Stores encrypted PDF in database
- Links PDF via `r513_pdf_file_id`

### 4. View/Download Certificate
- Similar to palasumpaan, use `api/get-pdf.php?id={pdf_id}`
- PDF is decrypted and served to authorized users

## Files Created

1. **Database Migration**
   - `database/add_request_class_and_seminar_tracking.sql` - SQL migration
   - `database/run_add_request_class_migration.php` - PHP migration runner
   - ✅ Migration completed successfully

2. **Generator**
   - `generate-r513.php` - R5-13 certificate generator
   - ✅ Created (requires template)

3. **Template** (TODO)
   - `R5-13_template.docx` - DOCX template with placeholders
   - ❌ Needs to be created manually

4. **UI Updates** (TODO)
   - `requests/create.php` - Add request_class dropdown
   - `requests/view.php` - Add seminar date tracking UI
   - `requests/view.php` - Add "Generate R5-13" button

## Next Steps

1. Create `R5-13_template.docx` manually in Microsoft Word
2. Update request creation form to include request_class selection
3. Add seminar date tracking interface in request view page
4. Add "Generate R5-13" button (shown when seminar is complete)
5. Test the complete workflow

## Notes

- R5-13 certificates are stored in the same `pdf_files` table as palasumpaan
- Use reference_type='r513' for R5-13 certificates
- The template should maintain the official INC format and layout
- Consider adding validation to ensure seminar dates are in chronological order
- May need to extend for R5-04 (30 days) with additional pages or different template
