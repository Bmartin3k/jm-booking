<?php
/**
 * Contracts: the client reads and e-signs the contract before paying.
 *
 * Evidence kept per signature (ESIGN Act): the exact text shown (stored, with a SHA-256 fingerprint),
 * the typed signature and name, both consent statements, time (UTC), IP address and browser.
 * The client gets a copy by email (PDF attachment + private link). Contracts are kept for
 * contract_retain_years, independent of the booking clean-up.
 */

if (!defined('ABSPATH')) exit;

function jmb_contracts_table() { global $wpdb; return $wpdb->prefix . 'jmb_contracts'; }

function jmb_contract_install() {
	global $wpdb;
	$t = jmb_contracts_table();
	$c = $wpdb->get_charset_collate();
	dbDelta("CREATE TABLE $t (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		token CHAR(32) NOT NULL DEFAULT '',
		kind VARCHAR(10) NOT NULL DEFAULT '',
		title VARCHAR(200) NOT NULL DEFAULT '',
		body LONGTEXT NOT NULL,
		body_hash CHAR(64) NOT NULL DEFAULT '',
		template_hash CHAR(64) NOT NULL DEFAULT '',
		signer_name VARCHAR(120) NOT NULL DEFAULT '',
		signature VARCHAR(120) NOT NULL DEFAULT '',
		signer_email VARCHAR(190) NOT NULL DEFAULT '',
		consent TEXT NOT NULL,
		signed_at DATETIME NOT NULL,
		ip VARCHAR(45) NOT NULL DEFAULT '',
		user_agent VARCHAR(255) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY booking_id (booking_id),
		KEY token (token)
	) $c;");
}

/** The two statements the client ticks. Stored verbatim with every signature. */
function jmb_contract_consents() {
	return [
		'esign'  => 'I agree to sign this contract electronically, and that typing my name below is my legal signature.',
		'agree'  => 'I have read this contract and agree to its terms.',
	];
}

function jmb_contract_enabled($s) { return !empty($s['contract_on']) && trim((string) $s['contract_full']) !== ''; }

function jmb_contract_template($kind, $s) {
	$t = $kind === 'mini' && trim((string) $s['contract_mini']) !== '' ? $s['contract_mini'] : $s['contract_full'];
	return str_replace("\r\n", "\n", (string) $t);
}

/** Fill-in fields available in templates. */
function jmb_contract_fields($d, $s) {
	$tz   = jmb_tz();
	$st   = new DateTimeImmutable($d['date'] . ' ' . $d['start'], $tz);
	$en   = new DateTimeImmutable($d['date'] . ' ' . $d['end'], $tz);
	$loc  = (string) ($d['location'] ?? '');
	if (strpos($loc, 'Same spot as booking #') === 0) $loc = 'to be shared by the photographer before the session';
	$cents = jmb_charge_cents($d['kind'], $s);
	return [
		'{business_name}' => $s['business_name'] !== '' ? $s['business_name'] : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
		'{client_name}'   => $d['name'],
		'{client_email}'  => $d['email'],
		'{client_phone}'  => $d['phone'] !== '' ? $d['phone'] : '(not given)',
		'{session_type}'  => $d['kind'] === 'mini' ? $s['mini_label'] : $s['full_label'],
		'{session_date}'  => $st->format('l, F j, Y'),
		'{session_time}'  => jmb_fmt($st) . ' – ' . jmb_fmt($en),
		'{session_length}' => (int) round(($en->getTimestamp() - $st->getTimestamp()) / 60) . ' minutes',
		'{location}'      => $loc !== '' ? $loc : '(to be agreed)',
		'{price}'         => $d['kind'] === 'mini' ? $s['mini_price'] : $s['full_price'],
		'{retainer}'      => $cents ? jmb_money($cents, $s) : '',
		'{balance}'       => jmb_balance_cents($d['kind'], $s) ? jmb_money(jmb_balance_cents($d['kind'], $s), $s) : jmb_money(0, $s),
		'{balance_due}'   => (int) $s['pay_due_days'] . ' days before the session',
		'{today}'         => jmb_now()->format('F j, Y'),
	];
}

/**
 * Render the contract for one booking: plain text, fields filled in. Returns [title, body, blocks, hash].
 * blocks = [['h'|'p', text], ...] for display; the hash covers exactly what the client is shown.
 */
function jmb_contract_render($d, $s) {
	$tpl   = jmb_contract_template($d['kind'], $s);
	$body  = trim(strtr($tpl, jmb_contract_fields($d, $s)));
	$blocks = [];
	$title  = '';
	foreach (preg_split('/\n\s*\n/', $body) as $para) {
		$para = trim($para);
		if ($para === '') continue;
		if (preg_match('/^#\s*(.+)$/', $para, $m) && strpos($para, "\n") === false) {
			$blocks[] = ['h', trim($m[1])];
			if ($title === '') $title = trim($m[1]);
		} else {
			$blocks[] = ['p', preg_replace('/[ \t]*\n[ \t]*/', "\n", $para)];
		}
	}
	if ($title === '') $title = 'Photography Agreement';
	return [$title, $body, $blocks, hash('sha256', $body), hash('sha256', $tpl)];
}

