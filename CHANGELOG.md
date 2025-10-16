# Changelog - WooCommerce Auto Stock Cleanup

## Updates Made on October 16, 2025

### 1. Updated Biustonosze Category Query Logic

**Previous Behavior:**
- The plugin was checking for products in the 'brazyliany' category where all variations had stock < 5

**New Behavior:**
- Now checks for products in the 'biustonosze' category with FEWER THAN 5 variations (sizes)
- Regardless of stock quantity, products are selected based on the number of variations they have

**Affected Methods:**
- `get_brazyliany_products()` - Updated to query for products with < 5 variations
- `count_brazyliany_products()` - Updated to count products with < 5 variations

**SQL Query Changes:**
```sql
-- NEW: Checks variation count instead of stock levels
HAVING COUNT(DISTINCT v.ID) < 5
```

### 2. Added `filter=out-of-stock` Parameter

**Purpose:**
Delete all out-of-stock products (total stock = 0) across ALL categories, not just specific ones.

**Affected Endpoints:**

#### GET `/wp-json/delete-images/v1/get-out-of-stock-products`
- **New Parameter:** `filter=out-of-stock`
- **Behavior:** Returns all products with 0 stock from all categories

**Example:**
```bash
curl "https://yoursite.com/wp-json/delete-images/v1/get-out-of-stock-products?filter=out-of-stock&limit=50"
```

#### POST `/wp-json/delete-images/v1/delete-products`
- **New Parameter:** `filter=out-of-stock`
- **Behavior:** Deletes all products with 0 stock from all categories

**Example:**
```bash
curl -X POST "https://yoursite.com/wp-json/delete-images/v1/delete-products?filter=out-of-stock&limit=500" \
     -H "X-API-Key: YOUR_API_KEY"
```

**New Method Added:**
- `get_all_out_of_stock_products($limit)` - Queries all products with total stock = 0 across all categories

### 3. Updated Admin UI

**Changes:**
- Updated endpoint documentation to include the new `filter` parameter
- Added examples showing both category-specific and all-category filtering
- Updated statistics labels:
  - "Non-Brazyliany" → "Non-Biustonosze"
  - Stock descriptions updated to reflect new logic

### Query Differences

#### Default Behavior (Single Quantity Products):
```sql
-- Products where ALL variations have stock = 1
HAVING SUM(IFNULL(CAST(stock_qty.meta_value AS UNSIGNED), 0) <> 1) = 0
```

#### Biustonosze Category:
```sql
-- Products with fewer than 5 variations/sizes
HAVING COUNT(DISTINCT v.ID) < 5
```

#### Out-of-Stock Filter (`filter=out-of-stock`):
```sql
-- Products where total stock across all variations = 0
HAVING SUM(IFNULL(CAST(stock_qty.meta_value AS UNSIGNED), 0)) = 0
```

### Usage Examples

#### 1. Get products with < 5 variations from biustonosze:
```bash
curl "https://yoursite.com/wp-json/delete-images/v1/get-out-of-stock-products?limit=100"
```

#### 2. Get all out-of-stock products from all categories:
```bash
curl "https://yoursite.com/wp-json/delete-images/v1/get-out-of-stock-products?filter=out-of-stock&limit=100"
```

#### 3. Delete all out-of-stock products:
```bash
curl -X POST "https://yoursite.com/wp-json/delete-images/v1/delete-products?filter=out-of-stock&limit=500" \
     -H "X-API-Key: YOUR_API_KEY"
```

#### 4. Then cleanup images:
```bash
curl -X POST "https://yoursite.com/wp-json/delete-images/v1/delete-associate-images" \
     -H "X-API-Key: YOUR_API_KEY"
```

### Technical Notes

- The `filter` parameter takes precedence over the `cat` parameter
- When `filter=out-of-stock` is used, all categories are searched
- The query joins with the term taxonomy to ensure only products with categories are included
- All queries maintain the same performance optimization with GROUP BY and prepared statements

