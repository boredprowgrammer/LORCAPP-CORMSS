#!/bin/bash

# CORegistry & CORTracker - Deployment Setup Script
# This script helps set up the application for production deployment

set -e  # Exit on error

echo "=========================================="
echo "CORegistry & CORTracker Deployment Setup"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if .env exists
if [ -f .env ]; then
    echo -e "${YELLOW}Warning: .env file already exists.${NC}"
    read -p "Do you want to overwrite it? (y/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Keeping existing .env file."
    else
        rm .env
    fi
fi

# Create .env from example if it doesn't exist
if [ ! -f .env ]; then
    echo "Creating .env file from template..."
    cp .env.example .env
    echo -e "${GREEN}✓ .env file created${NC}"
fi

# Generate encryption keys
echo ""
echo "Generating encryption keys..."
MASTER_KEY=$(openssl rand -base64 32)
ENCRYPTION_KEY=$(openssl rand -base64 32)

echo -e "${GREEN}✓ Keys generated${NC}"
echo ""
echo "MASTER_KEY: $MASTER_KEY"
echo "ENCRYPTION_KEY: $ENCRYPTION_KEY"
echo ""

# Update .env file
read -p "Do you want to update .env with these keys? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    # Use sed to replace the keys in .env
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        sed -i '' "s|^MASTER_KEY=.*|MASTER_KEY=$MASTER_KEY|" .env
        sed -i '' "s|^ENCRYPTION_KEY=.*|ENCRYPTION_KEY=$ENCRYPTION_KEY|" .env
    else
        # Linux
        sed -i "s|^MASTER_KEY=.*|MASTER_KEY=$MASTER_KEY|" .env
        sed -i "s|^ENCRYPTION_KEY=.*|ENCRYPTION_KEY=$ENCRYPTION_KEY|" .env
    fi
    echo -e "${GREEN}✓ Keys added to .env${NC}"
fi

# Prompt for environment
echo ""
read -p "Is this a production deployment? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s|^APP_ENV=.*|APP_ENV=production|" .env
    else
        sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    fi
    echo -e "${GREEN}✓ Set APP_ENV=production${NC}"
else
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s|^APP_ENV=.*|APP_ENV=development|" .env
    else
        sed -i "s|^APP_ENV=.*|APP_ENV=development|" .env
    fi
    echo -e "${GREEN}✓ Set APP_ENV=development${NC}"
fi

# Prompt for database details
echo ""
echo "Database Configuration"
echo "======================"
read -p "Database Host [localhost]: " db_host
db_host=${db_host:-localhost}

read -p "Database Name [church_officers_db]: " db_name
db_name=${db_name:-church_officers_db}

read -p "Database User [root]: " db_user
db_user=${db_user:-root}

read -sp "Database Password: " db_pass
echo ""

# Update .env with database details
if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "s|^DB_HOST=.*|DB_HOST=$db_host|" .env
    sed -i '' "s|^DB_NAME=.*|DB_NAME=$db_name|" .env
    sed -i '' "s|^DB_USER=.*|DB_USER=$db_user|" .env
    sed -i '' "s|^DB_PASS=.*|DB_PASS=$db_pass|" .env
else
    sed -i "s|^DB_HOST=.*|DB_HOST=$db_host|" .env
    sed -i "s|^DB_NAME=.*|DB_NAME=$db_name|" .env
    sed -i "s|^DB_USER=.*|DB_USER=$db_user|" .env
    sed -i "s|^DB_PASS=.*|DB_PASS=$db_pass|" .env
fi

echo -e "${GREEN}✓ Database configuration updated${NC}"

# Prompt for base URL
echo ""
read -p "Application Base URL [http://localhost]: " base_url
base_url=${base_url:-http://localhost}

if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "s|^BASE_URL=.*|BASE_URL=$base_url|" .env
else
    sed -i "s|^BASE_URL=.*|BASE_URL=$base_url|" .env
fi

echo -e "${GREEN}✓ Base URL updated${NC}"

# Create logs directory
echo ""
echo "Setting up logs directory..."
mkdir -p logs
chmod 755 logs
touch logs/php_errors.log
chmod 644 logs/php_errors.log
echo -e "${GREEN}✓ Logs directory created${NC}"

# Create login_attempts table
echo ""
read -p "Do you want to create the login_attempts table? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "Creating login_attempts table..."
    mysql -h "$db_host" -u "$db_user" -p"$db_pass" "$db_name" < database/login_attempts.sql 2>/dev/null
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ login_attempts table created${NC}"
    else
        echo -e "${RED}✗ Failed to create table. Please run manually:${NC}"
        echo "  mysql -h $db_host -u $db_user -p $db_name < database/login_attempts.sql"
    fi
fi

# Set file permissions
echo ""
read -p "Do you want to set file permissions? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "Setting file permissions..."
    find . -type f -name "*.php" -exec chmod 644 {} \;
    find . -type d -exec chmod 755 {} \;
    chmod 700 config/ includes/ database/ 2>/dev/null || true
    echo -e "${GREEN}✓ File permissions set${NC}"
fi

# Security checklist
echo ""
echo "=========================================="
echo "          SECURITY CHECKLIST"
echo "=========================================="
echo ""
echo "✓ .env file created with strong encryption keys"
echo "✓ Database configuration set"
echo "✓ Logs directory created"
echo ""
echo -e "${YELLOW}IMPORTANT: Complete these steps manually:${NC}"
echo ""
echo "1. Ensure .env is in .gitignore (check with: grep '.env' .gitignore)"
echo "2. Enable HTTPS redirect in .htaccess for production"
echo "3. Install SSL/TLS certificate (use Let's Encrypt: certbot)"
echo "4. Change default passwords in the database"
echo "5. Test security headers: curl -I https://yourdomain.com"
echo "6. Run security tests (see DEPLOYMENT_GUIDE.md)"
echo ""
echo -e "${GREEN}Setup complete!${NC}"
echo ""
echo "Next steps:"
echo "1. Review and edit .env file if needed"
echo "2. Follow DEPLOYMENT_GUIDE.md for production deployment"
echo "3. Run security verification tests"
echo ""
