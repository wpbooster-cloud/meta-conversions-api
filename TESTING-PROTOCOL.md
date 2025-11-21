# Testing Protocol & Checkpoint System

## Purpose

This document outlines the testing protocol to ensure events fire correctly from both browser (Pixel) and server (CAPI), and that deduplication works properly. **Always create a checkpoint after successful tests.**

---

## Pre-Testing Setup

### 1. Enable Debug Logging
- Go to Settings → Meta CAPI → Tools & Logs
- Enable "Debug Logging"
- This will log all API requests and event data

### 2. Configure Test Event Code
- Go to Facebook Events Manager → Test Events tab
- Copy your Test Event Code
- Paste into Settings → Meta CAPI → Credentials → Test Event Code
- This allows you to see events in real-time in Facebook

### 3. Open Browser Console
- Open browser DevTools (F12)
- Go to Console tab
- Look for `[Meta CAPI Pixel]` log messages

### 4. Open Network Tab
- In DevTools, go to Network tab
- Filter by "graph.facebook.com" or "fbevents.js"
- This shows Pixel and CAPI requests

---

## Testing Checklist

### ✅ Test 1: PageView Event

**Steps**:
1. Visit homepage
2. Check browser console for Pixel PageView event
3. Check Network tab for Pixel request to `fbevents.js`
4. Check plugin logs for CAPI PageView event
5. Check Facebook Test Events - should see ONE PageView with matching event_id

**Expected**:
- ✅ Pixel fires: `fbq('track', 'PageView', {...}, {eventID: 'pageview_123_...'})`
- ✅ CAPI fires: Server sends PageView with same `event_id`
- ✅ Facebook shows ONE event (not two duplicates)

**Checkpoint**: Create git commit/tag after successful test
```bash
git add .
git commit -m "✅ CHECKPOINT: PageView dual-tracking and deduplication working"
git tag -a v2.0.1-pageview -m "PageView events working correctly"
```

---

### ✅ Test 2: ViewContent Event (Product Page)

**Steps**:
1. Visit a WooCommerce product page
2. Check browser console for Pixel ViewContent event
3. Check plugin logs for CAPI ViewContent event
4. Check Facebook Test Events - should see ONE ViewContent with matching event_id

**Expected**:
- ✅ Pixel fires: `fbq('track', 'ViewContent', {...}, {eventID: 'viewcontent_456_...'})`
- ✅ CAPI fires: Server sends ViewContent with same `event_id`
- ✅ Event data matches (product ID, price, currency)

**Checkpoint**: Create git commit/tag after successful test
```bash
git add .
git commit -m "✅ CHECKPOINT: ViewContent dual-tracking and deduplication working"
git tag -a v2.0.1-viewcontent -m "ViewContent events working correctly"
```

---

### ✅ Test 3: AddToCart Event

**Steps**:
1. Visit product page
2. Click "Add to Cart" button
3. Check browser console for Pixel AddToCart event
4. Check plugin logs for CAPI AddToCart event
5. Check Facebook Test Events - should see ONE AddToCart with matching event_id

**Expected**:
- ✅ Pixel fires: `fbq('track', 'AddToCart', {...}, {eventID: 'addtocart_456_...'})`
- ✅ CAPI fires: Server sends AddToCart with same `event_id`
- ✅ Event data matches (product ID, quantity, value)

**Checkpoint**: Create git commit/tag after successful test
```bash
git add .
git commit -m "✅ CHECKPOINT: AddToCart dual-tracking and deduplication working"
git tag -a v2.0.1-addtocart -m "AddToCart events working correctly"
```

---

### ✅ Test 4: InitiateCheckout Event

**Steps**:
1. Add product to cart
2. Go to checkout page
3. Check browser console for Pixel InitiateCheckout event
4. Check plugin logs for CAPI InitiateCheckout event
5. Check Facebook Test Events - should see ONE InitiateCheckout with matching event_id

**Expected**:
- ✅ Pixel fires: `fbq('track', 'InitiateCheckout', {...}, {eventID: 'checkout_...'})`
- ✅ CAPI fires: Server sends InitiateCheckout with same `event_id`
- ✅ Event data matches (cart value, item count)

**Checkpoint**: Create git commit/tag after successful test
```bash
git add .
git commit -m "✅ CHECKPOINT: InitiateCheckout dual-tracking and deduplication working"
git tag -a v2.0.1-checkout -m "InitiateCheckout events working correctly"
```

---

### ✅ Test 5: Purchase Event (CRITICAL)

