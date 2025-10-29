=== Meta Conversions API ===
Contributors: wpbooster
Tags: facebook, conversions api, meta, elementor, tracking
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Facebook Conversions API to track page views and Elementor Pro form submissions.

== Description ==

Meta Conversions API for WordPress enables server-side event tracking to Facebook (Meta) without relying solely on browser-based pixels. This improves tracking accuracy and helps you measure campaign performance more reliably.

= Features =

* **Page View Tracking** - Automatically sends PageView events to Facebook
* **Elementor Pro Integration** - Tracks form submissions as Lead events
* **Privacy-Compliant** - Properly hashes PII data according to Facebook requirements
* **Event Deduplication** - Generates unique event IDs to prevent duplicate events
* **Debug Logging** - Built-in log viewer with automatic cleanup
* **Test Mode** - Support for Facebook Test Event Code
* **System Status** - Dashboard showing configuration and compatibility

= Requirements =

* WordPress 6.0 or higher
* PHP 8.0 or higher
* Facebook Dataset ID (Pixel ID)
* Facebook Conversions API Access Token
* Elementor Pro (optional, for form tracking)

= Privacy & Data Handling =

The plugin follows Facebook's best practices:

* All PII is hashed using SHA-256 before sending
* IP addresses and user agents sent as required by Facebook
* Facebook cookies (_fbp, _fbc) included for attribution
* Debug logs stored securely with automatic 30-day cleanup

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/meta-conversions-api`
2. Activate the plugin through the 'Plugins' menu
3. Go to Settings → Meta CAPI
4. Enter your Facebook Dataset ID and Access Token
5. Configure tracking settings

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

The Conversions API works independently, but using both the pixel and CAPI together provides the most accurate tracking and enables event deduplication.

= Will this slow down my site? =

No. Events are sent server-side after the page loads, so there's no impact on page load times.

== Screenshots ==

1. Settings page with Facebook credentials
2. System status dashboard
3. Built-in log viewer
4. Documentation tab with setup guide

== Changelog ==

= 1.0.0 =
* Initial release
* Page view tracking
* Elementor Pro form submission tracking
* Admin settings with tabbed navigation
* Debug logging with automatic management
* System status dashboard
* Test event functionality

== Upgrade Notice ==

= 1.0.0 =
Initial release of Meta Conversions API plugin.

== Support ==

For support, please visit https://wpbooster.cloud

== Credits ==

Developed by WP Booster

