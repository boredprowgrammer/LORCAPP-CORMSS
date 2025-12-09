# Security Audit System Documentation

## Overview
The Security Audit System provides comprehensive security assessment and encryption process verification for the LORCAPP CORMSS application.

**Date Created:** December 9, 2025  
**Version:** 1.0.0  
**Status:** Production Ready

---

## Components

### 1. Security Audit Tool (`security-audit.php`)
Comprehensive security assessment covering:
- Configuration security
- Encryption implementation
- Authentication & authorization
- Session security
- Database security
- File permissions
- Input validation
- HTTPS/SSL configuration
- Dependency checks
- Logging & monitoring
- CSRF protection
- Password policies
- Key management

### 2. Encryption Process Test (`test-encryption-process.php`)
Validates encryption implementations:
- CORegistry district-based encryption
- LORCAPP application-wide encryption
- Backward compatibility (GCM ↔ CBC)
- Key retrieval mechanisms
- Encryption strength
- Data integrity
- Performance benchmarks

---

## Installation

### Prerequisites
- PHP 7.4+ (PHP 8.0+ recommended)
- OpenSSL extension enabled
- MySQL/MariaDB database
- Admin-level access

### Setup
1. Files are already created in the project root:
   - `security-audit.php`
   - `test-encryption-process.php`

2. No additional installation required - tools use existing application infrastructure

---

## Usage

### Running Security Audit

#### Via Web Browser
```
https://your-domain.com/security-audit.php?run_audit=1
```

**Output Formats:**
- HTML Report: `?run_audit=1` (default)
- JSON Export: `?run_audit=1&format=json`

#### Via Command Line
```bash
cd /home/personal1/CORegistry\ and\ CORTracker
php security-audit.php
```

### Running Encryption Tests

#### Via Web Browser
```
https://your-domain.com/test-encryption-process.php?test=1
```

#### Via Command Line
```bash
php test-encryption-process.php
```

---

## Security Audit Categories

### 1. Configuration Security
**Checks:**
- ✓ Error display disabled in production
- ✓ HTTPS enforcement
- ✓ Session cookie security (HttpOnly, Secure, SameSite)
- ✓ .env file protection
- ✓ Sensitive file web accessibility

**Critical Issues:**
- Display errors enabled in production
- HTTPS not enforced
- .env file accessible via web

### 2. Encryption Security
**Checks:**
- ✓ OpenSSL availability
- ✓ Encryption key configuration
- ✓ District key strength
- ✓ Algorithm usage (GCM vs CBC)
- ✓ Hardcoded key detection
- ✓ Key rotation policy
- ✓ Infisical integration

**Critical Issues:**
- Missing encryption keys
- Weak key length (<32 bytes)
- Hardcoded keys in code

### 3. Authentication & Authorization
**Checks:**
- ✓ Password hashing algorithm (Argon2id preferred)
- ✓ Login attempt limiting
- ✓ Multi-factor authentication availability
- ✓ Default/weak credential detection

**Critical Issues:**
- Weak password hashing (MD5, SHA1)
- No brute force protection
- Default passwords in use

### 4. Session Security
**Checks:**
- ✓ Session timeout configuration
- ✓ Session ID regeneration
- ✓ Session validation (IP, user agent)
- ✓ Session fixation protection

**High Issues:**
- No session timeout
- Session fixation vulnerability

### 5. Database Security
**Checks:**
- ✓ Database connection encryption (SSL/TLS)
- ✓ Prepared statement usage
- ✓ Database user privileges
- ✓ SQL injection vulnerability scan

**High Issues:**
- Unencrypted database connection
- SQL injection vulnerabilities

### 6. File Permissions
**Checks:**
- ✓ Critical file permissions (.env, config files)
- ✓ Upload directory protection
- ✓ World-readable file detection

**High Issues:**
- World-readable sensitive files
- Unprotected upload directory

### 7. Input Validation
**Checks:**
- ✓ Input sanitization functions
- ✓ CSRF protection implementation
- ✓ XSS vulnerability detection
- ✓ Output escaping

**High Issues:**
- Unescaped user input in output
- Missing CSRF tokens

### 8. HTTPS/SSL Configuration
**Checks:**
- ✓ HTTPS enabled
- ✓ HSTS header configuration
- ✓ SSL certificate validity

**Critical Issues:**
- HTTPS not enabled in production
- Missing HSTS header

### 9. Dependencies & Versions
**Checks:**
- ✓ PHP version support status
- ✓ Composer dependency locking
- ✓ Known vulnerability detection

**Critical Issues:**
- Unsupported PHP version (EOL)

### 10. Logging & Monitoring
**Checks:**
- ✓ Audit log table existence
- ✓ Active logging verification
- ✓ Security event logging functions

**Medium Issues:**
- No audit logging
- Inactive logging

---

## Encryption Process Documentation

### CORegistry Encryption (District-Based)

