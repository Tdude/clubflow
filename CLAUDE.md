# ClubFlow - WordPress Plugin

## Overview
WordPress plugin for club/event booking with payment integration (Stripe, Swish, Klarna).
Swedish-language focused (SEK currency, Swedish locale).

## Key Architecture
- **Main entry:** `clubflow.php`
- **Classes:** `includes/class-clubflow-*.php`
- **Payment settings:** stored in WP option `clubflow_payment_settings` (see `ClubFlow_Payment::OPTION_KEY`)
- **Stripe integration:** `ClubFlow_Stripe` reads keys from unified payment settings
- **Booking CPT:** `club_booking` with meta prefix `_clubflow_booking_*`

## Important: Third-Party API Keys
- Stripe secret keys MUST start with `sk_test_` or `sk_live_`
- Stripe publishable keys MUST start with `pk_test_` or `pk_live_`
- Stripe webhook secrets start with `whsec_`
- Never hardcode or guess API keys; always validate format before use
- When debugging "Invalid API Key" errors, check the stored value in `clubflow_payment_settings` option first

## Development Notes
- Uses `wp_remote_request` for Stripe API calls (no Stripe PHP SDK)
- Payment amounts are in SEK, converted to ore (smallest unit) for Stripe
- Settings use a `preserve_or_sanitize` pattern to avoid wiping keys when submitting partial forms
