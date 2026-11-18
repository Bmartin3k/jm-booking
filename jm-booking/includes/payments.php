<?php
/**
 * Balance payments: what is left after the retainer, paid online before the session.
 *
 * - Created when a booking is paid (only bookings paid after the feature was switched on, so balances
 *   already being collected elsewhere are never billed twice). Balance = session price - retainer.
 * - Optionally split into up to 4 monthly parts, the last due N days before the session; fewer parts
 *   when there is not enough time.
 * - Each part is emailed send_days before it is due, with a link to /?jmb_pay=<token>. That page makes
 *   a fresh Stripe Checkout on every click, so the link never expires. Reminder on the due date,
 *   overdue notice to the photographer the day after. Stripe's webhook marks parts paid; the client
 *   gets a receipt.
 */

if (!defined('ABSPATH')) exit;

function jmb_payments_table() { global $wpdb; return $wpdb->prefix . 'jmb_payments'; }

function jmb_payments_install() {
	global $wpdb;
	$t = jmb_payments_table();
	$c = $wpdb->get_charset_collate();
	dbDelta("CREATE TABLE $t (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id BIGINT UNSIGNED NOT NULL,
		part TINYINT UNSIGNED NOT NULL DEFAULT 1,
		parts TINYINT UNSIGNED NOT NULL DEFAULT 1,
		amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
		due_date DATE NOT NULL,
		status VARCHAR(10) NOT NULL DEFAULT 'open',
		token CHAR(32) NOT NULL DEFAULT '',
		stripe_session VARCHAR(255) NOT NULL DEFAULT '',
		sessions TEXT NULL,
		payment_id VARCHAR(255) NOT NULL DEFAULT '',
		paid_at DATETIME NULL,
		paid_note VARCHAR(190) NOT NULL DEFAULT '',
		invoice_sent_at DATETIME NULL,
		reminder_sent_at DATETIME NULL,
		overdue_noted_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY booking_id (booking_id),
		KEY token (token),
		KEY status_due (status, due_date)
	) $c;");
}

function jmb_pay_defs() {
	return [
		'invoice' => [
			'label'   => 'Invoice',
			'subject' => 'Your balance for {session_date}: {amount} due {due_date}',
			'body'    => "Hi {first_name},\n\nHere is the {part_label}balance for your {session_type} on {session_date}.\n\nAmount: {amount}\nDue: {due_date}\n\nPay securely by card here:\n{pay_link}\n\nQuestions? Just reply to this email.\n\n{business_name}",
		],
		'reminder' => [
			'label'   => 'Reminder (on the due date)',
			'subject' => 'Reminder: {amount} is due today',
			'body'    => "Hi {first_name},\n\nA friendly reminder that {amount} for your {session_type} on {session_date} is due today.\n\nYou can pay here:\n{pay_link}\n\nIf you've already paid, thank you, and please ignore this email.\n\n{business_name}",
		],
		'receipt' => [
			'label'   => 'Receipt',
			'subject' => 'Payment received, thank you!',
			'body'    => "Hi {first_name},\n\nThank you! We received your payment of {amount} for your {session_type} on {session_date}.\n\nPaid so far: {paid_total} of {session_total}. Still to pay: {remaining}.\n\n{business_name}",
		],
	];
}

function jmb_pay_conf($key, $s = null) {
	$s   = $s ?: jmb_settings();
	$def = jmb_pay_defs()[$key];
	$c   = is_array($s['pay_emails'][$key] ?? null) ? $s['pay_emails'][$key] : [];
	return ['subject' => ($c['subject'] ?? '') ?: $def['subject'], 'body' => ($c['body'] ?? '') ?: $def['body']];
}

/** Session price in cents from the "price shown" text, or 0 when it is not a plain amount. */
function jmb_price_cents($kind, $s) { return jmb_cents($kind === 'mini' ? $s['mini_price'] : $s['full_price']); }

/** The balance a booking of this kind will have (price - retainer), for contracts and settings. */
function jmb_balance_cents($kind, $s) { return max(0, jmb_price_cents($kind, $s) - jmb_charge_cents($kind, $s)); }