**Steps**:
1. Complete a test order (use test payment method)
2. Check browser console for Pixel Purchase event (if timing = "placed")
3. Check plugin logs for CAPI Purchase event
4. Check Facebook Test Events - should see ONE Purchase with matching event_id
5. Verify order meta: `_meta_capi_purchase_tracked` should be set

**Expected**:
- ✅ Pixel fires (if timing = "placed"): `fbq('track', 'Purchase', {...}, {eventID: 'purchase_789'})`
- ✅ CAPI fires: Server sends Purchase with same `event_id: 'purchase_789'`
- ✅ Event IDs match exactly (no timestamp, no random - just order ID)
- ✅ Event data matches (order total, currency, items)

**Checkpoint**: Create git commit/tag after successful test
```bash
git add .
git commit -m "✅ CHECKPOINT: Purchase dual-tracking and deduplication working"
git tag -a v2.0.1-purchase -m "Purchase events working correctly - CRITICAL"
```

---

### ✅ Test 6: Purchase Event Timing "Payment Confirmed"

**Steps**:
1. Change setting: Purchase Event Timing → "When payment is confirmed"
2. Place order with offline payment (COD, bank transfer)
3. Manually change order status to "Processing" or "Completed"
4. Check plugin logs for CAPI Purchase event
5. Check browser console - should NOT fire Pixel Purchase (server-only)

**Expected**:
- ✅ CAPI fires when payment confirmed
- ✅ Pixel does NOT fire (browser-side skipped)
- ✅ Event ID: `purchase_{order_id}`

**Checkpoint**: Create git commit/tag after successful test
```bash
git add .
git commit -m "✅ CHECKPOINT: Purchase timing 'payment confirmed' working"
git tag -a v2.0.1-purchase-timing -m "Purchase event timing working correctly"
```

---

## Verification Methods

### Method 1: Browser Console
```javascript
// Check if Pixel is loaded
typeof fbq !== 'undefined' // Should be true

// Check recent events (if Pixel debug enabled)
fbq('track', 'PageView'); // Manually trigger to test
```

### Method 2: Plugin Logs
- Go to Settings → Meta CAPI → Tools & Logs
- View latest log entries
- Look for:
  - `Generated event ID` entries
  - `Sending event to Facebook` entries
  - `Event sent successfully` entries

### Method 3: Facebook Test Events
- Go to Facebook Events Manager → Test Events tab
- Events should appear in real-time
- Check `event_id` field - should match between Pixel and CAPI
- If you see two events with different `event_id`, deduplication is NOT working

### Method 4: Network Tab
- Filter by `graph.facebook.com` (CAPI requests)
- Filter by `fbevents.js` (Pixel requests)
- Check request payloads for `event_id` values

---

## Common Issues & Solutions

### Issue: Events not appearing in Facebook
**Check**:
- Test Event Code is set correctly
- Pixel ID and Access Token are correct
- Debug logging shows successful API calls
- Network tab shows successful requests

### Issue: Duplicate events in Facebook
**Check**:
- Event IDs match exactly between Pixel and CAPI
- No random components in event IDs
- Purchase events use order ID only (no timestamp)

### Issue: Browser events not firing
**Check**:
- Pixel is injected (check page source for `fbevents.js`)
- JavaScript console shows no errors
- `metaCAPIWooCommerceData` is available (check Network tab for localized script)

### Issue: Server events not firing
**Check**:
- Plugin logs show event generation
- API credentials are correct
- No PHP errors in logs
- WooCommerce hooks are firing

---

## Checkpoint Best Practices

1. **Test one event type at a time**
2. **Verify it works completely before moving on**
3. **Create checkpoint immediately after success**
4. **Use descriptive commit messages**
5. **Tag releases for major milestones**

### Checkpoint Format
```bash
# After each successful test
git add .
git commit -m "✅ CHECKPOINT: [Event Type] dual-tracking and deduplication working"
git tag -a v2.0.1-[event-type] -m "[Event Type] events working correctly"
```

### Major Milestone Checkpoint
```bash
# After all events tested and working
git add .
git commit -m "✅ CHECKPOINT: All events dual-tracking and deduplication working"
git tag -a v2.0.1-stable -m "All tracking events verified and working"
```

---

## Post-Testing

1. **Disable debug logging** (performance)
2. **Remove test event code** (if not needed)
3. **Document any issues found**
4. **Update CHANGELOG.md** with fixes
5. **Create release notes** for stable version

---

Generated: 2025-01-27

