<?php
/**
 * Automatic updates from GitHub releases.
 *
 * @package Meta_Conversions_API
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles automatic plugin updates from GitHub releases.
 */
class Meta_CAPI_Updater {

    private string $plugin_slug;
    private string $plugin_file;
    private string $github_repo;
    private string $version;
    private string $cache_key;
    private bool $cache_allowed;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->plugin_slug = 'meta-conversions-api';
        $this->plugin_file = plugin_basename(META_CAPI_PLUGIN_FILE); // Use actual plugin file path
        $this->github_repo = 'wpbooster-cloud/meta-conversions-api';
        $this->version = META_CAPI_VERSION;
        $this->cache_key = 'meta_capi_update_info';
        $this->cache_allowed = true;

        // Hook into WordPress update checks.
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'check_update']);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);

        // Clear cache when needed.
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);

        // Manual update check via URL parameter or admin action.
        add_action('admin_init', [$this, 'maybe_force_update_check']);
    }

    /**
     * Force update check if requested via URL parameter or manual button.
     */
    public function maybe_force_update_check(): void {
        // Check for URL parameter (for quick testing).
        if (isset($_GET['meta_capi_check_updates']) && 
            sanitize_key(wp_unslash($_GET['meta_capi_check_updates'])) === '1' &&
            current_user_can('update_plugins')) {
            $this->force_update_check();
            wp_safe_redirect(admin_url('plugins.php?meta_capi_update_checked=1'));
            exit;
        }

        // Check for manual button click from Tools page.
        if (isset($_POST['meta_capi_force_update_check']) && 
            check_admin_referer('meta_capi_force_update', 'meta_capi_update_nonce') &&
            current_user_can('manage_options')) {
            
            $this->force_update_check();
            
            // Redirect with success message.
            wp_safe_redirect(add_query_arg([
                'page' => 'meta-conversions-api',
                'tab' => 'tools',
                'update_checked' => '1'
            ], admin_url('options-general.php')));
            exit;
        }
    }

    /**
     * Force an immediate update check by clearing cache.
     */
    public function force_update_check(): void {
        // Clear our cache.
        delete_transient($this->cache_key);
        
        // Clear WordPress update cache.
        delete_site_transient('update_plugins');
        
        // Force WordPress to check for updates.
        wp_update_plugins();
    }

    /**
     * Check for plugin updates.
     *
     * @param object $transient Update transient object.
     * @return object Modified transient.
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get latest release info from GitHub.
        $release_info = $this->get_release_info();

        if ($release_info === false) {
            return $transient;
        }

        // Compare versions.
        if (version_compare($this->version, $release_info->version, '<')) {
            $plugin_data = [
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_file,
                'new_version' => $release_info->version,
                'url' => $release_info->homepage,
                'package' => $release_info->download_url,
                'icons' => [],
                'banners' => [],
                'tested' => $release_info->tested,
                'requires_php' => $release_info->requires_php,
            ];

            $transient->response[$this->plugin_file] = (object) $plugin_data;
        }

        return $transient;
    }

    /**
     * Get plugin information for the "View details" popup.
     *
     * @param false|object|array $result The result object or array.
     * @param string $action The type of information being requested.
     * @param object $args Plugin API arguments.
     * @return false|object Plugin info or false.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if ($args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release_info = $this->get_release_info();

        if ($release_info === false) {
            return $result;
        }

        $plugin_info = [
            'name' => $release_info->name,
            'slug' => $this->plugin_slug,
            'version' => $release_info->version,
            'author' => '<a href="https://wpbooster.cloud">WP Booster</a>',
            'homepage' => $release_info->homepage,
            'requires' => $release_info->requires,
            'tested' => $release_info->tested,
            'requires_php' => $release_info->requires_php,
            'downloaded' => 0,
            'last_updated' => $release_info->last_updated,
            'sections' => [
                'description' => $release_info->description,
                'changelog' => $release_info->changelog,
            ],
            'download_link' => $release_info->download_url,
        ];

        return (object) $plugin_info;
    }

    /**
     * Get latest release information from GitHub.
     *
     * @return false|object Release info or false on failure.
     */
    private function get_release_info() {
        // Check cache first (7 days).
        if ($this->cache_allowed) {
            $cache = get_transient($this->cache_key);
            if ($cache !== false) {
                return $cache;
            }
        }

        // Fetch latest release from GitHub API.
        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->github_repo}/releases/latest",
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            // Log error for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Meta CAPI Update Check Error: ' . $response->get_error_message());
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $release = json_decode($body);

        if (empty($release) || isset($release->message)) {
            // Log API response for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Meta CAPI Update Check: Invalid GitHub response - ' . print_r($release, true));
            }
            return false;
        }
        
        // Debug log successful fetch
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Meta CAPI Update Check: Found version ' . $release->tag_name . ' (current: ' . $this->version . ')');
        }

        // Parse version from tag (remove 'v' prefix).
        $version = ltrim($release->tag_name, 'v');

        // Find the ZIP download URL.
        $download_url = $release->zipball_url;
        foreach ($release->assets as $asset) {
            if (strpos($asset->name, '.zip') !== false) {
                $download_url = $asset->browser_download_url;
                break;
            }
        }

        // Build release info object.
        $release_info = (object) [
            'name' => 'Meta Conversions API',
            'version' => $version,
            'homepage' => "https://github.com/{$this->github_repo}",
            'download_url' => $download_url,
            'requires' => '6.0',
            'tested' => '6.7',
            'requires_php' => '8.0',
            'last_updated' => $release->published_at,
            'description' => 'WordPress plugin for Facebook Conversions API tracking with Elementor Pro integration.',
            'changelog' => $this->parse_changelog($release->body),
        ];

        // Cache for 7 days.
        set_transient($this->cache_key, $release_info, WEEK_IN_SECONDS);

        return $release_info;
    }

    /**
     * Parse markdown changelog to HTML.
     *
     * @param string $markdown Markdown content.
     * @return string HTML content.
     */
    private function parse_changelog(string $markdown): string {
        // Simple markdown to HTML conversion.
        $html = $markdown;

        // Headers.
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);

        // Lists.
        $html = preg_replace('/^[\*\-] (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);

        // Checkmarks.
        $html = str_replace('✅', '✅', $html);

        // Line breaks.
        $html = nl2br($html);

        return $html;
    }

    /**
     * Post-install hook to handle folder renaming.
     *
     * @param bool $response Install response.
     * @param array $hook_extra Extra hook data.
     * @param array $result Install result.
     * @return bool Install response.
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Check if this is our plugin.
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $response;
        }

        // Move files to correct directory.
        $proper_destination = WP_PLUGIN_DIR . '/' . $this->plugin_slug;
        $wp_filesystem->move($result['destination'], $proper_destination);
        $result['destination'] = $proper_destination;

        // Reactivate the plugin.
        if ($hook_extra['action'] === 'update') {
            activate_plugin($this->plugin_file);
        }

        return $response;
    }

    /**
     * Clear update cache after plugin update.
     *
     * @param object $upgrader Upgrader object.
     * @param array $options Update options.
     */
    public function clear_cache($upgrader, $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient($this->cache_key);
        }
    }
}

