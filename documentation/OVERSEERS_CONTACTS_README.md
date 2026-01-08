# Group and Area Overseers Contact Registry

## Overview
This feature provides a comprehensive contact registry for Group and Area Overseers at both Grupo and Purok levels, with secure encryption, QR code generation, and integration with the church officers registry.

## Features

### 1. Two-Level Contact Management
- **Grupo Level**: Manages contacts for Purok Grupo overseers
- **Purok Level**: Manages contacts for Purok overseers

### 2. Officer Positions
Each level includes three key positions:
- **Katiwala** (Leader)
- **II Katiwala** (Assistant Leader)
- **Kalihim** (Secretary)

### 3. Contact Information
For each position:
- Officer name (with autocomplete from church officers registry)
- Contact number
- Telegram account

### 4. Security Features
- **Encrypted Storage**: Officer IDs are encrypted using AES-256-GCM encryption
- **Role-Based Access**: District and Local users can only access their respective areas
- **Audit Trail**: Complete logging of all create, update, and delete operations

### 5. QR Code Generation
- Generate QR codes for contact numbers (tel: links)
- Generate QR codes for Telegram accounts (https://t.me/ links)
- Download QR codes as PNG images
- Share QR codes on mobile devices

### 6. Officer Name Suggestions
- Real-time search and autocomplete from church officers registry
- District and local filtering for relevant suggestions
- Encrypted name storage and retrieval

## File Structure

### Database
- `database/add_overseers_contacts.sql` - Database schema with audit trail

### Main Pages
- `overseers-contacts.php` - Main listing and management page
- `overseers-contacts-view.php` - Detailed view with QR codes

### API Endpoints
- `api/overseers-contacts/list.php` - List all contacts (with role-based filtering)
- `api/overseers-contacts/create.php` - Create new contact
- `api/overseers-contacts/update.php` - Update existing contact
- `api/overseers-contacts/delete.php` - Soft delete contact
- `api/get-districts.php` - Get districts for dropdown (created if not exists)

## Installation

### 1. Run Database Migration
```bash
mysql -u [username] -p [database_name] < database/add_overseers_contacts.sql
```

### 2. Verify Required Tables
Ensure these tables exist:
- `districts` - District information with encryption keys
- `locals` - Local congregation information
- `officers` - Officer registry with encrypted names
- `users` - User accounts for audit trail

### 3. Add Navigation Link
Add to your navigation menu:
```php
<a href="overseers-contacts.php">
    <i class="fas fa-address-book"></i>
    Overseers Contacts
</a>
```

## Usage

### Adding a Contact
1. Click "Add Contact" button
2. Select Type (Grupo or Purok level)
3. Select District and Local
4. Enter Grupo/Purok name
5. For each position:
   - Search and select officer from registry (optional)
   - Enter contact number
   - Enter Telegram account
6. Click "Save Contact"

### Viewing Contacts
- Click the eye icon to view detailed contact information
- Generate QR codes by clicking the QR icon next to any contact method
- Download or share QR codes

### Editing Contacts
1. Click the edit icon
2. Modify any fields
3. Click "Save Contact"

### Filtering
- Filter by Type (Grupo/Purok)
- Filter by District
- Filter by Local
- Search by name, area, or contact information

## Security Considerations

### Encryption
- Officer IDs are stored as encrypted JSON arrays
- Each district has its own encryption key
- Names are decrypted on-demand for display

### Access Control
- Admin: Full access to all contacts
- District: Access to their district only
- Local: Access to their local only
- Regular users: Read-only access (if granted)

### Audit Trail
All operations are logged with:
- Action type (create, update, delete, view)
- Old and new values
- User who performed the action
- Timestamp
- IP address
- User agent

## QR Code Features

### Supported Formats
1. **Phone Numbers**: Creates `tel:` links for direct calling
2. **Telegram**: Creates `https://t.me/` links to open Telegram

### Actions
- **View**: Display QR code in modal
- **Download**: Save QR code as PNG image
- **Share**: Share QR code on mobile devices (if supported)

## API Response Format

### List Contacts
```json
{
  "success": true,
  "contacts": [
    {
      "contact_id": 1,
      "contact_type": "grupo",
      "district_code": "D001",
      "local_code": "L001",
      "purok_grupo": "Grupo 1",
      "katiwala_names": "Juan Dela Cruz",
      "katiwala_contact": "+63 912 345 6789",
      "katiwala_telegram": "@juandc",
      "local_name": "Local Name",
      "district_name": "District Name"
    }
  ]
}
```

### Create/Update Response
```json
{
  "success": true,
  "message": "Contact created successfully",
  "contact_id": 1
}
```

## Dark Mode Support
- Full dark mode support using Tailwind CSS dark: variants
- Respects user's dark mode preference from cookies
- All modals and components are dark-mode compatible

## Dependencies
- **Tailwind CSS 2.2.19**: Styling
- **Font Awesome 6.4.0**: Icons
- **QRCode.js 1.5.3**: QR code generation
- **PHP 7.4+**: Server-side logic
- **MySQL 5.7+**: Database

## Troubleshooting

### Officers Not Appearing in Autocomplete
- Verify officer names are encrypted in the database
- Check district/local filters are set correctly
- Ensure `api/search-officers.php` endpoint exists and works

### QR Codes Not Generating
- Check browser console for JavaScript errors
- Verify QRCode.js library is loaded
- Ensure contact data is properly formatted

### Encryption Errors
- Verify district has encryption key in `districts` table
- Check `includes/encryption.php` exists and is included
- Ensure OpenSSL extension is enabled in PHP

## Future Enhancements
- Bulk import from CSV/Excel
- Export contacts to VCF (vCard) format
- SMS/Telegram notification integration
- Contact verification workflow
- Historical change tracking view
- Multi-language support

## Support
For issues or questions, contact the system administrator or refer to the main application documentation.
