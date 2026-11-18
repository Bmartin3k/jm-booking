<?php
/**
 * Plugin Name: JM Session Booking
 * Description: Booking calendar: sunset-based weekend sessions, a cap on booked weekends per month, a discounted mini session that opens on booked days, school-year / summer weekday hours. Clients pay online with Stripe Checkout; paid bookings are sent to Sprout Studio through Zapier. Shortcode: [jm_booking]
 * Version: 2.2.0
 * Requires PHP: 8.0
 * Requires at least: 6.4
 */

if (!defined('ABSPATH')) exit;

const JMB_VER    = '2.2.0';
const JMB_DB_VER = '4';   // bump to re-run jmb_install() on update (table columns + cron events)

/* ------------------------------------------------------------------ setup */

function jmb_table() { global $wpdb; return $wpdb->prefix . 'jmb_bookings'; }

function jmb_defaults() {
	return [
		'timezone'         => 'America/Chicago',
		'lat'              => '30.2672',     // Austin, TX
		'lon'              => '-97.7431',
		'full_minutes'     => 120,
		'mini_minutes'     => 60,
		'mini_gap'         => 30,            // break between mini end and full start
		'sunset_offset'    => 0,             // full session ends at sunset + this many minutes
		'weekend_cap'      => 2,             // booked weekends per month before the rest close
		'weekend_minis'    => 1,
		'weekday_minis'    => 0,
		'school_start'     => '08-12',       // MM-DD, school year start
		'school_end'       => '05-28',       // MM-DD, school year end
		'school_days'      => [3],           // ISO weekday numbers, 3 = Wednesday
		'school_from'      => '08:30',
		'school_to'        => '11:00',
		'summer_days'      => [3],
		'summer_from'      => '08:30',
		'summer_to'        => '11:00',
		'slot_step'        => 30,
		'min_notice_days'  => 2,
		'max_ahead_days'   => 180,
		'full_label'       => 'Full session',
		'mini_label'       => 'Mini session',
		'full_price'       => '',            // price text shown on the calendar
		'mini_price'       => '',
		'full_amount'      => '',            // amount charged online at booking, in dollars
		'mini_amount'      => '',
		'charge_label'     => 'Retainer',
		'currency'         => 'usd',
		'checkout_minutes' => 30,            // Stripe Checkout lifetime = how long an unpaid slot is held (Stripe minimum 30)
		'stripe_secret'    => '',
		'stripe_webhook'   => '',
		'intro'            => '',
		'blocked'          => '',
		'locations'        => "# Parks & nature\nAustin Colony Colorado River Park\nBlunn Creek Greenbelt\nBrushy Creek Lake Park (Cedar Park)\nButler Metro Park\nCircle C Ranch Metro Park\nCommons Ford Ranch Metro Park\nMayfield Park\nMueller Lake Park\nRed Bud Isle\nRiver Trail Trailhead\nRoy G. Guerrero Park\nSouthwest Greenway\nWalnut Creek Metro Park\n# Urban & colorful\nAuditorium Shores\nGraffiti & mural walls\nLou Neff Point\nSixth Street\nSouth Congress Avenue\nTaquero Mucho\nThe Blue Tile Wall\nThe Long Center\n# Eclectic\nCathedral of Junk\nDimension Gallery\nRecord stores in Austin\n# Campus\nUniversity of Texas campus",
		'turnstile_site'   => '',
		'turnstile_secret' => '',
		'ip_daily_holds'   => 5,
		'retain_days'      => 365,           // delete bookings this many days after the session date (0 = keep forever)
		'zapier_hook'      => '',
		'notify_email'     => '',
		'gcal_json'        => '',            // Google service account key (JSON)
		'gcal_id'          => '',            // her calendar ID (her Gmail address for the main calendar)
		'gcal_mode'        => 'weekend_day', // weekend_day | day | overlap
		'gcal_buffer'      => 30,            // minutes kept free around her events (overlap checks)
		'gcal_push'        => 1,             // add paid bookings to her calendar
	];
}

function jmb_settings() {
	$s = get_option('jmb_settings', []);
	return array_merge(jmb_defaults(), is_array($s) ? $s : []);
}

function jmb_tz() {
	try { return new DateTimeZone(jmb_settings()['timezone']); }
	catch (Exception $e) { return wp_timezone(); }
}

function jmb_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = jmb_table();
	$c = $wpdb->get_charset_collate();
	dbDelta("CREATE TABLE $t (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		kind VARCHAR(10) NOT NULL,
		date DATE NOT NULL,
		start_time CHAR(5) NOT NULL,
		end_time CHAR(5) NOT NULL,
		status VARCHAR(12) NOT NULL DEFAULT 'pending',
		name VARCHAR(120) NOT NULL DEFAULT '',
		email VARCHAR(190) NOT NULL DEFAULT '',
		phone VARCHAR(40) NOT NULL DEFAULT '',
		location VARCHAR(200) NOT NULL DEFAULT '',
		notes TEXT,
		parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		source VARCHAR(12) NOT NULL DEFAULT 'web',
		created_at DATETIME NOT NULL,
		hold_until DATETIME NULL,
		confirmed_at DATETIME NULL,
		zap_status VARCHAR(190) NOT NULL DEFAULT '',
		gcal_event VARCHAR(255) NOT NULL DEFAULT '',
		gcal_status VARCHAR(190) NOT NULL DEFAULT '',
		payment_note TEXT,
		token CHAR(32) NOT NULL DEFAULT '',
		stripe_session VARCHAR(255) NOT NULL DEFAULT '',
		payment_id VARCHAR(255) NOT NULL DEFAULT '',
		amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY date_status (date, status),
		KEY email (email),
		KEY token (token)
	) $c;");
	update_option('jmb_db_ver', JMB_DB_VER);
	if (!wp_next_scheduled('jmb_expire_holds')) wp_schedule_event(time() + 300, 'hourly', 'jmb_expire_holds');
	if (!wp_next_scheduled('jmb_purge_old')) wp_schedule_event(time() + 600, 'daily', 'jmb_purge_old');
}
register_activation_hook(__FILE__, 'jmb_install');
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('jmb_expire_holds'); wp_clear_scheduled_hook('jmb_purge_old'); });
add_action('plugins_loaded', function () { if (get_option('jmb_db_ver') !== JMB_DB_VER) jmb_install(); });

/* ------------------------------------------------------- availability */

function jmb_now() { return new DateTimeImmutable('now', jmb_tz()); }
function jmb_day($ymd) { return new DateTimeImmutable($ymd . ' 00:00', jmb_tz()); }
function jmb_fmt(DateTimeInterface $d) { return $d->format('g:i A'); }
function jmb_utc_now() { return gmdate('Y-m-d H:i:s'); }

/** Active bookings (confirmed, or awaiting payment with an unexpired hold) between two dates, grouped by date. */
function jmb_active_index($from, $to) {
	global $wpdb;
	$t = jmb_table();
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM $t WHERE date BETWEEN %s AND %s
		 AND (status = 'confirmed' OR (status = 'pending' AND hold_until > %s))
		 ORDER BY date, start_time", $from, $to, jmb_utc_now()), ARRAY_A);
	$idx = [];
	foreach ($rows as $r) $idx[$r['date']][] = $r;
	return $idx;
}

function jmb_index_around($ymd) {
	$d = jmb_day($ymd);
	return jmb_active_index($d->modify('-40 days')->format('Y-m-d'), $d->modify('+40 days')->format('Y-m-d'));
}

function jmb_is_weekend(DateTimeImmutable $d) { return (int) $d->format('N') >= 6; }

/** The Saturday that a weekend day belongs to (a weekend counts toward the Saturday's month). */
function jmb_weekend_key(DateTimeImmutable $d) {
	return $d->format('N') === '7' ? $d->modify('-1 day')->format('Y-m-d') : $d->format('Y-m-d');
}

function jmb_full_on($idx, $ymd) {
	foreach ($idx[$ymd] ?? [] as $r) if ($r['kind'] === 'full') return $r;
	return null;
}

function jmb_weekend_full($idx, $sat) {
	$sun = jmb_day($sat)->modify('+1 day')->format('Y-m-d');
	return jmb_full_on($idx, $sat) ?: jmb_full_on($idx, $sun);
}

function jmb_booked_weekends($idx, $ym) {
	$n = 0;
	for ($d = jmb_day($ym . '-01'); $d->format('Y-m') === $ym; $d = $d->modify('+1 day')) {
		if ($d->format('N') === '6' && jmb_weekend_full($idx, $d->format('Y-m-d'))) $n++;
	}
	return $n;
}

function jmb_blocked_dates($s) {
	$out = [];
	foreach (preg_split('/\R/', (string) $s['blocked']) as $line) {
		if (preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s*(?:(?:to|-|–)\s*(\d{4}-\d{2}-\d{2}))?/', $line, $m)) {
			if (!jmb_valid_date($m[1]) || (!empty($m[2]) && !jmb_valid_date($m[2]))) continue;
			$a = jmb_day($m[1]);
			$b = !empty($m[2]) ? jmb_day($m[2]) : $a;
			for ($d = $a, $i = 0; $d <= $b && $i < 400; $d = $d->modify('+1 day'), $i++) $out[$d->format('Y-m-d')] = true;
		}
	}
	return $out;
}

function jmb_season(DateTimeImmutable $d, $s) {
	$md = $d->format('m-d');
	$a = $s['school_start']; $b = $s['school_end'];
	$school = $a <= $b ? ($md >= $a && $md <= $b) : ($md >= $a || $md <= $b);
	return $school
		? ['school', (array) $s['school_days'], $s['school_from'], $s['school_to']]
		: ['summer', (array) $s['summer_days'], $s['summer_from'], $s['summer_to']];
}

function jmb_sunset_ts($ymd, $s) {
	$noon = new DateTimeImmutable($ymd . ' 12:00', jmb_tz());
	$info = date_sun_info($noon->getTimestamp(), (float) $s['lat'], (float) $s['lon']);
	return is_int($info['sunset'] ?? null) ? $info['sunset'] : null;
}

/** Dollars text ("150", "$150.00", "1,200") → cents, or 0 if not a positive amount. */
function jmb_cents($text) {
	$t = str_replace([',', '$', ' '], '', (string) $text);
	if (!preg_match('/^\d+(\.\d{1,2})?$/', $t)) return 0;
	return (int) round(((float) $t) * 100);
}

function jmb_money($cents, $s) {
	$sym = strtolower($s['currency']) === 'usd' ? '$' : strtoupper($s['currency']) . ' ';
	return $sym . number_format($cents / 100, 2);
}

function jmb_charge_cents($kind, $s) { return jmb_cents($kind === 'mini' ? $s['mini_amount'] : $s['full_amount']); }

