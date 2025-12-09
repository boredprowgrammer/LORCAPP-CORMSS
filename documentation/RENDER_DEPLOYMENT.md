# Render.com Deployment Guide for LORCAPP CORMSS

## Prerequisites
- GitHub account with LORCAPP-CORMSS repository
- Render.com account (free tier available)
- External MySQL database (Aiven, PlanetScale, or Render PostgreSQL)

## Deployment Steps

### Option 1: Deploy via Render Dashboard (Recommended)

#### 1. Create Render Account
1. Go to https://render.com
2. Sign up with GitHub
3. Authorize Render to access your repositories

#### 2. Create New Web Service
1. Click **"New +"** → **"Web Service"**
2. Connect your GitHub repository: `boredprowgrammer/LORCAPP-CORMSS`
3. Configure the service:

**Basic Settings:**
- **Name:** `lorcapp-cormss`
- **Region:** Choose closest to your users (Oregon, Frankfurt, Singapore)
- **Branch:** `main`
- **Runtime:** `Docker`
- **Dockerfile Path:** `./Dockerfile`

**Instance Type:**
- Free tier: Limited resources, sleeps after inactivity
- Starter ($7/month): Always on, better performance

#### 3. Configure Environment Variables

Add these environment variables in Render dashboard:

```
DB_HOST=your-mysql-host.com
DB_NAME=church_officers_db
DB_USER=your-db-username
DB_PASSWORD=your-db-password
DB_PORT=3306
MASTER_KEY=<generate-32-char-random-string>
SESSION_SECRET=<generate-random-string>
APP_ENV=production
APP_DEBUG=false
TZ=Asia/Manila
BASE_URL=https://your-app-name.onrender.com
```

**To generate secure keys:**
```bash
# Generate MASTER_KEY (32 characters)
openssl rand -base64 32

# Generate SESSION_SECRET
openssl rand -base64 48
```

#### 4. Deploy
1. Click **"Create Web Service"**
2. Render will:
   - Clone your repository
   - Build Docker image
   - Deploy to their infrastructure
3. Wait 5-10 minutes for first deployment

#### 5. Access Your Application
- Your app will be available at: `https://your-app-name.onrender.com`

---

### Option 2: Deploy via render.yaml (Infrastructure as Code)

#### 1. Update render.yaml
The `render.yaml` file is already configured. Just update these values:
```yaml
envVars:
  - key: DB_HOST
    value: your-mysql-host.com
  - key: DB_NAME
    value: church_officers_db
```

#### 2. Deploy from Repository
1. In Render dashboard, click **"New +"** → **"Blueprint"**
2. Connect repository
3. Render will read `render.yaml` and configure automatically

---

## Database Setup

### Option A: Use Aiven MySQL (Recommended - Free Tier)

1. **Create Aiven Account**
   - Go to https://aiven.io
   - Sign up for free tier (includes MySQL)

2. **Create MySQL Service**
   - Choose MySQL 8.0
   - Select free tier region
   - Create service (takes 5-10 minutes)

3. **Get Connection Details**
   - Host, port, username, password
   - Download SSL certificates if required

4. **Import Database**
   ```bash
   mysql -h your-host.aivencloud.com -P 12345 -u avnadmin -p --ssl-mode=REQUIRED church_officers_db < database/schema.sql
   ```

5. **Add to Render Environment Variables**

### Option B: Use Render PostgreSQL (Paid - $7/month minimum)

1. Create PostgreSQL database in Render
2. Convert MySQL schema to PostgreSQL
3. Update PHP code to use PostgreSQL

### Option C: Use PlanetScale (Free Tier Available)

1. Go to https://planetscale.com
2. Create free database
3. Get connection string
4. Add to Render environment variables

---

## Important Configuration

### 1. Update config/config.php

Ensure your config file reads from environment variables:

```php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'church_officers_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost');
define('MASTER_KEY', getenv('MASTER_KEY') ?: 'change-this-key');
```

### 2. SSL/HTTPS
- Render provides **automatic HTTPS** with free SSL certificates
- Your app will be accessible via `https://` automatically

### 3. Custom Domain
1. In Render dashboard → Your service → Settings
2. Click **"Custom Domains"**
3. Add your domain
4. Update DNS records as instructed

---

## Monitoring & Logs

### View Logs
1. Go to your service in Render dashboard
2. Click **"Logs"** tab
3. Real-time logs will appear

### Health Checks
- Render automatically monitors your app
- If health check fails, service will restart
- Configure in Dockerfile's HEALTHCHECK

### Metrics
- View CPU, memory, bandwidth usage
- Available in service dashboard

---

## Troubleshooting

### App Not Starting
```bash
# Check logs in Render dashboard
# Common issues:
# - Missing environment variables
# - Database connection failed
# - PHP errors
```

### Database Connection Error
1. Verify environment variables are correct
2. Check database is accessible from Render's IP
3. Whitelist Render IPs in database firewall (if using Aiven)

### Port Issues
- Render automatically assigns PORT environment variable
- Dockerfile is configured to use dynamic port
- No manual configuration needed

### Performance Issues (Free Tier)
- Free tier spins down after 15 minutes of inactivity
- First request after sleep takes 30-60 seconds
- Upgrade to Starter plan ($7/month) for always-on

---

## Scaling & Performance

### Horizontal Scaling
```yaml
# In render.yaml
autoDeploy: true
numInstances: 3  # Requires paid plan
```

### Persistent Storage
Render's filesystem is ephemeral. Use:
- External object storage (AWS S3, Cloudflare R2)
- Database for file metadata
- CDN for static assets

---

## CI/CD Pipeline

### Auto-Deploy on Git Push
1. In Render dashboard → Settings
2. Enable **"Auto-Deploy"**
3. Choose branch (main)
4. Every push to main automatically deploys

### Manual Deploy
1. Go to service dashboard
2. Click **"Manual Deploy"**
3. Choose branch/commit

---

## Security Checklist

- [x] Change all default passwords
- [x] Set strong MASTER_KEY and SESSION_SECRET
- [x] Enable HTTPS (automatic on Render)
- [x] Set APP_DEBUG=false in production
- [x] Whitelist database access IPs
- [x] Regular security updates
- [x] Monitor logs for suspicious activity

---

## Cost Estimate

### Free Tier
- **Web Service:** Free (with limitations)
- **Database:** Use external free tier (Aiven)
- **Total:** $0/month

### Starter Plan
- **Web Service:** $7/month (750 hours)
- **Database:** Use Aiven free or $10/month
- **Total:** $7-17/month

### Professional
- **Web Service:** $85/month
- **Database:** Render PostgreSQL $20/month
- **Total:** $105/month

---

## Backup Strategy

### Database Backups
```bash
# Manual backup from Render logs
mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME > backup.sql

# Restore
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME < backup.sql
```

### Automated Backups
- Use Aiven's automatic daily backups
- Or set up cron job in Render (paid plans)

---

## Support & Resources

- **Render Documentation:** https://render.com/docs
- **Render Status:** https://status.render.com
- **Community Forum:** https://community.render.com
- **GitHub Issues:** https://github.com/boredprowgrammer/LORCAPP-CORMSS/issues

---

## Quick Deploy Checklist

- [ ] Create Render account
- [ ] Set up MySQL database (Aiven/PlanetScale)
- [ ] Import database schema
- [ ] Create Render web service
- [ ] Configure environment variables
- [ ] Deploy application
- [ ] Test login and functionality
- [ ] Set up custom domain (optional)
- [ ] Enable auto-deploy
- [ ] Configure backups

---

**Your app should be live at:** `https://your-app-name.onrender.com`

🎉 **Deployment Complete!**