#### Algorithm
- **Cipher:** AES-256-GCM
- **Key Size:** 256 bits (32 bytes)
- **Nonce:** 12 bytes (random per encryption)
- **Authentication Tag:** 16 bytes

#### Encryption Flow
```
1. Input: Plaintext + District Code
2. Retrieve district-specific key (Infisical or DB)
3. Generate random 12-byte nonce
4. Encrypt with AES-256-GCM
5. Generate authentication tag
6. Package: nonce + tag + ciphertext
7. Base64 encode
8. Output: Encrypted string
```

#### Decryption Flow
```
1. Input: Encrypted string + District Code
2. Base64 decode
3. Extract: nonce (12B) + tag (16B) + ciphertext
4. Retrieve district key
5. Decrypt with AES-256-GCM
6. Verify authentication tag
7. Output: Plaintext (or empty if tampered)
```

#### Backward Compatibility
- **Primary:** Attempts GCM decryption first
- **Fallback:** Tries CBC decryption for legacy data
- **Archive Support:** Checks archived keys after rotation

### LORCAPP Encryption (Application-Wide)

#### Algorithm
- **Cipher:** AES-256-GCM
- **Key Source:** LORCAPP_ENCRYPTION_KEY environment variable
- **Key Derivation:** SHA-256 hash (first 32 bytes)
- **Nonce:** 12 bytes (random)
- **Tag:** 16 bytes

#### Usage
```php
// Encrypt
$encrypted = encryptValue($plaintext);

// Decrypt
$plaintext = decryptValue($encrypted);

// Bulk record encryption
$encryptedRecord = encryptRecordNames($data);
$decryptedRecord = decryptRecordNames($encryptedRecord);
```

---

## Security Features

### 1. Authenticated Encryption (AEAD)
- **GCM Mode:** Provides both confidentiality and integrity
- **Tag Verification:** Detects tampering automatically
- **Prevents Attacks:** Padding oracle, bit flipping, truncation

### 2. District Isolation
- Each district has unique encryption key
- Compromise of one key doesn't affect others
- Compliance with data privacy regulations

### 3. Key Rotation Support
- Maintains archived keys for backward compatibility
- Re-encryption possible without data loss
- Automatic fallback to archived keys

### 4. Secure Key Management
- **Primary:** Infisical integration (external vault)
- **Fallback:** Database storage with master key encryption
- **Never:** Hardcoded in source code

### 5. Nonce Randomness
- Cryptographically secure random number generator (CSPRNG)
- Unique nonce per encryption
- Prevents pattern analysis and replay attacks

---

## Security Score Interpretation

### Scoring System
- **Passed Check:** +1 point
- **Low Issue:** -1 point
- **Medium Issue:** -2 points
- **High Issue:** -5 points
- **Critical Issue:** -10 points

### Score Ranges
- **80-100:** Excellent - System is well secured
- **60-79:** Good - Some improvements needed
- **40-59:** Fair - Significant issues to address
- **0-39:** Poor - Critical security issues present

### Production Readiness
- **>= 80:** Ready for production deployment
- **60-79:** Address high/critical issues first
- **< 60:** NOT production ready - fix immediately

---

## Remediation Priority

### Priority 1: CRITICAL (Fix Immediately)
- Display errors enabled in production
- HTTPS not enforced
- Hardcoded credentials
- Missing encryption keys
- Session fixation vulnerability
- .env file web accessible
- Weak password hashing

### Priority 2: HIGH (Fix Before Deployment)
- No HSTS header
- Unencrypted database connection
- SQL injection vulnerabilities
- No login attempt limiting
- World-readable sensitive files
- No CSRF protection

### Priority 3: MEDIUM (Fix Soon After Deployment)
- Weak password requirements
- No session timeout
- Missing input sanitization
- Keys stored in database (vs external vault)
- No audit logging

### Priority 4: LOW (Improvements/Best Practices)
- Password expiration
- MFA not available
- Dependency updates needed
- Enhanced monitoring

---

## Test Results Interpretation

### Encryption Process Tests

#### Expected Results
All tests should pass (✓) for production deployment.

#### Failed Test Actions

**CORegistry Encryption Fails:**
- Check district keys in database
- Verify OpenSSL extension
- Check Infisical connection

**LORCAPP Encryption Fails:**
- Set LORCAPP_ENCRYPTION_KEY environment variable
- Verify key length (>=32 characters)

**Backward Compatibility Fails:**
- May indicate CBC-encrypted legacy data
- Verify fallback logic in Encryption class

**Tamper Detection Fails:**
- CRITICAL: GCM authentication not working
- Check OpenSSL version and GCM support

**Performance Issues:**
- Encryption >10ms: Acceptable but investigate
- Encryption >50ms: Performance problem
- Check server resources and key retrieval speed

---

## API Reference

### SecurityAudit Class

#### Methods

