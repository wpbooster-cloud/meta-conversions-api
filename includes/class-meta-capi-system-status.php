<?php
/**
 * System Status and Diagnostics for Meta Conversions API.
 *
 * Handles environment detection, compatibility checks, and cache testing:
 * - Detects Cloudways hosting
 * - Detects Cloudflare and other CDNs
 * - Tests WooCommerce page caching
 * - Detects active caching plugins
 * - Provides recommendations and warnings
 *
 * @package Meta_Conversions_API
 * @since 2.0.0
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta CAPI System Status Class.
 */
class Meta_CAPI_System_Status {

    /**
     * Meta CAPI Logger instance.
     *
     * @var Meta_CAPI_Logger
     */
    private Meta_CAPI_Logger $logger;

    /**
     * System status cache.
     *
     * @var array<string, mixed>|null
     */
    private ?array $status_cache = null;

    /**
     * Constructor.
     *
     * @param Meta_CAPI_Logger $logger Logger instance.
     */
    public function __construct(Meta_CAPI_Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Get complete system status.
     *
     * @param bool $force_refresh Force refresh cache.
     * @return array<string, mixed> System status array.
     */
    public function get_status(bool $force_refresh = false): array {
        // Return cached status if available.
        if ($this->status_cache !== null && !$force_refresh) {
            return $this->status_cache;
        }

        $this->logger->log('Gathering system status...', 'info');

        $status = [
            'timestamp'       => time(),
            'environment'     => $this->get_environment_info(),
            'hosting'         => $this->detect_hosting(),
            'cdn'             => $this->detect_cdn(),
            'caching_plugins' => $this->detect_caching_plugins(),
            'wordpress'       => $this->get_wordpress_info(),
            'plugin_config'   => $this->get_plugin_config(),
            'warnings'        => [],
            'recommendations' => [],
        ];

        // Generate warnings and recommendations.
        $status['warnings'] = $this->generate_warnings($status);
        $status['recommendations'] = $this->generate_recommendations($status);

        // Cache the status.
        $this->status_cache = $status;

        $this->logger->log('System status gathered', 'info', [
            'warnings_count' => count($status['warnings']),
            'recommendations_count' => count($status['recommendations']),
        ]);

        return $status;
    }

    /**
     * Detect hosting environment.
     *
     * @return array<string, mixed> Hosting information.
     */
    private function detect_hosting(): array {
        $hosting = [
            'provider' => 'Unknown',
            'is_cloudways' => false,
            'is_wpengine' => false,
            'is_kinsta' => false,
            'is_flywheel' => false,
            'detected' => false,
        ];

        // Cloudways detection (multiple methods for reliability).
        $is_cloudways = (
            getenv('CLOUDWAYS') === 'true' ||
            getenv('cw_allowed_ip') !== false ||
            (isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Cloudways') !== false) ||
            (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '.cloudwaysapps.com') !== false) ||
            (isset($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['DOCUMENT_ROOT'], '/cloudways/') !== false) ||
            (isset($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['DOCUMENT_ROOT'], '.cloudwaysapps.com') !== false) ||
            file_exists('/var/www/.cloudways') ||
            defined('CLOUDWAYS_ENV')
        );

        if ($is_cloudways) {
            $hosting['provider'] = 'Cloudways';
            $hosting['is_cloudways'] = true;
            $hosting['detected'] = true;
        }

        // WP Engine detection.
        if (defined('WPE_APIKEY') || (isset($_SERVER['IS_WPE']) && $_SERVER['IS_WPE'])) {
            $hosting['provider'] = 'WP Engine';
            $hosting['is_wpengine'] = true;
            $hosting['detected'] = true;
        }

        // Kinsta detection.
        if (defined('KINSTAMU_VERSION') || getenv('KINSTA_CACHE_ZONE')) {
            $hosting['provider'] = 'Kinsta';
            $hosting['is_kinsta'] = true;
            $hosting['detected'] = true;
        }

        // Flywheel detection.
        if (defined('FLYWHEEL_CONFIG_DIR')) {
            $hosting['provider'] = 'Flywheel';
            $hosting['is_flywheel'] = true;
            $hosting['detected'] = true;
        }

        $this->logger->log('Hosting detected', 'info', $hosting);

        return $hosting;
    }

    /**
     * Detect CDN and caching services.
     *
     * @return array<string, mixed> CDN information.
     */
    private function detect_cdn(): array {
        $cdn = [
            'cloudflare' => false,
            'cloudflare_enterprise' => false,
            'cloudflare_status' => null,
            'other_cdn' => false,
            'cdn_name' => null,
        ];

        // Get headers from homepage.
        $headers = $this->get_site_headers();

        if ($headers) {
            // Cloudflare detection.
            if (isset($headers['cf-ray']) || isset($headers['CF-RAY'])) {
                $cdn['cloudflare'] = true;
                $cdn['cdn_name'] = 'Cloudflare';
                
                // Check cache status.
                if (isset($headers['cf-cache-status'])) {
                    $cdn['cloudflare_status'] = $headers['cf-cache-status'];
                } elseif (isset($headers['CF-Cache-Status'])) {
                    $cdn['cloudflare_status'] = $headers['CF-Cache-Status'];
                }

                // Enterprise detection (has more features).
                if (isset($headers['cf-edge-cache']) || isset($headers['CF-Edge-Cache'])) {
                    $cdn['cloudflare_enterprise'] = true;
                }
            }

            // Other CDNs.
            if (isset($headers['x-amz-cf-id'])) {
                $cdn['other_cdn'] = true;
                $cdn['cdn_name'] = 'Amazon CloudFront';
            } elseif (isset($headers['x-akamai-transformed'])) {
                $cdn['other_cdn'] = true;
                $cdn['cdn_name'] = 'Akamai';
            } elseif (isset($headers['x-fastly-request-id'])) {
                $cdn['other_cdn'] = true;
                $cdn['cdn_name'] = 'Fastly';
            }
        }

        $this->logger->log('CDN detected', 'info', $cdn);

        return $cdn;
    }

    /**
     * Detect active caching plugins.
     *
     * @return array<string, mixed> Caching plugin information.
     */
    private function detect_caching_plugins(): array {
        $caching_plugins = [
            'breeze/breeze.php' => 'Breeze',
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'autoptimize/autoptimize.php' => 'Autoptimize',
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'cache-enabler/cache-enabler.php' => 'Cache Enabler',
            'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer',
        ];

        $active = [];
        foreach ($caching_plugins as $path => $name) {
            if (is_plugin_active($path)) {
                $active[] = $name;
            }
        }

        $result = [
            'detected' => !empty($active),
            'plugins' => $active,
            'count' => count($active),
        ];

        $this->logger->log('Caching plugins detected', 'info', $result);

        return $result;
    }

    /**
     * Get WordPress environment info.
     *
     * @return array<string, mixed> WordPress information.
     */
    private function get_wordpress_info(): array {
        global $wp_version;

        return [
            'version' => $wp_version,
            'multisite' => is_multisite(),
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'memory_limit' => WP_MEMORY_LIMIT,
            'php_version' => PHP_VERSION,
            'woocommerce_active' => class_exists('WooCommerce'),
            'woocommerce_version' => class_exists('WooCommerce') ? WC()->version : null,
            'elementor_pro_active' => defined('ELEMENTOR_PRO_VERSION'),
            'elementor_pro_version' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
        ];
    }

    /**
     * Get environment info (server, PHP, etc.).
     *
     * @return array<string, mixed> Environment information.
     */
    private function get_environment_info(): array {
        return [
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->get_mysql_version(),
            'https' => is_ssl(),
            'home_url' => home_url(),
        ];
    }

    /**
     * Get MySQL version.
     *
     * @return string MySQL version.
     */
    private function get_mysql_version(): string {
        global $wpdb;
        return $wpdb->get_var('SELECT VERSION()') ?: 'Unknown';
    }

    /**
     * Get plugin configuration status.
     *
     * @return array<string, mixed> Plugin configuration.
     */
    private function get_plugin_config(): array {
        $pixel_id = get_option('meta_capi_pixel_id', '');
        $access_token = get_option('meta_capi_access_token', '');

        return [
            'pixel_id_set' => !empty($pixel_id),
            'access_token_set' => !empty($access_token),
            'configured' => !empty($pixel_id) && !empty($access_token),
            'page_view_tracking' => (bool) get_option('meta_capi_enable_page_view', false),
            'form_tracking' => (bool) get_option('meta_capi_enable_form_tracking', false),
            'wc_tracking' => (bool) get_option('meta_capi_enable_woocommerce', false),
            'debug_logging' => (bool) get_option('meta_capi_enable_logging', false),
        ];
    }

    /**
     * Get site headers (homepage).
     *
     * @return array<string, string>|null Headers array or null on failure.
     */
    private function get_site_headers(): ?array {
        // Check transient cache first (1 hour).
        $cached = get_transient('meta_capi_site_headers');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_head(
            home_url('/'),
            [
                'timeout' => 10,
                'sslverify' => false,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . ' Meta-CAPI-Status',
            ]
        );

        if (is_wp_error($response)) {
            $this->logger->log('Failed to fetch site headers', 'error', ['error' => $response->get_error_message()]);
            return null;
        }

        $headers = wp_remote_retrieve_headers($response);
        
        // Convert to array and normalize keys.
        $headers_array = [];
        foreach ($headers as $key => $value) {
            $headers_array[strtolower($key)] = $value;
        }

        // Cache for 1 hour.
        set_transient('meta_capi_site_headers', $headers_array, HOUR_IN_SECONDS);

        return $headers_array;
    }

    /**
     * Test WooCommerce page caching.
     *
     * @return array<string, mixed> Cache test results.
     */
    public function test_woocommerce_caching(): array {
        if (!class_exists('WooCommerce')) {
            return [
                'available' => false,
                'error' => 'WooCommerce not active',
            ];
        }

        $this->logger->log('Testing WooCommerce page caching...', 'info');

        $results = [
            'available' => true,
            'timestamp' => time(),
            'pages' => [],
            'all_passed' => false,
        ];

        // Pages to test.
        $test_pages = [
            'checkout' => [
                'url' => wc_get_checkout_url(),
                'name' => 'Checkout Page',
            ],
            'cart' => [
                'url' => wc_get_cart_url(),
                'name' => 'Cart Page',
            ],
            'my_account' => [
                'url' => wc_get_page_permalink('myaccount'),
                'name' => 'My Account Page',
            ],
        ];

        $all_passed = true;

        foreach ($test_pages as $key => $page) {
            $test_result = $this->test_page_caching($page['url']);
            $test_result['name'] = $page['name'];
            $results['pages'][$key] = $test_result;

            if (!$test_result['passed']) {
                $all_passed = false;
            }
        }

        $results['all_passed'] = $all_passed;

        $this->logger->log('WooCommerce cache test complete', 'info', [
            'all_passed' => $all_passed,
            'tested_pages' => count($test_pages),
        ]);

        return $results;
    }

    /**
     * Test if a specific page is cached.
     *
     * @param string $url URL to test.
     * @return array<string, mixed> Test result.
     */
    private function test_page_caching(string $url): array {
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 15,
                'sslverify' => false,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . ' Meta-CAPI-CacheTest',
            ]
        );

