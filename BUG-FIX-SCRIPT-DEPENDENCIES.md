# Bug Fix: Script Dependencies

## Issue Verified ✅

**Bug:** `woocommerce-events.js` was missing `jquery` dependency and could potentially load without `meta-capi-pixel` being available.

## Problems Found

1. **Missing jQuery Dependency**
   - `woocommerce-events.js` uses jQuery (`(function($) { ... })(jQuery)`)
   - Dependency array only had `['meta-capi-pixel']`
   - Missing `'jquery'` dependency could cause script to load before jQuery

2. **Potential meta_capi_config Access Issue**
   - `meta_capi_config` is localized to `'meta-capi-pixel'` script handle
   - If `meta-capi-pixel` doesn't load (e.g., `$load_pixel` is false), `meta_capi_config` won't be available
   - However, current `woocommerce-events.js` doesn't directly reference `meta_capi_config`
   - The dependency on `meta-capi-pixel` ensures it loads first, making `meta_capi_config` available

## Fix Applied

**File:** `includes/class-meta-capi-scripts.php` (line 312)

**Before:**
```php
wp_enqueue_script(
    'meta-capi-wc-events',
    META_CAPI_PLUGIN_URL . 'assets/js/woocommerce-events' . $suffix . '.js',
    ['meta-capi-pixel'], // Depends on pixel helper
    META_CAPI_VERSION,
    true
);
```

**After:**
```php
wp_enqueue_script(
    'meta-capi-wc-events',
    META_CAPI_PLUGIN_URL . 'assets/js/woocommerce-events' . $suffix . '.js',
    ['jquery', 'meta-capi-pixel'], // Depends on jQuery and pixel helper
    META_CAPI_VERSION,
    true
);
```

## Verification

✅ **jQuery dependency added** - Script will now wait for jQuery to load
✅ **meta-capi-pixel dependency maintained** - Ensures `meta_capi_config` is available
✅ **Script loading logic** - If `meta-capi-pixel` doesn't load, WordPress won't load `woocommerce-events` (dependency check)

## Notes

- Current `woocommerce-events.js` doesn't directly use `meta_capi_config`
- Event ID coordination happens via:
  1. AJAX fragments (for AJAX add-to-cart)
  2. Pre-generated event IDs in localized `metaCAPIWooCommerceData`
- If future code needs `meta_capi_config.ajax_url`, it will now be available due to dependency

