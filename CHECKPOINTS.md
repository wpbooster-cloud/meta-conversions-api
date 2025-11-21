# Plugin Checkpoints

This document tracks working states of the plugin for easy rollback.

## Checkpoint: 2025-11-21 - All Events Sending

**Date:** November 21, 2025  
**Status:** ✅ All events sending (deduplication pending)  
**Git Commit:** `a212c18`

### Current State

**Events Status:**
- ✅ **PageView**: Server + Browser (sending, NOT deduplicated)
- ✅ **AddToCart**: Server (sending twice, deduplicated) + Browser (sending, NOT deduplicated)
- ✅ **InitiateCheckout**: Server + Browser (sending, NOT deduplicated)
- ✅ **Purchase**: Server + Browser (sending, NOT deduplicated)

### What's Working

1. **Server Events (CAPI)**
   - All events successfully sending to Facebook Conversions API
   - Events received and accepted by Meta (`events_received: 1`)
   - IP and user agent correctly captured from original request (not cron process)
   - Test event code working for server-side events

2. **Browser Events (Pixel)**
   - Pixel injecting correctly
   - All WooCommerce events firing in browser
   - Scripts loading with correct dependencies

3. **IP Detection**
   - Fixed priority order: `HTTP_CLIENT_IP` before `HTTP_X_REAL_IP` for universal compatibility
   - Consistent across all classes (Client, Tracking, WooCommerce, Coordinator)

4. **Event ID Generation**
   - Fixed `generateEventId` to handle empty `uniqueId` (no double underscores)
   - Minified version matches unminified logic

### Known Issues (To Fix Next)

1. **Deduplication Not Working**
   - Browser and server events not matching for deduplication
   - Need to verify:
     - Event IDs match exactly between browser and server
     - Event times match (currently extracting from event_id timestamp)
     - User data (IP, user agent, fbp cookie) matches

2. **AddToCart Sending Twice (Server)**
   - Server-side AddToCart event being sent twice
   - Need to investigate duplicate hook firing

### Files Modified in This Checkpoint

- `includes/class-meta-capi-client.php` - IP detection priority, user_data handling
- `includes/class-meta-capi-tracking.php` - IP/user agent capture, event_time extraction
- `includes/class-meta-capi-woocommerce.php` - IP detection, event_time extraction
- `includes/class-meta-capi-pixel.php` - Reverted test event code changes
- `includes/class-meta-capi-scripts.php` - Script dependencies
- `includes/class-meta-capi-settings.php` - Test event code note
- `includes/class-meta-capi-coordinator.php` - IP detection priority
- `assets/js/woocommerce-events.js` - generateEventId fix
- `assets/js/woocommerce-events.min.js` - generateEventId fix

### How to Rollback

```bash
cd meta-pixel-conversions-api
git log --oneline  # Find the checkpoint commit
git checkout <commit-hash>  # Rollback to this checkpoint
```

### Next Steps

1. Fix deduplication issues:
   - Verify event IDs match exactly
   - Verify event times match
   - Verify user data matches (IP, UA, fbp)
2. Fix duplicate AddToCart server events
3. Test all events in Facebook Test Events tab
4. Verify deduplication working

---

