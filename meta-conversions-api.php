<?php
/**
 * Plugin Name: Meta Pixel & Conversions API
 * Plugin URI: https://wpbooster.cloud/meta-pixel-conversions-api
 * Description: Complete Meta tracking solution with Pixel (browser) and Conversions API (server). Supports page views, Elementor Pro forms, and WooCommerce events with automatic deduplication.
 * Version: 2.1.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: WP Booster
 * Author URI: https://wpbooster.cloud
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: meta-conversions-api
 * Domain Path: /languages
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('META_CAPI_VERSION', '2.1.1');
define('META_CAPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('META_CAPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('META_CAPI_PLUGIN_FILE', __FILE__);

// Require Composer autoload if available.
if (file_exists(META_CAPI_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once META_CAPI_PLUGIN_DIR . 'vendor/autoload.php';
}

// Include core classes.
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-logger.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-client.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-settings.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-tracking.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-elementor.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-updater.php';

// Include Phase 1 classes (WooCommerce Integration).
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-woocommerce.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-pixel.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-coordinator.php';

// Include Phase 1.5 classes (Performance & Diagnostics).
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-system-status.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-scripts.php';

/**
 * Main plugin class.
 */
class Meta_CAPI {
    /**
     * Instance of this class.
     *
     * @var Meta_CAPI
     */
    private static $instance = null;

    /**
     * Settings instance.
     *
     * @var Meta_CAPI_Settings
     */
    public $settings;

    /**
     * Client instance.
     *
     * @var Meta_CAPI_Client
     */
    public $client;

    /**
     * Tracking instance.
     *
     * @var Meta_CAPI_Tracking
     */
    public $tracking;

    /**
     * Elementor integration instance.
     *
     * @var Meta_CAPI_Elementor
     */
    public $elementor;

    /**
     * Logger instance.
     *
     * @var Meta_CAPI_Logger
     */
    public $logger;

    /**
     * WooCommerce integration instance.
     *
     * @var Meta_CAPI_WooCommerce|null
     */
    public $woocommerce = null;

    /**
     * Meta Pixel management instance.
     *
     * @var Meta_CAPI_Pixel
     */
    public $pixel;

    /**
     * Event coordinator instance.
     *
     * @var Meta_CAPI_Coordinator
     */
    public $coordinator;

    /**
     * System status instance.
     *
     * @var Meta_CAPI_System_Status
     */
    public $system_status;

    /**
     * Scripts manager instance.
     *
     * @var Meta_CAPI_Scripts
     */
    public $scripts;

