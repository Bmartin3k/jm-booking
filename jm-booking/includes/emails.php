<?php
/**
 * Automatic emails, timed from the session date: prep tips, reminder, thank-you, review request.
 *
 * - Only confirmed bookings with an email address; cancelled or unpaid bookings get nothing.
 * - Each email goes to a booking at most once (log table, unique per booking + email).
 * - Booked after an email's send time: that email is skipped (the reminder still goes if the session
 *   is more than 12 hours away). After-session emails more than 7 days overdue are skipped, so
 *   turning an email on never mails old clients.
 * - Failed sends are retried on the next run, up to 3 attempts.
 */

if (!defined('ABSPATH')) exit;

function jmb_emails_table() { global $wpdb; return $wpdb->prefix . 'jmb_emails'; }

function jmb_emails_install() {
	global $wpdb;
	$t = jmb_emails_table();
	$c = $wpdb->get_charset_collate();
	dbDelta("CREATE TABLE $t (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id BIGINT UNSIGNED NOT NULL,
		email_key VARCHAR(20) NOT NULL,
		status VARCHAR(10) NOT NULL DEFAULT 'sent',
		attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
		sent_at DATETIME NOT NULL,
		note VARCHAR(255) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		UNIQUE KEY booking_email (booking_id, email_key)
	) $c;");
	if (!wp_next_scheduled('jmb_auto_emails')) wp_schedule_event(time() + 120, 'hourly', 'jmb_auto_emails');
}

/** The four emails with their default settings and starter text. */
function jmb_email_defs() {
	return [
		'prep' => [
			'label' => 'Prep tips', 'days' => -7, 'hour' => 9, 'late' => 0,
			'subject' => 'Getting ready for your {session_type} on {session_date}',
			'body' => "Hi {first_name},\n\nYour {session_type} is one week away, on {session_date} at {session_time}. Here are a few tips to get ready:\n\n- Outfits: pick colors that go together without matching exactly, and avoid big logos.\n- Kids: bring a snack and a favorite toy, and plan a calm afternoon beforehand.\n- Weather: if it looks bad, I'll reach out about rescheduling. Weather reschedules are always free.\n\nAny questions, just reply to this email.\n\n{business_name}",
		],
		'reminder' => [
			'label' => 'Reminder', 'days' => -2, 'hour' => 9, 'late' => 1,
			'subject' => 'See you {session_date}!',
			'body' => "Hi {first_name},\n\nA quick reminder about your {session_type}:\n\nWhen: {session_date}, {session_time}\nWhere: {location}\n\nPlease arrive about 10 minutes early. If anything comes up, reply to this email as soon as you can.\n\nSee you soon!\n{business_name}",
		],
		'thanks' => [
			'label' => 'Thank you', 'days' => 1, 'hour' => 10, 'late' => 0,
			'subject' => 'Thank you, {first_name}!',
			'body' => "Hi {first_name},\n\nThank you for your {session_type} yesterday! I loved working with you.\n\nI'm editing your photos now and will send your gallery as soon as it's ready.\n\n{business_name}",
		],
		'review' => [
			'label' => 'Review request', 'days' => 21, 'hour' => 10, 'late' => 0,
			'subject' => 'Would you share a few words?',
			'body' => "Hi {first_name},\n\nI hope you're enjoying your photos! If you have a minute, a short review would mean the world to me and helps other families find me:\n\n(paste your Google review link here)\n\nThank you so much!\n{business_name}",
		],
	];
}

/** Saved settings for one email, falling back to the defaults. Stored in jmb_settings['emails'][key]. */
function jmb_email_conf($key, $s = null) {
	$s    = $s ?: jmb_settings();
	$def  = jmb_email_defs()[$key];
	$conf = is_array($s['emails'][$key] ?? null) ? $s['emails'][$key] : [];
	return array_merge(['on' => 0, 'days' => $def['days'], 'hour' => $def['hour'], 'late' => $def['late'], 'subject' => $def['subject'], 'body' => $def['body']], $conf);
}

