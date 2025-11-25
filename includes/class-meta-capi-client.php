<?php
/**
 * Facebook Conversions API client.
 *
 * @package Meta_Conversions_API
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta_CAPI_Client class.
 */
class Meta_CAPI_Client {
    /**
     * API endpoint.
     *
     * @var string
     */
    private const API_ENDPOINT = 'https://graph.facebook.com/v18.0';

    /**
     * Logger instance.
     *
     * @var Meta_CAPI_Logger
     */
    private Meta_CAPI_Logger $logger;

    /**
     * Pixel ID.
     *
     * @var string
     */
    private string $pixel_id;

    /**
     * Access token.
     *
     * @var string
     */
    private string $access_token;

    /**
     * Test event code.
     *
     * @var string
     */
    private string $test_event_code;

    /**
     * Constructor.
     *
     * @param Meta_CAPI_Logger $logger Logger instance.
     */
    public function __construct(Meta_CAPI_Logger $logger) {
        $this->logger = $logger;
        $this->pixel_id = get_option('meta_capi_pixel_id', '');
        $this->access_token = get_option('meta_capi_access_token', '');
        $this->test_event_code = get_option('meta_capi_test_event_code', '');
    }

    /**
     * Check if the client is configured.
     *
     * @return bool True if configured, false otherwise.
     */
    public function is_configured(): bool {
        return !empty($this->pixel_id) && !empty($this->access_token);
    }

    /**
     * Send an event to Facebook Conversions API.
     *
     * @param array $event_data Event data.
     * @return array Response with 'success' and 'message' keys.
     */
    public function send_event(array $event_data): array {
        if (!$this->is_configured()) {
            $this->logger->warning('Facebook Conversions API not configured');
            return [
                'success' => false,
                'message' => __('Facebook Conversions API is not configured.', 'meta-conversions-api'),
            ];
        }

        // Build the API URL.
        $url = sprintf(
            '%s/%s/events',
            self::API_ENDPOINT,
            $this->pixel_id
        );

        // Prepare the event data.
        $event = $this->prepare_event_data($event_data);

        // Build request body.
        $body = [
            'data' => [$event],
        ];

        // Add test event code if configured.
        if (!empty($this->test_event_code)) {
            $body['test_event_code'] = $this->test_event_code;
        }

        // Build request arguments.
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 10, // Reduced from 30s to 10s for better performance
        ];

        // Add access token to URL.
        $url = add_query_arg('access_token', $this->access_token, $url);

        // Log detailed event information for debugging deduplication.
        // CRITICAL: Log user_data to verify fbp, IP, and user agent match browser-side.
        $user_data_summary = [
            'has_fbp' => !empty($event['user_data']['fbp']),
            'fbp_value' => !empty($event['user_data']['fbp']) ? substr($event['user_data']['fbp'], 0, 20) . '...' : 'missing',
            'has_fbc' => !empty($event['user_data']['fbc']),
            'has_ip' => !empty($event['user_data']['client_ip_address']),
            'ip_value' => !empty($event['user_data']['client_ip_address']) ? $event['user_data']['client_ip_address'] : 'missing',
            'ip_value_full' => !empty($event['user_data']['client_ip_address']) ? $event['user_data']['client_ip_address'] : 'missing', // Full IP for comparison
            'has_user_agent' => !empty($event['user_data']['client_user_agent']),
            'user_agent_preview' => !empty($event['user_data']['client_user_agent']) ? substr($event['user_data']['client_user_agent'], 0, 50) . '...' : 'missing',
            'user_agent_full' => !empty($event['user_data']['client_user_agent']) ? $event['user_data']['client_user_agent'] : 'missing', // Full user agent for comparison
            'has_em' => !empty($event['user_data']['em']),
            'has_ph' => !empty($event['user_data']['ph']),
        ];
        