    /**
     * Get the singleton instance.
     *
     * @return Meta_CAPI
     */
    public static function get_instance(): Meta_CAPI {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize the plugin.
     */
    private function init(): void {
        // Initialize logger first (needed by all other classes).
        $this->logger = new Meta_CAPI_Logger();

        // Initialize settings.
        $this->settings = new Meta_CAPI_Settings();

        // Initialize API client.
        $this->client = new Meta_CAPI_Client($this->logger);
        
        // Initialize automatic updates.
        new Meta_CAPI_Updater();

        // Initialize Phase 1 classes (Pixel & Coordinator) FIRST - needed by other classes.
        $this->coordinator = new Meta_CAPI_Coordinator($this->logger);
        $this->pixel = new Meta_CAPI_Pixel($this->logger);

        // Initialize tracking (page views, etc.) - requires coordinator.
        $this->tracking = new Meta_CAPI_Tracking($this->client, $this->logger, $this->coordinator);

        // Initialize Elementor integration.
        $this->elementor = new Meta_CAPI_Elementor($this->client, $this->logger);

        // Initialize Phase 1.5 classes (Performance & Diagnostics).
        $this->system_status = new Meta_CAPI_System_Status($this->logger);
        $this->scripts = new Meta_CAPI_Scripts($this->logger);

        // Initialize WooCommerce integration after all plugins have loaded.
        add_action('plugins_loaded', [$this, 'init_woocommerce']);

        $this->logger->log('Meta Conversions API v' . META_CAPI_VERSION . ' initialized');

        // Register hooks.
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_notices', [$this, 'admin_notices']);
        
        // CRITICAL: Hook into Breeze cache clearing to also clear Object Cache Pro.
        // Breeze clears its own cache and Varnish, but doesn't automatically clear Object Cache Pro.
        add_action('breeze_clear_all_cache', [$this, 'clear_object_cache_pro_on_breeze_clear'], 10);
        add_action('breeze_clear_varnish', [$this, 'clear_object_cache_pro_on_breeze_clear'], 10);
        
        // Plugin page customization.
        add_filter('plugin_action_links_' . plugin_basename(META_CAPI_PLUGIN_FILE), [$this, 'add_action_links']);
        add_filter('plugin_row_meta', [$this, 'add_row_meta'], 10, 2);

        // Log cleanup cron.
        add_action('meta_capi_cleanup_logs', [$this, 'cleanup_old_logs']);

        // Weekly anonymous stats (opt-out available).
        add_action('meta_capi_send_stats', [$this, 'send_anonymous_stats']);

        // Manual stats trigger via secret URL parameter (for testing).
        add_action('admin_init', [$this, 'maybe_send_stats_manually']);

        // Activation/Deactivation hooks.
        register_activation_hook(META_CAPI_PLUGIN_FILE, [__CLASS__, 'activate_static']);
        register_deactivation_hook(META_CAPI_PLUGIN_FILE, [__CLASS__, 'deactivate_static']);
    }

    /**
     * Initialize WooCommerce integration after all plugins have loaded.
     * This ensures WooCommerce class exists before we check for it.
     */
    public function init_woocommerce(): void {
        // Only initialize if WooCommerce is active and tracking is enabled.
        if (class_exists('WooCommerce') && get_option('meta_capi_enable_woocommerce', false)) {
            $this->woocommerce = new Meta_CAPI_WooCommerce($this->client, $this->logger, $this->coordinator);
            $this->logger->log('WooCommerce integration initialized', 'info');
        }
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'meta-conversions-api',
            false,
            dirname(plugin_basename(META_CAPI_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Display admin notices.
     */
    public function admin_notices(): void {
        // Only show notices on admin pages.
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Show analytics notice on first activation.
        if (get_transient('meta_capi_show_analytics_notice')) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong><?php esc_html_e('Meta Conversions API: Anonymous Usage Analytics', 'meta-conversions-api'); ?></strong><br>
                    <?php 
                    echo wp_kses_post(
                        sprintf(
                            __('This plugin sends completely anonymous usage data weekly to help us improve. No personal data is collected. <a href="%s">Learn more or opt-out in Settings</a>.', 'meta-conversions-api'),
                            esc_url(admin_url('options-general.php?page=meta-conversions-api#analytics-settings'))
                        )
                    );
                    ?>
                </p>
            </div>
            <?php
            delete_transient('meta_capi_show_analytics_notice');
        }
        
        // Check if credentials are configured.
        // Trim whitespace to handle cases where users might have entered only spaces.
        $pixel_id = trim((string) get_option('meta_capi_pixel_id', ''));
        $access_token = trim((string) get_option('meta_capi_access_token', ''));
        $is_configured = !empty($pixel_id) && !empty($access_token);

        // Don't show the notice on the settings page itself (user is already configuring).
        $is_settings_page = strpos($screen->id, 'meta-conversions-api') !== false;
        
        // Don't show if settings were just saved (check for settings-updated parameter).
        // WordPress passes '1' (numeric string) when settings are saved, not 'true'.
        $settings_just_saved = isset($_GET['settings-updated']) && $_GET['settings-updated'] === '1';

        // Show setup notice only if not configured, not on settings page, and settings weren't just saved.
        if (!$is_configured && !$is_settings_page && !$settings_just_saved) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e('Meta Conversions API - Setup Required', 'meta-conversions-api'); ?></strong><br>
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            __('Please <a href="%s">configure your settings</a> to start tracking events.', 'meta-conversions-api'),
                            esc_url(admin_url('options-general.php?page=meta-conversions-api'))
                        )
                    );
                    ?>
                </p>
            </div>
            <?php
            // Don't show other notices if not configured (unless on settings page).
            if (!$is_settings_page) {
                return;
            }
        }

        // Show system status warnings (only on plugin pages).
        if (strpos($screen->id, 'meta-conversions-api') !== false || $screen->id === 'plugins') {
            $status = $this->system_status->get_status();
            
            if (!empty($status['warnings'])) {
                foreach ($status['warnings'] as $warning) {
                    $notice_class = 'notice-' . ($warning['level'] === 'error' ? 'error' : 'warning');
                    ?>
                    <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
                        <p>
                            <strong><?php echo esc_html($warning['title']); ?></strong><br>
                            <?php echo esc_html($warning['message']); ?>
                            <?php if (!empty($warning['action_text'])): ?>
                                <br>
                                <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools')); ?>" class="button button-small">
                                    <?php echo esc_html($warning['action_text']); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php
                }
            }
        }
    }