/** Fill-in fields for one booking. */
function jmb_email_fields($b, $s) {
	$first = preg_split('/\s+/', trim($b['name']))[0] ?? '';
	return [
		'{client_name}'   => $b['name'],
		'{first_name}'    => $first !== '' ? $first : $b['name'],
		'{session_type}'  => $b['kind_label'],
		'{session_date}'  => $b['date_pretty'],
		'{session_time}'  => $b['time_pretty'],
		'{location}'      => $b['private_loc'] ? 'I\'ll send you the exact spot separately' : ($b['location'] !== '' ? $b['location'] : 'to be confirmed'),
		'{business_name}' => $s['business_name'] !== '' ? $s['business_name'] : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
	];
}

/** Send time for one email and booking (unix time), from the session date, the day offset and the hour. */
function jmb_email_due($b, $conf) {
	$d = jmb_day($b['date'])->modify(((int) $conf['days'] >= 0 ? '+' : '') . (int) $conf['days'] . ' days');
	return $d->setTime((int) $conf['hour'], 0)->getTimestamp();
}

function jmb_email_send($key, $b, $to = null, $test = false) {
	$s    = jmb_settings();
	$conf = jmb_email_conf($key, $s);
	$f    = jmb_email_fields($b, $s);
	return wp_mail($to ?: $b['email'], ($test ? '[Test] ' : '') . strtr($conf['subject'], $f), strtr($conf['body'], $f));
}

function jmb_email_log($booking_id, $key, $status, $note = '', $attempts = 1) {
	global $wpdb;
	$t = jmb_emails_table();
	$wpdb->query($wpdb->prepare(
		"INSERT INTO $t (booking_id, email_key, status, attempts, sent_at, note) VALUES (%d, %s, %s, %d, %s, %s)
		 ON DUPLICATE KEY UPDATE status = VALUES(status), attempts = VALUES(attempts), sent_at = VALUES(sent_at), note = VALUES(note)",
		$booking_id, $key, $status, $attempts, jmb_utc_now(), mb_substr($note, 0, 255)));
}

/** Hourly: send every email that is due. Returns ['sent' => n, 'skipped' => n, 'failed' => n]. */
function jmb_run_auto_emails() {
	global $wpdb;
	$s     = jmb_settings();
	$now   = time();
	$count = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
	$keys  = array_filter(array_keys(jmb_email_defs()), function ($k) use ($s) { return !empty(jmb_email_conf($k, $s)['on']); });
	if (!$keys) return $count;
	$from = jmb_now()->modify('-60 days')->format('Y-m-d');
	$to   = jmb_now()->modify('+60 days')->format('Y-m-d');
	$rows = $wpdb->get_results($wpdb->prepare(
		'SELECT * FROM ' . jmb_table() . " WHERE status = 'confirmed' AND email <> '' AND date BETWEEN %s AND %s", $from, $to), ARRAY_A);
	if (!$rows) return $count;
	$log = [];
	foreach ($wpdb->get_results('SELECT booking_id, email_key, status, attempts FROM ' . jmb_emails_table() . ' WHERE booking_id IN (' . implode(',', array_map('intval', wp_list_pluck($rows, 'id'))) . ')', ARRAY_A) as $l)
		$log[$l['booking_id'] . '/' . $l['email_key']] = $l;

	foreach ($rows as $row) {
		$b = jmb_decorate($row);
		if ($b['kind'] === 'mini' && empty($s['emails_minis'])) continue;
		$start = (new DateTimeImmutable($b['date'] . ' ' . $b['start_time'], jmb_tz()))->getTimestamp();
		foreach ($keys as $key) {
			$prev = $log[$b['id'] . '/' . $key] ?? null;
			if ($prev && ($prev['status'] !== 'failed' || (int) $prev['attempts'] >= 3)) continue;   // done (or gave up)
			$conf = jmb_email_conf($key, $s);
			$due  = jmb_email_due($b, $conf);
			if ($due > $now) continue;                                                              // not yet
			$before = (int) $conf['days'] < 0;
			$booked = strtotime(($b['confirmed_at'] ?: $b['created_at']) . ' UTC');
			$why = '';
			if ($before && $start <= $now) $why = 'session already started';
			elseif ($before && $booked > $due && !($conf['late'] && $start - $now > 12 * HOUR_IN_SECONDS)) $why = 'booked after the send time';
			elseif (!$before && $now - $due > 7 * DAY_IN_SECONDS) $why = 'more than 7 days overdue';
			if ($why) { jmb_email_log($b['id'], $key, 'skipped', $why, 0); $count['skipped']++; continue; }

			$err = '';
			$catch = function ($e) use (&$err) { $err = $e->get_error_message(); };
			add_action('wp_mail_failed', $catch);
			$ok = jmb_email_send($key, $b);
			remove_action('wp_mail_failed', $catch);
			$attempts = ($prev ? (int) $prev['attempts'] : 0) + 1;
			jmb_email_log($b['id'], $key, $ok ? 'sent' : 'failed', $ok ? '' : ($err ?: 'wp_mail returned false'), $attempts);
			$count[$ok ? 'sent' : 'failed']++;
			if (!$ok && $attempts >= 3)
				jmb_mail_admin("Automatic email failed: {$b['name']}", jmb_email_defs()[$key]['label'] . " email to {$b['email']} (booking #{$b['id']}) failed 3 times: $err\nCheck the email settings (WP Mail SMTP).");
		}
	}
	update_option('jmb_emails_last_run', ['time' => $now] + $count, false);
	return $count;
}
add_action('jmb_auto_emails', 'jmb_run_auto_emails');

