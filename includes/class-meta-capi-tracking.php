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
     * Constructor.
     *
     * @param Meta_CAPI_Client $client Client instance.
     * @param Meta_CAPI_Logger $logger Logger instance.
     */
    public function __construct(Meta_CAPI_Client $client, Meta_CAPI_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;

        // Hook into WordPress to track page views.
        if (get_option('meta_capi_enable_page_view', true)) {
            add_action('wp', [$this, 'track_page_view'], 10);
        }
    }

    /**
     * Track page view event.
     */
    public function track_page_view(): void {
        // Don't track admin pages or AJAX requests.
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        // Don't track if user is logged in and is an admin (optional).
        if (current_user_can('manage_options') && apply_filters('meta_capi_skip_admin_tracking', true)) {
            return;
        }

        // Allow filtering to skip tracking on specific pages.
        if (apply_filters('meta_capi_skip_page_view', false)) {
            return;
        }

        // Prepare event data.
        $event_data = [
            'event_name' => 'PageView',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => $this->get_current_url(),
            'user_data' => $this->get_user_data(),
            'custom_data' => $this->get_page_custom_data(),
        ];

        // Allow filtering event data before sending.
        $event_data = apply_filters('meta_capi_page_view_event_data', $event_data);

        // Send the event asynchronously to avoid blocking page load.
        $this->send_event_async($event_data);
    }

    /**
     * Get user data for the current visitor.
     *
     * @return array User data.
     */
    private function get_user_data(): array {
        $user_data = [];

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
     * Send event asynchronously using WordPress HTTP API.
     *
     * @param array $event_data Event data.
     */
    private function send_event_async(array $event_data): void {
        // For async processing, we'll use wp_schedule_single_event
        // or send immediately (you can switch to wp_cron for true async).
        
        // For immediate sending (blocking):
        $this->client->send_event($event_data);

        // For async sending (non-blocking), uncomment below and comment above:
        // wp_schedule_single_event(time(), 'meta_capi_send_event', [$event_data]);
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
    $logger = new Meta_CAPI_Logger();
    $client = new Meta_CAPI_Client($logger);
    $client->send_event($event_data);
});

