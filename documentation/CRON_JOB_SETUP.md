# Setting Up Automated Key Rotation with cron-job.org

## 🌐 Why cron-job.org?

Since Render.com free tier doesn't support cron jobs, we use **cron-job.org** (free external service) to trigger key rotation via HTTP webhook.

## 📋 Setup Steps

### Step 1: Generate a Secret Token

Generate a secure random token for authentication:

```bash
# On Linux/Mac
openssl rand -hex 32

# Or use this
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

**Save this token!** You'll need it in Step 2 and Step 3.

Example token: `a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2`

---

### Step 2: Add Environment Variable to Render

1. Go to: https://dashboard.render.com
2. Select your service: **lorcapp-cormss**
3. Go to **"Environment"** tab
4. Click **"Add Environment Variable"**
5. Add:
   ```
   Key: CRON_SECRET_TOKEN
   Value: a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2
   ```
6. Click **"Save Changes"**
7. Render will redeploy (takes ~2-3 minutes)

---

### Step 3: Create Account on cron-job.org

1. Go to: https://cron-job.org/en/
2. Click **"Sign up"** (top right)
3. Create account:
   - Email: your-email@example.com
   - Password: (choose strong password)
4. Verify your email
5. Log in

---

### Step 4: Create Cron Job

#### A. Click "Create Cronjob"

1. After login, click **"Create cronjob"** button
2. You'll see a form with multiple sections

#### B. Fill in Basic Settings

**Section 1: General**
```
Title: LORCAPP Key Rotation (Every 90 Days)
Address: https://your-app-name.onrender.com/cron-rotate-keys.php?token=YOUR_SECRET_TOKEN
```

⚠️ **Replace:**
- `your-app-name` with your actual Render app name
- `YOUR_SECRET_TOKEN` with the token from Step 1

**Example:**
```
https://lorcapp-cormss.onrender.com/cron-rotate-keys.php?token=a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2
```

#### C. Configure Schedule

**Section 2: Schedule**

Choose **"Every 90 days"** or use custom:

**Option 1: Every 90 Days (Recommended)**
```
Pattern: Custom
Days: */90
Time: 02:00 (2 AM)
Timezone: Your timezone (e.g., Asia/Manila)
```

**Option 2: Quarterly (Every 3 months)**
```
Pattern: Custom
Months: */3 (Every 3 months)
Day of month: 1 (First day)
Time: 02:00
Timezone: Your timezone
```

**Option 3: First Sunday Every 3 Months**
```
Pattern: Custom
Months: */3
Weekday: Sunday
Time: 02:00
Timezone: Your timezone
```

#### D. Advanced Settings (Optional but Recommended)

**Section 3: Execution**
```
☑ Save responses
☑ Send me an email on execution failure
☐ Notify on success (optional, can be noisy)

Request method: GET
Request timeout: 120 seconds
```

**Section 4: Notifications**
```
☑ Notify me on failure
Email: your-email@example.com

Failure threshold: 1 (notify immediately)
```

#### E. Save the Cron Job

1. Click **"Create cronjob"** at the bottom
2. You should see your new cron job in the dashboard

---

### Step 5: Test the Cron Job Immediately

#### Option A: Via cron-job.org Dashboard

1. Find your cron job in the list
2. Click the **"▶ Run now"** button (play icon)
3. Wait for execution (~30-60 seconds)
4. Click **"Execution history"** to see results

#### Option B: Via Browser (Manual Test)

1. Open your browser
2. Go to: `https://your-app.onrender.com/cron-rotate-keys.php?token=YOUR_SECRET_TOKEN`
3. You should see JSON response:
   ```json
   {
     "status": "success",
     "message": "Keys are current, no rotation needed",
     "days_until_next": 90,
     "output": "..."
   }
   ```

#### Option C: Via Command Line

```bash
curl "https://your-app.onrender.com/cron-rotate-keys.php?token=YOUR_SECRET_TOKEN"
```

---

### Step 6: Verify It Works

After running the test:

1. **Check the response:**
   - Status should be `"success"`
   - Message shows rotation status
   - Output shows detailed log

2. **Check Render logs:**
   ```
   Render Dashboard → Your Service → Logs
   ```
   Look for entries from `cron-rotate-keys.php`

3. **Check rotation log file:**
   The endpoint creates: `logs/cron-rotation.log`

---

## 📊 Monitoring & Maintenance

### Check Execution History

1. Go to: https://cron-job.org/en/members/jobs/
2. Click on your cron job
3. Click **"Execution history"**
4. You'll see:
   - ✅ Successful executions (green)
   - ❌ Failed executions (red)
   - Response code and time

### View Rotation Logs

SSH into your server or check Render logs:
```bash
cat logs/cron-rotation.log
```

### Email Notifications

