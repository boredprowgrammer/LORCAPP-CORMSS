# Migration to Aiven Cloud MySQL

## Overview
This guide helps you migrate from local MySQL to Aiven Cloud MySQL.

## Connection Details
- **Host:** ppestotomas-duck-f96c.c.aivencloud.com
- **Port:** 13829
- **Database:** defaultdb
- **User:** avnadmin
- **Password:** AVNS_lRD3kgRt3SwMqmjqQ6p
- **SSL Mode:** REQUIRED

## Pre-Migration Steps

### 1. Test Connection
```bash
mysql -h ppestotomas-duck-f96c.c.aivencloud.com \
      -P 13829 \
      -u avnadmin \
      -p'AVNS_lRD3kgRt3SwMqmjqQ6p' \
      --ssl-mode=REQUIRED \
      defaultdb
```

### 2. Backup Local Database
```bash
mysqldump -u root -p church_officers_db > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 3. Check Database Size
```bash
mysql -u root -p -e "SELECT 
    table_schema AS 'Database',
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)'
FROM information_schema.tables 
WHERE table_schema = 'church_officers_db'
GROUP BY table_schema;"
```

## Migration Steps

### 1. Export Schema and Data
```bash
# Export structure
mysqldump -u root -p --no-data church_officers_db > schema.sql

# Export data
mysqldump -u root -p --no-create-info --skip-triggers church_officers_db > data.sql
```

### 2. Import to Aiven Cloud
```bash
# Import schema (with foreign key checks disabled to handle table order)
{
    echo "SET FOREIGN_KEY_CHECKS=0;"
    cat schema.sql
    echo "SET FOREIGN_KEY_CHECKS=1;"
} | mysql -h ppestotomas-duck-f96c.c.aivencloud.com \
           -P 13829 \
           -u avnadmin \
           -p'AVNS_lRD3kgRt3SwMqmjqQ6p' \
           --ssl-mode=REQUIRED \
           defaultdb

# Import data
{
    echo "SET FOREIGN_KEY_CHECKS=0;"
    cat data.sql
    echo "SET FOREIGN_KEY_CHECKS=1;"
} | mysql -h ppestotomas-duck-f96c.c.aivencloud.com \
           -P 13829 \
           -u avnadmin \
           -p'AVNS_lRD3kgRt3SwMqmjqQ6p' \
           --ssl-mode=REQUIRED \
           defaultdb
```

### 3. Verify Migration
```bash
# Connect and check tables
mysql -h ppestotomas-duck-f96c.c.aivencloud.com \
      -P 13829 \
      -u avnadmin \
      -p'AVNS_lRD3kgRt3SwMqmjqQ6p' \
      --ssl-mode=REQUIRED \
      defaultdb

# In MySQL shell:
SHOW TABLES;
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM officers;
```

## Application Configuration

### 1. Set Environment Variables

**Option A: Using .env file (recommended)**
```bash
cp .env.aiven .env
# Edit .env and update ENCRYPTION_KEY and BASE_URL
```

**Option B: Export in shell/systemd**
```bash
export APP_ENV=production
export DB_HOST=ppestotomas-duck-f96c.c.aivencloud.com
export DB_PORT=13829
export DB_NAME=defaultdb
export DB_USER=avnadmin
export DB_PASS=AVNS_lRD3kgRt3SwMqmjqQ6p
export DB_SSL_MODE=REQUIRED
```

### 2. Update PHP-FPM or Apache Environment
```bash
# For PHP-FPM (/etc/php/8.x/fpm/pool.d/www.conf)
env[DB_HOST] = ppestotomas-duck-f96c.c.aivencloud.com
env[DB_PORT] = 13829
env[DB_NAME] = defaultdb
env[DB_USER] = avnadmin
env[DB_PASS] = AVNS_lRD3kgRt3SwMqmjqQ6p
env[DB_SSL_MODE] = REQUIRED

# Restart PHP-FPM
sudo systemctl restart php8.1-fpm
```

### 3. Test Application Connection
```bash
# Create test script
cat > test-db-connection.php << 'EOF'
<?php
putenv('DB_HOST=ppestotomas-duck-f96c.c.aivencloud.com');
putenv('DB_PORT=13829');
putenv('DB_NAME=defaultdb');
putenv('DB_USER=avnadmin');
putenv('DB_PASS=AVNS_lRD3kgRt3SwMqmjqQ6p');
putenv('DB_SSL_MODE=REQUIRED');

require_once 'config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    echo "✓ Database connection successful!\n";
    
    $stmt = $db->query("SELECT DATABASE() as db, VERSION() as version");
    $result = $stmt->fetch();
    echo "✓ Connected to: " . $result['db'] . "\n";
    echo "✓ MySQL Version: " . $result['version'] . "\n";
    
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Tables found: " . count($tables) . "\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
EOF

php test-db-connection.php
```

## Post-Migration Checklist

- [ ] Test login with existing users
- [ ] Verify officer records are visible
- [ ] Test search functionality
- [ ] Check reports generation
- [ ] Test file uploads (if any)
- [ ] Verify audit logs are working
- [ ] Test all CRUD operations
- [ ] Monitor error logs for connection issues

## Rollback Plan

If issues occur:
```bash
# Restore local database
mysql -u root -p church_officers_db < backup_YYYYMMDD_HHMMSS.sql

# Switch back to local environment
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=church_officers_db
export DB_USER=root
export DB_PASS=rootUser123
export DB_SSL_MODE=DISABLED
```

## Security Notes

1. **Change default password** in Aiven Cloud console immediately
2. **Restrict IP access** in Aiven Cloud firewall rules
3. **Use different credentials** for production vs development
4. **Never commit** .env file to git
5. **Rotate passwords** regularly (every 90 days)
6. **Monitor access logs** in Aiven Cloud console

## Troubleshooting

### SSL Connection Issues
If you get SSL errors:
```bash
# Check PHP MySQL SSL support
php -r "echo 'MySQL SSL: ' . (extension_loaded('mysqli') && mysqli_ssl_set(mysqli_init(), null, null, null, null, null) !== false ? 'Supported' : 'Not Supported') . PHP_EOL;"

# Install SSL certificates if needed
sudo apt-get install ca-certificates
```

### Connection Timeout
If connections time out:
- Check firewall rules in Aiven Cloud
- Verify network connectivity: `telnet ppestotomas-duck-f96c.c.aivencloud.com 13829`
- Check PHP timeout settings in php.ini

### Performance Issues
- Enable connection pooling in PHP
- Adjust Aiven Cloud plan for better performance
- Consider enabling query caching
- Monitor slow query logs in Aiven console

## Support

- **Aiven Cloud Console:** https://console.aiven.io/
- **Aiven Documentation:** https://docs.aiven.io/
- **MySQL Documentation:** https://dev.mysql.com/doc/