        // Log full payload for debugging (sanitize sensitive data).
        $payload_for_log = $body;
        if (isset($payload_for_log['data'][0]['user_data'])) {
            // Mask hashed PII in logs (show structure, not values).
            $user_data_keys = array_keys($payload_for_log['data'][0]['user_data']);
            $payload_for_log['data'][0]['user_data'] = array_fill_keys($user_data_keys, '[REDACTED]');
            // But keep IP and user agent visible (needed for dedup debugging).
            // CRITICAL: Show FULL values (not truncated) for deduplication comparison.
            if (!empty($event['user_data']['client_ip_address'])) {
                $payload_for_log['data'][0]['user_data']['client_ip_address'] = $event['user_data']['client_ip_address'];
            }
            if (!empty($event['user_data']['client_user_agent'])) {
                $payload_for_log['data'][0]['user_data']['client_user_agent'] = $event['user_data']['client_user_agent']; // Full user agent for comparison
            }
            if (!empty($event['user_data']['fbp'])) {
                $payload_for_log['data'][0]['user_data']['fbp'] = substr($event['user_data']['fbp'], 0, 30) . '...';
            }
        }

        // Log full IP and user agent for deduplication comparison (CRITICAL for debugging).
        $dedup_comparison = [
            'ip_address' => !empty($event['user_data']['client_ip_address']) ? $event['user_data']['client_ip_address'] : 'MISSING',
            'user_agent_full' => !empty($event['user_data']['client_user_agent']) ? $event['user_data']['client_user_agent'] : 'MISSING',
            'user_agent_length' => !empty($event['user_data']['client_user_agent']) ? strlen($event['user_data']['client_user_agent']) : 0,
            'fbp' => !empty($event['user_data']['fbp']) ? $event['user_data']['fbp'] : 'MISSING',
            'note' => 'Compare these EXACT values with browser event in Meta Test Events for deduplication',
        ];
        
        $this->logger->info('Sending event to Facebook Conversions API', [
            'event_name' => $event_data['event_name'] ?? 'unknown',
            'event_id' => $event['event_id'] ?? 'none',
            'event_time' => $event['event_time'] ?? 'none',
            'event_time_formatted' => isset($event['event_time']) ? date('Y-m-d H:i:s', $event['event_time']) : 'none',
            'user_data' => $user_data_summary,
            'dedup_comparison' => $dedup_comparison, // Full values for comparison
            'url' => preg_replace('/access_token=[^&]+/', 'access_token=***', $url),
            'source' => 'CAPI',
            'full_payload' => $payload_for_log, // Full payload structure for debugging
        ]);

        // Send the request.
        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error('Failed to send event to Facebook', [
                'error' => $error_message,
            ]);

            // Track failure for admin notification
            $event_name = $event_data['event_name'] ?? 'Unknown';
            $this->track_api_failure($error_message, $event_name, 0);

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_response = json_decode($response_body, true);