        if (is_wp_error($response)) {
            return [
                'passed' => false,
                'cached' => null,
                'status' => 'error',
                'message' => $response->get_error_message(),
            ];
        }

        $headers = wp_remote_retrieve_headers($response);
        
        // Check various cache headers.
        $cache_indicators = [
            'cf-cache-status' => ['MISS', 'BYPASS', 'DYNAMIC', 'EXPIRED'],
            'x-cache' => ['MISS', 'BYPASS'],
            'x-cache-status' => ['MISS', 'BYPASS'],
            'x-litespeed-cache' => ['miss', 'bypass'],
        ];

        $is_cached = false;
        $cache_status = 'unknown';

        foreach ($cache_indicators as $header => $good_values) {
            if (isset($headers[$header])) {
                $value = strtoupper($headers[$header]);
                $cache_status = $value;
                
                // Check if value indicates caching is bypassed.
                $is_bypassed = false;
                foreach ($good_values as $good_value) {
                    if (strpos($value, strtoupper($good_value)) !== false) {
                        $is_bypassed = true;
                        break;
                    }
                }
                
                if (!$is_bypassed && strpos($value, 'HIT') !== false) {
                    $is_cached = true;
                }
                
                break;
            }
        }

        return [
            'passed' => !$is_cached,
            'cached' => $is_cached,
            'status' => $cache_status,
            'message' => $is_cached ? 
                'Page is cached - may cause tracking issues' : 
                'Page is not cached - correct configuration',
        ];
    }

    /**
     * Generate warnings based on system status.
     *
     * @param array<string, mixed> $status System status array.
     * @return array<array<string, string>> Warning messages.
     */
    private function generate_warnings(array $status): array {
        $warnings = [];

        // Plugin not configured.
        if (!$status['plugin_config']['configured']) {
            $warnings[] = [
                'level' => 'error',
                'title' => 'Plugin Not Configured',
                'message' => 'Please add your Dataset ID and Access Token to start tracking events.',
                'action' => 'complete_setup',
                'action_text' => 'Complete Setup',
            ];
        }

        // Cloudways + Cloudflare detected.
        if ($status['hosting']['is_cloudways'] && $status['cdn']['cloudflare']) {
            $warnings[] = [
                'level' => 'warning',
                'title' => 'Cloudways + Cloudflare Detected',
                'message' => 'Test WooCommerce page caching to ensure proper tracking. You may need to disable edge caching or contact support.',
                'action' => 'test_cache',
                'action_text' => 'Run Cache Test',
            ];
        }

        // Generic Cloudflare warning.
        if (!$status['hosting']['is_cloudways'] && $status['cdn']['cloudflare']) {
            $warnings[] = [
                'level' => 'info',
                'title' => 'Cloudflare Detected',
                'message' => 'Ensure WooCommerce pages are excluded from caching. See Setup Guide for page rule configuration.',
                'action' => 'view_docs',
                'action_text' => 'View Setup Guide',
            ];
        }

        return $warnings;
    }

    /**
     * Generate recommendations based on system status.
     *
     * @param array<string, mixed> $status System status array.
     * @return array<array<string, string>> Recommendation messages.
     */
    private function generate_recommendations(array $status): array {
        $recommendations = [];

        // WooCommerce active but tracking disabled.
        if ($status['wordpress']['woocommerce_active'] && !$status['plugin_config']['wc_tracking']) {
            $recommendations[] = [
                'title' => 'Enable WooCommerce Tracking',
                'message' => 'WooCommerce is active. Enable Purchase tracking to maximize your conversion data.',
            ];
        }

        // Elementor Pro active but tracking disabled.
        if ($status['wordpress']['elementor_pro_active'] && !$status['plugin_config']['form_tracking']) {
            $recommendations[] = [
                'title' => 'Enable Form Tracking',
                'message' => 'Elementor Pro is active. Enable form tracking to capture lead submissions.',
            ];
        }

        // Multiple caching plugins.
        if ($status['caching_plugins']['count'] > 1) {
            $recommendations[] = [
                'title' => 'Multiple Caching Plugins Detected',
                'message' => 'You have ' . $status['caching_plugins']['count'] . ' caching plugins active. Consider using only one to avoid conflicts.',
            ];
        }

        return $recommendations;
    }

    /**
     * Clear system status cache.
     *
     * @return void
     */
    public function clear_cache(): void {
        $this->status_cache = null;
        delete_transient('meta_capi_site_headers');
        $this->logger->log('System status cache cleared', 'info');
    }
}