function jmb_slot($kind, $ymd, $start_ts, $minutes, $s, $extra = []) {
	$st = (new DateTimeImmutable('@' . $start_ts))->setTimezone(jmb_tz());
	$en = $st->modify("+$minutes minutes");
	$c  = jmb_charge_cents($kind, $s);
	return array_merge([
		'kind'   => $kind,
		'date'   => $ymd,
		'start'  => $st->format('H:i'),
		'end'    => $en->format('H:i'),
		'label'  => $kind === 'full' ? $s['full_label'] : $s['mini_label'],
		'time'   => jmb_fmt($st) . ' – ' . jmb_fmt($en),
		'price'  => $kind === 'full' ? $s['full_price'] : $s['mini_price'],
		'charge' => $c ? jmb_money($c, $s) . ' ' . strtolower($s['charge_label']) . ' due now' : '',
	], $extra);
}

/** Location list from settings: lines are locations, "# Heading" lines start a group. */
function jmb_locations($s) {
	$groups = [];
	$cur = ['group' => '', 'items' => []];
	foreach (preg_split('/\R/', (string) $s['locations']) as $line) {
		$line = trim($line);
		if ($line === '') continue;
		if ($line[0] === '#') {
			if ($cur['items']) $groups[] = $cur;
			$cur = ['group' => trim(substr($line, 1)), 'items' => []];
		} else {
			$cur['items'][] = $line;
		}
	}
	if ($cur['items']) $groups[] = $cur;
	return $groups;
}

function jmb_location_is_public($loc, $s) {
	foreach (jmb_locations($s) as $g) if (in_array($loc, $g['items'], true)) return true;
	return false;
}

/** What other people (mini-session bookers) may see of a full session's location. */
function jmb_public_location($full, $s) {
	return jmb_location_is_public($full['location'], $s) ? $full['location'] : 'Shared with you after booking';
}

/** The mini session for a day with a full session: only once that full session is confirmed (paid). */
function jmb_mini_slot($full, $idx, $s) {
	if ($full['status'] !== 'confirmed') return null;
	foreach ($idx[$full['date']] ?? [] as $r) if ($r['kind'] === 'mini') return null;
	$fs = new DateTimeImmutable($full['date'] . ' ' . $full['start_time'], jmb_tz());
	$mm = (int) $s['mini_minutes'];
	$st = $fs->modify('-' . ((int) $s['mini_gap'] + $mm) . ' minutes');
	return jmb_slot('mini', $full['date'], $st->getTimestamp(), $mm, $s, [
		'location' => jmb_public_location($full, $s),
		'note'     => 'Discounted shorter session, same day and location as a booked session.',
	]);
}

/** Slots from the regular rules (weekends at sunset, school-year / summer weekday hours, minis). */
function jmb_rule_slots($ymd, $idx, $s, DateTimeImmutable $now) {
	$d   = jmb_day($ymd);
	$min = $now->setTime(0, 0)->modify('+' . (int) $s['min_notice_days'] . ' days');
	$max = $now->setTime(0, 0)->modify('+' . (int) $s['max_ahead_days'] . ' days');
	if ($d < $min || $d > $max) return [];
	if (isset(jmb_blocked_dates($s)[$ymd])) return [];

	$fm = (int) $s['full_minutes'];

	if (jmb_is_weekend($d)) {
		$sat = jmb_weekend_key($d);
		$wf  = jmb_weekend_full($idx, $sat);
		if ($wf) {   // this weekend is taken; only the mini on the booked day can open
			if ($wf['date'] === $ymd && $s['weekend_minis']) {
				$m = jmb_mini_slot($wf, $idx, $s);
				return $m ? [$m] : [];
			}
			return [];
		}
		if (jmb_booked_weekends($idx, substr($sat, 0, 7)) >= (int) $s['weekend_cap']) return [];
		$sun = jmb_sunset_ts($ymd, $s);
		if (!$sun) return [];
		$start = $sun + (int) $s['sunset_offset'] * 60 - $fm * 60;
		$start = (int) (floor($start / 900) * 900);   // round down to a quarter hour
		$sunset = (new DateTimeImmutable('@' . $sun))->setTimezone(jmb_tz());
		return [jmb_slot('full', $ymd, $start, $fm, $s, ['note' => 'Golden hour session, sunset ' . jmb_fmt($sunset) . '.'])];
	}

	$full = jmb_full_on($idx, $ymd);
	if ($full) {
		if ($s['weekday_minis']) { $m = jmb_mini_slot($full, $idx, $s); return $m ? [$m] : []; }
		return [];
	}
	[, $days, $from, $to] = jmb_season($d, $s);
	if (!in_array((int) $d->format('N'), array_map('intval', $days), true)) return [];
	$out  = [];
	$step = max(15, (int) $s['slot_step']);
	$a = new DateTimeImmutable("$ymd $from", jmb_tz());
	$b = new DateTimeImmutable("$ymd $to", jmb_tz());
	for ($t = $a; $t->modify("+$fm minutes") <= $b; $t = $t->modify("+$step minutes")) {
		$out[] = jmb_slot('full', $ymd, $t->getTimestamp(), $fm, $s);
	}
	return $out;
}

/* ------------------------------------------------------ extra open times */

/** Extra times she opened by hand: [['date' => 'Y-m-d', 'time' => 'H:i' | 'sunset'], ...], upcoming only, sorted. */
function jmb_extras() {
	$x = get_option('jmb_extra', []);
	if (!is_array($x)) return [];
	$today = jmb_now()->format('Y-m-d');
	$x = array_values(array_filter($x, function ($e) use ($today) {
		return is_array($e) && jmb_valid_date($e['date'] ?? '') && ($e['date'] >= $today)
			&& (($e['time'] ?? '') === 'sunset' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $e['time'] ?? ''));
	}));
	usort($x, function ($a, $b) { return strcmp($a['date'] . $a['time'], $b['date'] . $b['time']); });
	return $x;
}

function jmb_extra_start_ts($e, $s) {
	if ($e['time'] === 'sunset') {
		$sun = jmb_sunset_ts($e['date'], $s);
		if (!$sun) return null;
		$start = $sun + (int) $s['sunset_offset'] * 60 - (int) $s['full_minutes'] * 60;
		return (int) (floor($start / 900) * 900);
	}
	return (new DateTimeImmutable($e['date'] . ' ' . $e['time'], jmb_tz()))->getTimestamp();
}

/**
 * Every open slot on one date: the regular rules plus any extra times she opened by hand.
 * Extra times skip the weekend rules and the "how far ahead" limit, but respect closed dates,
 * the minimum notice and one full session per day.
 */
function jmb_open_slots($ymd, $idx, $s, DateTimeImmutable $now) {
	$slots = jmb_rule_slots($ymd, $idx, $s, $now);
	$mine  = array_filter(jmb_extras(), function ($e) use ($ymd) { return $e['date'] === $ymd; });
	if (!$mine) return $slots;
	if (jmb_day($ymd) < $now->setTime(0, 0)->modify('+' . (int) $s['min_notice_days'] . ' days')) return $slots;
	if (isset(jmb_blocked_dates($s)[$ymd]) || jmb_full_on($idx, $ymd)) return $slots;
	$have = [];
	foreach ($slots as $x) if ($x['kind'] === 'full') $have[$x['start']] = true;
	foreach ($mine as $e) {
		$ts = jmb_extra_start_ts($e, $s);
		if ($ts === null) continue;
		$slot = jmb_slot('full', $ymd, $ts, (int) $s['full_minutes'], $s, [
			'note' => $e['time'] === 'sunset' ? 'Golden hour session (extra date).' : 'Extra date.',
		]);
		if (isset($have[$slot['start']])) continue;
		$have[$slot['start']] = true;
		$slots[] = $slot;
	}
	usort($slots, function ($a, $b) { return strcmp($a['start'], $b['start']); });
	return $slots;
}

/**
 * Open slots on one date after checking her Google Calendar (when connected).
 * $fresh = true skips the 5-minute cache (used right before a booking is saved).
 */
function jmb_day_slots($ymd, $idx, $s, DateTimeImmutable $now, $fresh = false) {
	$slots = jmb_open_slots($ymd, $idx, $s, $now);
	if (!$slots || !jmb_gcal_ready($s)) return $slots;
	$busy = jmb_gcal_busy_day($ymd, $fresh);
	if (!$busy) return $slots;
	$whole = $s['gcal_mode'] === 'day' || ($s['gcal_mode'] === 'weekend_day' && jmb_is_weekend(jmb_day($ymd)));
	if ($whole) return [];
	$buf = max(0, (int) $s['gcal_buffer']) * 60;
	return array_values(array_filter($slots, function ($x) use ($busy, $buf, $ymd) {
		$st = (new DateTimeImmutable("$ymd {$x['start']}", jmb_tz()))->getTimestamp();
		$en = (new DateTimeImmutable("$ymd {$x['end']}", jmb_tz()))->getTimestamp();
		foreach ($busy as [$a, $b]) if ($st - $buf < $b && $en + $buf > $a) return false;
		return true;
	}));
}

function jmb_slot_free($row, $s) {
	foreach (jmb_day_slots($row['date'], jmb_index_around($row['date']), $s, jmb_now()) as $x)
		if ($x['kind'] === $row['kind'] && $x['start'] === $row['start_time']) return true;
	return false;
}

/* ---------------------------------------------------- Google Calendar */

/** HTTP for Google: the safe variant unless a test mock is configured (JMB_GCAL_API). */
function jmb_http($url, $args) {
	return defined('JMB_GCAL_API') ? wp_remote_request($url, $args) : wp_safe_remote_request($url, $args);
}

function jmb_gcal_creds($s) {
	$j = json_decode((string) $s['gcal_json'], true);
	return (is_array($j) && !empty($j['client_email']) && !empty($j['private_key'])) ? $j : null;
}

function jmb_gcal_ready($s) { return jmb_gcal_creds($s) && $s['gcal_id']; }

function jmb_b64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

function jmb_gcal_note($msg) { update_option('jmb_gcal_status', ['time' => time(), 'msg' => mb_substr($msg, 0, 300)], false); }

/** OAuth access token for the service account (signed JWT grant), cached for its lifetime. */
function jmb_gcal_token($s) {
	$c = jmb_gcal_creds($s);
	if (!$c) return new WP_Error('jmb_gcal', 'No service account key saved.');
	$ck = 'jmb_gtok_' . md5($c['client_email'] . ($c['private_key_id'] ?? ''));
	$t  = get_transient($ck);
	if ($t) return $t;
	$uri = $c['token_uri'] ?? 'https://oauth2.googleapis.com/token';
	$now = time();
	$jwt = jmb_b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . jmb_b64url(wp_json_encode([
		'iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/calendar.events',
		'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600,
	]));
	if (!openssl_sign($jwt, $sig, $c['private_key'], OPENSSL_ALGO_SHA256)) return new WP_Error('jmb_gcal', 'Could not sign with the service account key.');
	$r = jmb_http($uri, ['method' => 'POST', 'timeout' => 15, 'body' => [
		'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . jmb_b64url($sig),
	]]);
	if (is_wp_error($r)) return $r;
	$j = json_decode(wp_remote_retrieve_body($r), true);
	if (empty($j['access_token'])) return new WP_Error('jmb_gcal', 'Google sign-in failed: ' . ($j['error_description'] ?? $j['error'] ?? 'HTTP ' . wp_remote_retrieve_response_code($r)));
	set_transient($ck, $j['access_token'], max(60, (int) ($j['expires_in'] ?? 3600) - 300));
	return $j['access_token'];
}