function jmb_contract_for_booking($booking_id) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . jmb_contracts_table() . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 1', $booking_id), ARRAY_A);
}

function jmb_contract_by_token($token) {
	global $wpdb;
	if (!preg_match('/^[A-Za-z0-9]{32}$/', (string) $token)) return null;
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . jmb_contracts_table() . ' WHERE token = %s', $token), ARRAY_A);
}

function jmb_contract_url($c) { return add_query_arg('jmb_contract', $c['token'], home_url('/')); }

/** Validate the signing fields from the booking form. Returns WP_Error or the cleaned signature. */
function jmb_contract_check_request($p, $render) {
	$sig = trim(sanitize_text_field($p['signature'] ?? ''));
	if (empty($p['agree_esign']) || empty($p['agree_terms']))
		return new WP_Error('jmb_contract', 'Please tick both boxes to sign the contract.', ['status' => 400]);
	if (mb_strlen($sig) < 2 || mb_strlen($sig) > 120)
		return new WP_Error('jmb_contract', 'Please type your full name to sign the contract.', ['status' => 400]);
	if (!hash_equals($render[3], (string) ($p['contract_hash'] ?? '')))
		return new WP_Error('jmb_contract_changed', 'The contract was updated while you were reading it. Please review it again.', ['status' => 409]);
	return $sig;
}

function jmb_contract_save($booking_id, $kind, $render, $name, $email, $signature) {
	global $wpdb;
	$wpdb->insert(jmb_contracts_table(), [
		'booking_id' => $booking_id, 'token' => wp_generate_password(32, false), 'kind' => $kind,
		'title' => mb_substr($render[0], 0, 200), 'body' => $render[1], 'body_hash' => $render[3], 'template_hash' => $render[4],
		'signer_name' => $name, 'signature' => $signature, 'signer_email' => $email,
		'consent' => wp_json_encode(array_values(jmb_contract_consents())), 'signed_at' => jmb_utc_now(),
		'ip' => mb_substr(jmb_client_ip(), 0, 45), 'user_agent' => mb_substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
	]);
	return (int) $wpdb->insert_id;
}

/** "Signed electronically by ..." lines, used on the page, in the PDF and in the email. */
function jmb_contract_audit_lines($c) {
	$tz   = jmb_tz();
	$when = (new DateTimeImmutable($c['signed_at'], new DateTimeZone('UTC')))->setTimezone($tz);
	$cons = json_decode($c['consent'], true) ?: [];
	$lines = [
		'Signed electronically by: ' . $c['signature'],
		'Name and email on the booking: ' . $c['signer_name'] . ' <' . $c['signer_email'] . '>',
		'Date and time: ' . $when->format('F j, Y \a\t g:i:s A T') . ' (' . gmdate('Y-m-d H:i:s', strtotime($c['signed_at'] . ' UTC')) . ' UTC)',
		'IP address: ' . $c['ip'],
		'Browser: ' . $c['user_agent'],
		'Booking number: ' . $c['booking_id'],
		'Document fingerprint (SHA-256): ' . $c['body_hash'],
	];
	foreach ($cons as $i => $text) $lines[] = 'Agreed (' . ($i + 1) . '): ' . $text;
	return $lines;
}

function jmb_contract_verify($c) { return hash_equals($c['body_hash'], hash('sha256', $c['body'])); }

/* ---------------------------------------------------------------- PDF */

/**
 * Minimal PDF writer (no libraries): Courier/Courier-Bold, Letter size, word-wrapped text.
 * Courier is monospaced, so line wrapping is exact. Text is converted to Windows-1252.
 */
/** UTF-8 → Windows-1252 (one byte per glyph, so wrapping by length is exact). */
function jmb_pdf_enc($s) {
	$s = strtr($s, ["\u{2013}" => '-', "\u{2014}" => '-', "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2026}" => '...', "\u{00A0}" => ' ']);
	$c = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s) : false;
	return $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
}

