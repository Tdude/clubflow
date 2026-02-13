# ClubFlow

A booking and calendar plugin for WordPress, built for clubs and associations.

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![License](https://img.shields.io/badge/License-GPL--2.0-green)

## Features

### 📅 Calendar & Events

- Full calendar view (month grid on desktop, 2-week list on mobile)
- Color-coded categories
- Event details: date, time, location, description, featured image
- Click-to-open modal with booking form
- Responsive design with mobile-first approach

### 🎟️ Booking System

- Simple booking: name, email, phone (optional)
- Auto-generated confirmation codes
- Capacity limits with "X spots left" / "Fully booked" display
- Duplicate prevention (same email can't book same event twice)
- Member/non-member pricing support

### 💳 Payment Integration (Optional)

- **Manual** — "Pay at venue"
- **Swish** — QR code + number display (Swedish mobile payments)
- **Klarna** — Checkout integration
- **Stripe** — Card payments via Stripe Checkout

### 📧 Email Confirmation

- Mailchimp integration for booking confirmations
- Includes: event name, date, location, confirmation code

## Shortcodes

```php
// Full calendar
[club_calendar]

// Filtered by category
[club_calendar category="yoga"]

// List view with custom range
[club_calendar view="listRange" list_months="2"]

// Event list
[club_events_list]
[club_events_list limit="5" category="dance"]

// Standalone booking widget (for product pages)
[club_booking id="123"]
```

## Event Modes

| Mode | Description |
|------|-------------|
| **Calendar** | Shows in calendar (default) |
| **Product** | Hidden from calendar, for standalone booking pages |
| **Package** | Hidden from calendar, can link to multiple events |

## Installation

1. Upload `clubflow/` to `/wp-content/plugins/`
2. Activate the plugin
3. Create events under **Events → Add New**
4. Add `[club_calendar]` to any page

## Screenshots

*Coming soon*

## Requirements

- WordPress 5.0+
- PHP 7.4+

## Languages

- 🇬🇧 English
- 🇸🇪 Swedish (Svenska) — full translation included

## Configuration

### Payment Setup

1. Go to **Events → Settings**
2. Enable payments
3. Choose payment method
4. Enter API credentials (Stripe/Klarna) or Swish number

### Mailchimp Setup

1. Go to **Events → Settings → Mailchimp**
2. Enter API key
3. Optionally set audience/list ID

## Development

```bash
# Clone
git clone https://github.com/Tdude/clubflow.git

# The plugin uses vanilla JS and CSS — no build step required
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

GPL-2.0 — see [LICENSE](LICENSE) for details.

## Credits

Built by [Tibor Berki](https://github.com/Tdude)

Uses:
- [FullCalendar](https://fullcalendar.io/) for calendar rendering
- Stripe, Klarna, and Swish APIs for payments
