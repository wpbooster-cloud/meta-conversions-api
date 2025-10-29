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
            'timeout' => 30,
        ];

        // Add access token to URL.
        $url = add_query_arg('access_token', $this->access_token, $url);

        $this->logger->info('Sending event to Facebook Conversions API', [
            'event_name' => $event_data['event_name'] ?? 'unknown',
            'url' => preg_replace('/access_token=[^&]+/', 'access_token=***', $url),
        ]);

        // Send the request.
        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error('Failed to send event to Facebook', [
                'error' => $error_message,
            ]);

            // Track failure for admin notification
            $this->track_api_failure($error_message);

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_response = json_decode($response_body, true);

        if ($response_code >= 200 && $response_code < 300) {
            $this->logger->info('Event sent successfully', [
                'response' => $decoded_response,
            ]);

            return [
                'success' => true,
                'message' => __('Event sent successfully.', 'meta-conversions-api'),
                'response' => $decoded_response,
            ];
        } else {
            $error_message = $decoded_response['error']['message'] ?? __('Unknown error', 'meta-conversions-api');
            
            $this->logger->error('Failed to send event', [
                'response_code' => $response_code,
                'error' => $decoded_response,
            ]);

            // Track failure for admin notification
            $this->track_api_failure($error_message);

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

        // Get client IP address.
        $prepared['client_ip_address'] = $user_data['client_ip_address'] ?? $this->get_client_ip();

        // Get user agent.
        $prepared['client_user_agent'] = $user_data['client_user_agent'] ?? $this->get_user_agent();

        // Get Facebook browser ID (fbp cookie).
        if (!empty($user_data['fbp'])) {
            $prepared['fbp'] = $user_data['fbp'];
        } elseif (!empty($_COOKIE['_fbp'])) {
            $prepared['fbp'] = sanitize_text_field($_COOKIE['_fbp']);
        }

        // Get Facebook click ID (fbc cookie).
        if (!empty($user_data['fbc'])) {
            $prepared['fbc'] = $user_data['fbc'];
        } elseif (!empty($_COOKIE['_fbc'])) {
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
     *
     * @return string Client IP address.
     */
    private function get_client_ip(): string {
        $ip = '';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return sanitize_text_field($ip);
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
     */
    private function track_api_failure(string $error_message): void {
        // Get current failure count (resets after 1 hour)
        $failure_count = get_transient('meta_capi_failure_count');
        $failure_count = $failure_count ? (int) $failure_count + 1 : 1;
        
        // Store failure count
        set_transient('meta_capi_failure_count', $failure_count, HOUR_IN_SECONDS);
        
        // Store last error for reference
        set_transient('meta_capi_last_error', $error_message, DAY_IN_SECONDS);
        
        // Send notification after 5 failures in 1 hour (but max once per day)
        if ($failure_count >= 5 && !get_transient('meta_capi_alert_sent')) {
            $this->send_failure_notification($failure_count, $error_message);
        }
    }

    /**
     * Send email notification to admin about API failures.
     *
     * @param int    $failure_count Number of failures.
     * @param string $last_error Last error message.
     */
    private function send_failure_notification(int $failure_count, string $last_error): void {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $settings_url = admin_url('options-general.php?page=meta-conversions-api');
        
        $subject = sprintf(
            /* translators: %s: Site name */
            __('[%s] Meta Conversions API: Connection Issues Detected', 'meta-conversions-api'),
            $site_name
        );
        
        $message = sprintf(
            /* translators: 1: Failure count, 2: Error message, 3: Settings URL */
            __(
                "Hello,\n\n" .
                "The Meta Conversions API plugin on %1\$s has detected connection issues.\n\n" .
                "Details:\n" .
                "- Failures detected: %2\$d in the last hour\n" .
                "- Last error: %3\$s\n\n" .
                "Recommended actions:\n" .
                "1. Verify your Facebook Dataset ID and Access Token are correct\n" .
                "2. Check that your Access Token hasn't expired\n" .
                "3. Ensure your Facebook Business Manager account is active\n\n" .
                "View settings: %4\$s\n\n" .
                "This notification will not be sent again for 24 hours.\n\n" .
                "---\n" .
                "Meta Conversions API Plugin by WP Booster",
                'meta-conversions-api'
            ),
            $site_name,
            $failure_count,
            $last_error,
            $settings_url
        );
        
        // Send email
        $sent = wp_mail($admin_email, $subject, $message);
        
        if ($sent) {
            // Mark alert as sent (expires in 24 hours)
            set_transient('meta_capi_alert_sent', true, DAY_IN_SECONDS);
            
            // Log notification
            $this->logger->info('Admin notification sent for API failures', [
                'failure_count' => $failure_count,
                'admin_email' => $admin_email,
            ]);
        }
    }
}

