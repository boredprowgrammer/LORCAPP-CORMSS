# R5-13 Seminar Tracking System - Complete Implementation

## Overview
This system adds comprehensive seminar tracking for officer requests with two classes: **R5-04** (30-day extended seminar) and **R5-15** (8-day standard seminar). When all required seminar days are completed, the system can generate Form 513 (R5-13 Certificate).

## Features Implemented

### 1. Request Class Selection (requests/add.php)
- **Visual Card Interface**: Users select between R5-04 or R5-15 when creating a request
- **R5-15 (Default)**: 8-day standard seminar requirement
- **R5-04**: 30-day extended seminar requirement
- **Automatic Calculation**: System automatically sets `seminar_days_required` based on class

### 2. Seminar Progress Tracking (requests/view.php)
- **Progress Display**: Shows X/Y days completed with progress bar
- **Color-Coded Progress**: Purple for in-progress, green when complete
- **Add Seminar Date**: Modal interface to record individual seminar dates
- **Seminar Details**: Each entry shows date, topic, and notes
- **Delete Functionality**: Authorized users can remove seminar dates

### 3. R5-13 Certificate Generation (generate-r513.php)
- **Automatic Generation**: Available when all required days are completed
- **PHPWord Template**: Uses R5-13_template.docx with placeholder replacement
- **PDF Conversion**: Converts DOCX to PDF via Stirling PDF API
- **Encrypted Storage**: Stores PDF in database with encryption
- **Preview Mode**: View generated certificate without regenerating

## Database Schema

### New Columns in `officer_requests`
```sql
request_class ENUM('R5-04', 'R5-15') DEFAULT NULL
seminar_days_required INT DEFAULT 0
seminar_days_completed INT DEFAULT 0
seminar_dates JSON DEFAULT NULL
r513_generated_at TIMESTAMP NULL DEFAULT NULL
r513_pdf_file_id INT NULL
```

### Seminar Dates JSON Structure
```json
[
  {
    "date": "2024-01-15",
    "topic": "Pananampalataya",
    "notes": "Completed successfully",
    "added_at": "2024-01-15 14:30:00"
  },
  {
    "date": "2024-01-22",
    "topic": "Paglilingkod",
    "notes": "",
    "added_at": "2024-01-22 09:15:00"
  }
]
```

## File Structure

### Frontend Files
- **requests/add.php**: Request creation with seminar class selection
- **requests/view.php**: Request details with seminar tracking UI
- **requests/update-seminar.php**: API endpoint for adding/removing seminar dates

### Backend Files
- **generate-r513.php**: R5-13 certificate generator
- **R5-13_template.docx**: DOCX template with placeholders

### Database Files
- **database/add_request_class_and_seminar_tracking.sql**: Schema migration
- **database/run_add_request_class_migration.php**: Migration runner

### Documentation
- **documentation/R5-13_IMPLEMENTATION.md**: Original implementation guide
- **documentation/R5-13_SEMINAR_TRACKING.md**: This comprehensive guide

## User Workflow

### Creating a Request with Seminar
1. Navigate to **Requests > Add New Request**
2. Fill in officer information
3. Select **Request Class**:
   - **R5-15**: Standard 8-day seminar (default, recommended)
   - **R5-04**: Extended 30-day seminar (special circumstances)
4. Submit request

### Tracking Seminar Attendance
1. Open request in **Requests > View**
2. Scroll to **Seminar Progress** section
3. Click **Add Seminar Date** button
4. Enter:
   - **Date**: Date of seminar attendance (required)
   - **Topic/Lesson**: e.g., "Pananampalataya", "Paglilingkod"
   - **Notes**: Additional notes (optional)
5. Click **Add Seminar Date**
6. Repeat until all required days are completed

### Generating R5-13 Certificate
1. Complete all required seminar days (8 for R5-15, 30 for R5-04)
2. **Generate R5-13 Certificate** section appears automatically
3. Click **Generate R5-13 Certificate** button
4. Certificate is generated with:
   - Officer details
   - District/Local information
   - All seminar dates
   - Date of certificate generation
5. Certificate is stored as encrypted PDF
6. Click **Preview R5-13 Certificate** to view

## API Endpoints

### update-seminar.php
**Purpose**: Add or remove seminar dates

#### Add Seminar Date
```json
POST /requests/update-seminar.php
{
  "request_id": 123,
  "action": "add",
  "date": "2024-01-15",
  "topic": "Pananampalataya",
  "notes": "Completed successfully"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Seminar date added successfully",
  "days_completed": 5,
  "days_required": 8
}
```

#### Remove Seminar Date
```json
POST /requests/update-seminar.php
{
  "request_id": 123,
  "action": "delete",
  "index": 2
}
```

**Response**:
```json
{
  "success": true,
  "message": "Seminar date removed successfully",
  "days_completed": 4,
  "days_required": 8
}
```

