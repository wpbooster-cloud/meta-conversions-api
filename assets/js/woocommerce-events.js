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

                fbq('track', 'ViewContent', {
                    content_ids: [productData.id],
                    content_name: productData.name,
                    content_type: 'product',
                    content_category: productData.category || '',
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
                    
                    // Extract event ID from fragments (if provided by server).
                    // The server injects it as a hidden div in fragments['meta_capi_addtocart_event_id'].
                    if (fragments && fragments.meta_capi_addtocart_event_id) {
                        // Parse HTML fragment to extract data-event-id attribute.
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = fragments.meta_capi_addtocart_event_id;
                        const eventIdElement = tempDiv.querySelector('#meta-capi-addtocart-event-id');
                        if (eventIdElement) {
                            eventId = eventIdElement.getAttribute('data-event-id') || '';
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

                    fbq('track', 'AddToCart', {
                        content_ids: [productData.id],
                        content_name: productData.name,
                        content_type: 'product',
                        value: productData.price * quantity,
                        currency: currency
                    }, {
                        eventID: eventId
                    });

                    console.log('Meta CAPI: AddToCart event tracked (AJAX)', {
                        product_id: productId,
                        quantity: quantity,
                        event_id: eventId,
                        source: isFallback ? 'browser_generated' : 'server_provided'
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

                    fbq('track', 'AddToCart', {
                        content_ids: [variationId || productData.id],
                        content_name: productData.name,
                        content_type: 'product',
                        value: productData.price * quantity,
                        currency: productData.currency
                    }, {
                        eventID: eventId
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
                // Format: checkout_{session_id}_{timestamp} - must match server-side exactly.
                let eventId = cartData.initiatecheckout_event_id;
                
                // Fallback: Generate if server didn't provide one (shouldn't happen).
                if (!eventId) {
                    console.warn('Meta CAPI: InitiateCheckout event ID not provided by server, generating fallback (deduplication may not work)');
                    // Try to get session identifier (would need to be passed from server for proper matching).
                    eventId = this.generateEventId('InitiateCheckout', '');
                }

                fbq('track', 'InitiateCheckout', {
                    content_ids: cartData.content_ids || [],
                    contents: cartData.contents || [],
                    content_type: 'product',
                    value: cartData.value || 0,
                    currency: cartData.currency,
                    num_items: cartData.num_items || 0
                }, {
                    eventID: eventId
                });

                console.log('Meta CAPI: InitiateCheckout event tracked', {
                    value: cartData.value,
                    num_items: cartData.num_items,
                    event_id: eventId
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

                // Ensure we have required values
                const purchaseData = {
                    content_ids: orderData.content_ids || [],
                    contents: orderData.contents || [],
                    content_type: 'product',
                    value: parseFloat(orderData.value) || 0,
                    currency: orderData.currency || metaCAPIWooCommerceData.currency || 'USD',
                    num_items: parseInt(orderData.num_items) || 0
                };

                if (typeof fbq === 'undefined') {
                    console.error('Meta CAPI: Facebook Pixel (fbq) not loaded. Purchase event not tracked.');
                    return;
                }

                fbq('track', 'Purchase', purchaseData, {
                    eventID: eventId
                });

                console.log('Meta CAPI: Purchase event tracked (browser-side)', {
                    order_id: orderData.id,
                    value: purchaseData.value,
                    currency: purchaseData.currency,
                    num_items: purchaseData.num_items,
                    event_id: eventId
                });
            }
        };

        // Initialize
        MetaCAPIWooCommerce.init();
    });

})(jQuery);