        if ($response_code >= 200 && $response_code < 300) {
            // Meta's API response may contain deduplication hints.
            // Log full response for debugging.
            $this->logger->info('Event sent successfully - Meta API Response', [
                'event_name' => $event_data['event_name'] ?? 'unknown',
                'event_id' => $event['event_id'] ?? 'none',
                'event_time' => $event['event_time'] ?? 'none',
                'response_code' => $response_code,
                'response_body' => $decoded_response, // Full response from Meta
                'events_received' => isset($decoded_response['events_received']) ? $decoded_response['events_received'] : 'not_provided',
                'messages' => isset($decoded_response['messages']) ? $decoded_response['messages'] : 'not_provided',
                'fbtrace_id' => isset($decoded_response['fbtrace_id']) ? $decoded_response['fbtrace_id'] : 'not_provided',
            ]);

            // Check for deduplication warnings in response.
            if (isset($decoded_response['messages']) && is_array($decoded_response['messages'])) {
                foreach ($decoded_response['messages'] as $message) {
                    if (isset($message['message']) && (stripos($message['message'], 'duplicate') !== false || stripos($message['message'], 'dedup') !== false)) {
                        $this->logger->warning('Meta API deduplication message detected', [
                            'event_id' => $event['event_id'] ?? 'none',
                            'message' => $message,
            ]);
                    }
                }
            }

            return [
                'success' => true,
                'message' => __('Event sent successfully.', 'meta-conversions-api'),
                'response' => $decoded_response,
            ];
        } else {
            $error_message = $decoded_response['error']['message'] ?? __('Unknown error', 'meta-conversions-api');
            
            $this->logger->error('Failed to send event - Meta API Error Response', [
                'response_code' => $response_code,
                'response_body' => $response_body, // Raw response body
                'decoded_response' => $decoded_response,
                'event_id' => $event['event_id'] ?? 'none',
                'event_name' => $event_data['event_name'] ?? 'unknown',
            ]);

            // Track failure for admin notification
            $event_name = $event_data['event_name'] ?? 'Unknown';
            $this->track_api_failure($error_message, $event_name, $response_code);

            return [
                'success' => false,
                'message' => $error_message,
                'response' => $decoded_response,
            ];
        }
    }

    /**
     * Prepare event data for the API.
     *
     * @param array $event_data Raw event data.
     * @return array Prepared event data.
     */
    private function prepare_event_data(array $event_data): array {
        $event = [
            'event_name' => $event_data['event_name'] ?? 'PageView',
            'event_time' => $event_data['event_time'] ?? time(),
            'action_source' => $event_data['action_source'] ?? 'website',
            'event_source_url' => $event_data['event_source_url'] ?? $this->get_current_url(),
            'user_data' => $this->prepare_user_data($event_data['user_data'] ?? []),
        ];

        // Add event ID for deduplication.
        if (!empty($event_data['event_id'])) {
            $event['event_id'] = $event_data['event_id'];
        } else {
            $event['event_id'] = $this->generate_event_id($event);
        }

        // Add custom data if provided.
        if (!empty($event_data['custom_data'])) {
            $event['custom_data'] = $event_data['custom_data'];
        }

        // Add opt_out if provided.
        if (isset($event_data['opt_out'])) {
            $event['opt_out'] = (bool) $event_data['opt_out'];
        }

        return $event;
    }

    /**
     * Prepare user data for the API.
     *
     * @param array $user_data Raw user data.
     * @return array Prepared and hashed user data.
     */
    private function prepare_user_data(array $user_data): array {
        $prepared = [];

        // CRITICAL: For async events (processed by cron), user_data is captured from the original request
        // and passed in event_data. We MUST use the provided user_data, not try to detect from $_SERVER
        // (which would be the cron process's IP/user agent, not the browser's).
        // Only use fallback detection if user_data is completely empty (shouldn't happen for async events).
        
        // Get client IP address.
        if (!empty($user_data['client_ip_address'])) {
            $prepared['client_ip_address'] = $user_data['client_ip_address'];
        } elseif (empty($user_data)) {
            // Only fallback if user_data is completely empty (shouldn't happen for async events).
            $prepared['client_ip_address'] = $this->get_client_ip();
        } else {
            // user_data exists but client_ip_address is missing - this is a bug, log warning
            $this->logger->warning('user_data provided but client_ip_address missing', [
                'user_data_keys' => array_keys($user_data),
            ]);
        }

        // Get user agent.
        if (!empty($user_data['client_user_agent'])) {
            $prepared['client_user_agent'] = $user_data['client_user_agent'];
        } elseif (empty($user_data)) {
            // Only fallback if user_data is completely empty (shouldn't happen for async events).
            $prepared['client_user_agent'] = $this->get_user_agent();
        } else {
            // user_data exists but client_user_agent is missing - this is a bug, log warning
            $this->logger->warning('user_data provided but client_user_agent missing', [
                'user_data_keys' => array_keys($user_data),
            ]);
        }
        
        // Get Facebook browser ID (fbp cookie).
        // CRITICAL: Use provided fbp from user_data (captured from original request).
        // Don't fallback to $_COOKIE in cron context (cookies won't be available).
        if (!empty($user_data['fbp'])) {
            $prepared['fbp'] = $user_data['fbp'];
        } elseif (empty($user_data) && !empty($_COOKIE['_fbp'])) {
            // Only fallback if user_data is completely empty (shouldn't happen for async events).
            $prepared['fbp'] = sanitize_text_field($_COOKIE['_fbp']);
        }

        // Get Facebook click ID (fbc cookie).
        // CRITICAL: Use provided fbc from user_data (captured from original request).
        // Don't fallback to $_COOKIE in cron context (cookies won't be available).
        if (!empty($user_data['fbc'])) {
            $prepared['fbc'] = $user_data['fbc'];
        } elseif (empty($user_data) && !empty($_COOKIE['_fbc'])) {
            // Only fallback if user_data is completely empty (shouldn't happen for async events).
            $prepared['fbc'] = sanitize_text_field($_COOKIE['_fbc']);
        }

        // Hash PII data according to Facebook requirements.
        if (!empty($user_data['email'])) {
            $prepared['em'] = $this->hash_pii(strtolower(trim($user_data['email'])));
        }

        if (!empty($user_data['phone'])) {
            $prepared['ph'] = $this->hash_pii($this->normalize_phone($user_data['phone']));
        }

        if (!empty($user_data['first_name'])) {
            $prepared['fn'] = $this->hash_pii(strtolower(trim($user_data['first_name'])));
        }

        if (!empty($user_data['last_name'])) {
            $prepared['ln'] = $this->hash_pii(strtolower(trim($user_data['last_name'])));
        }

        if (!empty($user_data['city'])) {
            $prepared['ct'] = $this->hash_pii(strtolower(trim($user_data['city'])));
        }

        if (!empty($user_data['state'])) {
            $prepared['st'] = $this->hash_pii(strtolower(trim($user_data['state'])));
        }

        if (!empty($user_data['zip'])) {
            $prepared['zp'] = $this->hash_pii(strtolower(trim($user_data['zip'])));
        }

        if (!empty($user_data['country'])) {
            $prepared['country'] = $this->hash_pii(strtolower(trim($user_data['country'])));
        }

        // Add external_id for better matching (optional but recommended by Meta).
        // external_id should be a hashed email or phone number for user identification.
        // Meta uses this for advanced matching and deduplication.
        if (!empty($user_data['email'])) {
            // Use hashed email as external_id (same hash as em field for consistency).
            $prepared['external_id'] = $this->hash_pii(strtolower(trim($user_data['email'])));
        } elseif (!empty($user_data['phone'])) {
            // Fallback to hashed phone if email not available.
            $prepared['external_id'] = $this->hash_pii($this->normalize_phone($user_data['phone']));
        }

        return $prepared;
    }

    /**
     * Hash PII data using SHA-256.
     *
     * @param string $value Value to hash.
     * @return string Hashed value.
     */
    private function hash_pii(string $value): string {
        return hash('sha256', $value);
    }

    /**
     * Normalize phone number.
     *
     * @param string $phone Phone number.
     * @return string Normalized phone number.
     */
    private function normalize_phone(string $phone): string {
        // Remove all non-numeric characters.
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Get client IP address.
     * Handles CDN/proxy headers correctly for deduplication.
     * Security: Checks headers in order of trust (most trusted first).
     * Meta Pixel uses the browser's actual IP, so we must extract the real client IP.
     *
     * @return string Client IP address.
     */
    private function get_client_ip(): string {
        $ip = '';

        // Check headers in order of trust (most trusted first).
        // CRITICAL: Priority order must match browser-side detection for deduplication.
        // For Cloudflare sites, browser events use CF-Connecting-IP, so server must check it first.
        // Security: HTTP_X_FORWARDED_FOR can be spoofed by clients, so check it last.
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare (MUST be first for CF sites - browser uses this).
            'HTTP_X_REAL_IP',        // Nginx/proxy real IP (check second).
            'HTTP_X_FORWARDED_FOR',  // Can be spoofed, check third.
            'HTTP_CLIENT_IP',        // Some proxies set this (check after X-Forwarded-For).
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                
                // Handle comma-separated IPs (X-Forwarded-For can have multiple IPs: "client, proxy1, proxy2").
                // We want the FIRST (original client) IP.
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
                
                // Remove port number if present (e.g., "192.168.1.1:8080" -> "192.168.1.1").
                // Apply this BEFORE validation to ensure consistent handling.
                if (strpos($ip, ':') !== false) {
                    $ip_parts = explode(':', $ip);
                    $ip = $ip_parts[0];
                }

                // Validate it's a real IP address (allow private/reserved ranges for local testing).
                if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
                
                // If IP from header failed validation, continue to next header (don't break).
                // This ensures we try all headers before falling back to REMOTE_ADDR.
            }
        }

        // Fallback to REMOTE_ADDR (might be proxy IP, but better than nothing).
        // Apply same port-stripping logic as headers for consistency.
        $fallback_ip = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        
        // Remove port number if present (CRITICAL: must do this before validation).
        // Bug fix: Previously this wasn't done, causing IPs with ports to fail validation.
        if (!empty($fallback_ip) && strpos($fallback_ip, ':') !== false) {
            $ip_parts = explode(':', $fallback_ip);
            $fallback_ip = $ip_parts[0];
        }
        
        // Validate fallback IP too.
        if (!empty($fallback_ip) && filter_var($fallback_ip, FILTER_VALIDATE_IP)) {
            return $fallback_ip;
        }

        return '';
    }

    /**
     * Get user agent.
     *
     * @return string User agent.
     */
    private function get_user_agent(): string {
        return !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
    }

    /**
     * Get current URL.
     *
     * @return string Current URL.
     */
    private function get_current_url(): string {
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $protocol = is_ssl() ? 'https://' : 'http://';
            return $protocol . sanitize_text_field($_SERVER['HTTP_HOST']) . sanitize_text_field($_SERVER['REQUEST_URI']);
        }

        return home_url('/');
    }

    /**
     * Generate a unique event ID for deduplication.
     *
     * @param array $event Event data.
     * @return string Event ID.
     */
    private function generate_event_id(array $event): string {
        $data = wp_json_encode($event) . microtime(true);
        return md5($data);
    }

    /**
     * Track API failures and send admin notification if threshold reached.
     *
     * @param string $error_message Error message.
     * @param string $event_name Event name (optional).
     * @param int    $response_code HTTP response code (optional).
     */
    private function track_api_failure(string $error_message, string $event_name = '', int $response_code = 0): void {
        // Check if notifications are enabled.
        if (!get_option('meta_capi_enable_error_notifications', true)) {
            return;
        }
        
        // Get current failure count (resets after 1 hour)
        $failure_count = get_transient('meta_capi_failure_count');
        $failure_count = $failure_count ? (int) $failure_count + 1 : 1;
        
        // Store failure count
        set_transient('meta_capi_failure_count', $failure_count, HOUR_IN_SECONDS);
        
        // Store failure details.
        $failures = get_transient('meta_capi_failure_details');
        $failures = is_array($failures) ? $failures : [];
        
        $failure_entry = [
            'timestamp' => current_time('mysql'),
            'event_name' => $event_name ?: 'Unknown',
            'error' => $error_message,
            'response_code' => $response_code,
        ];
        
        // Keep last 10 failures for reporting.
        $failures[] = $failure_entry;
        if (count($failures) > 10) {
            $failures = array_slice($failures, -10);
        }
        
        set_transient('meta_capi_failure_details', $failures, HOUR_IN_SECONDS);
        set_transient('meta_capi_last_error', $error_message, DAY_IN_SECONDS);
        
        // Get threshold setting.
        $threshold = get_option('meta_capi_notification_threshold', 5);
        
        // Send notification if threshold reached (but max once per day)
        if ($failure_count >= $threshold && !get_transient('meta_capi_alert_sent')) {
            $this->send_failure_notification($failure_count, $failures);
        }
    }

    /**
     * Send email notification to admin about API failures.
     *
     * @param int   $failure_count Number of failures.
     * @param array $failures Array of failure details.
     */
    private function send_failure_notification(int $failure_count, array $failures): void {
        // Get notification email (or default to admin email).
        $notification_email = get_option('meta_capi_notification_email', '');
        if (empty($notification_email) || !is_email($notification_email)) {
            $notification_email = get_option('admin_email');
        }
        
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $domain = wp_parse_url($site_url, PHP_URL_HOST);
        $settings_url = admin_url('options-general.php?page=meta-conversions-api');
        $troubleshooting_url = admin_url('options-general.php?page=meta-conversions-api&tab=troubleshooting');
        
        $subject = sprintf(
            /* translators: 1: Site name, 2: Domain */
            __('[%1$s - %2$s] Meta Pixel & Conversions API: Connection Issues Detected', 'meta-conversions-api'),
            $site_name,
            $domain
        );
        
        // Group failures by event type and error message.
        $event_summary = [];
        $unique_errors = [];
        
        foreach ($failures as $failure) {
            $event_type = $failure['event_name'] ?? 'Unknown';
            if (!isset($event_summary[$event_type])) {
                $event_summary[$event_type] = 0;
            }
            $event_summary[$event_type]++;
            
            $error_key = $failure['error'];
            if (!isset($unique_errors[$error_key])) {
                $unique_errors[$error_key] = [
                    'error' => $failure['error'],
                    'response_code' => $failure['response_code'],
                    'count' => 0,
                ];
            }
            $unique_errors[$error_key]['count']++;
        }
        
        // Get recent unique errors (last 5).
        $recent_errors = array_slice($unique_errors, -5, 5, true);
        
        // Build HTML email.
        $message = $this->build_failure_email_html(
            $site_name,
            $domain,
            $failure_count,
            $event_summary,
            $recent_errors,
            $failures,
            $settings_url,
            $troubleshooting_url
        );
        
        // Set content type for HTML email.
        add_filter('wp_mail_content_type', function() {
            return 'text/html';
        });
        
        // Send email.
        $sent = wp_mail($notification_email, $subject, $message);
        
        // Reset content type.
        remove_all_filters('wp_mail_content_type');
        
        if ($sent) {
            // Mark alert as sent (expires in 24 hours)
            set_transient('meta_capi_alert_sent', true, DAY_IN_SECONDS);
            
            // Log notification
            $this->logger->info('Admin notification sent for API failures', [
                'failure_count' => $failure_count,
                'notification_email' => $notification_email,
            ]);
        }
    }
    
    /**
     * Build HTML email content for failure notification.
     *
     * @param string $site_name Site name.
     * @param string $domain Site domain.
     * @param int    $failure_count Total failure count.
     * @param array  $event_summary Event type summary.
     * @param array  $recent_errors Recent unique errors.
     * @param array  $all_failures All failure details.
     * @param string $settings_url Settings page URL.
     * @param string $troubleshooting_url Troubleshooting page URL.
     * @return string HTML email content.
     */
    private function build_failure_email_html(
        string $site_name,
        string $domain,
        int $failure_count,
        array $event_summary,
        array $recent_errors,
        array $all_failures,
        string $settings_url,
        string $troubleshooting_url
    ): string {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('Meta Pixel & Conversions API Error Notification', 'meta-conversions-api'); ?></title>
        </head>
        <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
            <div style="background-color: #ffffff; border-radius: 8px; padding: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                
                <h1 style="color: #d63638; margin-top: 0; font-size: 24px; border-bottom: 2px solid #d63638; padding-bottom: 10px;">
                    <?php echo esc_html__('Meta Pixel & Conversions API: Connection Issues Detected', 'meta-conversions-api'); ?>
                </h1>
                
                <p style="font-size: 16px; margin-top: 20px;">
                    <?php
                    printf(
                        /* translators: 1: Site name, 2: Domain */
                        esc_html__('Hello,', 'meta-conversions-api')
                    );
                    ?>
                </p>
                
                <p style="font-size: 16px;">
                    <?php
                    printf(
                        /* translators: 1: Site name, 2: Domain, 3: Failure count */
                        esc_html__('The Meta Pixel & Conversions API plugin on %1$s (%2$s) has detected %3$d API connection failure(s) in the last hour.', 'meta-conversions-api'),
                        '<strong>' . esc_html($site_name) . '</strong>',
                        esc_html($domain),
                        $failure_count
                    );
                    ?>
                </p>
                
                <p style="font-size: 16px;">
                    <?php esc_html_e('This email was sent to alert you to potential connection issues that may prevent events from being tracked on Facebook.', 'meta-conversions-api'); ?>
                </p>
                
                <h2 style="color: #23282d; font-size: 20px; margin-top: 30px; margin-bottom: 15px; border-top: 1px solid #ddd; padding-top: 20px;">
                    <?php esc_html_e('Failure Summary', 'meta-conversions-api'); ?>
                </h2>
                
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; background-color: #f9f9f9; border-radius: 4px; overflow: hidden;">
                    <thead>
                        <tr style="background-color: #23282d; color: #fff;">
                            <th style="padding: 12px; text-align: left; font-weight: 600;"><?php esc_html_e('Event Type', 'meta-conversions-api'); ?></th>
                            <th style="padding: 12px; text-align: center; font-weight: 600;"><?php esc_html_e('Failures', 'meta-conversions-api'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($event_summary)): ?>
                            <?php foreach ($event_summary as $event_type => $count): ?>
                                <tr style="border-bottom: 1px solid #ddd;">
                                    <td style="padding: 10px 12px;"><?php echo esc_html($event_type); ?></td>
                                    <td style="padding: 10px 12px; text-align: center; font-weight: 600; color: #d63638;"><?php echo esc_html($count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2" style="padding: 12px; text-align: center; color: #646970;">
                                    <?php esc_html_e('No event type information available', 'meta-conversions-api'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if (!empty($recent_errors)): ?>
                    <h2 style="color: #23282d; font-size: 20px; margin-top: 30px; margin-bottom: 15px; border-top: 1px solid #ddd; padding-top: 20px;">
                        <?php esc_html_e('Recent Error Details', 'meta-conversions-api'); ?>
                    </h2>
                    
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; background-color: #f9f9f9; border-radius: 4px; overflow: hidden;">
                        <thead>
                            <tr style="background-color: #23282d; color: #fff;">
                                <th style="padding: 12px; text-align: left; font-weight: 600;"><?php esc_html_e('Error Message', 'meta-conversions-api'); ?></th>
                                <?php if (!empty(array_filter(array_column($recent_errors, 'response_code')))): ?>
                                    <th style="padding: 12px; text-align: center; font-weight: 600;"><?php esc_html_e('Response Code', 'meta-conversions-api'); ?></th>
                                <?php endif; ?>
                                <th style="padding: 12px; text-align: center; font-weight: 600;"><?php esc_html_e('Occurrences', 'meta-conversions-api'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_errors as $error_data): ?>
                                <tr style="border-bottom: 1px solid #ddd;">
                                    <td style="padding: 10px 12px; word-break: break-word;"><?php echo esc_html($error_data['error']); ?></td>
                                    <?php if (!empty(array_filter(array_column($recent_errors, 'response_code')))): ?>
                                        <td style="padding: 10px 12px; text-align: center;">
                                            <?php echo $error_data['response_code'] > 0 ? esc_html($error_data['response_code']) : '—'; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td style="padding: 10px 12px; text-align: center; font-weight: 600;"><?php echo esc_html($error_data['count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <h2 style="color: #23282d; font-size: 20px; margin-top: 30px; margin-bottom: 15px; border-top: 1px solid #ddd; padding-top: 20px;">
                    <?php esc_html_e('Troubleshooting Steps', 'meta-conversions-api'); ?>
                </h2>
                
                <ol style="padding-left: 20px; line-height: 1.8;">
                    <li>
                        <strong><?php esc_html_e('Verify Credentials', 'meta-conversions-api'); ?></strong><br>
                        <?php esc_html_e('Check that your Facebook Dataset ID (Pixel ID) and Access Token are correct in the plugin settings.', 'meta-conversions-api'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Check Access Token Expiration', 'meta-conversions-api'); ?></strong><br>
                        <?php esc_html_e('Access tokens can expire. Generate a new one in Facebook Events Manager if needed.', 'meta-conversions-api'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Verify Facebook Business Manager', 'meta-conversions-api'); ?></strong><br>
                        <?php esc_html_e('Ensure your Facebook Business Manager account is active and has proper permissions.', 'meta-conversions-api'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Review Debug Logs', 'meta-conversions-api'); ?></strong><br>
                        <?php
                        printf(
                            /* translators: %s: Troubleshooting URL */
                            esc_html__('Enable debug logging and review detailed error information in the %s section.', 'meta-conversions-api'),
                            '<a href="' . esc_url($troubleshooting_url) . '">' . esc_html__('Tools & Logs', 'meta-conversions-api') . '</a>'
                        );
                        ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Check Server Connectivity', 'meta-conversions-api'); ?></strong><br>
                        <?php esc_html_e('Ensure your server can reach Facebook\'s API endpoints (graph.facebook.com). Some hosting providers may block external API calls.', 'meta-conversions-api'); ?>
                    </li>
                </ol>
                
                <div style="background-color: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px; margin: 25px 0; border-radius: 4px;">
                    <p style="margin: 0; font-size: 14px;">
                        <strong><?php esc_html_e('Quick Links:', 'meta-conversions-api'); ?></strong><br>
                        <a href="<?php echo esc_url($settings_url); ?>" style="color: #2271b1; text-decoration: none;"><?php esc_html_e('Plugin Settings', 'meta-conversions-api'); ?></a> | 
                        <a href="<?php echo esc_url($troubleshooting_url); ?>" style="color: #2271b1; text-decoration: none;"><?php esc_html_e('Troubleshooting Guide', 'meta-conversions-api'); ?></a> | 
                        <a href="https://developers.facebook.com/docs/marketing-api/conversions-api" target="_blank" style="color: #2271b1; text-decoration: none;"><?php esc_html_e('Facebook Documentation', 'meta-conversions-api'); ?></a> | 
                        <a href="https://developers.facebook.com/docs/meta-pixel" target="_blank" style="color: #2271b1; text-decoration: none;"><?php esc_html_e('Meta Pixel Docs', 'meta-conversions-api'); ?></a>
                    </p>
                </div>
                
                <p style="font-size: 14px; color: #646970; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <?php
                    printf(
                        /* translators: %s: Site name */
                        esc_html__('This notification will not be sent again for 24 hours. If issues persist, please review your plugin settings and check the troubleshooting guide.', 'meta-conversions-api')
                    );
                    ?>
                </p>
                
                <p style="font-size: 12px; color: #8c8f94; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                    <?php esc_html_e('Meta Pixel & Conversions API Plugin by WP Booster', 'meta-conversions-api'); ?><br>
                    <?php echo esc_html($domain); ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

