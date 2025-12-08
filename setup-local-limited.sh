#!/bin/bash
# Quick Setup Script for Local (Limited) Role
# Run this script to apply all database changes

echo "================================================"
echo "Local (Limited) Role - Database Setup"
echo "================================================"
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get database credentials
echo "Please enter your MySQL database credentials:"
read -p "Database host [localhost]: " DB_HOST
DB_HOST=${DB_HOST:-localhost}

read -p "Database name: " DB_NAME
read -p "Database user: " DB_USER
read -sp "Database password: " DB_PASS
echo ""
echo ""

# Check if database files exist
if [ ! -f "database/add_local_limited_role.sql" ]; then
    echo -e "${RED}Error: database/add_local_limited_role.sql not found${NC}"
    exit 1
fi

if [ ! -f "database/pending_actions.sql" ]; then
    echo -e "${RED}Error: database/pending_actions.sql not found${NC}"
    exit 1
fi

echo -e "${YELLOW}Starting database migrations...${NC}"
echo ""

# Step 1: Add local_limited role
echo "Step 1/2: Adding local_limited role to users table..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/add_local_limited_role.sql

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Successfully added local_limited role${NC}"
else
    echo -e "${RED}✗ Failed to add local_limited role${NC}"
    exit 1
fi
echo ""

# Step 2: Create pending_actions table
echo "Step 2/2: Creating pending_actions table..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/pending_actions.sql

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Successfully created pending_actions table${NC}"
else
    echo -e "${RED}✗ Failed to create pending_actions table${NC}"
    exit 1
fi
echo ""

# Verify changes
echo "Verifying database changes..."
VERIFY_QUERY="SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='users' AND COLUMN_NAME='senior_approver_id';"
RESULT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -s -N -e "$VERIFY_QUERY")

if [ -n "$RESULT" ]; then
    echo -e "${GREEN}✓ senior_approver_id column exists${NC}"
else
    echo -e "${RED}✗ senior_approver_id column not found${NC}"
fi

VERIFY_TABLE="SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='pending_actions';"
RESULT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -s -N -e "$VERIFY_TABLE")

if [ -n "$RESULT" ]; then
    echo -e "${GREEN}✓ pending_actions table exists${NC}"
else
    echo -e "${RED}✗ pending_actions table not found${NC}"
fi

echo ""
echo "================================================"
echo -e "${GREEN}Database setup complete!${NC}"
echo "================================================"
echo ""
echo "Next steps:"
echo "1. Test creating a local (limited) user from the admin panel"
echo "2. Assign a senior approver to the limited user"
echo "3. Log in as the limited user and perform an action"
echo "4. Log in as the senior approver to review pending actions"
echo ""
echo "For more information, see LOCAL_LIMITED_IMPLEMENTATION.md"
echo ""