function jmb_gcal_api($method, $path, $query = [], $body = null) {
	$s   = jmb_settings();
	$tok = jmb_gcal_token($s);
	if (is_wp_error($tok)) return $tok;
	$base = defined('JMB_GCAL_API') ? JMB_GCAL_API : 'https://www.googleapis.com/calendar/v3';
	$args = ['method' => $method, 'timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $tok]];
	if ($body !== null) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode($body); }
	$r = jmb_http($base . $path . ($query ? '?' . http_build_query($query, '', '&') : ''), $args);
	if (is_wp_error($r)) return $r;
	$code = (int) wp_remote_retrieve_response_code($r);
	$j    = json_decode(wp_remote_retrieve_body($r), true);
	if ($code >= 300) return new WP_Error('jmb_gcal', is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : "HTTP $code", ['code' => $code]);
	return is_array($j) ? $j : [];
}

function jmb_gcal_events_path($s) { return '/calendars/' . rawurlencode($s['gcal_id']) . '/events'; }

/**
 * Her busy times between two dates, grouped by local date: ['Y-m-d' => [[start_ts, end_ts], ...]].
 * Skips cancelled events, events marked "Free", and the events this plugin created itself.
 * If Google cannot be reached, the last good copy is used so a hiccup does not open blocked days.
 */
function jmb_gcal_busy($from, $to, $fresh = false) {
	static $mem = [];
	$s   = jmb_settings();
	$key = 'jmb_gbusy_' . md5($s['gcal_id'] . $from . $to);
	if (!$fresh && isset($mem[$key])) return $mem[$key];
	if (!$fresh && is_array($c = get_transient($key))) return $mem[$key] = $c;
	$tz = jmb_tz();
	$q  = [
		'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 2500, 'timeZone' => $tz->getName(),
		'timeMin' => jmb_day($from)->format(DATE_RFC3339), 'timeMax' => jmb_day($to)->modify('+1 day')->format(DATE_RFC3339),
		'fields' => 'items(id,status,start,end,transparency,extendedProperties),nextPageToken',
	];
	$items = []; $n = 0;
	do {
		$r = jmb_gcal_api('GET', jmb_gcal_events_path($s), $q);
		if (is_wp_error($r)) {
			jmb_gcal_note('Could not read the calendar: ' . $r->get_error_message());
			$snap = get_option('jmb_gbusy_snapshot', []);
			$out = [];
			for ($d = jmb_day($from); $d <= jmb_day($to); $d = $d->modify('+1 day'))
				if (!empty($snap[$d->format('Y-m-d')])) $out[$d->format('Y-m-d')] = $snap[$d->format('Y-m-d')];
			return $mem[$key] = $out;
		}
		$items = array_merge($items, $r['items'] ?? []);
		$q['pageToken'] = $r['nextPageToken'] ?? null;
	} while ($q['pageToken'] && ++$n < 5);

	$busy = [];
	foreach ($items as $e) {
		if (($e['status'] ?? '') === 'cancelled' || ($e['transparency'] ?? '') === 'transparent') continue;
		if (!empty($e['extendedProperties']['private']['jmb_booking'])) continue;
		if (!empty($e['start']['date'])) {   // all-day: end date is exclusive
			$end = jmb_day($e['end']['date'] ?? $e['start']['date']);
			$d   = jmb_day($e['start']['date']);
			if ($end <= $d) $end = $d->modify('+1 day');
			for ($i = 0; $d < $end && $i < 370; $d = $d->modify('+1 day'), $i++)
				$busy[$d->format('Y-m-d')][] = [$d->getTimestamp(), $d->modify('+1 day')->getTimestamp()];
			continue;
		}
		$st = strtotime($e['start']['dateTime'] ?? '');
		$en = strtotime($e['end']['dateTime'] ?? '');
		if (!$st || !$en || $en <= $st) continue;
		$d = (new DateTimeImmutable('@' . $st))->setTimezone($tz)->setTime(0, 0);
		for ($i = 0; $d->getTimestamp() < $en && $i < 370; $d = $d->modify('+1 day'), $i++)
			$busy[$d->format('Y-m-d')][] = [$st, $en];
	}
	set_transient($key, $busy, 5 * MINUTE_IN_SECONDS);
	// keep a copy per date for outages; forget dates that are past or no longer busy
	$snap  = get_option('jmb_gbusy_snapshot', []);
	$today = jmb_now()->format('Y-m-d');
	for ($d = jmb_day($from); $d <= jmb_day($to); $d = $d->modify('+1 day')) unset($snap[$d->format('Y-m-d')]);
	foreach ($snap as $k => $v) if ($k < $today) unset($snap[$k]);
	update_option('jmb_gbusy_snapshot', array_merge($snap, $busy), false);
	jmb_gcal_note('OK, calendar read');
	return $mem[$key] = $busy;
}

/** Busy times for one date, from the month already loaded for the calendar when possible. */
function jmb_gcal_busy_day($ymd, $fresh = false) {
	global $jmb_gcal_range;
	if (!$fresh && is_array($jmb_gcal_range) && $ymd >= $jmb_gcal_range[0] && $ymd <= $jmb_gcal_range[1])
		return jmb_gcal_busy($jmb_gcal_range[0], $jmb_gcal_range[1])[$ymd] ?? [];
	return jmb_gcal_busy($ymd, $ymd, $fresh)[$ymd] ?? [];
}

/** Put a paid booking in her calendar (or update it). Returns a short status. */
function jmb_gcal_push($b) {
	global $wpdb;
	$s = jmb_settings();
	if (!$b || !$s['gcal_push'] || !jmb_gcal_ready($s)) return 'off';
	$tz   = jmb_tz()->getName();
	$desc = "Booked online through the website.\n\nClient: {$b['name']}\nEmail: {$b['email']}\nPhone: {$b['phone']}\n"
		. ($b['amount_cents'] ? 'Paid: ' . jmb_money((int) $b['amount_cents'], $s) . "\n" : '')
		. ($b['notes'] ? "\nNotes: {$b['notes']}\n" : '') . "\nBooking #{$b['id']}: " . admin_url('admin.php?page=jmb');
	$ev = [
		'summary'     => "{$b['kind_label']}: {$b['name']}",
		'location'    => $b['location'],
		'description' => $desc,
		'start'       => ['dateTime' => "{$b['date']}T{$b['start_time']}:00", 'timeZone' => $tz],
		'end'         => ['dateTime' => "{$b['date']}T{$b['end_time']}:00", 'timeZone' => $tz],
		'extendedProperties' => ['private' => ['jmb_booking' => (string) $b['id']]],
	];
	$path = jmb_gcal_events_path($s);
	$r = $b['gcal_event'] ? jmb_gcal_api('PUT', $path . '/' . rawurlencode($b['gcal_event']), [], $ev) : jmb_gcal_api('POST', $path, [], $ev);
	if (is_wp_error($r) && $b['gcal_event'] && (int) ($r->get_error_data()['code'] ?? 0) === 404) $r = jmb_gcal_api('POST', $path, [], $ev);
	$status = is_wp_error($r) ? 'error: ' . $r->get_error_message() : 'added';
	$data   = ['gcal_status' => mb_substr($status . ' ' . gmdate('Y-m-d H:i'), 0, 190)];
	if (!is_wp_error($r) && !empty($r['id'])) $data['gcal_event'] = $r['id'];
	$wpdb->update(jmb_table(), $data, ['id' => (int) $b['id']]);
	return $status;
}

/** Remove a cancelled booking's event from her calendar. */
function jmb_gcal_remove($b) {
	global $wpdb;
	$s = jmb_settings();
	if (!$b || !$b['gcal_event'] || !jmb_gcal_ready($s)) return;
	$r = jmb_gcal_api('DELETE', jmb_gcal_events_path($s) . '/' . rawurlencode($b['gcal_event']));
	$gone = !is_wp_error($r) || in_array((int) ($r->get_error_data()['code'] ?? 0), [404, 410], true);
	$wpdb->update(jmb_table(), $gone ? ['gcal_event' => '', 'gcal_status' => 'removed ' . gmdate('Y-m-d H:i')]
		: ['gcal_status' => mb_substr('remove failed: ' . $r->get_error_message(), 0, 190)], ['id' => (int) $b['id']]);
}

/* ------------------------------------------------------------- Stripe */

function jmb_stripe_ready($s) { return (bool) $s['stripe_secret'] && (bool) $s['stripe_webhook']; }

function jmb_stripe_test_mode($s) { return (bool) preg_match('/^(sk|rk)_test_/', $s['stripe_secret']); }

/** Call the Stripe API. JMB_STRIPE_API can point at a mock server for testing. */
function jmb_stripe($method, $path, $params = []) {
	$s    = jmb_settings();
	$base = defined('JMB_STRIPE_API') ? JMB_STRIPE_API : 'https://api.stripe.com';
	$args = [
		'method'  => $method,
		'timeout' => 15,
		'headers' => ['Authorization' => 'Bearer ' . $s['stripe_secret'], 'Stripe-Version' => '2024-06-20'],
		'body'    => $params ? http_build_query($params, '', '&') : null,
	];
	$r = defined('JMB_STRIPE_API') ? wp_remote_request($base . $path, $args) : wp_safe_remote_request($base . $path, $args);
	if (is_wp_error($r)) return $r;
	$j = json_decode(wp_remote_retrieve_body($r), true);
	$code = wp_remote_retrieve_response_code($r);
	if ($code >= 300 || !is_array($j)) {
		$msg = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : "HTTP $code";
		return new WP_Error('jmb_stripe', $msg);
	}
	return $j;
}

