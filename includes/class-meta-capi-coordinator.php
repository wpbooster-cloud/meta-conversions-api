<?php
/**
 * Event Coordinator for Meta Conversions API.
 *
 * Coordinates event tracking between browser (Meta Pixel) and server (CAPI):
 * - Generates unique event IDs
 * - Stores event data for coordination
 * - Prevents duplicate event sending
 * - Manages event queue and deduplication
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
 * Meta CAPI Event Coordinator Class.
 */
class Meta_CAPI_Coordinator {

    /**
     * Meta CAPI Logger instance.
     *
     * @var Meta_CAPI_Logger
     */
    private Meta_CAPI_Logger $logger;

    /**
     * Transient prefix for event storage.
     *
     * @var string
     */
    private const TRANSIENT_PREFIX = 'meta_capi_event_';

    /**
     * Event ID cache to prevent duplicates within same request.
     *
     * @var array<string, bool>
     */
    private array $event_cache = [];

    /**
     * Constructor.
     *
     * @param Meta_CAPI_Logger $logger Logger instance.
     */
    public function __construct(Meta_CAPI_Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Generate a unique event ID.
     *
     * Format depends on event type:
     * - Purchase: purchase_{order_id}
     * - AddToCart: addtocart_{product_id}_{timestamp}
     * - ViewContent: viewcontent_{product_id}_{timestamp}
     * - InitiateCheckout: checkout_{session_id}
     *
     * Security: All inputs are sanitized.
     * Performance: Uses microtime for uniqueness without DB queries.
     *
     * @param string $event_type Event type (purchase, addtocart, etc.).
     * @param string $identifier Primary identifier (order ID, product ID, etc.).
     * @param bool   $include_timestamp Whether to include timestamp for uniqueness.
     * @return string Unique event ID.
     */
    public function generate_event_id(
        string $event_type,
        string $identifier,
        bool $include_timestamp = true
    ): string {
        // Sanitize inputs.
        $event_type = sanitize_key($event_type);
        $identifier = sanitize_key($identifier);

        if (empty($event_type) || empty($identifier)) {
            $this->logger->log('Invalid event ID parameters', 'error', [
                'event_type' => $event_type,
                'identifier' => $identifier,
            ]);
            // Fallback to generic ID.
            return 'event_' . uniqid('', true);
        }

        // Build event ID.
        if ($include_timestamp) {
            // Use milliseconds for uniqueness.
            $timestamp = (int) (microtime(true) * 1000);
            $event_id  = sprintf('%s_%s_%d', $event_type, $identifier, $timestamp);
        } else {
            // Static ID (for events that should be truly unique like Purchase).
            $event_id = sprintf('%s_%s', $event_type, $identifier);
        }

        $this->logger->log('Generated event ID', 'info', [
            'event_type'        => $event_type,
            'identifier'        => $identifier,
            'include_timestamp' => $include_timestamp,
            'event_id'          => $event_id,
        ]);

        return $event_id;
    }

    /**
     * Store event data for coordination between browser and server.
     *
     * Stores event ID and related data in a transient for retrieval.
     * Used when frontend needs to pass event ID to backend.
     *
     * @param string               $event_id   Unique event ID.
     * @param array<string, mixed> $event_data Event data to store.
     * @param int                  $expiration Transient expiration in seconds (default 1 hour).
     * @return bool True if stored successfully.
     */
    public function store_event_data(
        string $event_id,
        array $event_data,
        int $expiration = HOUR_IN_SECONDS
    ): bool {
        if (empty($event_id)) {
            $this->logger->log('Cannot store event with empty ID');
            return false;
        }

        $transient_key = $this->get_transient_key($event_id);

        // Add metadata.
        $event_data['_stored_at']  = time();
        $event_data['_event_id']   = $event_id;
        $event_data['_user_ip']    = $this->get_client_ip();
        $event_data['_session_id'] = $this->get_session_id();

        $result = set_transient($transient_key, $event_data, $expiration);

        if ($result) {
            $this->logger->log('Event data stored', 'info', [
                'event_id'   => $event_id,
                'expiration' => $expiration . 's',
            ]);
        } else {
            $this->logger->log('Failed to store event data', 'error', ['event_id' => $event_id]);
        }

        return $result;
    }

    /**
     * Retrieve stored event data.
     *
     * @param string $event_id Event ID to retrieve.
     * @param bool   $delete   Whether to delete after retrieval (default true).
     * @return array<string, mixed>|null Event data or null if not found.
     */
    public function get_event_data(string $event_id, bool $delete = true): ?array {
        if (empty($event_id)) {
            return null;
        }

        $transient_key = $this->get_transient_key($event_id);
        $event_data    = get_transient($transient_key);

        if ($event_data === false || !is_array($event_data)) {
            $this->logger->log('Event data not found', 'info', ['event_id' => $event_id]);
            return null;
        }

        $this->logger->log('Event data retrieved', 'info', [
            'event_id' => $event_id,
            'age'      => time() - ($event_data['_stored_at'] ?? 0) . 's',
        ]);

        // Delete after retrieval to prevent reuse.
        if ($delete) {
            delete_transient($transient_key);
            $this->logger->log('Event data deleted after retrieval', 'info', ['event_id' => $event_id]);
        }

        return $event_data;
    }

    /**
     * Check if an event has already been tracked.
     *
     * Prevents duplicate event sending within a time window.
     * Uses both in-memory cache and transient storage.
     *
     * @param string $event_id  Event ID to check.
     * @param int    $time_window Time window in seconds to check for duplicates (default 5 minutes).
     * @return bool True if event was already tracked.
     */
    public function is_duplicate_event(string $event_id, int $time_window = 300): bool {
        // Check in-memory cache first (for same request).
        if (isset($this->event_cache[$event_id])) {
            $this->logger->log('Duplicate event detected (memory cache)', 'info', ['event_id' => $event_id]);
            return true;
        }

        // Check transient storage (for different requests).
        $tracking_key = 'meta_capi_tracked_' . md5($event_id);
        $tracked_at   = get_transient($tracking_key);

        if ($tracked_at !== false) {
            $age = time() - (int) $tracked_at;
            $this->logger->log('Duplicate event detected (transient)', 'info', [
                'event_id' => $event_id,
                'age'      => $age . 's',
            ]);
            return true;
        }

        return false;
    }

    /**
     * Mark an event as tracked to prevent duplicates.
     *
     * Stores tracking information in both memory and transient.
     *
     * @param string $event_id    Event ID to mark.
     * @param int    $time_window How long to remember this event (default 5 minutes).
     * @return void
     */
    public function mark_event_tracked(string $event_id, int $time_window = 300): void {
        // Store in memory cache.
        $this->event_cache[$event_id] = true;

        // Store in transient.
        $tracking_key = 'meta_capi_tracked_' . md5($event_id);
        set_transient($tracking_key, time(), $time_window);

        $this->logger->log('Event marked as tracked', 'info', [
            'event_id'    => $event_id,
            'cached_for'  => $time_window . 's',
        ]);
    }

    /**
     * Get transient key for event storage.
     *
     * @param string $event_id Event ID.
     * @return string Transient key.
     */
    private function get_transient_key(string $event_id): string {
        // Use MD5 hash to keep key length consistent.
        return self::TRANSIENT_PREFIX . md5($event_id);
    }

    /**
     * Get current session ID.
     *
     * Uses WooCommerce session if available, otherwise creates one.
     * Security: Session ID is sanitized.
     *
     * @return string Session ID.
     */
    private function get_session_id(): string {
        // Try WooCommerce session first.
        if (function_exists('WC') && WC()->session) {
            $session_id = WC()->session->get_customer_id();
            if (!empty($session_id)) {
                return sanitize_key($session_id);
            }
        }

        // Try WordPress session.
        if (session_id()) {
            return sanitize_key(session_id());
        }

        // Fallback: Generate from user ID or IP.
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }

        return 'guest_' . md5($this->get_client_ip() . wp_salt());
    }

