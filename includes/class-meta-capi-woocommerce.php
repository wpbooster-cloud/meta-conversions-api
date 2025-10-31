<?php
/**
 * WooCommerce Integration for Meta Conversions API.
 *
 * Handles all WooCommerce event tracking including:
 * - ViewContent (Product pages)
 * - AddToCart
 * - InitiateCheckout
 * - Purchase
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
 * Meta CAPI WooCommerce Integration Class.
 */
class Meta_CAPI_WooCommerce {

    /**
     * Meta CAPI Client instance.
     *
     * @var Meta_CAPI_Client
     */
    private Meta_CAPI_Client $client;

    /**
     * Meta CAPI Logger instance.
     *
     * @var Meta_CAPI_Logger
     */
    private Meta_CAPI_Logger $logger;

    /**
     * Whether WooCommerce is active.
     *
     * @var bool
     */
    private bool $woocommerce_active = false;

    /**
     * Plugin settings cache.
     *
     * @var array<string, mixed>
     */
    private array $settings = [];

    /**
     * Constructor.
     *
     * @param Meta_CAPI_Client $client CAPI Client instance.
     * @param Meta_CAPI_Logger $logger Logger instance.
     */
    public function __construct(Meta_CAPI_Client $client, Meta_CAPI_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;

        // Check if WooCommerce is active.
        $this->woocommerce_active = $this->is_woocommerce_active();

        if (!$this->woocommerce_active) {
            $this->logger->log('WooCommerce is not active. WooCommerce tracking disabled.');
            return;
        }

        // Load settings.
        $this->load_settings();

        // Initialize hooks if tracking is enabled.
        $this->init_hooks();
    }

    /**
     * Check if WooCommerce is active.
     *
     * @return bool True if WooCommerce is active.
     */
    private function is_woocommerce_active(): bool {
        return class_exists('WooCommerce');
    }

    /**
     * Load plugin settings into cache.
     *
     * @return void
     */
    private function load_settings(): void {
        // Get event settings with proper defaults (true for all except search).
        // If option doesn't exist (false returned), default to true for first-time setup.
        $viewcontent_enabled = get_option('meta_capi_wc_enable_viewcontent');
        $addtocart_enabled = get_option('meta_capi_wc_enable_addtocart');
        $initiatecheckout_enabled = get_option('meta_capi_wc_enable_initiatecheckout');
        $purchase_enabled = get_option('meta_capi_wc_enable_purchase');
        
        // Get purchase timing with fallback to 'placed' if empty.
        $purchase_timing = get_option('meta_capi_wc_purchase_timing', 'placed');
        if (empty($purchase_timing)) {
            $purchase_timing = 'placed';
        }
        
        $this->settings = [
            'enable_viewcontent'      => $viewcontent_enabled === false ? true : (bool) $viewcontent_enabled,
            'enable_addtocart'        => $addtocart_enabled === false ? true : (bool) $addtocart_enabled,
            'enable_initiatecheckout' => $initiatecheckout_enabled === false ? true : (bool) $initiatecheckout_enabled,
            'enable_purchase'         => $purchase_enabled === false ? true : (bool) $purchase_enabled,
            'enable_search'           => (bool) get_option('meta_capi_wc_enable_search', false),
            'purchase_event_timing'   => $purchase_timing, // 'placed' or 'paid'
            'send_customer_email'     => (bool) get_option('meta_capi_wc_send_email', true),
            'send_customer_phone'     => (bool) get_option('meta_capi_wc_send_phone', true),
            'send_customer_name'      => (bool) get_option('meta_capi_wc_send_name', true),
            'send_customer_address'   => (bool) get_option('meta_capi_wc_send_address', true),
        ];

        $this->logger->log('WooCommerce settings loaded', 'info', $this->settings);
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    private function init_hooks(): void {
        // Purchase Event - hook depends on timing setting.
        if ($this->settings['enable_purchase']) {
            if ($this->settings['purchase_event_timing'] === 'paid') {
                // Track when payment is confirmed.
                add_action('woocommerce_payment_complete', [$this, 'track_purchase'], 10, 1);
                add_action('woocommerce_order_status_processing', [$this, 'track_purchase_by_status'], 10, 1);
                add_action('woocommerce_order_status_completed', [$this, 'track_purchase_by_status'], 10, 1);
                $this->logger->log('Purchase tracking hook registered', 'info', ['timing' => 'payment_confirmed']);
            } else {
                // Track when order is placed (default).
                add_action('woocommerce_thankyou', [$this, 'track_purchase'], 10, 1);
                // Fallback: some gateways/themes may not trigger thankyou reliably; hook into order processed too.
                add_action('woocommerce_checkout_order_processed', [$this, 'track_purchase'], 10, 1);
                $this->logger->log('Purchase tracking hook registered', 'info', ['timing' => 'order_placed']);
            }
        }

        // InitiateCheckout Event - fires when checkout page is loaded.
        if ($this->settings['enable_initiatecheckout']) {
            add_action('woocommerce_before_checkout_form', [$this, 'track_initiate_checkout'], 10);
            $this->logger->log('InitiateCheckout tracking hook registered', 'info');
        }

        // AddToCart Event - fires when item is added to cart.
        if ($this->settings['enable_addtocart']) {
            add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);
            $this->logger->log('AddToCart tracking hook registered', 'info');
        }

        // ViewContent Event - fires on single product page.
        if ($this->settings['enable_viewcontent']) {
            add_action('woocommerce_after_single_product', [$this, 'track_view_content'], 10);
            $this->logger->log('ViewContent tracking hook registered', 'info');
        }

        // Search Event - fires on search results page.
        if ($this->settings['enable_search']) {
            add_action('pre_get_posts', [$this, 'track_search'], 10, 1);
            $this->logger->log('Search tracking hook registered', 'info');
        }
    }

