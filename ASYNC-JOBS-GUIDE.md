# Asynchronous Job System Guide

## Overview

Version 2.2.0 introduces a **fully asynchronous job system** that solves the blocking API and timeout issues. Now when you hit the cleanup endpoint, you get an instant response with a job ID, and the actual processing happens in the background.

## 🚀 **New Workflow**

### 1. **Start Job (Instant Response)**
```bash
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup" \
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
    "status": "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/cleanup_12345678-1234-1234-1234-123456789abc",
    "all_jobs": "https://kobiecy-akcent.pl/wp-json/delete-images/v1/jobs"
  }
}
```

### 2. **Monitor Progress**
```bash
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/cleanup_12345678-1234-1234-1234-123456789abc"
```

**Progress Response:**
```json
{
  "success": true,
  "job": {
    "id": "cleanup_12345678-1234-1234-1234-123456789abc",
    "status": "running",
    "created_at": "2025-10-13 10:15:00",
    "started_at": "2025-10-13 10:15:05", 
    "completed_at": null,
    "progress": {
      "current_batch": 5,
      "total_batches": 40,
      "products_processed": 250,
      "products_deleted": 180,
      "images_deleted": 520,
      "variations_deleted": 380,
      "processing_stage": "deleting_non_brazyliany",
      "percentage": 12.5
    },
    "runtime": "2 minutes",
    "estimated_remaining": "14 minutes",
    "logs": [
      {
        "timestamp": "2025-10-13 10:15:05",
        "level": "info", 
        "message": "Starting job processing"
      },
      {
        "timestamp": "2025-10-13 10:15:10",
        "level": "info",
        "message": "Found 1903 non-brazyliany and 0 brazyliany products to delete"
      }
    ]
  }
}
```

### 3. **Job Completion**
```json
{
  "success": true,
  "job": {
    "id": "cleanup_12345678-1234-1234-1234-123456789abc",
    "status": "completed",
    "created_at": "2025-10-13 10:15:00",
    "started_at": "2025-10-13 10:15:05",
    "completed_at": "2025-10-13 10:35:22",
    "progress": {
      "percentage": 100,
      "processing_stage": "completed"
    },
    "stats": {
      "total_scanned": 15698,
      "non_brazyliany_found": 1903,
      "brazyliany_found": 0,
      "products_deleted": 1903,
      "images_deleted": 5432,
      "variations_deleted": 3890,
      "execution_time": "284.56 seconds"
    }
  }
}
```

## 🔧 **New API Endpoints**

### 1. **Create Cleanup Job** (Replaces blocking cleanup)
- **URL**: `POST /wp-json/delete-images/v1/cleanup`
- **Auth**: API Key required
- **Response**: Instant (202 Accepted) with job ID
- **Processing**: Happens in background

### 2. **Get Job Status**
- **URL**: `GET /wp-json/delete-images/v1/job/{job_id}`
- **Auth**: None (public endpoint)
- **Response**: Current job status and progress

### 3. **List All Jobs**  
- **URL**: `GET /wp-json/delete-images/v1/jobs`
- **Auth**: API Key required
- **Response**: Last 20 jobs with status

### 4. **Legacy Stats Endpoint** (Unchanged)
- **URL**: `GET /wp-json/delete-images/v1/stats`
- **Auth**: None (public endpoint)
- **Response**: Last completed cleanup stats

## 📊 **Job States**

| Status | Description | Next State |
|--------|-------------|------------|
| `queued` | Job created, waiting to start | `running` |
| `running` | Currently processing | `completed` / `failed` |
| `completed` | Successfully finished | - |
| `failed` | Error occurred | - |
| `cancelled` | Manually cancelled | - |

## 📈 **Progress Tracking**

### Processing Stages:
1. **`initializing`** - Job setup
2. **`scanning`** - Counting products to delete
3. **`deleting_non_brazyliany`** - Processing non-brazyliany products
4. **`deleting_brazyliany`** - Processing brazyliany products
5. **`completed`** - Finished

### Progress Metrics:
- **`current_batch`** / **`total_batches`** - Batch progress
- **`percentage`** - Completion percentage
- **`products_processed`** - Products handled so far
- **`runtime`** - Time elapsed since start
- **`estimated_remaining`** - Estimated time left

## 🔄 **Automated Monitoring Script**

