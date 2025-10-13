# API Usage Examples

## Quick Start

### 1. Generate API Key
1. Login to WordPress admin
2. Go to **Tools → Delete Images by IDs**
3. Click **Generate API Key**
4. Copy the generated key

### 2. Test the Endpoints

#### Test Cleanup Endpoint
```bash
# Replace YOUR_SITE_URL and YOUR_API_KEY
curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: YOUR_API_KEY" \
     -H "Content-Type: application/json"
```

#### Test Stats Endpoint
```bash
# No authentication needed
curl "https://your-site.com/wp-json/delete-images/v1/stats"
```

## Cron Job Examples

### Daily at 2 AM
```bash
0 2 * * * curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

### Every 6 hours
```bash
0 */6 * * * curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

### Weekly on Monday at 3 AM
```bash
0 3 * * 1 curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

### Every day at midnight with timestamp in log
```bash
0 0 * * * echo "=== Cleanup started at $(date) ===" >> /var/log/product-cleanup.log && curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1
```

## Response Examples

### Successful Cleanup Response
```json
{
  "success": true,
  "data": {
    "total_scanned": 1500,
    "non_brazyliany_found": 25,
    "brazyliany_found": 8,
    "products_deleted": 33,
    "images_deleted": 156,
    "variations_deleted": 98,
    "execution_time": "12.45 seconds",
    "timestamp": "2025-10-13 10:30:45"
  }
}
```

### Stats Response
```json
{
  "success": true,
  "stats": {
    "total_scanned": 1500,
    "non_brazyliany_found": 25,
    "brazyliany_found": 8,
    "products_deleted": 33,
    "images_deleted": 156,
    "variations_deleted": 98,
    "execution_time": "12.45 seconds",
    "timestamp": "2025-10-13 10:30:45"
  },
  "last_cleanup": {
    "date": "2025-10-13 10:30:45",
    "count": 33,
    "product_ids": [123, 456, 789],
    "stats": {
      "total_scanned": 1500,
      "non_brazyliany_found": 25,
      "brazyliany_found": 8,
      "products_deleted": 33,
      "images_deleted": 156,
      "variations_deleted": 98,
      "execution_time": "12.45 seconds"
    }
  }
}
```

### Error Response (Invalid API Key)
```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": {
    "status": 401
  }
}
```

## Advanced Examples

### Cleanup with email notification on completion
```bash
#!/bin/bash
SITE_URL="https://your-site.com"
API_KEY="YOUR_API_KEY"
EMAIL="admin@your-site.com"

# Run cleanup
RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: $API_KEY")

# Extract stats
PRODUCTS_DELETED=$(echo $RESPONSE | jq -r '.data.products_deleted')
IMAGES_DELETED=$(echo $RESPONSE | jq -r '.data.images_deleted')

# Send email
echo "Cleanup completed: $PRODUCTS_DELETED products and $IMAGES_DELETED images deleted." | \
     mail -s "Product Cleanup Report" $EMAIL
```

### Cleanup only if products found
```bash
#!/bin/bash
SITE_URL="https://your-site.com"
API_KEY="YOUR_API_KEY"

# Check stats first
STATS=$(curl -s "$SITE_URL/wp-json/delete-images/v1/stats")
FOUND=$(echo $STATS | jq -r '.stats.non_brazyliany_found + .stats.brazyliany_found')

# Only run cleanup if products found (you'd need to query first, but this is for illustration)
if [ "$FOUND" -gt 0 ]; then
    curl -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" \
         -H "X-API-Key: $API_KEY"
fi
```

### Monitor stats and alert if high deletion count
```bash
#!/bin/bash
SITE_URL="https://your-site.com"
API_KEY="YOUR_API_KEY"
THRESHOLD=100

# Run cleanup
RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: $API_KEY")

PRODUCTS_DELETED=$(echo $RESPONSE | jq -r '.data.products_deleted')

if [ "$PRODUCTS_DELETED" -gt "$THRESHOLD" ]; then
    echo "WARNING: $PRODUCTS_DELETED products deleted (threshold: $THRESHOLD)" | \
         mail -s "High Product Deletion Alert" admin@your-site.com
fi
```

## Testing

### Test API Key is working
```bash
# Should return 401/403 error
curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: WRONG_KEY"

# Should return success
curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: YOUR_CORRECT_KEY"
```

### View current stats without running cleanup
```bash
curl "https://your-site.com/wp-json/delete-images/v1/stats" | jq '.'
```

### Pretty print JSON response
```bash
curl "https://your-site.com/wp-json/delete-images/v1/stats" | jq '.'
```

## Monitoring

### Check cron is running
```bash
# View crontab
crontab -l

# Check if cron service is running
systemctl status cron  # or 'crond' on some systems
```

### View cleanup logs
```bash
# View last 50 lines
tail -n 50 /var/log/product-cleanup.log

# Follow log in real-time
tail -f /var/log/product-cleanup.log

# View WordPress cleanup log
tail -f /srv/http/wholesaler/wp-content/delete-images-cleanup.log
```

### Check last cleanup time
```bash
curl -s "https://your-site.com/wp-json/delete-images/v1/stats" | jq -r '.last_cleanup.date'
```

## Troubleshooting

### Test endpoint is accessible
```bash
curl -I "https://your-site.com/wp-json/delete-images/v1/stats"
# Should return: HTTP/1.1 200 OK
```

### Check API key is set
Login to WordPress admin and check the API Key section shows a key.

### Verify cron job syntax
```bash
# Test cron expression
echo "0 2 * * *" | crontab -
crontab -l
```

### Manual test run
```bash
# Run the cron command manually to see output
curl -X POST "https://your-site.com/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: YOUR_API_KEY" \
     -v  # verbose output
```

