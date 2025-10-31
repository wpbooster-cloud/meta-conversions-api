/**
 * Meta Pixel Helper Functions
 * 
 * Provides utilities for tracking events with Meta Pixel
 * and coordinating with server-side Conversions API.
 * 
 * @package Meta_Conversions_API
 * @since 2.0.0
 */

(function(window, document) {
    'use strict';

    /**
     * Meta CAPI Pixel Helper Object
     */
    window.MetaCAPIPixel = {
        
        /**
         * Debug mode flag
         */
        debug: false,

        /**
         * Initialize the pixel helper
         * 
         * @param {Object} config Configuration object
         */
        init: function(config) {
            this.debug = config.debug || false;
            this.log('Meta CAPI Pixel Helper initialized', config);
        },

        /**
         * Generate a unique event ID
         * 
         * @param {string} eventType - Event type (purchase, addtocart, etc.)
         * @param {string} identifier - Unique identifier
         * @param {boolean} includeTimestamp - Whether to include timestamp
         * @returns {string} Event ID
         */
        generateEventID: function(eventType, identifier, includeTimestamp) {
            if (typeof includeTimestamp === 'undefined') {
                includeTimestamp = true;
            }

            var eventID = eventType + '_' + identifier;
            
            if (includeTimestamp) {
                eventID += '_' + Date.now();
            }

            this.log('Generated Event ID', {
                eventType: eventType,
                identifier: identifier,
                eventID: eventID
            });

            return eventID;
        },

        /**
         * Track an event with Meta Pixel
         * 
         * @param {string} eventName - Event name
         * @param {Object} eventData - Event data
         * @param {string} eventID - Event ID for deduplication
         * @returns {boolean} Success status
         */
        track: function(eventName, eventData, eventID) {
            // Check if fbq is available
            if (typeof fbq === 'undefined') {
                this.log('Meta Pixel not loaded, skipping event', {
                    eventName: eventName
                }, 'warn');
                return false;
            }

            try {
                // Track with event ID for deduplication
                fbq('track', eventName, eventData, {
                    eventID: eventID
                });

                this.log('Event tracked', {
                    eventName: eventName,
                    eventData: eventData,
                    eventID: eventID
                });

                return true;
            } catch (error) {
                this.log('Error tracking event', {
                    eventName: eventName,
                    error: error.message
                }, 'error');
                return false;
            }
        },

        /**
         * Get Meta Pixel cookies (_fbp, _fbc)
         * 
         * @returns {Object} Object with fbp and fbc values
         */
        getPixelCookies: function() {
            var cookies = {
                fbp: this.getCookie('_fbp'),
                fbc: this.getCookie('_fbc')
            };

            // If _fbc doesn't exist, try to get fbclid from URL
            if (!cookies.fbc) {
                var fbclid = this.getUrlParameter('fbclid');
                if (fbclid) {
                    cookies.fbc = 'fb.1.' + Date.now() + '.' + fbclid;
                }
            }

            this.log('Retrieved pixel cookies', cookies);
            return cookies;
        },

        /**
         * Get a cookie value by name
         * 
         * @param {string} name - Cookie name
         * @returns {string|null} Cookie value or null
         */
        getCookie: function(name) {
            var value = '; ' + document.cookie;
            var parts = value.split('; ' + name + '=');
            
            if (parts.length === 2) {
                return parts.pop().split(';').shift();
            }
            
            return null;
        },

        /**
         * Get URL parameter value
         * 
         * @param {string} name - Parameter name
         * @returns {string|null} Parameter value or null
         */
        getUrlParameter: function(name) {
            name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
            var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
            var results = regex.exec(location.search);
            return results === null ? null : decodeURIComponent(results[1].replace(/\+/g, ' '));
        },

        /**
         * Store event ID in sessionStorage for coordination
         * 
         * @param {string} eventID - Event ID to store
         * @param {Object} eventData - Event data to store
         */
        storeEventID: function(eventID, eventData) {
            if (typeof sessionStorage === 'undefined') {
                this.log('SessionStorage not available', null, 'warn');
                return;
            }

            try {
                var data = {
                    eventID: eventID,
                    eventData: eventData,
                    timestamp: Date.now()
                };

                sessionStorage.setItem('meta_capi_' + eventID, JSON.stringify(data));
                this.log('Event ID stored', data);
            } catch (error) {
                this.log('Error storing event ID', {
                    error: error.message
                }, 'error');
            }
        },

        /**
         * Retrieve event ID from sessionStorage
         * 
         * @param {string} eventID - Event ID to retrieve
         * @param {boolean} remove - Whether to remove after retrieval
         * @returns {Object|null} Event data or null
         */
        getStoredEventID: function(eventID, remove) {
            if (typeof sessionStorage === 'undefined') {
                return null;
            }

            if (typeof remove === 'undefined') {
                remove = true;
            }

            try {
                var key = 'meta_capi_' + eventID;
                var data = sessionStorage.getItem(key);

                if (data) {
                    data = JSON.parse(data);
                    
                    if (remove) {
                        sessionStorage.removeItem(key);
                    }

                    this.log('Event ID retrieved', data);
                    return data;
                }
            } catch (error) {
                this.log('Error retrieving event ID', {
                    error: error.message
                }, 'error');
            }

            return null;
        },

        /**
         * Send event data to server via AJAX
         * 
         * @param {string} action - WordPress AJAX action
         * @param {Object} data - Data to send
         * @param {Function} callback - Callback function
         */
        sendToServer: function(action, data, callback) {
            // Check if AJAX URL is available
            if (typeof meta_capi_ajax === 'undefined' || !meta_capi_ajax.ajax_url) {
                this.log('AJAX URL not defined', null, 'error');
                return;
            }

            // Prepare data
            var ajaxData = {
                action: action,
                nonce: meta_capi_ajax.nonce
            };

            // Merge with provided data
            for (var key in data) {
                if (data.hasOwnProperty(key)) {
                    ajaxData[key] = data[key];
                }
            }

            this.log('Sending data to server', {
                action: action,
                data: ajaxData
            });

            // Send via AJAX (vanilla JS, no jQuery dependency)
            var xhr = new XMLHttpRequest();
            xhr.open('POST', meta_capi_ajax.ajax_url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    var response = {};
                    try {
                        response = JSON.parse(xhr.responseText);
                    } catch (e) {
                        response = { success: false, error: 'Invalid JSON response' };
                    }

                    MetaCAPIPixel.log('Server response received', response);

                    if (typeof callback === 'function') {
                        callback(response);
                    }
                } else {
                    MetaCAPIPixel.log('Server request failed', {
                        status: xhr.status
                    }, 'error');
                }
            };

            xhr.onerror = function() {
                MetaCAPIPixel.log('Network error', null, 'error');
            };

            // Convert data to URL-encoded format
            var params = [];
            for (var key in ajaxData) {
                if (ajaxData.hasOwnProperty(key)) {
                    params.push(encodeURIComponent(key) + '=' + encodeURIComponent(ajaxData[key]));
                }
            }

            xhr.send(params.join('&'));
        },

        /**
         * Log message to console (if debug mode enabled)
         * 
         * @param {string} message - Message to log
         * @param {*} data - Additional data
         * @param {string} level - Log level (log, warn, error)
         */
        log: function(message, data, level) {
            if (!this.debug) {
                return;
            }

            level = level || 'log';
            var prefix = '[Meta CAPI Pixel]';

            if (console && console[level]) {
                if (data) {
                    console[level](prefix, message, data);
                } else {
                    console[level](prefix, message);
                }
            }
        }
    };

    // Auto-initialize if config is available
    if (typeof meta_capi_config !== 'undefined') {
        window.MetaCAPIPixel.init(meta_capi_config);
    }

})(window, document);