### generate-r513.php
**Purpose**: Generate R5-13 certificate

#### Generate Certificate
```
POST /generate-r513.php
request_id=123
```

**Response**:
```json
{
  "success": true,
  "message": "R5-13 certificate generated successfully",
  "pdf_file_id": 456
}
```

#### Preview Certificate
```
GET /generate-r513.php?request_id=123&preview=1
```

**Response**: PDF file download

## Template Placeholders

### R5-13_template.docx Placeholders
```
${DISTRICT_NAME}        - District name
${DISTRICT_CODE}        - District code
${LOCAL_NAME}           - Local name
${LOCAL_CODE}           - Local code
${FULLNAME}             - Officer full name
${DUTY}                 - Officer duty/position
${BUWAN}                - Month (Tagalog)
${ARAW}                 - Day
${TAON}                 - Year
${SEMINAR1_DATE}        - First seminar date
${SEMINAR2_DATE}        - Second seminar date
...
${SEMINAR8_DATE}        - Eighth seminar date
```

**Note**: For R5-04 (30-day), placeholders go up to `${SEMINAR30_DATE}`

## Security Features

### Permissions
- **Admin**: Full access to all requests
- **District**: Manage requests in their district
- **Local**: Manage requests in their local
- **Read-Only**: View requests only (no seminar tracking)

### Data Protection
- **Authentication Required**: All API endpoints check login status
- **Permission Validation**: Users must have manage rights
- **Encrypted PDFs**: All generated certificates stored encrypted
- **SQL Injection Protection**: Prepared statements throughout
- **XSS Protection**: Output escaping with Security::escape()

## Error Handling

### Common Errors
1. **"Request does not have a seminar requirement"**
   - Request was created before seminar tracking was added
   - No request_class selected during creation

2. **"Already completed all X required seminar days"**
   - Cannot add more dates than required
   - Delete existing dates first if correction needed

3. **"Invalid date format"**
   - Date must be in YYYY-MM-DD format
   - Use date picker to avoid format issues

4. **"Seminar not completed yet"**
   - Cannot generate R5-13 until all days completed
   - Complete remaining seminar days first

## Progress Indicators

### Status Colors
- **Purple**: Seminar in progress
- **Green**: All seminar days completed
- **Gray**: No seminar requirement

### Progress Bar
```
Progress = (days_completed / days_required) * 100%
```

### Visual Feedback
- Days completed shown as badges numbered 1, 2, 3...
- Progress percentage displayed
- Checkmark icon when complete
- Color transitions from purple to green

## Testing Checklist

### Create Request
- [ ] R5-15 selection shows "8 days of seminar"
- [ ] R5-04 selection shows "30 days of seminar"
- [ ] Default selection is R5-15
- [ ] Backend correctly sets seminar_days_required

### Track Seminar
- [ ] Add seminar date modal appears
- [ ] Date picker works correctly
- [ ] Topic and notes saved properly
- [ ] Progress bar updates after adding date
- [ ] Dates sorted chronologically
- [ ] Delete button removes date
- [ ] Cannot add more than required days

### Generate Certificate
- [ ] Button appears when days_completed >= days_required
- [ ] Modal shows correct information
- [ ] Certificate generates successfully
- [ ] PDF is encrypted and stored
- [ ] Preview shows correct certificate
- [ ] Regenerate button works
- [ ] r513_generated_at timestamp set

## Maintenance

### Database Cleanup
```sql
-- Find requests with incomplete seminar tracking
SELECT request_id, request_class, seminar_days_completed, seminar_days_required
FROM officer_requests
WHERE request_class IS NOT NULL
  AND seminar_days_completed < seminar_days_required;

-- Find requests ready for R5-13 generation
SELECT request_id, request_class, seminar_days_completed, r513_generated_at
FROM officer_requests
WHERE request_class IS NOT NULL
  AND seminar_days_completed >= seminar_days_required
  AND r513_generated_at IS NULL;
```

### Log Monitoring
Check logs for:
- Failed seminar date additions
- Certificate generation errors
- Stirling PDF API failures
- Template processing errors

## Future Enhancements

### Potential Improvements
1. **Bulk Import**: Import seminar dates from CSV
2. **Attendance QR Code**: Scan QR codes to record attendance
3. **Email Notifications**: Notify when seminar completed
4. **Certificate Templates**: Multiple template options
5. **Seminar Calendar**: View all scheduled seminars
6. **Attendance Reports**: Export seminar attendance data
7. **Digital Signatures**: Sign certificates digitally
8. **Mobile App**: Track attendance via mobile

## Support

### Troubleshooting
- Check error logs in `logs/` directory
- Verify Stirling PDF API is accessible
- Ensure R5-13_template.docx exists
- Confirm database columns exist
- Validate user permissions

### Contact
For issues or questions, contact the system administrator.

---

**Implementation Date**: January 2024  
**Version**: 1.0  
**Status**: Production Ready ✅