    /**
     * Track Purchase event when order is completed.
     *
     * Fires on woocommerce_payment_complete hook.
     * Security: Uses WooCommerce's built-in order validation.
     * Performance: Runs async after order creation.
     *
     * @param int $order_id The order ID.
     * @return void
     */
    public function track_purchase(int $order_id): void {
        try {
            // Validate order ID.
            if ($order_id <= 0) {
                $this->logger->log('Invalid order ID provided to track_purchase', 'error', ['order_id' => $order_id]);
                return;
            }

            // Get order object.
            $order = wc_get_order($order_id);
            
            if (!$order) {
                $this->logger->log('Order not found', 'error', ['order_id' => $order_id]);
                return;
            }

            // Check if we've already tracked this order (prevent duplicates).
            $already_tracked = $order->get_meta('_meta_capi_purchase_tracked', true);
            if ($already_tracked) {
                $this->logger->log('Purchase already tracked for this order', 'info', ['order_id' => $order_id]);
                return;
            }

            $this->logger->log('Tracking Purchase event', 'info', ['order_id' => $order_id]);

            // Build event data.
            $event_data = $this->build_purchase_event_data($order);

            // Send to Facebook.
            $result = $this->client->send_event($event_data);

            if ($result['success']) {
                // Mark order as tracked.
                $order->update_meta_data('_meta_capi_purchase_tracked', true);
                $order->update_meta_data('_meta_capi_purchase_tracked_time', time());
                $order->save_meta_data();

                $this->logger->log('Purchase event sent successfully', 'info', [
                    'order_id'  => $order_id,
                    'event_id'  => $event_data['event_id'] ?? 'unknown',
                    'value'     => $event_data['custom_data']['value'] ?? 0,
                ]);
            } else {
                $this->logger->log('Failed to send Purchase event', 'error', [
                    'order_id' => $order_id,
                    'error'    => $result['message'] ?? 'Unknown error',
                ]);
            }

        } catch (Exception $e) {
            $this->logger->log('Exception in track_purchase', 'error', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Build event data for Purchase event.
     *
     * @param WC_Order $order WooCommerce order object.
     * @return array<string, mixed> Event data array.
     */
    private function build_purchase_event_data(WC_Order $order): array {
        $event_data = [
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'event_id'         => 'purchase_' . $order->get_id(), // Deduplication key.
            'event_source_url' => $order->get_checkout_order_received_url(),
            'action_source'    => 'website',
            'user_data'        => $this->build_user_data($order),
            'custom_data'      => $this->build_custom_data($order),
        ];

        return $event_data;
    }

    /**
     * Build user_data array for event.
     *
     * Security: All PII is hashed using SHA-256.
     * Privacy: Only sends data if settings allow.
     *
     * @param WC_Order $order WooCommerce order object.
     * @return array<string, mixed> User data array.
     */
    private function build_user_data(WC_Order $order): array {
        $user_data = [
            'client_ip_address' => $this->get_client_ip(),
            'client_user_agent' => $this->get_user_agent(),
        ];

        // Add email (hashed).
        if ($this->settings['send_customer_email'] && $order->get_billing_email()) {
            $user_data['em'] = $this->hash_data($order->get_billing_email());
        }

        // Add phone (hashed).
        if ($this->settings['send_customer_phone'] && $order->get_billing_phone()) {
            $user_data['ph'] = $this->hash_data($order->get_billing_phone());
        }

        // Add name (hashed).
        if ($this->settings['send_customer_name']) {
            $first_name = $order->get_billing_first_name();
            $last_name  = $order->get_billing_last_name();
            
            if ($first_name) {
                $user_data['fn'] = $this->hash_data($first_name);
            }
            if ($last_name) {
                $user_data['ln'] = $this->hash_data($last_name);
            }
        }

        // Add address data (hashed).
        if ($this->settings['send_customer_address']) {
            $city     = $order->get_billing_city();
            $state    = $order->get_billing_state();
            $zip      = $order->get_billing_postcode();
            $country  = $order->get_billing_country();

            if ($city) {
                $user_data['ct'] = $this->hash_data($city);
            }
            if ($state) {
                $user_data['st'] = $this->hash_data($state);
            }
            if ($zip) {
                $user_data['zp'] = $this->hash_data($zip);
            }
            if ($country) {
                $user_data['country'] = $this->hash_data($country);
            }
        }

        return $user_data;
    }

    /**
     * Build custom_data array for event.
     *
     * @param WC_Order $order WooCommerce order object.
     * @return array<string, mixed> Custom data array.
     */
    private function build_custom_data(WC_Order $order): array {
        $items        = $order->get_items();
        $content_ids  = [];
        $content_name = [];
        $contents     = [];

        foreach ($items as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $content_ids[]  = (string) $product->get_id();
            $content_name[] = $product->get_name();
            
            $contents[] = [
                'id'         => (string) $product->get_id(),
                'quantity'   => $item->get_quantity(),
                'item_price' => (float) $product->get_price(),
            ];
        }

        return [
            'content_ids'   => $content_ids,
            'content_name'  => implode(', ', $content_name),
            'content_type'  => 'product',
            'contents'      => $contents,
            'currency'      => $order->get_currency(),
            'value'         => (float) $order->get_total(),
            'num_items'     => $order->get_item_count(),
            'order_id'      => (string) $order->get_id(),
        ];
    }

    /**
     * Track Purchase by order status change.
     * Used when purchase_event_timing is 'paid' for offline payment methods.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function track_purchase_by_status(int $order_id): void {
        // Re-use the main track_purchase method.
        $this->track_purchase($order_id);
    }

    /**
     * Track InitiateCheckout event.
     *
     * Fires when checkout page is loaded.
     *
     * @return void
     */
    public function track_initiate_checkout(): void {
        try {
            // Skip if user is admin (prevents polluting data).
            if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
                return;
            }

            // Get cart data.
            $cart = WC()->cart;
            if (!$cart || $cart->is_empty()) {
                $this->logger->log('InitiateCheckout: Cart is empty', 'info');
                return;
            }

            $this->logger->log('Tracking InitiateCheckout event', 'info');

            // Generate event ID for deduplication.
            $event_id = $this->generate_event_id('InitiateCheckout');

            // Build event data.
            $event_data = [
                'event_name'       => 'InitiateCheckout',
                'event_time'       => time(),
                'event_id'         => $event_id,
                'event_source_url' => wc_get_checkout_url(),
                'action_source'    => 'website',
                'user_data'        => $this->build_user_data_from_session(),
                'custom_data'      => $this->build_cart_custom_data($cart),
            ];

            // Send to Facebook.
            $result = $this->client->send_event($event_data);

            if ($result['success']) {
                $this->logger->log('InitiateCheckout event sent successfully', 'info', [
                    'event_id' => $event_id,
                    'value'    => $event_data['custom_data']['value'] ?? 0,
                ]);
            } else {
                $this->logger->log('Failed to send InitiateCheckout event', 'error', [
                    'error' => $result['message'] ?? 'Unknown error',
                ]);
            }

        } catch (Exception $e) {
            $this->logger->log('Exception in track_initiate_checkout', 'error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Track AddToCart event.
     *
     * Fires when item is added to cart.
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $product_id    Product ID.
     * @param int    $quantity      Quantity added.
     * @param int    $variation_id  Variation ID (0 if simple product).
     * @param array  $variation     Variation data.
     * @param array  $cart_item_data Cart item data.
     * @return void
     */
    public function track_add_to_cart(
        string $cart_item_key,
        int $product_id,
        int $quantity,
        int $variation_id,
        array $variation,
        array $cart_item_data
    ): void {
        try {
            // Skip if user is admin (prevents polluting data).
            if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
                return;
            }

            // Use variation ID if available, otherwise use product ID.
            $effective_product_id = $variation_id > 0 ? $variation_id : $product_id;
            
            // Get product object.
            $product = wc_get_product($effective_product_id);
            if (!$product) {
                $this->logger->log('AddToCart: Product not found', 'error', ['product_id' => $effective_product_id]);
                return;
            }

            $this->logger->log('Tracking AddToCart event', 'info', [
                'product_id' => $effective_product_id,
                'quantity'   => $quantity,
            ]);

            // Generate event ID for deduplication.
            $event_id = $this->generate_event_id('AddToCart_' . $effective_product_id);

            // Build event data.
            $event_data = [
                'event_name'       => 'AddToCart',
                'event_time'       => time(),
                'event_id'         => $event_id,
                'event_source_url' => get_permalink($product_id),
                'action_source'    => 'website',
                'user_data'        => $this->build_user_data_from_session(),
                'custom_data'      => [
                    'content_ids'  => [(string) $effective_product_id],
                    'content_name' => $product->get_name(),
                    'content_type' => 'product',
                    'contents'     => [
                        [
                            'id'         => (string) $effective_product_id,
                            'quantity'   => $quantity,
                            'item_price' => (float) $product->get_price(),
                        ],
                    ],
                    'currency'     => get_woocommerce_currency(),
                    'value'        => (float) $product->get_price() * $quantity,
                ],
            ];

            // Send to Facebook.
            $result = $this->client->send_event($event_data);

            if ($result['success']) {
                $this->logger->log('AddToCart event sent successfully', 'info', [
                    'event_id'   => $event_id,
                    'product_id' => $effective_product_id,
                    'value'      => $event_data['custom_data']['value'],
                ]);
            } else {
                $this->logger->log('Failed to send AddToCart event', 'error', [
                    'product_id' => $effective_product_id,
                    'error'      => $result['message'] ?? 'Unknown error',
                ]);
            }

        } catch (Exception $e) {
            $this->logger->log('Exception in track_add_to_cart', 'error', [
                'product_id' => $product_id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Track ViewContent event.
     *
     * Fires on single product page.
     *
     * @return void
     */
    public function track_view_content(): void {
        try {
            // Skip if user is admin (prevents polluting data).
            if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
                return;
            }

            // Get current product.
            global $product;
            if (!$product || !is_a($product, 'WC_Product')) {
                $this->logger->log('ViewContent: No valid product found', 'error');
                return;
            }

            $product_id = $product->get_id();

            $this->logger->log('Tracking ViewContent event', 'info', ['product_id' => $product_id]);

            // Generate event ID for deduplication.
            $event_id = $this->generate_event_id('ViewContent_' . $product_id);

            // Build event data.
            $event_data = [
                'event_name'       => 'ViewContent',
                'event_time'       => time(),
                'event_id'         => $event_id,
                'event_source_url' => get_permalink($product_id),
                'action_source'    => 'website',
                'user_data'        => $this->build_user_data_from_session(),
                'custom_data'      => [
                    'content_ids'   => [(string) $product_id],
                    'content_name'  => $product->get_name(),
                    'content_type'  => 'product',
                    'content_category' => $this->get_product_category($product),
                    'contents'      => [
                        [
                            'id'         => (string) $product_id,
                            'quantity'   => 1,
                            'item_price' => (float) $product->get_price(),
                        ],
                    ],
                    'currency'      => get_woocommerce_currency(),
                    'value'         => (float) $product->get_price(),
                ],
            ];

            // Send to Facebook.
            $result = $this->client->send_event($event_data);

            if ($result['success']) {
                $this->logger->log('ViewContent event sent successfully', 'info', [
                    'event_id'   => $event_id,
                    'product_id' => $product_id,
                ]);
            } else {
                $this->logger->log('Failed to send ViewContent event', 'error', [
                    'product_id' => $product_id,
                    'error'      => $result['message'] ?? 'Unknown error',
                ]);
            }

        } catch (Exception $e) {
            $this->logger->log('Exception in track_view_content', 'error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Track Search event.
     *
     * Fires on product search.
     *
     * @param WP_Query $query WordPress query object.
     * @return void
     */
    public function track_search(WP_Query $query): void {
        // TODO: Implement in Phase 5
        if ($query->is_search() && $query->is_main_query()) {
            $this->logger->log('Search event triggered (not yet implemented)', 'info', [
                'search_query' => $query->get('s'),
            ]);
        }
    }

    /**
     * Hash sensitive data using SHA-256.
     *
     * Security: Implements Facebook's standard hashing.
     * Format: lowercase, trimmed, then hashed.
     *
     * @param string $data Data to hash.
     * @return string Hashed data.
     */
    private function hash_data(string $data): string {
        return hash('sha256', strtolower(trim($data)));
    }

    /**
     * Get client IP address.
     *
     * Security: Validates and sanitizes IP address.
     * Handles proxy headers safely.
     *
     * @return string Client IP address.
     */
    private function get_client_ip(): string {
        $ip = '';

        // Check for proxy headers (in order of trust).
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                
                // If X-Forwarded-For, take the first IP.
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip  = trim($ips[0]);
                }
                
                break;
            }
        }

        // Validate IP address.
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '0.0.0.0';
    }

    /**
     * Get user agent string.
     *
     * Security: Sanitizes user agent.
     *
     * @return string User agent string.
     */
    private function get_user_agent(): string {
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        }

        return '';
    }

    /**
     * Build user_data from current session (for non-order events).
     *
     * @return array<string, mixed> User data array.
     */
    private function build_user_data_from_session(): array {
        $user_data = [
            'client_ip_address' => $this->get_client_ip(),
            'client_user_agent' => $this->get_user_agent(),
        ];

        // Add Facebook browser ID if available.
        if (!empty($_COOKIE['_fbc'])) {
            $user_data['fbc'] = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        }
        if (!empty($_COOKIE['_fbp'])) {
            $user_data['fbp'] = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        }

        // If user is logged in, add email and name.
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            
            if ($this->settings['send_customer_email'] && $current_user->user_email) {
                $user_data['em'] = $this->hash_data($current_user->user_email);
            }
            
            if ($this->settings['send_customer_name']) {
                if ($current_user->first_name) {
                    $user_data['fn'] = $this->hash_data($current_user->first_name);
                }
                if ($current_user->last_name) {
                    $user_data['ln'] = $this->hash_data($current_user->last_name);
                }
            }
        }

        return $user_data;
    }

    /**
     * Build custom_data from cart (for InitiateCheckout events).
     *
     * @param WC_Cart $cart WooCommerce cart object.
     * @return array<string, mixed> Custom data array.
     */
    private function build_cart_custom_data(WC_Cart $cart): array {
        $content_ids  = [];
        $content_name = [];
        $contents     = [];

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();
            $content_ids[]  = (string) $product_id;
            $content_name[] = $product->get_name();
            
            $contents[] = [
                'id'         => (string) $product_id,
                'quantity'   => $cart_item['quantity'],
                'item_price' => (float) $product->get_price(),
            ];
        }

        return [
            'content_ids'   => $content_ids,
            'content_name'  => implode(', ', $content_name),
            'content_type'  => 'product',
            'contents'      => $contents,
            'currency'      => get_woocommerce_currency(),
            'value'         => (float) $cart->get_total('edit'),
            'num_items'     => $cart->get_cart_contents_count(),
        ];
    }

    /**
     * Get product category for event data.
     *
     * @param WC_Product $product Product object.
     * @return string Product category name.
     */
    private function get_product_category(WC_Product $product): string {
        $categories = get_the_terms($product->get_id(), 'product_cat');
        
        if ($categories && !is_wp_error($categories)) {
            $category_names = array_map(function($cat) {
                return $cat->name;
            }, $categories);
            
            return implode(', ', $category_names);
        }
        
        return '';
    }

    /**
     * Check if WooCommerce integration is properly configured.
     *
     * @return bool True if configured properly.
     */
    public function is_configured(): bool {
        return $this->woocommerce_active && (
            $this->settings['enable_purchase'] ||
            $this->settings['enable_addtocart'] ||
            $this->settings['enable_initiatecheckout'] ||
            $this->settings['enable_viewcontent']
        );
    }

    /**
     * Get integration status for debugging.
     *
     * @return array<string, mixed> Status information.
     */
    public function get_status(): array {
        return [
            'woocommerce_active' => $this->woocommerce_active,
            'woocommerce_version' => $this->woocommerce_active ? WC()->version : null,
            'settings'           => $this->settings,
            'configured'         => $this->is_configured(),
        ];
    }
    
    /**
     * Generate unique event ID for deduplication.
     *
     * @param string $prefix Event prefix.
     * @return string Unique event ID.
     */
    private function generate_event_id(string $prefix = ''): string {
        $timestamp = (string) time();
        $random = bin2hex(random_bytes(8));
        return $prefix . '_' . $timestamp . '_' . $random;
    }
}

