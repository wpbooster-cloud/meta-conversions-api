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
        
        // Initialize WooCommerce event defaults when WooCommerce tracking is enabled.
        add_action('update_option_meta_capi_enable_woocommerce', [$this, 'maybe_initialize_wc_defaults'], 10, 2);
        add_action('add_option_meta_capi_enable_woocommerce', [$this, 'initialize_wc_defaults_on_add'], 10, 2);
    }

    /**
     * Sanitize WooCommerce enable option and initialize defaults if needed.
     *
     * @param mixed $value The submitted value.
     * @return bool The sanitized boolean value.
     */
    public function sanitize_woocommerce_enable($value): bool {
        $sanitized = rest_sanitize_boolean($value);
        $old_value = get_option('meta_capi_enable_woocommerce', false);
        
        // If being enabled (was false, now true), initialize defaults.
        if (!$old_value && $sanitized) {
            // Check if events have been initialized before.
            $initialized = get_option('meta_capi_wc_events_initialized', false);
            
            if (!$initialized) {
                // Set defaults for event checkboxes (they'll be saved with the form).
                $_POST['meta_capi_wc_enable_viewcontent'] = '1';
                $_POST['meta_capi_wc_enable_addtocart'] = '1';
                $_POST['meta_capi_wc_enable_initiatecheckout'] = '1';
                $_POST['meta_capi_wc_enable_purchase'] = '1';
                
                // Set purchase timing default.
                if (empty($_POST['meta_capi_wc_purchase_timing'])) {
                    $_POST['meta_capi_wc_purchase_timing'] = 'placed';
                }
                
                // Mark as initialized.
                update_option('meta_capi_wc_events_initialized', '1');
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Initialize WooCommerce event defaults when tracking is first enabled (via update).
     *
     * @param mixed $old_value Previous value.
     * @param mixed $new_value New value.
     */
    public function maybe_initialize_wc_defaults($old_value, $new_value): void {
        // This is now handled in sanitize callback.
        // Kept for backwards compatibility if needed.
    }
    
    /**
     * Initialize WooCommerce event defaults when option is first created (via add).
     *
     * @param string $option Option name.
     * @param mixed $value Option value.
     */
    public function initialize_wc_defaults_on_add($option, $value): void {
        // This is now handled in sanitize callback.
        // Kept for backwards compatibility if needed.
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

        register_setting('meta_capi_settings', 'meta_capi_enable_pixel', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
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

        register_setting('meta_capi_settings', 'meta_capi_enable_woocommerce', [
            'type' => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_woocommerce_enable'],
            'default' => false,
        ]);

        // WooCommerce event tracking settings.
        register_setting('meta_capi_settings', 'meta_capi_wc_purchase_timing', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'placed',
        ]);

        register_setting('meta_capi_settings', 'meta_capi_wc_enable_viewcontent', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('meta_capi_settings', 'meta_capi_wc_enable_addtocart', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('meta_capi_settings', 'meta_capi_wc_enable_initiatecheckout', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);

        register_setting('meta_capi_settings', 'meta_capi_wc_enable_purchase', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ]);
        
        // WooCommerce initialization flag (internal, not displayed).
        register_setting('meta_capi_settings', 'meta_capi_wc_events_initialized', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);

        // Pixel settings.
        register_setting('meta_capi_settings', 'meta_capi_disable_auto_config', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true, // Default to true (disable Facebook's auto-config)
        ]);

        // Note: meta_capi_enable_logging is handled separately on Tools page via toggle form

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
            __('Dataset ID (Pixel ID)', 'meta-conversions-api'),
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
            'meta_capi_enable_pixel',
            __('Enable Meta Pixel Injection', 'meta-conversions-api'),
            [$this, 'render_pixel_injection_field'],
            'meta-conversions-api',
            'meta_capi_tracking'
        );

        add_settings_field(
            'meta_capi_disable_auto_config',
            __('Disable Facebook Auto-Config', 'meta-conversions-api'),
            [$this, 'render_disable_auto_config_field'],
            'meta-conversions-api',
            'meta_capi_tracking'
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
            'setup' => [
                'title' => __('Setup Guide', 'meta-conversions-api'),
                'url' => admin_url('options-general.php?page=meta-conversions-api&tab=setup'),
            ],
            'troubleshooting' => [
                'title' => __('Troubleshooting', 'meta-conversions-api'),
                'url' => admin_url('options-general.php?page=meta-conversions-api&tab=troubleshooting'),
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
            case 'setup':
                $this->render_setup_guide_page();
                break;
            case 'troubleshooting':
                $this->render_troubleshooting_page();
                break;
            case 'tools':
                $this->render_tools_page();
                break;
            // Legacy redirect for old 'documentation' tab
            case 'documentation':
                $this->render_setup_guide_page();
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
            
            <?php 
            // Manually call admin notices since hook isn't firing
            $this->show_admin_notices(); 
            ?>

            <?php
            // Show recommendations panel if there are any
            $plugin_instance = meta_capi();
            if (isset($plugin_instance->system_status)) {
                $status = $plugin_instance->system_status->get_status();
                $has_recommendations = !empty($status['recommendations']);
                $rec_count = count($status['recommendations'] ?? []);
                
                if ($has_recommendations) {
                    ?>
                    <div class="notice notice-info" style="position: relative; margin-top: 10px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 5px 0;">
                            <div>
                                <strong>💡 <?php echo sprintf(esc_html__('%d Recommendation%s', 'meta-conversions-api'), $rec_count, $rec_count > 1 ? 's' : ''); ?></strong>
                                <button type="button" class="button-link" id="toggle-recommendations" style="margin-left: 10px; text-decoration: none;">
                                    <span class="dashicons dashicons-arrow-down-alt2" style="font-size: 16px; line-height: 1.2;"></span>
                                    <?php esc_html_e('Show Details', 'meta-conversions-api'); ?>
                                </button>
                            </div>
                        </div>
                        <div id="recommendations-content" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; padding-right: 10px;">
                            <?php foreach ($status['recommendations'] as $rec): ?>
                                <div style="margin-bottom: 15px;">
                                    <strong><?php echo esc_html($rec['title']); ?></strong><br>
                                    <span style="color: #646970;"><?php echo esc_html($rec['message']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <script>
                    jQuery(document).ready(function($) {
                        $('#toggle-recommendations').on('click', function(e) {
                            e.preventDefault();
                            var content = $('#recommendations-content');
                            var icon = $(this).find('.dashicons');
                            var text = $(this).contents().filter(function(){ return this.nodeType === 3; }).last();
                            
                            if (content.is(':visible')) {
                                content.slideUp(200);
                                icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                                text[0].textContent = ' <?php esc_html_e('Show Details', 'meta-conversions-api'); ?>';
                            } else {
                                content.slideDown(200);
                                icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                                text[0].textContent = ' <?php esc_html_e('Hide Details', 'meta-conversions-api'); ?>';
                            }
                        });
                    });
                    </script>
                    <?php
                }
            }
            ?>

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
            <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=setup#anonymous-analytics')); ?>">
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
            <?php esc_html_e('Your Facebook Dataset ID (formerly called Pixel ID) - a 15-16 digit number from Events Manager.', 'meta-conversions-api'); ?>
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
     * Render Pixel injection field.
     */
    public function render_pixel_injection_field(): void {
        $value = get_option('meta_capi_enable_pixel', true);
        ?>
        <label>
            <input
                type="checkbox"
                name="meta_capi_enable_pixel"
                id="meta_capi_enable_pixel"
                value="1"
                <?php checked($value, true); ?>
            >
            <?php esc_html_e('Automatically inject Meta Pixel code on all pages', 'meta-conversions-api'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Adds the Meta Pixel (fbq) JavaScript to your site for browser-side tracking. Disable this if you already have the pixel installed via another method.', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

    /**
     * Render disable auto-config field.
     */
    public function render_disable_auto_config_field(): void {
        $value = get_option('meta_capi_disable_auto_config', true);
        ?>
        <label>
            <input
                type="checkbox"
                name="meta_capi_disable_auto_config"
                id="meta_capi_disable_auto_config"
                value="1"
                <?php checked($value, true); ?>
            >
            <?php esc_html_e('Disable Facebook\'s automatic event tracking', 'meta-conversions-api'); ?>
            <span style="color: #00a32a; margin-left: 8px;">✓ <?php esc_html_e('Recommended', 'meta-conversions-api'); ?></span>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, prevents Facebook from automatically logging button clicks and other events. This keeps your event data clean by only tracking the specific events you configure. Recommended for accurate conversion tracking.', 'meta-conversions-api'); ?>
        </p>
        <?php
    }

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
        $enabled = get_option('meta_capi_enable_woocommerce', false);
        $wc_active = class_exists('WooCommerce');
        
        // Get individual event settings with proper defaults.
        // If option doesn't exist (false returned), default to true for first-time setup.
        $viewcontent_enabled = get_option('meta_capi_wc_enable_viewcontent');
        if ($viewcontent_enabled === false) {
            $viewcontent_enabled = true;
        }
        
        $addtocart_enabled = get_option('meta_capi_wc_enable_addtocart');
        if ($addtocart_enabled === false) {
            $addtocart_enabled = true;
        }
        
        $initiatecheckout_enabled = get_option('meta_capi_wc_enable_initiatecheckout');
        if ($initiatecheckout_enabled === false) {
            $initiatecheckout_enabled = true;
        }
        
        $purchase_enabled = get_option('meta_capi_wc_enable_purchase');
        if ($purchase_enabled === false) {
            $purchase_enabled = true;
        }
        
        $purchase_timing = get_option('meta_capi_wc_purchase_timing', 'placed');
        if (empty($purchase_timing)) {
            $purchase_timing = 'placed';
        }
        ?>
        <label<?php echo !$wc_active ? ' style="opacity: 0.5;"' : ''; ?>>
            <input
                type="checkbox"
                name="meta_capi_enable_woocommerce"
                id="meta_capi_enable_woocommerce"
                value="1"
                <?php checked($enabled, true); ?>
                <?php disabled(!$wc_active); ?>
            >
            <strong><?php esc_html_e('Enable WooCommerce Event Tracking', 'meta-conversions-api'); ?></strong>
            <?php if (!$wc_active): ?>
                <span style="color: #d63638; margin-left: 8px;">
                    <?php esc_html_e('(WooCommerce not active)', 'meta-conversions-api'); ?>
                </span>
            <?php endif; ?>
        </label>
        <p class="description">
            <?php 
            if ($wc_active) {
                esc_html_e('Track WooCommerce events via Meta Pixel (browser) and Conversions API (server) for accurate conversion tracking.', 'meta-conversions-api');
            } else {
                esc_html_e('WooCommerce must be installed and activated to enable eCommerce tracking.', 'meta-conversions-api');
            }
            ?>
        </p>

        <?php if ($wc_active && $enabled): ?>
        <div style="margin-top: 15px; padding: 15px; background: #f6f7f9; border: 1px solid #dcdcde; border-radius: 4px;">
            <h4 style="margin-top: 0;"><?php esc_html_e('WooCommerce Events', 'meta-conversions-api'); ?></h4>
            
            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="meta_capi_wc_enable_viewcontent" value="1" <?php checked($viewcontent_enabled, true); ?>>
                <?php esc_html_e('ViewContent (Product page views)', 'meta-conversions-api'); ?>
            </label>

            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="meta_capi_wc_enable_addtocart" value="1" <?php checked($addtocart_enabled, true); ?>>
                <?php esc_html_e('AddToCart (Items added to cart)', 'meta-conversions-api'); ?>
            </label>

            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="meta_capi_wc_enable_initiatecheckout" value="1" <?php checked($initiatecheckout_enabled, true); ?>>
                <?php esc_html_e('InitiateCheckout (Checkout started)', 'meta-conversions-api'); ?>
            </label>

            <label style="display: block; margin-bottom: 15px;">
                <input type="checkbox" name="meta_capi_wc_enable_purchase" value="1" <?php checked($purchase_enabled, true); ?>>
                <?php esc_html_e('Purchase (Order completed)', 'meta-conversions-api'); ?>
            </label>

            <hr style="margin: 15px 0; border: none; border-top: 1px solid #dcdcde;">

            <h4 style="margin-bottom: 10px;"><?php esc_html_e('Purchase Event Timing', 'meta-conversions-api'); ?></h4>
            <p class="description" style="margin-top: 0; margin-bottom: 10px;">
                <?php esc_html_e('Choose when to send Purchase events to Facebook:', 'meta-conversions-api'); ?>
            </p>

            <label style="display: block; margin-bottom: 10px;">
                <input type="radio" name="meta_capi_wc_purchase_timing" value="placed" <?php checked($purchase_timing, 'placed'); ?>>
                <strong><?php esc_html_e('When order is placed', 'meta-conversions-api'); ?></strong>
                <span class="description"><?php esc_html_e('(includes unpaid orders like COD, bank transfer)', 'meta-conversions-api'); ?></span>
            </label>

            <label style="display: block;">
                <input type="radio" name="meta_capi_wc_purchase_timing" value="paid" <?php checked($purchase_timing, 'paid'); ?>>
                <strong><?php esc_html_e('When payment is confirmed', 'meta-conversions-api'); ?></strong>
                <span class="description"><?php esc_html_e('(only paid orders, recommended for accurate ROAS)', 'meta-conversions-api'); ?></span>
            </label>
        </div>
        <?php endif; ?>
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
        // Test result notice
        $test_result = get_transient('meta_capi_test_result');
        if ($test_result) {
            delete_transient('meta_capi_test_result');
            $notice_class = $test_result['type'] === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible">';
            echo '<p>' . esc_html($test_result['message']) . '</p>';
            echo '</div>';
        }

        // Debug logging warning
        if (get_option('meta_capi_enable_logging')) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>';
            echo '<strong>' . esc_html__('Meta CAPI Debug Logging Active', 'meta-conversions-api') . '</strong><br>';
            echo esc_html__('Debug logging is currently enabled. This will log all events and API requests. Remember to disable it once you\'re done troubleshooting.', 'meta-conversions-api') . ' ';
            echo '<a href="' . esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools#debug-logging')) . '">';
            echo esc_html__('Disable in Tools & Logs', 'meta-conversions-api');
            echo '</a>';
            echo '</p>';
            echo '</div>';
        }

        // Test event code notice
        $test_code = get_option('meta_capi_test_event_code', '');
        if (!empty($test_code)) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p>';
            echo '<strong>' . esc_html__('Test Event Mode Active', 'meta-conversions-api') . '</strong><br>';
            
            $message = sprintf(
                esc_html__('Test Event Code (%s) is active. Events are being sent as test events and won\'t affect your production statistics.', 'meta-conversions-api'),
                '<code>' . esc_html($test_code) . '</code>'
            );
            echo $message . ' ';
            
            echo '<a href="' . esc_url(admin_url('options-general.php?page=meta-conversions-api#test-event-code')) . '">';
            echo esc_html__('Remove in Settings', 'meta-conversions-api');
            echo '</a>';
            echo '</p>';
            echo '</div>';
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
     * Render setup guide page.
     */
    public function render_setup_guide_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap meta-capi-admin-wrap">
            <h1><?php esc_html_e('Setup Guide', 'meta-conversions-api'); ?></h1>
            
            <?php 
            // Manually call admin notices since hook isn't firing
            $this->show_admin_notices(); 
            ?>

            <?php $this->render_tabs('setup'); ?>
            
            <div class="meta-capi-admin-container">
                <div class="meta-capi-admin-main">

            <div class="card" style="max-width: 100%;">
                <h2><?php esc_html_e('Quick Navigation', 'meta-conversions-api'); ?></h2>
                <ul style="column-count: 2; column-gap: 30px; list-style: disc; margin-left: 20px;">
                    <li><a href="#quick-start"><?php esc_html_e('Quick Start Guide', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#what-tracked"><?php esc_html_e('What Gets Tracked', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#woocommerce-setup"><?php esc_html_e('WooCommerce Setup', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#privacy"><?php esc_html_e('Privacy & Data Handling', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#anonymous-analytics"><?php esc_html_e('Anonymous Usage Analytics', 'meta-conversions-api'); ?></a></li>
                    <li><a href="#plugin-updates"><?php esc_html_e('Plugin Updates', 'meta-conversions-api'); ?></a></li>
                </ul>
                <p style="margin-top: 15px;">
                    <?php 
                    echo wp_kses_post(
                        sprintf(
                            __('Having issues? Check the <a href="%s">Troubleshooting</a> page.', 'meta-conversions-api'),
                            esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=troubleshooting'))
                        )
                    );
                    ?>
                </p>
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

                <h3 style="margin-top: 20px;"><?php esc_html_e('WooCommerce Events', 'meta-conversions-api'); ?></h3>
                <p><?php esc_html_e('When WooCommerce tracking is enabled, the plugin tracks eCommerce events via both Meta Pixel (browser-side) and Conversions API (server-side) for maximum accuracy and deduplication:', 'meta-conversions-api'); ?></p>
                
                <h4 style="margin-top: 15px; font-size: 14px;"><?php esc_html_e('ViewContent', 'meta-conversions-api'); ?></h4>
                <p><?php esc_html_e('Fires when a customer views a product page. Tracks product ID, name, price, category, and currency.', 'meta-conversions-api'); ?></p>

                <h4 style="margin-top: 15px; font-size: 14px;"><?php esc_html_e('AddToCart', 'meta-conversions-api'); ?></h4>
                <p><?php esc_html_e('Fires when a customer adds an item to their cart. Works with both AJAX and traditional add-to-cart buttons. Tracks product details, quantity, and value.', 'meta-conversions-api'); ?></p>

                <h4 style="margin-top: 15px; font-size: 14px;"><?php esc_html_e('InitiateCheckout', 'meta-conversions-api'); ?></h4>
                <p><?php esc_html_e('Fires when a customer reaches the checkout page. Tracks cart contents, total value, and item count.', 'meta-conversions-api'); ?></p>

                <h4 style="margin-top: 15px; font-size: 14px;"><?php esc_html_e('Purchase', 'meta-conversions-api'); ?></h4>
                <p><?php esc_html_e('Fires when an order is completed. You can configure when this event triggers:', 'meta-conversions-api'); ?></p>
                <ul style="list-style: disc; margin-left: 20px; margin-top: 10px;">
                    <li><strong><?php esc_html_e('When order is placed:', 'meta-conversions-api'); ?></strong> <?php esc_html_e('Tracks all orders immediately, including unpaid orders (COD, bank transfer). Good for measuring order volume.', 'meta-conversions-api'); ?></li>
                    <li><strong><?php esc_html_e('When payment is confirmed:', 'meta-conversions-api'); ?></strong> <?php esc_html_e('Only tracks paid orders. Recommended for accurate ROAS (Return on Ad Spend) measurement.', 'meta-conversions-api'); ?></li>
                </ul>

                <div style="background: #f0f6fc; padding: 15px; border-left: 4px solid #2271b1; margin-top: 15px;">
                    <strong><?php esc_html_e('⚙️ Configuration Note:', 'meta-conversions-api'); ?></strong>
                    <p style="margin: 10px 0 0 0;">
                        <?php esc_html_e('Each WooCommerce event can be individually enabled/disabled in', 'meta-conversions-api'); ?>
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api#tracking-settings')); ?>">
                            <?php esc_html_e('Tracking Settings', 'meta-conversions-api'); ?>
                        </a>.
                        <?php esc_html_e('Events are sent via both browser (Pixel) and server (CAPI) with automatic deduplication using event IDs.', 'meta-conversions-api'); ?>
                    </p>
                </div>

                <h3 style="margin-top: 20px;"><?php esc_html_e('Facebook Auto-Config (Disabled by Default)', 'meta-conversions-api'); ?></h3>
                <p><?php esc_html_e('This plugin automatically disables Facebook\'s "Auto-Config" feature to keep your event data clean. Without this, Facebook would automatically track button clicks, form submissions, and other interactions that you haven\'t explicitly configured.', 'meta-conversions-api'); ?></p>
                
                <p style="margin-top: 10px;"><strong><?php esc_html_e('Why This Matters:', 'meta-conversions-api'); ?></strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Prevents noisy, irrelevant events from cluttering your Facebook Events Manager', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Ensures only the events YOU configure are tracked (PageView, Purchase, AddToCart, etc.)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Improves data accuracy for conversion optimization and reporting', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Recommended by Facebook for e-commerce sites', 'meta-conversions-api'); ?></li>
                </ul>

                <p style="margin-top: 10px;"><?php esc_html_e('You can enable Auto-Config in', 'meta-conversions-api'); ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api#tracking-settings')); ?>">
                        <?php esc_html_e('Tracking Settings', 'meta-conversions-api'); ?>
                    </a>
                    <?php esc_html_e('if needed, but it\'s not recommended for most sites.', 'meta-conversions-api'); ?>
                </p>
            </div>

            <div class="card" id="woocommerce-setup" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('WooCommerce Setup', 'meta-conversions-api'); ?></h2>
                <p><?php esc_html_e('To enable WooCommerce event tracking:', 'meta-conversions-api'); ?></p>
                
                <ol style="line-height: 2;">
                    <li>
                        <?php esc_html_e('Ensure WooCommerce is installed and active', 'meta-conversions-api'); ?>
                    </li>
                    <li>
                        <?php esc_html_e('Go to', 'meta-conversions-api'); ?>
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api#tracking-settings')); ?>">
                            <?php esc_html_e('Settings → Meta CAPI → Tracking Settings', 'meta-conversions-api'); ?>
                        </a>
                    </li>
                    <li>
                        <?php esc_html_e('Check "Enable WooCommerce Event Tracking"', 'meta-conversions-api'); ?>
                    </li>
                    <li>
                        <?php esc_html_e('Configure which events to track (ViewContent, AddToCart, InitiateCheckout, Purchase)', 'meta-conversions-api'); ?>
                    </li>
                    <li>
                        <?php esc_html_e('Choose your Purchase event timing:', 'meta-conversions-api'); ?>
                        <ul style="list-style: disc; margin-left: 20px; margin-top: 10px;">
                            <li><strong><?php esc_html_e('When order is placed:', 'meta-conversions-api'); ?></strong> <?php esc_html_e('For all orders including unpaid (default)', 'meta-conversions-api'); ?></li>
                            <li><strong><?php esc_html_e('When payment is confirmed:', 'meta-conversions-api'); ?></strong> <?php esc_html_e('For accurate ROAS tracking (recommended for most stores)', 'meta-conversions-api'); ?></li>
                        </ul>
                    </li>
                    <li>
                        <?php esc_html_e('Save Settings', 'meta-conversions-api'); ?>
                    </li>
                    <li>
                        <?php esc_html_e('Test by making a purchase and checking Facebook Events Manager', 'meta-conversions-api'); ?>
                    </li>
                </ol>

                <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin-top: 20px;">
                    <strong><?php esc_html_e('💡 Pro Tip:', 'meta-conversions-api'); ?></strong>
                    <p style="margin: 10px 0 0 0;">
                        <?php esc_html_e('All WooCommerce events are tracked via BOTH browser (Meta Pixel) and server (Conversions API) with automatic deduplication. This provides the most accurate conversion tracking possible, even if customers use ad blockers or have browser restrictions.', 'meta-conversions-api'); ?>
                    </p>
                </div>
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

                </div>

                <?php $this->render_sidebar(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render troubleshooting page.
     */
    public function render_troubleshooting_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap meta-capi-admin-wrap">
            <h1><?php esc_html_e('Troubleshooting', 'meta-conversions-api'); ?></h1>
            
            <?php 
            // Manually call admin notices since hook isn't firing
            $this->show_admin_notices(); 
            ?>

            <?php $this->render_tabs('troubleshooting'); ?>
            
            <div class="meta-capi-admin-container">
                <div class="meta-capi-admin-main">

            <div class="card" id="events-not-showing" style="max-width: 100%;">
                <h2><?php esc_html_e('Events Not Showing in Facebook?', 'meta-conversions-api'); ?></h2>
                <ol style="line-height: 1.8;">
                    <li><?php esc_html_e('Verify your Dataset ID and Access Token are correct', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Enable Debug Logging in', 'meta-conversions-api'); ?>
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools#debug-logging')); ?>">
                            <?php esc_html_e('Tools & Logs', 'meta-conversions-api'); ?>
                        </a>
                    </li>
                    <li><?php esc_html_e('Check logs in', 'meta-conversions-api'); ?>
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools#log-viewer')); ?>">
                            <?php esc_html_e('Tools & Logs page', 'meta-conversions-api'); ?>
                        </a>
                    </li>
                    <li><?php esc_html_e('Wait 1-2 minutes (Facebook processing delay)', 'meta-conversions-api'); ?></li>
                    <li><?php esc_html_e('Use Test Event Code to verify in Test Events tab', 'meta-conversions-api'); ?></li>
                </ol>
            </div>

            <div class="card" id="log-management" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Debug Log Management', 'meta-conversions-api'); ?></h2>
                <p>
                    <?php esc_html_e('View and download logs directly in the', 'meta-conversions-api'); ?> 
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=tools#log-viewer')); ?>">
                        <?php esc_html_e('Tools & Logs', 'meta-conversions-api'); ?>
                    </a> 
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
                    <li><a href="https://developers.facebook.com/docs/meta-pixel" target="_blank"><?php esc_html_e('Meta Pixel Documentation', 'meta-conversions-api'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api')); ?>"><?php esc_html_e('Plugin Settings', 'meta-conversions-api'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('options-general.php?page=meta-conversions-api&tab=setup')); ?>"><?php esc_html_e('Setup Guide', 'meta-conversions-api'); ?></a></li>
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
            
            <?php 
            // Manually call admin notices since hook isn't firing
            $this->show_admin_notices(); 
            ?>

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
                        <tr style="background-color: #d5e8f7;">
                            <td colspan="2" style="padding: 8px;"><strong><?php esc_html_e('System Information', 'meta-conversions-api'); ?></strong></td>
                        </tr>
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
                        <tr style="background-color: #d5e8f7;">
                            <td colspan="2" style="padding: 8px;"><strong><?php esc_html_e('Configuration', 'meta-conversions-api'); ?></strong></td>
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
                            <td><strong><?php esc_html_e('Test Event Code', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo !empty(get_option('meta_capi_test_event_code')) ? '🔵 ' . esc_html__('Active', 'meta-conversions-api') : '⚪ ' . esc_html__('Not Set', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr style="background-color: #d5e8f7;">
                            <td colspan="2" style="padding: 8px;"><strong><?php esc_html_e('Tracking Features', 'meta-conversions-api'); ?></strong></td>
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
                        <tr style="background-color: #d5e8f7;">
                            <td colspan="2" style="padding: 8px;"><strong><?php esc_html_e('Integrations', 'meta-conversions-api'); ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Elementor Pro', 'meta-conversions-api'); ?></strong></td>
                            <td><?php echo did_action('elementor_pro/init') ? '✅ ' . esc_html__('Active', 'meta-conversions-api') : '⚪ ' . esc_html__('Not Active', 'meta-conversions-api'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('WooCommerce', 'meta-conversions-api'); ?></strong></td>
                            <td>
                                <?php 
                                if (class_exists('WooCommerce')) {
                                    echo '✅ ' . esc_html__('Active', 'meta-conversions-api');
                                    if (defined('WC_VERSION')) {
                                        echo ' (v' . esc_html(WC_VERSION) . ')';
                                    }
                                } else {
                                    echo '⚪ ' . esc_html__('Not Active', 'meta-conversions-api');
                                }
                                ?>
                            </td>
                        </tr>
                        <?php
                        // Get advanced system status
                        $plugin_instance = meta_capi();
                        if (isset($plugin_instance->system_status)) {
                            $status = $plugin_instance->system_status->get_status();
                            ?>
                            <tr style="background-color: #d5e8f7; border-top: 2px solid #72aee6;">
                                <td colspan="2" style="padding: 8px;"><strong><?php esc_html_e('Environment & Compatibility', 'meta-conversions-api'); ?></strong></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Hosting Provider', 'meta-conversions-api'); ?></strong></td>
                                <td>
                                    <?php 
                                    if (!empty($status['hosting']['detected'])) {
                                        echo '✅ ' . esc_html($status['hosting']['provider']);
                                    } else {
                                        echo '❓ ' . esc_html__('Standard/Unknown', 'meta-conversions-api');
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('CDN', 'meta-conversions-api'); ?></strong></td>
                                <td>
                                    <?php 
                                    if (!empty($status['cdn']['cloudflare'])) {
                                        echo '✅ Cloudflare';
                                        if ($status['cdn']['cloudflare_enterprise']) {
                                            echo ' <span style="color: #d63638; font-weight: bold;">(Enterprise)</span>';
                                        }
                                        if (!empty($status['cdn']['cloudflare_status'])) {
                                            echo ' - ' . esc_html($status['cdn']['cloudflare_status']);
                                        }
                                    } elseif (!empty($status['cdn']['other_cdn'])) {
                                        echo '✅ ' . esc_html($status['cdn']['cdn_name']);
                                    } else {
                                        echo '⚪ ' . esc_html__('Not Detected', 'meta-conversions-api');
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Caching Plugins', 'meta-conversions-api'); ?></strong></td>
                                <td>
                                    <?php 
                                    if (!empty($status['caching_plugins']['detected'])) {
                                        echo '✅ ' . esc_html(implode(', ', $status['caching_plugins']['plugins']));
                                    } else {
                                        echo '⚪ ' . esc_html__('None Detected', 'meta-conversions-api');
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
                
                <?php
                // Display warnings and recommendations
                if (isset($status) && (!empty($status['warnings']) || !empty($status['recommendations']))) {
                    ?>
                    <div style="margin-top: 20px;">
                        <?php if (!empty($status['warnings'])): ?>
                            <?php foreach ($status['warnings'] as $warning): ?>
                                <div class="notice notice-<?php echo esc_attr($warning['level'] === 'error' ? 'error' : 'warning'); ?> inline" style="margin: 10px 0;">
                                    <p>
                                        <strong><?php echo esc_html($warning['title']); ?></strong><br>
                                        <?php echo esc_html($warning['message']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($status['recommendations'])): ?>
                            <?php foreach ($status['recommendations'] as $rec): ?>
                                <div class="notice notice-info inline" style="margin: 10px 0;">
                                    <p>
                                        <strong><?php echo esc_html($rec['title']); ?></strong><br>
                                        <?php echo esc_html($rec['message']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php
                } elseif (isset($status)) {
                    ?>
                    <div class="notice notice-success inline" style="margin-top: 15px;">
                        <p>✅ <strong><?php esc_html_e('All compatibility checks passed!', 'meta-conversions-api'); ?></strong></p>
                    </div>
                    <?php
                }
                ?>
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
                        <button type="button" id="copy-log-btn" class="button button-secondary" style="margin-left: 8px;">
                            📋 <?php esc_html_e('Copy to Clipboard', 'meta-conversions-api'); ?>
                        </button>
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
                        
                        <div id="log-content" style="background: #f0f0f1; padding: 15px; border-radius: 4px; max-height: 500px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.6; margin-top: 10px;">
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
                    
                    <script>
                    document.getElementById('copy-log-btn').addEventListener('click', function() {
                        const logContent = <?php echo json_encode($log_content); ?>;
                        navigator.clipboard.writeText(logContent).then(() => {
                            const btn = this;
                            const originalText = btn.innerHTML;
                            btn.innerHTML = '✅ <?php esc_html_e('Copied!', 'meta-conversions-api'); ?>';
                            btn.style.background = '#00a32a';
                            btn.style.borderColor = '#00a32a';
                            btn.style.color = '#fff';
                            setTimeout(() => {
                                btn.innerHTML = originalText;
                                btn.style.background = '';
                                btn.style.borderColor = '';
                                btn.style.color = '';
                            }, 2000);
                        }).catch(err => {
                            alert('<?php esc_html_e('Failed to copy. Please copy manually.', 'meta-conversions-api'); ?>');
                        });
                    });
                    </script>
                    
                    <!-- Clear Logs Section -->
                    <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #dcdcde;">
                        <h3><?php esc_html_e('Clear Logs', 'meta-conversions-api'); ?></h3>
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
                    
                <?php else: ?>
                    <p><?php esc_html_e('No log file found for today. Logs will be created when events are tracked.', 'meta-conversions-api'); ?></p>
                <?php endif; ?>
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