function jmb_payments_for($booking_id) {
	global $wpdb;
	return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . jmb_payments_table() . ' WHERE booking_id = %d ORDER BY part', $booking_id), ARRAY_A);
}

/**
 * Plan the balance for a booking: [[amount_cents, due_date], ...]. Empty when there is nothing to collect.
 * Parts are monthly, the last one due `pay_due_days` before the session; the first is never earlier than a week from today.
 */
function jmb_balance_plan($b, $s, $today = null) {
	$total   = jmb_price_cents($b['kind'], $s);
	$balance = $total - (int) $b['amount_cents'];
	if ($total <= 0 || $balance < 50) return [];
	$today = $today ?: jmb_now()->setTime(0, 0);
	$last  = jmb_day($b['date'])->modify('-' . max(0, (int) $s['pay_due_days']) . ' days');
	if ($last < $today) $last = $today;
	$room  = (int) floor(($last->getTimestamp() - $today->modify('+7 days')->getTimestamp()) / (30 * DAY_IN_SECONDS));
	$n     = max(1, min(max(1, (int) $s['pay_parts']), 1 + max(0, $room)));
	$each  = intdiv($balance, $n);
	$plan  = [];
	for ($i = 0; $i < $n; $i++) {
		$amount = $each + ($i === 0 ? $balance - $each * $n : 0);   // any odd cents go on the first part
		$plan[] = [$amount, $last->modify('-' . (30 * ($n - 1 - $i)) . ' days')->format('Y-m-d')];
	}
	return $plan;
}

/** Create the balance payments for a freshly paid booking (once; only when switched on, only for bookings paid after that). */
function jmb_balance_create($b) {
	global $wpdb;
	$s = jmb_settings();
	if (empty($s['pay_on']) || $b['source'] !== 'web' || jmb_payments_for((int) $b['id'])) return 0;
	$since = (int) get_option('jmb_pay_since');
	$paid  = strtotime(($b['confirmed_at'] ?: $b['created_at']) . ' UTC');
	if (!$since || $paid < $since) return 0;
	$plan = jmb_balance_plan($b, $s);
	foreach ($plan as $i => [$amount, $due]) {
		$wpdb->insert(jmb_payments_table(), [
			'booking_id' => (int) $b['id'], 'part' => $i + 1, 'parts' => count($plan), 'amount_cents' => $amount,
			'due_date' => $due, 'status' => 'open', 'token' => wp_generate_password(32, false), 'created_at' => jmb_utc_now(),
		]);
	}
	return count($plan);
}

function jmb_pay_url($p) { return add_query_arg('jmb_pay', $p['token'], home_url('/')); }

/** Fill-in fields for payment emails. */
function jmb_pay_fields($p, $b, $s) {
	$all   = $p['booking_id'] ? jmb_payments_for((int) $p['booking_id']) : [$p];
	$total = jmb_price_cents($b['kind'], $s) ?: ((int) $b['amount_cents'] + array_sum(array_map(function ($x) { return $x['status'] === 'void' ? 0 : (int) $x['amount_cents']; }, $all)));
	$paid  = (int) $b['amount_cents'] + array_sum(array_map(function ($x) { return $x['status'] === 'paid' ? (int) $x['amount_cents'] : 0; }, $all));
	return jmb_email_fields($b, $s) + [
		'{amount}'        => jmb_money((int) $p['amount_cents'], $s),
		'{due_date}'      => jmb_day($p['due_date'])->format('l, F j'),
		'{pay_link}'      => $p['token'] ? jmb_pay_url($p) : '(the client\'s payment link appears here)',
		'{part_label}'    => (int) $p['parts'] > 1 ? 'payment ' . (int) $p['part'] . ' of ' . (int) $p['parts'] . ' of the ' : '',
		'{session_total}' => jmb_money($total, $s),
		'{paid_total}'    => jmb_money($paid, $s),
		'{remaining}'     => jmb_money(max(0, $total - $paid), $s),
	];
}