/** Verify a Stripe webhook signature (Stripe-Signature: t=...,v1=...). */
function jmb_stripe_verify($payload, $header, $secret, $tolerance = 300) {
	if (!$secret || !$header) return false;
	$t = null; $sigs = [];
	foreach (explode(',', $header) as $part) {
		[$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
		if ($k === 't') $t = (int) $v;
		if ($k === 'v1') $sigs[] = $v;
	}
	if (!$t || !$sigs || abs(time() - $t) > $tolerance) return false;
	$expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
	foreach ($sigs as $sig) if (hash_equals($expected, $sig)) return true;
	return false;
}

/** Return URL for Stripe: only our own site, never somewhere a visitor chose. */
function jmb_return_base($url) {
	$home = wp_parse_url(home_url());
	$u    = wp_parse_url((string) $url);
	if (!$u || empty($u['host']) || strcasecmp($u['host'], $home['host']) !== 0) return home_url('/');
	$scheme = $home['scheme'] ?? 'https';
	$port   = isset($u['port']) ? ':' . (int) $u['port'] : '';
	return $scheme . '://' . $u['host'] . $port . ($u['path'] ?? '/');
}

/* ------------------------------------------------------------- REST */

add_action('rest_api_init', function () {
	$routes = [
		['/availability', 'GET', 'jmb_rest_availability'],
		['/book', 'POST', 'jmb_rest_book'],
		['/status', 'GET', 'jmb_rest_status'],
		['/cancel', 'POST', 'jmb_rest_cancel'],
		['/stripe', 'POST', 'jmb_rest_stripe'],
	];
	foreach ($routes as [$path, $method, $cb])
		register_rest_route('jmb/v1', $path, ['methods' => $method, 'permission_callback' => '__return_true', 'callback' => $cb]);
});

function jmb_rest_availability(WP_REST_Request $req) {
	$s   = jmb_settings();
	$now = jmb_now();
	$ym  = (string) $req->get_param('month');
	if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) $ym = $now->format('Y-m');
	$first = jmb_day($ym . '-01');
	$last  = $first->modify('last day of this month');
	$idx   = jmb_active_index($first->modify('-40 days')->format('Y-m-d'), $last->modify('+40 days')->format('Y-m-d'));
	$days  = [];
	if (jmb_gcal_ready($s)) { global $jmb_gcal_range; $jmb_gcal_range = [$first->format('Y-m-d'), $last->format('Y-m-d')]; }
	for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
		$ymd = $d->format('Y-m-d');
		$days[$ymd] = jmb_day_slots($ymd, $idx, $s, $now);
	}
	$ex         = jmb_extras();
	$last_extra = $ex ? $ex[count($ex) - 1]['date'] : '';
	$res = new WP_REST_Response([
		'month'            => $ym,
		'today'            => $now->format('Y-m-d'),
		'min'              => $now->setTime(0, 0)->modify('+' . (int) $s['min_notice_days'] . ' days')->format('Y-m-d'),
		'max'              => max($now->setTime(0, 0)->modify('+' . (int) $s['max_ahead_days'] . ' days')->format('Y-m-d'), $last_extra),
		'checkout_minutes' => max(30, (int) $s['checkout_minutes']),
		'online'           => jmb_stripe_ready($s),
		'days'             => $days,
	]);
	$res->header('Cache-Control', 'no-store');
	return $res;
}

/**
 * Visitor IP for rate limiting. X-Forwarded-For is only trusted when the request comes from a private/loopback
 * address (our own reverse proxy, e.g. NPM); then the last entry is the one that proxy added. A public
 * REMOTE_ADDR (direct, or already resolved by Apache mod_remoteip behind Cloudflare) is used as is.
 */
function jmb_client_ip() {
	$ra = $_SERVER['REMOTE_ADDR'] ?? '';
	$public = filter_var($ra, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	if (!$public && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
		$last  = end($parts);
		if (filter_var($last, FILTER_VALIDATE_IP)) return $last;
	}
	return $ra;
}

function jmb_turnstile_ok($token, $s) {
	if (!$s['turnstile_secret']) return true;   // not enabled
	if (!$token) return false;
	$r = wp_safe_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
		'timeout' => 8,
		'body'    => ['secret' => $s['turnstile_secret'], 'response' => $token, 'remoteip' => jmb_client_ip()],
	]);
	if (is_wp_error($r)) return false;
	$j = json_decode(wp_remote_retrieve_body($r), true);
	return !empty($j['success']);
}

function jmb_valid_date($ymd) {
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $ymd, $m)) return false;
	return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function jmb_rest_book(WP_REST_Request $req) {
	global $wpdb;
	$s = jmb_settings();
	$p = $req->get_json_params() ?: $req->get_body_params();
	if (!empty($p['website'])) return new WP_Error('jmb_bad', 'Something went wrong.', ['status' => 400]);   // honeypot

	if (!jmb_stripe_ready($s)) return new WP_Error('jmb_offline', 'Online booking is not available right now. Please contact us to book.', ['status' => 503]);

	$ip     = jmb_client_ip();
	$rl_key = 'jmb_rl_' . md5($ip);
	$hits   = (int) get_transient($rl_key);
	if ($hits >= 10) return new WP_Error('jmb_rate', 'Too many booking attempts. Please try again in an hour.', ['status' => 429]);
	set_transient($rl_key, $hits + 1, HOUR_IN_SECONDS);
	$day_key = 'jmb_rd_' . md5($ip);
	if ((int) get_transient($day_key) >= max(1, (int) $s['ip_daily_holds']))
		return new WP_Error('jmb_rate', 'Too many booking attempts today. Please contact us to book.', ['status' => 429]);
	if (!jmb_turnstile_ok((string) ($p['cf_turnstile'] ?? ''), $s))
		return new WP_Error('jmb_bot', 'Please complete the "verify you are human" check and try again.', ['status' => 400]);

	$kind     = ($p['kind'] ?? '') === 'mini' ? 'mini' : 'full';
	$date     = (string) ($p['date'] ?? '');
	$start    = (string) ($p['start'] ?? '');
	$name     = sanitize_text_field($p['name'] ?? '');
	$email    = sanitize_email($p['email'] ?? '');
	$phone    = sanitize_text_field($p['phone'] ?? '');
	$location = sanitize_text_field($p['location'] ?? '');
	$notes    = sanitize_textarea_field($p['notes'] ?? '');

	if (!jmb_valid_date($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start))
		return new WP_Error('jmb_bad', 'Please pick a date and time.', ['status' => 400]);
	if (mb_strlen($name) < 2 || mb_strlen($name) > 120) return new WP_Error('jmb_bad', 'Please enter your name.', ['status' => 400]);
	if (!is_email($email)) return new WP_Error('jmb_bad', 'Please enter a valid email address.', ['status' => 400]);
	if ($kind === 'full' && mb_strlen($location) < 2) return new WP_Error('jmb_bad', 'Please tell us where you would like the session.', ['status' => 400]);
	$phone = mb_substr($phone, 0, 40); $location = mb_substr($location, 0, 200); $notes = mb_substr($notes, 0, 2000);

	$cents = jmb_charge_cents($kind, $s);
	if ($cents < 50) return new WP_Error('jmb_offline', 'Online booking is not available for this session type. Please contact us to book.', ['status' => 503]);

	$t       = jmb_table();
	$minutes = max(30, (int) $s['checkout_minutes']);
	$token   = wp_generate_password(32, false);

	if ((int) $wpdb->get_var("SELECT GET_LOCK('jmb_book', 10)") !== 1)
		return new WP_Error('jmb_busy', 'Busy, please try again.', ['status' => 503]);
	try {
		// one unpaid checkout per email: starting a new one releases the old one
		$old = $wpdb->get_results($wpdb->prepare(
			"SELECT id, stripe_session FROM $t WHERE email = %s AND source = 'web' AND status = 'pending' AND hold_until > %s", $email, jmb_utc_now()), ARRAY_A);
		foreach ($old as $o) jmb_release((int) $o['id'], $o['stripe_session']);

		$idx   = jmb_index_around($date);
		$slots = jmb_day_slots($date, $idx, $s, jmb_now(), true);   // fresh look at her calendar
		$slot  = null;
		foreach ($slots as $x) if ($x['kind'] === $kind && $x['start'] === $start) { $slot = $x; break; }
		if (!$slot) return new WP_Error('jmb_taken', 'Sorry, that time is no longer available. Please pick another.', ['status' => 409]);
		$parent = 0;
		if ($kind === 'mini') {
			$f = jmb_full_on($idx, $date);
			$parent = $f ? (int) $f['id'] : 0;
			// the mini client only learns the full session's spot if it is one of the public locations
			$location = $f ? (jmb_location_is_public($f['location'], $s) ? $f['location'] : 'Same spot as booking #' . (int) $f['id'] . ' (share privately)') : $location;
		}
		$wpdb->insert($t, [
			'kind' => $kind, 'date' => $date, 'start_time' => $slot['start'], 'end_time' => $slot['end'],
			'status' => 'pending', 'name' => $name, 'email' => $email, 'phone' => $phone,
			'location' => $location, 'notes' => $notes, 'parent_id' => $parent, 'source' => 'web',
			'created_at' => jmb_utc_now(), 'token' => $token, 'amount_cents' => $cents,
			// Stripe Checkout cannot be paid after it expires; the extra 10 minutes covers webhook delay
			'hold_until' => gmdate('Y-m-d H:i:s', time() + ($minutes + 10) * 60),
		]);
		$id = (int) $wpdb->insert_id;
	} finally {
		$wpdb->query("SELECT RELEASE_LOCK('jmb_book')");
	}
	if (!$id) return new WP_Error('jmb_db', 'Could not save the booking. Please try again.', ['status' => 500]);
	set_transient($day_key, (int) get_transient($day_key) + 1, DAY_IN_SECONDS);

	$b    = jmb_get($id);
	$base = jmb_return_base($p['return_url'] ?? '');
	$desc = sprintf('%s, %s, %s', $b['kind_label'], $b['date_pretty'], $b['time_pretty']);
	$session = jmb_stripe('POST', '/v1/checkout/sessions', [
		'mode'                 => 'payment',
		'customer_email'       => $email,
		'client_reference_id'  => (string) $id,
		'expires_at'           => time() + $minutes * 60,
		'success_url'          => add_query_arg(['jmb' => 'paid', 'jmb_ref' => $token], $base),
		'cancel_url'           => add_query_arg(['jmb' => 'cancel', 'jmb_ref' => $token], $base),
		'metadata'             => ['booking_id' => $id],
		'payment_intent_data'  => ['description' => "Booking #$id: $desc", 'metadata' => ['booking_id' => $id]],
		'line_items'           => [[
			'quantity'   => 1,
			'price_data' => [
				'currency'     => strtolower($s['currency']),
				'unit_amount'  => $cents,
				'product_data' => ['name' => $b['kind_label'] . ' ' . strtolower($s['charge_label']), 'description' => $desc],
			],
		]],
	]);
	if (is_wp_error($session) || empty($session['url']) || empty($session['id'])) {
		$wpdb->update($t, ['status' => 'cancelled', 'payment_note' => 'Stripe error: ' . (is_wp_error($session) ? $session->get_error_message() : 'no url')], ['id' => $id]);
		return new WP_Error('jmb_stripe', 'Payment could not be started. Please try again in a moment.', ['status' => 502]);
	}
	$wpdb->update($t, ['stripe_session' => $session['id']], ['id' => $id]);
	return ['ok' => true, 'checkout_url' => $session['url']];
}

function jmb_by_token($token) {
	global $wpdb;
	if (!preg_match('/^[A-Za-z0-9]{32}$/', (string) $token)) return null;
	$row = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . jmb_table() . ' WHERE token = %s', $token), ARRAY_A);
	return $row ? jmb_get((int) $row['id']) : null;
}

