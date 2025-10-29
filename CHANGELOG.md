# Changelog

All notable changes to Meta Conversions API will be documented in this file.

## [1.0.3] - 2025-10-29

### Fixed
- **Fixed automatic updates not displaying** - Plugin now correctly detects folder name regardless of capitalization or spaces
- Updater now uses `plugin_basename()` to dynamically detect actual plugin path

## [1.0.2] - 2025-10-29

### Added
- **Automatic updates from GitHub releases**
  - Plugin checks for updates weekly
  - One-click updates directly from WordPress admin
  - Manual "Check for Updates Now" button in Tools & Logs
  - No manual download required
- **Anonymous usage analytics** (opt-out available)
  - Transparent privacy notice on activation
  - Opt-out checkbox in Settings
  - Detailed explanation of data collected
  - Weekly check-in to wpbooster.cloud
  - Completely anonymous - only site hash, versions, and feature usage
- **Admin email notifications for API failures**
  - Automatic alerts after 5+ API failures within 1 hour
  - Maximum one notification per 24 hours
  - Includes error details and settings link

### Improved
- Enhanced documentation with GitHub release links and update instructions
- Better installation instructions
- Added Plugin Updates section to Documentation tab
- Added anchor links throughout documentation for easy navigation

## [1.0.0] - 2025-10-29

### Added
- Initial release
- Page view tracking via Facebook Conversions API
- Elementor Pro form submission tracking (Lead events)
- Admin settings page with tabbed navigation (Settings, Documentation, Tools & Logs)
- Facebook Dataset ID and Access Token configuration
- Test Event Code support for testing in Facebook Events Manager
- Debug logging with automatic management:
  - 10MB file size cap per log file
  - Automatic log rotation
  - 30-day retention with automatic cleanup via WP Cron
  - Built-in log viewer (no FTP required)
  - Download logs directly from admin
- System status dashboard showing:
  - PHP and WordPress version checks
  - Plugin configuration status
  - Elementor Pro and WooCommerce detection
- Privacy-compliant data handling:
  - SHA-256 hashing of PII data
  - Proper user data collection
  - Facebook cookie integration (_fbp, _fbc)
- Admin notices for:
  - Active debug logging
  - Active test event code
  - Missing credentials
- Automatic event deduplication with unique event IDs
- Security features:
  - .htaccess protection for log files
  - Nonce verification on all forms
  - Proper capability checks
  - Input sanitization and output escaping

### Coming Soon
- WooCommerce integration
- Additional form builder support
- Contact Form 7 integration

