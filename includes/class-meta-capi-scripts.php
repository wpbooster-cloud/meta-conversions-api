<?php
/**
 * Script Management for Meta Conversions API.
 *
 * Handles intelligent script loading:
 * - Conditional loading based on settings
 * - Only loads on relevant pages
 * - Async/defer attributes
 * - Debug vs production versions
 * - Performance optimization
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
 * Meta CAPI Scripts Class.
 */
class Meta_CAPI_Scripts {

    /**
     * Meta CAPI Logger instance.
     *
     * @var Meta_CAPI_Logger
     */
    private Meta_CAPI_Logger $logger;

    /**
     * Whether to load debug versions of scripts.
     *
     * @var bool
     */
    private bool $debug_mode = false;

    /**
     * Plugin settings cache.
     *
     * @var array<string, mixed>
     */
    private array $settings = [];

    /**
     * Constructor.
     *
     * @param Meta_CAPI_Logger $logger Logger instance.
     */
    public function __construct(Meta_CAPI_Logger $logger) {
        $this->logger = $logger;

        // Load settings.
        $this->load_settings();

        // Initialize hooks.
        $this->init_hooks();
    }

    /**
     * Load plugin settings.
     *
     * @return void
     */
    private function load_settings(): void {
        $this->settings = [
            'pixel_id'                => get_option('meta_capi_pixel_id', ''),
            'access_token'            => get_option('meta_capi_access_token', ''),
            'pixel_auto_inject'       => (bool) get_option('meta_capi_pixel_auto_inject', true),
            'enable_page_view'        => (bool) get_option('meta_capi_enable_page_view', false),
            'enable_form_tracking'    => (bool) get_option('meta_capi_enable_form_tracking', false),
            'wc_enable_viewcontent'   => (bool) get_option('meta_capi_wc_enable_viewcontent', true),
            'wc_enable_addtocart'     => (bool) get_option('meta_capi_wc_enable_addtocart', true),
            'wc_enable_checkout'      => (bool) get_option('meta_capi_wc_enable_initiatecheckout', true),
            'wc_enable_purchase'      => (bool) get_option('meta_capi_wc_enable_purchase', true),
        ];

        // Debug mode check.
        $this->debug_mode = (
            defined('WP_DEBUG') && WP_DEBUG ||
            (bool) get_option('meta_capi_enable_logging', false)
        );

        $this->logger->log('Script settings loaded', 'info', [
            'debug_mode' => $this->debug_mode,
            'pixel_configured' => !empty($this->settings['pixel_id']),
        ]);
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    private function init_hooks(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts'], 20);
        add_filter('script_loader_tag', [$this, 'add_async_defer'], 10, 2);
        
        $this->logger->log('Script hooks registered', 'info');
    }

    /**
     * Enqueue scripts intelligently.
     *
     * Only loads scripts that are needed based on:
     * - Plugin configuration
     * - Current page type
     * - Active features
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        // Don't load anything if not configured.
        if (empty($this->settings['pixel_id']) || empty($this->settings['access_token'])) {
            $this->logger->log('Scripts not loaded: Plugin not configured', 'info');
            return;
        }

        // Don't load in admin.
        if (is_admin()) {
            return;
        }

        // Don't load for admin users (optional).
        if ($this->should_skip_for_user()) {
            $this->logger->log('Scripts not loaded: Skipped for admin user', 'info');
            return;
        }

        // Determine what needs to load.
        $load_pixel = $this->should_load_pixel_script();
        $load_wc_events = $this->should_load_wc_events();

        $this->logger->log('Script loading decision', 'info', [
            'load_pixel' => $load_pixel,
            'load_wc_events' => $load_wc_events,
        ]);

        // Load Meta Pixel helper script.
        if ($load_pixel) {
            $this->enqueue_pixel_script();
        }

        // Load WooCommerce events script.
        if ($load_wc_events) {
            $this->enqueue_wc_events_script();
        }

        // Localize scripts.
        if ($load_pixel || $load_wc_events) {
            $this->localize_scripts();
        }
    }

    /**
     * Check if pixel script should load.
     *
     * @return bool True if should load.
     */
    private function should_load_pixel_script(): bool {
        // Pixel injection must be enabled.
        if (!$this->settings['pixel_auto_inject']) {
            return false;
        }

        // Load if ANY tracking is enabled.
        return (
            $this->settings['enable_page_view'] ||
            $this->settings['enable_form_tracking'] ||
            $this->is_woocommerce_tracking_enabled()
        );
    }

    /**
     * Check if WooCommerce events script should load.
     *
     * @return bool True if should load.
     */
    private function should_load_wc_events(): bool {
        // WooCommerce must be active.
        if (!class_exists('WooCommerce')) {
            return false;
        }

        // At least one WC tracking feature must be enabled.
        if (!$this->is_woocommerce_tracking_enabled()) {
            return false;
        }

        // Only load on WooCommerce pages.
        return $this->is_woocommerce_page();
    }

    /**
     * Check if WooCommerce tracking is enabled.
     *
     * @return bool True if any WC tracking enabled.
     */
    private function is_woocommerce_tracking_enabled(): bool {
        return (
            $this->settings['wc_enable_viewcontent'] ||
            $this->settings['wc_enable_addtocart'] ||
            $this->settings['wc_enable_checkout'] ||
            $this->settings['wc_enable_purchase']
        );
    }

    /**
     * Check if current page is a WooCommerce page.
     *
     * @return bool True if WooCommerce page.
     */
    private function is_woocommerce_page(): bool {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        return (
            is_woocommerce() ||
            is_cart() ||
            is_checkout() ||
            is_account_page() ||
            is_product() ||
            is_shop() ||
            is_product_category() ||
            is_product_tag()
        );
    }

    /**
     * Check if should skip loading for current user.
     *
     * @return bool True if should skip.
     */
    private function should_skip_for_user(): bool {
        $skip_admins = (bool) get_option('meta_capi_pixel_skip_admins', false);
        
        return $skip_admins && current_user_can('manage_options');
    }

    /**
     * Enqueue Meta Pixel helper script.
     *
     * @return void
     */
    private function enqueue_pixel_script(): void {
        $suffix = $this->debug_mode ? '' : '.min';
        
        wp_enqueue_script(
            'meta-capi-pixel',
            META_CAPI_PLUGIN_URL . 'assets/js/meta-pixel' . $suffix . '.js',
            [],
            META_CAPI_VERSION,
            true // Load in footer
        );

        $this->logger->log('Meta Pixel script enqueued', 'info', ['suffix' => $suffix]);
    }

    /**
     * Enqueue WooCommerce events script.
     *
     * @return void
     */
    private function enqueue_wc_events_script(): void {
        $suffix = $this->debug_mode ? '' : '.min';
        
        wp_enqueue_script(
            'meta-capi-wc-events',
            META_CAPI_PLUGIN_URL . 'assets/js/woocommerce-events' . $suffix . '.js',
            ['meta-capi-pixel'], // Depends on pixel helper
            META_CAPI_VERSION,
            true // Load in footer
        );

        $this->logger->log('WooCommerce events script enqueued', 'info', ['suffix' => $suffix]);
    }

    /**
     * Localize scripts with configuration data.
     *
     * @return void
     */
    private function localize_scripts(): void {
        // Base configuration for Meta Pixel helper.
        wp_localize_script(
            'meta-capi-pixel',
            'meta_capi_config',
            [
                'debug' => $this->debug_mode,
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('meta_capi_ajax'),
            ]
        );

        // WooCommerce-specific configuration and data.
        if (wp_script_is('meta-capi-wc-events', 'enqueued')) {
            $wc_data = $this->get_woocommerce_page_data();
            
            wp_localize_script(
                'meta-capi-wc-events',
                'metaCAPIWooCommerceData',
                $wc_data
            );
        }

        $this->logger->log('Scripts localized', 'info');
    }

    /**
     * Get WooCommerce page-specific data for JavaScript.
     *
     * @return array<string, mixed> Page data.
     */
    private function get_woocommerce_page_data(): array {
        $data = [
            'currency' => get_woocommerce_currency(),
            'is_product' => is_product(),
            'is_cart' => is_cart(),
            'is_checkout' => is_checkout(),
            'is_order_received' => is_order_received_page(),
        ];

        // Product page data.
        if (is_product()) {
            global $product;
            if ($product && is_a($product, 'WC_Product')) {
                $categories = get_the_terms($product->get_id(), 'product_cat');
                $category_names = [];
                if ($categories && !is_wp_error($categories)) {
                    $category_names = array_map(function($cat) {
                        return $cat->name;
                    }, $categories);
                }

                $data['product'] = [
                    'id' => (string) $product->get_id(),
                    'name' => $product->get_name(),
                    'price' => (float) $product->get_price(),
                    'category' => implode(', ', $category_names),
                    'currency' => get_woocommerce_currency(),
                ];
            }
        }

        // Cart/Checkout page data.
        if ((is_cart() || is_checkout()) && !is_order_received_page()) {
            $cart = WC()->cart;
            if ($cart && !$cart->is_empty()) {
                $content_ids = [];
                $contents = [];

                foreach ($cart->get_cart() as $cart_item) {
                    $product = $cart_item['data'];
                    if ($product) {
                        $product_id = $product->get_id();
                        $content_ids[] = (string) $product_id;
                        $contents[] = [
                            'id' => (string) $product_id,
                            'quantity' => $cart_item['quantity'],
                            'item_price' => (float) $product->get_price(),
                        ];
                    }
                }

                $data['cart'] = [
                    'content_ids' => $content_ids,
                    'contents' => $contents,
                    'value' => (float) $cart->get_total('edit'),
                    'currency' => get_woocommerce_currency(),
                    'num_items' => $cart->get_cart_contents_count(),
                ];
            }
        }

        // Order received (thank you) page data.
        if (is_order_received_page()) {
            $order_id = isset($_GET['order-received']) ? absint($_GET['order-received']) : 0;
            
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $content_ids = [];
                    $contents = [];

                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        if ($product) {
                            $product_id = $product->get_id();
                            $content_ids[] = (string) $product_id;
                            $contents[] = [
                                'id' => (string) $product_id,
                                'quantity' => $item->get_quantity(),
                                'item_price' => (float) $product->get_price(),
                            ];
                        }
                    }

                    $data['order'] = [
                        'id' => (string) $order->get_id(),
                        'content_ids' => $content_ids,
                        'contents' => $contents,
                        'value' => (float) $order->get_total(),
                        'currency' => $order->get_currency(),
                        'num_items' => $order->get_item_count(),
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * Add async/defer attributes to scripts.
     *
     * @param string $tag    Script tag HTML.
     * @param string $handle Script handle.
     * @return string Modified script tag.
     */
    public function add_async_defer(string $tag, string $handle): string {
        // Only add to our scripts.
        if (!in_array($handle, ['meta-capi-pixel', 'meta-capi-wc-events'], true)) {
            return $tag;
        }

        // Add async and defer for non-blocking execution.
        if (strpos($tag, 'async') === false) {
            $tag = str_replace(' src=', ' async defer src=', $tag);
        }

        return $tag;
    }

    /**
     * Get script loading status for debugging.
     *
     * @return array<string, mixed> Status information.
     */
    public function get_status(): array {
        return [
            'debug_mode' => $this->debug_mode,
            'settings' => $this->settings,
            'should_load_pixel' => $this->should_load_pixel_script(),
            'should_load_wc' => $this->should_load_wc_events(),
            'is_wc_page' => $this->is_woocommerce_page(),
            'skip_for_user' => $this->should_skip_for_user(),
        ];
    }
}

