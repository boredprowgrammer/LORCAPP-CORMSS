# Church Officers Registry System (CORS)

A comprehensive Church Officers Registry and Request Management system built with PHP, MySQL, and modern UI components.
[![Better Stack Badge](https://uptime.betterstack.com/status-badges/v1/monitor/2b49u.svg)](https://uptime.betterstack.com/?utm_source=status_badge)
## Features

### Core Functionality
- **Headcount Tracker**
  - Automatic headcount management with CODE A (new record +1) and CODE D (existing record, no change)
  - Real-time tracking across districts and local congregations
  - Individual officer record pages with complete history

- **Transfer Management**
  - Transfer In: Add officers from other locations with automatic headcount increment
  - Transfer Out: Move officers to other locations with automatic headcount decrement
  - Auto-generated week numbers (Monday-Sunday basis)
  - Complete transfer history tracking

- **Officer Removal**
  - CODE A: Namatay (Deceased)
  - CODE B: Lumipat sa ibang lokal (Transfer Out)
  - CODE C: Inalis sa karapatan - suspendido (Suspended)
  - CODE D: Lipat Kapisanan (Transfer Kapisanan)
  - Automatic headcount adjustment

- **Officer Details Modal**
  - Quick view modal component for officer information
  - View complete officer details without page navigation
  - Integrated across multiple reports and pages
  - Shows departments, oath dates, and assignment history

- **R5 Transactions Tracking**
  - Comprehensive R5 transactions management system
  - Track oath taking, transfers, and removals by period
  - Generate detailed transaction reports and PDF logsheets
  - Period-based filtering and analytics

- **Bulk Palasumpaan Generator**
  - Generate multiple oath certificates (Palasumpaan) in batch
  - Improved decryption handling with fallback logic
  - Integration with Stirling PDF for efficient processing
  - Error handling and validation

- **LORC/LCRC Checker**
  - Verify officer record completeness
  - Inline editing for control numbers, registry numbers, purok, and grupo
  - Duplicate detection and merge functionality
  - Identify missing information and data gaps

- **R5-18 Checker**
  - Verify R5-18 form completeness
  - Track 2x2 picture attachments
  - Monitor signatory completion
  - Data verification workflow

- **Masterlist Generator**
  - Generate official officer masterlist reports
  - Landscape orientation with professional formatting
  - Control number sorting with nulls last
  - Auto-filter by district/local based on user role
  - Signatory autocomplete from tarheta records
  - Cookie-based signatory preferences
  - Print-optimized layout

- **Announcement System**
  - Create and manage system-wide announcements
  - Target specific roles (Admin, District, Local)
  - Location-based targeting (district/local specific)
  - Priority levels (Low, Medium, High, Urgent)
  - Announcement types (Info, Success, Warning, Error)
  - Schedule with start/end dates
  - Pin important announcements
  - User dismissal tracking

- **Real-Time Chat System**
  - Encrypted messaging between users
  - Conversation management with read receipts
  - Typing indicators
  - Real-time message updates
  - User search and conversation history

- **ISO 8601 Calendar**
  - Week number tracking (Monday-Sunday)
  - Monthly calendar view
  - Integration with transfer tracking
  - Quick navigation and date selection

- **Security Audit System**
  - Comprehensive security monitoring
  - 90-day automatic key rotation
  - Manual key rotation with data re-encryption
  - Infisical machine identity integration
  - Audit logging and compliance tracking

- **Developer Mode**
  - Protected with Ctrl+Shift+D key combination
  - Right-click protection when disabled
  - Diagonal watermark overlay on sensitive pages
  - Development tools access control

- **Legacy & Tarheta Control**
  - Manage legacy control numbers
  - Link legacy records to current officers
  - Tarheta control number registry
  - Search and autocomplete functionality

- **Request Management System**
  - Modal-based request workflow
  - Requirements checklist tracking
  - Approval workflow management
  - Request status monitoring
  - Document attachment support

### Security Features
- **Name Encryption**: District-specific encryption keys for officer names
- **Role-Based Access Control**: Admin, District User, and Local User roles
- **CSRF Protection**: Token-based form security
- **SQL Injection Prevention**: Prepared statements
- **XSS Protection**: Input sanitization and output escaping
- **Secure Sessions**: HTTP-only cookies, session timeout, login attempt limits
- **Password Hashing**: Argon2ID algorithm
- **Audit Logging**: Complete activity tracking

### User Roles
- **Admin**: Full system access, manage all districts and users
- **District User**: District-wide data management
- **Local User**: Local congregation level access only
- **Local Limited**: Restricted local access with limited permissions

### Modern UI/UX
- Dark theme with Tailwind CSS + DaisyUI
- Fully responsive design (mobile-first approach)
- Alpine.js for interactive components
- Font Awesome 6.x icons
- Smooth animations and transitions
- Auto-dismissing alerts
- Custom scrollbars
- Print-optimized layouts
- Inline editing capabilities

## Technology Stack

- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+ / MariaDB 10.3+
- **Frontend**: 
  - Tailwind CSS 3.x
  - DaisyUI 4.x
  - Alpine.js 3.x
  - Font Awesome 6.x
- **Security**: OpenSSL for encryption, Argon2ID for passwords

## Installation

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx web server
- OpenSSL PHP extension
- PDO MySQL extension

### Setup Instructions

1. **Clone or extract the project**
   ```bash
   cd /path/to/webroot
   ```

2. **Configure the database**
   - Create a MySQL database
   - Import the schema:
     ```bash
     mysql -u username -p database_name < database/schema.sql
     ```

3. **Configure environment**
   - Copy `config/config.php` and update:
     - Database credentials
     - Base URL
     - Master encryption key (IMPORTANT!)

4. **Set permissions**
   ```bash
   chmod 755 config/
   chmod 644 config/*.php
   ```

5. **Access the system**
   - Navigate to your base URL
   - Login with default credentials (see below)

### Default Credentials

**Admin Account:**
- Username: `admin`
- Password: `Admin@123`

**District User:**
- Username: `district1`
- Password: `District@123`

**Local User:**
- Username: `local1`
- Password: `Local@123`

**⚠️ IMPORTANT: Change all default passwords immediately after first login!**

## Database Schema

### Key Tables
- `users` - System users with role-based access
- `districts` - District information with encryption keys
- `local_congregations` - Local congregation data
- `officers` - Officer records (encrypted names)
- `officer_departments` - Department assignments
- `transfers` - Transfer in/out records
- `officer_removals` - Removal records with codes
- `headcount` - Real-time headcount tracking
- `audit_log` - Complete activity audit trail
- `officer_requests` - Aspiring officer requests
- `announcements` - System announcements
- `announcement_dismissals` - User dismissal tracking
- `chat_messages` - Encrypted chat messages
- `chat_conversations` - Conversation metadata
- `chat_read_receipts` - Message read tracking
- `legacy_officers` - Legacy control number records
- `tarheta_control` - Tarheta registry numbers
- `call_up_slips` - Officer call-up records

## Usage Guide

### Adding an Officer
1. Navigate to "Add Officer"
2. Enter personal information (Last Name, First Name, M.I.)
3. Select district and local congregation
4. Choose department and specify duty
5. Enter oath date
6. Check "has existing record" if applicable
   - Unchecked = CODE A (New Record, +1 headcount)
   - Checked = CODE D (Existing Record, no headcount change)

### Transfer In
1. Navigate to "Transfer In"
2. Enter officer information
3. Specify origin (from local and district)
4. Select destination (to local and district)
5. Enter department, oath date, and transfer date
6. Week number is auto-generated
7. Headcount automatically increases by +1

### Transfer Out
1. Navigate to "Transfer Out"
2. Search and select officer (autocomplete)
3. Specify destination location
4. Enter transfer date and details
5. Week number is auto-generated
6. Headcount automatically decreases by -1

### Removing an Officer
1. Navigate to "Remove Officer"
2. Search and select officer
3. Choose removal code:
   - A: Namatay (Deceased)
   - B: Lumipat (Transfer Out)
   - C: Suspendido (Suspended)
   - D: Lipat Kapisanan
4. Enter removal date and reason
5. Headcount automatically adjusted

## Security Best Practices

1. **Change Default Passwords**: Immediately update all default credentials
2. **Update Master Key**: Generate a secure MASTER_KEY in config.php
3. **Enable HTTPS**: Always use SSL/TLS in production
4. **Regular Backups**: Backup database and encryption keys
5. **Update Dependencies**: Keep PHP and MySQL updated
6. **Review Audit Logs**: Regularly check audit_log table
7. **Limit Access**: Only grant necessary permissions to users

## File Structure

```
CORegistry and CORTracker/
├── config/
│   ├── config.php          # Main configuration
│   └── database.php        # Database connection
├── includes/
│   ├── security.php        # Security functions
│   ├── encryption.php      # Encryption functions
│   ├── functions.php       # Helper functions
│   └── layout.php          # Main layout template
├── database/
│   └── schema.sql          # Database schema
├── officers/
│   ├── add.php             # Add officer
│   ├── list.php            # Officers list
│   ├── view.php            # View officer details
│   ├── edit.php            # Edit officer
│   └── remove.php          # Remove officer
├── transfers/
│   ├── transfer-in.php     # Transfer in form
│   └── transfer-out.php    # Transfer out form
├── reports/
│   ├── headcount.php           # Headcount reports
│   ├── departments.php         # Department reports
│   ├── lorc-lcrc-checker.php   # LORC/LCRC completeness checker
│   ├── r518-checker.php        # R5-18 form checker
│   ├── r5-transactions.php     # R5 transactions tracking
│   ├── r5-transactions-reports.php   # R5 transaction reports
│   ├── r5-transactions-search.php    # R5 transaction search
│   └── r5-transactions-logsheet.php  # R5 transaction PDF logsheet
├── requests/
│   ├── bulk-palasumpaan.php          # Bulk oath certificate generator
│   ├── bulk-palasumpaan-generate.php # Bulk generator backend
│   └── list.php                      # Officer requests list
├── legacy/
│   └── list.php            # Legacy control numbers management
├── tarheta/
│   └── list.php            # Tarheta control registry
├── calendar.php            # ISO 8601 week calendar
├── chat.php                # Real-time messaging system
├── security-audit.php      # Security monitoring dashboard
├── rotate-keys-90days.php  # Automated key rotation script
├── rotate-district-keys.php # Manual key rotation utility
├── admin/
│   ├── users.php           # User management
│   ├── districts.php       # District/local management
│   ├── audit.php           # Audit log viewer
│   └── announcements.php   # Announcement management
├── api/
│   ├── get-locals.php            # API: Get locals by district
│   ├── search-officers.php       # API: Search officers
│   ├── get-officer-details.php   # API: Get officer details
│   ├── merge-officers.php        # API: Merge duplicate officers
│   ├── search-legacy.php         # API: Search legacy records
│   ├── search-tarheta.php        # API: Search tarheta records
│   ├── dismiss-announcement.php  # API: Dismiss announcements
│   └── chat/                     # Real-time chat API endpoints
│       ├── get-conversations.php
│       ├── get-messages.php
│       ├── send-message.php
│       ├── mark-read.php
│       ├── search-users.php
│       └── typing-indicator.php
├── assets/
│   └── js/
│       └── officer-details-modal.js  # Officer details modal component
├── login.php               # Login page
├── logout.php              # Logout handler
├── dashboard.php           # Main dashboard
└── README.md               # This file
```

## Departments

The system supports the following departments:
- Pamunuan
- KNTSTSP
- Katiwala ng dako (GWS)
- Katiwala ng Purok
- II Katiwala ng Purok
- Katiwala ng Grupo
- II Katiwala ng Grupo
- Kalihim ng Grupo
- Diakono
- Diakonesa
- Lupon sa Pagpapatibay
- Ilaw Ng Kaligtasan
- Mang-aawit
- Organista
- Pananalapi
- Kalihiman
- Buklod
- KADIWA
- Binhi
- PNK
- Guro
- SCAN
- TSV
- CBI

## Future Features (Next Release)

- **Church Officers Request Management System**
  - Manage aspiring church officers
  - Request approval workflow
  - Status tracking
  - Integration with main registry

## 📚 Documentation

Complete documentation is available in the [`/documentation`](documentation/) folder:

- **[Installation Guide](documentation/INSTALL.md)** - Setup and installation instructions
- **[Security Documentation](documentation/)** - Security audit, encryption, and best practices
- **[Deployment Guides](documentation/)** - Docker, Render, and Aiven deployment
- **[Feature Documentation](documentation/)** - Detailed feature implementations
- **[API Documentation](documentation/)** - API endpoints and usage
- **[Officer Details Modal](documentation/officer-details-modal.md)** - Modal component usage guide
- **[Announcement System](documentation/ANNOUNCEMENTS.md)** - Announcement management documentation
- **[Chat System](documentation/chat-system.md)** - Real-time messaging documentation
- **[Calendar System](documentation/calendar.md)** - ISO 8601 calendar guide
- **[Key Rotation](documentation/KEY_ROTATION_SUMMARY.md)** - 90-day key rotation system
- **[Security Audit](documentation/SECURITY_AUDIT.md)** - Security monitoring guide
- **[Call-Up System](documentation/CALL_UP_FEATURE.md)** - Officer call-up documentation

For a complete index, see [documentation/README.md](documentation/README.md)

## Support

For issues, questions, or contributions, please contact your system administrator.

## License

Proprietary - Church Officers Registry System
© 2025 All Rights Reserved

## Changelog

### Version 2.1 (January 2, 2026)
- **New Features**
  - Officer Details Modal component for quick view across all pages
  - R5 Transactions tracking system with reports, search, and PDF generation
  - Bulk Palasumpaan (oath certificate) generator with batch processing
  - LORC/LCRC Checker with inline editing and duplicate detection
  - R5-18 Checker for form completeness verification
  - Masterlist Generator with landscape orientation and signatory autocomplete
  - Announcement System with role/location targeting and scheduling
  - Real-Time Chat System with encryption and read receipts
  - ISO 8601 Calendar for week tracking
  - Security Audit System with 90-day key rotation
  - Developer Mode with watermark and right-click protection
  - Legacy & Tarheta Control management
  - Request Management System with modal workflow
  - API endpoints for officer details and record merging
  
- **Improvements**
  - Enhanced decryption handling with better fallback logic
  - Improved error logging with detailed context
  - Better mobile responsiveness across all pages
  - Optimized search functionality for legacy and tarheta records
  - Control number sorting with nulls last in masterlist
  - Auto-filter by district/local based on user role
  - Print-optimized layouts for reports
  - Inline editing capabilities for various fields
  
- **Bug Fixes**
  - Fixed decryption failures when officer name data unavailable
  - Improved error handling in bulk operations
  - Enhanced validation for encrypted data fields
  - Resolved CODE D merge issues
  - Fixed unknown name display in search suggestions

### Version 1.0.0 (November 26, 2025)
- Initial release
- Core officer management features
- Transfer in/out functionality
- Officer removal with codes
- Headcount tracking
- Role-based access control
- Name encryption system
- Modern dark UI with Tailwind CSS + DaisyUI
- Comprehensive security features
- Audit logging

---

**Note**: This system handles sensitive personal information. Ensure compliance with data protection regulations and implement proper backup procedures.
