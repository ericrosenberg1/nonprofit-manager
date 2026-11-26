<?php
/**
 * Which page holds one of our forms: unsubscribe, email preferences, signup,
 * or donation.
 *
 * Until 2026.11.21 we searched pages for the shortcode's
 * text and took the first hit, so a notes page that only mentioned
 * [npmp_email_unsubscribe] became the unsubscribe page and every email footer
 * linked to it (Pete, test.u3acommunities.org, "Thoughts on u3acommunities
 * hosting"). Now a page counts only when its name says what it is and it
 * holds the real form. The string tests here need no database, so
 * tests/test-form-pages.php runs them alone. Rig tests/rig/form-pages.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/npmp-mb-compat.php';

/**
 * Our form pages: the shortcode, the block, and the words a page's slug or
 * title must hold to count as that page.
 *
 * @return array<string,array{shortcode:string,block:string,words:string[]}>
 */
function npmp_form_page_kinds() {
	return array(
		'unsubscribe' => array(
			'shortcode' => 'npmp_email_unsubscribe',
			'block'     => 'nonprofit-manager/email-unsubscribe',
			'words'     => array( 'unsubscribe' ),
		),
		'preferences' => array(
			'shortcode' => 'npmp_manage_preferences',
			'block'     => '',
			'words'     => array( 'preference', 'subscri', 'email', 'newsletter', 'mailing' ),
		),
		'signup'      => array(
			'shortcode' => 'npmp_email_signup',
			'block'     => 'nonprofit-manager/email-signup',
			'words'     => array( 'signup', 'sign-up', 'sign up', 'subscribe', 'join', 'newsletter', 'mailing' ),
		),
		'donation'    => array(
			'shortcode' => 'npmp_donation_form',
			'block'     => 'nonprofit-manager/donation-form',
			'words'     => array( 'donat', 'give', 'giving', 'support', 'contribut', 'fund' ),
		),
	);
}

/**
 * One kind's definition.
 *
 * @param string $kind Key of npmp_form_page_kinds().
 * @return array|null
 */
function npmp_form_page_kind( $kind ) {
	$kinds = npmp_form_page_kinds();
	return isset( $kinds[ $kind ] ) ? $kinds[ $kind ] : null;
}

/**
 * Whether content holds the form itself: the shortcode as WordPress would run
 * it, or the block. An escaped [[shortcode]] prints as text, so it doesn't
 * count. Unlike has_shortcode(), this works before the shortcode is
 * registered, which matters on upgrade and in the unit test.
 *
 * @param string $content Raw post content.
 * @param string $kind    Key of npmp_form_page_kinds().
 * @return bool
 */
function npmp_form_page_has_form( $content, $kind ) {
	$def     = npmp_form_page_kind( $kind );
	$content = (string) $content;
	if ( ! $def || '' === $content ) {
		return false;
	}
	$tag = preg_quote( $def['shortcode'], '/' );
	if ( preg_match( '/(?<!\[)\[' . $tag . '(?=[\s\/\]])[^\]]*\](?!\])/', $content ) ) {
		return true;
	}
	if ( '' !== $def['block'] && preg_match( '/<!--\s+wp:' . preg_quote( $def['block'], '/' ) . '(?=[\s\/])/', $content ) ) {
		return true;
	}
	return false;
}

/**
 * Whether content mentions the form at all, the way the old text search saw
 * it: the shortcode's opening text or the block's name anywhere.
 *
 * @param string $content Raw post content.
 * @param string $kind    Key of npmp_form_page_kinds().
 * @return bool
 */
function npmp_form_page_mentions( $content, $kind ) {
	$def     = npmp_form_page_kind( $kind );
	$content = (string) $content;
	if ( ! $def || '' === $content ) {
		return false;
	}
	return false !== stripos( $content, '[' . $def['shortcode'] )
		|| ( '' !== $def['block'] && false !== stripos( $content, 'wp:' . $def['block'] ) );
}

/**
 * Whether a page's slug or title names it as this kind of page.
 *
 * @param string $title Page title.
 * @param string $slug  Page slug.
 * @param string $kind  Key of npmp_form_page_kinds().
 * @return bool
 */
