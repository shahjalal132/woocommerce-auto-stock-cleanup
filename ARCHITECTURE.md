# Architecture Diagram - WooCommerce Auto Stock Cleanup

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    WordPress / WooCommerce                       │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         │
┌────────────────────────┴────────────────────────────────────────┐
│          WooCommerce_Auto_Stock_Cleanup (Main Class)            │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                    Uses Traits                            │  │
│  │  ┌──────────────────┐     ┌──────────────────────────┐  │  │
│  │  │ WC_REST_API_Trait│     │WC_Batch_Processing_Trait │  │  │
│  │  └──────────────────┘     └──────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────┬────────────────────────────────────────┘
                         │
            ┌────────────┼────────────┐
            │            │            │
            ▼            ▼            ▼
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│   Helpers    │ │   Templates  │ │   Features   │
│              │ │              │ │              │
│ ┌──────────┐ │ │ ┌──────────┐ │ │ ┌──────────┐ │
│ │  Query   │ │ │ │  Admin   │ │ │ │Scheduler │ │
│ │  Helper  │ │ │ │   Page   │ │ │ └──────────┘ │
│ └──────────┘ │ │ └──────────┘ │ │              │
│              │ │              │ │ ┌──────────┐ │
│ ┌──────────┐ │ │ ┌──────────┐ │ │ │Duplicate │ │
│ │ Deletion │ │ │ │   API    │ │ │ │  Images  │ │
│ │  Helper  │ │ │ │Endpoints │ │ │ └──────────┘ │
│ └──────────┘ │ │ └──────────┘ │ │              │
│              │ │              │ │ ┌──────────┐ │
│ ┌──────────┐ │ │ ┌──────────┐ │ │ │ Category │ │
│ │ Utility  │ │ │ │ Category │ │ │ │ Deletion │ │
│ │  Helper  │ │ │ │Endpoints │ │ │ └──────────┘ │
│ └──────────┘ │ │ └──────────┘ │ │              │
│              │ │              │ │              │
│              │ │ ┌──────────┐ │ │              │
│              │ │ │  Stats   │ │ │              │
│              │ │ │  Table   │ │ │              │
│              │ │ └──────────┘ │ │              │
└──────────────┘ └──────────────┘ └──────────────┘
```

## Data Flow

### REST API Request Flow
```
Client Request
    │
    ▼
┌──────────────────┐
│  REST API Trait  │ ← Validates API Key
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│  Query Helper    │ ← Fetches products from DB
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ Deletion Helper  │ ← Deletes products/images
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ Utility Helper   │ ← Logs activity
└────────┬─────────┘
         │
         ▼
    JSON Response
```

### Admin Page Request Flow
```
Admin Request
    │
    ▼
┌──────────────────┐
│   Main Class     │ ← Handles admin page
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│   Admin Page     │ ← Main template
│    Template      │
└────────┬─────────┘
         │
    ┌────┴────┬────────┬────────┐
    ▼         ▼        ▼        ▼
┌────────┐ ┌─────┐ ┌────────┐ ┌─────┐
│   API  │ │ Cat │ │ Stats  │ │ CSS │
│Partial │ │Part.│ │Partial │ │/ JS │
└────────┘ └─────┘ └────────┘ └─────┘
```

### Batch Processing Flow
```
Cron Trigger / Manual Run
    │
    ▼
┌──────────────────────────┐
│ Batch Processing Trait   │
│  - Initialize stats      │
│  - Set time limits       │
└────────┬─────────────────┘
         │
         ▼
┌──────────────────────────┐
│   Query Helper           │
│  - Count products        │
│  - Get non-brazyliany    │
│  - Get brazyliany        │
└────────┬─────────────────┘
         │
         ▼
┌──────────────────────────┐
│  Process Batches         │
│  - Batch 1 → Delete      │
│  - Batch 2 → Delete      │
│  - Batch N → Delete      │
│  - Check timeout         │
│  - Memory cleanup        │
└────────┬─────────────────┘
         │
         ▼
┌──────────────────────────┐
│   Deletion Helper        │
│  - Delete products       │
│  - Delete variations     │
│  - Delete images         │
└────────┬─────────────────┘
         │
         ▼
