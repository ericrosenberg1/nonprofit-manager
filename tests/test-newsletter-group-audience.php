<?php
/**
 * A newsletter aimed at a Pro group keeps its audience in the editor
 * (includes/email-newsletter/editor.php).
 *
 * Pete at U3A unticked All Members, ticked one group, and saved. Pro saved
 * the group, but the Newsletter Archive screen and the stored label said
 * "All subscribed members", and the editor came back with All Members
 * ticked, so the group looked ignored. These checks run the real save and
 * render code with Pro's two listeners copied in (they save the group from
 * the request and name it in the label, as includes/groups/newsletters.php
 * in Pro does).
 *
 * The block editor's own save request runs in
 * tests/rig/newsletter-group-audience.php.
 *
 * Run: php tests/test-newsletter-group-audience.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_meta']    = array();
$GLOBALS['t_actions'] = array();
$GLOBALS['t_filters'] = array();

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $hook ][] = $cb;
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['t_actions'][ $hook ] ?? array() as $cb ) {
		if ( is_callable( $cb ) ) {
			$cb( ...$args );
		}
	}
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $hook ][] = $cb;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['t_filters'][ $hook ] ?? array() as $cb ) {
		if ( is_callable( $cb ) ) {
			$value = $cb( $value, ...$args );
		}
	}
	return $value;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['t_meta'][ $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['t_meta'][ $id ][ $key ] );
	return true;
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function sanitize_textarea_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function wp_unslash( $v ) {
	return $v;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}
function get_option( $name, $default = false ) {
	return 'npmp_membership_levels' === $name ? array( 'Gold', 'Silver' ) : $default;
}
function wp_create_nonce( $action ) {
	return 'nonce';
}
function checked( $a, $b, $echo = true ) {
	return (string) $a === (string) $b ? ' checked=\'checked\'' : '';
}
function disabled( $a, $b, $echo = true ) {
	return (string) $a === (string) $b ? ' disabled=\'disabled\'' : '';
}
function current_user_can( $cap, ...$args ) {
	return false;
}
function esc_url( $url ) {
	return $url;
}
function wp_kses_post( $text ) {
	return $text;
}
function npmp_newsletter_preview_url( $id ) {
	return 'preview-' . (int) $id;
}
function npmp_label( $key ) {
	return array( 'members' => 'Members', 'members_lc' => 'members', 'levels_lc' => 'membership levels' )[ $key ] ?? $key;
}
function npmp_email_recipient_tag_help_suffix() {
	return '';
}
function wp_sprintf( $pattern, $list ) {
	return implode( ' and ', (array) $list );
}

require_once dirname( __DIR__ ) . '/includes/email-newsletter/class-newsletter-manager.php';
require_once dirname( __DIR__ ) . '/includes/email-newsletter/editor.php';

// Pro's two listeners, reduced to what they do with group 2, "Art Club".
add_action(
	'npmp_newsletter_audience_saved',
	static function ( $post_id, $levels = array(), $request = array() ) {
		if ( empty( $request['npmp_newsletter_groups_present'] ) ) {
			return;
		}
		$ids = array_map( 'intval', (array) ( $request['npmp_newsletter_groups'] ?? array() ) );
		if ( $ids ) {
			update_post_meta( $post_id, '_npmp_newsletter_groups', $ids );
		} else {
			delete_post_meta( $post_id, '_npmp_newsletter_groups' );
		}
	}
);
add_filter(
	'npmp_newsletter_audience_label',
	static function ( $label, $newsletter_id = 0 ) {
		$ids = (array) get_post_meta( $newsletter_id, '_npmp_newsletter_groups', true );
		if ( ! array_filter( $ids ) ) {
			return $label;
		}
		$names  = wp_sprintf( '%l', array_map( static fn( $id ) => array( 2 => 'Art Club', 3 => 'Walking' )[ $id ] ?? '', $ids ) );
		$levels = array_diff( array_filter( array_map( 'strval', (array) get_post_meta( $newsletter_id, '_npmp_newsletter_levels', true ) ) ), array( '__all__' ) );
		return $levels ? sprintf( '%1$s in %2$s', $label, $names ) : sprintf( 'Members of %s', $names );
	}
);

/**
 * The editor's All Members checkbox for a newsletter: checked or not.
 *
 * @param int $id Newsletter ID.
 * @return string|null "checked", "unchecked", or null when it isn't printed.
 */
