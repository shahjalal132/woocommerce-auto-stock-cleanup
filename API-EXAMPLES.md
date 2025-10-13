# API Usage Examples (v2.2.0 - Asynchronous Jobs)

## Quick Start

### 1. Generate API Key
1. Login to WordPress admin
2. Go to **Tools → WooCommerce Auto Stock Cleanup**
3. Click **Generate API Key**
4. Copy the generated key

### 2. New Asynchronous Workflow (Recommended)

#### Create Cleanup Job (Instant Response)
```bash
# Replace YOUR_SITE_URL and YOUR_API_KEY
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: YOUR_API_KEY" \
     -H "Content-Type: application/json"
```

**Instant Response (202 Accepted):**
```json
{
  "success": true,
  "job_id": "cleanup_12345678-1234-1234-1234-123456789abc",
  "status": "queued",
  "message": "Cleanup job created successfully. Use the job ID to check progress.",
  "endpoints": {
    "status": "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/cleanup_12345678-1234-1234-1234-123456789abc",
    "all_jobs": "https://kobiecy-akcent.pl/wp-json/delete-images/v1/jobs"
  }
}
```

#### Monitor Job Progress
```bash
# Check job status (no auth needed)
JOB_ID="cleanup_12345678-1234-1234-1234-123456789abc"
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/$JOB_ID"
```

**Progress Response:**
```json
{
  "success": true,
  "job": {
    "id": "cleanup_12345678-1234-1234-1234-123456789abc",
    "status": "running",
    "progress": {
      "percentage": 25.5,
      "current_batch": 10,
      "total_batches": 40,
      "products_deleted": 150,
      "processing_stage": "deleting_non_brazyliany"
    },
    "runtime": "3 minutes",
    "estimated_remaining": "8 minutes"
  }
}
```

#### List All Jobs
```bash
# View all recent jobs (requires API key)
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/jobs" \
     -H "X-API-Key: YOUR_API_KEY"
```

## Automated Monitoring Examples

### Complete Job Workflow Script
```bash
#!/bin/bash
SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"

# 1. Start cleanup job
echo "Starting cleanup job..."
RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: $API_KEY")

JOB_ID=$(echo $RESPONSE | jq -r '.job_id')
echo "Job ID: $JOB_ID"

# 2. Monitor progress
while true; do
    STATUS=$(curl -s "$SITE_URL/wp-json/delete-images/v1/job/$JOB_ID")
    
    CURRENT_STATUS=$(echo $STATUS | jq -r '.job.status')
    PERCENTAGE=$(echo $STATUS | jq -r '.job.progress.percentage // 0')
    DELETED=$(echo $STATUS | jq -r '.job.progress.products_deleted // 0')
    
    echo "$(date): $CURRENT_STATUS - $PERCENTAGE% - Deleted: $DELETED products"
    
    if [ "$CURRENT_STATUS" = "completed" ] || [ "$CURRENT_STATUS" = "failed" ]; then
        break
    fi
    
    sleep 30
done

# 3. Show final results
echo "Final results:"
curl -s "$SITE_URL/wp-json/delete-images/v1/job/$JOB_ID" | jq '.job.stats'
```

## Cron Job Examples

### Simple Async Cleanup (Recommended)
```bash
# Start cleanup and exit immediately (non-blocking)
0 2 * * * curl -s -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" > /dev/null 2>&1
```

### Monitored Cleanup with Logging
```bash
#!/bin/bash
# /usr/local/bin/cleanup-with-monitoring.sh

SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"
LOG_FILE="/var/log/product-cleanup.log"

echo "$(date): Starting cleanup job" >> $LOG_FILE

# Create job
JOB_RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" -H "X-API-Key: $API_KEY")
JOB_ID=$(echo $JOB_RESPONSE | jq -r '.job_id')

if [ "$JOB_ID" = "null" ]; then
    echo "$(date): Failed to create job" >> $LOG_FILE
    exit 1
fi

echo "$(date): Job $JOB_ID created" >> $LOG_FILE

# Monitor until completion (with timeout)
TIMEOUT=3600  # 1 hour timeout
START_TIME=$(date +%s)

while true; do
    CURRENT_TIME=$(date +%s)
    if [ $((CURRENT_TIME - START_TIME)) -gt $TIMEOUT ]; then
        echo "$(date): Job monitoring timed out after 1 hour" >> $LOG_FILE
        break
    fi
    
    JOB_STATUS=$(curl -s "$SITE_URL/wp-json/delete-images/v1/job/$JOB_ID")
    STATUS=$(echo $JOB_STATUS | jq -r '.job.status')
    
    if [ "$STATUS" = "completed" ]; then
        DELETED=$(echo $JOB_STATUS | jq -r '.job.stats.products_deleted')
        echo "$(date): Job completed - $DELETED products deleted" >> $LOG_FILE
        break
    elif [ "$STATUS" = "failed" ]; then
        ERROR=$(echo $JOB_STATUS | jq -r '.job.error')
        echo "$(date): Job failed - $ERROR" >> $LOG_FILE
        break
    fi
    
    sleep 60  # Check every minute
done
```

**Crontab entry:**
```bash
0 2 * * * /usr/local/bin/cleanup-with-monitoring.sh
```

## API Endpoint Reference

### 1. Create Cleanup Job
- **URL**: `POST /wp-json/delete-images/v1/cleanup`
- **Auth**: API Key required (`X-API-Key` header)
- **Response**: `202 Accepted` with job ID
- **Description**: Creates background job, returns immediately

