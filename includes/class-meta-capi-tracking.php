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
        // Log that this hook fired (for debugging).
        $this->logger->log('generate_pageview_event_id() hook fired', 'debug', [
            'is_admin' => is_admin(),
            'wp_doing_ajax' => wp_doing_ajax(),
            'wp_doing_cron' => wp_doing_cron(),
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
        ]);

        // Don't track admin pages or AJAX requests.
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            $this->logger->log('generate_pageview_event_id() returning early - admin/ajax/cron', 'debug');
            return;
        }

        // CRITICAL: Check admin user here too, but we still generate event ID for Pixel coordination.
        // Pixel needs the event ID even if CAPI will skip sending (for browser-side tracking).
        // However, if admin tracking is disabled, we can skip generating the ID entirely.
        // Note: The actual skip for CAPI sending happens in track_page_view().
        // For now, we generate the ID anyway to ensure Pixel has it (Pixel's own admin check will prevent injection).

        // Skip tracking favicon, robots.txt, and other non-page requests.
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (preg_match('/\/(favicon\.ico|robots\.txt|sitemap.*\.xml|wp-admin|wp-includes|wp-json|crossdomain\.xml|apple-touch-icon.*\.png)/i', $request_uri)) {
            $this->logger->log('generate_pageview_event_id() returning early - non-page request', 'debug', ['request_uri' => $request_uri]);
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
        } else {
            // Event ID already exists (from a previous call or instance).
            $this->logger->log('PageView event ID already exists, skipping regeneration', 'debug', [
                'event_id' => self::$current_pageview_event_id,
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
                $this->logger->log('generate_pageview_event_id() - page excluded, but event ID generated for Pixel', 'debug', ['page_id' => $current_page_id]);
                return;
            }
        }
        
        // Allow filtering to skip tracking on specific pages.
        if (apply_filters('meta_capi_skip_page_view', false)) {
            $this->logger->log('generate_pageview_event_id() - skipped via filter, but event ID generated for Pixel', 'debug');
            return;
        }
        
        // Note: Admin check happens in track_page_view(), not here.
        // This ensures event ID is generated for Pixel even if CAPI will skip sending.
    }

    /**
     * Track page view event.
     */
    public function track_page_view(): void {
        // Log entry into this method (for debugging what triggers it).
        $this->logger->log('track_page_view() hook fired', 'debug', [
            'is_admin' => is_admin(),
            'wp_doing_ajax' => wp_doing_ajax(),
            'wp_doing_cron' => wp_doing_cron(),
            'is_user_logged_in' => is_user_logged_in(),
            'current_user_can_manage_options' => current_user_can('manage_options'),
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'http_referer' => isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : 'direct',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 50) : '',
        ]);

        // CRITICAL: Check admin user FIRST, before setting static flag or any other checks.
        // This prevents admin users from being tracked when viewing the frontend.
        // Note: is_admin() only returns true for admin dashboard pages, not when admin views frontend.
        // So we must check current_user_can('manage_options') separately for frontend pages.
        // Also check if user is logged in first to avoid false positives.
        $user_is_logged_in = is_user_logged_in();
        $is_admin_user = $user_is_logged_in && current_user_can('manage_options');
        $skip_admin_tracking = apply_filters('meta_capi_skip_admin_tracking', true);
        
        // Log admin check details for debugging.
        if ($user_is_logged_in) {
            $this->logger->log('User is logged in, checking admin status', 'debug', [
                'user_id' => get_current_user_id(),
                'is_admin_user' => $is_admin_user,
                'skip_admin_tracking_filter' => $skip_admin_tracking,
            ]);
        }
        
        if ($is_admin_user && $skip_admin_tracking) {
            $this->logger->log('track_page_view() returning early - admin user (skip admin tracking enabled)', 'info', [
                'user_id' => get_current_user_id(),
                'is_admin_user' => $is_admin_user,
                'skip_admin_tracking_filter' => $skip_admin_tracking,
                'note' => 'Admin users are excluded from CAPI tracking by default',
            ]);
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
        
        // Log that we're starting to track (for debugging duplicate tracking).
        $this->logger->log('Starting PageView tracking (server-side)', 'debug', [
            'hook' => current_filter(),
            'event_id' => self::$current_pageview_event_id,
            'static_flag_set' => true,
        ]);

        // Don't track admin pages or AJAX requests.
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            $this->logger->log('track_page_view() returning early - admin/ajax/cron', 'debug', [
                'is_admin' => is_admin(),
                'wp_doing_ajax' => wp_doing_ajax(),
                'wp_doing_cron' => wp_doing_cron(),
            ]);
            return;
        }

        // Skip tracking favicon, robots.txt, and other non-page requests.
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (preg_match('/\/(favicon\.ico|robots\.txt|sitemap.*\.xml|wp-admin|wp-includes|wp-json|crossdomain\.xml|apple-touch-icon.*\.png)/i', $request_uri)) {
            return;
        }

        // Check if this page is excluded.
        $excluded_pages_str = get_option('meta_capi_exclude_pages', '');
        if (!empty($excluded_pages_str)) {
            $excluded_pages = array_filter(array_map('absint', explode(',', $excluded_pages_str)));
            $current_page_id = get_queried_object_id();
            if (in_array($current_page_id, $excluded_pages, true)) {
                $this->logger->info('PageView skipped - page is in exclusion list', ['page_id' => $current_page_id]);
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
            $identifier = $page_id > 0 ? (string) $page_id : md5($this->get_current_url());
            $event_id = $this->coordinator->generate_event_id('pageview', $identifier, true);
            self::$current_pageview_event_id = $event_id;
            $this->logger->log('PageView event ID generated in fallback (generate_pageview_event_id did not run)', 'warning', [
                'event_id' => $event_id,
                'page_id' => $page_id,
                'identifier' => $identifier,
                'note' => 'template_redirect hook may not have fired (cached page, redirect, or early exit)',
                'hook' => 'fallback_in_track_page_view',
                'warning' => 'This should not happen - event ID should be generated on template_redirect',
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

        // Prepare event data.
        $event_data = [
            'event_name' => 'PageView',
            'event_time' => $event_time, // Use current time to match browser Pixel timing
            'event_id' => $event_id, // Add event ID for deduplication
            'action_source' => 'website',
            'event_source_url' => $this->get_current_url(),
            'user_data' => $this->get_user_data(),
            'custom_data' => $this->get_page_custom_data(),
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
            'url' => $this->get_current_url(),
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
    public static function get_pageview_event_id(): string {
        return self::$current_pageview_event_id;
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
        // Priority order: HTTP_CLIENT_IP before HTTP_X_REAL_IP for universal compatibility.
        // HTTP_X_REAL_IP is Nginx-specific, while HTTP_CLIENT_IP is more universally supported.
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare (most trusted when using CF).
            'HTTP_CLIENT_IP',        // Universal proxy header (check before Nginx-specific).
            'HTTP_X_REAL_IP',        // Nginx/proxy real IP (Nginx-specific, check after HTTP_CLIENT_IP).
            'HTTP_X_FORWARDED_FOR',  // Can be spoofed, check last.
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
     * Send event asynchronously using WordPress cron.
     *
     * @param array $event_data Event data.
     */
    private function send_event_async(array $event_data): void {
        if (!empty($event_data) && is_array($event_data)) {
            wp_schedule_single_event(time(), 'meta_capi_send_event', [$event_data]);
            // Don't call spawn_cron() - WordPress cron will process scheduled events automatically
            // Calling spawn_cron() can cause blocking/timeout issues on some servers
            // Events will be processed on the next cron run (usually within 1 minute)
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
add_action('meta_capi_send_event', function($event_data) {
    try {
        if (empty($event_data) || !is_array($event_data)) {
            error_log('Meta CAPI: Invalid event data in async handler');
            return;
        }
        $logger = new Meta_CAPI_Logger();
        $client = new Meta_CAPI_Client($logger);
        $client->send_event($event_data);
    } catch (Exception $e) {
        error_log('Meta CAPI: Error in async event handler: ' . $e->getMessage());
    } catch (Error $e) {
        error_log('Meta CAPI: Fatal error in async event handler: ' . $e->getMessage());
    }
});