/** Warning banner in wp-admin when automatic emails gave up: an email alert would not arrive if email itself is broken. */
add_action('admin_notices', function () {
	global $wpdb;
	if (!current_user_can('manage_options')) return;
	$n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . jmb_emails_table() . " WHERE status = 'failed' AND attempts >= 3 AND sent_at > %s",
		gmdate('Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS)));
	if (!$n) return;
	$why = (string) $wpdb->get_var('SELECT note FROM ' . jmb_emails_table() . " WHERE status = 'failed' AND attempts >= 3 ORDER BY sent_at DESC LIMIT 1");
	echo '<div class="notice notice-error"><p><strong>Bookings:</strong> ' . sprintf(_n('%d automatic email to a client could not be sent', '%d automatic emails to clients could not be sent', $n), $n)
		. ' in the last 14 days' . ($why ? ' (' . esc_html($why) . ')' : '') . '. Check <a href="' . esc_url(admin_url('admin.php?page=wp-mail-smtp')) . '">WP Mail SMTP</a> and use "Send test confirmation" on the <a href="'
		. esc_url(admin_url('admin.php?page=jmb-settings')) . '">booking settings</a>. Once email works: <a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=jmb_retry_emails'), 'jmb_retry_emails'))
		. '">Retry them now</a> (emails that are no longer useful, like a reminder after the session, are skipped).</p></div>';
});

/** "Retry them now": give failed emails a fresh set of attempts and run the sender straight away. */
add_action('admin_post_jmb_retry_emails', function () {
	global $wpdb;
	if (!current_user_can('manage_options')) wp_die('No.');
	check_admin_referer('jmb_retry_emails');
	$wpdb->query('UPDATE ' . jmb_emails_table() . " SET attempts = 0 WHERE status = 'failed'");
	$r = jmb_run_auto_emails();
	wp_safe_redirect(admin_url('admin.php?page=jmb&show=all&msg=' . rawurlencode(sprintf('emails retried: %d sent, %d skipped, %d failed', $r['sent'], $r['skipped'], $r['failed']))));
	exit;
});

/** Short "prep ✓ reminder ✓" summary for the Bookings list. */
function jmb_email_summary($booking_id) {
	global $wpdb;
	$rows = $wpdb->get_results($wpdb->prepare('SELECT email_key, status FROM ' . jmb_emails_table() . ' WHERE booking_id = %d', $booking_id), ARRAY_A);
	$defs = jmb_email_defs();
	$out  = [];
	foreach ($rows as $r) $out[] = strtolower($defs[$r['email_key']]['label'] ?? $r['email_key']) . ($r['status'] === 'sent' ? ' ✓' : ' (' . $r['status'] . ')');
	return implode(', ', $out);
}

/* --------------------------------------------------------------- admin */

/** Send a [Test] copy of one automatic email (sample booking) to $to. Returns a message for the settings page. */
function jmb_email_test($key, $to) {
	if (!isset(jmb_email_defs()[$key])) return 'unknown email';
	if (!is_email($to)) return 'no email address to send the test to';
	$why = '';
	$catch = function ($e) use (&$why) { $why = $e->get_error_message(); };
	add_action('wp_mail_failed', $catch);
	[$b] = jmb_sample_booking($to);
	$ok = jmb_email_send($key, $b, $to, true);
	remove_action('wp_mail_failed', $catch);
	return 'saved; ' . jmb_email_defs()[$key]['label'] . ($ok ? " test sent to $to" : ' test could not be sent' . ($why ? ": $why" : ''));
}

