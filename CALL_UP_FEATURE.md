# Call-Up Officer Feature

## Overview
The Call-Up Officer feature (Tawag-Pansin) allows church administrators to create, manage, and print official call-up slips for officers who need to respond to various administrative matters.

## Features Implemented

### 1. Database Schema
**File:** `database/call_up_slips.sql`

Creates the `call_up_slips` table with the following fields:
- `slip_id` - Auto-increment primary key
- `officer_id` - Links to officers table
- `file_number` - Unique identifier (e.g., BUK-2025-001)
- `department` - Department/Kapisanan
- `reason` - Detailed reason for the call-up
- `issue_date` - Date the slip was issued
- `deadline_date` - Response deadline
- `status` - issued, responded, expired, cancelled
- `response_date` - When officer responded
- `response_notes` - Officer's response details
- `prepared_by` - User who created the slip
- `local_code` & `district_code` - Location tracking

**Installation:**
```sql
mysql -u your_user -p church_officers_db < database/call_up_slips.sql
```

### 2. Create Call-Up Slip Page
**File:** `officers/call-up.php`

Features:
- Officer search with autocomplete
- Real-time officer details display
- File number input with format guidance
- Department selection dropdown
- Reason text area
- Deadline date picker
- Automatic redirect to print after creation
- CSRF protection
- Role-based access control
- Audit logging

### 3. Call-Up List/Management Page
**File:** `officers/call-up-list.php`

Features:
- Filterable list by status (all, issued, responded, expired, cancelled)
- Search by officer name or file number
- Status badges with color coding
- Actions: View/Print, Mark as Responded, Cancel
- Automatic expiration detection
- Response notes modal
- Status counts in filters
- Empty state with helpful messaging

### 4. Print Call-Up Slip Page
**File:** `officers/print-call-up.php`

Features:
- Uses the exact template format from `Call-UpForm_Template.html`
- Print-optimized layout
- Clean, professional design matching church standards
- Includes all required fields:
  - Church/District/Local headers
  - Officer name (encrypted, decrypted for display)
  - Department/Kapisanan
  - File number
  - Issue date (Filipino format)
  - Reason section
  - Deadline with instructions (Nota)
  - Signature sections (Prepared by & Destinado)
- Print button with auto-close option
- Responsive design

### 5. Navigation Integration
**File:** `includes/layout.php`

Added new navigation section:
- "Call-Up Slips" section header
- "View Call-Ups" link - Goes to list page
- "Create Call-Up" link - Goes to creation form
- Active state highlighting
- Icon integration

## Usage Guide

### Creating a Call-Up Slip

1. Navigate to **Officers > Create Call-Up**
2. Search for the officer using the search box
3. Select the officer from the results
4. Fill in the required fields:
   - **File Number**: Format should be DEPT-YEAR-### (e.g., BUK-2025-001)
   - **Department**: Select from dropdown
   - **Reason**: Explain why the officer is being called up
   - **Deadline Date**: When response is due
5. Click "Create & Print Call-Up Slip"
6. The slip will be created and the print page will open automatically

### Viewing Call-Up Slips

1. Navigate to **Officers > View Call-Ups**
2. Use filters:
   - **Status**: All, Issued, Responded, Expired, Cancelled
   - **Search**: By officer name or file number
3. View status counts in the filter dropdown
4. Actions available:
   - **Print icon**: View/print the slip
   - **Check icon**: Mark as responded (for issued slips)
   - **X icon**: Cancel the slip (for issued slips)

### Printing Call-Up Slips

1. From the list, click the printer icon
2. Review the slip in the print preview
3. Use the "Print Call-Up Slip" button or browser print (Ctrl+P)
4. Slip includes all information in official church format
5. Close the window when done

### Managing Responses

1. When an officer responds, click the check mark icon
2. Optionally add response notes
3. The slip status will change to "Responded"
4. Response date is automatically recorded

## Status Workflow

1. **Issued** - Newly created, waiting for response
2. **Responded** - Officer has provided response
3. **Expired** - Deadline passed without response (auto-detected)
4. **Cancelled** - Administratively cancelled

## Security Features

- Role-based access control
- CSRF token protection on all forms
- District/Local filtering based on user role
- Encrypted officer names
- Audit logging for all actions
- SQL injection prevention (prepared statements)

## File Structure

```
officers/
├── call-up.php           # Create new call-up slip
├── call-up-list.php      # View and manage call-ups
└── print-call-up.php     # Print formatted slip

database/
└── call_up_slips.sql     # Database schema

includes/
└── layout.php            # Updated with navigation links
```

## Database Relationships

```
call_up_slips
├── officer_id → officers.officer_id
├── prepared_by → users.user_id
├── local_code → local_congregations.local_code
└── district_code → districts.district_code
```

## Permissions

The call-up feature respects existing officer management permissions:
- Users can only create call-ups for officers in their jurisdiction
- District users: Can manage call-ups for their district
- Local users: Can manage call-ups for their local congregation
- Admin: Can manage all call-ups

## Notes

- File numbers should follow a consistent format for easy tracking
- The print template matches the exact design from Call-UpForm_Template.html
- Filipino date format is used (AGOSTO 06, 2025)
- Automatic expiration checking runs when viewing the list
- Officers are shown with encrypted names that can be revealed on double-click

## Future Enhancements

Possible additions:
- Email notifications for call-ups
- Bulk call-up creation
- Call-up templates for common reasons
- Statistics and analytics
- Export to PDF functionality
- SMS notifications
- Calendar integration for deadlines

## Support

For issues or questions, refer to the main README.md or contact the system administrator.