/** The page polls this after Stripe sends the client back. Only the client has the token. */
function jmb_rest_status(WP_REST_Request $req) {
	$b = jmb_by_token($req->get_param('ref'));
	if (!$b) return new WP_Error('jmb_none', 'Booking not found.', ['status' => 404]);
	$res = new WP_REST_Response([
		'status'  => $b['status'],
		'summary' => sprintf('%s on %s, %s', $b['kind_label'], $b['date_pretty'], $b['time_pretty']),
		'paid'    => $b['status'] === 'confirmed' && $b['amount_cents'] ? jmb_money((int) $b['amount_cents'], jmb_settings()) : '',
	]);
	$res->header('Cache-Control', 'no-store');
	return $res;
}

/** Client pressed "back" on Stripe Checkout: release the time right away. */
function jmb_rest_cancel(WP_REST_Request $req) {
	$p = $req->get_json_params() ?: $req->get_body_params();
	$b = jmb_by_token($p['ref'] ?? '');
	if (!$b) return new WP_Error('jmb_none', 'Booking not found.', ['status' => 404]);
	if ($b['status'] === 'pending') jmb_release((int) $b['id'], $b['stripe_session']);
	return ['ok' => true];
}

/** Cancel an unpaid booking and expire its Stripe Checkout so it can no longer be paid. */
function jmb_release($id, $stripe_session) {
	global $wpdb;
	if ($stripe_session) jmb_stripe('POST', '/v1/checkout/sessions/' . rawurlencode($stripe_session) . '/expire');
	$wpdb->query($wpdb->prepare('UPDATE ' . jmb_table() . " SET status = 'cancelled' WHERE id = %d AND status = 'pending'", $id));
}

/** Stripe webhook: checkout.session.completed / async_payment_succeeded / expired. */
function jmb_rest_stripe(WP_REST_Request $req) {
	global $wpdb;
	$s       = jmb_settings();
	$payload = $req->get_body();
	if (!jmb_stripe_verify($payload, (string) $req->get_header('stripe_signature'), $s['stripe_webhook']))
		return new WP_Error('jmb_sig', 'Bad signature.', ['status' => 400]);
	$event = json_decode($payload, true);
	$type  = $event['type'] ?? '';
	$obj   = $event['data']['object'] ?? [];
	$id    = (int) ($obj['metadata']['booking_id'] ?? $obj['client_reference_id'] ?? 0);
	$t     = jmb_table();
	$row   = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A) : null;
	if (!$row || ($obj['id'] ?? '') !== $row['stripe_session']) return ['ok' => true, 'ignored' => 'unknown session'];

	if ($type === 'checkout.session.expired') {
		$wpdb->query($wpdb->prepare("UPDATE $t SET status = 'expired' WHERE id = %d AND status = 'pending'", $id));
		return ['ok' => true];
	}
	if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) return ['ok' => true, 'ignored' => $type];
	if (($obj['payment_status'] ?? '') !== 'paid') return ['ok' => true, 'waiting' => 'payment not finished'];
	if ($row['status'] === 'confirmed') return ['ok' => true, 'already' => true];   // Stripe retries; stay idempotent

	$pi     = is_string($obj['payment_intent'] ?? null) ? $obj['payment_intent'] : '';
	$amount = (int) ($obj['amount_total'] ?? 0);
	if ($row['status'] !== 'pending' && !jmb_slot_free($row, $s)) {
		// paid, but the time was released and taken by someone else in between: tell her to refund or move it
		$wpdb->update($t, ['status' => 'conflict', 'payment_id' => $pi, 'amount_cents' => $amount, 'payment_note' => 'Paid after the time was released'], ['id' => $id]);
		$b = jmb_get($id);
		jmb_mail_admin("PAID but the time was taken: {$b['name']}, {$b['date_pretty']}",
			"Booking #$id was paid (" . jmb_money($amount, $s) . ") after its checkout had been released, and someone else has that time now. Refund it in Stripe or arrange another date with the client.\n" . admin_url('admin.php?page=jmb'));
		return ['ok' => true, 'conflict' => true];
	}
	jmb_confirm($id, 'Stripe ' . ($obj['id'] ?? ''), $pi, $amount);
	return ['ok' => true];
}

/* ----------------------------------------------------- booking helpers */

function jmb_get($id) {
	global $wpdb;
	$b = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . jmb_table() . ' WHERE id = %d', $id), ARRAY_A);
	if (!$b) return null;
	$s  = jmb_settings();
	$tz = jmb_tz();
	$st = new DateTimeImmutable($b['date'] . ' ' . $b['start_time'], $tz);
	$en = new DateTimeImmutable($b['date'] . ' ' . $b['end_time'], $tz);
	$b['kind_label']  = $b['kind'] === 'mini' ? $s['mini_label'] : $s['full_label'];
	$b['date_pretty'] = $st->format('l, F j, Y');
	$b['time_pretty'] = jmb_fmt($st) . ' – ' . jmb_fmt($en);
	$b['lead_name']   = sprintf('%s #%d – %s – %s', $b['kind_label'], $b['id'], $b['name'], $st->format('D M j'));
	$b['private_loc'] = strpos($b['location'], 'Same spot as booking #') === 0;
	return $b;
}

function jmb_confirm($id, $note = '', $payment_id = '', $amount_cents = null) {
	global $wpdb;
	$data = ['status' => 'confirmed', 'confirmed_at' => jmb_utc_now(), 'payment_note' => $note];
	if ($payment_id) $data['payment_id'] = $payment_id;
	if ($amount_cents !== null) $data['amount_cents'] = (int) $amount_cents;
	$wpdb->update(jmb_table(), $data, ['id' => $id]);
	$b = jmb_get($id);
	if (!$b) return;
	if ($b['source'] === 'web') { jmb_zap($b); jmb_gcal_push($b); }
	jmb_mail_confirmed($b);
}

function jmb_zap($b) {
	$s = jmb_settings();
	if (!$s['zapier_hook'] || !$b) return 'no Zapier URL';
	$parts = preg_split('/\s+/', trim($b['name']), 2);
	$payload = [
		'event'        => 'booking.paid',
		'booking_id'   => (int) $b['id'],
		'lead_name'    => $b['lead_name'],
		'kind'         => $b['kind'],
		'session_type' => $b['kind_label'],
		'date'         => $b['date'],
		'date_pretty'  => $b['date_pretty'],
		'start'        => $b['start_time'],
		'end'          => $b['end_time'],
		'time_pretty'  => $b['time_pretty'],
		'name'         => $b['name'],
		'first_name'   => $parts[0] ?? '',
		'last_name'    => $parts[1] ?? '',
		'email'        => $b['email'],
		'phone'        => $b['phone'],
		'location'     => $b['location'],
		'notes'        => (string) $b['notes'],
		'price'        => $b['kind'] === 'mini' ? $s['mini_price'] : $s['full_price'],
		'amount_paid'  => $b['amount_cents'] ? number_format($b['amount_cents'] / 100, 2, '.', '') : '',
		'payment_id'   => $b['payment_id'],
		'admin_url'    => admin_url('admin.php?page=jmb'),
	];
	$r = wp_safe_remote_post($s['zapier_hook'], [
		'timeout' => 8, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload),
	]);
	$status = is_wp_error($r) ? 'error: ' . $r->get_error_message() : 'sent ' . wp_remote_retrieve_response_code($r);
	global $wpdb;
	$wpdb->update(jmb_table(), ['zap_status' => mb_substr($status . ' ' . gmdate('Y-m-d H:i'), 0, 190)], ['id' => (int) $b['id']]);
	return $status;
}

function jmb_admin_email() {
	$s = jmb_settings();
	return is_email($s['notify_email']) ? $s['notify_email'] : get_option('admin_email');
}

function jmb_mail_admin($subject, $body) {
	wp_mail(jmb_admin_email(), '[Booking] ' . $subject, $body);
}

function jmb_mail_confirmed($b) {
	$s    = jmb_settings();
	$site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$paid = $b['amount_cents'] ? ' We received your ' . jmb_money((int) $b['amount_cents'], $s) . ' ' . strtolower($s['charge_label']) . '.' : '';
	$where = $b['location'] && !$b['private_loc'] ? " at {$b['location']}" : '';
	if ($b['email']) wp_mail($b['email'], "You're booked! {$b['kind_label']} – {$b['date_pretty']}",
		"Hi {$b['name']},\n\nYour {$b['kind_label']} on {$b['date_pretty']}, {$b['time_pretty']}$where is confirmed.$paid\n\n"
		. ($b['private_loc'] ? "We'll send you the exact location before your session.\n\n" : '')
		. "We'll be in touch with the details. See you then!\n\n$site");
	jmb_mail_admin("New booking: {$b['name']}, {$b['date_pretty']}",
		"{$b['kind_label']} #{$b['id']} is booked" . ($b['amount_cents'] ? ' and paid (' . jmb_money((int) $b['amount_cents'], $s) . ')' : '') . ".\n\n"
		. "{$b['date_pretty']}, {$b['time_pretty']}\nName: {$b['name']}\nEmail: {$b['email']}\nPhone: {$b['phone']}\nLocation: {$b['location']}\nNotes: {$b['notes']}\n\n"
		. admin_url('admin.php?page=jmb'));
}

add_action('jmb_expire_holds', function () {
	global $wpdb;
	$wpdb->query($wpdb->prepare('UPDATE ' . jmb_table() . " SET status = 'expired' WHERE status = 'pending' AND hold_until <= %s", jmb_utc_now()));
});

/** Privacy clean-up: delete bookings (names, emails, phones, notes) once the session date is older than retain_days. */
function jmb_purge_old() {
	global $wpdb;
	$days = (int) jmb_settings()['retain_days'];
	if ($days <= 0) return 0;
	$cutoff = jmb_now()->modify("-$days days")->format('Y-m-d');
	return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . jmb_table() . ' WHERE date < %s', $cutoff));
}
add_action('jmb_purge_old', 'jmb_purge_old');

/* -------------------------------------------------------- shortcode */

add_shortcode('jm_booking', function () {
	$s = jmb_settings();
	$u = plugin_dir_url(__FILE__);
	wp_enqueue_style('jmb', $u . 'assets/booking.css', [], JMB_VER);
	wp_enqueue_script('jmb', $u . 'assets/booking.js', [], JMB_VER, true);
	wp_localize_script('jmb', 'JMB', [
		'api'       => esc_url_raw(rest_url('jmb/v1/')),
		'weekStart' => (int) get_option('start_of_week', 1),
		'locations' => jmb_locations($s),
		'turnstile' => $s['turnstile_site'],
	]);
	if ($s['turnstile_site']) wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', [], null, true);
	$intro = $s['intro'] ? '<p class="jmb-intro">' . nl2br(esc_html($s['intro'])) . '</p>' : '';
	return '<div class="jmb" data-jmb>' . $intro . '<div class="jmb-cal" aria-live="polite"><p class="jmb-loading">Loading calendar…</p></div><div class="jmb-panel" hidden></div></div>';
});

/* ------------------------------------------------------------ admin */

