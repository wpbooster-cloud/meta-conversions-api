<?php
/**
 * Plugin Name: Meta Conversions API
 * Plugin URI: https://wpbooster.cloud/meta-conversions-api
 * Description: Connects to Facebook Conversions API to track page views and Elementor Pro form submissions
 * Version: 1.1.0
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
define('META_CAPI_VERSION', '1.1.0');
define('META_CAPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('META_CAPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('META_CAPI_PLUGIN_FILE', __FILE__);

// Require Composer autoload if available.
if (file_exists(META_CAPI_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once META_CAPI_PLUGIN_DIR . 'vendor/autoload.php';
}

// Include core classes.
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-settings.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-client.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-tracking.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-elementor.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-logger.php';
require_once META_CAPI_PLUGIN_DIR . 'includes/class-meta-capi-updater.php';

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
        // Initialize logger first.
        $this->logger = new Meta_CAPI_Logger();

        // Initialize settings.
        $this->settings = new Meta_CAPI_Settings();

        // Initialize API client.
        $this->client = new Meta_CAPI_Client($this->logger);
        
        // Initialize automatic updates.
        new Meta_CAPI_Updater();

        // Initialize tracking.
        $this->tracking = new Meta_CAPI_Tracking($this->client, $this->logger);

        // Initialize Elementor integration.
        $this->elementor = new Meta_CAPI_Elementor($this->client, $this->logger);

        // Register hooks.
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_notices', [$this, 'admin_notices']);
        
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
        register_activation_hook(META_CAPI_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(META_CAPI_PLUGIN_FILE, [$this, 'deactivate']);
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
        $pixel_id = get_option('meta_capi_pixel_id');
        $access_token = get_option('meta_capi_access_token');

        if (empty($pixel_id) || empty($access_token)) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            __('Meta Conversions API: Please <a href="%s">configure your settings</a> to start tracking events.', 'meta-conversions-api'),
                            esc_url(admin_url('options-general.php?page=meta-conversions-api'))
                        )
                    );
                    ?>
                </p>
            </div>
            <?php
        }

        // Check if Elementor Pro is active.
        if (!did_action('elementor_pro/init')) {
            ?>
            <div class="notice notice-info">
                <p>
                    <?php esc_html_e('Meta Conversions API: Elementor Pro is not active. Form submission tracking will not be available.', 'meta-conversions-api'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Plugin activation.
     */
    public function activate(): void {
        // Set default options.
        add_option('meta_capi_pixel_id', '');
        add_option('meta_capi_access_token', '');
        add_option('meta_capi_test_event_code', '');
        add_option('meta_capi_enable_page_view', '1');
        add_option('meta_capi_enable_form_tracking', '1');
        add_option('meta_capi_enable_logging', '0');
        
        // Analytics opt-in by default (can be disabled in settings).
        add_option('meta_capi_disable_stats', '0');
        
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
     * Check for secret URL parameter to manually trigger stats (for testing).
     */
    public function maybe_send_stats_manually(): void {
        // Secret parameter: ?meta_capi_ping_stats=wpbooster2024
        if (isset($_GET['meta_capi_ping_stats']) && 
            sanitize_text_field(wp_unslash($_GET['meta_capi_ping_stats'])) === 'wpbooster2024' &&
            current_user_can('manage_options')) {
            
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

