# WooCommerce Auto Stock Cleanup - Code Structure

## Overview
This plugin has been refactored to follow clean code principles with a modular, maintainable structure.

## Directory Structure

```
delete-images-by-ids/
├── assets/
│   └── admin/
│       ├── css/
│       └── js/
├── includes/
│   ├── helpers/           # Helper classes for specific operations
│   │   ├── class-product-query-helper.php    # Product database queries
│   │   ├── class-deletion-helper.php         # Product/image deletion operations
│   │   └── class-utility-helper.php          # Logging and utility functions
│   ├── traits/            # Reusable trait files
│   │   ├── trait-rest-api.php                # REST API endpoints
│   │   └── trait-batch-processing.php        # Batch processing logic
│   ├── templates/         # View templates
│   │   ├── admin-page.php                    # Main admin page template
│   │   └── partials/                         # Template partials
│   │       ├── api-endpoints.php             # API documentation partial
│   │       ├── category-endpoints.php        # Category API partial
│   │       └── stats-table.php               # Statistics table partial
│   ├── scheduler.php                         # Cron scheduling
│   ├── delete-duplicate-images.php           # Duplicate image deletion
│   └── delete-products-by-category.php       # Category-based deletion
├── woocommerce-auto-stock-cleanup.php        # Main plugin file (refactored)
├── woocommerce-auto-stock-cleanup-backup.php # Original backup
└── README.md
```

## Code Organization

### 1. Main Plugin File
**Location:** `woocommerce-auto-stock-cleanup.php`

The main plugin file is now clean and focused on:
- Plugin initialization
- Hook registration
- Dependency loading
- Using traits for modular functionality

**Key Features:**
- Uses `WC_REST_API_Trait` for all REST API endpoints
- Uses `WC_Batch_Processing_Trait` for cleanup batch processing
- Delegates complex operations to helper classes
- Clean, readable code under 250 lines

### 2. Helper Classes
**Location:** `includes/helpers/`

#### `class-product-query-helper.php`
Handles all product-related database queries:
- `get_single_quantity_products()` - Get products with specific criteria
- `get_all_out_of_stock_products()` - Get out-of-stock products
- `get_non_brazyliany_products()` - Category-filtered queries
- `get_brazyliany_products()` - Biustonosze products
- `count_*()` methods - Product counting operations

#### `class-deletion-helper.php`
Handles deletion operations:
- `delete_products_fast()` - Fast SQL-based product deletion
- `delete_attachment_fast()` - Fast image deletion
- `delete_products_and_attachments()` - Comprehensive deletion

#### `class-utility-helper.php`
Common utility functions:
- `log_message()` - Logging functionality
- `log_cleanup()` - Cleanup activity logging
- `create_deleted_products_table()` - Database table creation

### 3. Traits
**Location:** `includes/traits/`

#### `trait-rest-api.php`
All REST API related functionality:
- `register_rest_routes()` - Route registration
- `rest_permission_check()` - API authentication
- `rest_get_out_of_stock_products()` - GET endpoint handler
- `rest_delete_products_fast()` - DELETE endpoint handler
- `rest_delete_associate_images()` - Image deletion endpoint
- `rest_get_stats()` - Statistics endpoint

#### `trait-batch-processing.php`
Batch processing logic:
- `run_daily_cleanup()` - Main cleanup orchestration
- `process_non_brazyliany_batches()` - Non-brazyliany batch processing
- `process_brazyliany_batches()` - Brazyliany batch processing

### 4. Templates
**Location:** `includes/templates/`

#### Main Template
- `admin-page.php` - Main admin page template with variables

#### Partials
- `api-endpoints.php` - API documentation section
- `category-endpoints.php` - Category API documentation
- `stats-table.php` - Statistics display table

## Benefits of This Structure

### 1. **Separation of Concerns**
- Each class/file has a single, well-defined responsibility
- Business logic separated from presentation
- Database operations isolated in query helpers

### 2. **Maintainability**
- Easy to locate and modify specific functionality
- Changes in one area don't affect others
- Clear file naming conventions

### 3. **Testability**
- Helper classes can be tested independently
- Traits can be tested in isolation
- Mock dependencies easily

### 4. **Reusability**
- Helper classes are static and can be used anywhere
- Traits can be applied to multiple classes
- Templates can be reused

### 5. **Scalability**
- Easy to add new features without cluttering main file
- Can add new helpers/traits as needed
- Clean architecture supports growth

## Usage Examples

### Using Helper Classes

```php
// Get products
$products = WC_Product_Query_Helper::get_all_out_of_stock_products( 100 );

// Delete products
$result = WC_Deletion_Helper::delete_products_fast( $products );

// Log message
WC_Utility_Helper::log_message( 'Processing complete' );
```

### Using Templates

```php
// In admin page
$stats = get_option( 'delete_images_cleanup_stats', [] );
$api_key = get_option( 'delete_images_api_key', '' );
$site_url = get_site_url();

include __DIR__ . '/includes/templates/admin-page.php';
```

## Migration Notes

### What Changed
1. **Main file reduced from 1617 lines to ~250 lines**
2. **All HTML moved to template files**
3. **Database queries moved to helper class**
4. **Deletion logic moved to helper class**
5. **REST API moved to trait**
6. **Batch processing moved to trait**

### What Stayed the Same
1. All functionality works exactly as before
2. Same REST API endpoints
3. Same admin interface
4. Same database structure
5. Backward compatible

### Backup
The original file is saved as `woocommerce-auto-stock-cleanup-backup.php`

## Best Practices

### Adding New Features

1. **New Database Query?**
   - Add to `class-product-query-helper.php`

2. **New Deletion Operation?**
   - Add to `class-deletion-helper.php`

3. **New REST Endpoint?**
   - Add to `trait-rest-api.php`

4. **New Admin Section?**
   - Create new partial in `templates/partials/`

5. **New Utility Function?**
   - Add to `class-utility-helper.php`

## Performance Considerations

- Helper classes use static methods (no instantiation overhead)
- Traits are compiled at runtime (no performance penalty)
- Templates are included only when needed
- Database queries are optimized and use prepared statements

## Security

- All inputs sanitized in REST endpoints
- Nonce checks on admin actions
- API key authentication for sensitive endpoints
- SQL injection prevention with prepared statements

## Future Enhancements

1. Add unit tests for helper classes
2. Add PHPDoc blocks for better IDE support
3. Consider adding a service container
4. Add action/filter hooks for extensibility
5. Consider splitting REST API trait into smaller traits

