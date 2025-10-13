# Batch Processing Guide for Large-Scale Product Deletion

## Overview

When you have 2000+ products to delete, the plugin uses intelligent batch processing to handle the load efficiently without causing server timeouts or memory issues.

## How Batch Processing Works

### Key Features:
- **Batch Size**: 50 products per batch
- **Max Processing Time**: 5 minutes per run  
- **Memory Management**: Automatic cleanup after each batch
- **Timeout Protection**: Graceful handling of time limits
- **Progress Tracking**: Detailed logging and statistics

### Performance Optimizations:
- ✅ Memory limit increased to 512MB
- ✅ Garbage collection after each batch
- ✅ Small delays between batches (0.1s) to prevent server overload
- ✅ Time buffers to ensure graceful completion
- ✅ Automatic recovery from partial runs

## Processing Flow

```
1. Count Total Products (2000+ products)
2. Process Non-Brazyliany Products:
   ├── Batch 1: Products 1-50
   ├── Batch 2: Products 51-100  
   ├── Batch 3: Products 101-150
   └── ... (continue until timeout or completion)
3. Process Brazyliany Products (if time remaining):
   ├── Batch 1: Products 1-50
   ├── Batch 2: Products 51-100
   └── ... (continue until timeout or completion)
4. Log Results & Update Statistics
```

## Monitoring Large Deletions

### 1. Check Current Status
```bash
# Get real-time stats
curl "https://your-site.com/wp-json/delete-images/v1/stats" | jq '.stats'
```

**Example Response:**
```json
{
  "total_scanned": 3500,
  "non_brazyliany_found": 2156,
  "brazyliany_found": 45,
  "products_deleted": 350,
  "images_deleted": 1850,
  "variations_deleted": 890,
  "execution_time": "300.12 seconds",
  "batches_processed": 7,
  "status": "partial_timeout"
}
```

### 2. Monitor Progress Over Multiple Runs

```bash
#!/bin/bash
# Monitor progress script

SITE_URL="https://your-site.com"
API_KEY="YOUR_API_KEY"

echo "=== Monitoring Product Cleanup Progress ==="
echo "$(date): Starting monitoring..."

while true; do
    # Get current stats
    RESPONSE=$(curl -s "$SITE_URL/wp-json/delete-images/v1/stats")
    
    TOTAL_FOUND=$(echo $RESPONSE | jq -r '.stats.non_brazyliany_found + .stats.brazyliany_found')
    DELETED=$(echo $RESPONSE | jq -r '.stats.products_deleted')
    STATUS=$(echo $RESPONSE | jq -r '.stats.status')
    BATCHES=$(echo $RESPONSE | jq -r '.stats.batches_processed')
    
    echo "$(date): Found: $TOTAL_FOUND | Deleted: $DELETED | Batches: $BATCHES | Status: $STATUS"
    
    if [ "$DELETED" -ge "$TOTAL_FOUND" ] && [ "$STATUS" = "completed" ]; then
        echo "$(date): ✅ Cleanup completed successfully!"
        break
    fi
    
    # Wait 30 seconds before next check
    sleep 30
done
```

## Cron Setup for Large Deletions

### Frequent Runs (Every 10 minutes)
For faster completion of large batches:
```bash
*/10 * * * * curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

### Standard Daily Run
```bash
0 2 * * * curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

### Hourly Cleanup (For very active stores)
```bash
0 * * * * curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

## Log Analysis

### View Batch Processing Logs
```bash
tail -f /srv/http/wholesaler/wp-content/delete-images-cleanup.log
```

**Example Log Output:**
```
[2025-10-13 10:30:45] Starting batch deletion of 2156 products...
[2025-10-13 10:30:46] Processing non-brazyliany batch 1 (50 products)
[2025-10-13 10:31:12] Processing non-brazyliany batch 2 (50 products)
[2025-10-13 10:31:38] Processing non-brazyliany batch 3 (50 products)
...
[2025-10-13 10:35:20] Processed 7 non-brazyliany batches
[2025-10-13 10:35:21] Timeout reached, brazyliany products will be processed in next run
[2025-10-13 10:35:22] Cleanup completed. Total time: 300.12 seconds

[2025-10-13 10:35:22] Cleanup Stats:
  - Products Scanned: 3500
  - Non-Brazyliany Found: 2156
  - Brazyliany Found: 45
  - Products Deleted: 350
  - Images Deleted: 1850
  - Variations Deleted: 890
  - Execution Time: 300.12 seconds
```

## Advanced Monitoring Script

### Complete Progress Tracker with Notifications
```bash
#!/bin/bash

SITE_URL="https://your-site.com"
API_KEY="YOUR_API_KEY"
EMAIL="admin@your-site.com"
LOG_FILE="/var/log/cleanup-monitor.log"

# Function to log with timestamp
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S'): $1" | tee -a $LOG_FILE
}

# Function to send notification
send_notification() {
    local subject="$1"
    local message="$2"
    echo "$message" | mail -s "$subject" $EMAIL
}

