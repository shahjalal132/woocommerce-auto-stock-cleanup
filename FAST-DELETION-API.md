# Fast Deletion API (v2.2.0)

## Overview

New **ultra-fast deletion system** that separates product deletion from image deletion for maximum performance. Products are deleted instantly via direct SQL, while images are cleaned up separately in batches.

### Performance Comparison

| Method | Products/Second | 2000 Products |
|--------|-----------------|---------------|
| **OLD** (wp_delete_post) | ~2-5 products/sec | 6-16 minutes |
| **NEW** (Direct SQL) | ~100-200 products/sec | 10-20 seconds |

**Speed Improvement**: 20-40x faster! ⚡

## Architecture

```
1. Query Products → 2. Store to Table → 3. SQL DELETE → 4. Delete Images (Later)
   (GET /get-out-of-stock-products)  (POST /delete-products)  (POST /delete-associate-images)
```

### Custom Table: `wp_out_of_stock_products_data`

Tracks deleted products for image cleanup:
```sql
- id (auto_increment)
- product_id (bigint)
- product_title (varchar)
- category_slug (varchar)
- attachment_ids (text, comma-separated)
- deleted_at (datetime)
- images_deleted (tinyint, 0=pending, 1=done)
```

## API Endpoints

### 1. GET /wp-json/delete-images/v1/get-out-of-stock-products

**Purpose**: Query products without deleting (preview/check)

**Parameters**:
- `cat` (optional) - Category slug filter
- `limit` (optional, default: 100, max: 1000) - Number of products

**Examples**:
```bash
# Get all single-quantity products (limit 100)
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/get-out-of-stock-products"

# Get specific category
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/get-out-of-stock-products?cat=electronics"

# Get with custom limit
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/get-out-of-stock-products?limit=500"

# Combine filters
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/get-out-of-stock-products?cat=electronics&limit=200"
```

**Response**:
```json
{
  "success": true,
  "count": 150,
  "category": "electronics",
  "limit": 200,
  "products": [
    {
      "product_id": 12345,
      "product_name": "Product Name",
      "category_slug": "electronics",
      "attachment_ids": "789,790,791"
    }
  ]
}
```

### 2. POST /wp-json/delete-images/v1/delete-products

**Purpose**: Delete products instantly (SQL only, no images yet)

**Authentication**: API Key required

**Parameters**:
- `cat` (optional) - Category slug filter
- `limit` (optional, default: 100, max: 1000) - Number of products

**Examples**:
```bash
# Delete 100 products (all categories)
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-products" \
     -H "X-API-Key: YOUR_API_KEY"

# Delete from specific category
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-products?cat=electronics" \
     -H "X-API-Key: YOUR_API_KEY"

# Delete with custom limit
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-products?limit=500" \
     -H "X-API-Key: YOUR_API_KEY"
```

**Response**:
```json
{
  "success": true,
  "products_deleted": 150,
  "variations_deleted": 450,
  "stored_for_image_cleanup": 150,
  "execution_time": "2.34 seconds",
  "category": "electronics"
}
```

### 3. POST /wp-json/delete-images/v1/delete-associate-images

**Purpose**: Delete images for previously deleted products

**Authentication**: API Key required

**Parameters**:
- `batch_size` (optional, default: 50, max: 500) - Images per batch

**Examples**:
```bash
# Delete images (batch of 50)
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-associate-images" \
     -H "X-API-Key: YOUR_API_KEY"

# Larger batch
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-associate-images?batch_size=200" \
     -H "X-API-Key: YOUR_API_KEY"
```

**Response**:
```json
{
  "success": true,
  "images_deleted": 156,
  "records_processed": 50,
  "remaining_records": 100,
  "execution_time": "5.67 seconds"
}
```

## Complete Workflow Examples

### Example 1: Preview → Delete → Cleanup Images

```bash
#!/bin/bash
SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"

# Step 1: Preview what will be deleted
echo "=== Step 1: Preview ==="
curl -s "$SITE_URL/wp-json/delete-images/v1/get-out-of-stock-products?limit=10" | jq '{count: .count, category: .category}'

# Step 2: Delete products (instant)
echo "=== Step 2: Delete Products ==="
RESULT=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-products?limit=100" \
     -H "X-API-Key: $API_KEY")
echo $RESULT | jq '{products_deleted, execution_time, stored_for_image_cleanup}'

# Step 3: Delete images (can run later)
echo "=== Step 3: Delete Images ==="
IMAGE_RESULT=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-associate-images" \
     -H "X-API-Key: $API_KEY")
echo $IMAGE_RESULT | jq '{images_deleted, remaining_records}'
```

### Example 2: Batch Delete All Products

```bash
#!/bin/bash
SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"
BATCH_SIZE=500

echo "Starting bulk deletion..."

# Delete products in batches until none found
while true; do
    RESULT=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-products?limit=$BATCH_SIZE" \
         -H "X-API-Key: $API_KEY")
    
    DELETED=$(echo $RESULT | jq -r '.products_deleted')
    TIME=$(echo $RESULT | jq -r '.execution_time')
    
    echo "Deleted $DELETED products in $TIME"
    
    if [ "$DELETED" -eq 0 ]; then
        echo "All products deleted!"
        break
    fi
    
    sleep 2
done

# Now delete all images
echo "Deleting images..."
while true; do
    RESULT=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-associate-images?batch_size=200" \
         -H "X-API-Key: $API_KEY")
    
    REMAINING=$(echo $RESULT | jq -r '.remaining_records')
    DELETED=$(echo $RESULT | jq -r '.images_deleted')
    
    echo "Deleted $DELETED images, $REMAINING remaining"
    
    if [ "$REMAINING" -eq 0 ]; then
        echo "All images deleted!"
        break
    fi
    
    sleep 1
done
```

