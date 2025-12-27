#!/bin/bash

# R5-13 Seminar Tracking System - Setup Verification Script
# This script verifies that all components are properly installed

echo "=========================================="
echo "R5-13 Seminar Tracking - Setup Verification"
echo "=========================================="
echo ""

ERRORS=0

# Check database migration
echo "✓ Checking database migration..."
if [ -f "database/add_request_class_and_seminar_tracking.sql" ]; then
    echo "  ✓ Migration SQL file exists"
else
    echo "  ✗ Migration SQL file missing!"
    ERRORS=$((ERRORS + 1))
fi

# Check template file
echo ""
echo "✓ Checking template file..."
if [ -f "R5-13_template.docx" ]; then
    echo "  ✓ R5-13_template.docx exists"
else
    echo "  ✗ R5-13_template.docx missing!"
    ERRORS=$((ERRORS + 1))
fi

# Check generator script
echo ""
echo "✓ Checking generator script..."
if [ -f "generate-r513.php" ]; then
    echo "  ✓ generate-r513.php exists"
else
    echo "  ✗ generate-r513.php missing!"
    ERRORS=$((ERRORS + 1))
fi

# Check API endpoint
echo ""
echo "✓ Checking API endpoint..."
if [ -f "requests/update-seminar.php" ]; then
    echo "  ✓ update-seminar.php exists"
else
    echo "  ✗ update-seminar.php missing!"
    ERRORS=$((ERRORS + 1))
fi

# Check modified frontend files
echo ""
echo "✓ Checking frontend files..."
if grep -q "request_class" "requests/add.php"; then
    echo "  ✓ requests/add.php updated with seminar class selection"
else
    echo "  ✗ requests/add.php not updated!"
    ERRORS=$((ERRORS + 1))
fi

if grep -q "Seminar Progress" "requests/view.php"; then
    echo "  ✓ requests/view.php updated with seminar tracking"
else
    echo "  ✗ requests/view.php not updated!"
    ERRORS=$((ERRORS + 1))
fi

# Check documentation
echo ""
echo "✓ Checking documentation..."
if [ -f "documentation/R5-13_SEMINAR_TRACKING.md" ]; then
    echo "  ✓ Comprehensive documentation exists"
else
    echo "  ✗ Documentation missing!"
    ERRORS=$((ERRORS + 1))
fi

# Summary
echo ""
echo "=========================================="
if [ $ERRORS -eq 0 ]; then
    echo "✓ ALL CHECKS PASSED!"
    echo "=========================================="
    echo ""
    echo "System is ready to use. Next steps:"
    echo "1. Ensure database migration has been run"
    echo "2. Test creating a new request with R5-15 or R5-04"
    echo "3. Add seminar dates and track progress"
    echo "4. Generate R5-13 certificate when complete"
    echo ""
    exit 0
else
    echo "✗ FOUND $ERRORS ERROR(S)"
    echo "=========================================="
    echo ""
    echo "Please fix the errors above before using the system."
    echo ""
    exit 1
fi