add_action('admin_menu', function () {
	add_menu_page('Bookings', 'Bookings', 'manage_options', 'jmb', 'jmb_admin_bookings', 'dashicons-calendar-alt', 26);
	add_submenu_page('jmb', 'Bookings', 'Bookings', 'manage_options', 'jmb', 'jmb_admin_bookings');
	add_submenu_page('jmb', 'Booking settings', 'Settings', 'manage_options', 'jmb-settings', 'jmb_admin_settings');
});

function jmb_action_url($act, $id) {
	return wp_nonce_url(admin_url("admin-post.php?action=jmb_act&do=$act&id=$id"), "jmb_act_$id");
}

add_action('admin_post_jmb_act', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	global $wpdb;
	$id = (int) ($_GET['id'] ?? 0);
	check_admin_referer("jmb_act_$id");
	$do  = sanitize_key($_GET['do'] ?? '');
	$t   = jmb_table();
	$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A);
	$msg = 'not found';
	if ($row && $do === 'confirm') {
		if ($row['status'] !== 'pending' && !jmb_slot_free($row, jmb_settings())) $msg = 'not confirmed: that time is taken now';
		else { jmb_confirm($id, 'marked paid by hand'); $msg = 'confirmed'; }
	} elseif ($row && $do === 'cancel') {
		if ($row['status'] === 'pending') jmb_release($id, $row['stripe_session']);
		else $wpdb->update($t, ['status' => 'cancelled'], ['id' => $id]);
		jmb_gcal_remove(jmb_get($id));
		$msg = 'cancelled (refunds are done in Stripe)';
	} elseif ($row && $do === 'gcal') {
		$msg = 'calendar: ' . jmb_gcal_push(jmb_get($id));
	} elseif ($row && $do === 'resend') {
		$msg = 'sent to Zapier: ' . jmb_zap(jmb_get($id));
	}
	wp_safe_redirect(admin_url('admin.php?page=jmb&msg=' . rawurlencode($msg)));
	exit;
});

add_action('admin_post_jmb_extra_add', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	check_admin_referer('jmb_extra_add');
	$date = sanitize_text_field($_POST['date'] ?? '');
	$time = !empty($_POST['sunset']) ? 'sunset' : sanitize_text_field($_POST['time'] ?? '');
	if (!jmb_valid_date($date) || $date < jmb_now()->format('Y-m-d')) wp_die('Pick a date from today on.');
	if ($time !== 'sunset' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) wp_die('Pick a start time, or tick "golden hour".');
	$x = jmb_extras();
	foreach ($x as $e) if ($e['date'] === $date && $e['time'] === $time) { wp_safe_redirect(admin_url('admin.php?page=jmb&msg=' . rawurlencode('extra time was already open'))); exit; }
	$x[] = ['date' => $date, 'time' => $time];
	update_option('jmb_extra', $x, false);
	wp_safe_redirect(admin_url('admin.php?page=jmb&msg=' . rawurlencode('extra time opened')));
	exit;
});

add_action('admin_post_jmb_extra_del', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	$date = sanitize_text_field($_GET['date'] ?? '');
	$time = sanitize_text_field($_GET['time'] ?? '');
	check_admin_referer("jmb_extra_del_{$date}_{$time}");
	update_option('jmb_extra', array_values(array_filter(jmb_extras(), function ($e) use ($date, $time) {
		return !($e['date'] === $date && $e['time'] === $time);
	})), false);
	wp_safe_redirect(admin_url('admin.php?page=jmb&msg=' . rawurlencode('extra time removed (existing bookings are kept)')));
	exit;
});

add_action('admin_post_jmb_add', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	check_admin_referer('jmb_add');
	global $wpdb;
	$s     = jmb_settings();
	$kind  = ($_POST['kind'] ?? '') === 'mini' ? 'mini' : 'full';
	$date  = sanitize_text_field($_POST['date'] ?? '');
	$start = sanitize_text_field($_POST['start'] ?? '');
	if (!jmb_valid_date($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start)) wp_die('Date and start time are required.');
	$mins = $kind === 'mini' ? (int) $s['mini_minutes'] : (int) $s['full_minutes'];
	$end  = (new DateTimeImmutable("$date $start", jmb_tz()))->modify("+$mins minutes")->format('H:i');
	$wpdb->insert(jmb_table(), [
		'kind' => $kind, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'status' => 'confirmed',
		'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
		'phone' => '', 'location' => sanitize_text_field(wp_unslash($_POST['location'] ?? '')), 'notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
		'parent_id' => 0, 'source' => 'manual', 'created_at' => jmb_utc_now(), 'confirmed_at' => jmb_utc_now(),
	]);
	wp_safe_redirect(admin_url('admin.php?page=jmb&msg=added'));
	exit;
});

function jmb_admin_bookings() {
	global $wpdb;
	$s     = jmb_settings();
	$t     = jmb_table();
	$show  = ($_GET['show'] ?? '') === 'all' ? 'all' : 'upcoming';
	$today = jmb_now()->format('Y-m-d');
	$rows  = $show === 'all'
		? $wpdb->get_results("SELECT * FROM $t ORDER BY date DESC, start_time DESC LIMIT 500", ARRAY_A)
		: $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE date >= %s AND status IN ('pending','confirmed','conflict') ORDER BY date, start_time", $today), ARRAY_A);
	$dash = 'https://dashboard.stripe.com/' . (jmb_stripe_test_mode($s) ? 'test/' : '') . 'payments/';
	echo '<div class="wrap"><h1>Bookings</h1>';
	if (!jmb_stripe_ready($s)) echo '<div class="notice notice-warning"><p>Online booking is off until the Stripe keys are saved on the <a href="' . esc_url(admin_url('admin.php?page=jmb-settings')) . '">Settings</a> page.</p></div>';
	if (!empty($_GET['msg'])) echo '<div class="notice notice-success is-dismissible"><p>Booking ' . esc_html(wp_unslash($_GET['msg'])) . '.</p></div>';
	echo '<p><a href="' . esc_url(admin_url('admin.php?page=jmb')) . '">Upcoming</a> | <a href="' . esc_url(admin_url('admin.php?page=jmb&show=all')) . '">All</a></p>';
	echo '<table class="widefat striped"><thead><tr><th>#</th><th>Date</th><th>Time</th><th>Type</th><th>Status</th><th>Paid</th><th>Client</th><th>Location</th><th>Sent to</th><th></th></tr></thead><tbody>';
	if (!$rows) echo '<tr><td colspan="10">No bookings.</td></tr>';
	foreach ($rows as $r) {
		$b = jmb_get((int) $r['id']);
		$status = esc_html($r['status'] === 'pending' ? 'paying now' : ($r['status'] === 'conflict' ? 'PAID, time taken: refund or move' : $r['status']));
		$paid = '';
		if ($r['payment_id']) $paid = '<a href="' . esc_url($dash . rawurlencode($r['payment_id'])) . '" target="_blank" rel="noopener">' . esc_html(jmb_money((int) $r['amount_cents'], $s)) . '</a>';
		elseif ($r['status'] === 'confirmed' && $r['source'] === 'web') $paid = 'by hand';
		$acts = [];
		if (in_array($r['status'], ['pending', 'expired', 'cancelled'], true) && $r['source'] === 'web')
			$acts[] = '<a href="' . esc_url(jmb_action_url('confirm', $r['id'])) . '" onclick="return confirm(\'Mark this booking as paid (outside Stripe)?\')">Mark paid</a>';
		if (in_array($r['status'], ['pending', 'confirmed', 'conflict'], true))
			$acts[] = '<a href="' . esc_url(jmb_action_url('cancel', $r['id'])) . '" onclick="return confirm(\'Cancel this booking? The time opens up again. This does NOT refund: do that in Stripe.\')">Cancel</a>';
		if ($r['source'] === 'web' && $r['status'] === 'confirmed') $acts[] = '<a href="' . esc_url(jmb_action_url('resend', $r['id'])) . '">Resend to Zapier</a>';
		if ($r['source'] === 'web' && $r['status'] === 'confirmed' && jmb_gcal_ready($s) && $s['gcal_push'] && !$r['gcal_event'])
			$acts[] = '<a href="' . esc_url(jmb_action_url('gcal', $r['id'])) . '">Add to Google Calendar</a>';
		printf('<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s<br><small>%s %s</small></td><td>%s</td><td><small>%s</small></td><td>%s</td></tr>',
			$r['id'], esc_html($b['date_pretty']), esc_html($b['time_pretty']), esc_html($b['kind_label']), $status, $paid,
			esc_html($r['name']), esc_html($r['email']), esc_html($r['phone']), esc_html($r['location']),
			esc_html(trim($r['zap_status'] . ($r['gcal_status'] ? ' · calendar ' . $r['gcal_status'] : ''))), implode(' · ', $acts));
	}
	echo '</tbody></table>';
	$s = jmb_settings();
	echo '<h2>Open an extra time</h2><p>Adds a bookable time on any date, outside the usual rules (for example a Tuesday, or a third weekend this month). Closed dates and one session per day still apply.</p>';
	$extras = jmb_extras();
	if ($extras) {
		$idx = jmb_active_index($extras[0]['date'], end($extras)['date']);
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Date</th><th>Time</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ($extras as $e) {
			$ts    = jmb_extra_start_ts($e, $s);
			$st    = $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone(jmb_tz()) : null;
			$label = $st ? jmb_fmt($st) . ' – ' . jmb_fmt($st->modify('+' . (int) $s['full_minutes'] . ' minutes')) : '?';
			if ($e['time'] === 'sunset') $label .= ' (golden hour)';
			$soon  = jmb_day($e['date']) < jmb_now()->setTime(0, 0)->modify('+' . (int) $s['min_notice_days'] . ' days');
			$state = jmb_full_on($idx, $e['date']) ? 'booked'
				: (isset(jmb_blocked_dates($s)[$e['date']]) ? 'closed date'
				: ($soon ? 'too soon to book online (' . (int) $s['min_notice_days'] . '-day notice)' : 'open'));
			$del   = wp_nonce_url(admin_url('admin-post.php?action=jmb_extra_del&date=' . rawurlencode($e['date']) . '&time=' . rawurlencode($e['time'])), "jmb_extra_del_{$e['date']}_{$e['time']}");
			printf('<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">Remove</a></td></tr>',
				esc_html(jmb_day($e['date'])->format('l, F j, Y')), esc_html($label), esc_html($state), esc_url($del));
		}
		echo '</tbody></table>';
	}
	echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jmb_extra_add">';
	wp_nonce_field('jmb_extra_add');
	echo '<table class="form-table"><tr><th>Date</th><td><input type="date" name="date" required min="' . esc_attr(jmb_now()->format('Y-m-d')) . '"></td></tr>
		<tr><th>Start time</th><td><input type="time" name="time"> or <label><input type="checkbox" name="sunset" value="1"> golden hour (ends at sunset)</label></td></tr></table>';
	submit_button('Open this time', 'primary', 'submit', true);
	echo '</form>';
	echo '<h2>Add a booking by hand</h2><p>For sessions booked outside the website. It counts toward the weekend limit and opens the mini session like any confirmed booking.</p>';
	echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jmb_add">';
	wp_nonce_field('jmb_add');
	echo '<table class="form-table"><tr><th>Type</th><td><select name="kind"><option value="full">Full session</option><option value="mini">Mini session</option></select></td></tr>
		<tr><th>Date</th><td><input type="date" name="date" required></td></tr>
		<tr><th>Start time</th><td><input type="time" name="start" required></td></tr>
		<tr><th>Client name</th><td><input type="text" name="name" class="regular-text"></td></tr>
		<tr><th>Email</th><td><input type="email" name="email" class="regular-text"></td></tr>
		<tr><th>Location</th><td><input type="text" name="location" class="regular-text"></td></tr>
		<tr><th>Notes</th><td><textarea name="notes" rows="2" class="large-text"></textarea></td></tr></table>';
	submit_button('Add booking');
	echo '</form></div>';
}

