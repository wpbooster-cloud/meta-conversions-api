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
        
        // Listen for Elementor AJAX success response
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

        // Fire browser-side Lead event
        fbq('track', 'Lead', customData, {
            eventID: eventId
        });
    }

})(jQuery);

