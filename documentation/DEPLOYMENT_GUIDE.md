# Pre-Deployment Security Checklist

## ✅ COMPLETED SECURITY FIXES

The following critical, high, and medium priority security issues have been fixed:

### Critical Issues Fixed ✓
1. ✅ **Debug mode disabled in production** - Added environment-based error handling
2. ✅ **Removed hardcoded credential defaults** - Requires environment variables in production
3. ✅ **Session fixation fixed** - Added `session_regenerate_id()` after login
4. ✅ **Session hijacking protection** - Added IP and User-Agent binding

### High Priority Issues Fixed ✓
5. ✅ **Database-backed rate limiting** - Implemented login_attempts table
6. ✅ **Improved CSRF protection** - Action-specific tokens with expiration
7. ✅ **Content Security Policy added** - CSP headers with nonce support
8. ✅ **Password strength validation** - Added `validatePasswordStrength()` function
9. ✅ **Enhanced file upload validation** - Double extension check, image verification
10. ✅ **HTTPS enforcement** - Added redirect in config (commented, enable in production)
11. ✅ **Enhanced security headers** - Updated .htaccess with complete headers

### Medium Priority Issues Fixed ✓
12. ✅ **Input length validation** - Added maxLength parameter to sanitizeInput()
13. ✅ **Secure logging** - Created secureLog() function to redact sensitive data
14. ✅ **SQL injection in import fixed** - Added validation for lorcapp_ids array
15. ✅ **Default credentials hidden** - Only shown in development mode

---

## 🚀 DEPLOYMENT STEPS

### Step 1: Create Logs Directory
```bash
mkdir -p logs
chmod 755 logs
touch logs/php_errors.log
chmod 644 logs/php_errors.log
```

### Step 2: Create .env File
Create a `.env` file in the project root (never commit to git):

```bash
# Environment
APP_ENV=production

# Database Configuration
DB_HOST=localhost
DB_NAME=church_officers_db
DB_USER=your_db_user
DB_PASS=YOUR_STRONG_DATABASE_PASSWORD_HERE

# Application URL
BASE_URL=https://yourdomain.com

# Encryption Keys (Generate with: openssl rand -base64 32)
MASTER_KEY=YOUR_GENERATED_32_CHAR_KEY_HERE
ENCRYPTION_KEY=YOUR_GENERATED_32_CHAR_KEY_HERE
```

### Step 3: Generate Strong Keys
```bash
# Generate MASTER_KEY
openssl rand -base64 32

# Generate ENCRYPTION_KEY
openssl rand -base64 32
```

Copy these keys to your `.env` file.

### Step 4: Update .gitignore
Ensure `.env` is ignored:
```bash
echo ".env" >> .gitignore
echo "logs/*.log" >> .gitignore
git add .gitignore
git commit -m "Add .env and logs to .gitignore"
```

### Step 5: Run Database Migrations
```bash
# Create login_attempts table
mysql -u your_user -p your_database < database/login_attempts.sql

# Verify table was created
mysql -u your_user -p your_database -e "SHOW TABLES LIKE 'login_attempts';"
```

### Step 6: Enable HTTPS in .htaccess
Uncomment these lines in `.htaccess`:
```apache
# Force HTTPS (uncomment for production)
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

And this line:
```apache
# Strict Transport Security (uncomment for production with HTTPS)
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
```

### Step 7: Configure SSL/TLS Certificate
If using Let's Encrypt:
```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com
```

### Step 8: Update Session Cookie Settings
The code already sets secure cookies, but verify in `config/config.php`:
```php
ini_set('session.cookie_secure', 1); // Already set
```

### Step 9: Change Default Passwords
**IMPORTANT**: After first deployment, immediately change all default passwords in the database:
- Admin user
- District users
- Local users

Use strong passwords that meet the new requirements:
- At least 12 characters
- Contains uppercase, lowercase, numbers, and special characters

### Step 10: Set Proper File Permissions
```bash
# Make files readable but not writable by web server
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# Make sensitive directories not readable
chmod 700 config/
chmod 700 includes/
chmod 700 database/