function jmb_pay_send($key, $p, $b, $to = null, $test = false) {
	$s = jmb_settings();
	$c = jmb_pay_conf($key, $s);
	$f = jmb_pay_fields($p, $b, $s);
	if (!$b['email'] && !$to) return false;
	return wp_mail($to ?: $b['email'], ($test ? '[Test] ' : '') . strtr($c['subject'], $f), strtr($c['body'], $f));
}

/** Hourly: send invoices and reminders that are due, and tell her about overdue payments. */
function jmb_run_payments() {
	global $wpdb;
	$s = jmb_settings();
	$count = ['invoices' => 0, 'reminders' => 0, 'overdue' => 0];
	if (empty($s['pay_on'])) return $count;
	$t     = jmb_payments_table();
	$today = jmb_now()->format('Y-m-d');
	$rows  = $wpdb->get_results("SELECT * FROM $t WHERE status = 'open'", ARRAY_A);
	foreach ($rows as $p) {
		$b = jmb_get((int) $p['booking_id']);
		if (!$b || $b['status'] !== 'confirmed') continue;
		$send_on = jmb_day($p['due_date'])->modify('-' . max(0, (int) $s['pay_send_days']) . ' days')->format('Y-m-d');
		if (!$p['invoice_sent_at'] && $send_on <= $today) {
			if (jmb_pay_send('invoice', $p, $b)) { $wpdb->update($t, ['invoice_sent_at' => jmb_utc_now()], ['id' => $p['id']]); $count['invoices']++; }
			continue;   // never invoice and remind in the same run
		}
		if ($p['invoice_sent_at'] && !$p['reminder_sent_at'] && !empty($s['pay_remind']) && $p['due_date'] <= $today
			&& strtotime($p['invoice_sent_at'] . ' UTC') < time() - 20 * HOUR_IN_SECONDS) {
			if (jmb_pay_send('reminder', $p, $b)) { $wpdb->update($t, ['reminder_sent_at' => jmb_utc_now()], ['id' => $p['id']]); $count['reminders']++; }
			continue;
		}
		if ($p['due_date'] < $today && !$p['overdue_noted_at']) {
			$wpdb->update($t, ['overdue_noted_at' => jmb_utc_now()], ['id' => $p['id']]);
			jmb_mail_admin("Payment overdue: {$b['name']}, " . jmb_money((int) $p['amount_cents'], $s),
				"{$b['name']} has not paid " . jmb_money((int) $p['amount_cents'], $s) . ' (due ' . jmb_day($p['due_date'])->format('F j') . ") for the {$b['kind_label']} on {$b['date_pretty']}.\n\n"
				. "Their payment link: " . jmb_pay_url($p) . "\n" . admin_url('admin.php?page=jmb'));
			$count['overdue']++;
		}
	}
	return $count;
}
add_action('jmb_auto_emails', 'jmb_run_payments', 20);

