<?php
/**
 * Meta Pixel Management for Meta Conversions API.
 *
 * Handles Meta Pixel (Facebook Pixel) injection and coordination:
 * - Auto-detects existing pixel installations
 * - Injects pixel code when needed
 * - Coordinates event IDs between browser and server
 * - Manages pixel configuration
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
 * Meta CAPI Pixel Management Class.
 */
class Meta_CAPI_Pixel {

    /**
     * Meta CAPI Logger instance.
     *
     * @var Meta_CAPI_Logger
     */
    private Meta_CAPI_Logger $logger;

    /**
     * Pixel ID from settings.
     *
     * @var string
     */
    private string $pixel_id = '';

    /**
     * Whether to auto-inject pixel.
     *
     * @var bool
     */
    private bool $auto_inject = true;

    /**
     * Whether an existing pixel was detected.
     *
     * @var bool|null
     */
    private ?bool $existing_pixel_detected = null;

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
     * Load plugin settings into cache.
     *
     * @return void
     */
    private function load_settings(): void {
        $this->pixel_id    = sanitize_text_field(get_option('meta_capi_pixel_id', ''));
        $this->auto_inject = (bool) get_option('meta_capi_enable_pixel', true);

        $this->settings = [
            'pixel_id'            => $this->pixel_id,
            'auto_inject'         => $this->auto_inject,
            'disable_auto_config' => (bool) get_option('meta_capi_disable_auto_config', true),
        ];

        $this->logger->log('Pixel settings loaded', 'info', $this->settings);
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    private function init_hooks(): void {
        // Only inject pixel if enabled and pixel ID is set.
        if ($this->auto_inject && !empty($this->pixel_id)) {
            add_action('wp_head', [$this, 'inject_pixel_code'], 5);
            add_action('wp_footer', [$this, 'inject_pixel_noscript'], 100);
            $this->logger->log('Pixel injection hooks registered', 'info');
        }

        // Admin hooks for pixel detection.
        if (is_admin()) {
            add_action('admin_init', [$this, 'detect_existing_pixel']);
        }
    }

    /**
     * Inject Meta Pixel base code in <head>.
     *
     * Security: Pixel ID is validated and sanitized.
     * Performance: Minimal code, loads async.
     *
     * @return void
     */
    public function inject_pixel_code(): void {
        // Don't inject for logged-in admins (optional).
        if ($this->should_skip_tracking()) {
            $this->logger->log('Skipping pixel injection for current user', 'info');
            return;
        }

        // Validate pixel ID format (should be numeric).
        if (!$this->is_valid_pixel_id($this->pixel_id)) {
            $this->logger->log('Invalid pixel ID format', 'error', ['pixel_id' => $this->pixel_id]);
            return;
        }

        // Check if pixel is already on page (prevent duplicates).
        if ($this->is_pixel_already_loaded()) {
            $this->logger->log('Pixel already detected on page, skipping injection', 'info');
            return;
        }

        $this->logger->log('Injecting Meta Pixel code', 'info', ['pixel_id' => $this->pixel_id]);
        
        ?>
        <!-- Meta Pixel Code (Meta Conversions API Plugin) -->
        <script type="text/javascript">
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        
        <?php if ($this->settings['disable_auto_config']): ?>
        fbq('set', 'autoConfig', false, '<?php echo esc_js($this->pixel_id); ?>');
        <?php endif; ?>
        fbq('init', '<?php echo esc_js($this->pixel_id); ?>');
        <?php if (!$this->settings['disable_auto_config']): ?>
        fbq('track', 'PageView');
        <?php endif; ?>
        </script>
        <!-- End Meta Pixel Code -->
        <?php
    }

    /**
     * Inject Meta Pixel noscript code in <body> (footer).
     *
     * Required for browsers with JavaScript disabled.
     *
     * @return void
     */
    public function inject_pixel_noscript(): void {
        // Same checks as main pixel injection.
        if ($this->should_skip_tracking() || !$this->is_valid_pixel_id($this->pixel_id)) {
            return;
        }

        ?>
        <!-- Meta Pixel Code (noscript) -->
        <noscript>
            <img height="1" width="1" style="display:none"
                 src="https://www.facebook.com/tr?id=<?php echo esc_attr($this->pixel_id); ?>&ev=PageView&noscript=1"
                 alt="" />
        </noscript>
        <!-- End Meta Pixel Code (noscript) -->
        <?php
    }

    /**
     * Detect if existing Meta Pixel is already on the site.
     *
     * Scans homepage HTML for fbq() calls or pixel script.
     * Runs in admin only to avoid performance impact.
     *
     * @return void
     */
    public function detect_existing_pixel(): void {
        // Only run once per session.
        if ($this->existing_pixel_detected !== null) {
            return;
        }

        // Check transient cache first (24 hour cache).
        $cached = get_transient('meta_capi_pixel_detection');
        if ($cached !== false) {
            $this->existing_pixel_detected = (bool) $cached;
            $this->logger->log('Pixel detection from cache', 'info', ['detected' => $this->existing_pixel_detected]);
            return;
        }

        $this->logger->log('Running pixel detection scan', 'info');

        // Fetch homepage HTML.
        $response = wp_remote_get(
            home_url('/'),
            [
                'timeout'    => 10,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . ' PixelDetector',
                'sslverify'  => false, // For local dev environments.
            ]
        );

        if (is_wp_error($response)) {
            $this->logger->log('Pixel detection failed', 'error', ['error' => $response->get_error_message()]);
            $this->existing_pixel_detected = false;
            set_transient('meta_capi_pixel_detection', 0, DAY_IN_SECONDS);
            return;
        }

        $html = wp_remote_retrieve_body($response);

        // Look for Meta Pixel indicators.
        $detected = (
            strpos($html, 'fbevents.js') !== false ||
            strpos($html, 'fbq(') !== false ||
            strpos($html, 'facebook.com/tr?id=') !== false
        );

        $this->existing_pixel_detected = $detected;
        set_transient('meta_capi_pixel_detection', $detected ? 1 : 0, DAY_IN_SECONDS);

        $this->logger->log('Pixel detection complete', 'info', [
            'detected'   => $detected,
            'cached_for' => '24 hours',
        ]);

        // Store in admin notice if detected.
        if ($detected && $this->auto_inject) {
            set_transient('meta_capi_pixel_conflict_warning', 1, WEEK_IN_SECONDS);
        }
    }

    /**
     * Check if pixel is already loaded on current page.
     *
     * Looks for pixel in output buffer if available.
     *
     * @return bool True if pixel detected.
     */
    private function is_pixel_already_loaded(): bool {
        // This is a simple check - in production we'd use JS detection.
        // For now, we rely on the admin detection.
        return $this->existing_pixel_detected === true;
    }

    /**
     * Validate pixel ID format.
     *
     * Pixel IDs should be 15-16 digit numbers.
     *
     * @param string $pixel_id Pixel ID to validate.
     * @return bool True if valid.
     */
    private function is_valid_pixel_id(string $pixel_id): bool {
        return !empty($pixel_id) && 
               is_numeric($pixel_id) && 
               strlen($pixel_id) >= 10 &&
               strlen($pixel_id) <= 20;
    }

    /**
     * Check if tracking should be skipped for current user/request.
     *
     * Skips tracking for:
     * - Admin users (optional setting)
     * - Preview/draft pages
     * - Admin pages
     * - AJAX requests
     *
     * @return bool True if should skip.
     */
    private function should_skip_tracking(): bool {
        // Skip in admin area.
        if (is_admin()) {
            return true;
        }

        // Skip for AJAX requests.
        if (wp_doing_ajax()) {
            return true;
        }

        // Skip for preview/draft pages.
        if (is_preview() || is_customize_preview()) {
            return true;
        }

        // Optional: Skip for logged-in admins.
        $skip_admins = (bool) get_option('meta_capi_pixel_skip_admins', false);
        if ($skip_admins && current_user_can('manage_options')) {
            return true;
        }

        return false;
    }

    /**
     * Generate a unique event ID for coordination.
     *
     * Format: {event_type}_{identifier}_{timestamp}
     * Example: purchase_123_1635789012
     *
     * @param string $event_type Event type (purchase, addtocart, etc.).
     * @param string $identifier Unique identifier (order ID, product ID, etc.).
     * @return string Event ID.
     */
    public function generate_event_id(string $event_type, string $identifier): string {
        // Use timestamp for uniqueness (milliseconds).
        $timestamp = (int) (microtime(true) * 1000);
        
        // Format: eventtype_identifier_timestamp.
        $event_id = sprintf(
            '%s_%s_%d',
            sanitize_key($event_type),
            sanitize_key($identifier),
            $timestamp
        );

        $this->logger->log('Generated event ID', 'info', [
            'event_type' => $event_type,
            'identifier' => $identifier,
            'event_id'   => $event_id,
        ]);

        return $event_id;
    }

    /**
     * Output JavaScript to track a custom event.
     *
     * Used for frontend tracking that coordinates with backend.
     *
     * @param string               $event_name  Event name (Purchase, AddToCart, etc.).
     * @param array<string, mixed> $event_data  Event parameters.
     * @param string               $event_id    Event ID for deduplication.
     * @return void
     */
    public function track_event(string $event_name, array $event_data, string $event_id): void {
        if ($this->should_skip_tracking()) {
            return;
        }

        // Sanitize event name.
        $event_name = sanitize_key($event_name);

        // Encode event data safely.
        $event_data_json = wp_json_encode($event_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        if ($event_data_json === false) {
            $this->logger->log('Failed to encode event data', 'error', ['event_name' => $event_name]);
            return;
        }

        ?>
        <script type="text/javascript">
        if (typeof fbq !== 'undefined') {
            fbq('track', '<?php echo esc_js($event_name); ?>', <?php echo $event_data_json; ?>, {
                eventID: '<?php echo esc_js($event_id); ?>'
            });
        }
        </script>
        <?php
    }

    /**
     * Get pixel configuration status.
     *
     * @return array<string, mixed> Status information.
     */
    public function get_status(): array {
        return [
            'pixel_id'               => !empty($this->pixel_id) ? 'Set' : 'Not Set',
            'pixel_id_valid'         => $this->is_valid_pixel_id($this->pixel_id),
            'auto_inject'            => $this->auto_inject,
            'existing_pixel_detected' => $this->existing_pixel_detected,
            'settings'               => $this->settings,
        ];
    }

    /**
     * Clear pixel detection cache.
     *
     * Useful after pixel settings change.
     *
     * @return void
     */
    public function clear_detection_cache(): void {
        delete_transient('meta_capi_pixel_detection');
        delete_transient('meta_capi_pixel_conflict_warning');
        $this->existing_pixel_detected = null;
        $this->logger->log('Pixel detection cache cleared', 'info');
    }
}

