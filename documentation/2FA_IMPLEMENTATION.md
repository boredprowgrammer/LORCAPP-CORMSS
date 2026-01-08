# Two-Factor Authentication (2FA) Implementation

## Overview
This system implements TOTP (Time-based One-Time Password) authentication using the robthree/twofactorauth library. 2FA adds an extra layer of security by requiring users to provide a time-based code from their authenticator app in addition to their password.

## Security Features

### 1. **Encrypted Storage**
- TOTP secrets are encrypted using the application's encryption system before storage
- Backup codes are hashed using `password_hash()` (like passwords) before storage
- All sensitive data is encrypted per-user using their user_id as the encryption key

### 2. **Rate Limiting**
- Maximum 5 failed 2FA setup attempts per 15 minutes
- Maximum 10 failed 2FA login attempts per 15 minutes
- Attempts are tracked by user ID and IP address
- Automatic cleanup of old attempt records

### 3. **Backup Codes**
- 10 backup codes generated during setup
- Each code is 8 characters (alphanumeric, uppercase)
- Codes are one-time use only
- Removed from database after use
- Can be downloaded as a text file during setup

### 4. **Audit Logging**
- All 2FA-related events are logged:
  - Setup initiated
  - 2FA enabled
  - 2FA disabled
  - Successful verification
  - Failed attempts
  - Backup code usage

### 5. **Session Security**
- Separate session flow for 2FA verification
- Password verified first, then 2FA required
- Session regeneration after successful 2FA
- IP address and user agent binding

## User Flow

### Enabling 2FA

1. User navigates to Settings page
2. Clicks "Enable Two-Factor Authentication"
3. System generates:
   - A unique TOTP secret (160-bit)
   - QR code for easy scanning
   - 10 backup codes
4. User scans QR code with authenticator app (Google Authenticator, Microsoft Authenticator, Authy, etc.)
5. User saves backup codes in a safe place
6. User enters a 6-digit code from their app to verify setup
7. 2FA is enabled upon successful verification

### Login with 2FA

1. User enters username and password
2. If 2FA is enabled, user is prompted for verification code
3. User enters 6-digit code from authenticator app (or backup code)
4. Upon successful verification, user is logged in

### Disabling 2FA

1. User navigates to Settings page
2. Clicks "Disable Two-Factor Authentication"
3. System prompts for:
   - Current password (for security)
   - Current 2FA code (proof of access to authenticator)
4. Upon successful verification, 2FA is disabled and all secrets are cleared

## Database Schema

### New Columns in `users` Table

```sql
totp_secret_encrypted       TEXT NULL          -- Encrypted TOTP secret
totp_enabled                TINYINT(1)         -- Whether 2FA is active
totp_backup_codes_encrypted TEXT NULL          -- Encrypted backup codes (JSON)
totp_verified_at            TIMESTAMP NULL     -- When 2FA was first verified
totp_last_used              TIMESTAMP NULL     -- Last time 2FA was used
```

### New Table: `totp_attempts`

```sql
CREATE TABLE totp_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);
```

## API Endpoints

### `/api/totp-setup.php`
- **Method:** POST
- **Auth:** Required (logged-in user)
- **Purpose:** Generate TOTP secret and QR code
- **Returns:** QR code (data URI), secret, backup codes

### `/api/totp-verify.php`
- **Method:** POST
- **Auth:** Required (logged-in user)
- **Purpose:** Verify TOTP code and enable 2FA
- **Params:** `code` (6-digit TOTP code)

### `/api/totp-disable.php`
- **Method:** POST
- **Auth:** Required (logged-in user)
- **Purpose:** Disable 2FA
- **Params:** `password`, `code` (6-digit TOTP code)

### `/api/totp-login-verify.php`
- **Method:** POST
- **Auth:** Not required (during login)
- **Purpose:** Verify TOTP code during login
- **Params:** `code`, `is_backup` (0 or 1)

## Security Best Practices Implemented

1. **No Secret Reuse:** Secrets are never displayed again after initial setup
2. **Backup Codes Hashed:** Like passwords, backup codes are hashed before storage
3. **Rate Limiting:** Prevents brute force attacks on TOTP codes
4. **Audit Trail:** All 2FA events are logged for security monitoring
5. **Time Window:** TOTP verification allows ±30 seconds time drift
6. **CSRF Protection:** All API endpoints validate CSRF tokens
7. **Session Isolation:** 2FA verification uses a separate session variable
8. **Input Validation:** All inputs are sanitized and validated
9. **Error Messages:** Generic error messages to prevent enumeration
10. **Forced Verification:** Must verify TOTP code before 2FA is activated

## Compatible Authenticator Apps

- Google Authenticator (iOS/Android)
- Microsoft Authenticator (iOS/Android)
- Authy (iOS/Android/Desktop)
- 1Password (with TOTP support)
- LastPass Authenticator
- Any RFC 6238 compliant TOTP app

## Migration

To enable 2FA for your installation:

1. Update composer dependencies:
   ```bash
   composer update
   ```

2. Run database migration:
   ```bash
   mysql -u username -p database_name < database/add_totp_2fa.sql
   ```

3. Users can now enable 2FA from their Settings page

## Configuration

No additional configuration required. The system uses:
- App name from `APP_NAME` constant
- Existing encryption system for secrets
- Existing audit logging system

## Testing Checklist

- [ ] Enable 2FA from settings
- [ ] Scan QR code with authenticator app
- [ ] Verify 6-digit code works
- [ ] Download backup codes
- [ ] Logout and login with 2FA
- [ ] Test backup code (only works once)
- [ ] Test rate limiting (too many failed attempts)
- [ ] Disable 2FA with password + code
- [ ] Verify all events are logged in audit_log

## Troubleshooting

### Time Sync Issues
If TOTP codes are consistently rejected, check:
- Server time is synchronized (NTP)
- User's phone time is set to automatic
- Time drift is within ±30 seconds

### Lost Access
If user loses access to authenticator:
- Use backup codes (10 provided during setup)
- Admin can disable 2FA directly in database:
  ```sql
  UPDATE users SET totp_enabled = 0 WHERE user_id = ?;
  ```

### Rate Limiting
If locked out due to failed attempts:
- Wait 15 minutes for automatic unlock
- Admin can clear attempts:
  ```sql
  DELETE FROM totp_attempts WHERE user_id = ? AND success = 0;
  ```

## Security Considerations

1. **Backup Codes:** Users MUST save backup codes securely
2. **Recovery:** No built-in recovery mechanism (by design for security)
3. **Admin Override:** Admins can disable 2FA in database if needed
4. **Time Sync:** Critical for TOTP to work correctly
5. **HTTPS:** Should always be used in production

## Future Enhancements

- [ ] WebAuthn/FIDO2 support (hardware keys)
- [ ] SMS backup option (not recommended but requested)
- [ ] Trusted device management
- [ ] Recovery codes regeneration
- [ ] Admin panel for 2FA management
- [ ] Email notification on 2FA changes

## Credits

- **Library:** [robthree/twofactorauth](https://github.com/RobThree/TwoFactorAuth)
- **Standard:** RFC 6238 (TOTP)
- **Algorithm:** HMAC-SHA1

---

**Last Updated:** January 5, 2026
**Version:** 1.0.0
