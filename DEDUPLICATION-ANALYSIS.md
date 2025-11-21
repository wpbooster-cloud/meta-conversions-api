# Deduplication Analysis & Timing Review

## Critical Issues Found

### 1. ⚠️ **CRITICAL: Async Events Lose User Context**

**Problem:**
- Events are scheduled via `wp_schedule_single_event()` and processed by WordPress cron
- When cron processes the event, it runs in a different context:
  - No user session
  - No cookies (`$_COOKIE` is empty)
  - No user IP/user agent (cron process IP, not visitor IP)
  - `$_SERVER` data is from cron process, not original request

**Impact:**
- User data (IP, user agent, fbp cookie) will be WRONG for async events
- Meta cannot match browser and server events → **Deduplication fails**

**Current Code:**
```php
// In send_event_async()
wp_schedule_single_event(time(), 'meta_capi_send_event', [$event_data]);

// In async handler
add_action('meta_capi_send_event', function($event_data) {
    $client = new Meta_CAPI_Client($logger);
    $client->send_event($event_data); // ❌ Tries to get IP/user agent from cron context
});
```

**Fix Required:**
- Capture user_data (IP, user agent, cookies) BEFORE scheduling
- Store complete user_data in event_data array
- Async handler must use stored user_data, not try to detect it

---

### 2. ⚠️ **CRITICAL: Event Time Mismatch**

**Problem:**
- Browser: Meta Pixel sets `event_time` when `fbq('track')` is called (immediate)
- Server: For async events, we use `time()` when event is PROCESSED (could be minutes later)
- Even with event_id containing timestamp, we're not extracting it for `event_time`

**Current Code:**
```php
// WooCommerce events use time() instead of extracting from event_id
$event_time = time(); // ❌ Wrong - this is when cron runs, not when event occurred
```

**Impact:**
- Browser event_time: `1635789012` (when user clicked)
- Server event_time: `1635789072` (60 seconds later when cron runs)
- Meta may reject deduplication if timestamps are too far apart

**Fix Required:**
- Extract timestamp from event_id (like PageView does)
- Use extracted timestamp for `event_time`
- Only fallback to `time()` if extraction fails

---

### 3. ⚠️ **Purchase Events Missing fbp/fbc Cookies**

**Problem:**
- Purchase events use `build_user_data($order)` which doesn't include fbp/fbc cookies
- Browser Pixel automatically includes these cookies
- Missing cookies can break deduplication

**Fix Required:**
- Add fbp/fbc cookie reading to `build_user_data()` for Purchase events
- Use current request cookies (from thank-you page), not order meta

---

## Event Flow Analysis

### PageView (✅ Mostly Correct)
1. **Event ID Generation**: `template_redirect` hook (early, before wp_head)
2. **Browser Tracking**: Pixel injected in `wp_head`, uses event ID from static property
3. **Server Tracking**: `wp` hook, extracts timestamp from event_id for event_time
4. **User Data**: Captured at time of request (correct)
5. **Timing**: Browser fires immediately, server sends async (but user_data captured before scheduling)

**Issue**: Server event is async, but user_data is captured before scheduling ✅

---

### AddToCart (❌ Has Issues)
1. **Event ID Generation**: On product page (`template_redirect`) OR when item added
2. **Browser Tracking**: Uses event ID from AJAX fragments or pre-generated ID
3. **Server Tracking**: `woocommerce_add_to_cart` hook, sends async
4. **User Data**: ❌ Captured in async handler (wrong context)
5. **Event Time**: ❌ Uses `time()` instead of extracting from event_id

**Issues**:
- User data captured in cron context (wrong IP/user agent)
- Event time doesn't match browser timing

---

### InitiateCheckout (❌ Has Issues)
1. **Event ID Generation**: `template_redirect` on checkout page
2. **Browser Tracking**: Uses event ID from localized script
3. **Server Tracking**: `wp` hook on checkout page, sends async
4. **User Data**: ❌ Captured in async handler (wrong context)
5. **Event Time**: ❌ Uses `time()` instead of extracting from event_id

**Issues**:
- User data captured in cron context (wrong IP/user agent)
- Event time doesn't match browser timing

---

### Purchase (⚠️ Needs fbp/fbc Cookies)
1. **Event ID Generation**: Uses order ID (static, no timestamp)
2. **Browser Tracking**: Thank-you page, uses order ID
3. **Server Tracking**: `woocommerce_thankyou` hook, sends synchronously
4. **User Data**: ✅ Captured from current request (correct)
5. **Event Time**: Uses `time()` (OK since synchronous)

**Issue**: Missing fbp/fbc cookies in user_data

---

## Meta's Deduplication Requirements

For events to deduplicate, Meta requires:

1. **Same event_id** ✅ (We're doing this correctly)
2. **Same event_time** (within ~48 hours, but closer is better) ⚠️ (Issue with async events)
3. **Matching user_data**:
   - Same IP address ❌ (Lost in async events)
   - Same user agent ❌ (Lost in async events)
   - Same fbp cookie ❌ (Lost in async events, missing in Purchase)
   - Same fbc cookie (optional, but helps)

---

## Recommended Fixes

### Fix 1: Capture User Data Before Scheduling
```php
// In send_event_async() - capture user_data BEFORE scheduling
private function send_event_async(array $event_data): void {
    // CRITICAL: Capture user_data from current request BEFORE scheduling
    if (empty($event_data['user_data'])) {
        $event_data['user_data'] = $this->get_current_user_data();
    }
    
    // Store complete event_data (with user_data) for async handler
    wp_schedule_single_event(time(), 'meta_capi_send_event', [$event_data]);
}
```

### Fix 2: Extract Event Time from Event ID
```php
// Extract timestamp from event_id (like PageView does)
private function extract_timestamp_from_event_id(string $event_id): int {
    $parts = explode('_', $event_id);
    if (count($parts) >= 3) {
        $timestamp_ms = (int) end($parts);
        return (int) floor($timestamp_ms / 1000); // Convert to seconds
    }
    return 0; // Fallback
}

// Use extracted timestamp
$event_time = $this->extract_timestamp_from_event_id($event_id);
if ($event_time === 0) {
    $event_time = time(); // Fallback
}
```

### Fix 3: Add fbp/fbc to Purchase Events
```php
// In build_user_data() for Purchase
if (!empty($_COOKIE['_fbp'])) {
    $user_data['fbp'] = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
}
if (!empty($_COOKIE['_fbc'])) {
    $user_data['fbc'] = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
}
```

---

## Testing Checklist

After fixes, verify:

1. ✅ Browser and server events have same event_id
2. ✅ Browser and server events have same event_time (within 1-2 seconds)
3. ✅ Server event user_data includes correct IP (from original request, not cron)
4. ✅ Server event user_data includes correct user agent (from original request)
5. ✅ Server event user_data includes fbp cookie (matches browser)
6. ✅ Events appear as deduplicated in Meta Events Manager

---

## Timeline

- **Browser Event**: Fires immediately when user interacts
- **Server Event (Async)**: Scheduled immediately, processed by cron (up to 1 minute delay)
- **Critical**: User data must be captured at scheduling time, not processing time