┌──────────────────────────┐
│   Utility Helper         │
│  - Log activity          │
│  - Store statistics      │
└──────────────────────────┘
```

## Component Responsibilities

### Main Class (woocommerce-auto-stock-cleanup.php)
```
┌──────────────────────────────────────┐
│  WooCommerce_Auto_Stock_Cleanup      │
├──────────────────────────────────────┤
│ Responsibilities:                    │
│ • Plugin initialization              │
│ • Hook registration                  │
│ • Menu registration                  │
│ • Asset enqueuing                    │
│ • AJAX handlers                      │
│ • Compatibility declarations         │
│                                      │
│ Uses:                                │
│ • WC_REST_API_Trait                  │
│ • WC_Batch_Processing_Trait          │
└──────────────────────────────────────┘
```

### Helper Classes
```
┌─────────────────────────┐  ┌─────────────────────────┐  ┌─────────────────────────┐
│  WC_Product_Query_Hel   │  │  WC_Deletion_Helper     │  │  WC_Utility_Helper      │
├─────────────────────────┤  ├─────────────────────────┤  ├─────────────────────────┤
│ • Get products          │  │ • Delete products       │  │ • Log messages          │
│ • Filter by category    │  │ • Delete variations     │  │ • Log cleanup stats     │
│ • Filter by stock       │  │ • Delete images         │  │ • Create DB tables      │
│ • Count products        │  │ • Fast SQL deletion     │  │ • Common utilities      │
│ • Optimized queries     │  │ • File operations       │  │ • Error handling        │
└─────────────────────────┘  └─────────────────────────┘  └─────────────────────────┘
```

### Traits
```
┌─────────────────────────────────────┐  ┌─────────────────────────────────────┐
│     WC_REST_API_Trait               │  │  WC_Batch_Processing_Trait          │
├─────────────────────────────────────┤  ├─────────────────────────────────────┤
│ • register_rest_routes()            │  │ • run_daily_cleanup()               │
│ • rest_permission_check()           │  │ • process_non_brazyliany_batches()  │
│ • rest_get_out_of_stock_products()  │  │ • process_brazyliany_batches()      │
│ • rest_delete_products_fast()       │  │ • Timeout management                │
│ • rest_delete_associate_images()    │  │ • Memory optimization               │
│ • rest_get_stats()                  │  │ • Progress tracking                 │
└─────────────────────────────────────┘  └─────────────────────────────────────┘
```

### Templates
```
┌────────────────────────┐
│   Admin Page Template  │
├────────────────────────┤
│ • Main structure       │
│ • Includes partials    │
│ • Variable-based       │
└────────┬───────────────┘
         │
    ┌────┴────┬─────────┬──────────┐
    ▼         ▼         ▼          ▼
┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
│  API   │ │Category│ │ Stats  │ │ Other  │
│Partial │ │Partial │ │Partial │ │Sections│
└────────┘ └────────┘ └────────┘ └────────┘
```

## Database Interactions

```
┌──────────────────────────────────────┐
│       WordPress Database             │
├──────────────────────────────────────┤
│                                      │
│  wp_posts                            │
│  ├─ products                         │
│  ├─ variations                       │
│  └─ attachments                      │
│                                      │
│  wp_postmeta                         │
│  ├─ _stock                           │
│  ├─ _stock_status                    │
│  ├─ _thumbnail_id                    │
│  └─ _product_image_gallery           │
│                                      │
│  wp_term_relationships               │
│  ├─ product categories               │
│  └─ product tags                     │
│                                      │
│  wp_out_of_stock_products_data       │
│  ├─ Deleted product records          │
│  ├─ Pending image deletions          │
│  └─ Cleanup history                  │
│                                      │
│  wp_options                          │
│  ├─ delete_images_api_key            │
│  ├─ delete_images_cleanup_stats      │
│  └─ delete_images_last_cleanup       │
└──────────────────────────────────────┘
         ▲
         │
    ┌────┴────┐
    │         │
    ▼         ▼
