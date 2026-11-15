<?php
/**
 * Real-WordPress check of the donation pieces Pete asked for on the support
 * forum (wordpress.org/support/topic/donations-23/):
 *
 * - Inside an email (npmp_email_the_content(), a newsletter test render) the
 *   donation form block and shortcode print a table-based Donate button to
 *   the donation page, never a form. On a web page they still print the form.
 * - A newsletter fills that button's link with the reader's signed token.
 * - Prefill: a logged-in user, a valid token, and a forged token, all over
 *   real HTTP against the site.
 * - Payment links: the settings screen saves a good template, refuses a bad
 *   one without touching the saved links, and the public form prints one
 *   button per link carrying the template.
 *
 * Run inside a throwaway WordPress with donations and newsletters on, the
 * site served by `PHP_CLI_SERVER_WORKERS=4 php -S` at its home URL:
 *   NPMP_RIG_URL=http://127.0.0.1:8873 wp eval-file tests/rig/donation-links.php
 * Without NPMP_RIG_URL the HTTP checks are skipped and reported as skipped.
 * It removes every post, user, and option it makes.
 */

$fails = 0;
$passes = 0;
$ok    = function ( $label, $cond ) use ( &$fails, &$passes ) {
	$cond ? $passes++ : $fails++;
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_donation_email_button' ) || ! function_exists( 'npmp_email_the_content' ) || ! class_exists( 'NPMP_Newsletter_Manager' ) ) {
	echo "  FAIL  donations or newsletters module not loaded (turn both on)\n";
	exit( 1 );
}

$keep_options = array( 'npmp_donation_page_id', 'npmp_enabled_payment_gateways', 'npmp_payment_links', 'npmp_paypal_email' );
$old_options  = array();
foreach ( $keep_options as $name ) {
	$old_options[ $name ] = get_option( $name, null );
}
$made_posts = array();
$made_users = array();

$page_id      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Give rig', 'post_content' => '<!-- wp:nonprofit-manager/donation-form /-->' ) );
$made_posts[] = $page_id;
update_option( 'npmp_donation_page_id', $page_id );
update_option( 'npmp_enabled_payment_gateways', array( 'paypal_link', 'payment_link' ) );
update_option( 'npmp_paypal_email', 'treasurer@example.org' );
update_option( 'npmp_payment_links', array( array( 'label' => 'Pay with Monzo', 'url' => 'https://monzo.me/rigclub/{amount}?d={note}', 'note' => 'Subs' ) ) );
$permalink = get_permalink( $page_id );

echo "Donation form inside an email\n";
foreach ( array(
	'block'     => '<!-- wp:nonprofit-manager/donation-form /-->',
	'shortcode' => '[npmp_donation_form]',
) as $kind => $body ) {
	$html = npmp_email_the_content( $body );
	$ok( "{$kind}: a table button, no form", false !== strpos( $html, 'class="npmp-email-donate"' ) && false === stripos( $html, '<form' ) && false === stripos( $html, '<script' ) && false === stripos( $html, '<input' ) );
	$ok( "{$kind}: links to the donation page with the reader tag", false !== strpos( $html, 'href="' . esc_url( $permalink ) . ( false === strpos( $permalink, '?' ) ? '?' : '&#038;' ) . 'npmp_donor=[npmp_donor_key]"' ) );
	$ok( "{$kind}: brand color and white text inline", false !== strpos( $html, 'bgcolor="#0f766e"' ) && false !== strpos( $html, 'color:#ffffff' ) );
}
$ok( 'render flag is off again after the render', ! npmp_email_is_rendering() );
$web = do_blocks( '<!-- wp:nonprofit-manager/donation-form /-->' );
$ok( 'on a web page the block still prints the form', false !== strpos( $web, '<form id="npmp-multi-gateway-form"' ) && false === strpos( $web, 'npmp-email-donate' ) );

echo "\nButton target\n";
update_option( 'npmp_donation_page_id', 0 );
$ok( 'no donation page set: the first page holding the form', 0 === strpos( npmp_donation_email_button() ? npmp_donation_email_target_url() : '', $permalink ) );
wp_update_post( array( 'ID' => $page_id, 'post_status' => 'draft' ) );
$ok( 'no published page with the form: nothing printed', '' === npmp_donation_email_button() );
wp_update_post( array( 'ID' => $page_id, 'post_status' => 'publish' ) );
update_option( 'npmp_donation_page_id', $page_id );

