# Changelog

All notable changes to Meta Pixel & Conversions API will be documented in this file.

## [2.0.0] - 2025-10-31

### Added

#### Meta Pixel Integration
- Automatic pixel injection for browser-side tracking (can be disabled)
- Coordinated event tracking with Conversions API
- Automatic event deduplication between browser and server
- Option to disable Facebook's auto-config for cleaner data

#### Full WooCommerce Support
Track complete eCommerce journey with granular control:
- **ViewContent** - Product page views
- **AddToCart** - Items added to cart (supports simple and variable products)
- **InitiateCheckout** - Checkout page visits
- **Purchase** - Order completion with configurable timing
- Individual event toggles for each event type
- Purchase event timing: "When order is placed" or "When payment is confirmed"

#### Tracking Exceptions
- **Page Exclusions** - Searchable multi-select interface to exclude specific pages from tracking
- **Form Exclusions** - Searchable multi-select interface to exclude specific Elementor forms from tracking
- Auto-cleanup of deleted/unpublished forms from exclusion list
- Auto-removal of missing pages from exclusion list

#### Enhanced Documentation
- Split into **Setup Guide** and **Troubleshooting** tabs for better organization
- Comprehensive WooCommerce setup instructions
- Better navigation and user experience

### Improved
- Enhanced system status with WooCommerce and Pixel detection
- Collapsible recommendations panel in settings
- Dual Pixel + CAPI coverage for maximum tracking accuracy
- Excluded favicon and non-page requests from PageView tracking
- Added fallback purchase hook for better payment gateway compatibility
- Auto-enabled WooCommerce event defaults on first activation
- More granular control over what events to track
- Admin orders automatically skipped to prevent data pollution
- Simplified browser-side tracking architecture

### Changed
- Plugin renamed to "Meta Pixel & Conversions API" to reflect dual tracking capabilities
- PHP requirement increased to 8.0+ for better type safety and performance
- Test Event Code moved to main credentials section for better visibility
- Error Notifications section renamed from "Testing" for clarity

### Fixed
- Fixed add to cart 500 error (event ID generation)
- Fixed purchase event hook reliability
- Fixed WooCommerce event defaults not enabling on first activation
- Fixed duplicate Lead events from Elementor forms
- Fixed form exclusion not working in browser-side tracking
- Fixed page exclusions not working consistently
- Fixed Purchase event timing "payment confirmed" sending browser-side events on thank-you page

## [1.0.5] - 2025-10-29

### Fixed
- **Fixed plugin deactivation after automatic update** - Updater now correctly preserves folder name and reactivates plugin
- Post-update folder handling now uses actual plugin directory name

## [1.0.4] - 2025-10-29

### Changed
- Minor improvements and testing automatic update functionality

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

### Future Plans
- Additional form builder support (Contact Form 7, Gravity Forms, etc.)
- Advanced matching parameters
- Custom audience sync

