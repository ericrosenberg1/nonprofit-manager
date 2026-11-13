<?php
/**
 * Public newsletter archive: the parts that need no database.
 *
 * Which newsletters may be listed, how the [npmp_newsletters] attributes
 * resolve, the excerpt text, the pager, and the scrub that keeps any
 * per-recipient link out of a public page. Unit tested by
 * tests/test-newsletter-archive.php. The WordPress side is archive.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post meta that marks a newsletter for the public archive. '1' means the
 * owner ticked "Show in the public archive". Every other value means no.
 */
if ( ! defined( 'NPMP_NEWSLETTER_PUBLIC_META' ) ) {
	define( 'NPMP_NEWSLETTER_PUBLIC_META', '_npmp_newsletter_public' );
}

/**
 * Post statuses a sent newsletter can carry. The Send button doesn't publish
 * the post, so a newsletter written as a draft and sent stays a draft. The
 * send status meta, not the post status, says it went out.
 *
 * @return string[]
 */
function npmp_newsletter_archive_post_statuses() {
	return array( 'publish', 'private', 'draft', 'pending' );
}

/**
 * Whether a newsletter may appear in the public archive.
 *
 * All three must hold: the post isn't in the trash (or an auto-draft), the
 * queue finished sending it (`_npmp_newsletter_status` is 'sent', which a
 * test send, a Send later that hasn't run, and a send in progress never set),
 * and the owner ticked the box.
 *
 * @param string $post_status  WordPress post status.
 * @param string $send_status  `_npmp_newsletter_status` meta.
 * @param mixed  $public_flag  `_npmp_newsletter_public` meta.
 * @return bool
 */
function npmp_newsletter_archive_listable( $post_status, $send_status, $public_flag ) {
	return in_array( (string) $post_status, npmp_newsletter_archive_post_statuses(), true )
		&& 'sent' === (string) $send_status
		&& '1' === (string) $public_flag;
}

/**
 * Built-in list settings.
 *
 * @return array
 */
function npmp_newsletter_archive_defaults() {
	return array(
		'limit'          => 10,
		'order'          => 'desc',
		'layout'         => 'list',
		'columns'        => 3,
		'date'           => 1,
		'excerpt'        => 1,
		'excerpt_length' => 30,
		'readmore'       => 0,
		'pagination'     => 1,
		'event_emails'   => 0,
	);
}

/**
 * Read a loose yes/no value.
 *
 * @param mixed $value Raw value.
 * @return bool|null Null when unset or unrecognised.
 */
function npmp_newsletter_archive_bool( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return (bool) $value;
	}
	if ( ! is_string( $value ) ) {
		return null;
	}
	$value = strtolower( trim( $value ) );
	if ( in_array( $value, array( '1', 'true', 'yes', 'on', 'show' ), true ) ) {
		return true;
	}
	if ( in_array( $value, array( '0', 'false', 'no', 'off', 'hide' ), true ) ) {
		return false;
	}
	return null;
}

/**
 * Resolve shortcode or block attributes into list settings. Unknown or
 * blank values fall back to the defaults, the way [npmp_events] does.
 *
 * @param array $atts Raw attributes.
 * @return array
 */
function npmp_newsletter_archive_settings( $atts ) {
	$s    = npmp_newsletter_archive_defaults();
	$atts = is_array( $atts ) ? $atts : array();

	if ( isset( $atts['limit'] ) && is_numeric( $atts['limit'] ) ) {
		$limit      = (int) $atts['limit'];
		$s['limit'] = $limit < 1 ? -1 : min( 100, $limit );
	}

	if ( isset( $atts['order'] ) && is_string( $atts['order'] ) ) {
		$order = strtolower( trim( $atts['order'] ) );
		if ( 'asc' === $order ) {
			$s['order'] = 'asc';
		} elseif ( in_array( $order, array( 'desc', 'auto' ), true ) ) {
			$s['order'] = 'desc';
		}
	}

	if ( isset( $atts['layout'] ) && is_string( $atts['layout'] ) ) {
		$layout = strtolower( trim( $atts['layout'] ) );
		if ( in_array( $layout, array( 'list', 'grid', 'cards' ), true ) ) {
			$s['layout'] = $layout;
		}
	}

	if ( isset( $atts['columns'] ) && is_numeric( $atts['columns'] ) ) {
		$columns = (int) $atts['columns'];
		if ( $columns >= 2 && $columns <= 4 ) {
			$s['columns'] = $columns;
		}
	}

	if ( isset( $atts['excerpt_length'] ) && is_numeric( $atts['excerpt_length'] ) && (int) $atts['excerpt_length'] > 0 ) {
		$s['excerpt_length'] = max( 5, min( 200, (int) $atts['excerpt_length'] ) );
	}

	foreach ( array( 'date', 'excerpt', 'readmore', 'pagination', 'event_emails' ) as $key ) {
		if ( array_key_exists( $key, $atts ) ) {
			$flag = npmp_newsletter_archive_bool( $atts[ $key ] );
			if ( null !== $flag ) {
				$s[ $key ] = $flag ? 1 : 0;
			}
		}
	}

	if ( -1 === $s['limit'] ) {
		$s['pagination'] = 0;
	}

	return $s;
}