    /**
     * Get client IP address.
     *
     * Security: Validates and sanitizes IP.
     * Performance: Checks proxy headers in order of trust.
     *
     * @return string Client IP address.
     */
    private function get_client_ip(): string {
        $ip = '';

        // Check for proxy headers.
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare.
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                
                // Handle comma-separated IPs (X-Forwarded-For).
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip  = trim($ips[0]);
                }
                
                break;
            }
        }

        // Validate IP.
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '0.0.0.0';
    }

    /**
     * Cleanup old event data.
     *
     * Removes expired event tracking data.
     * Should be called via WP Cron periodically.
     *
     * @return int Number of items cleaned up.
     */
    public function cleanup_old_events(): int {
        global $wpdb;

        // Delete expired transients with our prefix.
        $count = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                AND option_name LIKE %s",
                $wpdb->esc_like('_transient_timeout_' . self::TRANSIENT_PREFIX) . '%',
                '%' . $wpdb->esc_like('meta_capi_') . '%'
            )
        );

        $this->logger->log('Cleaned up old event data', 'info', ['count' => $count]);

        return $count;
    }

    /**
     * Get coordinator status for debugging.
     *
     * @return array<string, mixed> Status information.
     */
    public function get_status(): array {
        global $wpdb;

        // Count stored events.
        $stored_events = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} 
                WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::TRANSIENT_PREFIX) . '%'
            )
        );

        // Count tracked events.
        $tracked_events = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} 
                WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_meta_capi_tracked_') . '%'
            )
        );

        return [
            'stored_events'        => $stored_events,
            'tracked_events'       => $tracked_events,
            'memory_cache_size'    => count($this->event_cache),
            'session_id'           => $this->get_session_id(),
            'client_ip'            => $this->get_client_ip(),
        ];
    }

    /**
     * Clear all coordinator data.
     *
     * Useful for testing or troubleshooting.
     * Security: Should only be called by admin.
     *
     * @return bool True if cleared successfully.
     */
    public function clear_all_data(): bool {
        if (!current_user_can('manage_options')) {
            $this->logger->log('Unauthorized attempt to clear coordinator data', 'error');
            return false;
        }

        global $wpdb;

        // Delete all event transients.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::TRANSIENT_PREFIX) . '%',
                $wpdb->esc_like('_transient_timeout_' . self::TRANSIENT_PREFIX) . '%'
            )
        );

        // Delete all tracking transients.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                OR option_name LIKE %s",
                $wpdb->esc_like('_transient_meta_capi_tracked_') . '%',
                $wpdb->esc_like('_transient_timeout_meta_capi_tracked_') . '%'
            )
        );

        // Clear memory cache.
        $this->event_cache = [];

        $this->logger->log('All coordinator data cleared', 'info');

        return true;
    }
}

