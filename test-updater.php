<?php
/**
 * Test Updater Script
 * 
 * Upload this to your site's root directory and visit: https://yoursite.com/test-updater.php
 * Then DELETE this file when done testing!
 */

// Load WordPress
require_once('wp-load.php');

// Must be admin
if (!current_user_can('manage_options')) {
    die('Must be logged in as admin');
}

echo '<h1>Meta CAPI Update Checker Debug</h1>';
echo '<style>body { font-family: monospace; padding: 20px; } pre { background: #f0f0f1; padding: 10px; border-radius: 4px; }</style>';

// Current version
echo '<h2>1. Current Plugin Version</h2>';
echo '<pre>Version: ' . (defined('META_CAPI_VERSION') ? META_CAPI_VERSION : 'NOT FOUND') . '</pre>';

// Check GitHub API
echo '<h2>2. GitHub API Response</h2>';
$response = wp_remote_get('https://api.github.com/repos/wpbooster-cloud/meta-conversions-api/releases/latest');
if (is_wp_error($response)) {
    echo '<pre style="color: red;">ERROR: ' . $response->get_error_message() . '</pre>';
} else {
    $release = json_decode(wp_remote_retrieve_body($response));
    echo '<pre>';
    echo 'Tag Name: ' . ($release->tag_name ?? 'NOT FOUND') . "\n";
    echo 'Version (without v): ' . ltrim($release->tag_name ?? '', 'v') . "\n";
    echo 'Published: ' . ($release->published_at ?? 'NOT FOUND') . "\n";
    echo 'Download URL: ' . ($release->zipball_url ?? 'NOT FOUND') . "\n";
    echo '</pre>';
}

// Check cached update info
echo '<h2>3. Cached Update Info</h2>';
$cached = get_transient('meta_capi_update_info');
if ($cached) {
    echo '<pre>' . print_r($cached, true) . '</pre>';
} else {
    echo '<pre>No cache found (this is normal after forcing update check)</pre>';
}

// Check WordPress update transient
echo '<h2>4. WordPress Update Transient</h2>';
$update_plugins = get_site_transient('update_plugins');
if (isset($update_plugins->response['meta-conversions-api/meta-conversions-api.php'])) {
    echo '<pre style="color: green;">✅ UPDATE AVAILABLE!</pre>';
    echo '<pre>' . print_r($update_plugins->response['meta-conversions-api/meta-conversions-api.php'], true) . '</pre>';
} else {
    echo '<pre style="color: orange;">⚠️  No update detected in WordPress transient</pre>';
}

// Test version comparison
echo '<h2>5. Version Comparison</h2>';
$current = META_CAPI_VERSION ?? '0.0.0';
$latest = ltrim($release->tag_name ?? 'v0.0.0', 'v');
$comparison = version_compare($current, $latest, '<');
echo '<pre>';
echo 'Current: ' . $current . "\n";
echo 'Latest:  ' . $latest . "\n";
echo 'Update needed? ' . ($comparison ? 'YES ✅' : 'NO ❌') . "\n";
echo '</pre>';

// Manual trigger
echo '<h2>6. Force Update Check</h2>';
echo '<a href="?force=1" style="display: inline-block; background: #2271b1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Force Check Now</a>';

if (isset($_GET['force'])) {
    delete_transient('meta_capi_update_info');
    delete_site_transient('update_plugins');
    wp_update_plugins();
    echo '<pre style="color: green;">✅ Caches cleared and update check triggered. Refresh page to see results.</pre>';
}

echo '<hr>';
echo '<p><strong>⚠️  DELETE THIS FILE AFTER TESTING!</strong></p>';

