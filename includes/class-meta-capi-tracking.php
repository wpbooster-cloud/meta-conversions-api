<?php
/**
 * Page view tracking for Meta Conversions API.
 *
 * @package Meta_Conversions_API
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta_CAPI_Tracking class.
 */
class Meta_CAPI_Tracking {
    /**
     * Client instance.
     *
     * @var Meta_CAPI_Client
     */
    private Meta_CAPI_Client $client;

    /**
     * Logger instance.
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
     * Current page view event ID (for Pixel coordination).
     *
     * @var string
     */
    private static string $current_pageview_event_id = '';

    /**
     * Global flag to prevent duplicate PageView tracking across all instances.
     *
     * @var bool
     */
    private static bool $pageview_tracked_global = false;

    /**
     * Global flag to prevent duplicate hook registrations across all instances.
     *
     * @var bool
     */
    private static bool $hooks_registered_global = false;

    /**
     * Constructor.
     *
     * @param Meta_CAPI_Client $client Client instance.
     * @param Meta_CAPI_Logger $logger Logger instance.
     * @param Meta_CAPI_Coordinator $coordinator Event Coordinator instance.
     */
    public function __construct(Meta_CAPI_Client $client, Meta_CAPI_Logger $logger, Meta_CAPI_Coordinator $coordinator) {
        $this->client = $client;
        $this->logger = $logger;
        $this->coordinator = $coordinator;

        // Hook into WordPress to track page views.
        // Generate event ID early (before wp_head) so it can be passed to Pixel.
        // Following Meta's recommended approach: Single event ID generation, consistent timing.
        if (get_option('meta_capi_enable_page_view', true)) {
            // Prevent duplicate hook registrations across ALL instances (global static flag).
            if (!self::$hooks_registered_global) {
                // CRITICAL: Generate event ID on 'template_redirect' hook ONLY (fires BEFORE wp_head).
                // This is Meta's recommended approach - single point of generation.
                // Priority 5 ensures it runs early but after WordPress has determined the page type.
                // The static property check inside generate_pageview_event_id() prevents duplicate generation.
                add_action('template_redirect', [$this, 'generate_pageview_event_id'], 5);
                
                // Track page view (sends to CAPI) - runs on 'wp' hook AFTER event ID is generated.
                // 'wp' hook fires after 'template_redirect', so event ID is guaranteed to exist.
                add_action('wp', [$this, 'track_page_view'], 10);
                
                self::$hooks_registered_global = true;
                $this->logger->log('PageView tracking hooks registered (global, simplified)', 'debug', [
                    'template_redirect_priority' => 5,
                    'wp_track_priority' => 10,
                    'approach' => 'Single event ID generation on template_redirect (Meta recommended)',
                ]);
            }
        }
    }
    
