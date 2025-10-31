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
             * Matches server-side format for proper deduplication
             */
            generateEventId: function(eventName, uniqueId = '') {
                const timestamp = Date.now();
                const random = Math.random().toString(36).substring(2, 15);
                return eventName + '_' + uniqueId + '_' + timestamp + '_' + random;
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

                const eventId = this.generateEventId('ViewContent', productData.id);

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

                    const eventId = self.generateEventId('AddToCart', productId);
                    
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
                        event_id: eventId
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

                    const eventId = self.generateEventId('AddToCart', productData.id);

                    fbq('track', 'AddToCart', {
                        content_ids: [productData.id],
                        content_name: productData.name,
                        content_type: 'product',
                        value: productData.price * quantity,
                        currency: productData.currency
                    }, {
                        eventID: eventId
                    });

                    console.log('Meta CAPI: AddToCart event tracked (Form)', {
                        product_id: productData.id,
                        quantity: quantity,
                        event_id: eventId
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

                const eventId = this.generateEventId('InitiateCheckout');

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
                if (typeof metaCAPIWooCommerceData === 'undefined' || !metaCAPIWooCommerceData.is_order_received) {
                    return;
                }

                const orderData = metaCAPIWooCommerceData.order;
                if (!orderData || !orderData.id) {
                    return;
                }

                // Use order ID as unique identifier for deduplication with server
                const eventId = 'purchase_' + orderData.id;

                fbq('track', 'Purchase', {
                    content_ids: orderData.content_ids || [],
                    contents: orderData.contents || [],
                    content_type: 'product',
                    value: orderData.value || 0,
                    currency: orderData.currency,
                    num_items: orderData.num_items || 0
                }, {
                    eventID: eventId
                });

                console.log('Meta CAPI: Purchase event tracked', {
                    order_id: orderData.id,
                    value: orderData.value,
                    event_id: eventId
                });
            }
        };

        // Initialize
        MetaCAPIWooCommerce.init();
    });

})(jQuery);
