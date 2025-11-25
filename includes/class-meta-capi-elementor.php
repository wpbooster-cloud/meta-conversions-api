<?php
/**
 * Elementor Pro form integration for Meta Conversions API.
 *
 * @package Meta_Conversions_API
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta_CAPI_Elementor class.
 */
class Meta_CAPI_Elementor {
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
     * Constructor.
     *
     * @param Meta_CAPI_Client $client Client instance.
     * @param Meta_CAPI_Logger $logger Logger instance.
     */
    public function __construct(Meta_CAPI_Client $client, Meta_CAPI_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;

        // Hook into Elementor Pro forms if enabled.
        if (get_option('meta_capi_enable_form_tracking', true)) {
            add_action('elementor_pro/forms/new_record', [$this, 'track_form_submission'], 10, 2);
        }

        // Add action for manual form tracking.
        add_action('meta_capi_track_lead', [$this, 'track_lead_event'], 10, 2);
    }

    /**
     * Track Elementor Pro form submission.
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Form record.
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Ajax handler.
     */
    public function track_form_submission($record, $ajax_handler): void {
        // Check if Elementor Pro is available.
        if (!did_action('elementor_pro/init')) {
            return;
        }

        $form_name = $record->get_form_settings('form_name');
        $form_id = $record->get_form_settings('id');
        $raw_fields = $record->get('fields');
        
        // CRITICAL: Check if current page is excluded FIRST (before any processing).
        // During AJAX requests, get_queried_object_id() returns 0, so we need to get page ID from referrer.
        $excluded_pages_str = get_option('meta_capi_exclude_pages', '');
        $current_page_id = $this->get_current_page_id();
        $is_page_excluded = false;
        if (!empty($excluded_pages_str) && $current_page_id > 0) {
            $excluded_pages = array_filter(array_map('absint', explode(',', $excluded_pages_str)));
            $is_page_excluded = in_array($current_page_id, $excluded_pages, true);
            if ($is_page_excluded) {
                $this->logger->info('Lead event skipped - page is in exclusion list', [
                    'page_id' => $current_page_id,
                    'form_id' => $form_id,
                    'form_name' => $form_name,
                    'referrer' => isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : 'none',
                ]);
                return; // CRITICAL: Exit early - don't send server event for excluded pages.
            }
        }
        
        // Check if this form is excluded.
        $excluded_forms_str = get_option('meta_capi_exclude_forms', '');
        if (!empty($excluded_forms_str)) {
            // Parse excluded forms (already sanitized with sanitize_key when saved).
            $excluded_forms = array_filter(array_map('trim', explode(',', $excluded_forms_str)));
            
            // Normalize function: ensure consistent matching.
            // We normalize by removing ALL non-alphanumeric characters (spaces, hyphens, underscores, etc.)
            // This ensures "Contact Form", "contact-form", "contact_form" all match "contactform".
            $normalize = function($value) {
                if (empty($value)) {
                    return '';
                }
                
                // Convert to string and ensure we have valid UTF-8.
                $str = (string) $value;
                
                // Remove any BOM or invisible characters.
                $str = preg_replace('/^\xEF\xBB\xBF/', '', $str); // UTF-8 BOM
                $str = trim($str);
                
                // Convert to lowercase using standard strtolower (handles ASCII reliably).
                $normalized = strtolower($str);
                
                // Remove ALL non-alphanumeric characters (spaces, punctuation, everything).
                // This regex should handle all standard ASCII and most Unicode letters/numbers.
                $normalized = preg_replace('/[^a-z0-9]/i', '', $normalized);
                
                return $normalized;
            };
            
            // Normalize form_id and form_name for comparison.
            $normalized_form_id = $normalize($form_id);
            $normalized_form_name = $normalize($form_name);
            
            // Also normalize excluded forms list.
            $normalized_excluded = array_map($normalize, $excluded_forms);
            
            // Check if form_id matches.
            $is_excluded_by_id = in_array($normalized_form_id, $normalized_excluded, true);
            
            // Check if form_name matches.
            $is_excluded_by_name = in_array($normalized_form_name, $normalized_excluded, true);
            
            // Check if form_id matches any excluded form with counter suffix (handles duplicate forms).
            $is_excluded_by_prefix = false;
            foreach ($normalized_excluded as $excluded_id) {
                if (strpos($excluded_id, $normalized_form_id . '_') === 0 || strpos($normalized_form_id, $excluded_id . '_') === 0) {
                    $is_excluded_by_prefix = true;
                    break;
                }
            }
            
            // Check if form is excluded (by ID, name, or prefix match).
            if ($is_excluded_by_id || $is_excluded_by_name || $is_excluded_by_prefix) {
                $this->logger->info('Lead event skipped - form is in exclusion list', [
                    'form_name' => $form_name,
                    'form_id' => $form_id,
                    'match_type' => $is_excluded_by_id ? 'id' : ($is_excluded_by_name ? 'name' : 'prefix'),
                ]);
                return;
            }
        }

        $this->logger->info('Elementor form submitted', [
            'form_name' => $form_name,
            'form_id' => $form_id,
            'field_count' => count($raw_fields),
        ]);

        // Extract field values.
        $fields = [];
        $field_titles = [];
        foreach ($raw_fields as $id => $field) {
            $fields[$id] = [
                'title' => $field['title'] ?? '',
                'value' => $field['value'] ?? '',
                'type' => $field['type'] ?? '',
            ];
            $field_titles[] = $field['title'] ?? '';
        }

        $this->logger->info('Form field titles detected', [
            'fields' => $field_titles,
        ]);

        // CRITICAL: Prepare user data to match what Meta Pixel automatically sends.
        // Meta Pixel automatically sends: IP address, User agent, fbp cookie (Browser id), fbc cookie.
        // For deduplication, server MUST send the same fields.
        // Form fields (email, phone, name) are additional but should not replace the core matching fields.
        $user_data = $this->extract_user_data_from_form($fields);
        
        // CRITICAL: Add the core matching fields that Meta Pixel automatically sends.
        // These are REQUIRED for deduplication - IP, user agent, and fbp/fbc cookies.
        // Without these, Meta cannot match browser and server events.
        $user_data['client_ip_address'] = $this->get_client_ip();
        $user_data['client_user_agent'] = $this->get_user_agent();
        
        // Add Facebook cookies (fbp = Browser id, fbc = Click id).
        // These are critical for deduplication.
        if (!empty($_COOKIE['_fbp'])) {
            $user_data['fbp'] = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        }
        if (!empty($_COOKIE['_fbc'])) {
            $user_data['fbc'] = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        }

        // CRITICAL: Prepare custom data to match browser format exactly for deduplication.
        // Browser sends: content_name, content_category, source
        // Server must match these parameters for Meta's deduplication to work.
        $custom_data = [
            'content_name' => sanitize_text_field($form_name), // Match browser format
            'content_category' => 'lead', // Match browser format
            'source' => 'elementor_form', // Match browser format
            // Also include form_id and form_name for additional context (won't break deduplication).
            'form_id' => $form_id,
            'form_name' => $form_name,
        ];

        // Add form field values to custom data (non-PII only).
        foreach ($fields as $id => $field) {
            $field_title = strtolower(str_replace(' ', '_', $field['title']));
            
            // Skip PII fields (already in user_data).
            if (in_array($field_title, ['email', 'phone', 'first_name', 'last_name', 'name'])) {
                continue;
            }

            $custom_data[$field_title] = $field['value'];
        }

        // Generate event ID BEFORE creating event data - use consistent format for deduplication.
        // Format: lead_[form_id]_[timestamp]_[random] - this must match exactly between browser and server.
        // CRITICAL: Generate timestamp once and use it for both event_id and event_time to ensure exact match.
        $timestamp = time();
        $random = wp_generate_password(12, false);
        $event_id = 'lead_' . sanitize_key($form_id) . '_' . $timestamp . '_' . $random;

        // Prepare event data.
        // CRITICAL: Use timestamp from event_id for event_time to ensure exact match with browser.
        // The browser extracts this same timestamp from the event ID, so they must match.
        $event_data = [
            'event_name' => 'Lead',
            'event_time' => $timestamp, // Use timestamp from event ID (already in seconds)
            'event_id' => $event_id, // Set event_id before filters
            'action_source' => 'website',
            'event_source_url' => $this->get_current_url(),
            'user_data' => $user_data,
            'custom_data' => $custom_data,
        ];

        // Log the data being sent (before hashing).
        $this->logger->info('Preparing Lead event data', [
            'user_data_fields' => array_keys($user_data),
            'has_email' => isset($user_data['email']),
            'has_phone' => isset($user_data['phone']),
            'has_name' => isset($user_data['first_name']) || isset($user_data['last_name']),
            'custom_data_fields' => array_keys($custom_data),
            'event_id' => $event_id,
            'event_time' => $timestamp,
        ]);

        // Allow filtering event data before sending.
        $event_data = apply_filters('meta_capi_form_submission_event_data', $event_data, $record, $fields);

        // Allow filtering by form ID.
        $event_data = apply_filters("meta_capi_form_submission_event_data_{$form_id}", $event_data, $record, $fields);
        
        // CRITICAL: Extract timestamp from event_id to ensure exact match with browser Pixel timing.
        // Event ID format: lead_{form_id}_{timestamp}_{random} where timestamp is in seconds.
        // We use this timestamp for event_time to ensure perfect alignment with browser.
        // The browser extracts this same timestamp from the event ID.
        $event_data['event_time'] = $timestamp; // Use timestamp from event ID (already in seconds)

        // Send the event via Conversions API (server-side).
        // NOTE: This works independently of browser pixel injection.
        // Server-side events will appear in Facebook, but may not show prominently
        // in the "Test Events" tab (which focuses on browser pixel events).
        $result = $this->client->send_event($event_data);

        if ($result['success']) {
            $this->logger->info('Lead event sent successfully (server-side)', [
                'form_name' => $form_name,
                'event_id' => $event_id,
            ]);

            // Fire browser-side Lead event for Facebook Test Events visibility.
            // BUT: Skip if form is excluded OR page is excluded.
            // Only pass browser-side tracking if NOT excluded.
            if (!$is_page_excluded && method_exists($ajax_handler, 'add_response_data')) {
                // Pass event data as JSON object for JavaScript to read.
                $tracking_data = [
                    'event_id' => $event_id,
                    'form_id' => $form_id,
                    'form_name' => $form_name,
                    'lead_params' => [
                        'content_name' => sanitize_text_field($form_name),
                        'content_category' => 'lead',
                        'source' => 'elementor_form'
                    ],
                ];
                
                // Add to AJAX response - JavaScript will read this and fire the Lead event.
                // We only pass the data object; JavaScript handles the actual firing to prevent duplicates.
                $ajax_handler->add_response_data('meta_capi_lead_tracking', $tracking_data);

                // Log that we attempted browser-side tracking.
                $this->logger->info('Browser-side Lead event queued', [
                    'form_id' => $form_id,
                    'event_id' => $event_id,
                    'method' => 'elementor_ajax',
                ]);
            } else {
                // Log why browser-side tracking was skipped.
                $reason = $is_page_excluded ? 'page excluded' : 'ajax handler method not available';
                $this->logger->info('Browser-side Lead event skipped', [
                    'form_id' => $form_id,
                    'reason' => $reason,
                ]);
            }
        } else {
            $this->logger->error('Failed to send lead event', [
                'form_name' => $form_name,
                'error' => $result['message'],
            ]);
        }
    }