    /**
     * Generate PageView event ID early (before wp_head for Pixel injection).
     * 
     * ✅ WORKING IMPLEMENTATION - DO NOT BREAK
     * This is the reference implementation for event ID coordination.
     * Both browser and server events are sending and deduplicating successfully.
     * See: PAGEVIEW-WORKING-SETUP.md for full documentation.
     * 
     * This follows Meta's recommended approach for deduplication:
     * 1. Generate event ID ONCE early (template_redirect hook)
     * 2. Store in static property (single source of truth)
     * 3. Pass same event ID to both Pixel (browser) and CAPI (server)
     * 4. Extract event_time from event_id to ensure exact match
     * 
     * Meta's deduplication requires matching:
     * - event_id (must be identical)
     * - event_time (must match exactly, extracted from event_id)
     * - user_data (fbp cookie, IP address, user agent)
     * 
     * This ensures both Pixel and CAPI use the same event ID.
     */
    public function generate_pageview_event_id(): void {
        // Don't track admin pages or AJAX requests.
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        
        // CRITICAL: Also check if we're on the plugin settings page (is_admin() may not catch this on 'template_redirect' hook).
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (!empty($request_uri) && (
            strpos($request_uri, 'options-general.php?page=meta-conversions-api') !== false ||
            strpos($request_uri, 'admin.php?page=meta-conversions-api') !== false
        )) {
            return;
        }

        // CRITICAL: Check admin user here too, but we still generate event ID for Pixel coordination.
        // Pixel needs the event ID even if CAPI will skip sending (for browser-side tracking).
        // However, if admin tracking is disabled, we can skip generating the ID entirely.
        // Note: The actual skip for CAPI sending happens in track_page_view().
        // For now, we generate the ID anyway to ensure Pixel has it (Pixel's own admin check will prevent injection).

        // Skip tracking favicon, robots.txt, and other non-page requests.
        if (preg_match('/\/(favicon\.ico|robots\.txt|sitemap.*\.xml|wp-admin|wp-includes|wp-json|crossdomain\.xml|apple-touch-icon.*\.png)/i', $request_uri)) {
            return;
        }
        
        // CRITICAL: Generate event ID even if we might skip tracking later.
        // Pixel needs the event ID even if CAPI will skip sending (for deduplication).
        // The actual sending decision happens in track_page_view().
        
        // Generate event ID ONCE for both Pixel and CAPI.
        // Only generate if not already set (prevent regeneration on subsequent calls).
        if (empty(self::$current_pageview_event_id)) {
            $page_id = get_queried_object_id();
            $identifier = $page_id > 0 ? (string) $page_id : md5($this->get_current_url());
            $event_id = $this->coordinator->generate_event_id('pageview', $identifier, true);
        
            // Store event ID as static property for Pixel to use (single source of truth).
            self::$current_pageview_event_id = $event_id;

            // Determine which hook generated this event ID (for debugging).
            $current_hook = current_filter();
            $hook_name = $current_hook ? $current_hook : 'unknown';
            
            $this->logger->log('PageView event ID generated', 'info', [
                'event_id' => $event_id,
                'page_id' => $page_id,
                'identifier' => $identifier,
                'hook' => $hook_name,
                'note' => 'Event ID generated for both Pixel and CAPI deduplication',
            ]);
        }
        // Note: If event ID already exists, we silently skip regeneration (expected behavior).
        
        // Check exclusion and skip flags AFTER generating ID (for Pixel coordination).
        // These checks prevent CAPI from sending, but Pixel still needs the event ID.
        $excluded_pages_str = get_option('meta_capi_exclude_pages', '');
        if (!empty($excluded_pages_str)) {
            $excluded_pages = array_filter(array_map('absint', explode(',', $excluded_pages_str)));
            $current_page_id = get_queried_object_id();
            if (in_array($current_page_id, $excluded_pages, true)) {
                return;
            }
        }
        
        // Allow filtering to skip tracking on specific pages.
        if (apply_filters('meta_capi_skip_page_view', false)) {
            return;
        }
        
        // Note: Admin check happens in track_page_view(), not here.
        // This ensures event ID is generated for Pixel even if CAPI will skip sending.
    }