add_action('admin_post_jmb_save', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	check_admin_referer('jmb_save');
	$in  = wp_unslash($_POST['jmb'] ?? []);
	$d   = jmb_defaults();
	$out = jmb_settings();
	foreach (['full_minutes', 'mini_minutes', 'mini_gap', 'sunset_offset', 'weekend_cap', 'slot_step', 'min_notice_days', 'max_ahead_days', 'ip_daily_holds', 'retain_days', 'checkout_minutes'] as $k)
		$out[$k] = isset($in[$k]) && is_numeric($in[$k]) ? (int) $in[$k] : $d[$k];
	$out['ip_daily_holds']   = max(1, $out['ip_daily_holds']);
	$out['retain_days']      = max(0, $out['retain_days']);
	$out['checkout_minutes'] = min(1440, max(30, $out['checkout_minutes']));
	foreach (['lat', 'lon'] as $k) $out[$k] = isset($in[$k]) && is_numeric($in[$k]) ? (string) (float) $in[$k] : $d[$k];
	foreach (['school_from', 'school_to', 'summer_from', 'summer_to'] as $k) $out[$k] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in[$k] ?? '') ? $in[$k] : $d[$k];
	foreach (['school_start', 'school_end'] as $k) $out[$k] = preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $in[$k] ?? '') ? $in[$k] : $d[$k];
	foreach (['school_days', 'summer_days'] as $k) $out[$k] = array_values(array_intersect(array_map('intval', (array) ($in[$k] ?? [])), [1, 2, 3, 4, 5]));
	foreach (['weekend_minis', 'weekday_minis'] as $k) $out[$k] = empty($in[$k]) ? 0 : 1;
	foreach (['full_label', 'mini_label', 'full_price', 'mini_price', 'charge_label'] as $k) $out[$k] = sanitize_text_field($in[$k] ?? '');
	foreach (['full_label', 'mini_label', 'charge_label'] as $k) if ($out[$k] === '') $out[$k] = $d[$k];   // names are never blank
	foreach (['full_amount', 'mini_amount'] as $k) { $c = jmb_cents($in[$k] ?? ''); $out[$k] = $c ? number_format($c / 100, 2, '.', '') : ''; }
	$out['currency'] = preg_match('/^[a-z]{3}$/', strtolower($in['currency'] ?? '')) ? strtolower($in['currency']) : $d['currency'];
	foreach (['intro', 'blocked', 'locations'] as $k) $out[$k] = sanitize_textarea_field($in[$k] ?? '');
	$out['timezone']     = in_array($in['timezone'] ?? '', timezone_identifiers_list(), true) ? $in['timezone'] : $d['timezone'];
	$out['zapier_hook']  = esc_url_raw(trim($in['zapier_hook'] ?? ''), ['https']);
	$out['notify_email'] = sanitize_email($in['notify_email'] ?? '');
	$out['turnstile_site'] = preg_replace('/[^A-Za-z0-9_-]/', '', $in['turnstile_site'] ?? '');
	// secrets: blank field = keep what is saved
	foreach (['turnstile_secret' => '/[^A-Za-z0-9_.-]/', 'stripe_secret' => '/[^A-Za-z0-9_]/', 'stripe_webhook' => '/[^A-Za-z0-9_]/'] as $k => $re)
		if (isset($in[$k]) && trim($in[$k]) !== '') $out[$k] = preg_replace($re, '', trim($in[$k]));
	if (!empty($in['turnstile_clear'])) { $out['turnstile_secret'] = ''; $out['turnstile_site'] = ''; }
	if (!empty($in['stripe_clear'])) { $out['stripe_secret'] = ''; $out['stripe_webhook'] = ''; }
	$out['gcal_id']     = sanitize_email($in['gcal_id'] ?? '') ?: sanitize_text_field($in['gcal_id'] ?? '');
	$out['gcal_mode']   = in_array($in['gcal_mode'] ?? '', ['weekend_day', 'day', 'overlap'], true) ? $in['gcal_mode'] : $d['gcal_mode'];
	$out['gcal_buffer'] = isset($in['gcal_buffer']) && is_numeric($in['gcal_buffer']) ? max(0, min(240, (int) $in['gcal_buffer'])) : $d['gcal_buffer'];
	$out['gcal_push']   = empty($in['gcal_push']) ? 0 : 1;
	if (isset($in['gcal_json']) && trim($in['gcal_json']) !== '') {
		$j = json_decode(trim($in['gcal_json']), true);
		if (is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) && openssl_pkey_get_private($j['private_key'])) {
			$out['gcal_json'] = wp_json_encode(array_intersect_key($j, array_flip(['type', 'client_email', 'private_key', 'private_key_id', 'token_uri'])));
		} else {
			wp_safe_redirect(admin_url('admin.php?page=jmb-settings&gcal=' . rawurlencode('that is not a valid service account key (paste the whole JSON file); nothing else was saved')));
			exit;
		}
	}
	if (!empty($in['gcal_clear'])) $out['gcal_json'] = '';
	unset($out['inbound_key'], $out['hold_hours']);   // from the Sprout-hold version
	update_option('jmb_settings', $out);
	wp_safe_redirect(admin_url('admin.php?page=jmb-settings&saved=1'));
	exit;
});

add_action('admin_post_jmb_teststripe', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	check_admin_referer('jmb_teststripe');
	$s = jmb_settings();
	$msg = 'no secret key saved';
	if ($s['stripe_secret']) {
		$r = jmb_stripe('GET', '/v1/checkout/sessions?limit=1');
		$msg = is_wp_error($r) ? 'error: ' . $r->get_error_message() : 'key works (' . (jmb_stripe_test_mode($s) ? 'TEST mode' : 'LIVE mode') . ')';
	}
	wp_safe_redirect(admin_url('admin.php?page=jmb-settings&stripe=' . rawurlencode($msg)));
	exit;
});

add_action('admin_post_jmb_testgcal', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	check_admin_referer('jmb_testgcal');
	$s = jmb_settings();
	if (!jmb_gcal_ready($s)) $msg = 'save the service account key and calendar ID first';
	else {
		$r = jmb_gcal_api('GET', jmb_gcal_events_path($s), ['singleEvents' => 'true', 'maxResults' => 250,
			'timeMin' => jmb_now()->format(DATE_RFC3339), 'timeMax' => jmb_now()->modify('+14 days')->format(DATE_RFC3339), 'fields' => 'items(id)']);
		if (is_wp_error($r)) $msg = 'cannot read the calendar: ' . $r->get_error_message() . ' (is it shared with the service account?)';
		else {
			$n = count($r['items'] ?? []);
			$t = jmb_now()->modify('+400 days')->format('Y-m-d');
			$w = jmb_gcal_api('POST', jmb_gcal_events_path($s), [], ['summary' => 'Booking plugin connection test (deleting now)',
				'start' => ['date' => $t], 'end' => ['date' => jmb_day($t)->modify('+1 day')->format('Y-m-d')], 'transparency' => 'transparent']);
			if (is_wp_error($w)) $msg = "reads OK ($n events in the next 14 days) but cannot add events: " . $w->get_error_message() . ' (share it with "Make changes to events")';
			else { jmb_gcal_api('DELETE', jmb_gcal_events_path($s) . '/' . rawurlencode($w['id'])); $msg = "connected: reads OK ($n events in the next 14 days) and can add events"; }
		}
	}
	wp_safe_redirect(admin_url('admin.php?page=jmb-settings&gcal=' . rawurlencode($msg)));
	exit;
});

add_action('admin_post_jmb_testzap', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	check_admin_referer('jmb_testzap');
	$s = jmb_settings();
	$msg = 'no Zapier URL saved';
	if ($s['zapier_hook']) {
		$r = wp_safe_remote_post($s['zapier_hook'], ['timeout' => 8, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode([
			'event' => 'booking.test', 'booking_id' => 0, 'lead_name' => 'Full session #0 – Test Client – Sat Jan 5',
			'session_type' => $s['full_label'], 'date' => '2030-01-05', 'date_pretty' => 'Saturday, January 5, 2030', 'start' => '15:30', 'end' => '17:30',
			'time_pretty' => '3:30 PM – 5:30 PM', 'name' => 'Test Client', 'first_name' => 'Test', 'last_name' => 'Client',
			'email' => 'test@example.com', 'phone' => '', 'location' => 'Test location', 'notes' => 'Test from the booking plugin',
			'price' => $s['full_price'], 'amount_paid' => '0.00', 'payment_id' => '',
		])]);
		$msg = is_wp_error($r) ? 'error: ' . $r->get_error_message() : 'sent, HTTP ' . wp_remote_retrieve_response_code($r);
	}
	wp_safe_redirect(admin_url('admin.php?page=jmb-settings&zap=' . rawurlencode($msg)));
	exit;
});