- ✅ Success: (optional) You'll get email when rotation completes
- ❌ Failure: You'll get email if something goes wrong

---

## 🔒 Security Features

### 1. Token Authentication
- ✅ Prevents unauthorized rotation
- ✅ Token required in URL query string
- ✅ Returns 401 if token is invalid

### 2. HTTPS Only
- ✅ All communication encrypted
- ✅ Token not exposed in plain text

### 3. IP Logging
- ✅ Logs IP address of each request
- ✅ Audit trail in `logs/cron-rotation.log`

---

## 🚨 Troubleshooting

### Issue: "Unauthorized: Invalid or missing token"

**Solution:**
```
1. Check token in URL matches CRON_SECRET_TOKEN in Render
2. Ensure token has no spaces or special characters
3. URL encode token if it contains special chars
```

### Issue: "Connection timeout"

**Solution:**
```
1. Increase timeout in cron-job.org (Advanced → Request timeout: 120s)
2. Check if Render app is sleeping (free tier sleeps after 15 min)
3. Add a "ping" job before rotation to wake up app
```

### Issue: "500 Internal Server Error"

**Solution:**
```
1. Check Render logs for PHP errors
2. Verify Infisical credentials are set
3. Test manually: curl https://your-app.onrender.com/cron-rotate-keys.php?token=TOKEN
```

### Issue: Render App Sleeping (Free Tier)

**Problem:** Render free tier sleeps after 15 minutes of inactivity.

**Solution:** Create 2 cron jobs:

**Job 1: Wake Up (runs 5 min before rotation)**
```
Title: LORCAPP Wake Up
URL: https://your-app.onrender.com/
Schedule: Every 90 days at 01:55
```

**Job 2: Rotate Keys**
```
Title: LORCAPP Key Rotation
URL: https://your-app.onrender.com/cron-rotate-keys.php?token=TOKEN
Schedule: Every 90 days at 02:00
```

---

## 📋 Quick Setup Checklist

- [ ] Generate secret token (`openssl rand -hex 32`)
- [ ] Add `CRON_SECRET_TOKEN` to Render environment variables
- [ ] Wait for Render to redeploy (~2-3 min)
- [ ] Create cron-job.org account
- [ ] Create cron job with correct URL and token
- [ ] Set schedule: Every 90 days at 2 AM
- [ ] Enable email notifications on failure
- [ ] Test with "Run now" button
- [ ] Verify response shows success
- [ ] Check Render logs
- [ ] Document token in secure location

---

## 🎯 Example Configuration

### Your Webhook URL
```
https://lorcapp-cormss.onrender.com/cron-rotate-keys.php?token=YOUR_SECRET_HERE
```

### Schedule Examples

**Every 90 days (Recommended):**
```
Pattern: Days */90
Time: 02:00
Timezone: Asia/Manila
```

**Quarterly (Every 3 months):**
```
Pattern: Months */3, Day 1
Time: 02:00
Timezone: Asia/Manila
```

**First Sunday of every quarter:**
```
Pattern: Months 1,4,7,10, Weekday Sunday, Week 1
Time: 02:00
Timezone: Asia/Manila
```

---

## 📧 Email Notification Example

When rotation completes, you'll receive:

```
Subject: Cronjob "LORCAPP Key Rotation" executed

Status: Success
HTTP Code: 200
Execution time: 12.5 seconds
Next execution: 2026-03-09 02:00:00

Response:
{
  "status": "success",
  "message": "Key rotation completed successfully",
  "districts_rotated": 1,
  "districts_failed": 0,
  "next_rotation": "2026-06-07"
}
```

---

## 🔄 Manual Rotation (If Needed)

If you need to rotate keys manually before the scheduled time:

**Option 1: Via cron-job.org**
```
Click "▶ Run now" button in dashboard
```

**Option 2: Via Browser**
```
Visit: https://your-app.onrender.com/cron-rotate-keys.php?token=YOUR_TOKEN
```

**Option 3: Via SSH/Terminal**
```bash
php rotate-keys-90days.php --force
```

---

## 💡 Pro Tips

1. **Test First**: Always click "Run now" after creating the cron job
2. **Save Token**: Store your secret token in a password manager
3. **Monitor Email**: Keep email notifications enabled
4. **Check Logs**: Review `logs/cron-rotation.log` periodically
5. **Backup Schedule**: Take database backups before rotation
6. **Wake Up App**: Create a wake-up job 5 min before rotation (if on free tier)

---

## 📞 Support

- **cron-job.org Help**: https://cron-job.org/en/faq/
- **Render Support**: https://render.com/docs
- **Check Logs**: Render Dashboard → Your Service → Logs

---

## ✅ You're Done!

Your key rotation is now automated! Keys will rotate every 90 days without any manual intervention. 🎉