    /**
     * Track page view event.
     */
    public function track_page_view(): void {
        // CRITICAL: Skip tracking during plugin activation and for a cooldown period after.
        // This must be checked VERY EARLY to prevent any events during activation.
        // Check this BEFORE any other operations, including logger calls.
        $skip_tracking_transient = get_transient('meta_capi_skip_tracking_after_activation');
        if ($skip_tracking_transient) {
            if (isset($this->logger)) {
                $this->logger->log('PageView skipped - plugin activation cooldown period', 'info', [
                    'note' => 'Skipping tracking during activation/redirect process',
                    'transient_active' => true,
                ]);
            }
            return;
        }
        
        // CRITICAL: Skip tracking if plugin is not configured (no credentials set).
        // This prevents events from being sent during activation or before setup.
        // Check if client is available before calling is_configured().
        if (!isset($this->client) || !$this->client->is_configured()) {
            if (isset($this->logger)) {
                $this->logger->log('PageView skipped - plugin not configured (missing Pixel ID or Access Token)', 'info', [
                    'note' => 'Tracking disabled until credentials are set in settings',
                ]);
            }
            return;
        }
        
        // CRITICAL: Check admin user FIRST, before setting static flag or any other checks.
        // This prevents admin users from being tracked when viewing the frontend.
        // Note: is_admin() only returns true for admin dashboard pages, not when admin views frontend.
        // So we must check current_user_can('manage_options') separately for frontend pages.
        // Also check if user is logged in first to avoid false positives.
        $user_is_logged_in = is_user_logged_in();
        $is_admin_user = $user_is_logged_in && current_user_can('manage_options');
        $skip_admin_tracking = apply_filters('meta_capi_skip_admin_tracking', true);
        
        if ($is_admin_user && $skip_admin_tracking) {
            return;
        }

        // Log if admin user but skip is disabled (for debugging).
        if ($is_admin_user && !$skip_admin_tracking) {
            $this->logger->log('Admin user detected but skip_admin_tracking filter returned false - tracking will proceed', 'warning', [
                'user_id' => get_current_user_id(),
                'skip_admin_tracking_filter' => $skip_admin_tracking,
                'note' => 'Admin tracking is explicitly enabled via filter',
            ]);
        }

        // CRITICAL: Prevent duplicate tracking across all instances (global static flag).
        // This must be checked BEFORE any other processing to prevent duplicate sends.
        if (self::$pageview_tracked_global) {
            $this->logger->log('PageView already tracked in this request, skipping duplicate (server-side check)', 'warning', [
                'event_id' => self::$current_pageview_event_id,
                'hook' => current_filter(),
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3), // Show call stack
            ]);
            return;
        }
        
        // Set flag IMMEDIATELY to prevent race conditions if this method is called multiple times.
        self::$pageview_tracked_global = true;

        // Don't track admin pages or AJAX requests.
        // CRITICAL: Also check REQUEST_URI for AJAX endpoints (wp_doing_ajax() may not catch all cases).
        $request_uri_check = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $is_ajax_endpoint = wp_doing_ajax() || 
                           (strpos($request_uri_check, 'wc-ajax=') !== false) ||
                           (strpos($request_uri_check, 'admin-ajax.php') !== false);
        
        if (is_admin() || $is_ajax_endpoint || wp_doing_cron()) {
            if ($is_ajax_endpoint) {
                $this->logger->log('PageView skipped - AJAX request detected', 'info', [
                    'request_uri' => $request_uri_check,
                    'wp_doing_ajax' => wp_doing_ajax(),
                ]);
            } elseif (is_admin()) {
                $this->logger->log('PageView skipped - admin page detected', 'info', [
                    'request_uri' => $request_uri_check,
                    'is_admin' => true,
                ]);
            }
            return;
        }
        
