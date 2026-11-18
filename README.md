# JM Session Booking

A WordPress booking calendar for a photographer, with a few rules that off-the-shelf booking plugins don't handle:

- **Golden-hour weekends.** Weekend sessions are timed from the day's sunset (the 2-hour session ends at sunset by default), calculated for the studio's latitude and longitude.
- **Two weekends a month.** One booking closes its whole weekend, and once 2 weekends in a month are booked, the rest of that month's weekends close.
- **Mini sessions on booked days.** When a full session is paid for, a discounted 1-hour mini session opens on the same day at the same location, ending 30 minutes before the full session.
- **School-year weekdays.** Weekday hours differ between the school year (for example Wednesdays 8:30–11:00) and summer.
- **Extra open times.** The photographer can open any date and time by hand, outside the rules.
- **Pay to book.** Clients pay a retainer through Stripe Checkout (Stripe's hosted page, so no card data touches the site). A paid booking can be sent to Sprout Studio as a new lead through a Zapier webhook.

All numbers above are settings. The calendar picks up the colors of the Kadence theme automatically and falls back to a neutral look on other themes.

## Install

1. Run `python3 tools/build_zip.py` (or zip the `jm-booking/` folder yourself).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. Put the shortcode `[jm_booking]` on a page.
4. Fill in **Bookings → Settings**:
   - **Payment:** the amount charged at booking, a Stripe secret key (a restricted key with only *Checkout Sessions: Write* is enough), and a Stripe webhook pointing at the URL shown on the page, with the events `checkout.session.completed`, `checkout.session.async_payment_succeeded` and `checkout.session.expired`. Online booking stays off until both keys are saved.
   - **Spam protection (recommended):** Cloudflare Turnstile site and secret keys.
   - **Sprout Studio (optional):** a Zapier "Catch Hook" URL; the Zap's action is *Sprout Studio → Create Lead*.

Requires WordPress 6.4+ and PHP 8.0+. WordPress needs to be able to send email for the confirmation emails.

## How a booking works

1. The client picks a time and fills in the form, then is sent to Stripe Checkout.
2. The time is held for 30 minutes while they pay. Pressing Back on Stripe releases it at once; otherwise it is released when the checkout expires.
3. Stripe calls the webhook, the booking is confirmed, the client and the photographer get an email, and the booking goes to Zapier.

The **Bookings** admin page lists bookings with links to each payment in Stripe, and has forms to open extra times and to add bookings made outside the website. Cancelling a booking there does not refund it; refunds are done in Stripe.

## Privacy and security

- Bookings are deleted automatically a year after the session date (setting; 0 keeps them).
- A location typed by a client is never shown to other clients; only locations from the settings list are.
- Webhooks are verified with Stripe's signature. Booking is limited per email address and per visitor IP.

## Tests

`tests/run.sh` starts a throwaway WordPress, MariaDB and a small mock of the Stripe API in Docker, runs the test suites against them, and removes everything afterwards. `tests/run.sh --browser` also runs the pay flow in headless Chrome.

The tests use fixed dates in December 2026 and will need new dates once those are in the past.

## License

MIT, see [LICENSE](LICENSE).
