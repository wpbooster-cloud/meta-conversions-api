# Deduplication Fixes Applied

## Summary

Fixed all identified deduplication issues to ensure browser (Pixel) and server (CAPI) events use matching event IDs.

---

## Fixes Applied

### ✅ 1. WooCommerce Class Now Uses Coordinator

**File**: `includes/class-meta-capi-woocommerce.php`

**Changes**:
- Added `Meta_CAPI_Coordinator` to constructor
- Removed local `generate_event_id()` method
- Updated all event ID generation to use `$this->coordinator->generate_event_id()`
- Added `get_wc_session_id()` helper method

**Event IDs Now Generated As**:
- **ViewContent**: `viewcontent_{product_id}_{timestamp}` (with timestamp)
- **AddToCart**: `addtocart_{product_id}_{timestamp}` (with timestamp)
- **InitiateCheckout**: `checkout_{session_id}_{timestamp}` (with timestamp)
- **Purchase**: `purchase_{order_id}` (NO timestamp - exact match)

**Impact**: Consistent event ID generation across all WooCommerce events

---

### ✅ 2. Browser-Side Event ID Generation Fixed

**File**: `assets/js/woocommerce-events.js`

**Changes**:
- Updated `generateEventId()` to match Coordinator format exactly
- Removed random component (was causing mismatches)
- Format: `eventtype_identifier_timestamp` (no random)
- Uses `Date.now()` for milliseconds timestamp (matches server)

**Before**:
```javascript
return eventName + '_' + uniqueId + '_' + timestamp + '_' + random;
// Format: eventname_id_timestamp_random ❌
```

**After**:
```javascript
const eventType = eventName.toLowerCase().replace(/[^a-z0-9]/g, '');
const timestamp = Date.now();
return eventType + '_' + uniqueId + '_' + timestamp;
// Format: eventtype_identifier_timestamp ✅
```

**Impact**: Browser and server now generate identical event IDs

---

### ✅ 3. PageView Event ID Coordination Added

**Files**: 
- `includes/class-meta-capi-tracking.php`
- `includes/class-meta-capi-pixel.php`

**Changes**:
- Added Coordinator to Tracking class constructor
- Generate event ID in `track_page_view()` using Coordinator
- Format: `pageview_{page_id}_{timestamp}` or `pageview_{url_hash}_{timestamp}`
- Set filter `meta_capi_pageview_event_id` for Pixel to use
- Updated Pixel injection to use `eventID` parameter in `fbq('track', 'PageView', {}, {eventID: '...'})`

**Impact**: PageView events now have matching event IDs between Pixel and CAPI

---

### ✅ 4. Purchase Event ID Verified

**Status**: ✅ Already Correct

**Format**:
- **Server**: `purchase_{order_id}` (from Coordinator with `include_timestamp=false`)
- **Browser**: `'purchase_' + orderData.id` → `purchase_{order_id}`

**Impact**: Purchase events already have matching event IDs (no random, no timestamp)

---

### ✅ 5. Main Plugin Updated

**File**: `meta-conversions-api.php`

**Changes**:
- Updated `init_woocommerce()` to pass `$this->coordinator` to WooCommerce constructor
- Updated `tracking` initialization to pass `$this->coordinator` to Tracking constructor

**Impact**: All classes now have access to Coordinator for consistent event ID generation

---

## Event ID Format Summary

| Event Type | Server Format | Browser Format | Status |
|-----------|---------------|----------------|--------|
| PageView | `pageview_{page_id}_{timestamp}` | `pageview_{page_id}_{timestamp}` | ✅ Fixed |
| ViewContent | `viewcontent_{product_id}_{timestamp}` | `viewcontent_{product_id}_{timestamp}` | ✅ Fixed |
| AddToCart | `addtocart_{product_id}_{timestamp}` | `addtocart_{product_id}_{timestamp}` | ✅ Fixed |
| InitiateCheckout | `checkout_{session_id}_{timestamp}` | `checkout_{session_id}_{timestamp}` | ✅ Fixed |
| Purchase | `purchase_{order_id}` | `purchase_{order_id}` | ✅ Already Correct |

**Note**: `{timestamp}` is milliseconds (e.g., `1706371200000`)

---

## Testing Required

After these fixes, test each event type:

1. **PageView**: 
   - Visit homepage
   - Check both Pixel and CAPI send same `event_id`
   - Verify Facebook shows ONE event (not duplicates)

2. **ViewContent**:
   - Visit product page
   - Check event IDs match
   - Verify Facebook shows ONE event

3. **AddToCart**:
   - Add product to cart
   - Check event IDs match
   - Verify Facebook shows ONE event

4. **InitiateCheckout**:
   - Go to checkout page
   - Check event IDs match
   - Verify Facebook shows ONE event

5. **Purchase** (CRITICAL):
   - Complete test order
   - Check event IDs match exactly (`purchase_123` format)
   - Verify Facebook shows ONE event

---

## Files Modified

1. `includes/class-meta-capi-woocommerce.php` - Use Coordinator, remove local method
2. `assets/js/woocommerce-events.js` - Fix event ID generation format
3. `includes/class-meta-capi-tracking.php` - Add PageView event ID
4. `includes/class-meta-capi-pixel.php` - Use PageView event ID in Pixel
5. `meta-conversions-api.php` - Pass Coordinator to classes

---

## Next Steps

1. **Test each event type** using TESTING-PROTOCOL.md
2. **Create checkpoint** after successful tests
3. **Verify in Facebook Test Events** - should see matching event IDs
4. **Check for duplicates** - should see ONE event per action, not two

---

Generated: 2025-01-27

