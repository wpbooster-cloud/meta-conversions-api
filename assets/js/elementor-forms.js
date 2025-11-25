/**
 * Meta CAPI Elementor Forms - Browser Side Tracking
 * 
 * Handles browser-side tracking for Elementor Pro form submissions:
 * - Lead events (form submissions)
 * 
 * Coordinates with server-side CAPI using event IDs for deduplication.
 * 
 * @package Meta_Conversions_API
 * @since 2.0.0
 */

(function($) {
    'use strict';

        // Track fired event IDs to prevent duplicates
        const firedEvents = new Set();

        // Wait for DOM and Meta Pixel to be ready
        $(document).ready(function() {
            /**
             * Track Lead event when Elementor form is submitted successfully
             * Elementor Pro uses AJAX form submissions and fires success events
             * 
             * IMPORTANT: We ONLY fire browser-side events if the server passes tracking data.
             * If a form is excluded server-side, no tracking data is passed, so no browser event fires.
             */
            
        // Method 1: Listen for Elementor Pro's specific form success event
        // Elementor Pro fires 'elementor/popup/before_open' and form success events
        $(document).on('submit_success', function(event, response) {
            // Check if this response contains our tracking data
            if (response && response.data && response.data.meta_capi_lead_tracking) {
                const trackingData = response.data.meta_capi_lead_tracking;
                if (typeof trackingData === 'object' && trackingData.event_id) {
                    fireLeadEvent(trackingData);
                }
            }
        });
        
        // Method 2: Listen for Elementor AJAX success response (fallback)
        // This catches Elementor Pro form submissions via admin-ajax.php
            $(document).on('ajaxComplete', function(event, xhr, settings) {
            // Check if this is an Elementor form submission
            if (settings.url && (settings.url.indexOf('elementor-pro') !== -1 || settings.url.indexOf('admin-ajax.php') !== -1) && settings.type === 'POST') {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                    // ONLY fire if server explicitly passed tracking data (form not excluded)
                        if (response.success && response.data && response.data.meta_capi_lead_tracking) {
                        const trackingData = response.data.meta_capi_lead_tracking;
                        
                        // If it's an object with event data, fire it
                        if (typeof trackingData === 'object' && trackingData.event_id) {
                            fireLeadEvent(trackingData);
                        }
                    }
                } catch (e) {
                    // Silently ignore - not an Elementor form or parse error
                    }
                }
            });
        
        // Method 3: Listen for Elementor form success via custom event (if Elementor fires it)
        // Some Elementor versions fire 'elementor/form/success' event
        $(document).on('elementor/form/success', function(event, formId, response) {
            if (response && response.data && response.data.meta_capi_lead_tracking) {
                const trackingData = response.data.meta_capi_lead_tracking;
                if (typeof trackingData === 'object' && trackingData.event_id) {
                    fireLeadEvent(trackingData);
                }
            }
            });
        });

        /**
         * Fire Lead event to Facebook Pixel
         * @param {Object} data Event data with event_id, form_id, form_name, and optional lead_params
         */
        function fireLeadEvent(data) {
            if (typeof fbq === 'undefined') {
                return;
            }

            const eventId = data.event_id || 'unknown';
            
            // Prevent duplicate firing of the same event ID
            if (firedEvents.has(eventId)) {
                return;
            }

            // Mark this event as fired
            firedEvents.add(eventId);
            
            // Clean up old event IDs (keep only last 100)
            if (firedEvents.size > 100) {
                const firstEvent = firedEvents.values().next().value;
                firedEvents.delete(firstEvent);
            }

            const formName = data.form_name || 'Form Submission';
            
            // Use provided lead_params or default
            const customData = data.lead_params || {
                content_name: formName,
                content_category: 'lead',
                source: 'elementor_form'
            };

            // CRITICAL: Extract timestamp from event_id to match server-side event_time exactly.
            // Event ID format: lead_{form_id}_{timestamp}_{random} where timestamp is in seconds.
            // CRITICAL: form_id may contain underscores (e.g., "form_123"), so we can't assume timestamp is at index 2.
            // We extract from the end: timestamp is the second-to-last part (before the random part).
            // This matches the WooCommerce implementation pattern for reliable extraction.
            // eventTime must be a Unix timestamp in seconds (not milliseconds).
            const eventTime = (function() {
                var eventParts = eventId.split('_');
                // Need at least: lead, form_id (may have underscores), timestamp, random = 4+ parts
                if (eventParts.length >= 4) {
                    // Timestamp is the second-to-last part (before random).
                    // Format: lead_{form_id}_{timestamp}_{random}
                    // Example: "lead_form_123_1764092422_abc123xyz" -> timestamp is at length - 2
                    var timestamp = parseInt(eventParts[eventParts.length - 2], 10);
                    if (!isNaN(timestamp) && timestamp > 0) {
                        return timestamp; // Already in seconds
                    }
                }
                // Fallback to current time if extraction fails (shouldn't happen).
                return Math.floor(Date.now() / 1000);
            })();

            // CRITICAL: Pass eventID and eventTime for deduplication.
            // eventTime must match server-side event_time exactly (extracted from event_id).
            fbq('track', 'Lead', customData, {
                eventID: eventId,
                eventTime: eventTime
            });
        }

})(jQuery);

