<?php
/**
 * Plugin Name: Meta Conversions API
 * Plugin URI: https://wpbooster.cloud/meta-conversions-api
 * Description: Connects to Facebook Conversions API to track page views and Elementor Pro form submissions
 * Version: 1.0.0
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
define('META_CAPI_VERSION', '1.0.0');
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
     * Send anonymous usage statistics (runs weekly via WP Cron).
     * Completely anonymous - only helps us understand plugin usage.
     * To opt-out: add_option('meta_capi_disable_stats', true);
     */
    public function send_anonymous_stats(): void {
        // Check if user opted out.
        if (get_option('meta_capi_disable_stats', false)) {
            return;
        }

        // Collect anonymous data.
        $data = [
            'site_hash' => md5(get_option('siteurl')), // Anonymous identifier
            'plugin_version' => META_CAPI_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'elementor_pro' => did_action('elementor_pro/init') ? 1 : 0,
            'woocommerce' => class_exists('WooCommerce') ? 1 : 0,
            'page_view_tracking' => get_option('meta_capi_enable_page_view', false) ? 1 : 0,
            'form_tracking' => get_option('meta_capi_enable_form_tracking', false) ? 1 : 0,
        ];

        // Send to wpbooster.cloud (non-blocking, won't slow down the site).
        wp_remote_post('https://wpbooster.cloud/wp-json/meta-capi/v1/stats', [
            'blocking' => false,
            'timeout' => 5,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($data),
        ]);
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

