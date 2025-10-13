# WooCommerce Auto Stock Cleanup Plugin

**Version:** 2.2.0  
**Author:** Shah Jalal

## Description

A high-performance WordPress plugin designed for WooCommerce stores that provides:
1. **Manual Image Deletion**: Delete WordPress attachments by their IDs with AJAX and a progress bar
2. **Asynchronous Job System**: Non-blocking API endpoints that return instantly with job IDs for background processing
3. **Real-time Progress Tracking**: Monitor job status, progress percentage, and estimated completion time
4. **Intelligent Batch Processing**: Efficiently handle 1000s of products with automatic batch processing, timeout protection, and memory management
5. **Automatic Product Cleanup via REST API**: Delete products with single quantity (stock = 1) for non-brazyliany categories and low stock (< 5) for brazyliany category
6. **Full WooCommerce Compatibility**: HPOS ready, Blocks compatible, and follows all WooCommerce standards

## Features

### 1. WooCommerce Compatibility ✅
- **HPOS (High-Performance Order Storage) Ready**: Fully compatible with modern WooCommerce order storage
- **WooCommerce Blocks Compatible**: Works seamlessly with Gutenberg blocks and modern checkout
- **Version Compatibility**: Supports WooCommerce 3.0+ to 8.0+
- **Standards Compliant**: Follows all WooCommerce coding standards and best practices
- **No Compatibility Warnings**: Properly declares all feature compatibility

### 2. Asynchronous Job System 🚀 (NEW v2.2.0)
- **Instant API Response**: Get job ID in ~100ms, no more waiting for long processes
- **Background Processing**: Jobs run independently without blocking API calls
- **Real-time Progress**: Monitor job status, percentage, and estimated time remaining
- **Job Queue Management**: Track multiple jobs with detailed status and logs
- **No Timeouts**: Eliminates "partial_timeout" issues from large datasets
- **Server Friendly**: Non-blocking execution prevents server resource exhaustion

### 3. Manual Image Deletion
- Enter comma-separated attachment IDs
- AJAX-powered deletion with real-time progress bar
- Visual feedback for successful and failed deletions

### 4. REST API Endpoints for Product Cleanup

#### **NEW: Async Cleanup Endpoint** (POST) - v2.2.0
Creates a background cleanup job and returns instantly with job ID.

**URL:** `https://your-site.com/wp-json/delete-images/v1/cleanup`  
**Method:** POST  
**Authentication:** API Key (X-API-Key header)  
**Response Time:** ~100ms (instant)

**Example cURL:**
```bash
curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: YOUR_API_KEY"
```

**Instant Response (202 Accepted):**
```json
{
  "success": true,
  "job_id": "cleanup_12345678-1234-1234-1234-123456789abc",
  "status": "queued",
  "message": "Cleanup job created successfully. Use the job ID to check progress.",
  "endpoints": {
    "status": "https://your-site.com/wp-json/delete-images/v1/job/cleanup_12345678-1234-1234-1234-123456789abc",
    "all_jobs": "https://your-site.com/wp-json/delete-images/v1/jobs"
  }
}
```

#### **Job Status Endpoint** (GET) - Monitor Progress
Real-time job monitoring without authentication.

**URL:** `https://your-site.com/wp-json/delete-images/v1/job/{job_id}`  
**Method:** GET  
**Authentication:** None required

**Example cURL:**
```bash
curl "https://your-site.com/wp-json/delete-images/v1/job/cleanup_12345678-1234-1234-1234-123456789abc"
```

**Progress Response:**
```json
{
  "success": true,
  "job": {
    "id": "cleanup_12345678-1234-1234-1234-123456789abc",
    "status": "running",
    "progress": {
      "percentage": 45.2,
      "current_batch": 18,
      "total_batches": 40,
      "products_deleted": 350,
      "processing_stage": "deleting_non_brazyliany"
    },
    "runtime": "5 minutes",
    "estimated_remaining": "6 minutes"
  }
}
```

#### **All Jobs Endpoint** (GET) - List Recent Jobs
View all recent cleanup jobs and their status.

**URL:** `https://your-site.com/wp-json/delete-images/v1/jobs`  
**Method:** GET  
**Authentication:** API Key required

**Example cURL:**
```bash
curl "https://your-site.com/wp-json/delete-images/v1/jobs" \
     -H "X-API-Key: YOUR_API_KEY"
```

#### **Stats Endpoint** (GET)
Retrieves the latest cleanup statistics without triggering a cleanup.

**URL:** `https://your-site.com/wp-json/delete-images/v1/stats`  
**Method:** GET  
**Authentication:** None (public endpoint)  