/** Save handler part: called from the main settings save with the posted jmb[] array. */
function jmb_emails_save($in, &$out) {
	$out['emails_minis'] = empty($in['emails_minis']) ? 0 : 1;
	$emails = [];
	foreach (jmb_email_defs() as $key => $def) {
		$e = is_array($in['emails'][$key] ?? null) ? $in['emails'][$key] : [];
		$days = isset($e['days']) && is_numeric($e['days']) ? max(0, min(120, (int) $e['days'])) : abs($def['days']);
		$emails[$key] = [
			'on'      => empty($e['on']) ? 0 : 1,
			'days'    => $def['days'] < 0 ? -$days : $days,          // the direction (before/after) is fixed per email
			'hour'    => isset($e['hour']) && is_numeric($e['hour']) ? max(0, min(23, (int) $e['hour'])) : $def['hour'],
			'late'    => $def['days'] < 0 ? (empty($e['late']) ? 0 : 1) : 0,
			'subject' => sanitize_text_field($e['subject'] ?? '') ?: $def['subject'],
			'body'    => trim(sanitize_textarea_field($e['body'] ?? '')) ?: $def['body'],
		];
	}
	$out['emails'] = $emails;
}

/** Settings page section. */
function jmb_emails_settings_html($s) {
	$h = '<h2 id="jmb-emails">Automatic emails</h2><p>Sent to clients with a confirmed booking, timed from the session date. Each one goes to a client once. If someone books after an email\'s send time, that email is skipped. Fill-in fields: <code>{first_name}</code> <code>{client_name}</code> <code>{session_type}</code> <code>{session_date}</code> <code>{session_time}</code> <code>{location}</code> <code>{business_name}</code>.</p>';
	$last = get_option('jmb_emails_last_run');
	if ($last) $h .= '<p class="description">Last run: ' . esc_html(wp_date('M j, g:i A', (int) $last['time'])) . ' (sent ' . (int) $last['sent'] . ', skipped ' . (int) $last['skipped'] . ', failed ' . (int) $last['failed'] . '). The check runs about once an hour.</p>';
	$h .= '<table class="form-table"><tr><th>Mini sessions</th><td><label><input type="checkbox" name="jmb[emails_minis]" value="1" ' . checked(!empty($s['emails_minis']), true, false) . '> Send these to mini-session clients too</label></td></tr>';
	foreach (jmb_email_defs() as $key => $def) {
		$c = jmb_email_conf($key, $s);
		$n = "jmb[emails][$key]";
		$hours = '';
		for ($i = 0; $i < 24; $i++) $hours .= '<option value="' . $i . '" ' . selected((int) $c['hour'], $i, false) . '>' . esc_html(date('g A', mktime($i, 0))) . '</option>';
		$h .= '<tr><th>' . esc_html($def['label']) . '</th><td>'
			. '<label><input type="checkbox" name="' . $n . '[on]" value="1" ' . checked(!empty($c['on']), true, false) . '> Send</label> '
			. '<input type="number" min="0" max="120" name="' . $n . '[days]" value="' . esc_attr(abs((int) $c['days'])) . '" style="width:64px"> days ' . ($def['days'] < 0 ? 'before' : 'after') . ' the session, at <select name="' . $n . '[hour]">' . $hours . '</select>'
			. ($def['days'] < 0 && $def['late'] ? ' <label style="margin-left:8px"><input type="checkbox" name="' . $n . '[late]" value="1" ' . checked(!empty($c['late']), true, false) . '> also to late bookings (if more than 12 hours before)</label>' : '')
			. '<br><input type="text" name="' . $n . '[subject]" value="' . esc_attr($c['subject']) . '" class="large-text" style="margin-top:6px" aria-label="Subject">'
			. '<textarea name="' . $n . '[body]" rows="8" class="large-text" style="margin-top:4px" aria-label="Message">' . esc_textarea($c['body']) . '</textarea>'
			. '<button type="submit" class="button" name="jmb_testauto" value="' . esc_attr($key) . '" style="margin-top:4px">Save and send me a test</button>'
			. ' <span class="description">to ' . esc_html(wp_get_current_user()->user_email) . '</span></td></tr>';
	}
	return $h . '</table>';
}