# Logs should be writable
chmod 755 logs/
chmod 644 logs/*.log
```

### Step 11: Test Security Headers
After deployment, test your security headers:
```bash
curl -I https://yourdomain.com
```

Verify these headers are present:
- `Strict-Transport-Security`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `X-XSS-Protection: 1; mode=block`
- `Content-Security-Policy`

### Step 12: Test HTTPS Redirect
```bash
curl -I http://yourdomain.com
```

Should return a 301 redirect to HTTPS.

### Step 13: Clean Old Login Attempts (Optional Cron Job)
Add to crontab to clean old login attempts daily:
```bash
crontab -e
```

Add:
```
0 2 * * * mysql -u your_user -pyour_pass your_database -e "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);"
```

---

## 🔒 SECURITY VERIFICATION CHECKLIST

Before going live, verify:

- [ ] `.env` file created with strong keys (not in git)
- [ ] `APP_ENV=production` in .env
- [ ] All environment variables set (no defaults used)
- [ ] HTTPS enabled and working
- [ ] SSL certificate valid (not self-signed)
- [ ] `display_errors=0` (check phpinfo)
- [ ] Error logs going to `/logs/php_errors.log`
- [ ] login_attempts table created
- [ ] Default credentials changed in database
- [ ] Default credentials not visible on login page
- [ ] Security headers present (test with curl)
- [ ] HTTPS redirect working
- [ ] Session cookies are secure (check browser dev tools)
- [ ] File permissions set correctly
- [ ] `.htaccess` protecting sensitive directories
- [ ] Test failed login rate limiting (try 5+ failed attempts)
- [ ] Test CSRF protection (try form submission without token)
- [ ] Test file upload (try uploading .php file - should be rejected)

---

## 🧪 SECURITY TESTING

### Test 1: Rate Limiting
```bash
# Try 6 failed logins within 1 minute
# Should get "Account temporarily locked" message
```

### Test 2: Session Hijacking Protection
```bash
# Login, copy session cookie
# Change User-Agent or IP
# Try to access protected page
# Should be logged out
```

### Test 3: CSRF Protection
```bash
# Try submitting form without CSRF token
# Should get "Invalid security token" error
```

### Test 4: File Upload Security
```bash
# Try uploading file named: test.php.jpg
# Should be rejected
# Try uploading actual .php file
# Should be rejected
```

### Test 5: XSS Protection
```bash
# Try entering: <script>alert('xss')</script>
# Should be escaped and displayed as text
```

---

## 📊 MONITORING RECOMMENDATIONS

### 1. Log Monitoring
Monitor these logs regularly:
- `/logs/php_errors.log` - Application errors
- Apache/Nginx error logs - Server errors
- `login_attempts` table - Failed login patterns

### 2. Set Up Alerts
Configure alerts for:
- Multiple failed login attempts from same IP
- Database connection failures
- Encryption errors
- File upload failures

### 3. Regular Security Audits
- Weekly: Review login_attempts patterns
- Monthly: Update dependencies
- Quarterly: Full security audit
- As needed: Apply security patches

---

## 🔄 MAINTENANCE TASKS

### Daily
- Monitor error logs
- Check for unusual login patterns

### Weekly
- Clean old login_attempts records
- Review audit logs
- Check disk space for logs

### Monthly
- Update PHP and dependencies
- Review and rotate encryption keys if needed
- Test backup restoration
- Security scan with OWASP ZAP or similar

### Quarterly
- Full penetration testing
- Code security audit
- Password expiration reminders
- Review user permissions

---

## 🆘 INCIDENT RESPONSE

If you detect a security breach:

1. **Immediate Actions**
   - Take site offline temporarily
   - Change all passwords and keys
   - Review all log files
   - Identify attack vector

2. **Investigation**
   - Check login_attempts for patterns
   - Review audit_log for unauthorized actions
   - Check file integrity
   - Analyze traffic logs

3. **Recovery**
   - Patch vulnerability
   - Restore from clean backup if needed
   - Regenerate all encryption keys
   - Force password reset for all users
   - Document incident

4. **Prevention**
   - Implement additional controls
   - Update monitoring
   - Staff training if needed

---

## 📞 SUPPORT

For security questions or to report vulnerabilities:
- Email: security@yourdomain.com
- Create security issue (private): GitHub Security Advisories

---

## 📚 ADDITIONAL RESOURCES

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)
- [SSL Labs Test](https://www.ssllabs.com/ssltest/)
- [Security Headers Test](https://securityheaders.com/)

---

**Last Updated:** December 3, 2025  
**Security Fixes Applied:** All Critical, High, and Medium Priority Issues  
**Status:** ✅ Ready for Production Deployment