/**
 * Plain text of a newsletter body for an excerpt.
 *
 * Block comments, headings, images, scripts, and styles go. Every
 * [tag] goes too, so a [first_name] or an [npmp_can_spam] never reaches
 * the page. Fill the recipient tags first if you want their defaults kept.
 * Tables stay, since many email layouts keep their text in one.
 *
 * @param string $content Raw post content.
 * @return string
 */
function npmp_newsletter_archive_text( $content ) {
	$content = (string) $content;
	$content = preg_replace( '/<!--.*?-->/s', '', $content );
	$content = preg_replace( '#<(h[1-6]|figure|iframe|script|style|form|noscript)\b[^>]*>.*?</\1>#is', ' ', $content );
	$content = preg_replace( '/\[\/?[a-z_][a-z0-9_-]*[^\]]*\]/i', '', $content );
	$content = preg_replace( '#</(p|li|div|blockquote|td|th|tr)>|<br\s*/?>#i', ' ', $content );
	$content = strip_tags( $content );
	$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return trim( (string) preg_replace( '/\s+/u', ' ', $content ) );
}

/**
 * Cut text to a word count, with an ellipsis when cut.
 *
 * @param string $text  Plain text.
 * @param int    $words Word limit.
 * @return string
 */
function npmp_newsletter_archive_trim_words( $text, $words ) {
	$parts = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
	$words = max( 5, (int) $words );
	if ( count( $parts ) <= $words ) {
		return implode( ' ', $parts );
	}
	return rtrim( implode( ' ', array_slice( $parts, 0, $words ) ), ',;:.' ) . '…';
}

/**
 * Previous and next page numbers for the pager.
 *
 * @param int $total    Matching newsletters.
 * @param int $per_page Newsletters per page (-1 for all).
 * @param int $current  Current page, from 1.
 * @return array { prev: int, next: int, pages: int, current: int }, 0 for none.
 */
function npmp_newsletter_archive_pager( $total, $per_page, $current ) {
	$total    = max( 0, (int) $total );
	$per_page = (int) $per_page;
	$pages    = ( $per_page < 1 || 0 === $total ) ? 1 : (int) ceil( $total / $per_page );
	$current  = min( max( 1, (int) $current ), $pages );
	return array(
		'prev'    => $current > 1 ? $current - 1 : 0,
		'next'    => $current < $pages ? $current + 1 : 0,
		'pages'   => $pages,
		'current' => $current,
	);
}

/**
 * Query keys that tie a link to one recipient. A plugin link (one with
 * "npmp" in it) loses them on a public page.
 *
 * @return string[]
 */
function npmp_newsletter_archive_private_query_keys() {
	return array( 'npmp_token', 'npmp_email', 'token', 'email', 'uid', '_npmp', 'nid' );
}

/**
 * Remove the per-recipient query keys from one URL.
 *
 * @param string $url URL as it sits in the attribute (may hold &amp;).
 * @return string
 */
