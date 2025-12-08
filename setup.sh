#!/bin/bash

# Church Officers Registry System - Installation Script
# This script helps set up the system on a new server

echo "=========================================="
echo "Church Officers Registry System Setup"
echo "=========================================="
echo ""

# Check PHP version
echo "Checking PHP version..."
php_version=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
echo "PHP version: $php_version"

if (( $(echo "$php_version < 7.4" | bc -l) )); then
    echo "ERROR: PHP 7.4 or higher is required"
    exit 1
fi

echo "✓ PHP version OK"
echo ""

# Check required PHP extensions
echo "Checking PHP extensions..."
required_extensions=("pdo" "pdo_mysql" "openssl" "mbstring" "json")

for ext in "${required_extensions[@]}"; do
    if php -m | grep -q "^$ext$"; then
        echo "✓ $ext extension found"
    else
        echo "✗ ERROR: $ext extension not found"
        exit 1
    fi
done

echo ""

# Create .env file from example
if [ ! -f .env ]; then
    echo "Creating .env file from .env.example..."
    cp .env.example .env
    echo "✓ .env file created"
    echo "⚠️  IMPORTANT: Edit .env file and update the configuration!"
else
    echo "✓ .env file already exists"
fi

echo ""

# Set permissions
echo "Setting directory permissions..."
chmod 755 config/
chmod 644 config/*.php
chmod 755 includes/
chmod 644 includes/*.php
echo "✓ Permissions set"

echo ""

# Database setup
echo "=========================================="
echo "Database Setup"
echo "=========================================="
echo ""
read -p "Do you want to set up the database now? (y/n): " setup_db

if [ "$setup_db" = "y" ]; then
    read -p "MySQL host [localhost]: " db_host
    db_host=${db_host:-localhost}
    
    read -p "MySQL username [root]: " db_user
    db_user=${db_user:-root}
    
    read -sp "MySQL password: " db_pass
    echo ""
    
    read -p "Database name [church_officers_db]: " db_name
    db_name=${db_name:-church_officers_db}
    
    echo ""
    echo "Creating database and importing schema..."
    
    # Create database
    mysql -h "$db_host" -u "$db_user" -p"$db_pass" -e "CREATE DATABASE IF NOT EXISTS $db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    
    # Import schema
    mysql -h "$db_host" -u "$db_user" -p"$db_pass" "$db_name" < database/schema.sql
    
    if [ $? -eq 0 ]; then
        echo "✓ Database setup complete"
        echo ""
        echo "Default credentials:"
        echo "  Admin: admin / Admin@123"
        echo "  District: district1 / District@123"
        echo "  Local: local1 / Local@123"
        echo ""
        echo "⚠️  IMPORTANT: Change these passwords immediately after first login!"
    else
        echo "✗ Database setup failed"
        exit 1
    fi
fi

echo ""
echo "=========================================="
echo "Setup Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Edit .env file with your configuration"
echo "2. Generate a secure MASTER_KEY:"
echo "   openssl rand -base64 32"
echo "3. Update BASE_URL in .env"
echo "4. Access the system through your web browser"
echo "5. Login and change default passwords"
echo ""
echo "For more information, see README.md"
echo ""
