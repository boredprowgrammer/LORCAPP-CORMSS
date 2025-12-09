# Security Audit & Encryption Process - Quick Start Guide

## 🚀 What's Been Created

Three comprehensive security tools have been created for your LORCAPP CORMSS system:

### 1. **`security-audit.php`** - Complete Security Assessment Tool
   - 14 security audit categories
   - Automated vulnerability detection
   - HTML & JSON reporting
   - Security scoring (0-100)
   - Production readiness verification

### 2. **`test-encryption-process.php`** - Encryption Validation Tool
   - Tests CORegistry district-based encryption
   - Tests LORCAPP application-wide encryption
   - Verifies backward compatibility (GCM ↔ CBC)
   - Performance benchmarking
   - Data integrity verification

### 3. **`SECURITY_AUDIT_SYSTEM.md`** - Complete Documentation
   - Detailed usage instructions
   - Security best practices
   - Troubleshooting guide
   - API reference

---

## 🎯 Quick Start

### Run Security Audit (Web Browser)
```
https://your-domain.com/security-audit.php?run_audit=1
```

### Run Security Audit (Command Line)
```bash
cd "/home/personal1/CORegistry and CORTracker"
php security-audit.php
```

### Run Encryption Tests
```bash
php test-encryption-process.php
```

### Export JSON Report
```
https://your-domain.com/security-audit.php?run_audit=1&format=json
```

---

## 📊 What Gets Audited

### Critical Security Checks ✅
- ✓ Production error display (should be OFF)
- ✓ HTTPS enforcement
- ✓ Encryption key configuration
- ✓ Session security (HttpOnly, Secure, SameSite)
- ✓ Password hashing (Argon2id recommended)
- ✓ SQL injection prevention
- ✓ CSRF protection
- ✓ File permissions
- ✓ Input validation
- ✓ Key management (Infisical integration)

### Encryption Process Verification ✅
- ✓ AES-256-GCM implementation
- ✓ District key strength (32 bytes)
- ✓ Nonce randomness
- ✓ Authentication tag verification
- ✓ Tamper detection
- ✓ Backward compatibility with CBC
- ✓ Performance benchmarks
- ✓ Key rotation support

---

## 🎨 Report Features

### Visual Security Score
- **80-100:** 🟢 Excellent (Production Ready)
- **60-79:** 🔵 Good (Minor fixes needed)
- **40-59:** 🟡 Fair (Significant issues)
- **0-39:** 🔴 Poor (NOT production ready)

### Issue Priority Levels
- 🔴 **CRITICAL:** Must fix immediately
- 🟠 **HIGH:** Fix before deployment
- 🟡 **MEDIUM:** Fix soon after deployment
- 🔵 **LOW:** Improvements/best practices

### Detailed Information
Each issue includes:
- **Category:** Which security area
- **Issue:** What's wrong
- **Risk:** Why it's dangerous
- **Remediation:** How to fix it

---

## 🔒 Encryption Process Overview

### CORegistry (District-Based)
```
Plaintext → District Key (32B) → Nonce (12B) → AES-256-GCM
→ Tag (16B) → Package → Base64 → Store
```

**Security Features:**
- Unique key per district (isolation)
- GCM authenticated encryption (tamper-proof)
- Backward compatible with CBC
- Infisical key management support

### LORCAPP (Application-Wide)
```
Plaintext → App Key → Nonce (12B) → AES-256-GCM
→ Tag (16B) → Package → Base64 → Store
```

**Security Features:**
- AES-256-GCM encryption
- Environment variable key storage
- Automatic legacy CBC decryption
- Record-level encryption support

---

## 📋 Common Issues & Quick Fixes

### Issue: Display Errors Enabled in Production
**Fix:**
```php
// config/config.php
if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
}
```

### Issue: Missing Encryption Key
**Fix:**
```bash
# Generate key
openssl rand -hex 32

# Add to .env
echo "LORCAPP_ENCRYPTION_KEY=<key-here>" >> .env
```

### Issue: HTTPS Not Enforced
**Fix:**
```apache
# .htaccess
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### Issue: Weak Session Security
**Fix:**
```php
// config/config.php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
```

---

## 🔄 Automation Setup

### Daily Security Audit (Cron)
```bash
# Add to crontab
0 2 * * * cd /home/personal1/CORegistry\ and\ CORTracker && php security-audit.php > /var/log/security-audit-$(date +\%Y\%m\%d).log 2>&1
```

### Weekly Encryption Test
```bash
# Add to crontab
0 3 * * 0 cd /home/personal1/CORegistry\ and\ CORTracker && php test-encryption-process.php > /var/log/encryption-test-$(date +\%Y\%m\%d).log 2>&1
```

---

## 📈 Integration with CI/CD

### Pre-Deployment Check
```bash
#!/bin/bash
# deploy-check.sh

cd /path/to/project
php security-audit.php > audit-report.txt

# Extract security score
SCORE=$(grep -oP 'security_score":\s*\K[0-9.]+' audit-report.txt)

if (( $(echo "$SCORE < 60" | bc -l) )); then
    echo "❌ Security audit failed: Score $SCORE/100"
    exit 1
fi