##### `runFullAudit()`
Executes complete security audit across all categories.

**Returns:** Array with results, issues, and security score

**Example:**
```php
$audit = new SecurityAudit();
$results = $audit->runFullAudit();
echo $results['security_score']; // 0-100
```

##### `exportJSON()`
Exports audit results as JSON string.

**Returns:** JSON string

**Example:**
```php
$json = $audit->exportJSON();
file_put_contents('audit-report.json', $json);
```

##### `exportHTML()`
Generates HTML report (used automatically in web interface).

### EncryptionProcessTest Class

#### Methods

##### `runAllTests()`
Executes all encryption tests and displays results.

**Output:** HTML formatted test results

**Example:**
```php
$test = new EncryptionProcessTest();
$test->runAllTests();
```

---

## Automation & Scheduling

### Periodic Audits

#### Daily Security Audit (Cron)
```bash
# Run daily at 2 AM
0 2 * * * cd /path/to/project && php security-audit.php > /var/log/security-audit-$(date +\%Y\%m\%d).log 2>&1
```

#### Weekly Encryption Test
```bash
# Run weekly on Sunday at 3 AM
0 3 * * 0 cd /path/to/project && php test-encryption-process.php > /var/log/encryption-test-$(date +\%Y\%m\%d).log 2>&1
```

### Alerts

#### Email Notifications
Add to audit script:
```php
if ($results['summary']['critical'] > 0) {
    mail('admin@example.com', 
         'CRITICAL: Security Audit Alert', 
         "Critical issues detected: " . $results['summary']['critical']);
}
```

#### Slack Integration
```php
if ($results['security_score'] < 60) {
    // Send Slack webhook
    $payload = json_encode([
        'text' => "Security Score: {$results['security_score']}/100 ⚠️"
    ]);
    // Send to Slack...
}
```

---

## Best Practices

### 1. Regular Audits
- Run security audit before each deployment
- Schedule automated daily audits
- Review audit logs weekly

### 2. Encryption Testing
- Test encryption after key rotation
- Verify backward compatibility
- Monitor performance metrics

### 3. Issue Tracking
- Create tickets for all CRITICAL/HIGH issues
- Assign owners and deadlines
- Verify fixes with re-audit

### 4. Documentation
- Keep audit reports in version control (sanitized)
- Document all remediation actions
- Maintain security changelog

### 5. Continuous Improvement
- Update audit checks for new threats
- Add tests for new features
- Review OWASP Top 10 annually

---

## Troubleshooting

### Common Issues

#### "Display Errors Enabled in Production"
```php
// config/config.php
if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
}
```

#### "Missing Encryption Key"
```bash
# Generate secure key
openssl rand -hex 32

# Add to .env
echo "LORCAPP_ENCRYPTION_KEY=<generated-key>" >> .env
```

#### "Infisical Authentication Failed"
```bash
# Check credentials
php check-infisical-env.php

# Verify Infisical connection
php debug-infisical-auth.php
```

#### "SQL Injection Detected"
Replace:
```php
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];
```
With:
```php
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);
```

---

## Security Compliance

### Standards Covered
- ✓ OWASP Top 10 (2021)
- ✓ NIST Cryptographic Standards
- ✓ PCI DSS (Payment Card Industry)
- ✓ GDPR (Data Privacy)
- ✓ HIPAA (Healthcare Data - if applicable)

### Audit Trail
All audit runs are logged with:
- Timestamp
- Auditor username
- Environment (dev/staging/production)
- Issues found
- Security score

---

## Support & Maintenance

### Updating the Audit System

#### Add New Check
```php
private function auditNewFeature() {
    $category = 'New Feature Security';
    
    // Perform check
    $result = /* your check logic */;
    
    if ($result) {
        $this->addPassed($category, 'Feature is secure');
    } else {
        $this->addHigh($category, 'Issue found', 
                      'Risk description', 
                      'How to fix');
    }
}

// Add to runFullAudit()
public function runFullAudit() {
    // ... existing checks ...
    $this->auditNewFeature();
}
```

### Version History
- **1.0.0** (2025-12-09): Initial release
  - Complete security audit system
  - Encryption process testing
  - HTML and JSON reporting

---

## Contact & Resources

### Internal Documentation
- `SECURITY_AUDIT_REPORT.md` - Previous audit findings
- `ENCRYPTION_SECURITY_ANALYSIS.md` - Encryption deep dive
- `INFISICAL_INTEGRATION.md` - Key management setup

### External Resources
- [OWASP Security Testing Guide](https://owasp.org/www-project-web-security-testing-guide/)
- [NIST Cryptographic Standards](https://csrc.nist.gov/)
- [PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)

---

## License & Attribution
Part of LORCAPP CORMSS Security Framework  
© 2025 All Rights Reserved

**Note:** This is a security-critical system. Only authorized administrators should have access to audit tools and reports.
