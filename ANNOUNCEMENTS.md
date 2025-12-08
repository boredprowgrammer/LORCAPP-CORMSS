# Announcement System

## Overview
The Announcement System allows administrators to create and manage system-wide announcements that can be displayed to users based on their role and location.

## Installation

1. **Create Database Tables**
   ```bash
   mysql -u username -p database_name < database/announcements.sql
   ```

2. **Verify Installation**
   - The announcements and announcement_dismissals tables should now exist in your database

## Features

### For Administrators
- Create, edit, and delete announcements
- Set announcement priority (Low, Medium, High, Urgent)
- Choose announcement type (Info, Success, Warning, Error)
- Pin important announcements to appear first
- Target specific audiences:
  - All users
  - Specific roles (Admin, District, Local)
  - Specific districts
  - Specific local congregations
- Schedule announcements with start and end dates
- Toggle announcement active status
- View dismissal statistics

### For Users
- View relevant announcements on dashboard
- Dismiss announcements individually
- See pinned announcements first
- Automatic hiding of expired announcements

## Announcement Types

| Type | Color | Use Case |
|------|-------|----------|
| Info | Blue | General information, updates |
| Success | Green | Good news, achievements |
| Warning | Yellow | Important notices, cautions |
| Error | Red | Critical issues, urgent alerts |

## Priority Levels

| Priority | Badge Color | Description |
|----------|-------------|-------------|
| Low | Gray | Minor updates |
| Medium | Blue | Standard announcements |
| High | Orange | Important notices |
| Urgent | Red | Critical alerts |

## Targeting Options

### Role-Based Targeting
- **All Users**: Everyone sees the announcement
- **Admin Only**: Only administrators
- **District Users**: All district-level users
- **Local Users**: All local-level users

### Location-Based Targeting
- **Specific District**: Only users in a particular district
- **Specific Local**: Only users in a particular local congregation

## Usage

### Creating an Announcement

1. Navigate to **Administration > Announcements**
2. Click **Create Announcement**
3. Fill in the form:
   - **Title**: Short, descriptive title (max 200 characters)
   - **Message**: Full announcement text (supports line breaks)
   - **Type**: Choose visual style (info, success, warning, error)
   - **Priority**: Set importance level
   - **Target Role**: Who should see this
   - **District/Local**: Optional location targeting
   - **Start/End Date**: Optional scheduling
   - **Pin**: Check to keep announcement at top
4. Click **Create Announcement**

### Editing an Announcement

1. Go to **Administration > Announcements**
2. Find the announcement to edit
3. Click the **Edit** (pencil) icon
4. Modify fields as needed
5. Click **Update Announcement**

### Deactivating an Announcement

1. Go to **Administration > Announcements**
2. Find the announcement
3. Click the **Toggle** icon
4. Announcement will no longer be displayed to users

### Deleting an Announcement

1. Go to **Administration > Announcements**
2. Find the announcement
3. Click the **Delete** (trash) icon
4. Confirm deletion

## Display Behavior

### On Dashboard
- Announcements appear between the welcome header and key metrics
- Sorted by: Pinned first, then Priority (High to Low), then Date (Newest first)
- Users can dismiss announcements with the X button
- Dismissed announcements won't reappear for that user

### Visibility Rules
An announcement is shown if ALL conditions are met:
1. `is_active = 1`
2. Current date is between start_date and end_date (if set)
3. User hasn't dismissed it
4. Target role matches OR target district/local matches

## API Endpoints

### Dismiss Announcement
**Endpoint**: `POST /api/dismiss-announcement.php`

**Request**:
```json
{
  "announcement_id": 123
}
```

**Response**:
```json
{
  "success": true,
  "message": "Announcement dismissed"
}
```

## Database Schema

### announcements Table
```sql
- announcement_id (INT, PK, AUTO_INCREMENT)
- title (VARCHAR 200)
- message (TEXT)
- announcement_type (ENUM: info, warning, success, error)
- priority (ENUM: low, medium, high, urgent)
- is_active (TINYINT 1)
- is_pinned (TINYINT 1)
- target_role (ENUM: all, admin, district, local)
- target_district_code (VARCHAR 20, nullable)
- target_local_code (VARCHAR 20, nullable)
- start_date (DATETIME, nullable)
- end_date (DATETIME, nullable)
- created_by (INT, FK to users)
- updated_by (INT, FK to users, nullable)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

### announcement_dismissals Table
```sql
- id (INT, PK, AUTO_INCREMENT)
- announcement_id (INT, FK to announcements)
- user_id (INT, FK to users)
- dismissed_at (TIMESTAMP)
- UNIQUE (user_id, announcement_id)
```

## File Structure

```
/admin/
  announcements.php       - Admin management interface
/api/
  dismiss-announcement.php - API endpoint for dismissing
/includes/
  announcements.php       - Helper functions and rendering
/database/
  announcements.sql       - Database schema
```

## Helper Functions

### getUserAnnouncements($userId)
Get all active, relevant announcements for a user.

**Parameters**:
- `$userId` (int): User ID (optional, defaults to current user)

**Returns**: Array of announcement objects

### dismissAnnouncement($announcementId, $userId)
Mark an announcement as dismissed for a user.

**Parameters**:
- `$announcementId` (int): Announcement ID
- `$userId` (int): User ID

**Returns**: Boolean success status

### renderAnnouncement($announcement, $dismissible)
Generate HTML for displaying an announcement.

**Parameters**:
- `$announcement` (array): Announcement data
- `$dismissible` (bool): Whether to show dismiss button

**Returns**: HTML string

## Example Use Cases

### System Maintenance Notice
```
Title: Scheduled Maintenance - December 15
Message: The system will be unavailable on December 15 from 2:00 AM to 4:00 AM for routine maintenance.
Type: Warning
Priority: High
Target: All Users
Start Date: December 10, 2025 00:00
End Date: December 15, 2025 23:59
Pin: Yes
```

### New Feature Announcement
```
Title: New Calendar Feature Available!
Message: Check out our new ISO 8601 week calendar for tracking transfers. Access it from the Dashboard Quick Actions.
Type: Success
Priority: Medium
Target: All Users
Pin: No
```

### District-Specific Notice
```
Title: District 1 Meeting - January 5
Message: All District 1 users are required to attend the meeting on January 5, 2025 at 10:00 AM.
Type: Info
Priority: High
Target: District Users
District: District 1
Start Date: December 20, 2024
End Date: January 5, 2025
Pin: Yes
```

## Best Practices

1. **Keep titles concise** - Users should understand the message at a glance
2. **Use appropriate types** - Match the visual style to the message tone
3. **Set realistic priorities** - Don't mark everything as urgent
4. **Schedule announcements** - Use start/end dates for time-sensitive messages
5. **Target appropriately** - Only show announcements to relevant users
6. **Pin sparingly** - Too many pinned items reduce their effectiveness
7. **Review regularly** - Deactivate or delete outdated announcements

## Troubleshooting

### Announcements Not Showing
- Check `is_active` status
- Verify start/end dates
- Confirm user role/location matches targeting
- Check if user has dismissed the announcement

### Cannot Create Announcement
- Verify database tables exist
- Check user has admin role
- Ensure CSRF token is valid
- Review error logs

### Dismiss Not Working
- Check browser console for JavaScript errors
- Verify API endpoint is accessible
- Confirm user is logged in
- Check database connectivity

## Future Enhancements
- Email notifications for urgent announcements
- Announcement read receipts
- Rich text editor for message formatting
- Announcement templates
- Analytics dashboard (views, dismissals, etc.)
- Scheduled automatic deletion
- Announcement categories
- User preferences for announcement types