        // CRITICAL: Also check for plugin activation redirects (WordPress redirects after plugin activation).
        // These might trigger a frontend pageview before the admin redirect completes.
        if (isset($_GET['activate']) || isset($_GET['activate-multi']) || 
            (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'plugins.php') !== false && 
             isset($_GET['plugin']) && strpos($_GET['plugin'], 'meta-conversions-api') !== false)) {
            $this->logger->log('PageView skipped - plugin activation detected', 'info', [
                'request_uri' => $request_uri_check,
                'activate_param' => isset($_GET['activate']) ? 'yes' : 'no',
                'referer' => isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : 'none',
            ]);
            return;
        }
        
        // CRITICAL: Also check if we're on the plugin settings page (is_admin() may not catch this on 'wp' hook).
        // Check both REQUEST_URI and current screen to be thorough.
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $is_plugin_settings_page = false;
        
        // Check if we're on the plugin settings page via REQUEST_URI.
        if (!empty($request_uri) && (
            strpos($request_uri, 'options-general.php?page=meta-conversions-api') !== false ||
            strpos($request_uri, 'admin.php?page=meta-conversions-api') !== false
        )) {
            $is_plugin_settings_page = true;
        }
        
        // Also check current screen if available (more reliable).
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && (
                $screen->id === 'settings_page_meta-conversions-api' ||
                (isset($_GET['page']) && $_GET['page'] === 'meta-conversions-api')
            )) {
                $is_plugin_settings_page = true;
            }
        }
        
        if ($is_plugin_settings_page) {
            $this->logger->log('PageView skipped - plugin settings page', 'info', [
                'request_uri' => $request_uri,
                'note' => 'Plugin settings pages should not trigger tracking',
            ]);
            return;
        }
        
        // Skip tracking favicon, robots.txt, and other non-page requests.
        if (preg_match('/\/(favicon\.ico|robots\.txt|sitemap.*\.xml|wp-admin|wp-includes|wp-json|crossdomain\.xml|apple-touch-icon.*\.png)/i', $request_uri)) {
            return;
        }
        
        // CRITICAL: Filter out bots, crawlers, and automated requests.
        // These can trigger multiple PageView events without actual user interaction.
        // Common culprits: monitoring services, cache warming, health checks, crawlers.
        $user_agent = $this->get_user_agent();
        if ($this->is_bot_request($user_agent, $request_uri_check)) {
            $this->logger->log('PageView skipped - bot/crawler detected', 'info', [
                'user_agent' => $user_agent,
                'request_uri' => $request_uri_check,
            ]);
            return;
        }
        
        // Check if this page is excluded.
        $excluded_pages_str = get_option('meta_capi_exclude_pages', '');
        if (!empty($excluded_pages_str)) {
            $excluded_pages = array_filter(array_map('absint', explode(',', $excluded_pages_str)));
            $current_page_id = get_queried_object_id();
            if (in_array($current_page_id, $excluded_pages, true)) {
                $this->logger->log('PageView skipped - page is in exclusion list', 'info', ['page_id' => $current_page_id]);
                return;
            }
        }

        // Allow filtering to skip tracking on specific pages.
        if (apply_filters('meta_capi_skip_page_view', false)) {
            return;
        }

        // Get event ID from early generation (should already be set by template_redirect).
        // This is Meta's recommended approach: Single event ID generated early, used by both Pixel and CAPI.
        $event_id = self::$current_pageview_event_id;
        
        // Fallback: Generate if somehow not set (shouldn't happen but handle gracefully).
        // This can happen if template_redirect hook didn't fire (e.g., cached pages, redirects, early exits).
        if (empty($event_id)) {
            $page_id = get_queried_object_id();
            $current_url = $this->get_current_url();
            $identifier = $page_id > 0 ? (string) $page_id : md5($current_url);
            $event_id = $this->coordinator->generate_event_id('pageview', $identifier, true);
            self::$current_pageview_event_id = $event_id;
            
            // Track fallback frequency for cache analysis.
            $fallback_count = get_transient('meta_capi_pageview_fallback_count');
            $fallback_count = $fallback_count ? (int) $fallback_count + 1 : 1;
            set_transient('meta_capi_pageview_fallback_count', $fallback_count, DAY_IN_SECONDS);
            
            // Detect likely cache-related issues.
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $is_likely_cached = (
                !did_action('template_redirect') ||
                (defined('WP_CACHE') && WP_CACHE) ||
                (function_exists('wp_cache_get') && wp_cache_get('pageview_fallback_' . md5($request_uri)))
            );
            
            $this->logger->log('PageView event ID generated in fallback (generate_pageview_event_id did not run)', 'warning', [
                'event_id' => $event_id,
                'page_id' => $page_id,
                'identifier' => $identifier,
                'url' => $current_url,
                'request_uri' => $request_uri,
                'request_method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'unknown',
                'template_redirect_fired' => did_action('template_redirect'),
                'wp_cache_defined' => defined('WP_CACHE') ? WP_CACHE : false,
                'is_likely_cached' => $is_likely_cached,
                'fallback_count_today' => $fallback_count,
                'note' => 'template_redirect hook may not have fired (cached page, redirect, or early exit)',
                'recommendation' => $fallback_count > 10 ? 'Consider excluding this page from cache (Breeze/Varnish)' : 'Monitor frequency - if high, consider cache exclusions',
                'hook' => 'fallback_in_track_page_view',
            ]);
        }

        // CRITICAL: Extract timestamp from event_id to ensure exact match between browser and server.
        // Event ID format: pageview_{identifier}_{timestamp_ms} where timestamp is in milliseconds.
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

        // CRITICAL: Get current URL and normalize to match browser's window.location.href exactly.
        // Browser Pixel uses window.location.href which includes trailing slashes for homepage.
        // We need to match this exactly for deduplication.
        $event_source_url = $this->get_current_url_normalized();
        
        // Prepare event data.
        // CRITICAL: PageView events should have minimal or no custom_data to match browser Pixel.
        // Browser Pixel sends PageView with empty custom_data {}, so server should match this.
        // This ensures better deduplication consistency.
        $event_data = [
            'event_name' => 'PageView',
            'event_time' => $event_time, // Use current time to match browser Pixel timing
            'event_id' => $event_id, // Add event ID for deduplication
            'action_source' => 'website',
            'event_source_url' => $event_source_url, // Normalized URL to match browser
            'user_data' => $this->get_user_data(),
            // Note: Removed custom_data to match browser Pixel (which sends empty {})
            // PageView is a simple event and doesn't need custom data for deduplication.
        ];

        // Get user_data for logging (before it's hashed/processed).
        $raw_user_data = $this->get_user_data();
        
        // Log PageView event details for debugging deduplication.
        $this->logger->log('PageView event prepared for CAPI', 'info', [
            'event_id' => $event_id,
            'event_time' => $event_time,
            'event_time_formatted' => date('Y-m-d H:i:s', $event_time),
            'event_time_unix' => $event_time,
            'source' => 'CAPI',
            'url' => $event_source_url,
            'url_normalized' => true,
            'note' => 'URL normalized to match browser window.location.href (includes trailing slash)',
            'user_data_preview' => [
                'has_client_ip' => !empty($raw_user_data['client_ip_address']),
                'client_ip' => !empty($raw_user_data['client_ip_address']) ? $raw_user_data['client_ip_address'] : 'missing',
                'has_client_user_agent' => !empty($raw_user_data['client_user_agent']),
                'user_agent_preview' => !empty($raw_user_data['client_user_agent']) ? substr($raw_user_data['client_user_agent'], 0, 50) . '...' : 'missing',
                'has_fbp' => !empty($raw_user_data['fbp']),
                'fbp_preview' => !empty($raw_user_data['fbp']) ? substr($raw_user_data['fbp'], 0, 20) . '...' : 'missing',
                'has_fbc' => !empty($raw_user_data['fbc']),
                'has_email' => !empty($raw_user_data['email']),
                'note' => 'This user_data will be hashed before sending to CAPI. IP/UA captured from original request.',
            ],
        ]);

        // Allow filtering event data before sending.
        $event_data = apply_filters('meta_capi_page_view_event_data', $event_data);

        // Send the event asynchronously to avoid blocking page load.
        $this->send_event_async($event_data);
    }

    /**
     * Get current page view event ID for Pixel coordination.
     *
     * @return string Event ID or empty string if not set.
     */
    /**
     * Get PageView event ID for Pixel injection.
     * 
     * CRITICAL: If event ID doesn't exist (e.g., template_redirect didn't fire on cached pages),
     * generate a fallback event ID here to ensure browser and server use the SAME ID.
     * This matches the fallback logic in track_page_view().
     * 
     * @return string Event ID (never empty - generates fallback if needed).
     */
    public static function get_pageview_event_id(): string {
        // If event ID already exists, return it (normal case).
        if (!empty(self::$current_pageview_event_id)) {
            return self::$current_pageview_event_id;
        }
        
        // Fallback: Generate event ID if template_redirect didn't fire (e.g., cached pages).
        // This ensures browser and server use the SAME fallback event ID.
        // We use the same logic as track_page_view() fallback for consistency.
        $page_id = get_queried_object_id();
        $current_url = self::get_current_url_static();
        $identifier = $page_id > 0 ? (string) $page_id : md5($current_url);
        
        // Create a coordinator instance to generate the event ID.
        // We can't use $this->coordinator in a static method, so create a temporary instance.
        $coordinator = new Meta_CAPI_Coordinator();
        $event_id = $coordinator->generate_event_id('pageview', $identifier, true);
        
        // Store it so track_page_view() will use the same ID (prevents double generation).
        self::$current_pageview_event_id = $event_id;
        
        // Log fallback usage (only if logger is available - this is a static method).
        // Note: This is called from wp_head, so logging here helps track Pixel-side fallback usage.
        if (class_exists('Meta_CAPI_Logger')) {
            $logger = meta_capi()->logger;
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $logger->log('PageView event ID generated in fallback (Pixel injection)', 'info', [
                'event_id' => $event_id,
                'page_id' => $page_id,
                'identifier' => $identifier,
                'url' => $current_url,
                'request_uri' => $request_uri,
                'template_redirect_fired' => did_action('template_redirect'),
                'context' => 'Pixel injection fallback (wp_head hook)',
                'note' => 'This fallback ensures Pixel has an event ID even if template_redirect did not fire',
            ]);
        }
        
        return $event_id;
    }
    
    /**
     * Get current URL (static version for use in static methods).
     * 
     * @return string Current URL.
     */
    private static function get_current_url_static(): string {
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            return $protocol . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        }
        return home_url();
    }

    /**
     * Extract timestamp from event ID and convert to seconds.
     * Event ID format: {eventtype}_{identifier}_{timestamp_ms}
     * 
     * @param string $event_id Event ID with embedded timestamp.
     * @return int Timestamp in seconds, or 0 if extraction fails.
     */
    private function extract_timestamp_from_event_id(string $event_id): int {
        // Event ID format: pageview_49_1763148995777
        // Extract the last segment (timestamp in milliseconds).
        $parts = explode('_', $event_id);
        if (count($parts) < 3) {
            return 0; // Invalid format.
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
     * Get user data for the current visitor.
     * 
     * CRITICAL: This must include client_ip_address and client_user_agent so they're
     * captured before scheduling async events. When cron processes the event, $_SERVER
     * will have the cron process's IP/user agent, not the browser's.
     *
     * @return array User data.
     */
    private function get_user_data(): array {
        $user_data = [];

        // CRITICAL: Capture IP and user agent from the original request.
        // These will be used when the event is processed by cron (which won't have access to the browser's IP/UA).
        $user_data['client_ip_address'] = $this->get_client_ip();
        $user_data['client_user_agent'] = $this->get_user_agent();

        // Get logged-in user data if available.
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            
            if ($user->user_email) {
                $user_data['email'] = $user->user_email;
            }
            
            if ($user->first_name) {
                $user_data['first_name'] = $user->first_name;
            }
            
            if ($user->last_name) {
                $user_data['last_name'] = $user->last_name;
            }
        }

        // Get Facebook cookies.
        if (!empty($_COOKIE['_fbp'])) {
            $user_data['fbp'] = sanitize_text_field($_COOKIE['_fbp']);
        }

        if (!empty($_COOKIE['_fbc'])) {
            $user_data['fbc'] = sanitize_text_field($_COOKIE['_fbc']);
        }

        return $user_data;
    }

    /**
     * Get client IP address.
     * Uses same logic as Meta_CAPI_Client for consistency.
     *
     * @return string Client IP address.
     */
    private function get_client_ip(): string {
        $ip = '';

        // Check headers in order of trust (most trusted first).
        // CRITICAL: Priority order must match browser-side detection for deduplication.
        // For Cloudflare sites, browser events use CF-Connecting-IP, so server must check it first.
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare (MUST be first for CF sites - browser uses this).
            'HTTP_X_REAL_IP',        // Nginx/proxy real IP (check second).
            'HTTP_X_FORWARDED_FOR',  // Can be spoofed, check third.
            'HTTP_CLIENT_IP',        // Some proxies set this (check after X-Forwarded-For).
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                
                // Handle comma-separated IPs (take first).
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                // Remove port number if present.
                if (strpos($ip, ':') !== false) {
                    $ip_parts = explode(':', $ip);
                    $ip = $ip_parts[0];
                }

                if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        // Fallback to REMOTE_ADDR.
        $fallback_ip = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        
        if (!empty($fallback_ip) && strpos($fallback_ip, ':') !== false) {
            $ip_parts = explode(':', $fallback_ip);
            $fallback_ip = $ip_parts[0];
        }

        return !empty($fallback_ip) && filter_var($fallback_ip, FILTER_VALIDATE_IP) ? $fallback_ip : '';
    }

    /**
     * Get user agent.
     * Uses same logic as Meta_CAPI_Client for consistency.
     *
     * @return string User agent.
     */
    private function get_user_agent(): string {
        return !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    }

    /**
     * Check if request is from a bot, crawler, or automated service.
     * Prevents automated requests from triggering PageView events.
     *
     * @param string $user_agent User agent string.
     * @param string $request_uri Request URI.
     * @return bool True if bot/crawler, false otherwise.
     */
    private function is_bot_request(string $user_agent, string $request_uri): bool {
        // Empty user agent is suspicious (could be bot).
        if (empty($user_agent)) {
            return true;
        }

        // Common bot/crawler patterns.
        $bot_patterns = [
            // Search engine crawlers.
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'sogou',
            // Social media crawlers.
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp', 'telegrambot',
            // Monitoring/health check services.
            'uptimerobot', 'pingdom', 'monitor', 'healthcheck', 'statuscheck',
            // WordPress/plugin detection tools.
            'pixeldetector', 'wpreview', 'wpbot',
            // Cache warming/preview tools.
            'cache', 'preview', 'warmup',
            // Other common bots.
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python-requests', 'http',
            // WordPress specific bots.
            'wordpress', 'wp-', 'wp_',
        ];

        $user_agent_lower = strtolower($user_agent);
        
        foreach ($bot_patterns as $pattern) {
            if (strpos($user_agent_lower, $pattern) !== false) {
                return true;
            }
        }

        // Check for common bot indicators in request URI.
        $bot_uri_patterns = [
            '/wp-cron',
            '/cron',
            '/health',
            '/ping',
            '/status',
        ];
        
        foreach ($bot_uri_patterns as $pattern) {
            if (strpos($request_uri, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get custom data for the current page.
     *
     * @return array Custom data.
     */
    private function get_page_custom_data(): array {
        $custom_data = [];

        // Add page type.
        if (is_front_page()) {
            $custom_data['content_type'] = 'home';
        } elseif (is_page()) {
            $custom_data['content_type'] = 'page';
        } elseif (is_single()) {
            $custom_data['content_type'] = 'post';
        } elseif (is_archive()) {
            $custom_data['content_type'] = 'archive';
        } elseif (is_search()) {
            $custom_data['content_type'] = 'search';
        }

        // Add page title.
        $custom_data['content_name'] = wp_get_document_title();

        // Add post/page ID if available.
        if (is_singular()) {
            $custom_data['content_ids'] = [get_the_ID()];
        }

        // Add category for posts.
        if (is_single()) {
            $categories = get_the_category();
            if (!empty($categories)) {
                $custom_data['content_category'] = $categories[0]->name;
            }
        }

        return $custom_data;
    }

    /**
     * Get current URL.
     *
     * @return string Current URL.
     */
    private function get_current_url(): string {
        global $wp;
        return home_url(add_query_arg([], $wp->request));
    }

    /**
     * Get current URL normalized to match browser's window.location.href.
     * 
     * CRITICAL: Browser Pixel uses window.location.href which includes trailing slashes
     * for homepage and may include query parameters. We need to match this exactly.
     *
     * @return string Normalized current URL.
     */
    private function get_current_url_normalized(): string {
        // Use REQUEST_URI to match browser's window.location.href exactly.
        // This includes trailing slashes, query parameters, and path exactly as browser sees it.
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $url = $protocol . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            
            // Normalize: Ensure homepage has trailing slash (matches browser behavior).
            $parsed = wp_parse_url($url);
            if (isset($parsed['path']) && $parsed['path'] === '/') {
                // Already has trailing slash, good.
            } elseif (!isset($parsed['path']) || $parsed['path'] === '') {
                // No path, add trailing slash for homepage.
                $url = rtrim($url, '/') . '/';
            }
            
            return $url;
        }
        
        // Fallback to WordPress method if REQUEST_URI not available.
        global $wp;
        $url = home_url(add_query_arg([], $wp->request));
        
        // Ensure trailing slash for homepage.
        if (empty($wp->request) || $wp->request === '/') {
            $url = trailingslashit($url);
        }
        
        return $url;
    }

    /**
     * Send event asynchronously using WordPress wp_footer hook.
     * This ensures events are sent without blocking page load, but earlier than shutdown.
     *
     * @param array $event_data Event data.
     */
    private function send_event_async(array $event_data): void {
        if (!empty($event_data) && is_array($event_data)) {
            // Prevent duplicate hook registrations using event_id + event_time as key.
            $event_id = $event_data['event_id'] ?? '';
            $event_time = $event_data['event_time'] ?? 0;
            $dedupe_key = $event_id . '_' . $event_time;
            
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
            
            $this->logger->log('PageView event queued for wp_footer hook processing', 'info', [
                'event_id' => $event_data['event_id'] ?? 'missing',
                'note' => 'Event will be sent in footer (faster than shutdown)',
            ]);
        } else {
            $this->logger->log('send_event_async() called with empty or invalid event data', 'warning', [
                'event_data_empty' => empty($event_data),
                'event_data_is_array' => is_array($event_data),
            ]);
        }
    }

    /**
     * Track a custom event.
     *
     * @param string $event_name Event name.
     * @param array  $custom_data Custom event data.
     * @param array  $user_data Optional user data.
     * @return array Response from the API.
     */
    public function track_custom_event(string $event_name, array $custom_data = [], array $user_data = []): array {
        // Merge with default user data.
        if (empty($user_data)) {
            $user_data = $this->get_user_data();
        }

        $event_data = [
            'event_name' => $event_name,
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => $this->get_current_url(),
            'user_data' => $user_data,
            'custom_data' => $custom_data,
        ];

        return $this->client->send_event($event_data);
    }
}

// Register async event sending action.
// Static cache to prevent duplicate event sends in the same request.
// Key format: event_id_event_time
$GLOBALS['meta_capi_sent_events'] = $GLOBALS['meta_capi_sent_events'] ?? [];

add_action('meta_capi_send_event', function($event_data) {
    $logger = new Meta_CAPI_Logger();
    
    try {
        // CRITICAL: Validate event_data is an array BEFORE accessing any keys.
        // In PHP 8+, accessing array keys on non-array values can raise TypeErrors.
        if (!is_array($event_data) || empty($event_data)) {
            $logger->log('Invalid event data in async handler', 'error', [
                'event_data_type' => gettype($event_data),
                'event_data_empty' => empty($event_data),
                'event_data_is_array' => is_array($event_data),
            ]);
            return;
        }
        
        // CRITICAL: Prevent duplicate sends.
        // wp_schedule_single_event() + spawn_cron() can cause the same event to be processed twice:
        // 1. Immediately via spawn_cron()
        // 2. Again when the scheduled event fires
        // Use event_id + event_time as unique identifier.
        // Safe to access now - we've validated $event_data is an array above.
        $event_id = $event_data['event_id'] ?? '';
        $event_time = $event_data['event_time'] ?? 0;
        $dedupe_key = $event_id . '_' . $event_time;
        
        if (isset($GLOBALS['meta_capi_sent_events'][$dedupe_key])) {
            $logger->log('Duplicate event prevented in async handler', 'warning', [
                'dedupe_key' => $dedupe_key,
                'event_id' => $event_id,
                'event_time' => $event_time,
            ]);
            return;
        }
        $GLOBALS['meta_capi_sent_events'][$dedupe_key] = true;
        
        $logger->log('Processing async event - sending to CAPI', 'info', [
            'event_name' => $event_data['event_name'] ?? 'unknown',
            'event_id' => $event_id,
            'event_time' => $event_time,
        ]);
        
        $client = new Meta_CAPI_Client($logger);
        $result = $client->send_event($event_data);
        
        $logger->log('Async event processing completed', 'info', [
            'event_id' => $event_id,
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'unknown',
        ]);
    } catch (Exception $e) {
        $logger->log('Exception in async event handler', 'error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    } catch (Error $e) {
        $logger->log('Fatal error in async event handler', 'error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
});