/** Stripe webhook for balance payments (metadata jmb_payment). Returns a response array, or null when not ours. */
function jmb_pay_webhook($type, $obj) {
	global $wpdb;
	$pid = (int) ($obj['metadata']['jmb_payment'] ?? 0);
	if (!$pid) return null;
	$t = jmb_payments_table();
	$p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $pid), ARRAY_A);
	$sid   = (string) ($obj['id'] ?? '');
	$known = $p ? preg_split('/\s+/', trim($p['stripe_session'] . ' ' . (string) $p['sessions']), -1, PREG_SPLIT_NO_EMPTY) : [];
	if (!$p || $sid === '' || !in_array($sid, $known, true)) return ['ok' => true, 'ignored' => 'unknown payment session'];
	if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) return ['ok' => true, 'ignored' => $type];
	if (($obj['payment_status'] ?? '') !== 'paid') return ['ok' => true, 'waiting' => 'payment not finished'];
	$pi     = is_string($obj['payment_intent'] ?? null) ? $obj['payment_intent'] : '';
	$amount = (int) ($obj['amount_total'] ?? $p['amount_cents']);
	if ($p['status'] === 'paid') {
		if ($pi === '' || $pi === $p['payment_id'] || strpos($p['paid_note'], $pi) !== false) return ['ok' => true, 'already' => true];   // Stripe retrying
		// a second, different payment for the same thing (e.g. two tabs): record it and ask her to refund one
		$wpdb->update($t, ['paid_note' => mb_substr(trim($p['paid_note'] . ' | ALSO PAID ' . $pi), 0, 190)], ['id' => $pid]);
		$b = jmb_get((int) $p['booking_id']);
		jmb_mail_admin('Balance paid twice: please refund one' . ($b ? " ({$b['name']})" : ''),
			($b ? "{$b['name']} " : 'A client ') . 'paid ' . jmb_money($amount, jmb_settings()) . " a second time for the same balance payment.\n\n"
			. "First payment: {$p['payment_id']}\nSecond payment: $pi\n\nRefund one of them in Stripe: https://dashboard.stripe.com/payments/$pi\n" . admin_url('admin.php?page=jmb&show=all'));
		return ['ok' => true, 'duplicate' => true];
	}
	$wpdb->update($t, ['status' => 'paid', 'paid_at' => jmb_utc_now(), 'amount_cents' => $amount,
		'payment_id' => $pi, 'paid_note' => $p['status'] === 'void' ? 'paid after it was voided' : 'Stripe'], ['id' => $pid]);
	$p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $pid), ARRAY_A);
	$b = jmb_get((int) $p['booking_id']);
	if ($b) {
		jmb_pay_send('receipt', $p, $b);
		jmb_mail_admin("Balance paid: {$b['name']}, " . jmb_money($amount, jmb_settings()), "{$b['name']} paid " . jmb_money($amount, jmb_settings()) . " for the {$b['kind_label']} on {$b['date_pretty']}.\n" . admin_url('admin.php?page=jmb'));
	}
	return ['ok' => true, 'paid' => $pid];
}

/** Close the payment's current Stripe checkout so it can no longer be paid (new checkout, amount change, paid in cash, void). */
function jmb_pay_expire($p) {
	if (!empty($p['stripe_session'])) jmb_stripe('POST', '/v1/checkout/sessions/' . rawurlencode($p['stripe_session']) . '/expire');
}

/** Cancelling a booking voids what is still open. */
function jmb_pay_void_open($booking_id) {
	global $wpdb;
	foreach (jmb_payments_for($booking_id) as $p) if ($p['status'] === 'open') jmb_pay_expire($p);
	$wpdb->query($wpdb->prepare('UPDATE ' . jmb_payments_table() . " SET status = 'void' WHERE booking_id = %d AND status = 'open'", $booking_id));
}

/* ------------------------------------------------------ client pay page */

