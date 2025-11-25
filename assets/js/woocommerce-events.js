/**
 * Meta CAPI WooCommerce Events - Browser Side Tracking
 * 
 * Handles browser-side tracking for WooCommerce events:
 * - ViewContent (Product page views)
 * - AddToCart (Items added to cart)
 * - InitiateCheckout (Checkout started)
 * - Purchase (Order completed)
 * 
 * Coordinates with server-side CAPI using event IDs for deduplication.
 * 
 * @package Meta_Conversions_API
 * @since 2.0.0
 */

(function($) {
    'use strict';
    
    // Wait for DOM and Meta Pixel to be ready
    $(document).ready(function() {
        if (typeof fbq === 'undefined') {
            console.warn('Meta CAPI: Facebook Pixel (fbq) not loaded. WooCommerce events will not be tracked.');
            return;
        }

        const MetaCAPIWooCommerce = {
            /**
             * Initialize WooCommerce event tracking
             */
            init: function() {
                this.trackViewContent();
                this.trackAddToCart();
                this.trackInitiateCheckout();
                this.trackPurchase();
            },

            /**
             * Generate event ID for deduplication
             * Matches server-side Coordinator format: eventtype_identifier_timestamp
             * Uses milliseconds timestamp (same as server) for exact matching
             */
            generateEventId: function(eventName, uniqueId = '') {
                // Normalize event name to lowercase (matches server-side sanitize_key)
                const eventType = eventName.toLowerCase().replace(/[^a-z0-9]/g, '');
                // Use milliseconds timestamp (same as server-side Coordinator)
                const timestamp = Date.now();
                // Format: eventtype_identifier_timestamp (NO random component - must match server exactly)
                // Handle empty uniqueId to avoid double underscores
                if (uniqueId && uniqueId.trim() !== '') {
                    return eventType + '_' + uniqueId + '_' + timestamp;
                } else {
                    return eventType + '_' + timestamp;
                }
            },

            /**
             * Track ViewContent event on product pages
             */
            trackViewContent: function() {
                if (typeof metaCAPIWooCommerceData === 'undefined' || !metaCAPIWooCommerceData.is_product) {
                    return;
                }

                const productData = metaCAPIWooCommerceData.product;
                if (!productData) {
                    return;
                }

                // Use server-generated event ID for deduplication (if available).
                // Server generates this on page load, ensuring Pixel and CAPI use the SAME event ID.
                let eventId = productData.viewcontent_event_id;
                
                // Fallback: Generate if server didn't provide one (shouldn't happen).
                if (!eventId) {
                    console.warn('Meta CAPI: ViewContent event ID not provided by server, generating fallback (deduplication may not work)');
                    eventId = this.generateEventId('ViewContent', productData.id);
                }

                // CRITICAL: Match server-side format exactly for deduplication.
                // Server sends: content_ids (strings), content_name, content_type, content_category, contents array, value, currency
                fbq('track', 'ViewContent', {
                    content_ids: [String(productData.id)], // Convert to string to match server format
                    content_name: productData.name,
                    content_type: 'product',
                    content_category: productData.category || '',
                    contents: [{ // Include contents array to match server format
                        id: String(productData.id),
                        quantity: 1,
                        item_price: productData.price
                    }],
                    value: productData.price,
                    currency: productData.currency
                }, {
                    eventID: eventId
                });

                console.log('Meta CAPI: ViewContent event tracked', {
                    product_id: productData.id,
                    event_id: eventId
                });
            },

            /**
             * Track AddToCart event
             * Handles both AJAX add to cart and traditional add to cart
             */
            trackAddToCart: function() {
                const self = this;

                // AJAX Add to Cart (most common)
                $(document.body).on('added_to_cart', function(event, fragments, cart_hash, button) {
                    const productId = button.data('product_id');
                    const quantity = button.data('quantity') || 1;

                    // Get product data from button or page
                    let productData = {
                        id: productId,
                        name: button.data('product_name') || button.attr('aria-label') || 'Product',
                        price: parseFloat(button.data('product_price')) || 0,
                        quantity: quantity
                    };

                    // If on product page, use detailed data
                    if (typeof metaCAPIWooCommerceData !== 'undefined' && metaCAPIWooCommerceData.product) {
                        productData = metaCAPIWooCommerceData.product;
                        productData.quantity = quantity;
                    }

                    // CRITICAL: Get event ID from server (via AJAX fragments) for deduplication.
                    // Server generates the event ID during woocommerce_add_to_cart hook,
                    // and passes it via fragments. We MUST use this same ID to match CAPI.
                    let eventId = '';
                    let isFallback = false; // Track if we're using a fallback-generated ID.
                    
                    // Extract event ID, product URL, product price, and product name from fragments (if provided by server).
                    // The server injects it as a hidden div in fragments['meta_capi_addtocart_event_id'].
                    let productUrl = window.location.href; // Default to current URL
                    let serverProductPrice = null; // Server-provided price for deduplication matching
                    let serverProductName = null; // Server-provided name for deduplication matching
                    if (fragments && fragments.meta_capi_addtocart_event_id) {
                        // Parse HTML fragment to extract data attributes.
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = fragments.meta_capi_addtocart_event_id;
                        const eventIdElement = tempDiv.querySelector('#meta-capi-addtocart-event-id');
                        if (eventIdElement) {
                            eventId = eventIdElement.getAttribute('data-event-id') || '';
                            // CRITICAL: Get product page URL from server (for deduplication).
                            // Meta uses event_source_url as part of deduplication matching.
                            // Browser and server must use the SAME URL (product page, not homepage).
                            const serverProductUrl = eventIdElement.getAttribute('data-product-url');
                            if (serverProductUrl) {
                                productUrl = serverProductUrl;
                            }
                            // CRITICAL: Get product price from server to match server's value for deduplication.
                            const serverPrice = eventIdElement.getAttribute('data-product-price');
                            if (serverPrice !== null) {
                                serverProductPrice = parseFloat(serverPrice);
                                if (!isNaN(serverProductPrice)) {
                                    // Use server-provided price to ensure exact match with server event.
                                    productData.price = serverProductPrice;
                                }
                            }
                            // CRITICAL: Get product name from server to match server's format for deduplication.
                            // Server sends clean product name (e.g., "Hat"), not "Add to cart: Hat".
                            const serverName = eventIdElement.getAttribute('data-product-name');
                            if (serverName) {
                                serverProductName = serverName;
                                // Use server-provided name to ensure exact match with server event.
                                productData.name = serverProductName;
                            }
                        }
                    }
                    
                    // Fallback: Generate if server didn't provide one (shouldn't happen).
                    // This will break deduplication, but at least the event will fire.
                    if (!eventId) {
                        console.warn('Meta CAPI: AddToCart event ID not provided by server, generating fallback (deduplication may not work)');
                        eventId = self.generateEventId('AddToCart', productId);
                        isFallback = true; // Mark as fallback-generated.
                    }
                    
                    // Get currency safely
                    const currency = productData.currency || 
                                   (typeof metaCAPIWooCommerceData !== 'undefined' ? metaCAPIWooCommerceData.currency : 'USD');

                    // CRITICAL: Meta Pixel automatically uses window.location.href as event_source_url.
                    // If the page has changed (redirect after add-to-cart), we need to ensure
                    // the event fires from the product page URL. However, Meta Pixel doesn't allow
                    // us to override event_source_url directly. The best we can do is:
                    // 1. Log the URL mismatch for debugging
                    // 2. Ensure the event fires before any redirect
                    // 3. Use the product URL from server in our logging
                    
                    // Check if current URL matches product URL (for deduplication debugging).
                    const currentUrl = window.location.href;
                    const urlMatches = currentUrl === productUrl || currentUrl.replace(/\/$/, '') === productUrl.replace(/\/$/, '');
                    if (!urlMatches) {
                        console.warn('[Meta CAPI] URL mismatch detected - this may break deduplication!', {
                            current_url: currentUrl,
                            product_url: productUrl,
                            note: 'Browser event will use current_url, but server uses product_url. They must match for deduplication.'
                        });
                    }

                    // CRITICAL: Extract timestamp from event_id to match server-side event_time exactly.
                    // Event ID format: addtocart_{product_id}_{timestamp_ms} where timestamp is in milliseconds.
                    // We extract this and convert to seconds to ensure perfect alignment with CAPI.
                    // eventTime must be a Unix timestamp in seconds (not milliseconds).
                    const eventTime = (function() {
                        var eventParts = eventId.split('_');
                        if (eventParts.length >= 3) {
                            var timestampMs = parseInt(eventParts[eventParts.length - 1], 10);
                            if (!isNaN(timestampMs)) {
                                return Math.floor(timestampMs / 1000); // Convert milliseconds to seconds.
                            }
                        }
                        // Fallback to current time if extraction fails (shouldn't happen).
                        return Math.floor(Date.now() / 1000);
                    })();

                    // CRITICAL: Match server-side format exactly for deduplication.
                    // - content_ids must be strings (server sends ["36"], not [36])
                    // - content_name must match server exactly (no "Add to cart: " prefix)
                    // - contents array must be included to match server format
                    // CRITICAL: Pass eventID and eventTime for deduplication.
                    // eventTime must match server-side event_time exactly (extracted from event_id).
                    fbq('track', 'AddToCart', {
                        content_ids: [String(productData.id)], // Convert to string to match server format
                        content_name: productData.name, // Use server-provided name (clean, no prefix)
                        content_type: 'product',
                        contents: [{ // Include contents array to match server format
                            id: String(productData.id),
                            quantity: quantity,
                            item_price: productData.price
                        }],
                        value: productData.price * quantity,
                        currency: currency
                    }, {
                        eventID: eventId,
                        eventTime: eventTime
                    });

                    console.log('[Meta CAPI Debug] AddToCart event tracked (AJAX) - FULL PAYLOAD', {
                            product_id: productId,
                            quantity: quantity,
                        event_id: eventId,
                        source: isFallback ? 'browser_generated' : 'server_provided',
                        event_source_url: currentUrl, // What Meta Pixel will use
                        product_url_from_server: productUrl, // What server will use
                        url_match: urlMatches,
                        warning: !urlMatches ? 'URL mismatch - deduplication may fail!' : 'URLs match - deduplication should work'
                    });
                });

                // Traditional Add to Cart (form submission)
                $('form.cart').on('submit', function() {
                    if (typeof metaCAPIWooCommerceData === 'undefined' || !metaCAPIWooCommerceData.product) {
                        return;
                    }

                    const productData = metaCAPIWooCommerceData.product;
                    const quantityInput = $(this).find('input[name="quantity"]');
                    const quantity = quantityInput.length ? parseInt(quantityInput.val()) : 1;

                    // CRITICAL: Use server-generated event ID for deduplication (pre-generated on product page).
                    // For variable products, check if there's a variation-specific event ID.
                    let eventId = '';
                    let isFallback = false; // Track if we're using a fallback-generated ID.
                    const variationInput = $(this).find('input[name="variation_id"]');
                    const variationId = variationInput.length ? variationInput.val() : null;
                    
                    if (variationId && productData.addtocart_event_ids && productData.addtocart_event_ids[variationId]) {
                        // Use variation-specific event ID.
                        eventId = productData.addtocart_event_ids[variationId];
                    } else if (productData.addtocart_event_id) {
                        // Use main product event ID.
                        eventId = productData.addtocart_event_id;
                    }
                    
                    // Fallback: Generate if server didn't provide one (shouldn't happen).
                    if (!eventId) {
                        console.warn('Meta CAPI: AddToCart event ID not provided by server for form submission, generating fallback (deduplication may not work)');
                        eventId = self.generateEventId('AddToCart', variationId || productData.id);
                        isFallback = true; // Mark as fallback-generated.
                    }

                    // CRITICAL: Extract timestamp from event_id to match server-side event_time exactly.
                    // Event ID format: addtocart_{product_id}_{timestamp_ms} where timestamp is in milliseconds.
                    // We extract this and convert to seconds to ensure perfect alignment with CAPI.
                    // eventTime must be a Unix timestamp in seconds (not milliseconds).
                    const eventTime = (function() {
                        var eventParts = eventId.split('_');
                        if (eventParts.length >= 3) {
                            var timestampMs = parseInt(eventParts[eventParts.length - 1], 10);
                            if (!isNaN(timestampMs)) {
                                return Math.floor(timestampMs / 1000); // Convert milliseconds to seconds.
                            }
                        }
                        // Fallback to current time if extraction fails (shouldn't happen).
                        return Math.floor(Date.now() / 1000);
                    })();

                    // CRITICAL: Pass eventID and eventTime for deduplication.
                    // eventTime must match server-side event_time exactly (extracted from event_id).
                    fbq('track', 'AddToCart', {
                        content_ids: [variationId || productData.id],
                        content_name: productData.name,
                        content_type: 'product',
                        value: productData.price * quantity,
                        currency: productData.currency
                    }, {
                        eventID: eventId,
                        eventTime: eventTime
                    });

                    console.log('Meta CAPI: AddToCart event tracked (Form)', {
                        product_id: variationId || productData.id,
                        quantity: quantity,
                        event_id: eventId,
                        source: isFallback ? 'browser_generated' : 'server_provided'
                    });
                });
            },

            /**
             * Track InitiateCheckout event
             */
            trackInitiateCheckout: function() {
                if (typeof metaCAPIWooCommerceData === 'undefined' || !metaCAPIWooCommerceData.is_checkout) {
                    return;
                }

                const cartData = metaCAPIWooCommerceData.cart;
                if (!cartData) {
                    return;
                }

                // Use server-generated event ID for deduplication (if available).
                // Server generates this on checkout page load, ensuring Pixel and CAPI use the SAME event ID.
                // Format: checkout_{session_id}_{timestamp_ms} - must match server-side exactly.
                let eventId = cartData.initiatecheckout_event_id;
                
                // Fallback: Generate if server didn't provide one (shouldn't happen).
                if (!eventId) {
                    console.warn('Meta CAPI: InitiateCheckout event ID not provided by server, generating fallback (deduplication may not work)');
                    // Try to get session identifier (would need to be passed from server for proper matching).
                    eventId = this.generateEventId('InitiateCheckout', '');
                }
                
                // CRITICAL: Extract timestamp from event_id to match server-side event_time exactly.
                // Event ID format: checkout_{session_id}_{timestamp_ms} where timestamp is in milliseconds.
                // We extract this and convert to seconds to ensure perfect alignment with CAPI.
                // eventTime must be a Unix timestamp in seconds (not milliseconds).
                const eventTime = (function() {
                    var eventParts = eventId.split('_');
                    if (eventParts.length >= 3) {
                        var timestampMs = parseInt(eventParts[eventParts.length - 1], 10);
                        if (!isNaN(timestampMs)) {
                            return Math.floor(timestampMs / 1000); // Convert milliseconds to seconds.
                        }
                    }
                    // Fallback to current time if extraction fails (shouldn't happen).
                    return Math.floor(Date.now() / 1000);
                })();

                // Get fbp cookie (Meta Pixel automatically includes this in user_data).
                var fbpCookie = '';
                var cookies = document.cookie.split(';');
                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();
                    if (cookie.indexOf('_fbp=') === 0) {
                        fbpCookie = cookie.substring(5);
                        break;
                    }
                }
                
                // Get user agent (Meta Pixel automatically includes this in user_data).
                var userAgent = navigator.userAgent || 'unknown';
                
                // CRITICAL: Pass eventID and eventTime for deduplication.
                // eventTime must match server-side event_time exactly (extracted from event_id).
                // CRITICAL: Include content_name to match server-side format for deduplication.
                fbq('track', 'InitiateCheckout', {
                    content_ids: cartData.content_ids || [],
                    content_name: cartData.content_name || '', // Match server-side format (comma-separated product names).
                    contents: cartData.contents || [],
                    content_type: 'product',
                    value: cartData.value || 0,
                    currency: cartData.currency,
                    num_items: cartData.num_items || 0
                }, {
                    eventID: eventId,
                    eventTime: eventTime
                });

                console.log('[Meta CAPI Debug] InitiateCheckout event tracked - FULL PAYLOAD', {
                    event_id: eventId,
                    event_time: eventTime,
                    event_time_formatted: new Date(eventTime * 1000).toISOString(),
                    event_time_current: Math.floor(Date.now() / 1000),
                    time_difference: Math.floor(Date.now() / 1000) - eventTime,
                    source: 'Browser',
                    url: window.location.href,
                    user_data: {
                        fbp: fbpCookie || 'not_set_yet',
                        fbp_preview: fbpCookie ? fbpCookie.substring(0, 30) + '...' : 'not_set_yet',
                        user_agent: userAgent.substring(0, 100) + '...',
                        ip_address: 'browser_detected', // Meta Pixel gets IP from request, not JavaScript
                        note: 'Meta Pixel automatically includes fbp, IP (from request), and user agent in event'
                    },
                    custom_data: {
                        value: cartData.value || 0,
                        currency: cartData.currency,
                        num_items: cartData.num_items || 0,
                        content_ids: cartData.content_ids || []
                    },
                    note: 'Compare user_data with server logs - IP, user_agent, and fbp MUST match for deduplication'
                });
            },

            /**
             * Track Purchase event on thank you page
             */
            trackPurchase: function() {
                if (typeof metaCAPIWooCommerceData === 'undefined') {
                    console.warn('Meta CAPI: WooCommerce data not available for Purchase tracking');
                    return;
                }

                if (!metaCAPIWooCommerceData.is_order_received) {
                    console.log('Meta CAPI: Not on order received page, skipping Purchase event');
                    return;
                }

                // Skip browser-side Purchase if timing is set to "payment confirmed" (server-only).
                const purchaseTiming = metaCAPIWooCommerceData.purchase_timing || 'placed';
                if (purchaseTiming === 'paid') {
                    console.log('Meta CAPI: Purchase timing set to "payment confirmed", skipping browser-side event (server-only tracking)');
                    return;
                }

                const orderData = metaCAPIWooCommerceData.order;
                if (!orderData || !orderData.id) {
                    console.warn('Meta CAPI: Order data not available', {
                        has_order_data: !!orderData,
                        wc_data: metaCAPIWooCommerceData
                    });
                    return;
                }

                // Use order ID as unique identifier for deduplication with server
                const eventId = 'purchase_' + orderData.id;
                
                // CRITICAL: Use order creation timestamp (from server) to match server event_time exactly.
                // Server uses order creation time, browser must use the same for deduplication.
                // eventTime must be a Unix timestamp in seconds (not milliseconds).
                // CRITICAL: Validate that eventTime is a valid number to prevent RangeError in date formatting.
                const eventTime = (function() {
                    if (orderData.event_time) {
                        const parsed = parseInt(orderData.event_time, 10);
                        if (!isNaN(parsed) && parsed > 0) {
                            return parsed; // Valid timestamp
                        }
                    }
                    // Fallback to current time if server data is missing or invalid
                    return Math.floor(Date.now() / 1000);
                })();

                // Get fbp cookie (Meta Pixel automatically includes this in user_data).
                var fbpCookie = '';
                var cookies = document.cookie.split(';');
                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();
                    if (cookie.indexOf('_fbp=') === 0) {
                        fbpCookie = cookie.substring(5);
                        break;
                    }
                }
                
                // Get user agent (Meta Pixel automatically includes this in user_data).
                var userAgent = navigator.userAgent || 'unknown';

                // CRITICAL: Match server-side format exactly for deduplication.
                // Include content_name and order_id to match server format.
                const purchaseData = {
                        content_ids: orderData.content_ids || [],
                        content_name: orderData.content_name || '', // Match server-side format for deduplication.
                        contents: orderData.contents || [],
                        content_type: 'product',
                        value: parseFloat(orderData.value) || 0,
                        currency: orderData.currency || metaCAPIWooCommerceData.currency || 'USD',
                        num_items: parseInt(orderData.num_items) || 0,
                        order_id: orderData.id || '' // Match server-side format for deduplication.
                    };

                if (typeof fbq === 'undefined') {
                    console.error('Meta CAPI: Facebook Pixel (fbq) not loaded. Purchase event not tracked.');
                    return;
                }

                // CRITICAL: Pass eventID and eventTime for deduplication.
                // eventTime must match server-side event_time exactly (order creation time).
                fbq('track', 'Purchase', purchaseData, {
                    eventID: eventId,
                    eventTime: eventTime
                });

                console.log('[Meta CAPI Debug] Purchase event tracked - FULL PAYLOAD', {
                    event_id: eventId,
                    event_time: eventTime,
                    event_time_formatted: new Date(eventTime * 1000).toISOString(),
                    event_time_current: Math.floor(Date.now() / 1000),
                    time_difference: Math.floor(Date.now() / 1000) - eventTime,
                    source: 'Browser',
                    url: window.location.href,
                    user_data: {
                        fbp: fbpCookie || 'not_set_yet',
                        fbp_preview: fbpCookie ? fbpCookie.substring(0, 30) + '...' : 'not_set_yet',
                        user_agent: userAgent.substring(0, 100) + '...',
                        ip_address: 'browser_detected', // Meta Pixel gets IP from request, not JavaScript
                        note: 'Meta Pixel automatically includes fbp, IP (from request), and user agent in event'
                    },
                    custom_data: purchaseData,
                    note: 'eventTime MUST match server-side event_time (order creation time) for deduplication. Compare user_data with server logs - IP, user_agent, and fbp MUST match.'
                });
            }
            };

        // Initialize
            MetaCAPIWooCommerce.init();
    });

})(jQuery);
