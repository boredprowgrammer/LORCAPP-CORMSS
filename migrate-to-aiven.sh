#!/bin/bash

# Aiven Cloud MySQL Migration Script
# This script automates the migration process

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
LOCAL_DB="church_officers_db"
LOCAL_USER="root"
AIVEN_HOST="ppestotomas-duck-f96c.c.aivencloud.com"
AIVEN_PORT="13829"
AIVEN_DB="church_officers_db"
AIVEN_USER="avnadmin"
AIVEN_PASS="AVNS_lRD3kgRt3SwMqmjqQ6p"
SCHEMA_FILE="database/church_officers_db.sql"

echo -e "${GREEN}=== Aiven Cloud MySQL Migration ===${NC}\n"

# Function to print step
print_step() {
    echo -e "${YELLOW}[$1]${NC} $2"
}

# Function to check command exists
check_command() {
    if ! command -v $1 &> /dev/null; then
        echo -e "${RED}Error: $1 is not installed${NC}"
        exit 1
    fi
}

# Check prerequisites
print_step "1" "Checking prerequisites..."
check_command mysql
check_command mysqldump

# Check if schema file exists
if [ ! -f "$SCHEMA_FILE" ]; then
    echo -e "${RED}Error: Schema file not found: $SCHEMA_FILE${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Prerequisites met${NC}\n"

# Test Aiven connection
print_step "2" "Testing Aiven Cloud connection..."
if mysql -h "$AIVEN_HOST" -P "$AIVEN_PORT" -u "$AIVEN_USER" -p"$AIVEN_PASS" --ssl-mode=REQUIRED -e "SELECT 1;" 2>/dev/null; then
    echo -e "${GREEN}✓ Aiven connection successful${NC}\n"
else
    echo -e "${RED}✗ Failed to connect to Aiven Cloud${NC}"
    echo "Please check your network and credentials"
    exit 1
fi

# Confirm before importing
echo -e "${YELLOW}WARNING: This will import the complete database schema and data to Aiven Cloud${NC}"
echo "Source file: $SCHEMA_FILE"
read -p "Continue with import? (yes/no): " CONFIRM
if [ "$CONFIRM" != "yes" ]; then
    echo "Migration cancelled"
    exit 0
fi

# Import complete database to Aiven
print_step "3" "Importing database to Aiven Cloud..."
{
    echo "SET SESSION sql_require_primary_key = 0;"
    cat "$SCHEMA_FILE"
} | mysql -h "$AIVEN_HOST" -P "$AIVEN_PORT" -u "$AIVEN_USER" -p"$AIVEN_PASS" --ssl-mode=REQUIRED "$AIVEN_DB"
echo -e "${GREEN}✓ Database imported${NC}\n"

# Verify migration
print_step "4" "Verifying migration..."
REMOTE_TABLES=$(mysql -h "$AIVEN_HOST" -P "$AIVEN_PORT" -u "$AIVEN_USER" -p"$AIVEN_PASS" --ssl-mode=REQUIRED "$AIVEN_DB" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$AIVEN_DB';")
echo "Tables in Aiven Cloud: $REMOTE_TABLES"

REMOTE_USERS=$(mysql -h "$AIVEN_HOST" -P "$AIVEN_PORT" -u "$AIVEN_USER" -p"$AIVEN_PASS" --ssl-mode=REQUIRED "$AIVEN_DB" -N -e "SELECT COUNT(*) FROM users;" 2>/dev/null || echo "0")
echo "Users in Aiven Cloud: $REMOTE_USERS"

if [ "$REMOTE_TABLES" -gt "0" ]; then
    echo -e "${GREEN}✓ Migration verified${NC}\n"
else
    echo -e "${RED}✗ Migration verification failed${NC}"
    exit 1
fi

# Summary
echo -e "${GREEN}=== Migration Complete ===${NC}"
echo
echo "Summary:"
echo "  - Source file: $SCHEMA_FILE"
echo "  - Tables migrated: $REMOTE_TABLES"
echo "  - Users migrated: $REMOTE_USERS"
echo
echo "Next steps:"
echo "  1. Update .env file with your BASE_URL"
echo "  2. Test application: php test-db-connection.php"
echo "  3. Configure web server environment variables"
echo "  4. Test login and all features"
echo