/** Escape already-encoded text for a PDF string literal. */
function jmb_pdf_esc($s) { return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']); }

function jmb_contract_pdf($c) {
	$size = 10; $lead = 13.5; $cw = 6.0;                 // Courier: every glyph is 600/1000 em
	$left = 60; $top = 732; $bottom = 60;
	$cols = (int) floor((612 - 2 * $left) / $cw);
	$lines = [];                                          // [bold?, text]
	$add = function ($text, $bold = false) use (&$lines, $cols) {
		foreach (explode("\n", $text) as $raw) {
			$raw = rtrim(jmb_pdf_enc($raw));
			if ($raw === '') { $lines[] = [false, '']; continue; }
			foreach (explode("\n", wordwrap($raw, $cols, "\n", true)) as $l) $lines[] = [$bold, $l];
		}
	};
	foreach (preg_split('/\n\s*\n/', $c['body']) as $para) {
		$para = trim($para);
		if ($para === '') continue;
		if (preg_match('/^#\s*(.+)$/', $para, $m) && strpos($para, "\n") === false) $add(mb_strtoupper(trim($m[1])), true);
		else $add($para);
		$lines[] = [false, ''];
	}
	$lines[] = [false, ''];
	$add('ELECTRONIC SIGNATURE', true);
	foreach (jmb_contract_audit_lines($c) as $l) $add($l);

	$pages = []; $cur = []; $y = $top;
	foreach ($lines as $ln) {
		if ($y < $bottom) { $pages[] = $cur; $cur = []; $y = $top; }
		if (!($ln[1] === '' && !$cur)) $cur[] = [$ln[0], $ln[1], $y];
		$y -= $lead;
	}
	if ($cur) $pages[] = $cur;
	$n = count($pages);

	$objs = [];
	$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
	$kids = [];
	$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
	$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>';
	$o = 5;
	foreach ($pages as $i => $pg) {
		$stream = '';
		foreach ($pg as [$bold, $text, $y]) {
			if ($text === '') continue;
			$stream .= 'BT /' . ($bold ? 'F2' : 'F1') . " $size Tf $left " . number_format($y, 2, '.', '') . ' Td (' . jmb_pdf_esc($text) . ") Tj ET\n";
		}
		$stream .= "BT /F1 8 Tf $left 36 Td (" . jmb_pdf_esc(jmb_pdf_enc($c['title'] . ' - booking ' . $c['booking_id'] . ' - page ' . ($i + 1) . " of $n")) . ") Tj ET\n";
		$objs[$o] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . ($o + 1) . " 0 R >>";
		$objs[$o + 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
		$kids[] = "$o 0 R";
		$o += 2;
	}
	$objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $n >>";
	$objs[$o] = '<< /Title (' . jmb_pdf_esc(jmb_pdf_enc($c['title'])) . ') /Producer (JM Session Booking) /CreationDate (D:' . gmdate('YmdHis', strtotime($c['signed_at'] . ' UTC')) . 'Z) >>';
	ksort($objs);

	$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
	$offsets = [];
	foreach ($objs as $num => $body) { $offsets[$num] = strlen($pdf); $pdf .= "$num 0 obj\n$body\nendobj\n"; }
	$xref = strlen($pdf);
	$pdf .= 'xref' . "\n0 " . ($o + 1) . "\n0000000000 65535 f \n";
	for ($i = 1; $i <= $o; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
	$pdf .= 'trailer' . "\n<< /Size " . ($o + 1) . " /Root 1 0 R /Info $o 0 R >>\nstartxref\n$xref\n%%EOF\n";
	return $pdf;
}

function jmb_contract_pdf_name($c) {
	return 'contract-booking-' . (int) $c['booking_id'] . '-' . sanitize_file_name(strtolower(preg_replace('/\s+/', '-', $c['signer_name']))) . '.pdf';
}

/** Pages with a private link (contract, payment): never inside another site's frame, never MIME-sniffed. */
function jmb_private_page_headers() {
	header('X-Frame-Options: DENY', true);
	header("Content-Security-Policy: frame-ancestors 'none'", true);
	header('X-Content-Type-Options: nosniff', true);
}

/* ------------------------------------------------------- page for the client */

add_action('template_redirect', function () {
	if (!isset($_GET['jmb_contract'])) return;
	$c = jmb_contract_by_token(sanitize_text_field(wp_unslash($_GET['jmb_contract'])));
	nocache_headers();
	jmb_private_page_headers();
	header('X-Robots-Tag: noindex, nofollow', true);
	header('Referrer-Policy: no-referrer', true);
	if (!$c) { status_header(404); wp_die('This contract link is not valid.', 'Not found', ['response' => 404]); }
	if (!empty($_GET['pdf'])) {
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="' . jmb_contract_pdf_name($c) . '"');
		echo jmb_contract_pdf($c);
		exit;
	}
	header('Content-Type: text/html; charset=utf-8');
	echo jmb_contract_html($c, add_query_arg(['jmb_contract' => $c['token'], 'pdf' => 1], home_url('/')), true);
	exit;
});

/** Stand-alone HTML copy of a signed contract (client page and admin view). */
function jmb_contract_html($c, $pdf_url, $standalone) {
	$h = '';
	foreach (preg_split('/\n\s*\n/', $c['body']) as $para) {
		$para = trim($para);
		if ($para === '') continue;
		if (preg_match('/^#\s*(.+)$/', $para, $m) && strpos($para, "\n") === false) $h .= '<h2>' . esc_html(trim($m[1])) . '</h2>';
		else $h .= '<p>' . nl2br(esc_html($para)) . '</p>';
	}
	$audit = '';
	foreach (jmb_contract_audit_lines($c) as $l) $audit .= '<li>' . esc_html($l) . '</li>';
	$ok = jmb_contract_verify($c);
	$out = '<div class="jmb-contract">' . $h
		. '<section class="jmb-sigblock"><h2>Electronic signature</h2><p class="jmb-sig">' . esc_html($c['signature']) . '</p><ul>' . $audit . '</ul>'
		. '<p class="jmb-verify">' . ($ok ? 'Fingerprint check: this text is unchanged since it was signed.' : 'WARNING: this text no longer matches its fingerprint.') . '</p></section>'
		. '<p class="jmb-actions"><a href="' . esc_url($pdf_url) . '">Download PDF</a> · <a href="#" onclick="window.print();return false">Print</a></p></div>';
	if (!$standalone) return $out;
	return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
		. '<title>' . esc_html($c['title']) . '</title><style>
		:root{--bg:#fff;--ink:#1f1f1f;--muted:#666;--line:#ddd}
		@media (prefers-color-scheme:dark){:root{--bg:#161616;--ink:#eee;--muted:#aaa;--line:#333}}
		body{background:var(--bg);color:var(--ink);font:16px/1.6 Georgia,"Times New Roman",serif;margin:0;padding:24px 16px}
		.jmb-contract{max-width:720px;margin:0 auto}h2{font-family:system-ui,sans-serif;font-size:1.15rem;margin:1.6em 0 .4em}
		.jmb-contract>h2:first-child{font-size:1.6rem;margin-top:0}.jmb-sigblock{border-top:1px solid var(--line);margin-top:2em;padding-top:.5em}
		.jmb-sig{font:italic 1.6rem Georgia,serif;margin:.2em 0}.jmb-sigblock ul{font:13px/1.5 system-ui,sans-serif;color:var(--muted);padding-left:1.1em;word-break:break-word}
		.jmb-verify{font:13px system-ui,sans-serif}.jmb-actions{font:15px system-ui,sans-serif;margin-top:2em}.jmb-actions a{color:inherit}
		@media print{.jmb-actions{display:none}body{padding:0}}
		</style></head><body>' . $out . '</body></html>';
}

/* --------------------------------------------------------- admin view */

// Registered under Bookings (so the page has a title) and then hidden from the menu.
add_action('admin_head', function () { remove_submenu_page('jmb', 'jmb-contract'); });
add_action('admin_menu', function () {   // priority 20: after the Bookings menu itself exists
	add_submenu_page('jmb', 'Signed contract', 'Signed contract', 'manage_options', 'jmb-contract', function () {
		global $wpdb;
		$c = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . jmb_contracts_table() . ' WHERE id = %d', (int) ($_GET['id'] ?? 0)), ARRAY_A);
		echo '<div class="wrap"><p><a href="' . esc_url(admin_url('admin.php?page=jmb&show=all')) . '">‹ Bookings</a></p>';
		if (!$c) { echo '<p>Contract not found.</p></div>'; return; }
		echo '<style>.jmb-contract{max-width:760px;background:#fff;padding:24px 32px;border:1px solid #dcdcde}.jmb-sig{font:italic 1.5rem Georgia,serif}</style>';
		echo jmb_contract_html($c, wp_nonce_url(admin_url('admin-post.php?action=jmb_contract_pdf&id=' . (int) $c['id']), 'jmb_contract_pdf_' . (int) $c['id']), false);
		echo '<p>Client\'s private link: <code>' . esc_html(jmb_contract_url($c)) . '</code></p></div>';
	});
}, 20);

add_action('admin_post_jmb_contract_pdf', function () {
	if (!current_user_can('manage_options')) wp_die('No.');
	global $wpdb;
	$id = (int) ($_GET['id'] ?? 0);
	check_admin_referer('jmb_contract_pdf_' . $id);
	$c = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . jmb_contracts_table() . ' WHERE id = %d', $id), ARRAY_A);
	if (!$c) wp_die('Not found.');
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="' . jmb_contract_pdf_name($c) . '"');
	echo jmb_contract_pdf($c);
	exit;
});

/* ------------------------------------------------------------ retention */

function jmb_contract_purge() {
	global $wpdb;
	$years = (int) jmb_settings()['contract_retain_years'];
	if ($years <= 0) return 0;
	$cutoff = gmdate('Y-m-d H:i:s', strtotime("-$years years"));
	return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . jmb_contracts_table() . ' WHERE signed_at < %s', $cutoff));
}
add_action('jmb_purge_old', 'jmb_contract_purge');
