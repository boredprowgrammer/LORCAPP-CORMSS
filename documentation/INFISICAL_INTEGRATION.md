# Infisical Integration Guide

## Overview
LORCAPP CORMSS now supports **Infisical** for secure encryption key management, replacing database storage of sensitive encryption keys.

## Why Infisical?

### ✅ Benefits
- **Centralized Secret Management**: All keys in one secure location
- **Access Control**: Fine-grained permissions per environment
- **Audit Trail**: Track all key access and changes
- **Key Rotation**: Easy key rotation without code changes
- **Encryption at Rest**: Keys encrypted in Infisical's infrastructure
- **No Database Exposure**: Keys not stored in backups
- **Compliance**: Meets SOC 2, ISO 27001 standards

---

## Setup Instructions

### 1. Create Infisical Account

1. Go to https://app.infisical.com
2. Click **"Sign up"** or **"Get Started"**
3. Sign up with:
   - Email and password, OR
   - GitHub account (recommended for team collaboration)
4. Verify your email address

### 2. Create New Project

1. After login, click **"+ New Project"** button
2. Fill in project details:
   - **Project Name**: `LORCAPP-CORMSS`
   - **Project Type**: Select **"Secrets Management"** (the default/most common option)
   - **Description**: "Church Officers Registry Management System - Encryption Keys"
3. Click **"Create Project"**

**Why "Secrets Management" project type?**
- Default and most flexible option for application secrets
- Perfect for storing encryption keys, API keys, database credentials
- Works with any application type (PHP, Node.js, Python, etc.)
- Supports custom folder structures and environments
- Includes all features needed for key management

### 3. Create Environments

Infisical projects have environments by default. Configure them:

1. In your project, go to **"Settings"** → **"Environments"**
2. You'll see default environments:
   - `development` (dev)
   - `staging` (stg)  
   - `production` (prod)
3. Optionally add more environments:
   - Click **"Add Environment"**
   - Name: `testing` or `qa`
   - Slug: `test`

**Best Practice**: Use separate environments for each deployment stage.

### 4. Create Folder Structure for Secrets

1. Go to **"Secrets"** tab
2. Select **"production"** environment
3. Create folder structure:
   - Click **"Add Folder"** button
   - Folder name: `/encryption-keys`
   - Description: "District and master encryption keys"
4. Repeat for `development` and `staging` environments

Your structure should look like:
```
production/
├── / (root)
│   ├── MASTER_KEY
│   └── CHAT_MASTER_KEY
└── /encryption-keys
    ├── DISTRICT_KEY_D001
    ├── DISTRICT_KEY_D002
    └── DISTRICT_KEY_D003
```

### 5. Add Initial Secrets

#### Option A: Via Dashboard (Manual)

1. Navigate to **production** → **/encryption-keys**
2. Click **"Add Secret"** button
3. Fill in:
   - **Key**: `DISTRICT_KEY_D001`
   - **Value**: `your-base64-encoded-key-here`
   - **Type**: Secret (not comment)
4. Click **"Save"**
5. Repeat for other district keys

#### Option B: Via Import (Bulk)

1. Click **"Import Secrets"** in top right
2. Choose format: **"Raw (KEY=VALUE)"**
3. Paste your secrets:
```env
DISTRICT_KEY_D001=bXktc2VjcmV0LWtleS0xMjM0NTY3ODk=
DISTRICT_KEY_D002=YW5vdGhlci1zZWNyZXQta2V5LTEyMzQ1Njc4OQ==
DISTRICT_KEY_D003=dGhpcmQtc2VjcmV0LWtleS0xMjM0NTY3ODk=
```
4. Click **"Import"**

### 6. Create Machine Identity (Service Account)

**Important**: This is how your application authenticates with Infisical.

#### Step-by-Step:

1. Open your **LORCAPP-CORMSS** project in Infisical
2. Click **"Project Settings"** (gear icon) in the left sidebar
3. Click **"Machine Identities"** in the settings menu
4. Click **"+ Create Identity"** button (top right)
5. Fill in the identity details:
   - **Name**: `LORCAPP-Production-Server`
   - **Role**: Select **"Developer"** (or create a custom role)
6. Click **"Create"**

7. After creation, click on the identity to configure **Project Roles**:
   - In the "Project Roles" section, ensure it has:
     - Role: **Developer** (or **Custom** with specific permissions)
     - Duration: **Permanent**
   
8. Scroll down to **"Authentication"** section
9. Click **"+ Add Auth Method"**
10. Select **"Universal Auth"** (recommended for servers)
11. Configure Universal Auth:
    - **Access Token TTL**: `7200` (2 hours)
    - **Access Token Max TTL**: `86400` (24 hours)
    - **Access Token Trusted IPs**: Leave empty or add your server IPs
12. Click **"Add"**

13. **IMPORTANT**: Copy and save these credentials immediately:
   - **Client ID**: Displayed on the page (starts with Machine Identity ID)
   - **Client Secret**: Shown only once after adding auth method
   
   ⚠️ **You won't see the Client Secret again!**

