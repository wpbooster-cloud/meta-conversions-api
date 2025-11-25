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
     * Event Coordinator instance.
     *
     * @var Meta_CAPI_Coordinator
     */
    private Meta_CAPI_Coordinator $coordinator;

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
     * ViewContent event ID (generated early for browser coordination).
     *
     * @var string
     */
    private static string $viewcontent_event_id = '';
    
    /**
     * Track if ViewContent has been tracked in this request (prevents duplicates).
     *
     * @var bool
     */
    private static bool $viewcontent_tracked_global = false;

    /**
     * InitiateCheckout event ID (generated early for browser coordination).
     *
     * @var string
     */
    private static string $initiatecheckout_event_id = '';

    /**
     * InitiateCheckout duplicate prevention flag (global across all instances).
     *
     * @var bool
     */
    private static bool $initiatecheckout_tracked_global = false;

    /**
     * AddToCart event IDs (keyed by product ID) for browser retrieval via AJAX fragments.
     *
     * @var array<string, string>
     */
    private static array $addtocart_event_ids = [];

    /**
     * Pre-generated AddToCart event IDs for traditional form submissions (keyed by product ID).
     * Generated on product page load, stored for browser to use, and checked by server.
     *
     * @var array<string, string>
     */
    private static array $form_addtocart_event_ids = [];

    /**
     * Constructor.
     *
     * @param Meta_CAPI_Client $client CAPI Client instance.
     * @param Meta_CAPI_Logger $logger Logger instance.
     * @param Meta_CAPI_Coordinator $coordinator Event Coordinator instance.
     */
    public function __construct(Meta_CAPI_Client $client, Meta_CAPI_Logger $logger, Meta_CAPI_Coordinator $coordinator) {
        $this->client = $client;
        $this->logger = $logger;
        $this->coordinator = $coordinator;

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
        // Generate event ID early (template_redirect) so it can be passed to browser via localized script.
        if ($this->settings['enable_initiatecheckout']) {
            // Generate event ID early (before wp_head for script localization).
            add_action('template_redirect', [$this, 'generate_initiatecheckout_event_id'], 5);
            // Track and send to CAPI (uses same event ID).
            // Use 'wp' hook instead of 'woocommerce_before_checkout_form' for reliability.
            // The form hook may not fire if form is conditionally rendered or theme interferes.
            add_action('wp', [$this, 'track_initiate_checkout'], 10);
            $this->logger->log('InitiateCheckout tracking hook registered', 'info');
        }

        // AddToCart Event - fires when item is added to cart.
        if ($this->settings['enable_addtocart']) {
            add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);
            // Pass event ID to browser via AJAX fragments for deduplication.
            add_filter('woocommerce_add_to_cart_fragments', [$this, 'add_event_id_to_fragments'], 10, 1);
            $this->logger->log('AddToCart tracking hook registered', 'info');
        }

        // ViewContent Event - fires on single product page.
        // Generate event ID early (template_redirect) so it can be passed to browser via localized script.
        if ($this->settings['enable_viewcontent']) {
            // Generate event ID early (before wp_head for script localization).
            add_action('template_redirect', [$this, 'generate_viewcontent_event_id'], 5);
            // Track and send to CAPI (uses same event ID).
            add_action('woocommerce_after_single_product', [$this, 'track_view_content'], 10);
            $this->logger->log('ViewContent tracking hook registered', 'info');
        }

        // Generate AddToCart event IDs on product pages for traditional form submissions.
        // This ensures both browser and server can use the same event ID for deduplication.
        if ($this->settings['enable_addtocart']) {
            add_action('template_redirect', [$this, 'generate_addtocart_event_ids_for_product'], 5);
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

            // Skip if order was placed by an admin (prevents polluting data).
            $user_id = $order->get_user_id();
            if ($user_id > 0 && user_can($user_id, 'manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
                $this->logger->log('Purchase skipped - order placed by admin', 'info', ['order_id' => $order_id, 'user_id' => $user_id]);
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
        // CRITICAL: Use order creation timestamp to match browser Pixel timing.
        // Browser receives order_timestamp from scripts.php (line 568) and uses it for eventTime.
        // Server must use the same order creation timestamp for deduplication to work.
        // The browser extracts this from orderData.event_time which comes from order creation time.
        $order_date = $order->get_date_created();
        $event_time = $order_date ? $order_date->getTimestamp() : time();
        
        // Build user data BEFORE creating event data (capture at request time).
        $user_data = $this->build_user_data($order);
        
        // CRITICAL: Log user data for deduplication debugging.
        // Meta uses IP, User Agent, fbp cookie, and country for matching.
        $this->logger->log('Purchase user_data captured (for deduplication)', 'info', [
            'order_id' => $order->get_id(),
            'event_id' => $this->coordinator->generate_event_id('purchase', (string) $order->get_id(), false),
            'user_data_keys' => array_keys($user_data),
            'has_ip' => !empty($user_data['client_ip_address']),
            'ip_value' => !empty($user_data['client_ip_address']) ? $user_data['client_ip_address'] : 'MISSING',
            'has_user_agent' => !empty($user_data['client_user_agent']),
            'user_agent_preview' => !empty($user_data['client_user_agent']) ? substr($user_data['client_user_agent'], 0, 100) . '...' : 'MISSING',
            'has_fbp' => !empty($user_data['fbp']),
            'fbp_preview' => !empty($user_data['fbp']) ? substr($user_data['fbp'], 0, 30) . '...' : 'MISSING',
            'has_fbc' => !empty($user_data['fbc']),
            'has_email' => !empty($user_data['em']),
            'has_phone' => !empty($user_data['ph']),
            'has_name' => !empty($user_data['fn']) || !empty($user_data['ln']),
            'note' => 'Compare these values with browser console logs - they MUST match for deduplication'
        ]);
        
        // CRITICAL: Use actual current URL (not get_checkout_order_received_url()) to match browser's window.location.href.
        // Meta Pixel automatically uses window.location.href as event_source_url, so server must match it exactly.
        // This includes query parameters, trailing slashes, etc. for perfect deduplication.
        $event_source_url = $this->get_current_request_url();
        
        $event_data = [
            'event_name'       => 'Purchase',
            'event_time'       => $event_time,
            // Generate event ID for deduplication using Coordinator (ensures consistency with browser-side).
            // Purchase events use order ID only (no timestamp) for exact matching.
            'event_id'         => $this->coordinator->generate_event_id('purchase', (string) $order->get_id(), false),
            'event_source_url' => $event_source_url, // Use actual current URL to match browser
            'action_source'    => 'website',
            'user_data'        => $user_data, // Use captured user_data
            'custom_data'      => $this->build_custom_data($order),
        ];

        $this->logger->log('Purchase event data prepared', 'debug', [
            'order_id' => $order->get_id(),
            'event_time' => $event_time,
            'event_time_formatted' => date('Y-m-d H:i:s', $event_time),
            'order_date' => $order_date ? $order_date->format('Y-m-d H:i:s') : 'not available',
            'order_date_timestamp' => $order_date ? $order_date->getTimestamp() : 'not available',
            'time_difference_seconds' => $order_date ? ($event_time - $order_date->getTimestamp()) : 'N/A',
            'note' => 'Using order creation timestamp to match browser Pixel timing (browser receives order_timestamp from scripts.php)',
        ]);

        return $event_data;
    }

    /**
     * Build user_data array for event.
     *
     * Security: All PII is hashed using SHA-256.
     * Privacy: Only sends data if settings allow.
     * 
     * CRITICAL: For Purchase events, use current request's IP/user agent to match browser event.
     * Browser Pixel uses the IP/user agent from the thank-you page load, so we must match that.
     *
     * @param WC_Order $order WooCommerce order object.
     * @return array<string, mixed> User data array.
     */
    private function build_user_data(WC_Order $order): array {
        // CRITICAL: Use current request's IP and user agent (from thank-you page) to match browser event.
        // Don't use order meta IP/user agent as it may be from checkout (different request).
        $user_data = [
            'client_ip_address' => $this->get_client_ip(),
            'client_user_agent' => $this->get_user_agent(),
        ];
        
        // Add Facebook browser ID (fbp cookie) - CRITICAL for deduplication.
        if (!empty($_COOKIE['_fbp'])) {
            $user_data['fbp'] = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        }
        
        // Add Facebook click ID (fbc cookie) - helps with attribution.
        if (!empty($_COOKIE['_fbc'])) {
            $user_data['fbc'] = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        }

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
     * Generate InitiateCheckout event ID early (before wp_head for script localization).
     * This ensures both Pixel and CAPI use the same event ID.
     *
     * @return void
     */
    public function generate_initiatecheckout_event_id(): void {
        // Only on checkout pages.
        if (!is_checkout() || is_order_received_page()) {
            return;
        }
        
        // Skip if user is admin.
        if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
            return;
        }
        
        // Get cart data.
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }
        
        // CRITICAL: Only generate if not already set (prevent overwriting fallback from track_initiate_checkout).
        // If track_initiate_checkout() ran first and generated a fallback, use that instead of generating a new one.
        if (!empty(self::$initiatecheckout_event_id)) {
            return; // Event ID already exists, don't regenerate.
        }
        
        // Generate event ID ONCE for both Pixel and CAPI.
        $session_id = $this->get_wc_session_id();
        $event_id = $this->coordinator->generate_event_id('checkout', $session_id, true);
        
        // Store in static property for later use.
        self::$initiatecheckout_event_id = $event_id;
        
        $this->logger->log('InitiateCheckout event ID generated', 'info', [
            'event_id' => $event_id,
            'session_id' => $session_id,
        ]);
    }
    
    /**
     * Get InitiateCheckout event ID for browser coordination.
     *
     * @return string Event ID or empty string.
     */
    public static function get_initiatecheckout_event_id(): string {
        return self::$initiatecheckout_event_id;
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
            // Only on checkout pages (not order received/thank you page).
            if (!is_checkout() || is_order_received_page()) {
                return;
            }

            // Skip if user is admin (prevents polluting data).
            if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
                $this->logger->log('InitiateCheckout: Skipped - user is admin', 'info');
                return;
            }

            // Get cart data.
            $cart = WC()->cart;
            if (!$cart || $cart->is_empty()) {
                $this->logger->log('InitiateCheckout: Cart is empty', 'info');
                return;
            }

            // CRITICAL: Prevent duplicate tracking across all instances (global static flag).
            if (self::$initiatecheckout_tracked_global) {
                $this->logger->log('InitiateCheckout already tracked in this request, skipping duplicate', 'warning', [
                    'event_id' => self::$initiatecheckout_event_id,
                    'hook' => current_filter(),
                ]);
                return;
            }
            
            // Set flag IMMEDIATELY to prevent race conditions.
            self::$initiatecheckout_tracked_global = true;

            $this->logger->log('Tracking InitiateCheckout event', 'info');

            // Get event ID from early generation (should already be set).
            $event_id = self::$initiatecheckout_event_id;
            
            // Fallback: Generate if somehow not set (shouldn't happen).
            if (empty($event_id)) {
                $session_id = $this->get_wc_session_id();
                $event_id = $this->coordinator->generate_event_id('checkout', $session_id, true);
                self::$initiatecheckout_event_id = $event_id;
                $this->logger->log('InitiateCheckout event ID generated in fallback', 'warning', ['event_id' => $event_id]);
            }

            // CRITICAL: Extract timestamp from event_id to match browser Pixel timing exactly.
            // Browser extracts timestamp from event_id (lines 353-364 in woocommerce-events.js) and uses it for eventTime.
            // Server must extract the same timestamp from event_id to ensure exact matching for deduplication.
            // Event ID format: checkout_{session_id}_{timestamp_ms} where timestamp is in milliseconds.
            // We extract this and convert to seconds to match browser's extraction.
            $event_time = $this->extract_timestamp_from_event_id($event_id);
            
            // Fallback to current time if extraction fails (shouldn't happen).
            if ($event_time === 0) {
                $event_time = time();
                $this->logger->log('InitiateCheckout: Failed to extract timestamp from event_id, using current time', 'warning', [
                    'event_id' => $event_id,
                    'event_time' => $event_time,
                ]);
            }

            // Build user data BEFORE creating event data (capture at request time).
            $user_data = $this->build_user_data_from_session();
            
            // CRITICAL: Log user data for deduplication debugging.
            // Meta uses IP, User Agent, fbp cookie, and country for matching.
            $this->logger->log('InitiateCheckout user_data captured (for deduplication)', 'info', [
                'event_id' => $event_id,
                'user_data_keys' => array_keys($user_data),
                'has_ip' => !empty($user_data['client_ip_address']),
                'ip_value' => !empty($user_data['client_ip_address']) ? $user_data['client_ip_address'] : 'MISSING',
                'has_user_agent' => !empty($user_data['client_user_agent']),
                'user_agent_preview' => !empty($user_data['client_user_agent']) ? substr($user_data['client_user_agent'], 0, 100) . '...' : 'MISSING',
                'has_fbp' => !empty($user_data['fbp']),
                'fbp_preview' => !empty($user_data['fbp']) ? substr($user_data['fbp'], 0, 30) . '...' : 'MISSING',
                'has_fbc' => !empty($user_data['fbc']),
                'has_email' => !empty($user_data['em']),
                'has_phone' => !empty($user_data['ph']),
                'note' => 'Compare these values with browser console logs - they MUST match for deduplication'
                ]);

            // CRITICAL: Use actual current URL (not wc_get_checkout_url()) to match browser's window.location.href.
            // Meta Pixel automatically uses window.location.href as event_source_url, so server must match it exactly.
            // This includes query parameters, trailing slashes, etc. for perfect deduplication.
            $event_source_url = $this->get_current_request_url();
            
            // Build event data.
            $event_data = [
                'event_name'       => 'InitiateCheckout',
                'event_time'       => $event_time, // Extracted from event_id to match browser exactly
                'event_id'         => $event_id,
                'event_source_url' => $event_source_url, // Use actual current URL to match browser
                'action_source'    => 'website',
                'user_data'        => $user_data, // Use captured user_data
                'custom_data'      => $this->build_cart_custom_data($cart),
            ];

            // CRITICAL: Send InitiateCheckout event via wp_footer hook for better deduplication timing.
            // Browser Pixel fires immediately, so server should too (within seconds, not minutes).
            // wp_footer hook runs after page content but before shutdown, ensuring fast processing.
            $this->send_event_async($event_data);

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
    /**
     * Static flag to prevent duplicate AddToCart tracking in the same request.
     * Key: cart_item_key (WooCommerce generates unique keys per cart item).
     *
     * @var array<string, bool>
     */
    private static array $addtocart_tracked = [];

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

            // CRITICAL: Prevent duplicate tracking in the same request.
            // The woocommerce_add_to_cart hook can fire multiple times (AJAX + fragments refresh).
            // Use cart_item_key as unique identifier (WooCommerce generates unique keys per cart item).
            if (isset(self::$addtocart_tracked[$cart_item_key])) {
                $this->logger->log('AddToCart already tracked for this cart item in this request, skipping duplicate', 'warning', [
                    'cart_item_key' => $cart_item_key,
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                ]);
                return;
            }
            self::$addtocart_tracked[$cart_item_key] = true;

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
                'cart_item_key' => $cart_item_key,
            ]);

            // Get or generate event ID for deduplication.
            // For traditional form submissions, use pre-generated ID from product page.
            // For AJAX add-to-cart, generate new ID and pass via fragments.
            $event_id = $this->get_or_generate_addtocart_event_id($effective_product_id);
            
            // Store event ID for browser retrieval via AJAX fragments (for AJAX add-to-cart).
            // Traditional form submissions use pre-generated IDs from product page.
            self::$addtocart_event_ids[(string) $effective_product_id] = $event_id;

            // CRITICAL: Extract timestamp from event_id to match browser Pixel timing exactly.
            // Browser extracts timestamp from event_id (lines 208-219 in woocommerce-events.js) and uses it for eventTime.
            // Server must extract the same timestamp from event_id to ensure exact matching for deduplication.
            // Event ID format: addtocart_{product_id}_{timestamp_ms} where timestamp is in milliseconds.
            // We extract this and convert to seconds to match browser's extraction.
            $event_time = $this->extract_timestamp_from_event_id($event_id);
            
            // Fallback to current time if extraction fails (shouldn't happen).
            if ($event_time === 0) {
                $event_time = time();
                $this->logger->log('AddToCart: Failed to extract timestamp from event_id, using current time', 'warning', [
                    'event_id' => $event_id,
                    'event_time' => $event_time,
                ]);
            }
            
            // Log extracted timestamp for comparison (for debugging).
            $this->logger->log('AddToCart: Using extracted timestamp from event_id', 'debug', [
                'event_id' => $event_id,
                'event_time_extracted' => $event_time,
                'note' => 'Browser extracts same timestamp from event_id - they must match for deduplication',
            ]);

            // CRITICAL: Use HTTP_REFERER for AJAX requests to match browser's window.location.href exactly.
            // Browser Pixel uses window.location.href (the page where button was clicked), not the AJAX endpoint.
            // For AJAX add-to-cart, get_current_request_url() returns the AJAX endpoint (?wc-ajax=add_to_cart),
            // but we need the actual page URL where the user clicked "Add to Cart".
            if (wp_doing_ajax() && !empty($_SERVER['HTTP_REFERER'])) {
                $event_source_url = sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER']));
            } else {
                $event_source_url = $this->get_current_request_url();
            }
            
            // Build event data.
            $event_data = [
                'event_name'       => 'AddToCart',
                'event_time'       => $event_time, // Use current time to match browser Pixel timing
                'event_id'         => $event_id,
                'event_source_url' => $event_source_url, // Use referrer or product page URL
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

            // CRITICAL: Send AddToCart event via wp_footer hook (or synchronously for AJAX) for better deduplication timing.
            // Browser Pixel fires immediately, so server should too (within seconds, not minutes).
            // wp_footer hook runs after page content but before shutdown, ensuring fast processing.
            // AJAX requests send synchronously since wp_footer doesn't fire.
            $this->send_event_async($event_data);

        } catch (Exception $e) {
            $this->logger->log('Exception in track_add_to_cart', 'error', [
                'product_id' => $product_id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Add event ID to WooCommerce AJAX fragments for browser retrieval.
     * This allows the browser to use the same event ID as the server for deduplication.
     *
     * @param array<string, string> $fragments Fragments to update.
     * @return array<string, string> Updated fragments.
     */
    public function add_event_id_to_fragments(array $fragments): array {
        // Get the most recently stored event ID (for the last added product).
        // Since WooCommerce processes one add-to-cart at a time, we can use the last entry.
        if (!empty(self::$addtocart_event_ids)) {
            // Get the most recent event ID (last in array).
            $event_ids = array_values(self::$addtocart_event_ids);
            $latest_event_id = end($event_ids);
            $product_id = array_key_last(self::$addtocart_event_ids);
            
            // CRITICAL: Get the page URL where add-to-cart occurred (for deduplication).
            // Meta uses event_source_url as part of deduplication matching.
            // Browser Pixel uses window.location.href, so server must match it.
            // Use referrer if available (the page where button was clicked), otherwise product page.
            $product_url = get_permalink($product_id); // Default to product page
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $referrer = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
                $site_url = home_url();
                // Only use referrer if it's from the same domain (security).
                if (strpos($referrer, $site_url) === 0) {
                    $product_url = $referrer;
                }
            }
            
            // Add event ID, product URL, product price, and product name to fragments for JavaScript to retrieve.
            // Use a hidden div that JavaScript can read from.
            // CRITICAL: Include product price and name so browser can match server's values for deduplication.
            $product = wc_get_product($product_id);
            $product_price = $product ? (float) $product->get_price() : 0;
            $product_name = $product ? $product->get_name() : '';
            
            $fragments['meta_capi_addtocart_event_id'] = sprintf(
                '<div id="meta-capi-addtocart-event-id" data-event-id="%s" data-product-id="%s" data-product-url="%s" data-product-price="%s" data-product-name="%s" style="display:none;"></div>',
                esc_attr($latest_event_id),
                esc_attr($product_id),
                esc_attr($product_url),
                esc_attr($product_price),
                esc_attr($product_name)
            );
            
            // Clear the stored event ID after use (prevent reuse).
            unset(self::$addtocart_event_ids[$product_id]);
        }
        
        return $fragments;
    }

    /**
     * Generate ViewContent event ID early (before wp_head for script localization).
     * This ensures both Pixel and CAPI use the same event ID.
     *
     * @return void
     */
    public function generate_viewcontent_event_id(): void {
        // Only on product pages.
        if (!is_product()) {
            return;
        }

        // Skip if user is admin.
        if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
            return;
        }

        // Get current product.
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }

        $product_id = $product->get_id();

        // Generate event ID ONCE for both Pixel and CAPI.
        $event_id = $this->coordinator->generate_event_id('viewcontent', (string) $product_id, true);
        
        // Store in static property for later use.
        self::$viewcontent_event_id = $event_id;

        $this->logger->log('ViewContent event ID generated', 'info', [
            'event_id' => $event_id,
                'product_id' => $product_id,
        ]);
    }

    /**
     * Get ViewContent event ID for browser coordination.
     *
     * @return string Event ID or empty string.
     */
    public static function get_viewcontent_event_id(): string {
        return self::$viewcontent_event_id;
    }

    /**
     * Generate AddToCart event IDs on product pages for traditional form submissions.
     * This allows the browser to use a pre-generated event ID that matches what the server will use.
     *
     * @return void
     */
    public function generate_addtocart_event_ids_for_product(): void {
        // Only on product pages.
        if (!is_product()) {
            return;
        }
        
        // Skip if user is admin.
        if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
            return;
        }

        // Get current product.
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        $product_id = $product->get_id();

        // Generate event ID for the main product.
        $event_id = $this->coordinator->generate_event_id('addtocart', (string) $product_id, true);
        self::$form_addtocart_event_ids[(string) $product_id] = $event_id;

        // If product has variations, generate event IDs for each variation.
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();
            foreach ($variations as $variation) {
                if (isset($variation['variation_id'])) {
                    $variation_id = (string) $variation['variation_id'];
                    $variation_event_id = $this->coordinator->generate_event_id('addtocart', $variation_id, true);
                    self::$form_addtocart_event_ids[$variation_id] = $variation_event_id;
                }
            }
        }
        
        $this->logger->log('AddToCart event IDs generated for product page', 'info', [
            'product_id' => $product_id,
            'event_ids_generated' => count(self::$form_addtocart_event_ids),
        ]);
    }

    /**
     * Get pre-generated AddToCart event ID for a product (for traditional form submissions).
     *
     * @param int $product_id Product ID or variation ID.
     * @return string Event ID or empty string if not found.
     */
    public static function get_form_addtocart_event_id(int $product_id): string {
        return self::$form_addtocart_event_ids[(string) $product_id] ?? '';
    }

    /**
     * Check and use pre-generated event ID for AddToCart (from form submission) or generate new one.
     *
     * @param int $product_id Product ID or variation ID.
     * @return string Event ID.
     */
    private function get_or_generate_addtocart_event_id(int $product_id): string {
        // Check if we have a pre-generated event ID from product page (for traditional form submissions).
        $pre_generated_id = self::$form_addtocart_event_ids[(string) $product_id] ?? '';
        
        if (!empty($pre_generated_id)) {
            // Use pre-generated ID and remove it (one-time use).
            unset(self::$form_addtocart_event_ids[(string) $product_id]);
            $this->logger->log('Using pre-generated AddToCart event ID', 'info', [
                'product_id' => $product_id,
                'event_id' => $pre_generated_id,
            ]);
            return $pre_generated_id;
        }

        // Generate new event ID (for AJAX add-to-cart or if pre-generated not available).
        $event_id = $this->coordinator->generate_event_id('addtocart', (string) $product_id, true);
        return $event_id;
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
            // CRITICAL: Only track on actual product page views, not AJAX requests or cached pages.
            // Skip AJAX, cron, and admin requests.
            // Also check REQUEST_URI for AJAX endpoints (wp_doing_ajax() may not catch all cases).
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $is_ajax_endpoint = wp_doing_ajax() || 
                               (strpos($request_uri, 'wc-ajax=') !== false) ||
                               (strpos($request_uri, 'admin-ajax.php') !== false);
            
            if ($is_ajax_endpoint || wp_doing_cron() || is_admin()) {
                if ($is_ajax_endpoint) {
                    $this->logger->log('ViewContent skipped - AJAX request detected', 'info', [
                        'request_uri' => $request_uri,
                        'wp_doing_ajax' => wp_doing_ajax(),
                    ]);
                }
                return;
            }
            
            // CRITICAL: Only track on actual single product pages.
            // The woocommerce_after_single_product hook can fire in other contexts (AJAX, widgets, etc.).
            if (!is_product() || is_order_received_page()) {
                return;
            }
            
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
            
            // CRITICAL: Prevent duplicate tracking in the same request.
            // The woocommerce_after_single_product hook can fire multiple times or on AJAX requests.
            if (self::$viewcontent_tracked_global) {
                $this->logger->log('ViewContent already tracked in this request, skipping duplicate', 'warning', [
                    'product_id' => $product_id,
                    'event_id' => self::$viewcontent_event_id,
                ]);
                return;
            }
            
            // Set flag IMMEDIATELY to prevent race conditions.
            self::$viewcontent_tracked_global = true;

            $this->logger->log('Tracking ViewContent event', 'info', ['product_id' => $product_id]);

            // Get event ID from early generation (should already be set).
            $event_id = self::$viewcontent_event_id;
            
            // Fallback: Generate if somehow not set (shouldn't happen).
            if (empty($event_id)) {
                $event_id = $this->coordinator->generate_event_id('viewcontent', (string) $product_id, true);
                self::$viewcontent_event_id = $event_id;
                $this->logger->log('ViewContent event ID generated in fallback', 'warning', ['event_id' => $event_id]);
            }

            // CRITICAL: Extract timestamp from event_id to ensure exact match with browser Pixel timing.
            // Event ID format: viewcontent_{product_id}_{timestamp_ms} where timestamp is in milliseconds.
            // We extract this timestamp and convert to seconds for event_time to ensure perfect alignment.
            // Meta deduplicates based on matching event_id AND event_time, so they must match exactly.
            $event_time = $this->extract_timestamp_from_event_id($event_id);
            
            // Fallback to current time if extraction fails (shouldn't happen).
            if ($event_time === 0) {
                $event_time = time();
                $this->logger->log('Failed to extract timestamp from event_id, using current time', 'warning', [
                    'event_id' => $event_id,
                    'event_time' => $event_time,
                ]);
            }

            // Build event data.
            $event_data = [
                'event_name'       => 'ViewContent',
                'event_time'       => $event_time, // Use current time to match browser Pixel timing
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

            // CRITICAL: Send ViewContent event via wp_footer hook for better deduplication timing.
            // Browser Pixel fires immediately, so server should too (within seconds, not minutes).
            // wp_footer hook runs after page content but before shutdown, ensuring fast processing.
            $this->send_event_async($event_data);

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
        // CRITICAL: Priority order must match browser-side detection for deduplication.
        // For Cloudflare sites, browser events use CF-Connecting-IP, so server must check it first.
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare (MUST be first for CF sites - browser uses this).
            'HTTP_X_REAL_IP',        // Nginx/proxy real IP (check second).
            'HTTP_X_FORWARDED_FOR',  // Can be spoofed, check third.
            'HTTP_CLIENT_IP',        // Some proxies set this (check after X-Forwarded-For).
            'REMOTE_ADDR',           // Fallback (proxy IP, not client IP).
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
     * Get WooCommerce session ID for event deduplication.
     *
     * @return string Session ID.
     */
    private function get_wc_session_id(): string {
        // Try WooCommerce session first.
        if (function_exists('WC') && WC()->session) {
            $session_id = WC()->session->get_customer_id();
            if (!empty($session_id)) {
                return sanitize_key($session_id);
            }
        }

        // Fallback: Generate from user ID or IP.
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }

        // Generate from IP and salt for guest users.
        $ip = $this->get_client_ip();
        return 'guest_' . md5($ip . wp_salt());
    }

    /**
     * Extract timestamp from event ID and convert to seconds.
     * Event ID format: {eventtype}_{identifier}_{timestamp_ms}
     *
     * @param string $event_id Event ID with embedded timestamp.
     * @return int Timestamp in seconds, or 0 if extraction fails.
     */
    private function extract_timestamp_from_event_id(string $event_id): int {
        // Event ID format: addtocart_123_1763148995777
        // Extract the last segment (timestamp in milliseconds).
        $parts = explode('_', $event_id);
        if (count($parts) < 3) {
            return 0; // Invalid format (Purchase events use format without timestamp).
        }
        
        // Last part should be the timestamp in milliseconds.
        $timestamp_ms = end($parts);
        
        // Validate it's numeric.
        if (!is_numeric($timestamp_ms)) {
            return 0; // Not a valid timestamp.
        }
        
        // Convert milliseconds to seconds.
        $timestamp_seconds = (int) floor((float) $timestamp_ms / 1000);
        
        return $timestamp_seconds;
    }

    /**
     * Send event asynchronously using WordPress wp_footer hook (for page loads) or synchronously (for AJAX).
     * 
     * CRITICAL: User data (IP, user agent, cookies) must be captured BEFORE scheduling,
     * as wp_footer hook runs after page content but still has access to original request data.
     *
     * @param array $event_data Event data (must include complete user_data).
     */
    private function send_event_async(array $event_data): void {
        if (!empty($event_data) && is_array($event_data)) {
            // Ensure user_data is complete before scheduling (captured from original request).
            if (empty($event_data['user_data']) || !isset($event_data['user_data']['client_ip_address'])) {
                $this->logger->warning('Event scheduled without complete user_data - deduplication may fail', [
                    'event_name' => $event_data['event_name'] ?? 'unknown',
                ]);
            }
            
            // Prevent duplicate sends using event_id + event_time.
            $event_id = $event_data['event_id'] ?? '';
            $event_time = $event_data['event_time'] ?? 0;
            $dedupe_key = $event_id . '_' . $event_time;
            
            // Check if we've already sent this exact event.
            if (isset($GLOBALS['meta_capi_sent_events'][$dedupe_key])) {
                $this->logger->log('Event already sent, skipping duplicate', 'warning', [
                    'event_id' => $event_id,
                    'event_time' => $event_time,
                    'dedupe_key' => $dedupe_key,
                ]);
                return;
            }
            
            // CRITICAL: For AJAX requests, send synchronously (wp_footer doesn't fire on AJAX).
            // For regular page loads, use wp_footer hook (non-blocking).
            if (wp_doing_ajax()) {
                // AJAX request - send immediately (synchronously but non-blocking for user).
                $this->logger->log('Sending event synchronously (AJAX request)', 'info', [
                    'event_name' => $event_data['event_name'] ?? 'unknown',
                    'event_id' => $event_id,
                    'note' => 'AJAX requests don\'t have wp_footer hook, sending immediately',
                ]);
                
                // Mark as sent to prevent duplicates.
                $GLOBALS['meta_capi_sent_events'][$dedupe_key] = true;
                
                // Send immediately.
                $result = $this->client->send_event($event_data);
                
                $this->logger->log('Event sent synchronously (AJAX)', 'info', [
                    'event_id' => $event_id,
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? 'unknown',
                ]);
            } else {
                // Regular page load - use wp_footer hook (non-blocking).
                // Check if we've already queued this exact event for sending.
                if (isset($GLOBALS['meta_capi_queued_events'][$dedupe_key])) {
                    $this->logger->log('Event already queued for wp_footer, skipping duplicate registration', 'warning', [
                        'event_id' => $event_id,
                        'event_time' => $event_time,
                        'dedupe_key' => $dedupe_key,
                    ]);
                    return;
                }
                
                // Mark this event as queued to prevent duplicate hook registrations.
                $GLOBALS['meta_capi_queued_events'] = $GLOBALS['meta_capi_queued_events'] ?? [];
                $GLOBALS['meta_capi_queued_events'][$dedupe_key] = true;
                
                // Use wp_footer hook to send event after page content but before shutdown (faster than shutdown).
                // This is more reliable than wp_schedule_single_event() which depends on cron.
                add_action('wp_footer', function() use ($event_data) {
                    // Create fresh instances to avoid any state issues.
                    $logger = new Meta_CAPI_Logger();
                    $client = new Meta_CAPI_Client($logger);
                    
                    // Prevent duplicate sends using event_id + event_time.
                    $event_id = $event_data['event_id'] ?? '';
                    $event_time = $event_data['event_time'] ?? 0;
                    $dedupe_key = $event_id . '_' . $event_time;
                    
                    if (isset($GLOBALS['meta_capi_sent_events'][$dedupe_key])) {
                        $logger->log('Duplicate event prevented in wp_footer handler', 'warning', [
                            'dedupe_key' => $dedupe_key,
                            'event_id' => $event_id,
                        ]);
                        return;
                    }
                    $GLOBALS['meta_capi_sent_events'][$dedupe_key] = true;
                    
                    $logger->log('Processing event via wp_footer hook - sending to CAPI', 'info', [
                        'event_name' => $event_data['event_name'] ?? 'unknown',
                        'event_id' => $event_id,
                        'event_time' => $event_time,
                    ]);
                    
                    $result = $client->send_event($event_data);
                    
                    $logger->log('Event processing completed via wp_footer hook', 'info', [
                        'event_id' => $event_id,
                        'success' => $result['success'] ?? false,
                        'message' => $result['message'] ?? 'unknown',
                    ]);
                }, 999); // High priority to run late in footer but before shutdown.
                
                $this->logger->log('Event queued for wp_footer hook processing', 'info', [
                    'event_name' => $event_data['event_name'] ?? 'unknown',
                    'event_id' => $event_data['event_id'] ?? 'missing',
                    'note' => 'Event will be sent in footer (faster than shutdown)',
                ]);
            }
        }
    }
    
    /**
     * Get current request URL to match browser's window.location.href.
     * 
     * CRITICAL: This must match exactly what the browser sends (including query params, trailing slashes).
     * Meta Pixel automatically uses window.location.href as event_source_url for deduplication.
     * 
     * @return string Current request URL.
     */
    private function get_current_request_url(): string {
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            return $protocol . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        }
        // Fallback to WooCommerce checkout URL if server vars not available.
        return wc_get_checkout_url();
    }
}

