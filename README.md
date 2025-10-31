# Meta Pixel & Conversions API for WordPress

A complete Meta tracking solution combining Pixel (browser-side) and Conversions API (server-side) for accurate event tracking. Supports page views, Elementor Pro forms, and WooCommerce with automatic event deduplication.

## Features

- **Dual Tracking**: Meta Pixel (browser-side) + Conversions API (server-side) for maximum accuracy
- **WooCommerce Integration**: Full event tracking for ViewContent, AddToCart, InitiateCheckout, and Purchase
- **Page View Tracking**: Automatically sends PageView events via both Pixel and CAPI
- **Elementor Pro Integration**: Tracks form submissions as Lead events
- **Event Deduplication**: Automatically prevents duplicate events between Pixel and CAPI
- **Privacy-Compliant**: Properly hashes PII data according to Facebook requirements
- **Auto-Config Control**: Disable Facebook's automatic event tracking for cleaner data
- **Debug Logging**: Optional logging with automatic management (10MB cap, 30-day retention)
- **Test Mode**: Support for Facebook Test Event Code
- **Extensible**: Hooks and filters for custom implementations

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Elementor Pro (optional, for form tracking)
- WooCommerce (optional, for eCommerce tracking)
- Facebook Dataset ID (Pixel ID)
- Facebook Conversions API Access Token

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/wpbooster-cloud/meta-conversions-api/releases)
2. Upload the ZIP file via WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings → Meta CAPI
5. Enter your Facebook Dataset ID and Access Token
6. Configure tracking settings

### Automatic Updates

The plugin automatically checks for updates from GitHub weekly. When a new version is available:
- You'll see an update notification in your WordPress admin dashboard
- Click "Update" to install the latest version automatically
- No manual download required!

## Configuration

### Getting Your Credentials

1. **Dataset ID**: 
   - Go to [Facebook Events Manager](https://business.facebook.com/events_manager2)
   - Select your pixel
   - The Dataset ID is displayed at the top (15-16 digit number)

2. **Access Token**:
   - In Events Manager, go to Settings → Conversions API
   - Click "Generate Access Token"
   - Copy the token and paste it into the plugin settings

3. **Test Event Code** (Optional):
   - In Events Manager, go to Test Events tab
   - Copy the Test Event Code
   - Use this to verify events are being sent correctly

### Plugin Settings

- **Enable Meta Pixel Injection**: Automatically inject Meta Pixel for browser-side tracking
- **Enable Page View Tracking**: Track all page views via Pixel + CAPI
- **Enable Form Submission Tracking**: Track Elementor Pro form submissions as Lead events
- **Enable WooCommerce Tracking**: Track ViewContent, AddToCart, InitiateCheckout, and Purchase events
- **Disable Auto-Config**: Prevent Facebook's automatic event tracking (recommended)
- **Enable Debug Logging**: Log all API requests (for troubleshooting only)

## Usage

### Automatic Tracking

Once configured, the plugin automatically tracks:
- Page views on all public pages (via Pixel + CAPI)
- Elementor Pro form submissions as Lead events
- WooCommerce product views, add to cart, checkout initiation, and purchases (via Pixel + CAPI)

### Manual Event Tracking

You can also track custom events programmatically:

```php
// Track a custom event
if (function_exists('meta_capi')) {
    $tracking = meta_capi()->tracking;
    
    $tracking->track_custom_event('Purchase', [
        'currency' => 'USD',
        'value' => 99.99,
    ], [
        'email' => 'customer@example.com',
    ]);
}
```

### Track Lead Event Manually

```php
// Track a lead conversion
do_action('meta_capi_track_lead', [
    'email' => 'lead@example.com',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'phone' => '+1234567890',
], [
    'lead_source' => 'website',
    'interest' => 'product_demo',
]);
```

## Filters and Hooks

### Skip Page View Tracking

```php
// Skip tracking on specific pages
add_filter('meta_capi_skip_page_view', function($skip) {
    if (is_page('thank-you')) {
        return true;
    }
    return $skip;
});
```

### Modify Page View Event Data

```php
// Add custom data to page view events
add_filter('meta_capi_page_view_event_data', function($event_data) {
    $event_data['custom_data']['custom_field'] = 'custom_value';
    return $event_data;
});
```

### Modify Form Submission Event Data

```php
// Modify form submission events
add_filter('meta_capi_form_submission_event_data', function($event_data, $record, $fields) {
    // Add custom logic
    return $event_data;
}, 10, 3);
```

### Skip Admin Tracking

```php
// Allow tracking for logged-in admins
add_filter('meta_capi_skip_admin_tracking', '__return_false');
```

## Data Privacy

The plugin follows Facebook's best practices for data privacy:

- All PII (Personally Identifiable Information) is hashed using SHA-256 before sending
- Hashed data includes: email, phone, first name, last name, city, state, zip, country
- IP addresses and user agents are sent unhashed (as required by Facebook)
- Facebook cookies (_fbp and _fbc) are included for better attribution

## Troubleshooting

### Events Not Showing in Facebook

1. Check that your Dataset ID and Access Token are correct
2. Enable Debug Logging in Settings → Meta CAPI → Tools & Logs
3. View logs directly in the Tools & Logs page (no FTP required)
4. Use Test Event Code to verify events in Facebook Events Manager
5. Click "Send Test Event" button in plugin settings
6. Wait 1-2 minutes for Facebook to process events

### Elementor Forms Not Tracking

1. Ensure Elementor Pro is installed and activated
2. Check that "Enable Form Submission Tracking" is enabled in settings
3. Check debug logs for error messages

### Debug Logging

When debug logging is enabled, logs are stored in:
```
/wp-content/uploads/meta-capi-logs/meta-capi-YYYY-MM-DD.log
```

**Automatic Log Management:**
- Log files are capped at 10MB per file
- Files automatically rotate when the size limit is reached
- Old logs are automatically deleted after 30 days
- Daily cleanup runs automatically in the background via WP Cron
- View and download logs directly from Tools & Logs page (no FTP required)

**Important:** Remember to disable debug logging once troubleshooting is complete.

## Security

- All settings are properly sanitized and validated
- Access tokens are stored securely in WordPress options
- Nonce verification for all form submissions
- Log directory is protected with .htaccess
- Follows WordPress coding standards and security best practices

## Support

For issues, questions, or feature requests, please contact support or visit the plugin documentation.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full version history.

### 2.0.0 (Development)
- **NEW**: Meta Pixel integration for browser-side tracking
- **NEW**: Full WooCommerce event tracking (ViewContent, AddToCart, InitiateCheckout, Purchase)
- **NEW**: Automatic event deduplication between Pixel and CAPI
- **NEW**: Purchase event timing control (order placed vs. payment confirmed)
- **NEW**: Auto-config disable option for cleaner Facebook tracking
- **IMPROVED**: Split documentation into Setup Guide and Troubleshooting tabs
- **IMPROVED**: Enhanced system status with WooCommerce detection

### 1.0.0
- Initial release
- Page view tracking via Conversions API
- Elementor Pro form submission tracking
- Admin settings page with tabbed navigation
- Debug logging with automatic management
- Test event functionality
- System status dashboard
- Automatic updates from GitHub

## License

GPL v2 or later

## Credits

Developed by WP Booster