### 2. Get Job Status
- **URL**: `GET /wp-json/delete-images/v1/job/{job_id}`
- **Auth**: None (public endpoint)
- **Response**: Job details with progress
- **Description**: Monitor job progress in real-time

### 3. List All Jobs
- **URL**: `GET /wp-json/delete-images/v1/jobs`
- **Auth**: API Key required
- **Response**: Array of recent jobs (last 20)
- **Description**: View all job history

### 4. Legacy Stats (Unchanged)
- **URL**: `GET /wp-json/delete-images/v1/stats`
- **Auth**: None (public endpoint)
- **Response**: Last completed cleanup statistics
- **Description**: Backward compatibility

## Job Status Values

| Status | Description | Typical Duration |
|--------|-------------|------------------|
| `queued` | Waiting to start | 1-5 seconds |
| `running` | Currently processing | 5-30 minutes |
| `completed` | Successfully finished | - |
| `failed` | Error occurred | - |

## Progress Tracking

### Real-time Metrics Available:
- **percentage**: Overall completion (0-100%)
- **current_batch** / **total_batches**: Batch progress
- **products_deleted**: Products removed so far
- **processing_stage**: Current operation stage
- **runtime**: Time elapsed since start
- **estimated_remaining**: Estimated time left

### Processing Stages:
1. `initializing` - Job setup
2. `scanning` - Counting products
3. `deleting_non_brazyliany` - Processing main products
4. `deleting_brazyliany` - Processing brazyliany products  
5. `completed` - Finished

## Error Handling

### Job Creation Errors
```bash
# Check if job creation failed
RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" -H "X-API-Key: $API_KEY")
SUCCESS=$(echo $RESPONSE | jq -r '.success')

if [ "$SUCCESS" != "true" ]; then
    echo "Job creation failed: $(echo $RESPONSE | jq -r '.error')"
    exit 1
fi
```

### Job Processing Errors
```bash
# Check job status for errors
JOB_STATUS=$(curl -s "$SITE_URL/wp-json/delete-images/v1/job/$JOB_ID")
STATUS=$(echo $JOB_STATUS | jq -r '.job.status')

if [ "$STATUS" = "failed" ]; then
    ERROR=$(echo $JOB_STATUS | jq -r '.job.error')
    echo "Job failed: $ERROR"
    
    # Check logs for more details
    echo $JOB_STATUS | jq -r '.job.logs[] | "\(.timestamp): \(.message)"'
fi
```

## Advanced Examples

### Multi-site Monitoring
```bash
#!/bin/bash
# Monitor cleanup jobs across multiple sites

SITES=(
    "https://site1.com"
    "https://site2.com" 
    "https://site3.com"
)
API_KEY="YOUR_API_KEY"

for SITE in "${SITES[@]}"; do
    echo "=== $SITE ==="
    
    # Start job
    JOB_RESPONSE=$(curl -s -X POST "$SITE/wp-json/delete-images/v1/cleanup" -H "X-API-Key: $API_KEY")
    JOB_ID=$(echo $JOB_RESPONSE | jq -r '.job_id')
    
    echo "Job started: $JOB_ID"
    
    # Store job ID for monitoring
    echo "$SITE:$JOB_ID" >> /tmp/cleanup-jobs.txt
done

# Monitor all jobs
while [ -s /tmp/cleanup-jobs.txt ]; do
    while read -r line; do
        SITE=$(echo $line | cut -d: -f1)
        JOB_ID=$(echo $line | cut -d: -f2)
        
        STATUS=$(curl -s "$SITE/wp-json/delete-images/v1/job/$JOB_ID" | jq -r '.job.status')
        
        if [ "$STATUS" = "completed" ] || [ "$STATUS" = "failed" ]; then
            echo "$SITE: $STATUS"
            # Remove completed job from monitoring
            grep -v "$line" /tmp/cleanup-jobs.txt > /tmp/cleanup-jobs-tmp.txt
            mv /tmp/cleanup-jobs-tmp.txt /tmp/cleanup-jobs.txt
        fi
    done < /tmp/cleanup-jobs.txt
    
    sleep 30
done

rm -f /tmp/cleanup-jobs.txt
echo "All jobs completed"
```

### Webhook Integration
```bash
#!/bin/bash
# Send webhook notification when job completes

SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"
WEBHOOK_URL="https://your-webhook-service.com/notify"

# Start job
JOB_RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" -H "X-API-Key: $API_KEY")
JOB_ID=$(echo $JOB_RESPONSE | jq -r '.job_id')

# Monitor and notify
while true; do
    JOB_STATUS=$(curl -s "$SITE_URL/wp-json/delete-images/v1/job/$JOB_ID")
    STATUS=$(echo $JOB_STATUS | jq -r '.job.status')
    
    if [ "$STATUS" = "completed" ]; then
        DELETED=$(echo $JOB_STATUS | jq -r '.job.stats.products_deleted')
        
        # Send success webhook
        curl -X POST "$WEBHOOK_URL" \
             -H "Content-Type: application/json" \
             -d "{\"status\": \"success\", \"job_id\": \"$JOB_ID\", \"products_deleted\": $DELETED}"
        break
        
    elif [ "$STATUS" = "failed" ]; then
        ERROR=$(echo $JOB_STATUS | jq -r '.job.error')
        
        # Send failure webhook
        curl -X POST "$WEBHOOK_URL" \
             -H "Content-Type: application/json" \
             -d "{\"status\": \"failed\", \"job_id\": \"$JOB_ID\", \"error\": \"$ERROR\"}"
        break
    fi
    
    sleep 30
done
```

---

**The new asynchronous job system eliminates blocking and timeout issues!** 🚀
