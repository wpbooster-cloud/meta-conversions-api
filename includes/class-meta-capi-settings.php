<?php
/**
 * Settings page for Meta Conversions API.
 *
 * @package Meta_Conversions_API
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta_CAPI_Settings class.
 */
class Meta_CAPI_Settings {
    /**
     * Constructor.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
    }

    /**
     * Add settings page to WordPress admin.
     */
    public function add_settings_page(): void {
        // Main settings page under Settings menu
        add_options_page(
            __('Meta Conversions API', 'meta-conversions-api'),
            __('Meta CAPI', 'meta-conversions-api'),
            'manage_options',
            'meta-conversions-api',
            [$this, 'render_main_page']
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings(): void {
        // Register settings.
        register_setting('meta_capi_settings', 'meta_capi_pixel_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('meta_capi_settings', 'meta_capi_access_token', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('meta_capi_settings', 'meta_capi_test_event_code', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('meta_capi_settings', 'meta_capi_enable_page_view', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('meta_capi_settings', 'meta_capi_enable_form_tracking', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('meta_capi_settings', 'meta_capi_enable_logging', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        register_setting('meta_capi_settings', 'meta_capi_disable_stats', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        // Add settings sections.
        add_settings_section(
            'meta_capi_credentials',
            __('Facebook Credentials', 'meta-conversions-api'),
            [$this, 'render_credentials_section'],
            'meta-conversions-api'
        );

        add_settings_section(
            'meta_capi_tracking',
            __('Tracking Settings', 'meta-conversions-api'),
            [$this, 'render_tracking_section'],
            'meta-conversions-api'
        );

        add_settings_section(
            'meta_capi_advanced',
            __('Testing', 'meta-conversions-api'),
            [$this, 'render_advanced_section'],
            'meta-conversions-api'
        );

        add_settings_section(
            'meta_capi_analytics',
            __('Anonymous Usage Analytics', 'meta-conversions-api'),
            [$this, 'render_analytics_section'],
            'meta-conversions-api'
        );

        // Add settings fields.
        add_settings_field(
            'meta_capi_pixel_id',
            __('Dataset ID', 'meta-conversions-api'),
            [$this, 'render_pixel_id_field'],
            'meta-conversions-api',
            'meta_capi_credentials'
        );

        add_settings_field(
            'meta_capi_access_token',
            __('Access Token', 'meta-conversions-api'),
            [$this, 'render_access_token_field'],
            'meta-conversions-api',
            'meta_capi_credentials'
        );

        add_settings_field(
            'meta_capi_test_event_code',
            __('Test Event Code', 'meta-conversions-api'),
            [$this, 'render_test_event_code_field'],
            'meta-conversions-api',
            'meta_capi_advanced'
        );

        add_settings_field(
            'meta_capi_enable_page_view',
            __('Enable Page View Tracking', 'meta-conversions-api'),
            [$this, 'render_page_view_field'],
            'meta-conversions-api',
            'meta_capi_tracking'
        );

        add_settings_field(
            'meta_capi_enable_form_tracking',
            __('Enable Elementor Pro Form Tracking', 'meta-conversions-api'),
            [$this, 'render_form_tracking_field'],
            'meta-conversions-api',
            'meta_capi_tracking'
        );

        add_settings_field(
            'meta_capi_enable_woocommerce',
            __('Enable WooCommerce Tracking', 'meta-conversions-api'),
            [$this, 'render_woocommerce_tracking_field'],
            'meta-conversions-api',
            'meta_capi_tracking'
        );

        // Debug logging is now on Tools page
        // add_settings_field(
        //     'meta_capi_enable_logging',
        //     __('Enable Debug Logging', 'meta-conversions-api'),
        //     [$this, 'render_logging_field'],
        //     'meta-conversions-api',
        //     'meta_capi_advanced'
        // );

        add_settings_field(
            'meta_capi_disable_stats',
            __('Disable Anonymous Analytics', 'meta-conversions-api'),
            [$this, 'render_analytics_opt_out_field'],
            'meta-conversions-api',
            'meta_capi_analytics'
        );
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_scripts(string $hook): void {
        // Only load on our plugin pages
        // Hook is 'settings_page_meta-conversions-api' since we're under Settings menu
        // Also check for the page parameter for tab navigation
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $is_our_page = (strpos($hook, 'meta-conversions-api') !== false) || 
                       ($page === 'meta-conversions-api');
        
        if (!$is_our_page) {
            return;
        }

        wp_enqueue_style(
            'meta-capi-admin',
            META_CAPI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            META_CAPI_VERSION
        );
    }

    /**
     * Render tab navigation.
     *
     * @param string $current Current tab.
     */
    private function render_tabs(string $current = 'settings'): void {
        $tabs = [
            'settings' => [
                'title' => __('Settings', 'meta-conversions-api'),
                'url' => admin_url('options-general.php?page=meta-conversions-api'),
            ],
            'documentation' => [
                'title' => __('Documentation', 'meta-conversions-api'),
                'url' => admin_url('options-general.php?page=meta-conversions-api&tab=documentation'),
            ],
            'tools' => [
                'title' => __('Tools & Logs', 'meta-conversions-api'),
                'url' => admin_url('options-general.php?page=meta-conversions-api&tab=tools'),
            ],
        ];

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $data) {
            $class = ($current === $tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($data['url']),
                esc_attr($class),
                esc_html($data['title'])
            );
        }
        echo '</h2>';
    }

    /**
     * Render main page with tab routing.
     */
    public function render_main_page(): void {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        
        switch ($tab) {
            case 'documentation':
                $this->render_documentation_page();
                break;
            case 'tools':
                $this->render_tools_page();
                break;
            default:
                $this->render_settings_page();
                break;
        }
    }

    /**
     * Render settings page.
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Test connection if requested.
        if (isset($_POST['test_connection']) && check_admin_referer('meta_capi_test_connection')) {
            $this->test_connection();
        }

        ?>
        <div class="wrap meta-capi-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php $this->render_tabs('settings'); ?>

            <div class="meta-capi-admin-container">
                <div class="meta-capi-admin-main">
                    <?php settings_errors('meta_capi_settings'); ?>

                    <form method="post" action="options.php">
                        <?php
                        settings_fields('meta_capi_settings');
                        do_settings_sections('meta-conversions-api');
                        submit_button(__('Save Settings', 'meta-conversions-api'));
                        ?>
                    </form>

                    <hr>

                    <h2><?php esc_html_e('Test Connection', 'meta-conversions-api'); ?></h2>
                    <p><?php esc_html_e('Test your Facebook Conversions API connection by sending a test event.', 'meta-conversions-api'); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('meta_capi_test_connection'); ?>
                        <input type="hidden" name="test_connection" value="1">
                        <?php submit_button(__('Send Test Event', 'meta-conversions-api'), 'secondary', 'submit', false); ?>
                    </form>
                </div>

                <?php $this->render_sidebar(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render credentials section description.
     */
    public function render_credentials_section(): void {
        ?>
        <p>
            <?php
            echo wp_kses_post(
                sprintf(
                    __('Enter your Facebook Dataset ID and Access Token. You can find these in your <a href="%s" target="_blank">Facebook Events Manager</a>.', 'meta-conversions-api'),
                    'https://business.facebook.com/events_manager2'
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render tracking section description.
     */
    public function render_tracking_section(): void {
        ?>
        <p><?php esc_html_e('Configure which events you want to track.', 'meta-conversions-api'); ?></p>
        <?php
    }

    /**
     * Render advanced section description.
     */
    public function render_advanced_section(): void {
        ?>
        <p><?php esc_html_e('Use the Test Event Code to verify events in Facebook Events Manager before going live.', 'meta-conversions-api'); ?></p>
        <?php
    }

    /**
     * Render analytics settings section description.
     */
    public function render_analytics_section(): void {
        ?>
        <div id="analytics-settings"></div>
        <p>
            <?php esc_html_e('This plugin sends completely anonymous usage data weekly to help us improve.', 'meta-conversions-api'); ?>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=documentation#anonymous-analytics')); ?>">
                <?php esc_html_e('Learn more about what data is collected', 'meta-conversions-api'); ?> →
            </a>
        </p>
        <?php
    }

    /**
     * Render Dataset ID field.
     */
    public function render_pixel_id_field(): void {
        $value = get_option('meta_capi_pixel_id', '');
        ?>
        <input
            type="text"
            name="meta_capi_pixel_id"
            id="meta_capi_pixel_id"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text"
            placeholder="<?php esc_attr_e('1234567890123456', 'meta-conversions-api'); ?>"
        >
        <p class="description">
            <?php esc_html_e('Your Facebook Dataset ID (15-16 digit number).', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

    /**
     * Render Access Token field.
     */
    public function render_access_token_field(): void {
        $value = get_option('meta_capi_access_token', '');
        ?>
        <input
            type="password"
            name="meta_capi_access_token"
            id="meta_capi_access_token"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text"
            placeholder="<?php esc_attr_e('Your access token', 'meta-conversions-api'); ?>"
        >
        <p class="description">
            <?php esc_html_e('Your Facebook Conversions API Access Token.', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

    /**
     * Render Test Event Code field.
     */
    public function render_test_event_code_field(): void {
        $value = get_option('meta_capi_test_event_code', '');
        ?>
        <div id="test-event-code"></div>
        <input
            type="text"
            name="meta_capi_test_event_code"
            id="meta_capi_test_event_code"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text"
            placeholder="<?php esc_attr_e('TEST12345', 'meta-conversions-api'); ?>"
        >
        <p class="description">
            <?php esc_html_e('Optional: Test Event Code for testing in Facebook Events Manager.', 'meta-conversions-api'); ?>
        </p>
        <?php if (!empty($value)): ?>
            <div class="notice notice-info inline" style="margin: 10px 0; padding: 8px 12px;">
                <p style="margin: 0;">
                    <strong>🔵 <?php esc_html_e('Test Event Code Active', 'meta-conversions-api'); ?></strong><br>
                    <?php esc_html_e('Events are being sent as test events. Clear this field before going live.', 'meta-conversions-api'); ?>
                </p>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render Page View tracking field.
     */
    public function render_page_view_field(): void {
        $value = get_option('meta_capi_enable_page_view', true);
        ?>
        <label>
            <input
                type="checkbox"
                name="meta_capi_enable_page_view"
                id="meta_capi_enable_page_view"
                value="1"
                <?php checked($value, true); ?>
            >
            <?php esc_html_e('Track page views via Conversions API', 'meta-conversions-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Automatically send PageView events to Facebook when users visit pages.', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

    /**
     * Render Form Tracking field.
     */
    public function render_form_tracking_field(): void {
        $value = get_option('meta_capi_enable_form_tracking', true);
        $elementor_pro_active = did_action('elementor_pro/init');
        ?>
        <label style="<?php echo !$elementor_pro_active ? 'opacity: 0.5;' : ''; ?>">
            <input
                type="checkbox"
                name="meta_capi_enable_form_tracking"
                id="meta_capi_enable_form_tracking"
                value="1"
                <?php checked($value, true); ?>
                <?php disabled(!$elementor_pro_active); ?>
            >
            <?php esc_html_e('Track Elementor Pro form submissions', 'meta-conversions-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Send Lead events to Facebook when Elementor Pro forms are submitted.', 'meta-conversions-api'); ?>
        </p>
        <?php if (!$elementor_pro_active): ?>
            <p class="description" style="color: #d63638;">
                <?php esc_html_e('⚠️ Elementor Pro is not active. Install and activate Elementor Pro to enable this feature.', 'meta-conversions-api'); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render WooCommerce tracking field.
     */
    public function render_woocommerce_tracking_field(): void {
        ?>
        <label style="opacity: 0.5;">
            <input
                type="checkbox"
                name="meta_capi_enable_woocommerce"
                id="meta_capi_enable_woocommerce"
                value="1"
                disabled
            >
            <?php esc_html_e('Track WooCommerce purchase events', 'meta-conversions-api'); ?>
            <span class="badge" style="background: #2271b1; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px; font-weight: 600;">
                <?php esc_html_e('COMING SOON', 'meta-conversions-api'); ?>
            </span>
        </label>
        <p class="description">
            <?php esc_html_e('Track WooCommerce purchases, add to cart, and checkout events. This feature will be available in a future update.', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

    /**
     * Render analytics opt-out field.
     */
    public function render_analytics_opt_out_field(): void {
        $value = get_option('meta_capi_disable_stats', false);
        ?>
        <label>
            <input
                type="checkbox"
                name="meta_capi_disable_stats"
                id="meta_capi_disable_stats"
                value="1"
                <?php checked($value, true); ?>
            >
            <?php esc_html_e('Disable anonymous usage analytics', 'meta-conversions-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Check this box to opt-out of sending anonymous usage data. This helps us improve the plugin, but is completely optional.', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

    /**
     * Render Logging field.
     */
    public function render_logging_field(): void {
        $value = get_option('meta_capi_enable_logging', false);
        ?>
        <label>
            <input
                type="checkbox"
                name="meta_capi_enable_logging"
                id="meta_capi_enable_logging"
                value="1"
                <?php checked($value, true); ?>
            >
            <?php esc_html_e('Enable debug logging', 'meta-conversions-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Log all API requests and responses for debugging. Only enable when troubleshooting.', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

    /**
     * Test connection to Facebook Conversions API.
     */
    private function test_connection(): void {
        $client = new Meta_CAPI_Client(new Meta_CAPI_Logger());
        
        $test_data = [
            'event_name' => 'PageView',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => home_url('/'),
            'user_data' => [
                'client_ip_address' => '127.0.0.1',
                'client_user_agent' => 'Test User Agent',
            ],
        ];

        $result = $client->send_event($test_data);

        if ($result['success']) {
            set_transient('meta_capi_test_result', [
                'type' => 'success',
                'message' => __('Test event sent successfully! Check your Facebook Events Manager to verify.', 'meta-conversions-api')
            ], 30);
        } else {
            set_transient('meta_capi_test_result', [
                'type' => 'error',
                'message' => sprintf(
                    __('Failed to send test event: %s', 'meta-conversions-api'),
                    $result['message']
                )
            ], 30);
        }
        
        // Redirect to avoid form resubmission
        wp_redirect(add_query_arg('test_sent', '1', wp_get_referer()));
        exit;
    }

    /**
     * Show admin notices for debug mode and test event code.
     */
    public function show_admin_notices(): void {
        // DEBUG: Temporary - remove after testing
        error_log('Meta CAPI: show_admin_notices() called');
        error_log('Meta CAPI: Screen ID = ' . (get_current_screen() ? get_current_screen()->id : 'NULL'));
        
        // Only show on plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'meta-conversions-api') === false) {
            error_log('Meta CAPI: Admin notices skipped - screen check failed');
            return;
        }
        
        error_log('Meta CAPI: Admin notices passed screen check!');

        // Check for test connection result
        $test_result = get_transient('meta_capi_test_result');
        if ($test_result) {
            delete_transient('meta_capi_test_result');
            $notice_class = $test_result['type'] === 'success' ? 'notice-success' : 'notice-error';
            ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
                <p><?php echo esc_html($test_result['message']); ?></p>
            </div>
            <?php
        }

        // Warning for debug logging enabled
        if (get_option('meta_capi_enable_logging', false)) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e('Meta CAPI Debug Logging Active', 'meta-conversions-api'); ?></strong><br>
                    <?php esc_html_e('Debug logging is currently enabled. This will log all events and API requests. Remember to disable it once you\'re done troubleshooting.', 'meta-conversions-api'); ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools#debug-logging')); ?>"><?php esc_html_e('Disable in Tools & Logs', 'meta-conversions-api'); ?></a>
                </p>
            </div>
            <?php
        }

        // Info notice for test event code active
        $test_code = get_option('meta_capi_test_event_code', '');
        if (!empty($test_code)) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong><?php esc_html_e('Test Event Mode Active', 'meta-conversions-api'); ?></strong><br>
                    <?php 
                    printf(
                        esc_html__('Test Event Code (%s) is active. Events are being sent as test events and won\'t affect your production statistics.', 'meta-conversions-api'),
                        '<code>' . esc_html($test_code) . '</code>'
                    );
                    ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api#test-event-code')); ?>"><?php esc_html_e('Remove in Settings', 'meta-conversions-api'); ?></a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Render promotional sidebar.
     */
    private function render_sidebar(): void {
        ?>
        <div class="meta-capi-admin-sidebar">
            <div class="meta-capi-promo-box">
                <div class="meta-capi-promo-logo">
                    <img src="<?php echo esc_url(META_CAPI_PLUGIN_URL . 'assets/images/wpbooster-logo.svg'); ?>" alt="WP Booster" />
                </div>
                
                <h3><?php esc_html_e('Need WordPress Help?', 'meta-conversions-api'); ?></h3>
                
                <p><?php esc_html_e('We offer done-for-you WordPress services:', 'meta-conversions-api'); ?></p>
                
                <ul class="meta-capi-promo-list">
                    <li>✓ <?php esc_html_e('Free Site Migration', 'meta-conversions-api'); ?></li>
                    <li>✓ <?php esc_html_e('Performance Optimization', 'meta-conversions-api'); ?></li>
                    <li>✓ <?php esc_html_e('Security Hardening', 'meta-conversions-api'); ?></li>
                    <li>✓ <?php esc_html_e('No Tickets or Queues', 'meta-conversions-api'); ?></li>
                    <li>✓ <?php esc_html_e('North American Based Support', 'meta-conversions-api'); ?></li>
                </ul>
                
                <div style="text-align: center; margin: 20px 0;">
                    <img src="<?php echo esc_url(META_CAPI_PLUGIN_URL . 'assets/images/cloudways-silver.svg'); ?>" alt="Cloudways Silver Partner" style="max-width: 100%; width: 180px; height: auto;" />
                </div>
                
                <p class="meta-capi-promo-tagline">
                    <strong><?php esc_html_e('Just done-for-you service.', 'meta-conversions-api'); ?></strong>
                </p>
                
                <a href="https://wpbooster.cloud/?utm_source=meta-capi-plugin&utm_medium=sidebar&utm_campaign=plugin-promo" target="_blank" rel="noopener noreferrer" class="button button-primary button-hero meta-capi-promo-button">
                    <?php esc_html_e('Learn More →', 'meta-conversions-api'); ?>
                </a>
                
                <div class="meta-capi-promo-divider"></div>
                
                <div class="meta-capi-hosting-box">
                    <p class="meta-capi-hosting-title">
                        <strong><?php esc_html_e('Just need hosting without the support?', 'meta-conversions-api'); ?></strong>
                    </p>
                    <p class="meta-capi-hosting-text">
                        <?php esc_html_e('We recommend Cloudways', 'meta-conversions-api'); ?>
                    </p>
                    <a href="https://www.cloudways.com/en/?id=1030430" target="_blank" rel="noopener noreferrer" class="button button-secondary meta-capi-hosting-button">
                        <?php esc_html_e('Get Cloudways →', 'meta-conversions-api'); ?>
                    </a>
                </div>
                
                <p class="meta-capi-promo-footer">
                    <small><?php esc_html_e('Plugin by', 'meta-conversions-api'); ?> <a href="https://wpbooster.cloud/?utm_source=meta-capi-plugin&utm_medium=sidebar&utm_campaign=plugin-promo" target="_blank" rel="noopener noreferrer">WP Booster</a></small>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render documentation page.
     */
    public function render_documentation_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap meta-capi-admin-wrap">
            <h1><?php esc_html_e('Meta Conversions API Documentation', 'meta-conversions-api'); ?></h1>

            <?php $this->render_tabs('documentation'); ?>
            
            <div class="meta-capi-admin-container">
                <div class="meta-capi-admin-main">

            <div class="card" style="max-width: 100%;">
                <h2><?php esc_html_e('Quick Navigation', 'meta-conversions-api'); ?></h2>
                <ul style="column-count: 2; column-gap: 30px; list-style: disc; margin-left: 20px;">
                    <li><a href="#quick-start"><?php esc_html_e('Quick Start Guide', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#what-tracked"><?php esc_html_e('What Gets Tracked', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#privacy"><?php esc_html_e('Privacy & Data Handling', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#anonymous-analytics"><?php esc_html_e('Anonymous Usage Analytics', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#plugin-updates"><?php esc_html_e('Plugin Updates', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#troubleshooting"><?php esc_html_e('Troubleshooting', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#useful-links"><?php esc_html_e('Useful Links', 'meta-conversions-api'); ?></a></li>
                </ul>
            </div>

            <div class="card" id="quick-start" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Quick Start Guide', 'meta-conversions-api'); ?></h2>
                <p><?php esc_html_e('Follow these steps to set up the Meta Conversions API plugin:', 'meta-conversions-api'); ?></p>
                
                <ol style="line-height: 2;">
                    <li>
                        <strong><?php esc_html_e('Get Your Facebook Credentials', 'meta-conversions-api'); ?></strong>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><?php esc_html_e('Go to', 'meta-conversions-api'); ?> <a href="https://business.facebook.com/events_manager2" target="_blank"><?php esc_html_e('Facebook Events Manager', 'meta-conversions-api'); ?></a></li>
                            <li><?php esc_html_e('Select your Facebook Pixel', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Copy your Dataset ID (15-16 digit number at the top)', 'meta-conversions-api'); ?></li>
                        </ul>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Generate Access Token', 'meta-conversions-api'); ?></strong>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><?php esc_html_e('In Events Manager, click on your Pixel', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Go to Settings → Conversions API', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Click "Generate Access Token"', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Copy the token (starts with "EAA...")', 'meta-conversions-api'); ?></li>
                        </ul>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Configure Plugin Settings', 'meta-conversions-api'); ?></strong>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><?php esc_html_e('Go to', 'meta-conversions-api'); ?> <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api')); ?>"><?php esc_html_e('Settings → Meta CAPI', 'meta-conversions-api'); ?></a></li>
                            <li><?php esc_html_e('Enter your Dataset ID and Access Token', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Enable the tracking features you want', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Save Settings', 'meta-conversions-api'); ?></li>
                        </ul>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Test Your Connection', 'meta-conversions-api'); ?></strong>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><?php esc_html_e('On the Settings page, scroll down to "Test Connection"', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Click "Send Test Event"', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Verify the event appears in Facebook Events Manager', 'meta-conversions-api'); ?></li>
                        </ul>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Optional: Use Test Event Code', 'meta-conversions-api'); ?></strong>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><?php esc_html_e('In Facebook Events Manager, go to Test Events tab', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Copy your Test Event Code', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Add it to plugin settings', 'meta-conversions-api'); ?></li>
                            <li><?php esc_html_e('Events will appear in Test Events tab (won\'t affect statistics)', 'meta-conversions-api'); ?></li>
                        </ul>
                    </li>
                </ol>
            </div>

            <div class="card" id="what-tracked" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('What Gets Tracked', 'meta-conversions-api'); ?></h2>
                
                <h3><?php esc_html_e('Page View Events', 'meta-conversions-api'); ?></h3>
                <p><?php esc_html_e('When enabled, the plugin automatically tracks:', 'meta-conversions-api'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('All public page visits', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Page title and URL', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Content type (page, post, archive, etc.)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('User data (IP, user agent, Facebook cookies)', 'meta-conversions-api'); ?></li>
                </ul>

                <h3 style="margin-top: 20px;"><?php esc_html_e('Lead Events (Elementor Pro Forms)', 'meta-conversions-api'); ?></h3>
                <p><?php esc_html_e('When enabled, form submissions automatically send:', 'meta-conversions-api'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Form name and ID', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('User contact information (email, phone, name - all hashed for privacy)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Custom form fields', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Facebook browser cookies for better attribution', 'meta-conversions-api'); ?></li>
                </ul>
            </div>

            <div class="card" id="privacy" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Privacy & Data Handling', 'meta-conversions-api'); ?></h2>
                <p><?php esc_html_e('The plugin follows Facebook\'s best practices for data privacy:', 'meta-conversions-api'); ?></p>
                
                <h3><?php esc_html_e('Data That Gets Hashed (SHA-256)', 'meta-conversions-api'); ?></h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Email addresses', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Phone numbers', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('First and last names', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Geographic data (city, state, zip, country)', 'meta-conversions-api'); ?></li>
                </ul>

                <h3 style="margin-top: 20px;"><?php esc_html_e('Data Sent Unhashed (As Required by Facebook)', 'meta-conversions-api'); ?></h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('IP addresses', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('User agent strings', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Facebook browser cookies (_fbp, _fbc)', 'meta-conversions-api'); ?></li>
                </ul>
            </div>

            <div class="card" id="anonymous-analytics" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Anonymous Usage Analytics', 'meta-conversions-api'); ?></h2>
                <p><?php esc_html_e('This plugin sends completely anonymous usage data weekly to help us improve. We collect no personal information whatsoever.', 'meta-conversions-api'); ?></p>
                
                <h3><?php esc_html_e('What We Collect:', 'meta-conversions-api'); ?></h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Anonymous site identifier (hashed - cannot be reversed to reveal your domain)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Plugin version', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('WordPress version', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('PHP version', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Which tracking features are enabled (page views, forms)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Whether Elementor Pro is active (yes/no)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Whether WooCommerce is active (yes/no)', 'meta-conversions-api'); ?></li>
                </ul>

                <h3 style="margin-top: 20px;"><?php esc_html_e('What We DO NOT Collect:', 'meta-conversions-api'); ?></h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Your domain name or URL', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Any personal information', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Any customer or user data', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Your Facebook Dataset ID or Access Token', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Any tracking data sent to Facebook', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Page content, URLs, or visitor information', 'meta-conversions-api'); ?></li>
                </ul>

                <h3 style="margin-top: 20px;"><?php esc_html_e('Why We Collect This:', 'meta-conversions-api'); ?></h3>
                <p><?php esc_html_e('This anonymous data helps us:', 'meta-conversions-api'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Understand which plugin versions are in use', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Prioritize features that users actually need', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Ensure compatibility with popular PHP and WordPress versions', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Make better decisions about future development', 'meta-conversions-api'); ?></li>
                </ul>

                <h3 style="margin-top: 20px;"><?php esc_html_e('How to Opt-Out:', 'meta-conversions-api'); ?></h3>
                <p>
                    <?php esc_html_e('You can disable analytics collection at any time in', 'meta-conversions-api'); ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api#analytics-settings')); ?>">
                        <?php esc_html_e('Settings', 'meta-conversions-api'); ?>
                    </a>.
                    <?php esc_html_e('Simply check the "Disable Anonymous Analytics" option.', 'meta-conversions-api'); ?>
                </p>

                <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin-top: 20px;">
                    <strong><?php esc_html_e('Privacy Commitment:', 'meta-conversions-api'); ?></strong>
                    <p style="margin: 10px 0 0 0;">
                        <?php esc_html_e('We take privacy seriously. The site identifier is a one-way hash (MD5) that cannot be reversed to reveal your domain. We literally cannot see who you are or what your website is.', 'meta-conversions-api'); ?>
                    </p>
                </div>
            </div>

            <div class="card" id="plugin-updates" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Plugin Updates', 'meta-conversions-api'); ?></h2>
                <p><?php esc_html_e('This plugin automatically checks for updates from GitHub once per week.', 'meta-conversions-api'); ?></p>
                
                <h3><?php esc_html_e('Automatic Updates', 'meta-conversions-api'); ?></h3>
                <p><?php esc_html_e('When a new version is available:', 'meta-conversions-api'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('You\'ll see an update notification on the Plugins page', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Click "Update Now" to install the latest version', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('The update installs automatically - no manual download needed', 'meta-conversions-api'); ?></li>
                </ul>

                <h3 style="margin-top: 20px;"><?php esc_html_e('Manual Update Check', 'meta-conversions-api'); ?></h3>
                <p><?php esc_html_e('Don\'t want to wait for the weekly check? Force an immediate update check:', 'meta-conversions-api'); ?></p>
                
                <p><strong><?php esc_html_e('Option 1: Use the Tools Page', 'meta-conversions-api'); ?></strong></p>
                <p>
                    <?php esc_html_e('Go to', 'meta-conversions-api'); ?> 
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools')); ?>"><?php esc_html_e('Tools & Logs', 'meta-conversions-api'); ?></a> 
                    <?php esc_html_e('and click "Check for Updates Now"', 'meta-conversions-api'); ?>
                </p>

                <p style="margin-top: 15px;"><strong><?php esc_html_e('Option 2: Use This Quick Link', 'meta-conversions-api'); ?></strong></p>
                <p><?php esc_html_e('Bookmark or copy this URL to force an update check anytime:', 'meta-conversions-api'); ?></p>
                <div style="background: #f0f0f1; padding: 12px; border-radius: 4px; margin: 10px 0; font-family: monospace; word-break: break-all;">
                    <?php echo esc_url(admin_url('?meta_capi_check_updates=1')); ?>
                </div>
                <p class="description">
                    <?php esc_html_e('Click to test:', 'meta-conversions-api'); ?> 
                    <a href="<?php echo esc_url(admin_url('?meta_capi_check_updates=1')); ?>" target="_blank"><?php esc_html_e('Force Update Check Now', 'meta-conversions-api'); ?></a>
                </p>

                <h3 style="margin-top: 20px;"><?php esc_html_e('Latest Releases', 'meta-conversions-api'); ?></h3>
                <p>
                    <?php esc_html_e('View changelog and download releases:', 'meta-conversions-api'); ?> 
                    <a href="https://github.com/wpbooster-cloud/meta-conversions-api/releases" target="_blank"><?php esc_html_e('GitHub Releases', 'meta-conversions-api'); ?></a>
                </p>
            </div>

            <div class="card" id="troubleshooting" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Troubleshooting', 'meta-conversions-api'); ?></h2>
                
                <h3><?php esc_html_e('Events Not Showing in Facebook?', 'meta-conversions-api'); ?></h3>
                <ol style="line-height: 1.8;">
                    <li><?php esc_html_e('Verify your Dataset ID and Access Token are correct', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Enable Debug Logging in Settings', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Check logs in Tools & Logs page', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Wait 1-2 minutes (Facebook processing delay)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Use Test Event Code to verify in Test Events tab', 'meta-conversions-api'); ?></li>
                </ol>

                <h3 style="margin-top: 20px;"><?php esc_html_e('Debug Log Management', 'meta-conversions-api'); ?></h3>
                <p>
                    <?php esc_html_e('View and download logs directly in the', 'meta-conversions-api'); ?> 
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools#log-viewer')); ?>"><?php esc_html_e('Tools & Logs', 'meta-conversions-api'); ?></a> 
                    <?php esc_html_e('page - no FTP required!', 'meta-conversions-api'); ?>
                </p>
                <p><strong><?php esc_html_e('Automatic Log Management:', 'meta-conversions-api'); ?></strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Log files are automatically capped at 10MB per file', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Old logs are automatically deleted after 30 days', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Daily automatic cleanup runs in the background', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Logs are stored in: /wp-content/uploads/meta-capi-logs/', 'meta-conversions-api'); ?></li>
                </ul>
                <p style="color: #d63638;">
                    <strong><?php esc_html_e('Note:', 'meta-conversions-api'); ?></strong> 
                    <?php esc_html_e('Remember to disable debug logging once troubleshooting is complete to prevent unnecessary log generation.', 'meta-conversions-api'); ?>
                </p>
            </div>

            <div class="card" id="useful-links" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Useful Links', 'meta-conversions-api'); ?></h2>
                <ul style="line-height: 2;">
                    <li><a href="https://business.facebook.com/events_manager2" target="_blank"><?php esc_html_e('Facebook Events Manager', 'meta-conversions-api'); ?></a></li>
                    <li><a href="https://developers.facebook.com/docs/marketing-api/conversions-api" target="_blank"><?php esc_html_e('Facebook Conversions API Documentation', 'meta-conversions-api'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api')); ?>"><?php esc_html_e('Plugin Settings', 'meta-conversions-api'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools')); ?>"><?php esc_html_e('Tools & Logs', 'meta-conversions-api'); ?></a></li>
                </ul>
            </div>

                </div>

                <?php $this->render_sidebar(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render tools page.
     */
    public function render_tools_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle clear logs action
        if (isset($_POST['clear_logs']) && check_admin_referer('meta_capi_clear_logs')) {
            $this->clear_all_logs();
        }

        // Handle toggle debug logging
        if (isset($_POST['toggle_debug']) && check_admin_referer('meta_capi_toggle_debug')) {
            $current = get_option('meta_capi_enable_logging', false);
            update_option('meta_capi_enable_logging', !$current);
            
            add_settings_error(
                'meta_capi_tools',
                'debug_toggled',
                sprintf(
                    __('Debug logging %s.', 'meta-conversions-api'),
                    !$current ? __('enabled', 'meta-conversions-api') : __('disabled', 'meta-conversions-api')
                ),
                'success'
            );
            settings_errors('meta_capi_tools');
        }

        // Show update check success message
        if (isset($_GET['update_checked']) && $_GET['update_checked'] === '1') {
            add_settings_error(
                'meta_capi_tools',
                'update_checked',
                __('Update check completed! If an update is available, you\'ll see it on the Plugins page.', 'meta-conversions-api'),
                'success'
            );
            settings_errors('meta_capi_tools');
        }

        // Handle download log
        if (isset($_GET['download_log']) && check_admin_referer('meta_capi_download_log', '_wpnonce')) {
            $this->download_log();
            exit;
        }

        $logger = new Meta_CAPI_Logger();
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/meta-capi-logs';
        $log_file = $log_dir . '/meta-capi-' . gmdate('Y-m-d') . '.log';
        
        ?>
        <div class="wrap meta-capi-admin-wrap">
            <h1><?php esc_html_e('Tools & Logs', 'meta-conversions-api'); ?></h1>

            <?php $this->render_tabs('tools'); ?>
            
            <div class="meta-capi-admin-container">
                <div class="meta-capi-admin-main">

            <!-- Debug Controls -->
            <div class="card" id="debug-logging" style="max-width: 100%;">
                <h2><?php esc_html_e('Debug Logging', 'meta-conversions-api'); ?></h2>
                <p>
                    <?php esc_html_e('Debug logging status:', 'meta-conversions-api'); ?>
                    <strong><?php echo get_option('meta_capi_enable_logging') ? esc_html__('Enabled', 'meta-conversions-api') : esc_html__('Disabled', 'meta-conversions-api'); ?></strong>
                </p>
                <?php if (get_option('meta_capi_enable_logging')): ?>
                    <p style="color: #d63638;">
                        ⚠️ <?php esc_html_e('Debug logging is currently active. This will create log files for every event.', 'meta-conversions-api'); ?>
                    </p>
                <?php endif; ?>
                <form method="post" style="margin-top: 10px;">
                    <?php wp_nonce_field('meta_capi_toggle_debug'); ?>
                    <input type="hidden" name="toggle_debug" value="1">
                    <?php if (get_option('meta_capi_enable_logging')): ?>
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Disable Debug Logging', 'meta-conversions-api'); ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Enable Debug Logging', 'meta-conversions-api'); ?>
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Update Check -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Plugin Updates', 'meta-conversions-api'); ?></h2>
                <p>
                    <?php esc_html_e('Current version:', 'meta-conversions-api'); ?>
                    <strong><?php echo esc_html(META_CAPI_VERSION); ?></strong>
                </p>
                <p class="description">
                    <?php esc_html_e('The plugin automatically checks for updates weekly. Use the button below to force an immediate update check.', 'meta-conversions-api'); ?>
                </p>
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('meta_capi_force_update', 'meta_capi_update_nonce'); ?>
                    <input type="hidden" name="meta_capi_force_update_check" value="1">
                    <button type="submit" class="button button-secondary">
                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                        <?php esc_html_e('Check for Updates Now', 'meta-conversions-api'); ?>
                    </button>
                </form>
                <p class="description" style="margin-top: 15px;">
                    <?php 
                    echo wp_kses_post(
                        sprintf(
                            __('Or use this quick link: <a href="%s">Force Update Check</a>', 'meta-conversions-api'),
                            esc_url(admin_url('?meta_capi_check_updates=1'))
                        )
                    );
                    ?>
                </p>
            </div>

            <!-- System Status -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('System Status', 'meta-conversions-api'); ?></h2>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td style="width: 30%;"><strong><?php esc_html_e('Plugin Version', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo esc_html(META_CAPI_VERSION); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('PHP Version', 'meta-conversions-api'); ?></strong></td>
                            <td>
                                <?php 
                                $php_version = PHP_VERSION;
                                $php_required = '7.4';
                                $php_ok = version_compare($php_version, $php_required, '>=');
                                echo $php_ok ? '✅ ' : '❌ ';
                                echo esc_html($php_version);
                                if (!$php_ok) {
                                    echo ' <span style="color: #d63638;">(' . sprintf(esc_html__('Requires %s or higher', 'meta-conversions-api'), $php_required) . ')</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('WordPress Version', 'meta-conversions-api'); ?></strong></td>
                            <td>
                                <?php 
                                $wp_version = get_bloginfo('version');
                                $wp_required = '6.0';
                                $wp_ok = version_compare($wp_version, $wp_required, '>=');
                                echo $wp_ok ? '✅ ' : '❌ ';
                                echo esc_html($wp_version);
                                if (!$wp_ok) {
                                    echo ' <span style="color: #d63638;">(' . sprintf(esc_html__('Requires %s or higher', 'meta-conversions-api'), $wp_required) . ')</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Dataset ID Configured', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo !empty(get_option('meta_capi_pixel_id')) ? '✅ ' . esc_html__('Yes', 'meta-conversions-api') : '❌ ' . esc_html__('No', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Access Token Configured', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo !empty(get_option('meta_capi_access_token')) ? '✅ ' . esc_html__('Yes', 'meta-conversions-api') : '❌ ' . esc_html__('No', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Page View Tracking', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo get_option('meta_capi_enable_page_view') ? '✅ ' . esc_html__('Enabled', 'meta-conversions-api') : '⚪ ' . esc_html__('Disabled', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Form Tracking', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo get_option('meta_capi_enable_form_tracking') ? '✅ ' . esc_html__('Enabled', 'meta-conversions-api') : '⚪ ' . esc_html__('Disabled', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Debug Logging', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo get_option('meta_capi_enable_logging') ? '🟡 ' . esc_html__('Enabled', 'meta-conversions-api') : '⚪ ' . esc_html__('Disabled', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Test Event Code', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo !empty(get_option('meta_capi_test_event_code')) ? '🔵 ' . esc_html__('Active', 'meta-conversions-api') : '⚪ ' . esc_html__('Not Set', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Elementor Pro', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo did_action('elementor_pro/init') ? '✅ ' . esc_html__('Active', 'meta-conversions-api') : '⚪ ' . esc_html__('Not Active', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('WooCommerce', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo class_exists('WooCommerce') ? '✅ ' . esc_html__('Active', 'meta-conversions-api') : '⚪ ' . esc_html__('Not Active', 'meta-conversions-api'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>


            <!-- Log Viewer -->
            <div class="card" id="log-viewer" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Recent Log Entries', 'meta-conversions-api'); ?></h2>
                
                <?php if (!get_option('meta_capi_enable_logging')): ?>
                    <div class="notice notice-info inline">
                        <p><?php esc_html_e('Debug logging is currently disabled. Enable it above to start logging events.', 'meta-conversions-api'); ?></p>
                    </div>
                <?php elseif (file_exists($log_file)): ?>
                    <p>
                        <strong><?php esc_html_e('Log file:', 'meta-conversions-api'); ?></strong> 
                        <code><?php echo esc_html(basename($log_file)); ?></code>
                        (<?php echo esc_html(size_format(filesize($log_file))); ?>)
                    </p>
                    
                    <p>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools&download_log=1'), 'meta_capi_download_log')); ?>" class="button button-secondary">
                            <?php esc_html_e('Download Log File', 'meta-conversions-api'); ?>
                        </a>
                    </p>
                    
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; padding: 10px; background: #f6f7f9; border: 1px solid #dcdcde; border-radius: 4px; font-weight: 600;">
                            <?php esc_html_e('View Log Entries (Last 20)', 'meta-conversions-api'); ?>
                        </summary>
                        
                        <?php
                        $log_content = file_get_contents($log_file);
                        $log_lines = explode('---', $log_content);
                        $recent_logs = array_slice(array_reverse($log_lines), 0, 20);
                        ?>
                        
                        <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; max-height: 500px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.6; margin-top: 10px;">
                            <?php
                            foreach ($recent_logs as $log_entry) {
                                if (trim($log_entry)) {
                                    echo '<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #ddd;">';
                                    echo nl2br(esc_html(trim($log_entry)));
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                    </details>
                <?php else: ?>
                    <p><?php esc_html_e('No log file found for today. Logs will be created when events are tracked.', 'meta-conversions-api'); ?></p>
                <?php endif; ?>
            </div>

            <!-- Clear Logs -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Clear Logs', 'meta-conversions-api'); ?></h2>
                <p><?php esc_html_e('Remove all debug log files. This action cannot be undone.', 'meta-conversions-api'); ?></p>
                
                <?php
                $log_files = glob($log_dir . '/meta-capi-*.log');
                $total_size = 0;
                if ($log_files) {
                    foreach ($log_files as $file) {
                        $total_size += filesize($file);
                    }
                }
                ?>
                
                <p>
                    <strong><?php esc_html_e('Current logs:', 'meta-conversions-api'); ?></strong>
                    <?php echo count($log_files); ?> <?php esc_html_e('files', 'meta-conversions-api'); ?>
                    (<?php echo esc_html(size_format($total_size)); ?>)
                </p>
                
                <form method="post" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete all log files? This cannot be undone.', 'meta-conversions-api'); ?>');">
                    <?php wp_nonce_field('meta_capi_clear_logs'); ?>
                    <input type="hidden" name="clear_logs" value="1">
                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e('Clear All Logs', 'meta-conversions-api'); ?>
                    </button>
                </form>
            </div>

            <!-- Log File Location -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Log File Location', 'meta-conversions-api'); ?></h2>
                <p>
                    <?php esc_html_e('Debug logs are stored at:', 'meta-conversions-api'); ?><br>
                    <code><?php echo esc_html($log_dir); ?>/meta-capi-YYYY-MM-DD.log</code>
                </p>
                <p>
                    <?php esc_html_e('You can access these files via:', 'meta-conversions-api'); ?>
                </p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('FTP/SFTP client', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('cPanel File Manager', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('WordPress File Manager plugin', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('SSH (if available)', 'meta-conversions-api'); ?></li>
                </ul>
                <p>
                    <em><?php esc_html_e('Note: Log files older than 30 days are automatically deleted.', 'meta-conversions-api'); ?></em>
                </p>
            </div>

                </div>

                <?php $this->render_sidebar(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Clear all log files.
     */
    private function clear_all_logs(): void {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/meta-capi-logs';
        $files = glob($log_dir . '/meta-capi-*.log');
        
        $count = 0;
        foreach ($files as $file) {
            if (wp_delete_file($file)) {
                $count++;
            }
        }

        add_settings_error(
            'meta_capi_tools',
            'logs_cleared',
            sprintf(
                __('%d log file(s) deleted successfully.', 'meta-conversions-api'),
                $count
            ),
            'success'
        );
        
        settings_errors('meta_capi_tools');
    }

    /**
     * Download log file.
     */
    private function download_log(): void {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/meta-capi-logs';
        $log_file = $log_dir . '/meta-capi-' . gmdate('Y-m-d') . '.log';

        if (!file_exists($log_file)) {
            wp_die(esc_html__('Log file not found.', 'meta-conversions-api'));
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="meta-capi-' . gmdate('Y-m-d') . '.log"');
        header('Content-Length: ' . filesize($log_file));
        readfile($log_file);
    }
}

