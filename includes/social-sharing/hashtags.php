<?php
/**
 * File path: includes/social-sharing/hashtags.php
 *
 * Event tags as hashtags in a social share (Pete at U3A asked for it). The
 * string work needs no database, so tests/test-social-hashtags.php runs it
 * alone. The share manager calls npmp_social_share_text() for every network,
 * free and Pro.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/npmp-mb-compat.php';

/**
 * One tag as a hashtag: spaces and punctuation out, and each word of a
 * several-word tag capitalized ("community garden" is #CommunityGarden). A
 * one-word tag keeps its own letter case, so "iPhone" stays #iPhone. Digits
 * alone make no hashtag on X or Mastodon, so "2026" gives ''.
 *
 * @param string $tag Tag name.
 * @return string Hashtag with its #, or ''.
 */
function npmp_social_hashtag( $tag ) {
	$tag = html_entity_decode( (string) $tag, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	// Apostrophes join a word ("Women's Group" is #WomensGroup).
	$tag   = preg_replace( "/['\x{2019}]/u", '', $tag );
	$words = preg_split( '/[^\p{L}\p{N}]+/u', (string) $tag, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! $words ) {
		return '';
	}
	if ( count( $words ) > 1 ) {
		$words = array_map(
			static function ( $word ) {
				return npmp_mb_upper( mb_substr( $word, 0, 1, 'UTF-8' ) ) . mb_substr( $word, 1, null, 'UTF-8' );
			},
			$words
		);
	}
	$joined = implode( '', $words );
	if ( ! preg_match( '/\p{L}/u', $joined ) ) {
		return '';
	}
	return '#' . $joined;
}

/**
 * Tag names as hashtags, in order, with case-insensitive repeats dropped.
 *
 * @param string[] $tags Tag names.
 * @return string[]
 */
function npmp_social_hashtags( $tags ) {
	$out  = array();
	$seen = array();
	foreach ( (array) $tags as $tag ) {
		if ( ! is_scalar( $tag ) ) {
			continue;
		}
		$hashtag = npmp_social_hashtag( (string) $tag );
		$key     = npmp_mb_lower( $hashtag );
		if ( '' === $hashtag || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[]        = $hashtag;
	}
	return $out;
}

/**
 * The text for one network: the share template filled in, then the
 * hashtags, within the network's character limit.
 *
 * A template with {hashtags} puts them there, any other template gets them on
 * a line of their own at the end. With a limit, the title and link always
 * stay. We keep as many hashtags as fit beside the title and link, in tag
 * order, then cut the excerpt to fit, the way we did before hashtags.
 *
 * @param string   $template  Share template with {title}, {excerpt}, {url}, and optionally {hashtags}.
 * @param array    $post_data title, excerpt, url, and hashtags (string[]).
 * @param int|null $limit     Character limit, null for none.
 * @return string
 */
function npmp_social_share_text( $template, $post_data, $limit = null ) {
	$title    = (string) ( $post_data['title'] ?? '' );
	$excerpt  = (string) ( $post_data['excerpt'] ?? '' );
	$url      = (string) ( $post_data['url'] ?? '' );
	$hashtags = array_values( array_filter( (array) ( $post_data['hashtags'] ?? array() ), 'is_string' ) );
	$template = (string) $template;
	$has_slot = false !== strpos( $template, '{hashtags}' );

	$render = static function ( $tags, $text_excerpt ) use ( $template, $title, $url, $has_slot ) {
		$line = implode( ' ', $tags );
		$text = str_replace(
			array( '{title}', '{excerpt}', '{url}', '{hashtags}' ),
			array( $title, $text_excerpt, $url, $line ),
			$template
		);
		if ( ! $has_slot && '' !== $line ) {
			$text = rtrim( $text ) . "\n\n" . $line;
		}
		return $has_slot ? trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) ) : $text;
	};

	$text = $render( $hashtags, $excerpt );
	if ( null === $limit || mb_strlen( $text, 'UTF-8' ) <= (int) $limit ) {
		return $text;
	}
	$limit = (int) $limit;

	// The shortest text we would send: title and link, as before hashtags.
	$core     = $title . "\n\n" . $url;
	$core_len = mb_strlen( $core, 'UTF-8' );

	// Keep as many hashtags as fit beside the title and link.
	$keep = $hashtags;
	while ( $keep && $core_len + 2 + mb_strlen( implode( ' ', $keep ), 'UTF-8' ) > $limit ) {
		array_pop( $keep );
	}

	$text = $render( $keep, $excerpt );
	if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
		return $text;
	}

	// Still too long: title, a cut excerpt, the link, then the hashtags.
	$line      = implode( ' ', $keep );
	$tail      = '' !== $line ? "\n\n" . $line : '';
	$available = $limit - mb_strlen( $title, 'UTF-8' ) - mb_strlen( $url, 'UTF-8' ) - mb_strlen( $tail, 'UTF-8' ) - 4; // 4 = the two blank lines around the excerpt.
	$short     = ( $available > 10 ) ? mb_substr( $excerpt, 0, $available - 3, 'UTF-8' ) . '...' : '';
	$text      = '' !== $short ? $title . "\n\n" . $short . "\n\n" . $url : $core;
	return mb_substr( $text . $tail, 0, $limit, 'UTF-8' );
}
