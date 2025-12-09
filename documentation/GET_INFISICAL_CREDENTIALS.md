# Get Infisical Universal Auth Credentials

## Current Setup
- **Machine Identity ID**: `402b06b0-6319-493b-a9c6-3e64c261d129`
- **Machine Identity Name**: `LORCAPP-Production-Server`
- **Project ID**: `b8c20aec-b0d5-4aaf-832c-3a07b899c233`

## Steps to Get Client ID and Client Secret

### 1. Open Your Machine Identity

1. Go to your Infisical project: https://app.infisical.com/project/b8c20aec-b0d5-4aaf-832c-3a07b899c233
2. Click **"Project Settings"** (gear icon) in the left sidebar
3. Click **"Machine Identities"**
4. Click on **"LORCAPP-Production-Server"** (ID: 402b06b0-6319-493b-a9c6-3e64c261d129)

### 2. Check Authentication Section

You should see an **"Authentication"** section at the bottom of the page.

#### Option A: If "Universal Auth" already exists

1. You'll see "Universal Auth" with a gear icon
2. Click the **gear icon** (⚙️) to configure
3. Look for the **Client ID** - it should be displayed
4. The **Client Secret** is only shown once when first created
   - If you lost it, you'll need to regenerate it

#### Option B: If no authentication method exists

1. Click **"+ Add Auth Method"** button
2. Select **"Universal Auth"**
3. Configure settings:
   - **Access Token TTL**: `7200` (2 hours)
   - **Access Token Max TTL**: `86400` (24 hours)  
   - **Access Token Trusted IPs**: Leave empty (or add your server IPs)
4. Click **"Add"** or **"Save"**
5. **IMPORTANT**: Copy both credentials immediately:
   - **Client ID**: Something like `ua_xxxxxxxxxxxxxxxxxxxxxx`
   - **Client Secret**: Long string shown only once

### 3. What the Credentials Look Like

✅ **Correct Universal Auth credentials**:
```
Client ID: ua_xxxxxxxxxxxxxxxxxxxxxxxx (starts with "ua_")
Client Secret: (long random string, 64+ characters)
```

❌ **What you provided (incorrect format)**:
```
Client ID: 5b156d17-ceac-49cf-afbb-5c10c21a0f42 (UUID format - this is NOT a Client ID)
Client Secret: 971f2f64f1e4ceea6a1add538c6bda6b1a76bb842ce034ccabc41075959b7850
```

### 4. Update .env File

Once you have the correct credentials:

```bash
# Edit .env file
nano .env

# Update these lines:
INFISICAL_CLIENT_ID=ua_xxxxxxxxxxxxxxxxxxxxxxxx
INFISICAL_CLIENT_SECRET=your-actual-secret-here
```

### 5. Test Connection

```bash
php debug-infisical-auth.php
```

If successful, you should see:
```
✓ Authentication successful!
Access Token: eyJhbGciOiJSUzI1Ni...
```

---

## Troubleshooting

### Error: "Invalid credentials" (401)

This means:
- Client ID is wrong
- Client Secret is wrong  
- Universal Auth is not configured for the machine identity

### How to Regenerate Universal Auth

If you lost the Client Secret:

1. Go to Machine Identity → Authentication section
2. Click gear icon on "Universal Auth"
3. Click **"Regenerate"** or **"Delete"** and recreate
4. Copy the NEW credentials immediately

---

## Quick Checklist

- [ ] Opened machine identity `402b06b0-6319-493b-a9c6-3e64c261d129`
- [ ] Found "Authentication" section at bottom
- [ ] Universal Auth is configured
- [ ] Client ID starts with `ua_`
- [ ] Client Secret is 64+ characters
- [ ] Updated `.env` file with correct credentials
- [ ] Ran `php debug-infisical-auth.php`
- [ ] Got HTTP 200 response

---

## Next Steps After Success

1. ✅ Authentication working
2. Add secrets to Infisical (MASTER_KEY, district keys)
3. Run full test: `php test-infisical-setup.php`
4. Migrate keys: `php migrate-keys-to-infisical.php`
5. Deploy to Render with environment variables