**Example cURL:**
```bash
curl "https://your-site.com/wp-json/delete-images/v1/stats"
```

**Response:**
```json
{
  "success": true,
  "stats": {
    "total_scanned": 3500,
    "non_brazyliany_found": 2156,
    "brazyliany_found": 45,
    "products_deleted": 350,
    "images_deleted": 1850,
    "variations_deleted": 890,
    "execution_time": "300.12 seconds",
    "timestamp": "2025-10-13 10:30:45",
    "batches_processed": 7,
    "status": "partial_timeout"
  },
  "last_cleanup": {
    "date": "2025-10-13 10:30:45",
    "count": 350,
    "product_ids": [123, 456, 789, ...]
  }
}
```

### 4. Detailed Statistics Tracking

The plugin tracks comprehensive statistics for each cleanup run:

- **Total Products Scanned**: Total number of published products in the database
- **Non-Brazyliany Products Found**: Products (excluding brazyliany) where all variations are out of stock
- **Brazyliany Products Found**: Products in brazyliany category where all variations have stock < 5
- **Total Products Deleted**: Number of products successfully deleted
- **Total Images Deleted**: Number of attachments (featured + gallery) deleted
- **Total Variations Deleted**: Number of product variations deleted
- **Execution Time**: How long the cleanup process took

### 5. Intelligent Batch Processing System

#### Performance Features:
- **Batch Size**: 50 products per batch for optimal performance
- **Time Management**: 5-minute maximum execution time with graceful timeout handling
- **Memory Optimization**: Automatic memory cleanup and garbage collection
- **Server Protection**: 0.1-second delays between batches to prevent overload
- **Progress Tracking**: Real-time monitoring with detailed batch statistics
- **Automatic Recovery**: Handles partial completions across multiple cron runs

#### Handling Large Volumes:
For **2000+ products**:
- Processing time: 5-10 minutes per run
- Expected completion: 1-3 hours with 10-minute cron intervals
- Memory usage: Optimized with automatic cleanup
- No timeouts or server crashes

### 6. Updated Cleanup Criteria

#### Non-Brazyliany Categories
- Deletes products where **ALL variations have exactly stock = 1**
- Uses improved query with `IFNULL(CAST(stock_qty.meta_value AS UNSIGNED), 0) <> 1`
- Includes all product attachments (featured image, gallery, and associated files)

#### Brazyliany Category  
- Deletes products where **ALL variations have stock quantity < 5**
- Checks `_stock` meta key for each variation
- If no variations have stock >= 5, the product is deleted

#### What Gets Deleted
For each product that meets the criteria:
- Featured image (`_thumbnail_id`)
- Product gallery images (`_product_image_gallery`)
- All product variations
- The product itself

## Installation

1. Upload the plugin folder to `/wp-content/plugins/delete-images-by-ids/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Tools → Delete Images by IDs** to configure

## Configuration

### Generate API Key

1. Go to **Tools → Delete Images by IDs**
2. Scroll to **REST API Endpoints** section
3. Click **Generate API Key**
4. Copy the generated key for use in your cron job

### Setup Manual Cron Job

Instead of using WordPress's built-in cron system, you can set up a system cron job for more reliability:

#### Example: Daily at 2 AM
```bash
# Edit crontab
crontab -e

# Add this line (replace YOUR_API_KEY and URL)
0 2 * * * curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

#### Example: Every 6 hours
```bash
0 */6 * * * curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

#### Example: Weekly on Monday at 3 AM
```bash
0 3 * * 1 curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

### Monitor Stats

You can monitor cleanup statistics by:
1. Viewing the admin page at **Tools → Delete Images by IDs**
2. Calling the stats endpoint: `GET /wp-json/delete-images/v1/stats`
3. Checking the log file: `/wp-content/delete-images-cleanup.log`

## Usage

### Manual Deletion (Admin UI)
1. Go to **Tools → Delete Images by IDs** in WordPress admin
2. Enter comma-separated attachment IDs (e.g., `123,456,789`)
3. Click "Delete Images" button
4. Watch the progress bar and see results

### Manual Cleanup Trigger
1. Go to **Tools → Delete Images by IDs**
2. Click "Run Cleanup Now" button
3. View updated statistics

### Automated Cleanup (Cron)
Set up a cron job as described in the Configuration section above.

## File Structure

```
delete-images-by-ids/
├── assets/
│   └── admin/
│       ├── css/
│       │   └── delete-images.css
│       └── js/
│           └── delete-images.js
├── delete-images-by-ids.php
└── README.md
```

## Database Queries

### Non-Brazyliany Products Query
Finds products (excluding brazyliany category) where all variations are out of stock.

