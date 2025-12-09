# Church Officers Registry System (CORS)
# Installation and Configuration Guide

## Quick Start

1. **Run the setup script:**
   ```bash
   chmod +x setup.sh
   ./setup.sh
   ```

2. **Configure environment:**
   - Edit `.env` file
   - Update database credentials
   - Set BASE_URL
   - Generate and set MASTER_KEY

3. **Generate encryption key:**
   ```bash
   openssl rand -base64 32
   ```

4. **Access the system:**
   - Navigate to your BASE_URL
   - Login with default credentials
   - Change passwords immediately

## Default Credentials

**Admin:**
- Username: admin
- Password: Admin@123

**District User:**
- Username: district1
- Password: District@123

**Local User:**
- Username: local1
- Password: Local@123

## Important Security Notes

1. Change all default passwords after first login
2. Use a strong MASTER_KEY (generate with openssl)
3. Enable HTTPS in production
4. Set appropriate file permissions
5. Backup encryption keys securely
6. Regularly backup database
7. Review audit logs periodically

## Support

See README.md for full documentation and feature list.
