# Deduplication & Dual-Tracking Issues Analysis

## Critical Issues Found

### 1. ❌ Event ID Format Mismatch

**Problem**: Browser and server generate event IDs differently, preventing deduplication.

**Current State**:
- **Server (WooCommerce)**: Uses own `generate_event_id()` → `prefix_timestamp_random`
- **Server (Coordinator)**: Has `generate_event_id()` → `eventtype_identifier_timestamp` (NOT USED)
- **Browser (JS)**: Uses `eventName_uniqueId_timestamp_random`

**Impact**: Events sent from browser and server have different event IDs, so Facebook treats them as separate events (duplicates).

**Example**:
- Browser Purchase: `purchase_123` (order ID only)
- Server Purchase: `purchase_123` (should match, but format might differ)
- Browser AddToCart: `AddToCart_456_1234567890_abc123` (with timestamp + random)
- Server AddToCart: `AddToCart_456_1234567890_xyz789` (different random = different ID)

### 2. ❌ PageView Missing Event ID

**Problem**: PageView events don't have `event_id` set, so they can't be deduplicated.

**Location**: `includes/class-meta-capi-tracking.php` line 86-93

**Impact**: Every page view sends both Pixel and CAPI events, creating duplicates.

### 3. ❌ Coordinator Not Being Used

**Problem**: WooCommerce class has its own event ID generator instead of using the Coordinator.

**Location**: `includes/class-meta-capi-woocommerce.php` line 829-833

**Impact**: Inconsistent event ID generation across the plugin.

### 4. ❌ Purchase Event ID Format Inconsistency

**Problem**: Purchase events use order ID only, but format might differ (string vs number).

**Current**:
- Server: `'purchase_' . $order->get_id()` (PHP string concatenation)
- Browser: `'purchase_' + orderData.id` (JavaScript string concatenation)

**Risk**: If order ID is number in JS but string in PHP, IDs won't match.

### 5. ❌ Browser-Side Random Component

**Problem**: Browser adds random component to event IDs, making them impossible to match with server.

**Location**: `assets/js/woocommerce-events.js` line 44

**Impact**: Server can't predict browser event IDs, so deduplication fails.

---

## Required Fixes

### Fix 1: Use Coordinator for All Event IDs
- WooCommerce class should use `$coordinator->generate_event_id()` instead of own method
- Ensure consistent format across all events

### Fix 2: Add Event ID to PageView
- Generate event ID for PageView events
- Use consistent format: `pageview_{page_id}_{timestamp}` or `pageview_{url_hash}_{timestamp}`

### Fix 3: Standardize Event ID Format
- Purchase: `purchase_{order_id}` (no timestamp, must match exactly)
- AddToCart: `addtocart_{product_id}_{timestamp}` (timestamp for uniqueness)
- ViewContent: `viewcontent_{product_id}_{timestamp}`
- InitiateCheckout: `checkout_{session_id}_{timestamp}`
- PageView: `pageview_{page_id}_{timestamp}`

### Fix 4: Browser-Side Event ID Generation
- Browser must generate event IDs that match server format
- Remove random component OR use same seed/algorithm
- For Purchase: Use order ID only (no timestamp, no random)

### Fix 5: Pass Event IDs to Browser
- Server should generate event IDs and pass them to browser via localized script data
- Browser uses server-generated IDs for Pixel tracking
- Ensures 100% match between Pixel and CAPI event IDs

---

## Testing Checklist

After fixes, test each event type:

- [ ] **PageView**: Check both Pixel and CAPI send same event_id
- [ ] **ViewContent**: Check both Pixel and CAPI send same event_id
- [ ] **AddToCart**: Check both Pixel and CAPI send same event_id
- [ ] **InitiateCheckout**: Check both Pixel and CAPI send same event_id
- [ ] **Purchase**: Check both Pixel and CAPI send same event_id (critical!)

**Verification**:
1. Enable debug logging
2. Check logs for event_id values
3. Verify browser console shows same event_id
4. Check Facebook Test Events - should see events with matching event_id (not duplicates)

---

## Implementation Priority

1. **HIGH**: Fix Purchase event ID matching (most critical for conversions)
2. **HIGH**: Add PageView event ID (most common event)
3. **MEDIUM**: Fix AddToCart/ViewContent/InitiateCheckout event IDs
4. **LOW**: Refactor to use Coordinator consistently

---

Generated: 2025-01-27

