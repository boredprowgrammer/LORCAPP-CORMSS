# Church Officers Registry System (CORS)

A comprehensive Church Officers Registry and Request Management system built with PHP, MySQL, and modern UI components.

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

### Modern UI/UX
- Dark theme with Tailwind CSS + DaisyUI
- Fully responsive design
- Alpine.js for interactive components
- Font Awesome icons
- Smooth animations and transitions
- Auto-dismissing alerts
- Custom scrollbars

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
- `officer_requests` - Aspiring officer requests (future release)

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
│   ├── headcount.php       # Headcount reports
│   └── departments.php     # Department reports
├── admin/
│   ├── users.php           # User management
│   ├── districts.php       # District/local management
│   └── audit.php           # Audit log viewer
├── api/
│   ├── get-locals.php      # API: Get locals by district
│   └── search-officers.php # API: Search officers
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

## Support

For issues, questions, or contributions, please contact your system administrator.

## License

Proprietary - Church Officers Registry System
© 2025 All Rights Reserved

## Changelog

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