    /**
     * Plugin activation (static wrapper for activation hook).
     */
    public static function activate_static(): void {
        $instance = self::get_instance();
        $instance->activate();
    }

    /**
     * Plugin activation.
     */
    public function activate(): void {
        // CRITICAL: Set skip tracking transient FIRST, before any other operations.
        // This prevents any server events from being sent during activation.
        // Extended cooldown: 30 seconds to cover activation, redirect, and any follow-up requests.
        if (function_exists('set_transient')) {
            set_transient('meta_capi_skip_tracking_after_activation', true, 30);
        }
        
        // Set default options ONLY if they don't already exist.
        // add_option() only adds if option doesn't exist, but we explicitly check to be extra safe.
        // This prevents any possibility of overwriting existing settings during re-activation.
        if (false === get_option('meta_capi_pixel_id', false)) {
            add_option('meta_capi_pixel_id', '');
        }
        if (false === get_option('meta_capi_access_token', false)) {
            add_option('meta_capi_access_token', '');
        }
        if (false === get_option('meta_capi_test_event_code', false)) {
            add_option('meta_capi_test_event_code', '');
        }
        if (false === get_option('meta_capi_enable_pixel', false)) {
            add_option('meta_capi_enable_pixel', '1');
        }
        if (false === get_option('meta_capi_enable_page_view', false)) {
            add_option('meta_capi_enable_page_view', '1');
        }
        if (false === get_option('meta_capi_enable_form_tracking', false)) {
            add_option('meta_capi_enable_form_tracking', '1');
        }
        if (false === get_option('meta_capi_enable_logging', false)) {
            add_option('meta_capi_enable_logging', '0');
        }
        
        // Analytics opt-in by default (can be disabled in settings).
        if (false === get_option('meta_capi_disable_stats', false)) {
            add_option('meta_capi_disable_stats', '0');
        }
        
        // Set flag to show analytics notice.
        set_transient('meta_capi_show_analytics_notice', true, DAY_IN_SECONDS);

        // Schedule daily log cleanup.
        if (!wp_next_scheduled('meta_capi_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'meta_capi_cleanup_logs');
        }

        // Schedule weekly anonymous stats (unless opted out).
        if (!get_option('meta_capi_disable_stats', false) && !wp_next_scheduled('meta_capi_send_stats')) {
            wp_schedule_event(time(), 'weekly', 'meta_capi_send_stats');
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
        
        // Note: Skip tracking transient is set at the very beginning of activate() to prevent any events.
    }

    /**
     * Plugin deactivation (static wrapper for deactivation hook).
     */
    public static function deactivate_static(): void {
        $instance = self::get_instance();
        $instance->deactivate();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate(): void {
        // Clear scheduled log cleanup.
        $timestamp = wp_next_scheduled('meta_capi_cleanup_logs');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'meta_capi_cleanup_logs');
        }

        // Clear scheduled stats.
        $stats_timestamp = wp_next_scheduled('meta_capi_send_stats');
        if ($stats_timestamp) {
            wp_unschedule_event($stats_timestamp, 'meta_capi_send_stats');
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Clean up old log files (runs daily via WP Cron).
     */
    public function cleanup_old_logs(): void {
        $this->logger->clear_old_logs(30); // Keep logs for 30 days.
    }

    /**
     * Clear all caches (Breeze, Varnish, Cloudflare, WordPress).
     * Static version for use during activation when instance may not be fully initialized.
     *
     * @return array Results of cache clearing operations.
     */
    public static function clear_all_caches_static(): array {
        return self::get_instance()->clear_all_caches();
    }

    /**
     * Clear external caches only (Breeze, Varnish, Cloudflare).
     * WordPress cache is NOT cleared to protect plugin settings.
     * Use this during activation to prevent clearing cached options.
     *
     * @return array Results of cache clearing operations.
     */
    public function clear_external_caches_only(): array {
        $results = [
            'breeze' => false,
            'varnish' => false,
            'cloudflare' => false,
        ];

        // Clear Breeze cache if plugin is active.
        if (class_exists('Breeze_Admin')) {
            if (function_exists('breeze_clear_all_cache')) {
                breeze_clear_all_cache();
                $results['breeze'] = true;
            } elseif (defined('BREEZE_VERSION')) {
                // Alternative method for newer Breeze versions.
                do_action('breeze_clear_all_cache');
                $results['breeze'] = true;
            }
        }

        // Clear Varnish cache if available.
        // Most Varnish implementations use PURGE method via HTTP.
        $varnish_host = get_option('vhp_varnish_url', '');
        if (!empty($varnish_host)) {
            wp_remote_request($varnish_host, [
                'method' => 'PURGE',
                'timeout' => 5,
            ]);
            $results['varnish'] = true;
        } else {
            // Try default Varnish clearing methods.
            $home_url = home_url('/');
            $parsed_url = parse_url($home_url);
            if (!empty($parsed_url['host'])) {
                // Attempt PURGE request to common Varnish endpoints.
                $varnish_endpoints = [
                    $home_url,
                    $parsed_url['scheme'] . '://' . $parsed_url['host'],
                ];

                foreach ($varnish_endpoints as $endpoint) {
                    $response = wp_remote_request($endpoint, [
                        'method' => 'PURGE',
                        'timeout' => 5,
                        'headers' => [
                            'Host' => $parsed_url['host'],
                            'X-Purge-Method' => 'default',
                        ],
                    ]);

                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 500) {
                        $results['varnish'] = true;
                        break;
                    }
                }
            }
        }

        // Clear Cloudflare cache if plugin is active.
        if (class_exists('Cloudflare')) {
            if (function_exists('cloudflare_purge_cache')) {
                cloudflare_purge_cache();
                $results['cloudflare'] = true;
            } elseif (defined('CLOUDFLARE_VERSION')) {
                // Try to clear via Cloudflare API if credentials are available.
                $email = get_option('cloudflare_api_email');
                $api_key = get_option('cloudflare_api_key');
                $zone_id = get_option('cloudflare_zone_id');

                if ($email && $api_key && $zone_id) {
                    $response = wp_remote_post(
                        sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $zone_id),
                        [
                            'headers' => [
                                'X-Auth-Email' => $email,
                                'X-Auth-Key' => $api_key,
                                'Content-Type' => 'application/json',
                            ],
                            'body' => wp_json_encode(['purge_everything' => true]),
                            'timeout' => 10,
                        ]
                    );

                    if (!is_wp_error($response)) {
                        $body = json_decode(wp_remote_retrieve_body($response), true);
                        if (isset($body['success']) && $body['success']) {
                            $results['cloudflare'] = true;
                        }
                    }
                }
            }
        }

        // Trigger action for other plugins to hook into.
        do_action('meta_capi_cache_cleared', $results);

        return $results;
    }

    /**
     * Clear all caches (Breeze, Varnish, Cloudflare, WordPress).
     * WARNING: wp_cache_flush() can clear cached options if using persistent object cache.
     * Use clear_external_caches_only() during activation to protect settings.
     *
     * @return array Results of cache clearing operations.
     */
    public function clear_all_caches(): array {
        $results = [
            'wordpress' => false,
            'breeze' => false,
            'varnish' => false,
            'cloudflare' => false,
        ];

        // Clear WordPress object cache.
        // WARNING: This can clear cached options if using persistent object cache (Redis, Memcached).
        // Only use when you're sure cached options won't be affected.
        try {
            // CRITICAL: Clear Object Cache Pro first if available (it has its own flush method).
            if (function_exists('wp_cache_flush_group')) {
                // Object Cache Pro supports group-based flushing.
                wp_cache_flush_group('default');
            }
            
            // Also try Object Cache Pro's specific flush method if available.
            if (class_exists('RedisCachePro\Plugin')) {
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
                // Object Cache Pro also has a direct flush method.
                if (method_exists('RedisCachePro\Plugin', 'flush')) {
                    \RedisCachePro\Plugin::flush();
                }
            }
            
            // Standard WordPress object cache flush (works with most object cache plugins).
            wp_cache_flush();
            $results['wordpress'] = true;
            $results['object_cache_pro'] = class_exists('RedisCachePro\Plugin');
        } catch (Exception $e) {
            // Cache flush failed, but continue with other cache clearing.
            // Log error if logger is available.
            if (isset($this->logger)) {
                $this->logger->warning('WordPress cache flush failed: ' . $e->getMessage());
            }
            $results['wordpress'] = false;
        }

        // Clear external caches (Breeze, Varnish, Cloudflare).
        $external_results = $this->clear_external_caches_only();
        $results['breeze'] = $external_results['breeze'];
        $results['varnish'] = $external_results['varnish'];
        $results['cloudflare'] = $external_results['cloudflare'];

        // Trigger action for other plugins to hook into.
        do_action('meta_capi_cache_cleared', $results);

        return $results;
    }

    /**
     * Clear Object Cache Pro when Breeze clears its cache.
     * Breeze clears internal cache and Varnish, but doesn't automatically clear Object Cache Pro.
     * This hook ensures Object Cache Pro is also cleared when Breeze cache is purged.
     *
     * @return void
     */
    public function clear_object_cache_pro_on_breeze_clear(): void {
        // Only clear if Object Cache Pro is active.
        if (!class_exists('RedisCachePro\Plugin') && !function_exists('wp_cache_flush')) {
            return;
        }

        try {
            // Try Object Cache Pro's specific flush method first.
            if (class_exists('RedisCachePro\Plugin')) {
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
                // Object Cache Pro also has a direct flush method if available.
                if (method_exists('RedisCachePro\Plugin', 'flush')) {
                    \RedisCachePro\Plugin::flush();
                }
            } else {
                // Standard WordPress object cache flush.
                wp_cache_flush();
            }

            // Log if logger is available.
            if (isset($this->logger)) {
                $this->logger->info('Object Cache Pro cleared automatically (triggered by Breeze cache clear)', [
                    'object_cache_pro_active' => class_exists('RedisCachePro\Plugin'),
                ]);
            }
        } catch (Exception $e) {
            // Log error if logger is available, but don't break Breeze's cache clearing.
            if (isset($this->logger)) {
                $this->logger->warning('Failed to clear Object Cache Pro on Breeze cache clear: ' . $e->getMessage());
            }
        }
    }

    /**
     * Check for secret URL parameter to manually trigger stats (for testing).
     */
    public function maybe_send_stats_manually(): void {
        // Secret parameter: ?meta_capi_ping_stats=wpbooster2024
        if (isset($_GET['meta_capi_ping_stats']) && 
            sanitize_text_field(wp_unslash($_GET['meta_capi_ping_stats'])) === 'wpbooster2024' &&
            current_user_can('manage_options')) {
            
            // Check if analytics are disabled before attempting to send.
            if (get_option('meta_capi_disable_stats', false)) {
                wp_die(
                    '<h1>⚠️ Analytics Disabled</h1>' .
                    '<p>Anonymous usage analytics are currently disabled in your plugin settings.</p>' .
                    '<p>To enable analytics, go to <strong>Settings → Meta Conversions API</strong> and uncheck "Disable Anonymous Analytics".</p>' .
                    '<p><a href="' . esc_url(admin_url('options-general.php?page=meta-conversions-api')) . '">← Back to Settings</a></p>',
                    'Analytics Disabled',
                    ['response' => 200]
                );
                return;
            }
            
            $this->send_anonymous_stats();
            
            wp_die(
                '<h1>✅ Analytics Ping Sent!</h1>' .
                '<p>Anonymous statistics have been sent to wpbooster.cloud</p>' .
                '<p><strong>Site Hash:</strong> ' . esc_html(md5(get_option('siteurl'))) . '</p>' .
                '<p><strong>Plugin Version:</strong> ' . esc_html(META_CAPI_VERSION) . '</p>' .
                '<p><a href="' . esc_url(admin_url('options-general.php?page=meta-conversions-api')) . '">← Back to Settings</a></p>',
                'Analytics Ping Sent',
                ['response' => 200]
            );
        }
    }

    /**
     * Send anonymous usage statistics (runs weekly via WP Cron).
     * Completely anonymous - only helps us understand plugin usage.
     * To opt-out: add_option('meta_capi_disable_stats', true);
     */
    public function send_anonymous_stats(): void {
        // Check if user opted out.
        if (get_option('meta_capi_disable_stats', false)) {
            return;
        }

        // Get active theme info
        $theme = wp_get_theme();
        $theme_name = $theme->get('Name');
        
        // Get database version
        global $wpdb;
        $mysql_version = $wpdb->db_version();
        
        // Get memory limit
        $memory_limit = ini_get('memory_limit');
        if (!$memory_limit) {
            $memory_limit = WP_MEMORY_LIMIT;
        }
        
        // Get Elementor Pro version
        $elementor_pro_version = '';
        if (did_action('elementor_pro/init') && defined('ELEMENTOR_PRO_VERSION')) {
            $elementor_pro_version = ELEMENTOR_PRO_VERSION;
        }
        
        // Get WooCommerce version
        $woocommerce_version = '';
        if (class_exists('WooCommerce') && defined('WC_VERSION')) {
            $woocommerce_version = WC_VERSION;
        }
        
        // Collect anonymous data.
        $data = [
            'site_hash' => md5(get_option('siteurl')), // Anonymous identifier
            'plugin_version' => META_CAPI_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysql_version,
            'memory_limit' => $memory_limit,
            'locale' => get_locale(),
            'is_multisite' => is_multisite() ? 1 : 0,
            'is_ssl' => is_ssl() ? 1 : 0,
            'active_theme' => $theme_name,
            'total_plugins' => count((array) get_option('active_plugins', [])),
            'elementor_pro' => did_action('elementor_pro/init') ? 1 : 0,
            'elementor_pro_version' => $elementor_pro_version,
            'woocommerce' => class_exists('WooCommerce') ? 1 : 0,
            'woocommerce_version' => $woocommerce_version,
            'page_view_tracking' => get_option('meta_capi_enable_page_view', false) ? 1 : 0,
            'form_tracking' => get_option('meta_capi_enable_form_tracking', false) ? 1 : 0,
            'debug_logging' => get_option('meta_capi_enable_logging', false) ? 1 : 0,
        ];

        // Send to wpbooster.cloud.
        $this->logger->log('Sending anonymous stats to wpbooster.cloud...');
        $this->logger->log('Stats data: ' . wp_json_encode($data));
        
        $response = wp_remote_post('https://wpbooster.cloud/wp-json/meta-capi/v1/stats', [
            'blocking' => true, // Changed to blocking for debugging
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Meta-CAPI-Plugin/' . META_CAPI_VERSION . ' (WordPress/' . get_bloginfo('version') . ')',
                'X-CAPI-Auth' => md5('wpbooster-meta-capi-2024'), // Secret auth token
            ],
            'body' => wp_json_encode($data),
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log('Stats send FAILED: ' . $response->get_error_message());
        } else {
            $this->logger->log('Stats send response code: ' . wp_remote_retrieve_response_code($response));
            $this->logger->log('Stats send response body: ' . wp_remote_retrieve_body($response));
        }
    }

    /**
     * Add action links on plugins page.
     *
     * @param array $links Existing plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_action_links(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=meta-conversions-api')),
            esc_html__('Settings', 'meta-conversions-api')
        );
        
        array_unshift($links, $settings_link);
        
        return $links;
    }

    /**
     * Add row meta on plugins page.
     *
     * @param array  $links Existing plugin row meta.
     * @param string $file  Plugin file path.
     * @return array Modified plugin row meta.
     */
    public function add_row_meta(array $links, string $file): array {
        if (plugin_basename(META_CAPI_PLUGIN_FILE) === $file) {
            $row_meta = [
                'wpbooster' => sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer" style="color: #2271b1; font-weight: 600;">%s</a>',
                    esc_url('https://wpbooster.cloud/?utm_source=meta-capi-plugin&utm_medium=plugins-page&utm_campaign=plugin-link'),
                    esc_html__('Premium Managed WordPress Hosting & Maintenance - Free Migration & Performance Optimization', 'meta-conversions-api')
                ),
            ];
            
            $links = array_merge($links, $row_meta);
        }
        
        return $links;
    }
}

/**
 * Initialize the plugin.
 */
function meta_capi(): Meta_CAPI {
    return Meta_CAPI::get_instance();
}

// Initialize the plugin.
meta_capi();

