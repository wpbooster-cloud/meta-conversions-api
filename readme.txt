=== Meta Pixel & Conversions API ===
Contributors: wpbooster
Tags: facebook, conversions api, meta, pixel, elementor, woocommerce, tracking, ecommerce
Requires at least: 6.0
Tested up to: 6.8.3
Requires PHP: 8.0
Stable tag: 2.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete Meta tracking solution with Pixel (browser-side) and Conversions API (server-side) for accurate event tracking. Supports page views, Elementor Pro forms, and WooCommerce with automatic event deduplication.

== Description ==

Meta Pixel & Conversions API for WordPress provides a complete tracking solution combining browser-side Pixel tracking with server-side Conversions API for maximum accuracy and reliability. This dual-tracking approach ensures events are captured even when browser cookies are blocked, improving campaign measurement and attribution.

= Features =

* **Dual Tracking** - Meta Pixel (browser-side) + Conversions API (server-side) for maximum accuracy
* **WooCommerce Integration** - Full event tracking for ViewContent, AddToCart, InitiateCheckout, and Purchase
* **Purchase Event Timing** - Choose when Purchase events fire: "When order is placed" or "When payment is confirmed"
* **Page View Tracking** - Automatically sends PageView events via both Pixel and CAPI
* **Elementor Pro Integration** - Tracks form submissions as Lead events with automatic deduplication
* **Event Deduplication** - Automatically prevents duplicate events between Pixel and CAPI
* **Tracking Exceptions** - Exclude specific pages or forms from tracking
* **Privacy-Compliant** - Properly hashes PII data according to Facebook requirements
* **Auto-Config Control** - Disable Facebook's automatic event tracking for cleaner data
* **Debug Logging** - Built-in log viewer with automatic cleanup (10MB cap, 30-day retention)
* **Test Mode** - Support for Facebook Test Event Code
* **System Status** - Dashboard showing configuration and compatibility
* **Automatic Updates** - Weekly checks for updates from GitHub releases

= Requirements =

* WordPress 6.0 or higher
* PHP 8.0 or higher
* Facebook Dataset ID (Pixel ID)
* Facebook Conversions API Access Token
* Elementor Pro (optional, for form tracking)
* WooCommerce (optional, for eCommerce tracking)

= Privacy & Data Handling =

The plugin follows Facebook's best practices:

* All PII is hashed using SHA-256 before sending
* IP addresses and user agents sent as required by Facebook
* Facebook cookies (_fbp, _fbc) included for attribution
* Debug logs stored securely with automatic 30-day cleanup

== Installation ==

1. Download the latest release from GitHub Releases
2. Upload the ZIP file via WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings → Meta CAPI
5. Enter your Facebook Dataset ID and Access Token
6. Configure tracking settings

== Frequently Asked Questions ==

= Where do I find my Dataset ID and Access Token? =