# Function to run cleanup
run_cleanup() {
    log_message "Starting cleanup process..."
    
    RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" \
         -H "X-API-Key: $API_KEY")
    
    if [ $? -eq 0 ]; then
        DELETED=$(echo $RESPONSE | jq -r '.data.products_deleted')
        BATCHES=$(echo $RESPONSE | jq -r '.data.batches_processed')
        TIME=$(echo $RESPONSE | jq -r '.data.execution_time')
        STATUS=$(echo $RESPONSE | jq -r '.data.status')
        
        log_message "Cleanup result: $DELETED products deleted in $BATCHES batches ($TIME) - Status: $STATUS"
        
        # Send notification for significant deletions
        if [ "$DELETED" -gt 100 ]; then
            send_notification "High Volume Product Cleanup" "Deleted $DELETED products in $BATCHES batches. Time: $TIME. Status: $STATUS"
        fi
        
        return 0
    else
        log_message "ERROR: Failed to run cleanup"
        send_notification "Cleanup Failed" "Product cleanup process failed to execute"
        return 1
    fi
}

# Function to check remaining products
check_remaining() {
    RESPONSE=$(curl -s "$SITE_URL/wp-json/delete-images/v1/stats")
    
    if [ $? -eq 0 ]; then
        NON_BRAZ=$(echo $RESPONSE | jq -r '.stats.non_brazyliany_found // 0')
        BRAZ=$(echo $RESPONSE | jq -r '.stats.brazyliany_found // 0')
        TOTAL_REMAINING=$((NON_BRAZ + BRAZ))
        
        log_message "Remaining products: $TOTAL_REMAINING ($NON_BRAZ non-brazyliany + $BRAZ brazyliany)"
        
        echo $TOTAL_REMAINING
    else
        log_message "ERROR: Failed to check stats"
        echo -1
    fi
}

# Main execution
log_message "=== Starting automated cleanup cycle ==="

INITIAL_REMAINING=$(check_remaining)
if [ "$INITIAL_REMAINING" -eq -1 ]; then
    exit 1
fi

log_message "Initial products to delete: $INITIAL_REMAINING"

if [ "$INITIAL_REMAINING" -gt 0 ]; then
    # Run multiple cleanup cycles for large deletions
    MAX_CYCLES=10
    CYCLE=1
    
    while [ $CYCLE -le $MAX_CYCLES ]; do
        log_message "=== Cycle $CYCLE of $MAX_CYCLES ==="
        
        if run_cleanup; then
            REMAINING=$(check_remaining)
            
            if [ "$REMAINING" -eq 0 ]; then
                log_message "✅ All products cleaned up successfully!"
                send_notification "Cleanup Complete" "All product cleanup completed after $CYCLE cycles"
                break
            elif [ "$REMAINING" -eq "$INITIAL_REMAINING" ]; then
                log_message "⚠️  No progress made in this cycle"
                break
            else
                PROGRESS=$((INITIAL_REMAINING - REMAINING))
                log_message "Progress: $PROGRESS products deleted, $REMAINING remaining"
                
                # Wait 5 minutes before next cycle
                sleep 300
            fi
        else
            log_message "❌ Cleanup failed in cycle $CYCLE"
            break
        fi
        
        CYCLE=$((CYCLE + 1))
    done
    
    if [ $CYCLE -gt $MAX_CYCLES ]; then
        log_message "⚠️  Reached maximum cycles limit"
        send_notification "Cleanup Incomplete" "Cleanup stopped after $MAX_CYCLES cycles. Manual intervention may be needed."
    fi
else
    log_message "ℹ️  No products found for cleanup"
fi

log_message "=== Cleanup cycle completed ==="
```

## Performance Tips

### 1. Optimize Cron Frequency
- **Large initial cleanup**: Every 10-15 minutes until complete
- **Maintenance mode**: Daily or weekly
- **High-traffic sites**: Hourly during off-peak

### 2. Server Configuration
```bash
# Increase PHP limits (in php.ini or wp-config.php)
max_execution_time = 600
memory_limit = 512M
max_input_vars = 3000
```

### 3. Database Optimization
```sql
-- Add indexes for better performance
ALTER TABLE wp_posts ADD INDEX idx_post_type_status (post_type, post_status);
ALTER TABLE wp_postmeta ADD INDEX idx_meta_key_value (meta_key, meta_value(10));
```

## Troubleshooting Large Deletions

### Issue: Process Times Out
**Solution**: Reduce batch size or increase execution time
```php
// In the plugin, modify:
$batch_size = 25; // Reduced from 50
$max_execution_time = 600; // Increased to 10 minutes
```

### Issue: Memory Errors
**Solution**: Increase memory limit and add more cleanup
```php
@ini_set('memory_limit', '1024M');
// Add more frequent garbage collection
```

### Issue: Database Locks
**Solution**: Add delays between batches
```php
usleep(500000); // 0.5 second delay instead of 0.1
```

### Issue: Partial Completion
**Solution**: Run more frequent cron jobs
```bash
# Every 5 minutes until completion
*/5 * * * * /path/to/cleanup-script.sh
```

## Expected Performance

For **2000 products**:
- **Batch size 50**: ~40 batches
- **Processing time**: 5-10 minutes per run
- **Total runs needed**: 8-16 (depending on server performance)
- **Complete in**: 1-3 hours with 10-minute intervals

## Security Considerations

- API rate limiting: The 0.1s delays prevent overwhelming the server
- Memory management: Automatic cleanup prevents memory leaks  
- Graceful timeouts: No forced terminations that could corrupt data
- Transaction safety: Each product deletion is atomic
