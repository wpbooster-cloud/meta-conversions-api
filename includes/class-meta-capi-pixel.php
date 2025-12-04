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
     * Global flag to prevent duplicate pixel injection across all instances.
     *
     * @var bool
     */
    private static bool $pixel_injected_global = false;

    /**
     * Global flag to prevent duplicate hook registrations across all instances.
     *
     * @var bool
     */
    private static bool $hooks_registered_global = false;

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

        $this->logger->info('Pixel settings loaded', $this->settings);
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    private function init_hooks(): void {
        // Prevent duplicate hook registrations across ALL instances (global static flag).
        if (self::$hooks_registered_global) {
            return;
        }
        
        $hooks_registered = false;
        
        // Only inject pixel if enabled and pixel ID is set.
        if ($this->auto_inject && !empty($this->pixel_id)) {
            add_action('wp_head', [$this, 'inject_preconnect_hints'], 2); // Early for preconnect
            add_action('wp_head', [$this, 'inject_pixel_code'], 5);
            add_action('wp_footer', [$this, 'inject_pixel_noscript'], 100);
            $hooks_registered = true;
            $this->logger->info('Pixel injection hooks registered (global with preconnect)');
        } else {
            // Log why hooks weren't registered (for debugging).
            $this->logger->log('Pixel injection hooks NOT registered', 'debug', [
                'auto_inject' => $this->auto_inject,
                'pixel_id_set' => !empty($this->pixel_id),
                'pixel_id' => !empty($this->pixel_id) ? 'set' : 'empty',
            ]);
        }

        // Admin hooks for pixel detection (always register if in admin, regardless of pixel injection status).
        if (is_admin()) {
            add_action('admin_init', [$this, 'detect_existing_pixel']);
        }
        
        // CRITICAL: Only set flag to true if hooks were actually registered.
        // If pixel injection is disabled or pixel_id is empty, don't set the flag,
        // allowing subsequent instances with proper configuration to register hooks.
        if ($hooks_registered) {
            self::$hooks_registered_global = true;
            $this->logger->log('Global hooks flag set to true (hooks were registered)', 'debug');
        } else {
            $this->logger->log('Global hooks flag NOT set (no hooks registered - allows subsequent instances to register)', 'debug');
        }
    }

    /**
     * Inject preconnect hints for Facebook domains.
     *
     * This speeds up pixel loading by establishing early connections to Facebook servers.
     * Saves ~300ms on DNS lookup + TLS handshake.
     *
     * @return void
     */
    public function inject_preconnect_hints(): void {
        // Don't inject for logged-in admins.
        if ($this->should_skip_tracking()) {
            return;
        }

        ?>
        <!-- Meta Pixel Preconnect Hints (Meta Conversions API Plugin) -->
        <link rel="preconnect" href="https://connect.facebook.net" crossorigin>
        <link rel="dns-prefetch" href="https://connect.facebook.net">
        <link rel="dns-prefetch" href="https://www.facebook.com">
        <?php
    }

    /**
     * Inject Meta Pixel base code in <head>.
     *
     * Security: Pixel ID is validated and sanitized.
     * Performance: Uses delayed loading strategy - pixel loads after user interaction or 3s timeout.
     *
     * @return void
     */
    public function inject_pixel_code(): void {
        // Log that this method was called (for debugging duplicate injections).
        $this->logger->log('inject_pixel_code() called', 'debug', [
            'hook' => current_filter(),
            'pixel_injected_global' => self::$pixel_injected_global,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2), // Show call stack
        ]);
        
        // Don't inject for logged-in admins (optional).
        if ($this->should_skip_tracking()) {
            $this->logger->info('Skipping pixel injection for current user');
            return;
        }

        // Validate pixel ID format (should be numeric).
        if (!$this->is_valid_pixel_id($this->pixel_id)) {
            $this->logger->error('Invalid pixel ID format', ['pixel_id' => $this->pixel_id]);
            return;
        }

        // Check if pixel is already on page (prevent duplicates).
        if ($this->is_pixel_already_loaded()) {
            $this->logger->info('Pixel already detected on page, skipping injection');
            return;
        }

        // Use a global static flag to prevent duplicate injection across all instances.
        // This is a fallback in case is_pixel_already_loaded() doesn't catch it.
        if (self::$pixel_injected_global) {
            $this->logger->warning('Pixel injection attempted multiple times in same request, preventing duplicate');
            return;
        }
        self::$pixel_injected_global = true;

        // Check if current page is excluded from tracking.
        $excluded_pages_str = get_option('meta_capi_exclude_pages', '');
        $current_page_id = get_queried_object_id();
        $is_page_excluded = false;
        if (!empty($excluded_pages_str) && $current_page_id > 0) {
            $excluded_pages = array_filter(array_map('absint', explode(',', $excluded_pages_str)));
            $is_page_excluded = in_array($current_page_id, $excluded_pages, true);
            if ($is_page_excluded) {
                $this->logger->info('Pixel injection skipped - page is in exclusion list', [
                    'page_id' => $current_page_id,
                ]);
                return; // Don't inject pixel at all on excluded pages.
            }
        }

        $this->logger->info('Injecting Meta Pixel code (delayed loading)', ['pixel_id' => $this->pixel_id]);
        
        ?>
        <!-- Meta Pixel Code (Meta Conversions API Plugin - Delayed Loading) -->
        <script type="text/javascript">
        // Log pixel initialization for debugging
        if (typeof console !== 'undefined' && console.log) {
            console.log('[Meta CAPI] Pixel code executing - initializing fbq stub with delayed loading');
        }
        
        // Step 1: Create fbq stub function immediately (captures all events in queue)
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!1;n.version='2.0';
        n.queue=[];n.agent='plmeta_capi_delayed'}(window,document,'script','about:blank');
        
        // Step 2: Delayed loading function (loads fbevents.js)
        (function() {
            var pixelLoaded = false;
            var pixelUrl = 'https://connect.facebook.net/en_US/fbevents.js';
            
            function loadMetaPixel() {
                if (pixelLoaded) return;
                pixelLoaded = true;
                
                if (typeof console !== 'undefined' && console.log) {
                    console.log('[Meta CAPI] Loading Meta Pixel (delayed)', {
                        trigger: 'user_interaction_or_timeout'
                    });
                }
                
                // Load the actual fbevents.js
                var script = document.createElement('script');
                script.async = true;
                script.src = pixelUrl;
                var firstScript = document.getElementsByTagName('script')[0];
                firstScript.parentNode.insertBefore(script, firstScript);
                
                // Mark fbq as loaded (will process queue when script arrives)
                window.fbq.loaded = true;
            }
            
            // Trigger 1: Load on first user interaction (fastest)
            var interactionEvents = ['mousemove', 'scroll', 'touchstart', 'click', 'keydown'];
            interactionEvents.forEach(function(eventType) {
                document.addEventListener(eventType, loadMetaPixel, { 
                    once: true, 
                    passive: true,
                    capture: true 
                });
            });
            
            // Trigger 2: Fallback after 3 seconds (ensures loading even with no interaction)
            setTimeout(loadMetaPixel, 3000);
            
            // Trigger 3: Load immediately if page is already interactive/complete
            if (document.readyState === 'complete') {
                setTimeout(loadMetaPixel, 100);
            } else {
                window.addEventListener('load', function() {
                    setTimeout(loadMetaPixel, 100);
                });
            }
        })();
        
        // Log after fbq function is created
        if (typeof console !== 'undefined' && console.log) {
            console.log('[Meta CAPI] fbq function created', {
                fbq_exists: typeof fbq !== 'undefined',
                fbq_type: typeof fbq,
                pixel_id: '<?php echo esc_js($this->pixel_id); ?>'
            });
        }
        
        // CRITICAL: Always disable autoConfig to prevent automatic PageView tracking.
        // Meta Pixel's auto PageView fires immediately on init with its own timestamp,
        // which would break deduplication. We track PageView manually with our event ID.
        try {
        fbq('set', 'autoConfig', false, '<?php echo esc_js($this->pixel_id); ?>');
        fbq('init', '<?php echo esc_js($this->pixel_id); ?>');
            
            if (typeof console !== 'undefined' && console.log) {
                console.log('[Meta CAPI] Pixel initialized', {
                    pixel_id: '<?php echo esc_js($this->pixel_id); ?>',
                    autoConfig_disabled: true
                });
            }
        } catch (e) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('[Meta CAPI] Error initializing pixel', {
                    error: e.message,
                    pixel_id: '<?php echo esc_js($this->pixel_id); ?>'
                });
            }
        }
        
        <?php
        // Get event ID from tracking class (generated early in template_redirect).
        // This is the SAME event ID used by CAPI for deduplication.
        // Single source of truth: static property in Meta_CAPI_Tracking class.
        $pageview_event_id = '';
        if (class_exists('Meta_CAPI_Tracking')) {
            $pageview_event_id = Meta_CAPI_Tracking::get_pageview_event_id();
            
            // CRITICAL: Log whether event ID was found or not (for debugging duplicate event IDs).
            if (empty($pageview_event_id)) {
                $this->logger->warning('PageView event ID NOT FOUND during Pixel injection - Pixel will track WITHOUT eventID', [
                    'hook' => 'wp_head',
                    'warning' => 'This means CAPI and Pixel will have different event IDs, breaking deduplication',
                    'note' => 'Event ID should have been generated on template_redirect hook',
                    'action' => 'Pixel will track without eventID - deduplication will NOT work',
                ]);
            } else {
                $this->logger->log('PageView event ID retrieved for Pixel injection', 'debug', [
                    'hook' => 'wp_head',
                    'event_id' => $pageview_event_id,
                    'note' => 'This is the SAME event ID that CAPI will use',
                ]);
            }
            
            // CRITICAL: DO NOT generate event ID on-demand here!
            // If we generate a NEW event ID at this point, it will be different from the one used by CAPI,
            // causing deduplication to fail. The event ID MUST be generated on template_redirect
            // (or earlier) so both CAPI and Pixel use the same ID.
            // If the event ID is missing, it's better to track without it (and log a warning)
            // than to generate a different ID that breaks deduplication.
        }
        
        // CRITICAL: We MUST have the same event ID as CAPI for deduplication.
        // If event ID is still empty after on-demand generation, Pixel will track without eventID.
        // This should not happen, but it's better than generating a different ID.
        ?>
        <?php if (!empty($pageview_event_id)): ?>
        // Track PageView with event ID for deduplication with CAPI (same event ID).
        // CRITICAL: Use multiple flags to prevent duplicate tracking:
        // 1. Check if already tracked (window._metaCapiPageViewTracked)
        // 2. Set flag immediately before tracking (prevents race conditions)
        // 3. Also check if fbq has already been called for PageView
        (function() {
            // CRITICAL: Check if PageView was already tracked (prevents duplicates).
            if (typeof window._metaCapiPageViewTracked !== 'undefined' && window._metaCapiPageViewTracked === true) {
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('[Meta CAPI] PageView already tracked, preventing duplicate (browser-side check)', {
                        event_id: '<?php echo esc_js($pageview_event_id); ?>',
                        timestamp: new Date().toISOString()
                    });
                }
                return; // Exit immediately if already tracked.
            }
            
            // Set flag IMMEDIATELY to prevent race conditions if script runs multiple times.
            window._metaCapiPageViewTracked = true;
            var eventId = '<?php echo esc_js($pageview_event_id); ?>';
            var serverTime = <?php echo time(); ?>;
            var currentTime = Math.floor(Date.now() / 1000);
            
            // CRITICAL: Check if event_id is stale (from cached page).
            // Only regenerate if event_id is VERY old (60+ seconds), indicating a truly cached page.
            // We should NOT regenerate for normal page load delays (server_time vs current_time can differ by 10-30 seconds
            // due to page rendering time, which is normal and doesn't indicate caching).
            // The server event WILL be sent even if there's a delay, so we must use the SAME event ID.
            var eventIdAge = 0;
            var isStale = false;
            var parts = eventId.split('_');
            if (parts.length >= 3) {
                var timestampMs = parseInt(parts[parts.length - 1], 10);
                if (!isNaN(timestampMs)) {
                    var eventIdTime = Math.floor(timestampMs / 1000);
                    eventIdAge = currentTime - eventIdTime;
                    
                    // Only regenerate if event_id is VERY old (60+ seconds), indicating a truly cached page.
                    // Normal page load delays (5-30 seconds) are expected and should NOT trigger regeneration.
                    // The server event will be sent via wp_footer hook, so we must use the SAME event ID.
                    if (eventIdAge > 60) {
                        isStale = true;
                        if (typeof console !== 'undefined' && console.warn) {
                            console.warn('[Meta CAPI] Event ID is stale (likely from cached page - 60+ seconds old)', {
                                old_event_id: eventId,
                                event_id_age_seconds: eventIdAge,
                                server_time: serverTime,
                                current_time: currentTime,
                                note: 'Event ID is very old. Page appears to be cached. Server event may not be sent. Consider excluding plugin from page cache (Breeze).'
                            });
                        }
                        // Generate fresh event_id: pageview_{page_id}_{current_timestamp_ms}
                        var pageId = parts.length >= 2 ? parts[1] : 'unknown';
                        eventId = 'pageview_' + pageId + '_' + Date.now();
                    }
                }
            }
            
            // CRITICAL: Extract timestamp from event_id to match server-side event_time exactly.
            // Event ID format: pageview_{identifier}_{timestamp_ms} where timestamp is in milliseconds.
            // We extract this and convert to seconds to ensure perfect alignment with CAPI.
            var eventTime = (function() {
                var eventParts = eventId.split('_');
                if (eventParts.length >= 3) {
                    var timestampMs = parseInt(eventParts[eventParts.length - 1], 10);
                    if (!isNaN(timestampMs)) {
                        return Math.floor(timestampMs / 1000); // Convert milliseconds to seconds.
                    }
                }
                // Fallback to current time if extraction fails (shouldn't happen).
                return Math.floor(Date.now() / 1000);
            })();
            
            // Log PageView event details for debugging deduplication.
            // CRITICAL: Log user_data info that Pixel will send for deduplication comparison.
            if (typeof console !== 'undefined' && console.log) {
                // Get fbp cookie (Meta Pixel automatically includes this in user_data).
                var fbpCookie = '';
                var cookies = document.cookie.split(';');
                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();
                    if (cookie.indexOf('_fbp=') === 0) {
                        fbpCookie = cookie.substring(5);
                        break;
                    }
                }
                
                // Get user agent and IP (as detected by browser - Meta Pixel will use this).
                var userAgent = navigator.userAgent || 'unknown';
                var ipAddress = 'browser_detected'; // Meta Pixel gets IP from request, not JavaScript
                
                console.log('[Meta CAPI Debug] PageView tracked via Pixel - FULL PAYLOAD', {
                    event_id: eventId,
                    event_time: eventTime,
                    event_time_formatted: new Date(eventTime * 1000).toISOString(),
                    event_time_unix: eventTime,
                    event_time_current: Math.floor(Date.now() / 1000),
                    time_difference: Math.floor(Date.now() / 1000) - eventTime,
                    source: 'Browser',
                    url: window.location.href,
                    user_data: {
                        fbp: fbpCookie || 'not_set_yet',
                        fbp_preview: fbpCookie ? fbpCookie.substring(0, 30) + '...' : 'not_set_yet',
                        user_agent: userAgent.substring(0, 100) + '...',
                        ip_address: ipAddress,
                        note: 'Meta Pixel automatically includes fbp, IP (from request), and user agent in event'
                    },
                    pixel_payload: {
                        eventName: 'PageView',
                        eventData: {},
                        options: {
                            eventID: eventId,
                            eventTime: eventTime
                        }
                    }
                });
            }
            
            // CRITICAL: Pass both eventID and eventTime for perfect deduplication alignment.
            // eventTime must match the server-side event_time exactly.
            // Meta Pixel v2.0+ respects the eventTime parameter when provided.
            // We MUST use the timestamp extracted from event_id (not current time) to match server.
            // IMPORTANT: eventTime must be a Unix timestamp in seconds (not milliseconds).
            var currentTime = Math.floor(Date.now() / 1000);
            if (typeof console !== 'undefined' && console.log) {
                console.log('[Meta CAPI] PageView tracking with extracted timestamp', {
                    event_id: eventId,
                    event_time_extracted: eventTime,
                    event_time_current: currentTime,
                    event_id_age_seconds: eventIdAge,
                    time_difference_seconds: currentTime - eventTime,
                    server_time: serverTime,
                    server_time_diff: Math.abs(currentTime - serverTime),
                    is_stale: isStale,
                    note: isStale ? '⚠️ Event ID was stale (from cache) - fresh one generated. Server event may not be sent if page is fully cached.' : 'eventTime MUST match server-side event_time exactly for deduplication'
                });
            }
            
            try {
                // Meta Pixel API: fbq('track', eventName, eventData, options)
                // options can include: eventID, eventTime, etc.
                // This is Meta's official format for deduplication.
                // eventTime MUST match server-side event_time exactly (Unix timestamp in seconds).
                
                // CRITICAL: Check if fbq exists before calling (may not exist on cached pages).
                if (typeof fbq === 'undefined') {
                    if (typeof console !== 'undefined' && console.error) {
                        console.error('[Meta CAPI] fbq is undefined - pixel script may not have loaded', {
                            event_id: eventId,
                            note: 'This can happen if the page is fully cached and pixel code is missing. Check if pixel code is in page HTML.'
                        });
                    }
                    return; // Can't track without fbq
                }
                
                fbq('track', 'PageView', {}, {
                    eventID: eventId
                });
                
                if (typeof console !== 'undefined' && console.log) {
                    console.log('[Meta CAPI] PageView fbq() call completed', {
                        event_id: eventId,
                        event_time: eventTime,
                        fbq_loaded: typeof fbq !== 'undefined' && fbq.loaded !== undefined ? fbq.loaded : 'unknown'
                    });
                }
            } catch (e) {
                // Fallback if fbq fails - log error but still try to track with eventID only.
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[Meta CAPI] Error tracking PageView:', {
                        error: e.message,
                        event_id: eventId,
                        stack: e.stack
                    });
                }
                // Fallback: Track with eventID only (deduplication may still work if event_time matches).
                try {
                fbq('track', 'PageView', {}, {
                    eventID: eventId
                });
                } catch (e2) {
                    if (typeof console !== 'undefined' && console.error) {
                        console.error('[Meta CAPI] Failed to track PageView even with fallback', {
                            error: e2.message,
                            event_id: eventId
                        });
                    }
                }
            }
        })(); // End self-executing function to prevent duplicate execution.
        <?php else: ?>
        // PageView event ID not available - track without eventID (deduplication will not work).
        // This should not happen if template_redirect hook ran properly.
        // CRITICAL: Use self-executing function with duplicate check to prevent multiple executions.
        (function() {
            // CRITICAL: Check if PageView was already tracked (prevents duplicates).
            if (typeof window._metaCapiPageViewTracked !== 'undefined' && window._metaCapiPageViewTracked === true) {
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('[Meta CAPI] PageView already tracked, preventing duplicate (browser-side check, no event ID)', {
                        timestamp: new Date().toISOString()
                    });
                }
                return; // Exit immediately if already tracked.
            }
            
            // Set flag IMMEDIATELY to prevent race conditions.
            window._metaCapiPageViewTracked = true;
            var eventTime = Math.floor(Date.now() / 1000); // Unix timestamp in seconds
            
            // Log PageView event details for debugging deduplication.
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[Meta CAPI Debug] PageView tracked via Pixel WITHOUT event ID (deduplication will fail)', {
                    event_id: 'none',
                    event_time: eventTime,
                    event_time_formatted: new Date(eventTime * 1000).toISOString(),
                    event_time_unix: eventTime,
                    source: 'Browser',
                    url: window.location.href
                });
            }
            
            fbq('track', 'PageView');
        })(); // End self-executing function to prevent duplicate execution.
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
            $this->logger->info('Pixel detection from cache', ['detected' => $this->existing_pixel_detected]);
            return;
        }

        $this->logger->info('Running pixel detection scan');

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
            $this->logger->error('Pixel detection failed', ['error' => $response->get_error_message()]);
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

        $this->logger->info('Pixel detection complete', [
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
            $this->logger->log('should_skip_tracking() returning true - is_admin()', 'debug');
            return true;
        }

        // Skip for AJAX requests.
        if (wp_doing_ajax()) {
            $this->logger->log('should_skip_tracking() returning true - wp_doing_ajax()', 'debug');
            return true;
        }

        // Skip for preview/draft pages.
        if (is_preview() || is_customize_preview()) {
            $this->logger->log('should_skip_tracking() returning true - is_preview() or is_customize_preview()', 'debug');
            return true;
        }

        // CRITICAL: Skip for logged-in admins (consistent with CAPI tracking).
        // This prevents admin users from triggering Pixel events when viewing the frontend.
        // Note: is_admin() only returns true for admin dashboard pages, not when admin views frontend.
        // So we must check current_user_can('manage_options') separately for frontend pages.
        // Use same filter as CAPI tracking for consistency: meta_capi_skip_admin_tracking (defaults to true).
        // Also check if user is logged in first to avoid false positives.
        $user_is_logged_in = is_user_logged_in();
        $is_admin_user = $user_is_logged_in && current_user_can('manage_options');
        $skip_admin_tracking = apply_filters('meta_capi_skip_admin_tracking', true);
        
        // Log admin check details for debugging.
        if ($user_is_logged_in) {
            $this->logger->log('User is logged in, checking admin status in should_skip_tracking()', 'debug', [
                'user_id' => get_current_user_id(),
                'is_admin_user' => $is_admin_user,
                'skip_admin_tracking_filter' => $skip_admin_tracking,
            ]);
        }
        
        if ($is_admin_user && $skip_admin_tracking) {
            $this->logger->log('should_skip_tracking() returning true - admin user (skip admin tracking enabled)', 'info', [
                'user_id' => get_current_user_id(),
                'is_admin_user' => $is_admin_user,
                'skip_admin_tracking_filter' => $skip_admin_tracking,
                'note' => 'Admin users are excluded from Pixel tracking by default',
            ]);
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

        $this->logger->info('Generated event ID', [
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
            $this->logger->error('Failed to encode event data', ['event_name' => $event_name]);
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
        $this->logger->info('Pixel detection cache cleared');
    }
}