1. Go to Facebook Events Manager (https://business.facebook.com/events_manager2)
2. Select your pixel
3. The Dataset ID is displayed at the top (15-16 digit number)
4. For the Access Token, go to Settings → Conversions API
5. Click "Generate Access Token" and copy it

= How do I verify events are being sent? =

1. Use the Test Event Code feature in plugin settings
2. Go to Facebook Events Manager → Test Events tab
3. Events will appear with your test event code
4. You can also check the built-in log viewer in Tools & Logs

= Do I need the Facebook Pixel JavaScript code? =

The plugin automatically injects the Meta Pixel code for browser-side tracking. Using both the pixel and CAPI together provides the most accurate tracking and enables event deduplication.

= Will this slow down my site? =

No. Events are sent server-side after the page loads, and browser-side events are non-blocking, so there's no impact on page load times.

= How do automatic updates work? =

The plugin checks for updates from GitHub releases weekly. When a new version is available, you'll see an update notification in your WordPress admin dashboard. Click "Update" to install automatically.

== Screenshots ==

1. Settings page with Facebook credentials and tracking options
2. WooCommerce event tracking configuration
3. Page and form exclusion settings
4. System status dashboard
5. Built-in log viewer
6. Documentation tab with setup guide

== Changelog ==

= 2.0.3 =
* Fixed changelog formatting for better readability
* Updated tested WordPress version to 6.8.3

= 2.0.2 =

Critical Bug Fixes:
* Fixed PageView eventTime missing in fbq() call - critical deduplication fix
* Fixed Purchase event timestamp mismatch - now uses order creation time for consistency between browser and server
* Fixed AddToCart/InitiateCheckout event_time extraction - server now extracts timestamp from event_id to match browser exactly

UI/UX Improvements:
* Improved error notification settings UI with visual grouping and better organization
* Added inline email validation with visual checkmark indicator
* Added success feedback message for test email notifications
* Fixed test notification form nested inside main form (prevented submission)
* Updated documentation links to point to internal documentation
* Added threshold helper text and help links for better UX

Other Fixes:
* Fixed analytics ping message when analytics are disabled

= 2.0.1 =

* Fixed duplicate Lead events from Elementor forms (browser-side deduplication)
* Fixed form exclusion not working in browser-side tracking
* Fixed page exclusions not working consistently
* Fixed Purchase event timing - When set to "payment confirmed", browser-side Purchase events are now properly skipped on thank-you page
* Improved Elementor form tracking architecture (simplified browser-side logic)
* Cleaned up debug logging from production code
* Simplified browser-side form tracking to rely on server-side exclusion logic

= 2.0.0 =

* Added Meta Pixel integration with automatic injection (can be disabled)
* Added full WooCommerce support (ViewContent, AddToCart, InitiateCheckout, Purchase)
* Added Purchase event timing options (order placed vs payment confirmed)
* Added page exclusions with searchable multi-select interface
* Added form exclusions with searchable multi-select interface
* Added auto-cleanup of deleted forms/pages from exclusion lists
* Added enhanced documentation split into Setup Guide and Troubleshooting tabs
* Improved system status with WooCommerce and Pixel detection
* Improved browser and server-side tracking coordination
* Fixed add to cart 500 error (event ID generation)
* Fixed purchase event hook reliability
* Fixed WooCommerce event defaults not enabling on first activation
* Changed plugin name to "Meta Pixel & Conversions API" to reflect dual tracking capabilities
* Changed PHP requirement to 8.0+ for better type safety and performance

= 1.0.5 =

* Fixed plugin deactivation after automatic update
* Post-update folder handling now uses actual plugin directory name

= 1.0.4 =

* Minor improvements and testing automatic update functionality

= 1.0.3 =

* Fixed automatic updates not displaying
* Updater now uses plugin_basename() to dynamically detect actual plugin path

= 1.0.2 =

* Added automatic updates from GitHub releases
* Added anonymous usage analytics (opt-out available)
* Added admin email notifications for API failures
* Enhanced documentation with GitHub release links

= 1.0.0 =

* Initial release
* Page view tracking via Facebook Conversions API
* Elementor Pro form submission tracking (Lead events)
* Admin settings page with tabbed navigation
* Debug logging with automatic management
* System status dashboard
* Test event functionality

== Upgrade Notice ==

= 2.0.3 =

Minor update - Fixed changelog formatting and updated tested WordPress version to 6.8.3.

= 2.0.2 =

Bug fix and UI improvement release - Fixes critical eventTime bugs affecting deduplication for PageView, Purchase, AddToCart, and InitiateCheckout events. Also includes improved error notification UI and better user experience. Recommended for all users.

= 2.0.1 =

Bug fix release - Fixes critical tracking issues including duplicate Lead events, form/page exclusions, and Purchase event timing. Recommended for all users.

= 2.0.0 =

Major update - Added full WooCommerce support, Meta Pixel integration, and tracking exceptions. PHP 8.0+ required.

= 1.0.0 =

Initial release of Meta Pixel & Conversions API plugin.

== Support ==

For support, please visit https://wpbooster.cloud

== Credits ==

Developed by WP Booster
