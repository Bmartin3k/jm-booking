<?php
/**
 * Test only: instead of sending email, record every wp_mail() call (with attachments, base64) in the
 * option jmb_test_mail. Loaded as a must-use plugin by tests/run.sh; never install this on a real site.
 */
add_filter('pre_wp_mail', function ($null, $atts) {
	if (get_option('jmb_test_mail_fail')) {   // simulate a broken mail setup
		do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP Error: Could not authenticate.'));
		return false;
	}
	$log   = get_option('jmb_test_mail', []);
	$files = [];
	foreach ((array) $atts['attachments'] as $f) {
		if (is_string($f) && is_readable($f)) $files[] = ['name' => basename($f), 'data' => base64_encode(file_get_contents($f))];
	}
	$log[] = ['to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'], 'attachments' => $files];
	update_option('jmb_test_mail', $log, false);
	return true;
}, 10, 2);
