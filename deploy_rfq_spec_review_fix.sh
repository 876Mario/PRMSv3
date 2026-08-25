#!/bin/bash

# Deployment script for RFQ Specification Review Error Fix
# Purpose: Apply the migration to fix trigger and create alias procedures
# Usage: ./deploy_rfq_spec_review_fix.sh

set -e  # Exit on error

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATION_FILE="$SCRIPT_DIR/migrations/2026_08_25_fix_spec_review_trigger_and_procedures.sql"

echo "================================================================"
echo "RFQ Specification Review Error Fix - Deployment Script"
echo "================================================================"
echo ""

# Check if migration file exists
if [ ! -f "$MIGRATION_FILE" ]; then
    echo "❌ Error: Migration file not found at $MIGRATION_FILE"
    exit 1
fi

echo "✓ Migration file found"
echo ""

# Check if .env file exists for database credentials
if [ ! -f "$SCRIPT_DIR/.env" ]; then
    echo "⚠️  Warning: .env file not found"
    echo "Please provide database credentials manually or create .env file"
    echo ""
    
    # Prompt for credentials
    read -p "Database host (default: localhost): " DB_HOST
    DB_HOST=${DB_HOST:-localhost}
    
    read -p "Database name: " DB_NAME
    if [ -z "$DB_NAME" ]; then
        echo "❌ Error: Database name is required"
        exit 1
    fi
    
    read -p "Database user: " DB_USER
    if [ -z "$DB_USER" ]; then
        echo "❌ Error: Database user is required"
        exit 1
    fi
    
    read -sp "Database password: " DB_PASS
    echo ""
    
    if [ -z "$DB_PASS" ]; then
        echo "❌ Error: Database password is required"
        exit 1
    fi
else
    echo "✓ Loading database credentials from .env"
    source "$SCRIPT_DIR/.env"
    
    # Map .env variables to script variables
    DB_HOST="${DB_HOST:-localhost}"
    DB_NAME="${DB_NAME}"
    DB_USER="${DB_USERNAME}"
    DB_PASS="${DB_PASSWORD}"
fi

echo ""
echo "Database connection details:"
echo "  Host: $DB_HOST"
echo "  Database: $DB_NAME"
echo "  User: $DB_USER"
echo ""

# Test database connection
echo "Testing database connection..."
if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" > /dev/null 2>&1; then
    echo "✓ Database connection successful"
else
    echo "❌ Error: Cannot connect to database"
    echo "Please check your credentials and try again"
    exit 1
fi

echo ""
echo "================================================================"
echo "MIGRATION PREVIEW"
echo "================================================================"
echo ""
echo "This migration will:"
echo "  1. Drop and recreate trigger: trg_initialize_spec_review_on_first_quote"
echo "     - Fix column name: spec_review_status → requestor_spec_review_status"
echo ""
echo "  2. Create backward-compatible stored procedures:"
echo "     - sp_approve_rfq_spec_review (alias)"
echo "     - sp_reject_rfq_spec_review (alias)"
echo ""
echo "These changes will fix the following errors:"
echo "  ✓ SQLSTATE[42S22]: Column not found: spec_review_status"
echo "  ✓ #1305 PROCEDURE sp_approve_rfq_spec_review does not exist"
echo "  ✓ #1305 PROCEDURE sp_reject_rfq_spec_review does not exist"
echo ""
echo "================================================================"
echo ""

# Prompt for confirmation
read -p "Do you want to proceed with the migration? (yes/no): " CONFIRM
if [ "$CONFIRM" != "yes" ]; then
    echo "Migration cancelled by user"
    exit 0
fi

echo ""
echo "Applying migration..."

# Apply the migration
if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MIGRATION_FILE"; then
    echo "✓ Migration applied successfully"
else
    echo "❌ Error: Migration failed"
    echo "Please check the error messages above"
    exit 1
fi

echo ""
echo "================================================================"
echo "POST-DEPLOYMENT VERIFICATION"
echo "================================================================"
echo ""

# Verify trigger
echo "Verifying trigger..."
TRIGGER_CHECK=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW CREATE TRIGGER trg_initialize_spec_review_on_first_quote\G" 2>/dev/null | grep -c "requestor_spec_review_status" || echo "0")
if [ "$TRIGGER_CHECK" -gt "0" ]; then
    echo "✓ Trigger uses correct column name: requestor_spec_review_status"
else
    echo "⚠️  Warning: Could not verify trigger column name"
fi

# Verify procedures
echo ""
echo "Verifying stored procedures..."
PROC_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW PROCEDURE STATUS WHERE Name LIKE '%rfq%spec%' OR Name LIKE '%rfq%requestor%'\G" 2>/dev/null | grep -c "Name:" || echo "0")
echo "  Found $PROC_COUNT RFQ-related procedures"

if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW PROCEDURE STATUS WHERE Name = 'sp_approve_rfq_spec_review'\G" 2>/dev/null | grep -q "sp_approve_rfq_spec_review"; then
    echo "  ✓ sp_approve_rfq_spec_review exists"
else
    echo "  ⚠️  sp_approve_rfq_spec_review not found"
fi

if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW PROCEDURE STATUS WHERE Name = 'sp_reject_rfq_spec_review'\G" 2>/dev/null | grep -q "sp_reject_rfq_spec_review"; then
    echo "  ✓ sp_reject_rfq_spec_review exists"
else
    echo "  ⚠️  sp_reject_rfq_spec_review not found"
fi

if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW PROCEDURE STATUS WHERE Name = 'sp_approve_rfq_requestor_review'\G" 2>/dev/null | grep -q "sp_approve_rfq_requestor_review"; then
    echo "  ✓ sp_approve_rfq_requestor_review exists"
else
    echo "  ⚠️  sp_approve_rfq_requestor_review not found"
fi

if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW PROCEDURE STATUS WHERE Name = 'sp_reject_rfq_requestor_review'\G" 2>/dev/null | grep -q "sp_reject_rfq_requestor_review"; then
    echo "  ✓ sp_reject_rfq_requestor_review exists"
else
    echo "  ⚠️  sp_reject_rfq_requestor_review not found"
fi

echo ""
echo "================================================================"
echo "DEPLOYMENT COMPLETE"
echo "================================================================"
echo ""
echo "Next steps:"
echo "  1. Test quote upload functionality"
echo "  2. Test requestor specification review workflow"
echo "  3. Test branch head approval workflow"
echo ""
echo "For detailed testing instructions, see:"
echo "  RFQ_SPEC_REVIEW_ERROR_FIX_COMPLETE.md"
echo ""
echo "================================================================"
