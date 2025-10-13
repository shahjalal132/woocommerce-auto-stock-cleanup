# cURL Authentication Fix

## Problem
API authentication works in Postman but fails with cURL returning:
```json
{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}
```

## Root Cause
Different HTTP clients (cURL vs Postman) may send custom headers differently, and PHP's `$_SERVER` array doesn't always capture them consistently.

## Solution Applied (v2.1.1)

Enhanced the `rest_permission_check()` method to try multiple ways of retrieving the API key:

1. **$_SERVER array** - Standard PHP approach
2. **getallheaders()** - More reliable for custom headers
3. **apache_request_headers()** - Apache-specific approach
4. **Case-insensitive matching** - Handles X-API-Key, X-Api-Key, x-api-key

## Testing After Fix

### Test 1: Basic cURL (Your Command)
```bash
curl --location --request POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     --header 'X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg'
```

### Test 2: Alternative Header Format
```bash
curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H 'X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg'
```

### Test 3: Lowercase Header
```bash
curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H 'x-api-key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg'
```

### Test 4: With Content-Type
```bash
curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H 'X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg' \
     -H 'Content-Type: application/json'
```

## If Still Not Working

### Check 1: Verify API Key is Set
```bash
# This should work (no auth required)
curl 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/stats'
```

If this works, the API is accessible. If the cleanup endpoint still fails, try:

### Check 2: Test with Simple Header
```bash
curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H 'X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg' \
     -v  # Verbose output to see what's being sent
```

### Check 3: Server Configuration
Some servers strip custom headers. Check your nginx/Apache config:

**For Nginx**, add to your config:
```nginx
location / {
    # Pass custom headers
    proxy_pass_header X-API-Key;
    fastcgi_pass_header X-API-Key;
}
```

**For Apache**, ensure mod_headers is enabled:
```bash
sudo a2enmod headers
sudo systemctl reload apache2
```

### Check 4: PHP-FPM Configuration
If using PHP-FPM, custom headers might be stripped. Add to your pool config:

`/etc/php/8.1/fpm/pool.d/www.conf`:
```ini
; Pass environment variables with HTTP_ prefix
clear_env = no
```

Then restart PHP-FPM:
```bash
sudo systemctl restart php8.1-fpm
```

## Debugging

### Enable WordPress Debug Mode
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Check Debug Log
```bash
tail -f /srv/http/wholesaler/wp-content/debug.log
```

### Manual Header Check Script
Create a test endpoint to see what headers are being received:

`test-headers.php` in your WordPress root:
```php
<?php
header('Content-Type: application/json');

$response = [
    'server_vars' => [
        'HTTP_X_API_KEY' => $_SERVER['HTTP_X_API_KEY'] ?? 'not set',
        'all_http_headers' => []
    ]
];

// Get all HTTP headers
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $response['server_vars']['all_http_headers'][$key] = $value;
    }
}

// Try getallheaders
if (function_exists('getallheaders')) {
    $response['getallheaders'] = getallheaders();
}

// Try apache_request_headers
if (function_exists('apache_request_headers')) {
    $response['apache_request_headers'] = apache_request_headers();
}

echo json_encode($response, JSON_PRETTY_PRINT);
```

Access it:
```bash
curl 'https://kobiecy-akcent.pl/test-headers.php' \
     -H 'X-API-Key: test123' \
     -H 'Custom-Header: value'
```

## Working cURL Command for Cron

Once fixed, your crontab entry should be:

```bash
# Daily at 2 AM
0 2 * * * /usr/bin/curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' -H 'X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg' >> /var/log/product-cleanup.log 2>&1
```

## Alternative: Use Authorization Header

If custom headers continue to be problematic, we can use the standard Authorization header:

```bash
curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H 'Authorization: Bearer e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg'
```

Let me know if you need this alternative implementation.

## Quick Fix Test

After updating the plugin, try this immediately:

```bash
# Method 1: Standard
curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H 'X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg'

# Method 2: With quotes (sometimes helps)
curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H "X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg"

# Method 3: Full path to curl
/usr/bin/curl -X POST 'https://kobiecy-akcent.pl/wp-json/delete-images/v1/cleanup' \
     -H 'X-API-Key: e0SZHqqFzv6fhzvzvHjV7G3p4kbkBOg'
```

The enhanced permission check now handles all these variations automatically!