function jmb_admin_settings() {
	$s = jmb_settings();
	$f = function ($k, $type = 'text', $attrs = '') use ($s) {
		return sprintf('<input type="%s" name="jmb[%s]" value="%s" %s>', $type, $k, esc_attr($s[$k]), $attrs);
	};
	$secret = function ($k) use ($s) {
		return sprintf('<input type="password" name="jmb[%s]" value="" class="regular-text code" autocomplete="off" placeholder="%s">', $k, $s[$k] ? 'saved (leave blank to keep)' : 'not set');
	};
	$days = function ($k) use ($s) {
		$names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
		$h = '';
		foreach ($names as $n => $label) $h .= sprintf('<label style="margin-right:10px"><input type="checkbox" name="jmb[%s][]" value="%d" %s> %s</label>', $k, $n, in_array($n, array_map('intval', (array) $s[$k]), true) ? 'checked' : '', $label);
		return $h;
	};
	$hook = rest_url('jmb/v1/stripe');
	echo '<div class="wrap"><h1>Booking settings</h1>';
	if (!empty($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
	foreach (['zap' => 'Zapier test', 'stripe' => 'Stripe test', 'gcal' => 'Google Calendar'] as $q => $label)
		if (!empty($_GET[$q])) echo '<div class="notice notice-info is-dismissible"><p>' . $label . ': ' . esc_html(wp_unslash($_GET[$q])) . '</p></div>';
	echo '<p>Put <code>[jm_booking]</code> on any page to show the calendar.</p>';
	echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jmb_save">';
	wp_nonce_field('jmb_save');
	echo '<h2>Sessions</h2><table class="form-table">';
	echo '<tr><th>Full session</th><td>Name ' . $f('full_label') . ' Length ' . $f('full_minutes', 'number', 'min="15" step="15" style="width:80px"') . ' min, price shown ' . $f('full_price', 'text', 'style="width:100px" placeholder="$350"') . '</td></tr>';
	echo '<tr><th>Mini session</th><td>Name ' . $f('mini_label') . ' Length ' . $f('mini_minutes', 'number', 'min="15" step="15" style="width:80px"') . ' min, price shown ' . $f('mini_price', 'text', 'style="width:100px" placeholder="$150"') . '<br>
		Ends ' . $f('mini_gap', 'number', 'min="0" step="5" style="width:70px"') . ' minutes before the full session starts. Opens once the full session is paid.<br>
		<label><input type="checkbox" name="jmb[weekend_minis]" value="1" ' . checked($s['weekend_minis'], 1, false) . '> Offer on weekends</label>
		<label style="margin-left:12px"><input type="checkbox" name="jmb[weekday_minis]" value="1" ' . checked($s['weekday_minis'], 1, false) . '> Offer on weekdays</label></td></tr>';
	echo '<tr><th>Locations</th><td><textarea name="jmb[locations]" rows="12" class="large-text code">' . esc_textarea($s['locations']) . '</textarea><p class="description">One location per line, shown as a dropdown on the booking form. A line starting with # begins a group (e.g. "# Parks & nature"). Clients can also pick "Not sure yet" or type their own; their own spots are never shown to other clients. Leave empty for a plain text box.</p></td></tr>';
	echo '<tr><th>Intro text</th><td><textarea name="jmb[intro]" rows="3" class="large-text">' . esc_textarea($s['intro']) . '</textarea><p class="description">Shown above the calendar.</p></td></tr>';
	echo '</table><h2>Payment (Stripe)</h2><table class="form-table">';
	echo '<tr><th>Charged at booking</th><td>Full session ' . $f('full_amount', 'text', 'style="width:100px" placeholder="150.00"') . ' Mini session ' . $f('mini_amount', 'text', 'style="width:100px" placeholder="50.00"') . ' Currency ' . $f('currency', 'text', 'style="width:60px"') . ' Called ' . $f('charge_label', 'text', 'style="width:120px"') . '<p class="description">The amount clients pay online to book (a retainer, or the full price). A session type with no amount cannot be booked online.</p></td></tr>';
	echo '<tr><th>Secret key</th><td>' . $secret('stripe_secret') . '<p class="description">Stripe dashboard → Developers → API keys. Best: a <b>restricted key</b> with only "Checkout Sessions: Write". Use a <code>rk_test_</code>/<code>sk_test_</code> key to try it out without real charges.</p></td></tr>';
	echo '<tr><th>Webhook</th><td>Endpoint URL <input type="text" readonly class="large-text code" value="' . esc_attr($hook) . '" onclick="this.select()"><br>Signing secret ' . $secret('stripe_webhook') . '<p class="description">Stripe dashboard → Developers → Webhooks → Add endpoint with the URL above and the events <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code> and <code>checkout.session.expired</code>. Paste its signing secret (<code>whsec_…</code>) here. Bookings confirm only when Stripe calls this.</p>
		<label><input type="checkbox" name="jmb[stripe_clear]" value="1"> remove saved Stripe keys</label></td></tr>';
	echo '<tr><th>Checkout time</th><td>A time is held for ' . $f('checkout_minutes', 'number', 'min="30" max="1440" style="width:80px"') . ' minutes while the client pays (Stripe minimum 30). Unpaid holds are released automatically.</td></tr>';
	echo '</table><h2>Weekends (golden hour)</h2><table class="form-table">';
	echo '<tr><th>Sunset timing</th><td>The full session ends ' . $f('sunset_offset', 'number', 'step="5" style="width:80px"') . ' minutes after sunset (negative = before). Start times round down to the quarter hour.</td></tr>';
	echo '<tr><th>Location for sunset</th><td>Latitude ' . $f('lat', 'text', 'style="width:110px"') . ' Longitude ' . $f('lon', 'text', 'style="width:110px"') . ' Time zone ' . $f('timezone', 'text', 'style="width:170px"') . '</td></tr>';
	echo '<tr><th>Weekend limit</th><td>One booking per weekend. Once ' . $f('weekend_cap', 'number', 'min="0" style="width:70px"') . ' weekends in a month are booked, the other weekends that month close.</td></tr>';
	echo '</table><h2>Weekdays</h2><table class="form-table">';
	echo '<tr><th>School year</th><td>From ' . $f('school_start', 'text', 'style="width:70px" placeholder="08-12"') . ' to ' . $f('school_end', 'text', 'style="width:70px" placeholder="05-28"') . ' (MM-DD, every year)</td></tr>';
	echo '<tr><th>School-year days</th><td>' . $days('school_days') . ' from ' . $f('school_from', 'time') . ' to ' . $f('school_to', 'time') . '</td></tr>';
	echo '<tr><th>Summer days</th><td>' . $days('summer_days') . ' from ' . $f('summer_from', 'time') . ' to ' . $f('summer_to', 'time') . '</td></tr>';
	echo '<tr><th>Start times</th><td>Every ' . $f('slot_step', 'number', 'min="15" step="15" style="width:70px"') . ' minutes inside those hours (one booking per day).</td></tr>';
	echo '</table><h2>Booking window</h2><table class="form-table">';
	echo '<tr><th>Notice</th><td>Bookable from ' . $f('min_notice_days', 'number', 'min="0" style="width:70px"') . ' days out up to ' . $f('max_ahead_days', 'number', 'min="1" style="width:80px"') . ' days ahead.</td></tr>';
	echo '<tr><th>Closed dates</th><td><textarea name="jmb[blocked]" rows="4" class="large-text code" placeholder="2026-12-24 to 2027-01-02">' . esc_textarea($s['blocked']) . '</textarea><p class="description">One date (YYYY-MM-DD) or range (YYYY-MM-DD to YYYY-MM-DD) per line.</p></td></tr>';
	echo '<tr><th>Spam protection</th><td>Cloudflare Turnstile site key ' . $f('turnstile_site', 'text', 'class="regular-text code"') . '<br>Secret key ' . $secret('turnstile_secret') . ' <label><input type="checkbox" name="jmb[turnstile_clear]" value="1"> turn off</label><p class="description">Free from the Cloudflare dashboard (Turnstile → Add widget). When set, the form shows a "verify you are human" check.</p>
		Each visitor (IP address) can start at most ' . $f('ip_daily_holds', 'number', 'min="1" style="width:70px"') . ' bookings a day; each email address one unpaid checkout at a time.</td></tr>';
	echo '<tr><th>Clean-up</th><td>Delete bookings ' . $f('retain_days', 'number', 'min="0" style="width:80px"') . ' days after the session date (0 = keep forever). Removes the client\'s name, email, phone and notes from this site; Stripe and Sprout keep their own records. Runs once a day.</td></tr>';
	echo '<tr><th>Notify email</th><td>' . $f('notify_email', 'email', 'class="regular-text" placeholder="' . esc_attr(get_option('admin_email')) . '"') . '</td></tr>';
	$gc = jmb_gcal_creds($s);
	$gs = get_option('jmb_gcal_status');
	echo '</table><h2>Google Calendar</h2><table class="form-table">';
	echo '<tr><th>Connection</th><td><p class="description" style="margin-top:0">One-time setup: (1) at console.cloud.google.com create a project and enable the <b>Google Calendar API</b>; (2) under IAM &amp; Admin → Service accounts create a service account, then Keys → Add key → JSON, and paste the downloaded file below; (3) in Google Calendar open the calendar\'s Settings → <b>Share with specific people</b>, add the service account\'s email with <b>Make changes to events</b>; (4) enter the calendar ID (for the main calendar: the Gmail address). Then press "Check Google Calendar".</p>
		Service account key<br><textarea name="jmb[gcal_json]" rows="3" class="large-text code" autocomplete="off" placeholder="' . esc_attr($gc ? 'saved: ' . $gc['client_email'] . ' (paste a new key to replace it)' : '{ "type": "service_account", ... }') . '"></textarea>'
		. ($gc ? '<br>Share the calendar with: <code>' . esc_html($gc['client_email']) . '</code> <label style="margin-left:12px"><input type="checkbox" name="jmb[gcal_clear]" value="1"> remove key</label>' : '')
		. '<br>Calendar ID ' . $f('gcal_id', 'text', 'class="regular-text" placeholder="name@gmail.com"')
		. ($gs ? '<p class="description">Last check: ' . esc_html(wp_date('M j, g:i A', (int) $gs['time'])) . ', ' . esc_html($gs['msg']) . '</p>' : '') . '</td></tr>';
	$modes = ['weekend_day' => 'Weekends: anything on her calendar closes that day. Weekdays: only clashing times close.', 'day' => 'Any event closes that whole day.', 'overlap' => 'Only times that clash with an event close.'];
	$opts = '';
	foreach ($modes as $k => $label) $opts .= '<label style="display:block"><input type="radio" name="jmb[gcal_mode]" value="' . esc_attr($k) . '" ' . checked($s['gcal_mode'], $k, false) . '> ' . esc_html($label) . '</label>';
	echo '<tr><th>When she has plans</th><td>' . $opts . 'Keep ' . $f('gcal_buffer', 'number', 'min="0" max="240" step="5" style="width:70px"') . ' minutes free before and after her events (travel). Events marked "Free" in Google are ignored.</td></tr>';
	echo '<tr><th>Add bookings</th><td><label><input type="checkbox" name="jmb[gcal_push]" value="1" ' . checked($s['gcal_push'], 1, false) . '> Put paid bookings on her calendar (with the client\'s details), and remove them if cancelled</label></td></tr>';
	echo '</table><h2>Sprout Studio via Zapier</h2><table class="form-table">';
	echo '<tr><th>Paid booking → Zapier</th><td>' . $f('zapier_hook', 'url', 'class="large-text" placeholder="https://hooks.zapier.com/hooks/catch/…"') . '<p class="description">Zap: trigger <b>Webhooks by Zapier → Catch Hook</b> (paste its URL here), action <b>Sprout Studio → Create Lead</b>. Sent once a booking is paid, with <code>lead_name</code>, name, email, phone, date, time, location, notes and <code>amount_paid</code>.</p></td></tr>';
	echo '</table>';
	submit_button('Save settings');
	echo '</form>';
	foreach (['jmb_teststripe' => 'Check the Stripe key', 'jmb_testgcal' => 'Check Google Calendar', 'jmb_testzap' => 'Send a test booking to Zapier'] as $act => $label) {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="' . $act . '">';
		wp_nonce_field($act);
		submit_button($label, 'secondary', 'submit', false);
		echo '</form>';
	}
	echo '</div>';
}