14. Store these in your password manager or environment variables

### 7. Create Separate Identities for Each Environment (Recommended)

Repeat step 6 for each environment:

| Identity Name | Environments | Read Access | Write Access |
|--------------|--------------|-------------|--------------|
| LORCAPP-Production | ✓ production | All paths | /encryption-keys |
| LORCAPP-Staging | ✓ staging | All paths | /encryption-keys |
| LORCAPP-Development | ✓ development | All paths | All paths |

**Security Tip**: Development identity can have more permissions for testing.

### 8. Configure Environment Variables

#### For Render.com:

1. Go to your Render dashboard
2. Select your web service: **lorcapp-cormss**
3. Go to **"Environment"** tab
4. Click **"Add Environment Variable"**
5. Add each variable:

#### For Render.com:

1. Go to your Render dashboard
2. Select your web service: **lorcapp-cormss**
3. Go to **"Environment"** tab
4. Click **"Add Environment Variable"**
5. Add each variable:

```env
# Get Project ID from Infisical URL
# Example: https://app.infisical.com/project/64abc123def456789/secrets
# Project ID: 64abc123def456789

INFISICAL_HOST=https://app.infisical.com
INFISICAL_CLIENT_ID=inf_client_xxxxxxxxxxxxxxxx
INFISICAL_CLIENT_SECRET=inf_secret_xxxxxxxxxxxxxxxxxxxxxxxxxx
INFISICAL_PROJECT_ID=64abc123def456789
INFISICAL_ENVIRONMENT=production
```

6. Click **"Save Changes"**
7. Render will redeploy automatically

#### For Local Development (.env file):

```bash
# .env
INFISICAL_HOST=https://app.infisical.com
INFISICAL_CLIENT_ID=inf_client_dev_xxxxxxxxxxxxxxxx
INFISICAL_CLIENT_SECRET=inf_secret_dev_xxxxxxxxxxxxxxxxxx
INFISICAL_PROJECT_ID=64abc123def456789
INFISICAL_ENVIRONMENT=development
```

### 9. Test Connection

Create a test file to verify setup:

```php
<?php
// test-infisical.php
require_once 'config/config.php';

echo "Testing Infisical Connection...\n\n";

try {
    // Test authentication
    echo "1. Testing authentication...\n";
    $testSecret = InfisicalKeyManager::getSecret('MASTER_KEY', '/');
    echo "   ✓ Authentication successful!\n";
    echo "   Master key retrieved: " . substr($testSecret, 0, 10) . "...\n\n";
    
    // Test district key
    echo "2. Testing district key retrieval...\n";
    $districtKey = InfisicalKeyManager::getDistrictKey('D001');
    echo "   ✓ District key retrieved!\n";
    echo "   Key: " . substr($districtKey, 0, 10) . "...\n\n";
    
    echo "✅ All tests passed! Infisical is configured correctly.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "- Check INFISICAL_CLIENT_ID is correct\n";
    echo "- Check INFISICAL_CLIENT_SECRET is correct\n";
    echo "- Verify project ID matches your Infisical project\n";
    echo "- Ensure machine identity has read permissions\n";
}
```

Run: `php test-infisical.php`

### 10. Migrate Existing Keys to Infisical

Run the migration script:

```php
<?php
// migrate-keys-to-infisical.php
require_once 'config/config.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT district_code, encryption_key FROM districts WHERE encryption_key IS NOT NULL");

while ($row = $stmt->fetch()) {
    try {
        InfisicalKeyManager::storeDistrictKeyInInfisical(
            $row['district_code'],
            $row['encryption_key']
        );
        echo "✓ Migrated key for district: {$row['district_code']}\n";
    } catch (Exception $e) {
        echo "✗ Failed for {$row['district_code']}: {$e->getMessage()}\n";
    }
}

echo "\nMigration complete!\n";
```

Run it:
```bash
php migrate-keys-to-infisical.php
```

---

## How It Works

### Key Retrieval Flow

```
1. Application requests district key
   ↓
2. Check Infisical configuration
   ↓
3. If configured → Fetch from Infisical (with caching)
   ↓
4. If not configured → Fallback to database
   ↓
5. Return key to encryption function
```

### Caching

- Keys cached in memory for 1 hour
- Reduces API calls to Infisical
- Automatic token refresh

### Fallback Mechanism

- If Infisical is unavailable: Falls back to database
- If Infisical not configured: Uses database by default
- Zero downtime during transition

---

## Folder Structure in Infisical

```
LORCAPP-CORMSS (Project)
└── production (Environment)
    └── /encryption-keys (Path)
        ├── DISTRICT_KEY_D001
        ├── DISTRICT_KEY_D002
        ├── DISTRICT_KEY_D003
        └── MASTER_KEY
```

---

## API Usage Examples

### Get Secret

```php
// Get a specific district key
$key = InfisicalKeyManager::getSecret('DISTRICT_KEY_D001', '/encryption-keys');

// Get master key
$masterKey = InfisicalKeyManager::getSecret('MASTER_KEY', '/');
```