function npmp_form_page_named( $title, $slug, $kind ) {
	$def = npmp_form_page_kind( $kind );
	if ( ! $def ) {
		return false;
	}
	$title = npmp_mb_lower( $title );
	$slug  = strtolower( rawurldecode( (string) $slug ) );
	foreach ( $def['words'] as $word ) {
		if ( false !== strpos( $slug, $word ) || false !== strpos( $title, $word ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a post is clearly this kind of page: a published page, named for
 * the job, that holds the form.
 *
 * @param WP_Post|object|array|null $post Post, or an array with the same fields.
 * @param string                    $kind Key of npmp_form_page_kinds().
 * @return bool
 */
function npmp_form_page_is_clear( $post, $kind ) {
	if ( ! $post ) {
		return false;
	}
	$post = (object) $post;
	if ( 'page' !== ( $post->post_type ?? 'page' ) || 'publish' !== ( $post->post_status ?? '' ) ) {
		return false;
	}
	return npmp_form_page_named( $post->post_title ?? '', $post->post_name ?? '', $kind )
		&& npmp_form_page_has_form( $post->post_content ?? '', $kind );
}

/**
 * The first clear page among candidates, oldest first.
 *
 * @param array  $candidates Posts (WP_Post, objects, or arrays).
 * @param string $kind       Key of npmp_form_page_kinds().
 * @param int    $exclude    Post ID to skip.
 * @return int Page ID, 0 when none qualifies.
 */
function npmp_form_page_pick( $candidates, $kind, $exclude = 0 ) {
	$best = 0;
	foreach ( (array) $candidates as $post ) {
		$post = (object) $post;
		$id   = (int) ( $post->ID ?? 0 );
		if ( ! $id || $id === (int) $exclude || ! npmp_form_page_is_clear( $post, $kind ) ) {
			continue;
		}
		if ( ! $best || $id < $best ) {
			$best = $id;
		}
	}
	return $best;
}

/**
 * The page a one-time repair should switch a saved setting to, or 0 to leave
 * it alone.
 *
 * We switch only when all three hold:
 * 1. The saved page isn't clearly this kind of page.
 * 2. The saved page mentions the form's shortcode or block. The old setup
 *    could pick a page only through that text, so a page someone picked by
 *    hand without it (where we add the form ourselves) never moves.
 * 3. Another page is clearly this kind of page.
 * With no clear page we create nothing and change nothing.
 *
 * @param WP_Post|object|array|null $saved      The saved page, null when missing.
 * @param array                     $candidates Pages that mention the form.
 * @param string                    $kind       Key of npmp_form_page_kinds().
 * @return int
 */
function npmp_form_page_repair_target( $saved, $candidates, $kind ) {
	if ( ! $saved ) {
		return 0;
	}
	$saved = (object) $saved;
	if ( 'publish' !== ( $saved->post_status ?? '' ) ) {
		return 0;
	}
	if ( npmp_form_page_is_clear( $saved, $kind ) || ! npmp_form_page_mentions( $saved->post_content ?? '', $kind ) ) {
		return 0;
	}
	return npmp_form_page_pick( $candidates, $kind, (int) ( $saved->ID ?? 0 ) );
}

/**
 * Published pages that mention a form, oldest first. Up to 50.
 *
 * @param string $kind Key of npmp_form_page_kinds().
 * @return WP_Post[]
 */
function npmp_form_page_candidates( $kind ) {
	$def = npmp_form_page_kind( $kind );
	if ( ! $def ) {
		return array();
	}
	global $wpdb;
	$like_code  = '%' . $wpdb->esc_like( '[' . $def['shortcode'] ) . '%';
	$like_block = '' !== $def['block'] ? '%' . $wpdb->esc_like( 'wp:' . $def['block'] ) . '%' : $like_code;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Runs on setup, on the one-time repair, and when a lookup finds no saved page.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT 50",
			$like_code,
			$like_block
		)
	);
	return array_values( array_filter( array_map( 'get_post', array_map( 'intval', (array) $ids ) ) ) );
}

/**
 * The clear page of a kind on this site.
 *
 * @param string $kind    Key of npmp_form_page_kinds().
 * @param int    $exclude Post ID to skip.
 * @return int Page ID, 0 when there is none.
 */
function npmp_form_page_find( $kind, $exclude = 0 ) {
	return npmp_form_page_pick( npmp_form_page_candidates( $kind ), $kind, $exclude );
}

/**
 * Repair sites where the old text search picked the wrong unsubscribe or
 * preferences page. Runs once, on the first load after the update.
 *
 * @return void
 */
function npmp_maybe_repair_form_pages() {
	if ( get_option( 'npmp_form_pages_repaired', false ) ) {
		return;
	}
	update_option( 'npmp_form_pages_repaired', 1 );

	$option   = 'npmp_membership_form_settings';
	$settings = get_option( $option, array() );
	if ( is_array( $settings ) && ! empty( $settings['unsubscribe_page_id'] ) ) {
		$saved  = get_post( absint( $settings['unsubscribe_page_id'] ) );
		$target = npmp_form_page_repair_target( $saved, npmp_form_page_candidates( 'unsubscribe' ), 'unsubscribe' );
		if ( $target ) {
			$settings['unsubscribe_page_id'] = $target;
			update_option( $option, $settings );
			if ( function_exists( 'npmp_get_membership_form_settings' ) ) {
				npmp_get_membership_form_settings( true );
			}
		}
	}

	$prefs = absint( get_option( 'npmp_preferences_page_id', 0 ) );
	if ( $prefs ) {
		$target = npmp_form_page_repair_target( get_post( $prefs ), npmp_form_page_candidates( 'preferences' ), 'preferences' );
		if ( $target ) {
			update_option( 'npmp_preferences_page_id', $target );
		}
	}
}
add_action( 'init', 'npmp_maybe_repair_form_pages', 30 );

/**
 * Forget that the preferences lookup found no page.
 *
 * The lookup remembers a miss for an hour (npmp_get_preferences_page_id()), so a
 * page created, published, renamed, or restored after a miss stayed unseen and
 * "Manage your email preferences" dropped out of the email footer. Runs when
 * any page changes state and when Membership Settings save.
 *
 * @return void
 */
function npmp_forget_preferences_page_miss() {
	delete_transient( 'npmp_prefs_page_lookup' );
}

/**
 * Clear the miss when a page is saved, trashed, restored, or deleted.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function npmp_forget_preferences_page_miss_for_post( $post_id ) {
	if ( 'page' === get_post_type( $post_id ) ) {
		npmp_forget_preferences_page_miss();
	}
}
add_action( 'save_post_page', 'npmp_forget_preferences_page_miss' );
add_action( 'wp_trash_post', 'npmp_forget_preferences_page_miss_for_post' );
add_action( 'untrashed_post', 'npmp_forget_preferences_page_miss_for_post' );
add_action( 'before_delete_post', 'npmp_forget_preferences_page_miss_for_post' );