add_action('template_redirect', function () {
	global $wpdb;
	if (!isset($_GET['jmb_pay'])) return;
	nocache_headers();
	jmb_private_page_headers();
	header('X-Robots-Tag: noindex, nofollow', true);
	header('Referrer-Policy: no-referrer', true);
	$token = sanitize_text_field(wp_unslash($_GET['jmb_pay']));
	$p = preg_match('/^[A-Za-z0-9]{32}$/', $token) ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . jmb_payments_table() . ' WHERE token = %s', $token), ARRAY_A) : null;
	$b = $p ? jmb_get((int) $p['booking_id']) : null;
	if (!$p || !$b) { status_header(404); echo jmb_pay_page('Link not found', '<p>This payment link is not valid. Please check the link in your email, or reply to it and we will help.</p>'); exit; }
	$s     = jmb_settings();
	$money = jmb_money((int) $p['amount_cents'], $s);
	$what  = esc_html($b['kind_label'] . ', ' . $b['date_pretty']) . ((int) $p['parts'] > 1 ? ' · payment ' . (int) $p['part'] . ' of ' . (int) $p['parts'] : '');

	if ($p['status'] === 'paid') { echo jmb_pay_page('Paid, thank you!', '<p class="sum">' . $what . '</p><p>We received ' . esc_html($money) . '. A receipt was emailed to you.</p>'); exit; }
	if ($p['status'] === 'void' || $b['status'] !== 'confirmed') { echo jmb_pay_page('Nothing to pay', '<p>This payment is no longer due. If you have questions, reply to the email you got from us.</p>'); exit; }

	if (!empty($_GET['paid'])) {   // back from Stripe; the webhook usually lands within seconds
		$tries = min(10, (int) ($_GET['t'] ?? 0));
		$again = $tries < 10 ? '<meta http-equiv="refresh" content="2;url=' . esc_url(add_query_arg(['jmb_pay' => $p['token'], 'paid' => 1, 't' => $tries + 1], home_url('/'))) . '">' : '';
		echo jmb_pay_page('Thank you!', '<p class="sum">' . $what . '</p><p>Your payment of ' . esc_html($money) . ' is being confirmed. A receipt will be emailed to you shortly; there is no need to pay again.</p>', $again);
		exit;
	}
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!jmb_stripe_ready($s)) { echo jmb_pay_page('Online payment is not available', '<p>Please reply to the email you got from us and we will sort it out.</p>'); exit; }
		$rl = 'jmb_pp_' . (int) $p['id'];
		$n  = (int) get_transient($rl);
		if ($n >= 10) { status_header(429); echo jmb_pay_page('Please wait a little', '<p>Too many payment attempts for this link. Please try again in an hour, or reply to our email.</p>'); exit; }
		set_transient($rl, $n + 1, HOUR_IN_SECONDS);
		jmb_pay_expire($p);   // only one open checkout per payment, so it cannot be paid twice
		$url  = jmb_pay_url($p);
		$sess = jmb_stripe('POST', '/v1/checkout/sessions', [
			'mode' => 'payment', 'customer_email' => $b['email'], 'client_reference_id' => 'pay-' . (int) $p['id'],
			'expires_at' => time() + 30 * MINUTE_IN_SECONDS,
			'success_url' => add_query_arg('paid', 1, $url), 'cancel_url' => $url,
			'metadata' => ['jmb_payment' => (int) $p['id'], 'jmb_booking' => (int) $b['id']],
			'payment_intent_data' => ['description' => "Booking #{$b['id']} balance" . ((int) $p['parts'] > 1 ? " ({$p['part']} of {$p['parts']})" : ''), 'metadata' => ['jmb_payment' => (int) $p['id']]],
			'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($s['currency']), 'unit_amount' => (int) $p['amount_cents'],
				'product_data' => ['name' => $b['kind_label'] . ' balance' . ((int) $p['parts'] > 1 ? " ({$p['part']} of {$p['parts']})" : ''), 'description' => $b['date_pretty']]]]],
		]);
		if (is_wp_error($sess) || empty($sess['url'])) { echo jmb_pay_page('Payment could not be started', '<p>Please try again in a moment.</p><p><a href="' . esc_url($url) . '">Back</a></p>'); exit; }
		$wpdb->update(jmb_payments_table(), ['stripe_session' => $sess['id'], 'sessions' => trim($p['sessions'] . ' ' . $sess['id'])], ['id' => (int) $p['id']]);
		wp_redirect($sess['url'], 303);
		exit;
	}
	$due = jmb_day($p['due_date'])->format('l, F j');
	echo jmb_pay_page('Balance due', '<p class="sum">' . $what . '</p><p class="amt">' . esc_html($money) . '</p><p>Due ' . esc_html($due) . '</p>'
		. '<form method="post"><button type="submit">Pay ' . esc_html($money) . ' by card</button></form><p class="fine">You will pay on Stripe\'s secure page.</p>');
	exit;
});

