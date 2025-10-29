# Debug Automatic Updates

If updates aren't showing, follow these steps:

## Step 1: Verify GitHub Release Exists
Visit: https://github.com/wpbooster-cloud/meta-conversions-api/releases

- You should see **v1.0.2** listed
- If not, create the release first!

## Step 2: Check What Version Your Site Has
Go to your site → **Plugins** page

- Look for "Meta Conversions API"
- What version does it show? (Should be 1.0.1 or lower)

## Step 3: Test GitHub API Directly
Visit this URL in your browser:
```
https://api.github.com/repos/wpbooster-cloud/meta-conversions-api/releases/latest
```

**Expected response**: JSON with `"tag_name": "v1.0.2"`

If you get an error or `"tag_name": "v1.0.0"`, the release isn't published correctly.

## Step 4: Enable WordPress Debug Mode
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Then force update check again and check `/wp-content/debug.log`

## Step 5: Clear ALL Caches
```php
// Add this to functions.php temporarily, visit any page, then remove it:
delete_transient('meta_capi_update_info');
delete_site_transient('update_plugins');
wp_update_plugins();
```

## Step 6: Check Transients Directly
```php
// Add to functions.php, visit any page:
var_dump(get_transient('meta_capi_update_info'));
var_dump(get_site_transient('update_plugins'));
die();
```

## Common Issues:

### Issue: Site shows v1.0.2 already
- **Solution**: Manually upload v1.0.0 or v1.0.1 to test updates

### Issue: GitHub API returns 404
- **Solution**: Release isn't published - create it

### Issue: Cache won't clear
- **Solution**: Try from different browser or incognito

### Issue: Update shows but won't install
- **Solution**: Check file permissions on `/wp-content/plugins/`