function npmp_newsletter_archive_strip_private_args( $url ) {
	$decoded = str_replace( '&amp;', '&', (string) $url );
	$hash    = '';
	$pos     = strpos( $decoded, '#' );
	if ( false !== $pos ) {
		$hash    = substr( $decoded, $pos );
		$decoded = substr( $decoded, 0, $pos );
	}
	$qpos = strpos( $decoded, '?' );
	if ( false === $qpos ) {
		return $url;
	}
	$base  = substr( $decoded, 0, $qpos );
	$pairs = explode( '&', substr( $decoded, $qpos + 1 ) );
	$keep  = array();
	foreach ( $pairs as $pair ) {
		if ( '' === $pair ) {
			continue;
		}
		$key = strtolower( rawurldecode( (string) strtok( $pair, '=' ) ) );
		if ( in_array( $key, npmp_newsletter_archive_private_query_keys(), true ) ) {
			continue;
		}
		$keep[] = $pair;
	}
	return str_replace( '&', '&amp;', $base . ( $keep ? '?' . implode( '&', $keep ) : '' ) . $hash );
}

/**
 * Take every per-recipient trace out of newsletter HTML bound for a public
 * page. The public view never adds tracking, a footer, or tokens, so this is
 * the second line: it catches a link someone pasted from their own copy of
 * an email.
 *
 * - An open-tracking pixel (an image whose src carries npmp_track) goes.
 * - A tracked click link becomes the link it wraps.
 * - Any other plugin link loses its token, email, and recipient ID.
 *
 * @param string $html HTML.
 * @return string
 */
function npmp_newsletter_archive_scrub( $html ) {
	$html = (string) $html;

	// Tracking pixels.
	$html = preg_replace( '#<img\b[^>]*\bsrc\s*=\s*(["\'])[^"\']*npmp_track=[^"\']*\1[^>]*>#i', '', $html );

	return (string) preg_replace_callback(
		'#\b(href|src|action)\s*=\s*(["\'])(.*?)\2#is',
		function ( $m ) {
			$url = $m[3];
			if ( false === stripos( $url, 'npmp' ) ) {
				return $m[0];
			}
			$plain = str_replace( '&amp;', '&', $url );
			if ( preg_match( '/[?&]npmp_track=click\b/i', $plain ) && preg_match( '/[?&]url=([^&#]*)/i', $plain, $u ) ) {
				$target = rawurldecode( $u[1] );
				// rawurlencode() then add_query_arg() may leave it encoded twice.
				if ( preg_match( '#^https?%3A#i', $target ) ) {
					$target = rawurldecode( $target );
				}
				if ( preg_match( '#^https?://#i', $target ) ) {
					return $m[1] . '=' . $m[2] . str_replace( '&', '&amp;', str_replace( '&amp;', '&', $target ) ) . $m[2];
				}
				return $m[1] . '=' . $m[2] . '#' . $m[2];
			}
			return $m[1] . '=' . $m[2] . npmp_newsletter_archive_strip_private_args( $url ) . $m[2];
		},
		$html
	);
}

/**
 * The path of a newsletter's public page, after the site's home URL.
 *
 * @param int    $id   Newsletter ID.
 * @param string $slug Title slug ('' for none).
 * @return string Like "newsletter/42/march-news/".
 */
function npmp_newsletter_archive_path( $id, $slug ) {
	$slug = trim( (string) $slug, '/' );
	return 'newsletter/' . (int) $id . '/' . ( '' !== $slug ? $slug . '/' : '' );
}

/**
 * Which newsletters a "Show in the public archive" or "Hide from the public
 * archive" bulk action changes.
 *
 * Show only ticks a newsletter that went out (send status 'sent'), so a
 * draft can't reach the public page early. Hide unticks anything ticked.
 *
 * @param array  $rows   Each: id, send_status, public (the meta value).
 * @param string $action 'show' or 'hide'.
 * @return array{change:int[],unsent:int[],already:int[]}
 */
function npmp_newsletter_public_bulk_plan( array $rows, $action ) {
	$plan = array(
		'change'  => array(),
		'unsent'  => array(),
		'already' => array(),
	);
	$show = 'show' === $action;
	foreach ( $rows as $row ) {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 ) {
			continue;
		}
		$is_public = '1' === (string) ( $row['public'] ?? '' );
		if ( $show && 'sent' !== (string) ( $row['send_status'] ?? '' ) ) {
			$plan['unsent'][] = $id;
		} elseif ( $show === $is_public ) {
			$plan['already'][] = $id;
		} else {
			$plan['change'][] = $id;
		}
	}
	return $plan;
}
