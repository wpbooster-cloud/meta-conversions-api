<?php
/**
 * Logger for Meta Conversions API.
 *
 * @package Meta_Conversions_API
 */

declare(strict_types=1);

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta_CAPI_Logger class.
 */
class Meta_CAPI_Logger {
    /**
     * Log file path.
     *
     * @var string
     */
    private string $log_file;

    /**
     * Maximum log file size in bytes (10MB).
     *
     * @var int
     */
    private const MAX_LOG_SIZE = 10485760; // 10MB

    /**
     * Constructor.
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/meta-capi-logs';
        
        // Create log directory if it doesn't exist.
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Add .htaccess to protect log files.
            $htaccess_file = $log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, 'Deny from all');
            }
        }

        $this->log_file = $log_dir . '/meta-capi-' . gmdate('Y-m-d') . '.log';
    }

    /**
     * Log a message.
     *
     * @param string $message Log message.
     * @param string $level   Log level (info, warning, error).
     * @param array  $context Additional context data.
     */
    public function log(string $message, string $level = 'info', array $context = []): void {
        // Only log if logging is enabled.
        if (!get_option('meta_capi_enable_logging', false)) {
            return;
        }

        // Check file size and rotate if needed.
        $this->rotate_if_needed();

        $timestamp = gmdate('Y-m-d H:i:s');
        $level = strtoupper($level);
        
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            $timestamp,
            $level,
            $message
        );

        if (!empty($context)) {
            $log_entry .= "Context: " . wp_json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }

        $log_entry .= "---\n";

        // Write to log file.
        error_log($log_entry, 3, $this->log_file);

        // Also log to WordPress debug log if WP_DEBUG_LOG is enabled.
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("Meta CAPI [{$level}]: {$message}");
        }
    }

    /**
     * Rotate log file if it exceeds max size.
     */
    private function rotate_if_needed(): void {
        if (!file_exists($this->log_file)) {
            return;
        }

        $file_size = filesize($this->log_file);
        
        if ($file_size >= self::MAX_LOG_SIZE) {
            // Rename current file with timestamp.
            $rotated_file = str_replace('.log', '-' . time() . '.log', $this->log_file);
            rename($this->log_file, $rotated_file);
            
            // Clean up old rotated files (keep only 3 most recent).
            $this->cleanup_rotated_logs();
        }
    }

    /**
     * Clean up old rotated log files.
     */
    private function cleanup_rotated_logs(): void {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/meta-capi-logs';
        
        $today = gmdate('Y-m-d');
        $pattern = $log_dir . '/meta-capi-' . $today . '-*.log';
        $files = glob($pattern);
        
        if (count($files) > 3) {
            // Sort by modification time, oldest first.
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Delete oldest files, keeping only 3 most recent.
            $to_delete = array_slice($files, 0, -3);
            foreach ($to_delete as $file) {
                wp_delete_file($file);
            }
        }
    }

    /**
     * Log an info message.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function info(string $message, array $context = []): void {
        $this->log($message, 'info', $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function warning(string $message, array $context = []): void {
        $this->log($message, 'warning', $context);
    }

    /**
     * Log an error message.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function error(string $message, array $context = []): void {
        $this->log($message, 'error', $context);
    }

    /**
     * Clear old log files.
     *
     * @param int $days Number of days to keep logs.
     */
    public function clear_old_logs(int $days = 30): void {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/meta-capi-logs';

        if (!is_dir($log_dir)) {
            return;
        }

        $files = glob($log_dir . '/meta-capi-*.log');
        $cutoff_time = time() - ($days * DAY_IN_SECONDS);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                wp_delete_file($file);
            }
        }
    }
}

