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

        // Prepare user data from form fields.
        $user_data = $this->extract_user_data_from_form($fields);

        // Prepare custom data.
        $custom_data = [
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

        // Prepare event data.
        $event_data = [
            'event_name' => 'Lead',
            'event_time' => time(),
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
        ]);

        // Allow filtering event data before sending.
        $event_data = apply_filters('meta_capi_form_submission_event_data', $event_data, $record, $fields);

        // Allow filtering by form ID.
        $event_data = apply_filters("meta_capi_form_submission_event_data_{$form_id}", $event_data, $record, $fields);

        // Send the event.
        $result = $this->client->send_event($event_data);

        if ($result['success']) {
            $this->logger->info('Lead event sent successfully', [
                'form_name' => $form_name,
            ]);
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