### Brazyliany Products Query
Finds products in the brazyliany category where all variations have stock < 5.

Both queries extract:
- Product ID
- Product Name
- Category Slug
- Attachment IDs (featured image + gallery images)

## Security

### API Key Protection
- The cleanup endpoint requires an API key passed in the `X-API-Key` header
- API keys are generated using WordPress's secure password generator (32 characters)
- Keys are stored in the WordPress options table
- You can regenerate the API key at any time from the admin page

### Other Security Measures
- AJAX requests protected with nonce verification
- Manual cleanup requires `manage_options` capability
- All user inputs are sanitized and validated
- Uses WordPress core functions for deletion
- Stats endpoint is public but read-only (no sensitive data exposed)

## Logging

### File Log
Location: `/wp-content/delete-images-cleanup.log`

Format:
```
[2025-10-13 10:30:45] Cleanup Stats:
  - Products Scanned: 1500
  - Non-Brazyliany Found: 25
  - Brazyliany Found: 8
  - Products Deleted: 33
  - Images Deleted: 156
  - Variations Deleted: 98
  - Execution Time: 12.45 seconds
```

### Database Storage
Option names:
- `delete_images_cleanup_stats`: Latest cleanup statistics
- `delete_images_last_cleanup`: Last cleanup metadata with product IDs
- `delete_images_api_key`: API key for authentication

## Troubleshooting

### API Returns "Unauthorized"
1. Make sure you generated an API key in the admin panel
2. Verify you're passing the API key in the `X-API-Key` header
3. Check that the API key matches exactly (no extra spaces)

### Cron Job Not Running
1. Check cron syntax: `crontab -l`
2. Verify the URL is correct and accessible
3. Check cron logs: `tail -f /var/log/product-cleanup.log`
4. Test the endpoint manually with cURL

### No Products Being Deleted
1. Check the stats to see how many products were scanned and found
2. Verify your products meet the deletion criteria
3. Check that variations exist and have proper stock meta data
4. Look at the log file for any errors

### Check Logs
View the log file to see cleanup history:
```bash
tail -f /srv/http/wholesaler/wp-content/delete-images-cleanup.log
```

### Database Prefix
The plugin uses `$wpdb->prefix` to support any WordPress database prefix. If you have a custom prefix (e.g., `wpd6_`), it will work automatically.

## Advantages Over WordPress Cron

Using manual cron jobs via REST API instead of WordPress's built-in cron system provides:

1. **Reliability**: System cron always runs at scheduled times, unlike WP-Cron which depends on site visitors
2. **Control**: You have full control over when and how often cleanup runs
3. **Performance**: Doesn't impact site performance during visitor browsing
4. **Monitoring**: Easy to log and monitor via system tools
5. **Flexibility**: Can run at specific times when server load is low

## Support

For issues or feature requests, contact Shah Jalal.

## Changelog

### Version 2.2.0
- **MAJOR**: Asynchronous Job System - Non-blocking API endpoints with instant responses
- **Job Queue Management**: Real-time progress tracking with job IDs
- **Enhanced Monitoring**: Live progress percentage, batch tracking, and time estimates
- **Background Processing**: Jobs run independently without blocking server resources
- **No More Timeouts**: Eliminates "partial_timeout" issues for large datasets
- **New Endpoints**: Job status, job listing, and enhanced monitoring
- **Server Performance**: Improved resource utilization and memory management
- **Enhanced Logging**: Detailed job logs with timestamp and progress tracking

### Version 2.1.0
- **WooCommerce Compatibility**: Full HPOS and WooCommerce Blocks compatibility
- **Feature Declarations**: Properly declared WooCommerce feature compatibility
- **Enhanced Headers**: Added WooCommerce-specific plugin headers
- **Multisite Support**: Improved WooCommerce detection for multisite installations
- **Activation Checks**: Better WooCommerce dependency validation
- **Admin Interface**: Added compatibility status display
- **Standards Compliance**: Follows all WooCommerce coding standards

### Version 2.0.0
- **BREAKING CHANGE**: Removed WordPress built-in cron scheduling
- Added REST API endpoints for cleanup and stats
- Added comprehensive statistics tracking
- Added API key authentication system
- Added detailed admin UI with statistics display
- Added execution time tracking
- Added variation count tracking
- Enhanced logging with detailed stats
- Updated admin interface with better organization

### Version 1.1
- Added automatic daily product cleanup
- Separate queries for brazyliany and non-brazyliany categories
- Background deletion of products and attachments
- Logging functionality
- Manual cleanup trigger

### Version 1.0
- Initial release
- Manual image deletion with AJAX progress bar