function t_all_members_box( $id ) {
	ob_start();
	npmp_newsletter_send_controls_html( (object) array( 'ID' => $id ) );
	$html = ob_get_clean();
	if ( ! preg_match( '/<input[^>]*npmp-newsletter-all[^>]*>/', $html, $m ) ) {
		return null;
	}
	return false !== strpos( $m[0], 'checked' ) ? 'checked' : 'unchecked';
}

/**
 * The editor's "Current selection" text.
 *
 * @param int $id Newsletter ID.
 * @return string
 */
function t_current_selection( $id ) {
	ob_start();
	npmp_newsletter_send_controls_html( (object) array( 'ID' => $id ) );
	$html = ob_get_clean();
	return preg_match( '/class="npmp-newsletter-audience-label"[^>]*>([^<]*)</', $html, $m ) ? $m[1] : '';
}

echo "Pete's save: All Members unticked, Art Club ticked, no level\n";
$_POST = array(
	'npmp_newsletter_groups_present' => '1',
	'npmp_newsletter_groups'         => array( '2' ),
);
npmp_newsletter_store_meta( 8, array(), '' );
check( 'the group is saved', array( 2 ), get_post_meta( 8, '_npmp_newsletter_groups', true ) );
check( 'no level is saved', array(), get_post_meta( 8, '_npmp_newsletter_levels', true ) );
check( 'stored label names the group', 'Members of Art Club', get_post_meta( 8, '_npmp_newsletter_audience_label', true ) );
check( 'archive screen label names the group', 'Members of Art Club', npmp_newsletter_get_audience_label( 8 ) );
check( 'editor reloads with All Members unticked', 'unchecked', t_all_members_box( 8 ) );
check( 'editor shows the group as the current selection', 'Members of Art Club', t_current_selection( 8 ) );

echo "All Members left ticked with a group: the group narrows it, as the send does\n";
$_POST = array(
	'npmp_newsletter_groups_present' => '1',
	'npmp_newsletter_groups'         => array( '2' ),
);
npmp_newsletter_store_meta( 9, array( '__all__' ), '' );
check( 'levels collapse to none', array(), get_post_meta( 9, '_npmp_newsletter_levels', true ) );
check( 'stored label names the group', 'Members of Art Club', get_post_meta( 9, '_npmp_newsletter_audience_label', true ) );
check( 'editor reloads with All Members unticked', 'unchecked', t_all_members_box( 9 ) );

echo "A level plus a group\n";
npmp_newsletter_store_meta( 10, array( 'Gold' ), '' );
check( 'stored label names both', 'Gold in Art Club', get_post_meta( 10, '_npmp_newsletter_audience_label', true ) );
check( 'editor reloads with All Members unticked', 'unchecked', t_all_members_box( 10 ) );

echo "No group: everyone, as before\n";
$_POST = array( 'npmp_newsletter_groups_present' => '1' );
npmp_newsletter_store_meta( 11, array( '__all__' ), '' );
check( 'no group saved', '', get_post_meta( 11, '_npmp_newsletter_groups', true ) );
check( 'stored label', 'All subscribed members', get_post_meta( 11, '_npmp_newsletter_audience_label', true ) );
check( 'editor reloads with All Members ticked', 'checked', t_all_members_box( 11 ) );

echo "Unticking the group later puts everyone back\n";
npmp_newsletter_store_meta( 8, array(), '' );
check( 'group removed', '', get_post_meta( 8, '_npmp_newsletter_groups', true ) );
check( 'stored label', 'All subscribed members', get_post_meta( 8, '_npmp_newsletter_audience_label', true ) );
check( 'editor reloads with All Members ticked', 'checked', t_all_members_box( 8 ) );

echo "Duplicate keeps the groups, so a copy never goes to everyone\n";
check( 'groups copied', true, in_array( '_npmp_newsletter_groups', npmp_newsletter_duplicate_meta_keys(), true ) );
check( 'levels copied', true, in_array( '_npmp_newsletter_levels', npmp_newsletter_duplicate_meta_keys(), true ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