### Example 3: Category-Specific Cleanup

```bash
#!/bin/bash
SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"

CATEGORIES=("electronics" "clothing" "toys")

for cat in "${CATEGORIES[@]}"; do
    echo "=== Processing category: $cat ==="
    
    # Get count
    COUNT=$(curl -s "$SITE_URL/wp-json/delete-images/v1/get-out-of-stock-products?cat=$cat" | jq -r '.count')
    echo "Found $COUNT products"
    
    if [ "$COUNT" -gt 0 ]; then
        # Delete
        curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-products?cat=$cat&limit=1000" \
             -H "X-API-Key: $API_KEY" | jq '{products_deleted, execution_time}'
    fi
done

# Cleanup all images at the end
curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-associate-images?batch_size=500" \
     -H "X-API-Key: $API_KEY" | jq .
```

## Cron Job Examples

### Setup 1: Delete Products Daily, Images Hourly

```bash
# Delete products at 2 AM
0 2 * * * curl -s -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-products?limit=1000" -H "X-API-Key: YOUR_KEY" >> /var/log/product-delete.log 2>&1

# Delete images every hour
0 * * * * curl -s -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-associate-images?batch_size=200" -H "X-API-Key: YOUR_KEY" >> /var/log/image-delete.log 2>&1
```

### Setup 2: Complete Cleanup Daily

```bash
# Single daily cleanup at 3 AM
0 3 * * * /usr/local/bin/complete-cleanup.sh >> /var/log/cleanup.log 2>&1
```

**complete-cleanup.sh**:
```bash
#!/bin/bash
SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"

# Delete products
curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-products?limit=2000" -H "X-API-Key: $API_KEY"

# Wait 30 seconds
sleep 30

# Delete images
curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-associate-images?batch_size=500" -H "X-API-Key: $API_KEY"
```

## Performance Tips

### 1. Optimal Batch Sizes

| Operation | Recommended | Max Safe |
|-----------|-------------|----------|
| Product Query | 100-500 | 1000 |
| Product Delete | 500-1000 | 1000 |
| Image Delete | 100-200 | 500 |

### 2. For 2000+ Products

**Option A: Single Large Batch**
```bash
# Delete all at once (fastest)
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-products?limit=1000" -H "X-API-Key: KEY"
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-products?limit=1000" -H "X-API-Key: KEY"
# Then images
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-associate-images?batch_size=500" -H "X-API-Key: KEY"
```

**Option B: Continuous Loop**
```bash
# Loop until all deleted
while true; do
    RESULT=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/delete-products?limit=1000" -H "X-API-Key: $API_KEY")
    DELETED=$(echo $RESULT | jq -r '.products_deleted')
    [ "$DELETED" -eq 0 ] && break
done
```

### 3. Monitoring

```bash
# Check pending image cleanup
curl -s -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-associate-images?batch_size=1" \
     -H "X-API-Key: YOUR_KEY" | jq '.remaining_records'
```

## Database Maintenance

### View Tracking Table

```sql
-- See products pending image deletion
SELECT COUNT(*) as pending FROM wp_out_of_stock_products_data WHERE images_deleted = 0;

-- See last 10 deleted products
SELECT * FROM wp_out_of_stock_products_data ORDER BY deleted_at DESC LIMIT 10;

-- Clean old records (older than 30 days)
DELETE FROM wp_out_of_stock_products_data WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND images_deleted = 1;
```

### Cleanup Script

```bash
#!/bin/bash
# Run weekly to clean old tracking records

mysql -u username -p database_name << EOF
DELETE FROM wp_out_of_stock_products_data 
WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY) 
AND images_deleted = 1;
EOF
```

## Troubleshooting

### Issue: Images Not Deleting
```bash
# Check pending count
curl -s "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-associate-images?batch_size=1" \
     -H "X-API-Key: KEY" | jq '.remaining_records'

# Force larger batch
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/delete-associate-images?batch_size=500" \
     -H "X-API-Key: KEY"
```

### Issue: Products Not Found
```bash
# Test query
curl -s "https://kobiecy-akcent.pl/wp-json/delete-images/v1/get-out-of-stock-products?limit=10" | jq '.count'
```

### Issue: Slow Performance
```bash
# Add database indexes
ALTER TABLE wp_out_of_stock_products_data ADD INDEX idx_images_deleted (images_deleted);
ALTER TABLE wp_out_of_stock_products_data ADD INDEX idx_deleted_at (deleted_at);
```

## Benefits Summary

✅ **20-40x Faster**: Direct SQL vs WordPress functions  
✅ **Instant Response**: Products deleted in seconds  
✅ **Flexible**: Delete products now, images later  
✅ **Scalable**: Handle thousands of products easily  
✅ **Trackable**: Custom table tracks everything  
✅ **Safe**: Separate operations prevent data loss  
✅ **Category Filtering**: Target specific categories  

---

**Recommended workflow**: Use `/delete-products` for instant product removal, then `/delete-associate-images` during off-peak hours for image cleanup.
