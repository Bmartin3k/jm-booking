# JM Session Booking

A WordPress booking calendar for a photographer, with a few rules that off-the-shelf booking plugins don't handle:

- **Golden-hour weekends.** Weekend sessions are timed from the day's sunset (the 2-hour session ends at sunset by default), calculated for the studio's latitude and longitude.
- **Two weekends a month.** One booking closes its whole weekend, and once 2 weekends in a month are booked, the rest of that month's weekends close.
- **Mini sessions on booked days.** When a full session is paid for, a discounted 1-hour mini session opens on the same day at the same location, ending 30 minutes before the full session.
- **School-year weekdays.** Weekday hours differ between the school year (for example Wednesdays 8:30–11:00) and summer.
- **Extra open times.** The photographer can open any date and time by hand, outside the rules.
- **Pay to book.** Clients pay a retainer through Stripe Checkout (Stripe's hosted page, so no card data touches the site). A paid booking can be sent to Sprout Studio as a new lead through a Zapier webhook.
- **Contracts with e-signature.** Before paying, the client reads the contract with their details filled in, ticks two consent boxes and types their name. The exact text, its SHA-256 fingerprint, the signature, time, IP and browser are stored, and the client is emailed a PDF copy.
- **Balance payments.** The rest of the session price is invoiced automatically before the session (optionally in monthly parts), paid by card on a payment page whose link never expires, with a reminder on the due date, a receipt, and an overdue notice to the photographer.
- **Automatic emails.** Prep tips before the session, a reminder with the time and place, a thank-you afterwards and a review request, each with its own timing and editable text.
- **Google Calendar, both ways.** Anything on her calendar closes that time (on weekends, the whole day), and paid bookings are added to her calendar and removed again if cancelled.

All numbers above are settings. The calendar picks up the colors of the Kadence theme automatically and falls back to a neutral look on other themes.

## Install

1. Run `python3 tools/build_zip.py` (or zip the `jm-booking/` folder yourself).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. Put the shortcode `[jm_booking]` on a page.
4. Fill in **Bookings → Settings**:
   - **Payment:** the amount charged at booking, a Stripe secret key (a restricted key with only *Checkout Sessions: Write* is enough), and a Stripe webhook pointing at the URL shown on the page, with the events `checkout.session.completed`, `checkout.session.async_payment_succeeded` and `checkout.session.expired`. Online booking stays off until both keys are saved.
   - **Contract (optional):** tick "Clients sign the contract before they pay" and paste the contract text. Plain text with `#` headings and fill-in fields such as `{client_name}`, `{session_date}`, `{location}`, `{price}` and `{retainer}` (the full list is on the settings page). A separate mini-session contract is optional.
   - **Balance payments (optional):** the "price shown" for a session must be a plain amount such as `$350`; the balance is that minus the retainer. Only bookings paid after switching this on are invoiced, so balances collected elsewhere are never billed twice. The Bookings list has send now, mark paid (cash, Venmo), change amount and void for each payment.
   - **Automatic emails (optional):** each email is off until ticked. Edit the text, then use "Save and send me a test". Emails go only to confirmed bookings, each one once; anyone who books after an email's send time skips it (the reminder still goes if the session is more than 12 hours away), and turning emails on never mails past clients.
   - **Google Calendar (optional):** create a Google Cloud project with the Google Calendar API enabled, add a service account and download its JSON key, paste the key into the settings, share the calendar with the service account's email ("Make changes to events"), and enter the calendar ID (the Gmail address for a main calendar). "Check Google Calendar" tests reading and writing.
   - **Spam protection (recommended):** Cloudflare Turnstile site and secret keys.
   - **Sprout Studio (optional):** a Zapier "Catch Hook" URL; the Zap's action is *Sprout Studio → Create Lead*.

Requires WordPress 6.4+ and PHP 8.0+. WordPress must be able to send email: use an SMTP plugin such as WP Mail SMTP, and check it with "Send test confirmation" on the settings page. Timed tasks (automatic emails, releasing unpaid holds, clean-up) use WP-Cron, which runs when the site gets visits; on a quiet site, add a real cron job that loads `wp-cron.php` every 15 minutes. If automatic emails fail 3 times, a red banner in wp-admin says so and offers "Retry them now".

## How a booking works

1. The client picks a time and fills in the form, signs the contract (when contracts are on), then is sent to Stripe Checkout.
2. The time is held for 30 minutes while they pay. Pressing Back on Stripe releases it at once; otherwise it is released when the checkout expires.
3. Stripe calls the webhook, the booking is confirmed, the client and the photographer get an email, the booking goes to Zapier, and it is added to her Google Calendar.

Her Google Calendar is read when the calendar loads (cached for 5 minutes) and again, fresh, right before a booking is saved. Events marked "Free" are ignored. If Google can't be reached, the last known busy days stay closed.

The **Bookings** admin page lists bookings with links to each payment in Stripe, and has forms to open extra times and to add bookings made outside the website. Cancelling a booking there does not refund it; refunds are done in Stripe.

## Privacy and security

- Bookings are deleted automatically a year after the session date (setting; 0 keeps them). Signed contracts are legal records and are kept separately, 6 years by default.
- Signed contracts can only be opened by the client through their private link, or by an admin. The page shows whether the text still matches its fingerprint.
- A location typed by a client is never shown to other clients; only locations from the settings list are.
- Webhooks are verified with Stripe's signature (and refused after 5 minutes). Booking is limited per email address and per visitor IP; only the browser that started a checkout can restart it for the same email.
- A balance payment has at most one open Stripe checkout: opening the pay page again, changing the amount, marking it paid or voiding it closes the previous one. If a client still manages to pay twice, the photographer is emailed which payment to refund.
- Private pages (signed contract, payment) are not indexed, cannot be shown inside another site's frame, and send no referrer.
- Secrets can be kept out of the database (and its backups) by defining them in `wp-config.php`; they then override the settings page:

  ```php
  define('JMB_STRIPE_SECRET', 'rk_live_...');
  define('JMB_STRIPE_WEBHOOK', 'whsec_...');
  define('JMB_GCAL_JSON', '{"type":"service_account", ...}');   // the whole key file
  define('JMB_TURNSTILE_SITE', '0x4...');
  define('JMB_TURNSTILE_SECRET', '0x4...');
  ```
- `tests/test_security.py` attacks the plugin (injection, script in every field, forged and replayed webhooks, double payments, other people's checkouts, access by non-admins, secrets on pages, request flooding, framing).

## Tests

`tests/run.sh` starts a throwaway WordPress, MariaDB and a small mock of the Stripe and Google Calendar APIs in Docker, runs the test suites against them, and removes everything afterwards. `tests/run.sh --browser` also runs the pay and contract-signing flows in headless Chrome. `KEEP=1 tests/run.sh` leaves the test site running at http://127.0.0.1:18080 (admin / admin) for poking around. `tests/mu/mail-log.php` records outgoing email in the test site only.

The tests use fixed dates in December 2026 and will need new dates once those are in the past.

## License

MIT, see [LICENSE](LICENSE).
