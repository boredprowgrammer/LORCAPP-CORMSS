# Docker Deployment Guide for LORCAPP CORMSS

## Prerequisites
- Docker Engine 20.10 or higher
- Docker Compose 2.0 or higher

## Quick Start

### 1. Clone the Repository
```bash
git clone https://github.com/boredprowgrammer/LORCAPP-CORMSS.git
cd LORCAPP-CORMSS
```

### 2. Configure Environment
```bash
# Copy the docker environment template
cp .env.docker .env

# Edit the .env file with your configuration
nano .env
```

**IMPORTANT:** Change these values:
- `MASTER_KEY` - Generate a secure random 32-character string
- `SESSION_SECRET` - Generate a secure random string
- `DB_PASSWORD` - Set a strong database password
- `DB_ROOT_PASSWORD` - Set a strong root password

### 3. Build and Start Containers
```bash
# Build and start all services
docker-compose up -d

# Check if containers are running
docker-compose ps
```

### 4. Access the Application
- **Web Application:** http://localhost:8080
- **phpMyAdmin:** http://localhost:8081

### Default Login Credentials
- **Admin:** 
  - Username: `admin`
  - Password: `Admin@123`
  
⚠️ **Change all default passwords immediately after first login!**

## Docker Commands

### Start Services
```bash
docker-compose up -d
```

### Stop Services
```bash
docker-compose down
```

### View Logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f web
docker-compose logs -f db
```

### Restart Services
```bash
docker-compose restart
```

### Rebuild Containers
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Access Container Shell
```bash
# Web container
docker-compose exec web bash

# Database container
docker-compose exec db bash
```

## Database Management

### Import SQL Files
```bash
# Copy SQL file to container
docker cp database/your-file.sql lorcapp-cormss-db:/tmp/

# Import into database
docker-compose exec db mysql -u root -p church_officers_db < /tmp/your-file.sql
```

### Backup Database
```bash
docker-compose exec db mysqldump -u root -p church_officers_db > backup-$(date +%Y%m%d).sql
```

### Restore Database
```bash
docker-compose exec -T db mysql -u root -p church_officers_db < backup-20240101.sql
```

## Volumes

The following directories are mounted as volumes:
- `./logs` - Application logs
- `./lorcapp/logs` - LORCAPP logs
- `./uploads` - Uploaded files
- `./temp` - Temporary files
- `./cache` - Cache files
- `mysql-data` - MySQL database files (Docker volume)

## Port Configuration

Default ports (can be changed in `docker-compose.yml`):
- **8080** - Web application
- **3306** - MySQL database
- **8081** - phpMyAdmin

### Change Ports
Edit `docker-compose.yml` and modify the `ports` section:
```yaml
services:
  web:
    ports:
      - "YOUR_PORT:80"  # Change YOUR_PORT to desired port
```

## Production Deployment

### 1. Use Production Environment
```bash
# Set production mode in .env
APP_ENV=production
APP_DEBUG=false
```

### 2. Enable HTTPS
Add SSL certificates and configure Apache for HTTPS:
```bash
# Mount certificates in docker-compose.yml
volumes:
  - ./ssl:/etc/apache2/ssl
```

### 3. Security Hardening
- Change all default passwords
- Generate secure encryption keys
- Disable phpMyAdmin in production
- Use environment-specific `.env` files
- Enable firewall rules
- Regular security updates

### 4. Resource Limits
Add resource limits in `docker-compose.yml`:
```yaml
services:
  web:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
```

## Troubleshooting

### Container won't start
```bash
# Check logs
docker-compose logs web

# Check if port is already in use
sudo netstat -tulpn | grep :8080
```

### Database connection error
```bash
# Verify database is running
docker-compose ps

# Check database logs
docker-compose logs db

# Test connection
docker-compose exec web php -r "new PDO('mysql:host=db;dbname=church_officers_db', 'church_user', 'church_pass');"
```

### Permission issues
```bash
# Fix file permissions
docker-compose exec web chown -R www-data:www-data /var/www/html
docker-compose exec web chmod -R 755 /var/www/html
```

### Reset Everything
```bash
# Stop and remove all containers, volumes, and images
docker-compose down -v
docker-compose build --no-cache
docker-compose up -d
```

## Monitoring

### Health Check
```bash
# Check container health
docker-compose ps

# Manual health check
curl http://localhost:8080
```

### Resource Usage
```bash
# View resource usage
docker stats
```

## Updates

### Pull Latest Changes
```bash
git pull origin main
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

## Support

For issues or questions, please open an issue on GitHub:
https://github.com/boredprowgrammer/LORCAPP-CORMSS/issues