echo "\nNewsletter fills the reader's token\n";
$nl_id        = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Rig appeal', 'post_content' => "<!-- wp:paragraph --><p>Please give.</p><!-- /wp:paragraph -->\n<!-- wp:nonprofit-manager/donation-form /-->" ) );
$made_posts[] = $nl_id;
update_post_meta( $nl_id, '_npmp_newsletter_template', 'none' );
$nl_html = NPMP_Newsletter_Manager::get_newsletter_content( $nl_id, true, null, 'pete.rig@example.org' );
$token   = '';
if ( preg_match( '/npmp_donor=([A-Za-z0-9_.\-]+)/', $nl_html, $m ) ) {
	$token = $m[1];
}
$ok( 'test send carries a filled token, not the tag', '' !== $token && false === strpos( $nl_html, '[npmp_donor_key]' ) );
$ok( 'the token names the test recipient', 'pete.rig@example.org' === npmp_donor_prefill_email( $token ) );
$ok( 'newsletter body has no form', false === stripos( $nl_html, '<form' ) );
$ok( 'leftover tag is stripped from plugin mail', 'x?npmp_donor=' === npmp_donation_strip_unfilled_key( 'x?npmp_donor=[npmp_donor_key]' ) );

echo "\nPayment link settings save\n";
$admin_id     = wp_insert_user( array( 'user_login' => 'rig_admin_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'rig.admin@example.org', 'role' => 'administrator' ) );
$made_users[] = $admin_id;
wp_set_current_user( $admin_id );
$post_settings = function ( $urls, $labels, $notes ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = wp_slash(
		array(
			'npmp_payment_gateway_nonce' => wp_create_nonce( 'npmp_payment_gateway' ),
			'npmp_gateways'              => array( 'paypal_link', 'payment_link' ),
			'npmp_currency'              => npmp_currency(),
			'npmp_paypal_email'          => 'treasurer@example.org',
			'npmp_payment_link_label'    => $labels,
			'npmp_payment_link_url'      => $urls,
			'npmp_payment_link_note'     => $notes,
		)
	);
	ob_start();
	npmp_render_payment_settings_page();
	$out                       = ob_get_clean();
	$_POST                     = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $out;
};
$screen = $post_settings( array( 'https://monzo.me/rigclub/{amount}?d={note}', '', 'https://pay.example.org/give?a={amount}&e={email}&n={name}&c={currency}' ), array( 'Monzo', '', 'Other' ), array( 'Club subs', '', '' ) );
$saved  = get_option( 'npmp_payment_links' );
$ok( 'two good links saved, blank row skipped', is_array( $saved ) && 2 === count( $saved ) );
$ok( 'placeholders survive the real esc_url_raw()', isset( $saved[1]['url'] ) && 'https://pay.example.org/give?a={amount}&e={email}&n={name}&c={currency}' === $saved[1]['url'] );
$ok( 'payment link gateway saved as enabled', in_array( 'payment_link', (array) get_option( 'npmp_enabled_payment_gateways' ), true ) );
$ok( 'the screen shows the saved link', false !== strpos( $screen, 'value="https://monzo.me/rigclub/{amount}?d={note}"' ) );

$before = get_option( 'npmp_payment_links' );
foreach ( array(
	'http'              => 'http://monzo.me/rigclub/{amount}',
	'javascript'        => 'javascript:alert(document.cookie)//{amount}',
	'placeholder host'  => 'https://{name}.example.org/',
	'unknown placeholder' => 'https://monzo.me/rigclub/{amt}',
) as $label => $bad ) {
	$screen = $post_settings( array( 'https://monzo.me/rigclub/{amount}', $bad ), array( 'Good', 'Bad' ), array( '', '' ) );
	$ok( "refused ({$label}): saved links unchanged", get_option( 'npmp_payment_links' ) === $before );
	$ok( "refused ({$label}): error shown, typed value kept and escaped", false !== strpos( $screen, 'notice-error' ) && false !== strpos( $screen, 'value="' . esc_attr( sanitize_text_field( $bad ) ) . '"' ) );
}
wp_set_current_user( 0 );

echo "\nPublic form\n";
npmp_donation_prefill_reset();
$form = do_shortcode( '[npmp_donation_form]' );
$ok( 'one button per payment link', 2 === substr_count( $form, 'class="npmp-paylink-button"' ) );
$ok( 'template carried escaped', false !== strpos( $form, 'data-template="https://pay.example.org/give?a={amount}&amp;e={email}&amp;n={name}&amp;c={currency}"' ) );
$ok( 'blank note falls back to the donation word', false !== strpos( $form, 'data-note="' . esc_attr( npmp_label( 'donation' ) ) . '"' ) );
$ok( 'name field shown for link gateways', false !== strpos( $form, 'id="npmp-donation-name"' ) );
$ok( 'logged out with no token: email blank', false !== strpos( $form, 'id="npmp-donation-email" autocomplete="email" value=""' ) );
$ok( 'PayPal link passes email and names', false !== strpos( $form, "'&email=' + encodeURIComponent" ) && false !== strpos( $form, "'&first_name=' + encodeURIComponent" ) );

echo "\nPrefill in process\n";
$member_id = 0;
if ( class_exists( 'NPMP_Member_Manager' ) ) {
	$mm        = NPMP_Member_Manager::get_instance();
	$member_id = $mm->add_member( array( 'email' => 'pete.rig@example.org', 'name' => 'Pete "Rig" O\'Brien & Co', 'status' => 'subscribed' ) );
	$member_id = is_wp_error( $member_id ) ? 0 : (int) $member_id;
}
$stored_name = $member_id ? (string) NPMP_Member_Manager::get_instance()->get_member_by_id( $member_id )->name : '';
$ok( 'rig contact made with quotes and an ampersand in the name', false !== strpos( $stored_name, '"' ) && false !== strpos( $stored_name, '&' ) );
$_GET['npmp_donor'] = $token;
npmp_donation_prefill_reset();
$pre = npmp_donation_prefill();
$ok( 'valid token: email filled', 'pete.rig@example.org' === $pre['email'] );
$ok( 'valid token: name from the contact', '' !== $stored_name && $stored_name === $pre['name'] );
$form = do_shortcode( '[npmp_donation_form]' );
$ok( 'valid token: name escaped in the field', false !== strpos( $form, 'id="npmp-donation-name" autocomplete="name" value="' . esc_attr( $stored_name ) . '"' ) && false !== strpos( esc_attr( $stored_name ), '&quot;' ) );
$_GET['npmp_donor'] = npmp_donor_b64url( 'mallory@example.org' ) . substr( $token, strpos( $token, '.' ) );
npmp_donation_prefill_reset();
$ok( 'forged token (address swapped): nothing filled', array( 'name' => '', 'email' => '' ) === npmp_donation_prefill() );
unset( $_GET['npmp_donor'] );
$_GET['email'] = 'pete.rig@example.org';
npmp_donation_prefill_reset();
$ok( 'unsigned ?email= is ignored', '' === npmp_donation_prefill()['email'] );
unset( $_GET['email'] );

echo "\nPrefill over HTTP\n";
$base = getenv( 'NPMP_RIG_URL' );
if ( ! $base ) {
	echo "  SKIP  NPMP_RIG_URL not set\n";
} else {
	$get = function ( $url, $cookies = array() ) {
		$r = wp_remote_get( $url, array( 'timeout' => 30, 'cookies' => $cookies, 'redirection' => 0 ) );
		return is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
	};
	$page_url = add_query_arg( 'page_id', $page_id, trailingslashit( $base ) );
	$email_value = function ( $html ) {
		return preg_match( '/id="npmp-donation-email"[^>]*value="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : null;
	};
	$plain = $get( $page_url );
	$ok( 'logged out, no token: form with empty email', '' === $email_value( $plain ) );
	$signed = $get( add_query_arg( 'npmp_donor', $token, $page_url ) );
	$ok( 'signed token: reader\'s email filled', 'pete.rig@example.org' === $email_value( $signed ) );
	$ok( 'signed token: contact name filled and escaped', false !== strpos( $signed, 'id="npmp-donation-name" autocomplete="name" value="' . esc_attr( $stored_name ) . '"' ) );
	$forged = $get( add_query_arg( 'npmp_donor', npmp_donor_b64url( 'mallory@example.org' ) . substr( $token, strpos( $token, '.' ) ), $page_url ) );
	$ok( 'forged token: email empty', '' === $email_value( $forged ) );
	$bare = $get( add_query_arg( array( 'npmp_donor' => 'pete.rig@example.org', 'email' => 'pete.rig@example.org' ), $page_url ) );
	$ok( 'bare address in the URL: email empty', '' === $email_value( $bare ) );

	$donor_id     = wp_insert_user( array( 'user_login' => 'rig_donor_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'logged.in@example.org', 'first_name' => 'Lou', 'last_name' => 'Gin', 'role' => 'subscriber' ) );
	$made_users[] = $donor_id;
	$expire       = time() + HOUR_IN_SECONDS;
	$cookie       = new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $donor_id, $expire, 'logged_in' ) ) );
	$logged       = $get( $page_url, array( $cookie ) );
	$ok( 'logged in: own email filled', 'logged.in@example.org' === $email_value( $logged ) );
	$ok( 'logged in: own name filled', false !== strpos( $logged, 'id="npmp-donation-name" autocomplete="name" value="Lou Gin"' ) );
	$logged_tok = $get( add_query_arg( 'npmp_donor', $token, $page_url ), array( $cookie ) );
	$ok( 'logged in with someone else\'s token: own email wins', 'logged.in@example.org' === $email_value( $logged_tok ) );
}

// Clean up.
if ( $member_id && isset( $mm ) ) {
	$mm->delete_member( $member_id );
}
foreach ( $made_posts as $id ) {
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $made_users as $id ) {
	wp_delete_user( $id );
}
foreach ( $old_options as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}

printf( "\n%d passed, %d failed\n", $passes, $fails );
exit( $fails > 0 ? 1 : 0 );