function jmb_pay_page($title, $html, $head = '') {
	$site = esc_html(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
	return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">' . $head
		. '<title>' . esc_html($title) . ' · ' . $site . '</title><style>
		:root{--bg:#f8f5ef;--card:#fff;--ink:#2b2b2b;--muted:#5c5c5c;--accent:#ffad00;--accent-ink:#3b3b3b;--line:#d9d4cf}
		@media (prefers-color-scheme:dark){:root{--bg:#161514;--card:#201f1d;--ink:#eee;--muted:#aaa;--line:#3a3836}}
		body{background:var(--bg);color:var(--ink);font:17px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:40px 16px}
		main{max-width:460px;margin:0 auto;background:var(--card);border:1px solid var(--line);border-radius:6px;padding:28px 24px;text-align:center}
		h1{font-size:1.5rem;margin:.2em 0 .6em}.sum{color:var(--muted)}.amt{font-size:2.2rem;font-weight:700;margin:.2em 0}.site{color:var(--muted);font-size:.9rem;margin-bottom:0}
		button{background:var(--accent);color:var(--accent-ink);border:0;border-radius:3px;padding:.85em 1.6em;font:inherit;font-weight:600;text-transform:uppercase;letter-spacing:.03em;cursor:pointer;margin-top:.6em}
		.fine{font-size:.85rem;color:var(--muted)}a{color:inherit}</style></head><body><main><h1>' . esc_html($title) . '</h1>' . $html . '<p class="site">' . $site . '</p></main></body></html>';
}

/* --------------------------------------------------------------- admin */

/** "retainer $150 ✓ · balance $200 due Dec 10" plus per-part actions, for the Bookings list. */
function jmb_pay_admin_cell($r) {
	$s   = jmb_settings();
	$out = [];
	foreach (jmb_payments_for((int) $r['id']) as $p) {
		$text = 'balance' . ((int) $p['parts'] > 1 ? " {$p['part']}/{$p['parts']}" : '') . ' ' . jmb_money((int) $p['amount_cents'], $s);
		$late = $p['status'] === 'open' && $p['due_date'] < jmb_now()->format('Y-m-d');
		if ($p['status'] === 'paid') $html = esc_html($text . ' ✓');
		elseif ($p['status'] === 'void') $html = '<s>' . esc_html($text) . '</s> void';
		else $html = esc_html($text . ' due ' . jmb_day($p['due_date'])->format('M j')) . ($late ? ' <strong style="color:#b32d2e">overdue</strong>' : '') . ($p['invoice_sent_at'] ? ' · sent' : '');
		$acts = [];
		$u = function ($do) use ($p) { return esc_url(wp_nonce_url(admin_url('admin-post.php?action=jmb_pay_act&do=' . $do . '&pid=' . (int) $p['id']), 'jmb_pay_act_' . (int) $p['id'])); };
		if ($p['status'] === 'open') {
			$acts[] = '<a href="' . $u('send') . '">send now</a>';
			$acts[] = '<a href="' . $u('paid') . '" onclick="return confirm(\'Mark this payment as paid (cash, Venmo...)?\')">mark paid</a>';
			$acts[] = '<a href="' . $u('amount') . '" onclick="var a=prompt(\'New amount for this payment (dollars):\',\'' . esc_js(number_format($p['amount_cents'] / 100, 2, '.', '')) . '\'); if(!a) return false; this.href+=\'&amount=\'+encodeURIComponent(a);">change amount</a>';
			$acts[] = '<a href="' . $u('void') . '" onclick="return confirm(\'Void this payment? The client will not be asked for it.\')">void</a>';
		}
		$out[] = $html . ($acts ? '<br><small>' . implode(' · ', $acts) . '</small>' : '');
	}
	return implode('<br>', $out);
}

add_action('admin_post_jmb_pay_act', function () {
	global $wpdb;
	if (!current_user_can('manage_options')) wp_die('No.');
	$pid = (int) ($_GET['pid'] ?? 0);
	check_admin_referer('jmb_pay_act_' . $pid);
	$t = jmb_payments_table();
	$p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $pid), ARRAY_A);
	$msg = 'payment not found';
	if ($p && $p['status'] === 'open') {
		$do = sanitize_key($_GET['do'] ?? '');
		$b  = jmb_get((int) $p['booking_id']);
		if ($do === 'send' && $b) {
			$ok  = jmb_pay_send($p['invoice_sent_at'] ? 'reminder' : 'invoice', $p, $b);
			if ($ok) $wpdb->update($t, [$p['invoice_sent_at'] ? 'reminder_sent_at' : 'invoice_sent_at' => jmb_utc_now()], ['id' => $pid]);
			$msg = $ok ? ($p['invoice_sent_at'] ? 'payment reminder sent' : 'invoice sent') : 'email could not be sent; check the email settings';
		} elseif ($do === 'paid') {
			jmb_pay_expire($p);
			$wpdb->update($t, ['status' => 'paid', 'paid_at' => jmb_utc_now(), 'paid_note' => 'marked paid by hand'], ['id' => $pid]);
			$msg = 'payment marked paid';
		} elseif ($do === 'void') {
			jmb_pay_expire($p);
			$wpdb->update($t, ['status' => 'void'], ['id' => $pid]);
			$msg = 'payment voided';
		} elseif ($do === 'amount') {
			$c = jmb_cents(wp_unslash($_GET['amount'] ?? ''));
			if ($c >= 50) { jmb_pay_expire($p); $wpdb->update($t, ['amount_cents' => $c, 'stripe_session' => ''], ['id' => $pid]); $msg = 'payment amount changed to ' . jmb_money($c, jmb_settings()); }
			else $msg = 'amount not changed: enter at least 0.50';
		}
	} elseif ($p) $msg = 'that payment is already ' . $p['status'];
	wp_safe_redirect(admin_url('admin.php?page=jmb&show=all&msg=' . rawurlencode($msg)));
	exit;
});

/** Banner: overdue balances. */
add_action('admin_notices', function () {
	global $wpdb;
	if (!current_user_can('manage_options') || empty(jmb_settings()['pay_on'])) return;
	$n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . jmb_payments_table() . ' p JOIN ' . jmb_table() . " b ON b.id = p.booking_id
		WHERE p.status = 'open' AND b.status = 'confirmed' AND p.due_date < %s", jmb_now()->format('Y-m-d')));
	if ($n) echo '<div class="notice notice-warning"><p><strong>Bookings:</strong> ' . sprintf(_n('%d balance payment is overdue.', '%d balance payments are overdue.', $n), $n)
		. ' <a href="' . esc_url(admin_url('admin.php?page=jmb&show=all')) . '">See the Bookings list</a>.</p></div>';
});

function jmb_pay_settings_save($in, &$out) {
	$was = !empty($out['pay_on']);
	$out['pay_on']        = empty($in['pay_on']) ? 0 : 1;
	$out['pay_parts']     = isset($in['pay_parts']) && is_numeric($in['pay_parts']) ? max(1, min(4, (int) $in['pay_parts'])) : 1;
	$out['pay_due_days']  = isset($in['pay_due_days']) && is_numeric($in['pay_due_days']) ? max(0, min(60, (int) $in['pay_due_days'])) : 2;
	$out['pay_send_days'] = isset($in['pay_send_days']) && is_numeric($in['pay_send_days']) ? max(0, min(60, (int) $in['pay_send_days'])) : 7;
	$out['pay_remind']    = empty($in['pay_remind']) ? 0 : 1;
	$e = [];
	foreach (jmb_pay_defs() as $k => $def) {
		$x = is_array($in['pay_emails'][$k] ?? null) ? $in['pay_emails'][$k] : [];
		$e[$k] = ['subject' => sanitize_text_field($x['subject'] ?? '') ?: $def['subject'], 'body' => trim(sanitize_textarea_field($x['body'] ?? '')) ?: $def['body']];
	}
	$out['pay_emails'] = $e;
	if ($out['pay_on'] && !$was) update_option('jmb_pay_since', time(), false);   // only bookings paid from now on
}

function jmb_pay_test($key, $to) {
	if (!isset(jmb_pay_defs()[$key])) return 'unknown email';
	if (!is_email($to)) return 'no email address to send the test to';
	[$b] = jmb_sample_booking($to);
	$s   = jmb_settings();
	$bal = jmb_balance_cents('full', $s) ?: 20000;
	$p   = ['id' => 0, 'booking_id' => 0, 'part' => 1, 'parts' => 1, 'amount_cents' => $bal, 'due_date' => jmb_day($b['date'])->modify('-' . (int) $s['pay_due_days'] . ' days')->format('Y-m-d'),
		'status' => $key === 'receipt' ? 'paid' : 'open', 'token' => ''];
	$why = '';
	$catch = function ($e) use (&$why) { $why = $e->get_error_message(); };
	add_action('wp_mail_failed', $catch);
	$ok = jmb_pay_send($key, $p, $b, $to, true);
	remove_action('wp_mail_failed', $catch);
	return 'saved; ' . jmb_pay_defs()[$key]['label'] . ($ok ? " test sent to $to" : ' test could not be sent' . ($why ? ": $why" : ''));
}

function jmb_pay_settings_html($s) {
	$full = jmb_price_cents('full', $s); $mini = jmb_price_cents('mini', $s);
	$warn = [];
	if (trim($s['full_price']) !== '' && !$full) $warn[] = 'the full session price "' . esc_html($s['full_price']) . '" is not a plain amount';
	if (trim($s['mini_price']) !== '' && !$mini) $warn[] = 'the mini session price "' . esc_html($s['mini_price']) . '" is not a plain amount';
	$since = (int) get_option('jmb_pay_since');
	$h = '<h2 id="jmb-pay">Balance payments</h2><p>The balance is the session price (the "price shown" above) minus the retainer, paid online through Stripe before the session.</p>';
	if ($warn) $h .= '<p style="color:#b32d2e">No balance can be worked out because ' . implode(' and ', $warn) . '. Use an amount like <code>$350</code>.</p>';
	$h .= '<table class="form-table"><tr><th>Collect the balance</th><td><label><input type="checkbox" name="jmb[pay_on]" value="1" ' . checked(!empty($s['pay_on']), true, false) . '> Invoice the balance automatically</label>'
		. '<p class="description">Only bookings paid after this is switched on' . ($since && !empty($s['pay_on']) ? ' (on ' . esc_html(wp_date('M j, Y g:i A', $since)) . ')' : '') . ' get a balance invoice, so balances you collect another way are never billed twice.'
		. ($full ? ' Full session: ' . esc_html(jmb_money($full, $s)) . ' − retainer ' . esc_html(jmb_money(jmb_charge_cents('full', $s), $s)) . ' = <strong>' . esc_html(jmb_money(jmb_balance_cents('full', $s), $s)) . '</strong>.' : '')
		. ($mini ? ' Mini session: <strong>' . esc_html(jmb_money(jmb_balance_cents('mini', $s), $s)) . '</strong>.' : '') . '</p></td></tr>';
	$parts = '';
	for ($i = 1; $i <= 4; $i++) $parts .= '<option value="' . $i . '" ' . selected((int) $s['pay_parts'], $i, false) . '>' . ($i === 1 ? 'one payment' : "$i monthly payments") . '</option>';
	$h .= '<tr><th>When</th><td>Due <input type="number" min="0" max="60" name="jmb[pay_due_days]" value="' . (int) $s['pay_due_days'] . '" style="width:64px"> days before the session, as <select name="jmb[pay_parts]">' . $parts . '</select> (fewer when the session is soon).<br>'
		. 'Email each invoice <input type="number" min="0" max="60" name="jmb[pay_send_days]" value="' . (int) $s['pay_send_days'] . '" style="width:64px"> days before it is due. '
		. '<label><input type="checkbox" name="jmb[pay_remind]" value="1" ' . checked(!empty($s['pay_remind']), true, false) . '> Remind on the due date if unpaid</label>. You are emailed the day after if a payment is overdue.</td></tr>';
	foreach (jmb_pay_defs() as $k => $def) {
		$c = jmb_pay_conf($k, $s);
		$h .= '<tr><th>' . esc_html($def['label']) . ' email</th><td><input type="text" name="jmb[pay_emails][' . $k . '][subject]" value="' . esc_attr($c['subject']) . '" class="large-text" aria-label="Subject">'
			. '<textarea name="jmb[pay_emails][' . $k . '][body]" rows="7" class="large-text" style="margin-top:4px" aria-label="Message">' . esc_textarea($c['body']) . '</textarea>'
			. '<button type="submit" class="button" name="jmb_testpay" value="' . esc_attr($k) . '" style="margin-top:4px">Save and send me a test</button></td></tr>';
	}
	return $h . '<tr><th>Fill-in fields</th><td><p class="description"><code>{amount}</code> <code>{due_date}</code> <code>{pay_link}</code> <code>{part_label}</code> (e.g. "payment 1 of 2 of the ") <code>{session_total}</code> <code>{paid_total}</code> <code>{remaining}</code>, plus the automatic-email fields.</p></td></tr></table>';
}