### Store Secret

```php
// Store new district key
InfisicalKeyManager::storeDistrictKeyInInfisical('D001', 'base64-encoded-key-here');
```

### Integration with Encryption Class

```php
// Automatic - no code changes needed
$encrypted = Encryption::encrypt($data, 'D001');
$decrypted = Encryption::decrypt($encrypted, 'D001');
```

---

## Environment-Specific Configuration

### Development

```env
INFISICAL_ENVIRONMENT=development
INFISICAL_PROJECT_ID=dev-project-id
```

### Staging

```env
INFISICAL_ENVIRONMENT=staging
INFISICAL_PROJECT_ID=staging-project-id
```

### Production

```env
INFISICAL_ENVIRONMENT=production
INFISICAL_PROJECT_ID=prod-project-id
```

---

## Security Best Practices

### ✅ DO:
- Use separate Infisical projects for dev/staging/prod
- Rotate machine identity secrets regularly
- Enable audit logging in Infisical
- Use least-privilege access (read-only where possible)
- Monitor Infisical access logs

### ❌ DON'T:
- Commit Infisical credentials to Git
- Share machine identities across environments
- Store Infisical secrets in application logs
- Use same keys across environments

---

## Monitoring & Troubleshooting

### Check Infisical Connection

```php
<?php
require_once 'config/config.php';

try {
    $key = InfisicalKeyManager::getSecret('TEST_SECRET', '/');
    echo "✓ Infisical connection successful\n";
    echo "Test secret value: $key\n";
} catch (Exception $e) {
    echo "✗ Infisical connection failed: " . $e->getMessage() . "\n";
}
```

### Common Issues

#### 1. Authentication Failed

```
Error: Failed to authenticate with Infisical
```

**Solution**: Check `INFISICAL_CLIENT_ID` and `INFISICAL_CLIENT_SECRET`

#### 2. Secret Not Found

```
Error: Secret not found: DISTRICT_KEY_D001
```

**Solution**: 
- Verify secret exists in Infisical dashboard
- Check path: `/encryption-keys`
- Verify environment matches

#### 3. Permission Denied

```
Error: Failed to fetch secret
```

**Solution**: 
- Check machine identity has read permissions
- Verify path permissions in Infisical

---

## Key Rotation Strategy

### Automated Rotation (Recommended)

```php
<?php
// rotate-district-keys.php
require_once 'config/config.php';

function rotateDistrictKey($districtCode) {
    // Generate new key
    $newKey = base64_encode(random_bytes(32));
    
    // Store in Infisical with version
    InfisicalKeyManager::storeDistrictKeyInInfisical(
        "{$districtCode}_v2",
        $newKey
    );
    
    // Re-encrypt all district data with new key
    // (implementation depends on your data structure)
    
    echo "✓ Rotated key for district: $districtCode\n";
}

// Rotate all district keys
$districts = ['D001', 'D002', 'D003'];
foreach ($districts as $district) {
    rotateDistrictKey($district);
}
```

### Manual Rotation

1. Generate new key in Infisical dashboard
2. Update secret with new value
3. Application automatically uses new key
4. Re-encrypt existing data (optional, for forward secrecy)

---

## Cost

### Infisical Cloud

- **Free Tier**: 5 users, unlimited secrets
- **Pro**: $18/month - Unlimited users, audit logs
- **Enterprise**: Custom pricing - SSO, SLA, compliance

### Self-Hosted (Free)

```bash
# Docker deployment
docker run -d \
  --name infisical \
  -p 80:80 \
  infisical/infisical:latest
```

---

## Migration Checklist

- [ ] Create Infisical account and project
- [ ] Generate machine identity credentials
- [ ] Add environment variables to Render
- [ ] Test connection with debug script
- [ ] Migrate existing keys from database
- [ ] Verify encryption/decryption works
- [ ] Monitor logs for 24 hours
- [ ] Remove keys from database (optional)
- [ ] Document for team

---

## Backup & Disaster Recovery

### Infisical Cloud

- Automatic backups every 6 hours
- 30-day retention
- Point-in-time recovery

### Self-Hosted

```bash
# Backup Infisical data
docker exec infisical /app/backup.sh

# Restore from backup
docker exec infisical /app/restore.sh backup-file.tar.gz
```

### Emergency Fallback

If Infisical is completely unavailable:

1. System automatically falls back to database keys
2. No downtime
3. Fix Infisical connection
4. Application resumes normal operation

---

## Support & Resources

- **Infisical Docs**: https://infisical.com/docs
- **SDK Reference**: https://infisical.com/docs/sdks/overview
- **Community**: https://infisical.com/slack
- **Status Page**: https://status.infisical.com

---

## Summary

✅ **Infisical integration provides**:
- Enterprise-grade secret management
- Zero-trust security model
- Compliance-ready infrastructure
- Easy key rotation
- Comprehensive audit trails

✅ **Your app remains secure even if**:
- Database is compromised
- Backups are stolen
- DBA access is misused

**Recommendation**: Enable Infisical for production immediately!
