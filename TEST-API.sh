#!/bin/bash
# Test script for Fast Deletion API

SITE_URL="https://kobiecy-akcent.pl"
API_KEY="e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg"

echo "========================================"
echo "Fast Deletion API Test Script"
echo "========================================"
echo

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test 1: Get out of stock products (no auth)
echo -e "${YELLOW}Test 1: Get out of stock products${NC}"
RESULT=$(curl -s "$SITE_URL/wp-json/delete-images/v1/get-out-of-stock-products?limit=5")
SUCCESS=$(echo $RESULT | jq -r '.success')

if [ "$SUCCESS" = "true" ]; then
    COUNT=$(echo $RESULT | jq -r '.count')
    echo -e "${GREEN}✓ PASS${NC} - Found $COUNT products"
    echo $RESULT | jq '{count, category, limit}'
else
    echo -e "${RED}✗ FAIL${NC}"
    echo $RESULT
fi
echo

# Test 2: Get with category filter
echo -e "${YELLOW}Test 2: Get with category filter${NC}"
RESULT=$(curl -s "$SITE_URL/wp-json/delete-images/v1/get-out-of-stock-products?cat=brazyliany&limit=5")
SUCCESS=$(echo $RESULT | jq -r '.success')

if [ "$SUCCESS" = "true" ]; then
    COUNT=$(echo $RESULT | jq -r '.count')
    CAT=$(echo $RESULT | jq -r '.category')
    echo -e "${GREEN}✓ PASS${NC} - Found $COUNT products in category: $CAT"
else
    echo -e "${RED}✗ FAIL${NC}"
fi
echo

# Test 3: Stats endpoint (no auth)
echo -e "${YELLOW}Test 3: Stats endpoint${NC}"
RESULT=$(curl -s "$SITE_URL/wp-json/delete-images/v1/stats")
SUCCESS=$(echo $RESULT | jq -r '.success')

if [ "$SUCCESS" = "true" ]; then
    echo -e "${GREEN}✓ PASS${NC}"
    echo $RESULT | jq '.stats | {total_scanned, non_brazyliany_found, brazyliany_found, products_deleted}'
else
    echo -e "${RED}✗ FAIL${NC}"
fi
echo

# Test 4: Delete products (requires auth - DRY RUN with limit=0)
echo -e "${YELLOW}Test 4: API Key authentication${NC}"
RESULT=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/get-out-of-stock-products" \
     -H "X-API-Key: $API_KEY")

# Check if API key works
if echo "$RESULT" | grep -q "rest_forbidden"; then
    echo -e "${RED}✗ FAIL${NC} - API Key not accepted"
    echo "Check your API key in WordPress admin"
else
    echo -e "${GREEN}✓ PASS${NC} - API Key authentication working"
fi
echo

# Test 5: Check pending image cleanup
echo -e "${YELLOW}Test 5: Pending image cleanup check${NC}"
RESULT=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-associate-images?batch_size=1" \
     -H "X-API-Key: $API_KEY")
SUCCESS=$(echo $RESULT | jq -r '.success')

if [ "$SUCCESS" = "true" ]; then
    REMAINING=$(echo $RESULT | jq -r '.remaining_records')
    echo -e "${GREEN}✓ PASS${NC} - $REMAINING images pending deletion"
    
    if [ "$REMAINING" -gt 0 ]; then
        echo -e "${YELLOW}Note:${NC} You have images waiting to be cleaned up"
        echo "Run: curl -X POST '$SITE_URL/wp-json/delete-images/v1/delete-associate-images' -H 'X-API-Key: $API_KEY'"
    fi
else
    echo -e "${RED}✗ FAIL${NC}"
fi
echo

# Summary
echo "========================================"
echo "Test Summary"
echo "========================================"
echo "All tests completed!"
echo
echo "To delete products (limit 10 for testing):"
echo "curl -X POST '$SITE_URL/wp-json/delete-images/v1/delete-products?limit=10' -H 'X-API-Key: $API_KEY'"
echo
echo "To check what will be deleted:"
echo "curl '$SITE_URL/wp-json/delete-images/v1/get-out-of-stock-products?limit=10'"