echo "✅ Security audit passed: Score $SCORE/100"
exit 0
```

---

## 🎓 Understanding the Results

### Security Score Calculation
```
Score = (Passed × 1) - (Critical × 10) - (High × 5) - (Medium × 2) - (Low × 1)
Percentage = (Score / Max Possible) × 100
```

### Example Interpretation

**Score: 85/100**
- Passed: 40 checks ✓
- Low: 2 issues
- Medium: 1 issue
- High: 0 issues
- Critical: 0 issues

**Verdict:** ✅ Production ready with minor improvements recommended

**Score: 45/100**
- Passed: 20 checks ✓
- Low: 5 issues
- Medium: 8 issues
- High: 3 issues
- Critical: 1 issue

**Verdict:** ⚠️ NOT production ready - fix critical and high issues first

---

## 🧪 Testing Encryption

### Manual Test
```php
<?php
require_once 'includes/encryption.php';

// Test CORegistry encryption
$plaintext = "Juan Dela Cruz";
$districtCode = "DIST001";

$encrypted = Encryption::encrypt($plaintext, $districtCode);
echo "Encrypted: $encrypted\n";

$decrypted = Encryption::decrypt($encrypted, $districtCode);
echo "Decrypted: $decrypted\n";

// Verify
if ($decrypted === $plaintext) {
    echo "✅ Encryption working correctly\n";
} else {
    echo "❌ Encryption failed\n";
}
?>
```

### Expected Output
```
Encrypted: aB3dE7fG9hJ2kL5mN8pQ1rS4tV6wX9yZ0cF3gH6jK9mP2sT5vX8zB1eG4hK7nQ0tW3yB6eH9k=
Decrypted: Juan Dela Cruz
✅ Encryption working correctly
```

---

## 📚 Documentation Files

All documentation is located in the project root:

1. **`SECURITY_AUDIT_SYSTEM.md`** - Complete documentation (this guide)
2. **`SECURITY_AUDIT_REPORT.md`** - Previous audit findings
3. **`ENCRYPTION_SECURITY_ANALYSIS.md`** - Encryption deep dive
4. **`INFISICAL_INTEGRATION.md`** - Key management setup

---

## 🚨 Emergency Response

### Critical Security Issue Detected

1. **Immediately:**
   - Run security audit: `php security-audit.php`
   - Review critical issues section
   - Block web access if needed

2. **Within 1 Hour:**
   - Fix all CRITICAL issues
   - Re-run audit to verify
   - Check for unauthorized access in logs

3. **Within 24 Hours:**
   - Fix HIGH priority issues
   - Rotate encryption keys if compromised
   - Update passwords if needed
   - Audit logs for suspicious activity

4. **Document:**
   - What was compromised
   - How it was fixed
   - Prevention measures added

---

## 💡 Pro Tips

### Tip 1: Regular Audits
Run security audit before EVERY deployment:
```bash
php security-audit.php && git push
```

### Tip 2: Monitor Trends
Save audit reports and track security score over time:
```bash
php security-audit.php > reports/audit-$(date +%Y%m%d).txt
```

### Tip 3: Automate Alerts
Set up email/Slack notifications for scores below 60:
```php
if ($results['security_score'] < 60) {
    mail('admin@example.com', 'Security Alert', $message);
}
```

### Tip 4: Test After Changes
Always run encryption tests after:
- Key rotation
- Code changes to encryption modules
- Database migrations
- Server updates

### Tip 5: Keep Backups
Backup encryption keys securely:
```bash
# Already done - check these files:
ls -la backup_keys_*.txt
```

---

## 🔗 Quick Links

### Run Audit Tools
- Security Audit: `security-audit.php?run_audit=1`
- Encryption Test: `test-encryption-process.php?test=1`
- JSON Export: `security-audit.php?run_audit=1&format=json`

### Check System Status
- District Keys: `check-district-keys.php`
- Infisical Auth: `debug-infisical-auth.php`
- Environment: `check-infisical-env.php`

---

## ✅ Pre-Deployment Checklist

Before deploying to production:

- [ ] Run `php security-audit.php`
- [ ] Security score >= 80
- [ ] Zero CRITICAL issues
- [ ] Zero HIGH issues (or documented exceptions)
- [ ] Run `php test-encryption-process.php`
- [ ] All encryption tests pass
- [ ] HTTPS enforced
- [ ] Display errors disabled
- [ ] Encryption keys configured
- [ ] Session security enabled
- [ ] CSRF protection active
- [ ] Backup created
- [ ] Keys backed up securely

---

## 📞 Need Help?

### Internal Resources
- Documentation: `/SECURITY_AUDIT_SYSTEM.md`
- Previous Reports: `/SECURITY_AUDIT_REPORT.md`
- Encryption Docs: `/ENCRYPTION_SECURITY_ANALYSIS.md`

### External Resources
- OWASP Top 10: https://owasp.org/www-project-top-ten/
- PHP Security: https://www.php.net/manual/en/security.php
- AES-GCM: https://csrc.nist.gov/publications/detail/sp/800-38d/final

---

## 🎉 Summary

You now have:
- ✅ Comprehensive security audit system
- ✅ Encryption process testing
- ✅ Automated vulnerability detection
- ✅ Production readiness verification
- ✅ Complete documentation
- ✅ Quick start guides
- ✅ Troubleshooting resources

**Next Step:** Run your first audit!
```bash
php security-audit.php
```

---

**Created:** December 9, 2025  
**Version:** 1.0.0  
**Status:** Ready to Use ✅