```bash
#!/bin/bash

SITE_URL="https://kobiecy-akcent.pl"
API_KEY="YOUR_API_KEY"

# Start cleanup job
echo "Starting cleanup job..."
RESPONSE=$(curl -s -X POST "$SITE_URL/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: $API_KEY")

JOB_ID=$(echo $RESPONSE | jq -r '.job_id')

if [ "$JOB_ID" = "null" ]; then
    echo "Failed to create job"
    exit 1
fi

echo "Job created: $JOB_ID"

# Monitor progress
while true; do
    STATUS_RESPONSE=$(curl -s "$SITE_URL/wp-json/delete-images/v1/job/$JOB_ID")
    
    STATUS=$(echo $STATUS_RESPONSE | jq -r '.job.status')
    PERCENTAGE=$(echo $STATUS_RESPONSE | jq -r '.job.progress.percentage // 0')
    STAGE=$(echo $STATUS_RESPONSE | jq -r '.job.progress.processing_stage')
    DELETED=$(echo $STATUS_RESPONSE | jq -r '.job.progress.products_deleted // 0')
    
    echo "$(date): Status: $STATUS | Progress: $PERCENTAGE% | Stage: $STAGE | Deleted: $DELETED"
    
    if [ "$STATUS" = "completed" ] || [ "$STATUS" = "failed" ]; then
        echo "Job finished with status: $STATUS"
        break
    fi
    
    sleep 30  # Check every 30 seconds
done

# Show final results
echo "Final job details:"
curl -s "$SITE_URL/wp-json/delete-images/v1/job/$JOB_ID" | jq '.job.stats'
```

## 🗂️ **Cron Integration**

### Simple Daily Cleanup
```bash
# Creates job and exits immediately
0 2 * * * curl -s -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" > /dev/null
```

### Advanced Monitoring Cron
```bash
# Run the monitoring script daily
0 2 * * * /path/to/cleanup-monitor.sh >> /var/log/cleanup-jobs.log 2>&1
```

## 🔍 **Monitoring Multiple Jobs**

### List Recent Jobs
```bash
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/jobs" \
     -H "X-API-Key: YOUR_API_KEY"
```

**Response:**
```json
{
  "success": true,
  "jobs": {
    "cleanup_12345678-1234-1234-1234-123456789abc": {
      "status": "completed",
      "created_at": "2025-10-13 10:15:00",
      "progress": {"percentage": 100}
    },
    "cleanup_87654321-4321-4321-4321-cba987654321": {
      "status": "running", 
      "created_at": "2025-10-13 12:30:00",
      "progress": {"percentage": 45}
    }
  },
  "total": 2
}
```

## ⚡ **Performance Benefits**

### Before (v2.1.0):
❌ **Blocking**: API call waits 5+ minutes  
❌ **Timeouts**: Returns "partial_timeout"  
❌ **Server Load**: Ties up web server resources  
❌ **No Progress**: Can't see what's happening  

### After (v2.2.0):
✅ **Instant Response**: Get job ID in ~100ms  
✅ **No Timeouts**: Background processing  
✅ **Server Friendly**: Non-blocking execution  
✅ **Real-time Progress**: Live status updates  
✅ **Better Monitoring**: Detailed logs and metrics  

## 🛠️ **Troubleshooting**

### Job Stuck in "queued" Status
```bash
# Check if background processing is working
curl -s "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/$JOB_ID" | jq '.job.logs'

# Manual trigger (if needed)
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/process-job/$JOB_ID" \
     -H "X-API-Key: YOUR_API_KEY"
```

### Job Failed
```bash
# Check error details
curl -s "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/$JOB_ID" | jq '.job.error'

# Check logs
curl -s "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/$JOB_ID" | jq '.job.logs'
```

### Background Processing Not Working
The system uses two methods for background processing:

1. **HTTP Request** (Primary): Non-blocking wp_remote_post()
2. **WordPress Cron** (Fallback): Scheduled 5 seconds later

If both fail, check:
- WordPress cron functionality
- HTTP loopback requests
- Server configuration

## 🔒 **Security**

- Job status endpoint is **public** (read-only)
- Job creation requires **API key**
- Internal processing endpoint has **enhanced security**
- Job data stored in WordPress options (not exposed)

## 📋 **Migration from v2.1.0**

### Old Method (Still Works):
```bash
# This now creates a job and waits for completion
curl -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY"
```

### New Method (Recommended):
```bash
# 1. Create job (instant)
JOB_ID=$(curl -s -X POST "https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" | jq -r '.job_id')

# 2. Monitor progress
curl "https://kobiecy-akcent.pl/wp-json/delete-images/v1/job/$JOB_ID"
```

---

**The new asynchronous system provides a much better experience for handling large product deletions!** 🚀
