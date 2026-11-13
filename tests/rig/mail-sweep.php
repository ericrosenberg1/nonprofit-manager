<?php
/**
 * Real-WordPress checks for the 11-03 members and email sweep: the send-queue
 * run lock, one body render per batch, deleted newsletters, the sender-name
 * override, click tracking on non-web links, level counts, the bulk-read
 * cache skip, the batched digest queue, the unsubscribe banner, the empty
 * preferences page, post notification copy, and a removed default level.
 *
 *   wp eval-file tests/rig/mail-sweep.php
 * Every message is caught before it leaves (sendmail is /usr/bin/true). It
 * adds contacts with ".sweep.test" addresses and deletes what it made.
 * Set NPMP_RIG_URL to the rig's web address to also check the unsubscribe
 * form's rate limit over HTTP.
 */

$fails = 0;
$total = 0;
$ok    = function ( $label, $cond ) use ( &$fails, &$total ) {
	$total++;
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

global $wpdb;
$sent = array();
add_action(
	'phpmailer_init',
	static function ( $m ) use ( &$sent ) {
		$to = array();
		foreach ( $m->getToAddresses() as $addr ) {
			$to[] = $addr[0];
		}
		$sent[] = array(
			'to'      => implode( ',', $to ),
			'from'    => $m->FromName,
			'subject' => $m->Subject,
			'body'    => $m->Body,
		);
		$m->isSendmail();
		$m->Sendmail = '/usr/bin/true';
	},
	9999
);

$mm  = NPMP_Member_Manager::get_instance();
$ids = array();
$add = function ( $row ) use ( $mm, &$ids ) {
	$id = $mm->add_member( $row );
	if ( ! is_wp_error( $id ) ) {
		$ids[] = (int) $id;
		return (int) $id;
	}
	return 0;
};

$table = $wpdb->prefix . 'npmp_newsletter_queue';
$wpdb->query( "DELETE FROM {$table} WHERE status = 'pending'" ); // phpcs:ignore
npmp_mail_queue_unlock( 'newsletter' );
npmp_mail_queue_unlock( 'digest' );

// Clear anything a killed earlier run left behind, so the counts below hold.
$stale = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE '%@sweep.test'" ); // phpcs:ignore
$stale = array_merge( $stale, get_posts( array( 'post_type' => 'npmp_newsletter', 'title' => 'Sweep issue', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
foreach ( $stale as $stale_id ) {
	wp_delete_post( (int) $stale_id, true );
}

// Gold contacts another rig left on the site count too, so the level checks
// compare against the counts before the rig's three.
$gold_before_all  = (int) ( NPMP_Member_Manager::get_instance()->count_by_level()['Gold'] ?? 0 );
$gold_before_subs = (int) ( NPMP_Member_Manager::get_instance()->count_by_level( 'subscribed' )['Gold'] ?? 0 );
$a = $add( array( 'name' => 'Ann Sweep', 'email' => 'ann@sweep.test', 'status' => 'subscribed', 'membership_level' => 'Gold' ) );
$b = $add( array( 'name' => 'Bob Sweep', 'email' => 'bob@sweep.test', 'status' => 'subscribed', 'membership_level' => 'Gold' ) );
$c = $add( array( 'name' => 'Cy Sweep', 'email' => 'cy@sweep.test', 'status' => 'unsubscribed', 'membership_level' => 'Gold' ) );
$ok( 'three contacts', 3 === count( $ids ) );

// ---- Queue lock ------------------------------------------------------------
$nl = wp_insert_post(
	array(
		'post_type'    => 'npmp_newsletter',
		'post_status'  => 'publish',
		'post_title'   => 'Sweep issue',
		'post_content' => '<p>Hello <a href="mailto:info@sweep.test">write us</a> <a href="#top">top</a> <a href="https://example.org/?a=1&amp;b=2">site</a></p>',
	)
);
update_post_meta( $nl, '_npmp_newsletter_template', 'none' );
update_post_meta( $nl, '_npmp_newsletter_levels', array( 'Gold' ) );
update_option( 'npmp_newsletter_track_clicks', 1 );

$queued = NPMP_Newsletter_Manager::queue_newsletter( $nl );
$ok( 'Gold newsletter queues the two subscribed members', 2 === (int) $queued );

$ok( 'first lock is granted', npmp_mail_queue_lock( 'newsletter' ) );
$ok( 'a second lock is refused while the first is held', ! npmp_mail_queue_lock( 'newsletter' ) );
$sent = array();
NPMP_Newsletter_Manager::process_queue();
$ok( 'a run that finds the lock held sends nothing', 0 === count( $sent ) );

// Age the lock past its TTL: the next run takes it over.
$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 3600 ) ), array( 'option_name' => 'npmp_queue_lock_newsletter' ) );
$renders = 0;
$count_renders = static function ( $c ) use ( &$renders ) {
	$renders++;
	return $c;
};
add_filter( 'the_content', $count_renders, 999 );
NPMP_Newsletter_Manager::process_queue();
remove_filter( 'the_content', $count_renders, 999 );
$ok( 'a stale lock is taken over and the batch sends', 2 === count( $sent ) );
$ok( 'the body renders once for the whole batch', 1 === $renders );
$ok( 'the lock is released after the run', npmp_mail_queue_lock( 'newsletter' ) );
npmp_mail_queue_unlock( 'newsletter' );
$ok( 'the newsletter is marked sent', 'sent' === get_post_meta( $nl, '_npmp_newsletter_status', true ) );

// ---- Click tracking only wraps web links -------------------------------------
$body = $sent[0]['body'] ?? '';
$ok( 'a mailto: link is left alone', false !== strpos( $body, 'href="mailto:info@sweep.test"' ) );
$ok( 'an #anchor link is left alone', false !== strpos( $body, 'href="#top"' ) );
$ok( 'an https link written with &amp; is tracked', false === strpos( $body, 'href="https://example.org/?a=1&amp;b=2"' ) && false !== strpos( $body, 'npmp_track=click' ) );
$hdrs = npmp_get_list_unsubscribe_headers( 'ann@sweep.test' );
$ok( 'List-Unsubscribe headers are built with one-click POST', 2 === count( $hdrs ) && 0 === strpos( $hdrs[1], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );

// ---- Deleted newsletter ------------------------------------------------------
$gone = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Gone', 'post_content' => 'x' ) );
update_post_meta( $gone, '_npmp_newsletter_levels', array( 'Gold' ) );
NPMP_Newsletter_Manager::queue_newsletter( $gone );
wp_delete_post( $gone, true );
$sent = array();
NPMP_Newsletter_Manager::process_queue();
$ok( 'rows for a deleted newsletter send nothing', 0 === count( $sent ) );
$ok( 'and are marked failed', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE newsletter_id = %d AND status = 'failed'", $gone ) ) ); // phpcs:ignore

// ---- Sender-name override never sticks --------------------------------------
$sent = array();
npmp_send_mail( 'ann@sweep.test', 'One', 'x', array( 'From: Art Group <noreply@sweep.test>' ) );
npmp_send_mail( 'ann@sweep.test', 'Two', 'x' );
$site_name = npmp_email_get_settings()['from_name'];
$ok( 'a From header name is used for its own message', 'Art Group' === ( $sent[0]['from'] ?? '' ) );
$ok( 'the next message goes back to the site name', $site_name === ( $sent[1]['from'] ?? '' ) );
$ok( 'the override stack is empty after sends', '' === npmp_mail_from_name_override() );
$thrower = static function () {
	throw new RuntimeException( 'boom' );
};
add_filter( 'pre_wp_mail', $thrower );
try {
	npmp_send_mail( 'ann@sweep.test', 'Three', 'x', array( 'From: Leak Test <noreply@sweep.test>' ) );
} catch ( RuntimeException $e ) {
	unset( $e );
}
remove_filter( 'pre_wp_mail', $thrower );
$ok( 'a send that throws still pops its override', '' === npmp_mail_from_name_override() && ! npmp_is_internal_mail_send() );

// ---- Level counts ------------------------------------------------------------
$all  = $mm->count_by_level();
$subs = $mm->count_by_level( 'subscribed' );
$ok( 'count_by_level() counts every Gold contact', (int) ( $all['Gold'] ?? 0 ) === $gold_before_all + 3 );
$ok( "count_by_level('subscribed') leaves out the unsubscribed one", (int) ( $subs['Gold'] ?? 0 ) === $gold_before_subs + 2 );
$ok( 'and matches what a Gold newsletter reaches', (int) ( $subs['Gold'] ?? 0 ) === count( $mm->get_members( array( 'per_page' => -1, 'status' => 'subscribed', 'level' => 'Gold' ) ) ) );

// ---- Bulk reads skip the object cache ----------------------------------------
wp_cache_delete( 'npmp_member_' . $a, 'npmp_members' );
$mm->get_members( array( 'per_page' => -1, 'status' => 'subscribed' ) );
$ok( 'an unbounded read does not cache each contact', false === wp_cache_get( 'npmp_member_' . $a, 'npmp_members' ) );
$mm->get_members( array( 'per_page' => 50, 'search' => 'ann@sweep.test' ) );
$ok( 'a paged read still caches', false !== wp_cache_get( 'npmp_member_' . $a, 'npmp_members' ) );

// ---- Weekly digest queue -----------------------------------------------------
$dtable = $wpdb->prefix . 'npmp_digest_queue';
update_option( 'npmp_enable_weekly_digest', 1 );
update_post_meta( $a, '_npmp_weekly_digest', '1' );
update_post_meta( $b, '_npmp_weekly_digest', '1' );
$recent = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Sweep digest post', 'post_content' => 'Body [gallery] text' ) );
npmp_process_weekly_digest();
$digest_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dtable} WHERE email LIKE '%@sweep.test' AND status = 'pending'" ); // phpcs:ignore
$ok( 'the digest queues each subscriber in one batched insert', 2 === $digest_rows );
$pending = get_option( 'npmp_digest_pending_content' );
$ok( 'the digest strips shortcodes from excerpts', false === strpos( (string) ( $pending['content'] ?? '' ), '[gallery]' ) );
$ok( 'the digest has no em dash', false === strpos( (string) ( $pending['content'] ?? '' ), '&mdash;' ) );
npmp_mail_queue_lock( 'digest' );
$sent = array();
npmp_process_digest_queue();
$ok( 'a held digest lock stops a second run', 0 === count( $sent ) );
npmp_mail_queue_unlock( 'digest' );
npmp_process_digest_queue();
$ok( 'the digest drains once the lock is free', count( $sent ) >= 2 );
update_option( 'npmp_enable_weekly_digest', 0 );
wp_delete_post( $recent, true );

// ---- Post notification copy --------------------------------------------------
update_option( 'npmp_enable_event_notifications', 1 );
$nameless = $add( array( 'email' => 'nameless@sweep.test', 'status' => 'subscribed' ) );
update_post_meta( $nameless, '_npmp_notify_events', '1' );
$event = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Sweep Picnic', 'post_content' => 'Bring a dish [npmp_calendar]' ) );
$sent = array();
npmp_process_post_notification( $event, '_npmp_notify_events' );
$mine = array_values( array_filter( $sent, static function ( $m ) {
	return 'nameless@sweep.test' === $m['to'];
} ) );
$ok( 'an event notice says "New event:"', isset( $mine[0] ) && 'New event: Sweep Picnic' === $mine[0]['subject'] );
$ok( 'a nameless contact gets "Hi there,"', isset( $mine[0] ) && false !== strpos( $mine[0]['body'], 'Hi there,' ) );
$ok( 'the excerpt drops the shortcode', isset( $mine[0] ) && false === strpos( $mine[0]['body'], '[npmp_calendar]' ) );
update_option( 'npmp_enable_event_notifications', 0 );
wp_delete_post( $event, true );

// ---- Unsubscribe banner ------------------------------------------------------
$_GET['npmp_unsubscribe']       = 'unsubscribed';
$_GET['npmp_unsubscribe_nonce'] = wp_create_nonce( 'npmp_banner_npmp_unsubscribe_unsubscribed' );
$banner                          = npmp_get_banner_html( 'npmp_unsubscribe', npmp_get_membership_form_settings() );
unset( $_GET['npmp_unsubscribe'], $_GET['npmp_unsubscribe_nonce'] );
$ok( 'the one-click landing banner says you are unsubscribed', false !== strpos( $banner, 'unsubscribed. We won' ) );

// ---- Empty preferences page --------------------------------------------------
foreach ( array( 'npmp_enable_post_notifications', 'npmp_enable_event_notifications', 'npmp_enable_weekly_digest' ) as $opt ) {
	update_option( $opt, 0 );
}
$_GET['email'] = 'ann@sweep.test';
$_GET['token'] = npmp_generate_preferences_token( 'ann@sweep.test' );
$page          = npmp_manage_preferences_shortcode();
if ( has_action( 'npmp_preferences_form_fields' ) ) {
	$ok( 'preferences page keeps the form when an add-on prints fields', false !== strpos( $page, '<form' ) );
} else {
	$ok( 'with nothing to choose, the page says so instead of a bare Save button', false === strpos( $page, '<form' ) && false !== strpos( $page, 'no email choices' ) );
}
$ok( 'the preferences page links to unsubscribe', false !== strpos( $page, 'npmp_one_click_unsubscribe' ) );
unset( $_GET['email'], $_GET['token'] );

// ---- Removing the default level clears it ------------------------------------
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );
$rig_levels  = get_option( 'npmp_membership_levels', null );
$rig_default = get_option( 'npmp_default_membership_level', null );
update_option( 'npmp_membership_levels', array( 'Gold', 'Sweep Tier' ) );
update_option( 'npmp_default_membership_level', 'Sweep Tier' );
// The handler runs on admin_init for the Membership Forms page and ends with
// a redirect, so call it for that page and stop at the redirect.
$_SERVER['REQUEST_METHOD']        = 'POST';
$_GET['page']                     = 'npmp_membership_forms';
$_POST['npmp_remove_level_nonce'] = wp_create_nonce( 'npmp_remove_membership_level' );
$_POST['npmp_level_to_remove']    = 'Sweep Tier';
$rig_stop = static function ( $location ) {
	throw new RuntimeException( (string) $location );
};
add_filter( 'wp_redirect', $rig_stop, 999 );
$rig_went = '';
try {
	npmp_membership_levels_handle_post();
} catch ( RuntimeException $e ) {
	$rig_went = $e->getMessage();
}
remove_filter( 'wp_redirect', $rig_stop, 999 );
unset( $_POST['npmp_remove_level_nonce'], $_POST['npmp_level_to_remove'], $_GET['page'] );
$_SERVER['REQUEST_METHOD'] = 'GET';
$ok( 'removing a level goes back to the levels list', false !== strpos( $rig_went, 'npmp_levels=removed' ) );
$ok( 'removing the default level clears the default', '' === get_option( 'npmp_default_membership_level' ) );
$ok( 'and the level list stays a plain list', array( 'Gold' ) === get_option( 'npmp_membership_levels' ) );
null === $rig_levels ? delete_option( 'npmp_membership_levels' ) : update_option( 'npmp_membership_levels', $rig_levels );
null === $rig_default ? delete_option( 'npmp_default_membership_level' ) : update_option( 'npmp_default_membership_level', $rig_default );

// ---- Unsubscribe form rate limit (HTTP) --------------------------------------
$base = getenv( 'NPMP_RIG_URL' );
if ( $base ) {
	wp_set_current_user( 0 );
	$nonce = wp_create_nonce( 'npmp_email_unsubscribe' );
	$last  = '';
	for ( $i = 0; $i < 11; $i++ ) {
		$r    = wp_remote_post(
			rtrim( $base, '/' ) . '/wp-admin/admin-post.php',
			array(
				'redirection' => 0,
				'timeout'     => 20,
				'headers'     => array( 'Referer' => rtrim( $base, '/' ) . '/' ),
				'body'        => array(
					'action'                       => 'npmp_handle_form',
					'npmp_action'                  => 'email_unsubscribe',
					'npmp_email_unsubscribe_nonce' => $nonce,
					'npmp_email'                   => 'ann@sweep.test',
				),
			)
		);
		$last = (string) wp_remote_retrieve_header( $r, 'location' );
	}
	$ok( 'the 11th unsubscribe request in an hour gets the busy banner', false !== strpos( $last, 'npmp_unsubscribe=busy' ) );
	// Clear the counters so a rerun starts fresh.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%npmp\\_rl\\_%'" ); // phpcs:ignore
}

// ---- Clean up ----------------------------------------------------------------
update_option( 'npmp_newsletter_track_clicks', 0 );
$wpdb->query( "DELETE FROM {$table} WHERE email LIKE '%@sweep.test'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$dtable} WHERE email LIKE '%@sweep.test'" ); // phpcs:ignore
wp_delete_post( $nl, true );
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
printf( "%d passed, %d failed\n", $total - $fails, $fails );
exit( $fails ? 1 : 0 );