┌────────┐ ┌────────┐
│ Query  │ │Deletion│
│ Helper │ │ Helper │
└────────┘ └────────┘
```

## API Endpoints Structure

```
/wp-json/delete-images/v1/
│
├─ /get-out-of-stock-products (GET) ← Public
│   ├─ ?cat=category
│   ├─ ?filter=out-of-stock
│   └─ ?limit=100
│
├─ /delete-products (POST) ← Requires API Key
│   ├─ ?cat=category
│   ├─ ?filter=out-of-stock
│   └─ ?limit=100
│
├─ /delete-associate-images (POST) ← Requires API Key
│   └─ ?batch_size=50
│
├─ /cat-products (GET) ← Public
│   ├─ ?cat=category
│   └─ ?limit=100
│
├─ /delete-cat-products (POST) ← Requires API Key
│   ├─ ?cat=category
│   └─ ?limit=100
│
├─ /cleanup (POST) ← Legacy, Requires API Key
│
└─ /stats (GET) ← Public
```

## File Structure Tree

```
delete-images-by-ids/
│
├─ woocommerce-auto-stock-cleanup.php  (Main, 250 lines)
│
├─ includes/
│  │
│  ├─ helpers/
│  │  ├─ class-product-query-helper.php  (400 lines)
│  │  ├─ class-deletion-helper.php       (200 lines)
│  │  └─ class-utility-helper.php        (80 lines)
│  │
│  ├─ traits/
│  │  ├─ trait-rest-api.php              (250 lines)
│  │  └─ trait-batch-processing.php      (170 lines)
│  │
│  ├─ templates/
│  │  ├─ admin-page.php                  (130 lines)
│  │  └─ partials/
│  │     ├─ api-endpoints.php            (80 lines)
│  │     ├─ category-endpoints.php       (50 lines)
│  │     └─ stats-table.php              (70 lines)
│  │
│  ├─ scheduler.php                      (Cron jobs)
│  ├─ delete-duplicate-images.php        (Duplicate handling)
│  └─ delete-products-by-category.php    (Category deletion)
│
├─ assets/
│  └─ admin/
│     ├─ css/delete-images.css
│     └─ js/delete-images.js
│
└─ docs/
   ├─ README.md
   ├─ CHANGELOG.md
   ├─ STRUCTURE.md
   ├─ ARCHITECTURE.md (This file)
   └─ REFACTORING-SUMMARY.md
```

## Dependency Graph

```
Main Class
├─ Uses → WC_REST_API_Trait
│         ├─ Uses → WC_Product_Query_Helper
│         ├─ Uses → WC_Deletion_Helper
│         └─ Uses → WC_Utility_Helper
│
├─ Uses → WC_Batch_Processing_Trait
│         ├─ Uses → WC_Product_Query_Helper
│         ├─ Uses → WC_Deletion_Helper
│         └─ Uses → WC_Utility_Helper
│
└─ Includes → Templates
              └─ Includes → Partials
```

## Performance Characteristics

```
┌────────────────────────────────────────┐
│        Operation Performance           │
├────────────────────────────────────────┤
│ Query Products     → O(n log n)        │
│ Delete Products    → O(n)              │
│ Delete Images      → O(n)              │
│ Batch Processing   → O(n/batch_size)   │
│ Template Rendering → O(1)              │
│ API Response       → O(n)              │
└────────────────────────────────────────┘

Memory Usage:
├─ Per Batch:  ~10-50 MB
├─ Peak:       ~512 MB (configurable)
└─ Average:    ~100 MB

Execution Time:
├─ Per Product: ~0.01-0.05 seconds
├─ Per Batch:   ~5-15 seconds
└─ Max Total:   5 minutes (timeout protected)
```

## Security Model

```
┌────────────────────────────────────┐
│         Authentication             │
├────────────────────────────────────┤
│ Public Endpoints:                  │
│ ├─ GET /get-out-of-stock-products  │
│ ├─ GET /cat-products               │
│ └─ GET /stats                      │
│                                    │
│ Protected Endpoints:               │
│ ├─ POST /delete-products           │
│ ├─ POST /delete-associate-images   │
│ ├─ POST /delete-cat-products       │
│ └─ POST /cleanup                   │
│                                    │
│ Admin Actions:                     │
│ ├─ Nonce verification              │
│ └─ Capability checks               │
└────────────────────────────────────┘
```

---

**Last Updated:** October 21, 2025
**Version:** 2.2.0
**Status:** Production Ready ✅