    /**
     * Extract user data from form fields.
     *
     * @param array $fields Form fields.
     * @return array User data.
     */
    private function extract_user_data_from_form(array $fields): array {
        $user_data = [];

        // Map common field names to user data fields.
        $field_mapping = [
            'email' => 'email',
            'e-mail' => 'email',
            'email_address' => 'email',
            'your_email' => 'email',
            
            'phone' => 'phone',
            'telephone' => 'phone',
            'phone_number' => 'phone',
            'tel' => 'phone',
            
            'first_name' => 'first_name',
            'firstname' => 'first_name',
            'fname' => 'first_name',
            
            'last_name' => 'last_name',
            'lastname' => 'last_name',
            'lname' => 'last_name',
            'surname' => 'last_name',
            
            'name' => 'full_name',
            'full_name' => 'full_name',
            'your_name' => 'full_name',
            
            'city' => 'city',
            'town' => 'city',
            
            'state' => 'state',
            'province' => 'state',
            
            'zip' => 'zip',
            'zipcode' => 'zip',
            'zip_code' => 'zip',
            'postal_code' => 'zip',
            'postcode' => 'zip',
            
            'country' => 'country',
        ];

        foreach ($fields as $id => $field) {
            $field_title = strtolower(str_replace([' ', '-'], '_', $field['title']));
            $field_value = $field['value'];

            // Skip empty values.
            if (empty($field_value)) {
                continue;
            }

            // Check if field matches any mapping.
            if (isset($field_mapping[$field_title])) {
                $mapped_field = $field_mapping[$field_title];
                
                // Handle full name splitting.
                if ($mapped_field === 'full_name') {
                    $name_parts = explode(' ', $field_value, 2);
                    $user_data['first_name'] = $name_parts[0];
                    if (isset($name_parts[1])) {
                        $user_data['last_name'] = $name_parts[1];
                    }
                } else {
                    $user_data[$mapped_field] = $field_value;
                }
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
     * 
     * Security: Validates IP address and handles proxy headers.
     *
     * @return string Client IP address.
     */
    private function get_client_ip(): string {
        $ip = '';
        
        // Check headers in order of preference (most reliable first).
        $headers = [
            'HTTP_CF_CONNECTING_IP',    // Cloudflare (most reliable).
            'HTTP_X_REAL_IP',           // Nginx proxy.
            'HTTP_X_FORWARDED_FOR',     // Standard proxy header (may contain multiple IPs).
            'HTTP_CLIENT_IP',            // Some proxies set this.
            'REMOTE_ADDR',               // Fallback (proxy IP, not client IP).
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                
                // If X-Forwarded-For, take the first IP.
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                break;
            }
        }

        // Validate IP address.
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '';
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
     * Get current page ID.
     * Works during AJAX requests by extracting page ID from HTTP_REFERER.
     *
     * @return int Page ID, or 0 if not found.
     */
    private function get_current_page_id(): int {
        // First, try get_queried_object_id() (works for normal page loads).
        $page_id = get_queried_object_id();
        if ($page_id > 0) {
            return $page_id;
        }

        // During AJAX requests, get_queried_object_id() returns 0.
        // Extract page ID from HTTP_REFERER URL.
        if (isset($_SERVER['HTTP_REFERER'])) {
            $referrer = sanitize_url(wp_unslash($_SERVER['HTTP_REFERER']));
            
            // Try to extract page ID from URL (e.g., /page-slug/ or ?p=123).
            // Method 1: Check for ?p= parameter.
            $parsed_url = parse_url($referrer);
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
                if (isset($query_params['p']) && is_numeric($query_params['p'])) {
                    return absint($query_params['p']);
                }
            }

            // Method 2: Try to get page ID from URL path using url_to_postid().
            $page_id = url_to_postid($referrer);
            if ($page_id > 0) {
                return $page_id;
            }

            // Method 3: Extract from permalink structure (e.g., /page-slug/).
            // This is a fallback - url_to_postid() should handle most cases.
            $path = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';
            if (!empty($path)) {
                // Try to match against known page slugs.
                $page = get_page_by_path($path);
                if ($page) {
                    return $page->ID;
                }
            }
        }

        return 0;
    }

    /**
     * Get current URL.
     *
     * @return string Current URL.
     */
    private function get_current_url(): string {
        if (isset($_SERVER['HTTP_REFERER'])) {
            return sanitize_text_field($_SERVER['HTTP_REFERER']);
        }

        global $wp;
        return home_url(add_query_arg([], $wp->request));
    }

    /**
     * Track a lead event manually.
     *
     * @param array $user_data User data.
     * @param array $custom_data Custom data.
     * @return array Response from the API.
     */
    public function track_lead_event(array $user_data, array $custom_data = []): array {
        $event_data = [
            'event_name' => 'Lead',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => $this->get_current_url(),
            'user_data' => $user_data,
            'custom_data' => $custom_data,
        ];

        return $this->client->send_event($event_data);
    }
}

