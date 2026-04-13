
Payment Flow Analysis Report

  1. Why "email" entries show status "completed"

  These are not actual payments. They are email-send log entries created by ClubFlow_Mailchimp::send_booking_confirmation() at class-clubflow-mailchimp.php:195-204.

  When a confirmation email is sent via wp_mail(), Mailchimp logs it to the payment log with:
  - method = 'email'
  - status = 'completed' (if wp_mail() returned true) or 'failed'
  - amount = 0

  This is a design issue, not a bug. Email dispatch records are being stored in the same club_payment_log CPT as actual financial transactions. The code at class-clubflow-payment.php:586-591 already tries to exclude email entries from
  get_booking_payment() queries, which confirms this was known to cause confusion.

  Recommendation: Either stop logging email sends in the payment log, or add a visual distinction in the admin list (e.g., a different icon/label like "Notification" instead of showing it alongside payments).

  ---
  2. Why payments appear as "Pending" then "Confirmed" ~2 minutes apart

  This is the expected Stripe flow, but it creates duplicate log entries:

  Step 1 — Booking created (class-clubflow-booking.php:852-864): When a user submits the form, a Stripe Checkout session is created and a payment log is written with status: 'pending'.

  Step 2 — User returns from Stripe (~1-3 min later): Stripe redirects to the success_url with ?booking_confirmed=1&booking_id=X. The maybe_show_confirmation_toast() method (class-clubflow-booking.php:2063-2074) calls
  ClubFlow_Stripe::verify_and_confirm(), which calls confirm_booking_payment() (class-clubflow-stripe.php:386-429). This writes a second log entry with status: 'completed'.

  The original "pending" log entry is never updated — confirm_booking_payment() calls ClubFlow_Payment::log_payment() (creates new) instead of ClubFlow_Payment::update_payment_status() (updates existing). So you get two rows: one "pending", one
  "completed".

  There's also a third potential confirmation path — the Stripe webhook (checkout.session.completed) also calls confirm_booking_payment(). The idempotency guard at class-clubflow-stripe.php:388-391 prevents the booking status from being set twice, but
  in a race condition between the return-redirect and webhook, you could theoretically get the webhook arriving just before the return handler. The idempotency guard is on booking meta, not on payment log creation, so the log entry would still be
  written by whichever path runs first.

  Recommendation: In confirm_booking_payment(), find and update the existing pending log entry instead of creating a new one. Use update_payment_status() on the existing log.

  ---
  3. Stripe ~8-second delay before redirect back to site

  ROOT CAUSE FOUND (2026-04-13): This is NOT a server timeout or workload issue.

  Per Stripe's fulfillment docs (https://docs.stripe.com/checkout/fulfillment):
  "When you have a webhook endpoint set up to listen for checkout.session.completed
  events and you set a success_url, Checkout waits up to 10 seconds for your server
  to respond to the webhook event delivery before redirecting your customer."

  Stripe intentionally HOLDS the user on the payment page for up to 10 seconds,
  waiting for our webhook handler to return HTTP 200. Our webhook handler was doing
  all of this synchronously before returning:
  1. Re-fetching the session from Stripe API (api_request with 10s timeout)
  2. Updating multiple post_meta fields
  3. Creating a payment log entry
  4. Sending confirmation emails (ClubFlow_Notifications)
  5. Firing clubflow_booking_payment_confirmed action (triggers Mailchimp)

  That waterfall easily takes 5-8 seconds, which is exactly the delay users see.

  FIX APPLIED: Deferred all heavy work to a `shutdown` hook in handle_webhook().
  The webhook now returns HTTP 200 immediately after signature verification.
  The Stripe API call, booking confirmation, and email sending run AFTER the
  response is flushed (using fastcgi_finish_request() when available on PHP-FPM).
  Changed in: includes/class-clubflow-stripe.php, lines 326-357.

  Safety net: The return-redirect path (maybe_show_confirmation_toast → verify_and_confirm)
  still independently confirms the booking on the success_url page load, so even if
  the deferred webhook work fails, the booking will be confirmed.

  Remaining concern — abandoned sessions:
  - If the user closes the tab or hits back, the booking remains pending_payment forever
  - There's no cleanup mechanism. Stripe sessions expire after 24h but our side never learns.
  - Recommendation: Add a WP-Cron job to expire pending_payment bookings older than 1 hour

  ---
  4. Mobile UX concerns

  Looking at class-clubflow-calendar.js:664-677:
  - The Stripe flow shows a "Skickar dig till betalning..." message and a link, then auto-redirects after 500ms
  - On slow mobile connections, the redirect fires before the page fully renders, so users may see a blank flash
  - If the redirect fails (popup blockers, PWA context), the fallback link is a plain <a> tag which is fine
  - The confirmation toast on return (class-clubflow-booking.php:2089-2111) cleans the URL immediately via replaceState, which means if the user refreshes, the confirmation context is lost

  No critical mobile layout bug found, but the 500ms auto-redirect is aggressive. A 1500-2000ms delay with a visible countdown would be more user-friendly on mobile.

  ---
  Summary of recommended fixes (priority order)

  ┌─────┬────────────────────────────────────────────────────┬──────────┬───────────────────────────────────────────────────────────────────┐
  │  #  │                       Issue                        │ Severity │                                Fix                                │
  ├─────┼────────────────────────────────────────────────────┼──────────┼───────────────────────────────────────────────────────────────────┤
  │ 1   │ Duplicate pending+completed log entries for Stripe │ HIGH     │ Update existing log entry instead of creating new one             │
  ├─────┼────────────────────────────────────────────────────┼──────────┼───────────────────────────────────────────────────────────────────┤
  │ 2   │ Email sends logged as "payments"                   │ MEDIUM   │ Log to a separate mechanism, or filter from payment log UI        │
  ├─────┼────────────────────────────────────────────────────┼──────────┼───────────────────────────────────────────────────────────────────┤
  │ 3a  │ Stripe ~8s redirect delay (webhook blocking)       │ HIGH     │ FIXED: Deferred webhook work to shutdown hook                     │
  ├─────┼────────────────────────────────────────────────────┼──────────┼───────────────────────────────────────────────────────────────────┤
  │ 3b  │ No cleanup of abandoned Stripe sessions            │ MEDIUM   │ Add WP-Cron to expire stale pending_payment bookings              │
  ├─────┼────────────────────────────────────────────────────┼──────────┼───────────────────────────────────────────────────────────────────┤
  │ 4   │ Webhook + return-redirect race on log creation     │ LOW      │ Add log-level idempotency (check if completed log already exists) │
  ├─────┼────────────────────────────────────────────────────┼──────────┼───────────────────────────────────────────────────────────────────┤
  │ 5   │ Aggressive 500ms Stripe redirect on mobile         │ LOW      │ Increase to 1500ms with visual feedback                           │
  └─────┴────────────────────────────────────────────────────┴──────────┴───────────────────────────────────────────────────────────────────┘