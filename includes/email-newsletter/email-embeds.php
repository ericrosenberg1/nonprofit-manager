<?php
/**
 * File path: includes/email-newsletter/email-embeds.php
 *
 * Embeds in the email version of a newsletter. Mail apps don't play a YouTube
 * video or show Google Slides from an iframe, so the reader saw a blank space
 * (Pete, wordpress.org topic "sharing-videos-and-other-content-in-a-newsletter").
 * In the email, each embed block (core/embed: YouTube, Vimeo, and the rest) and
 * each iframe (a Custom HTML block, a pasted Slides embed, an auto-embedded URL)
 * becomes a link. A YouTube video gets its thumbnail as a linked image.
 *
 * The link goes to the newsletter's web version when it has one (the owner
 * ticked "public" and it isn't an event email with private meeting details),
 * and otherwise to what the embed showed. The web version renders the same
 * body through NPMP_Newsletter_Manager::render_base_content(), inside
 * npmp_newsletter_web_render(), and keeps every embed as it is.
 *
 * The pure parts run on plain PHP in tests/test-email-embeds.php, and the rig
 * tests/rig/email-embeds.php checks a real send and the web page.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The 11-character YouTube video ID in a watch, short, embed, Shorts, or
 * live URL, or ''.
 *
 * @param string $url URL.
 * @return string
 */
function npmp_email_embed_youtube_id( $url ) {
	$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
	if ( ! preg_match( '#^(?:https?:)?//(?:[a-z0-9-]+\.)?(youtube\.com|youtube-nocookie\.com|youtu\.be)(/[^?\#]*)?(?:\?([^\#]*))?#i', $url, $m ) ) {
		return '';
	}
	$host = strtolower( $m[1] );
	$path = isset( $m[2] ) ? (string) $m[2] : '';
	$id   = '';
	if ( 'youtu.be' === $host ) {
		$id = (string) strtok( ltrim( $path, '/' ), '/' );
	} elseif ( preg_match( '#^/(?:embed|shorts|live|v)/([^/]+)#', $path, $p ) ) {
		$id = $p[1];
	} elseif ( isset( $m[3] ) ) {
		parse_str( (string) $m[3], $q );
		$id = isset( $q['v'] ) && is_string( $q['v'] ) ? $q['v'] : '';
	}
	return preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ? $id : '';
}

/**
 * What an embed shows: 'video', 'slides', or 'other'.
 *
 * @param string $url      Source URL.
 * @param string $provider Block providerNameSlug ('' when unknown).
 * @param string $type     Block type from its class (is-type-video), or ''.
 * @return string
 */
function npmp_email_embed_kind( $url, $provider = '', $type = '' ) {
	$url      = strtolower( (string) $url );
	$provider = strtolower( (string) $provider );
	if ( false !== strpos( $url, 'docs.google.com/presentation' ) || in_array( $provider, array( 'slideshare', 'speaker-deck', 'speakerdeck' ), true ) || preg_match( '#//(?:www\.)?(?:slideshare\.net|speakerdeck\.com)/#', $url ) ) {
		return 'slides';
	}
	$video = array( 'youtube', 'vimeo', 'dailymotion', 'videopress', 'wordpress-tv', 'ted', 'tiktok', 'animoto', 'facebook-video', 'loom', 'wistia' );
	if ( 'video' === strtolower( (string) $type ) || in_array( $provider, $video, true ) || '' !== npmp_email_embed_youtube_id( $url ) || preg_match( '#//(?:[a-z0-9-]+\.)?(?:vimeo\.com|dailymotion\.com|videopress\.com|wordpress\.tv|ted\.com/talks|tiktok\.com|loom\.com/(?:share|embed))#', $url ) ) {
		return 'video';
	}
	return 'other';
}

/**
 * The link text for an embed.
 *
 * @param string $kind 'video', 'slides', or 'other'.
 * @return string
 */
function npmp_email_embed_label( $kind ) {
	$labels = array(
		'video'  => function_exists( '__' ) ? __( 'Watch the video', 'nonprofit-manager' ) : 'Watch the video',
		'slides' => function_exists( '__' ) ? __( 'View the slides', 'nonprofit-manager' ) : 'View the slides',
		'other'  => function_exists( '__' ) ? __( 'View the embedded content', 'nonprofit-manager' ) : 'View the embedded content',
	);
	return $labels[ $kind ] ?? $labels['other'];
}

/**
 * A URL safe for an href, or '' for anything but http and https.
 *
 * @param string $url URL.
 * @return string Escaped for an attribute.
 */
function npmp_email_embed_href( $url ) {
	$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
	if ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	}
	if ( ! preg_match( '#^https?://[^\s"<>]+$#i', $url ) ) {
		return '';
	}
	return function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

/**
 * The email markup that stands in for one embed.
 *
 * @param string $source   What the embed showed.
 * @param string $web_url  The newsletter's web version, or '' for none.
 * @param string $provider Block providerNameSlug, or ''.
 * @param string $type     Block type, or ''.
 * @return string '' when there's nothing to link to.
 */
function npmp_email_embed_html( $source, $web_url = '', $provider = '', $type = '' ) {
	$kind   = npmp_email_embed_kind( $source, $provider, $type );
	$target = '' !== (string) $web_url ? $web_url : $source;
	$yt     = npmp_email_embed_youtube_id( $source );
	if ( '' === (string) $web_url && '' !== $yt ) {
		// A watch link opens the YouTube app, and an embed URL doesn't.
		$target = 'https://www.youtube.com/watch?v=' . $yt;
	}
	$href = npmp_email_embed_href( $target );
	if ( '' === $href ) {
		return '';
	}
	$label = npmp_email_embed_label( $kind );
	$text  = function_exists( 'esc_html' ) ? esc_html( $label ) : htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
	$out   = '';
	if ( '' !== $yt ) {
		$out .= '<p class="npmp-email-embed-thumb" style="margin:0 0 8px;"><a href="' . $href . '"><img src="https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg" alt="' . $text . '" width="480" height="360" style="display:block;width:100%;max-width:480px;height:auto;border:0;"></a></p>';
	}
	return $out . '<p class="npmp-email-embed-link"><a href="' . $href . '">' . $text . '</a></p>';
}

/**
 * Swap every iframe left in email HTML for a link.
 *
 * @param string $html    Rendered email HTML.
 * @param string $web_url The newsletter's web version, or ''.
 * @return string
 */
function npmp_email_embed_swap_iframes( $html, $web_url = '' ) {
	$html = (string) $html;
	if ( false === stripos( $html, '<iframe' ) ) {
		return $html;
	}
	return (string) preg_replace_callback(
		'#<iframe\b([^>]*)>.*?</iframe\s*>|<iframe\b([^>]*)/?>#is',
		static function ( $m ) use ( $web_url ) {
			$attrs = '' !== ( $m[1] ?? '' ) ? $m[1] : ( $m[2] ?? '' );
			$src   = '';
			if ( preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#is', $attrs, $s ) || preg_match( '#\bsrc\s*=\s*([^\s>]+)#i', $attrs, $s ) ) {
				$src = isset( $s[2] ) ? $s[2] : $s[1];
			}
			return npmp_email_embed_html( $src, $web_url );
		},
		$html
	);
}

/**
 * Whether the render in progress is a newsletter's web version, which keeps
 * its embeds. Calls nest.
 *
 * @param int $delta 1 to enter, -1 to leave, 0 to read.
 * @return bool
 */
function npmp_newsletter_web_render( $delta = 0 ) {
	static $depth = 0;
	$depth = max( 0, $depth + (int) $delta );
	return $depth > 0;
}

/**
 * The web version link for the email render in progress. Calls nest.
 *
 * @param string|false|null $set A URL ('' for none) to push, false to pop, null to read.
 * @return string
 */
function npmp_email_embed_target( $set = null ) {
	static $stack = array();
	if ( null === $set ) {
		return $stack ? (string) end( $stack ) : '';
	}
	if ( false === $set ) {
		array_pop( $stack );
		return '';
	}
	$stack[] = (string) $set;
	return (string) $set;
}

/**
 * The newsletter's web version, or '' when it has none. A newsletter the
 * owner made public gets its page once it's sent, which happens with this
 * email, so we link it from the first send.
 *
 * @param int $newsletter_id Newsletter ID.
 * @return string
 */
function npmp_newsletter_web_version_url( $newsletter_id ) {
	$newsletter_id = (int) $newsletter_id;
	if ( ! $newsletter_id || ! function_exists( 'npmp_newsletter_archive_url' ) ) {
		return '';
	}
	$post = get_post( $newsletter_id );
	if ( ! $post || 'npmp_newsletter' !== $post->post_type || '1' !== (string) get_post_meta( $newsletter_id, '_npmp_newsletter_public', true ) ) {
		return '';
	}
	if ( function_exists( 'npmp_newsletter_archive_post_statuses' ) && ! in_array( $post->post_status, npmp_newsletter_archive_post_statuses(), true ) ) {
		return '';
	}
	if ( function_exists( 'npmp_newsletter_archive_holds_private_meeting' ) && npmp_newsletter_archive_holds_private_meeting( $newsletter_id ) ) {
		return '';
	}
	if ( function_exists( 'npmp_newsletter_is_imported' ) && npmp_newsletter_is_imported( $post ) ) {
		return '';
	}
	return (string) npmp_newsletter_archive_url( $newsletter_id );
}

/**
 * In an email render, print an embed block as a link (and a YouTube video as
 * its linked thumbnail). The web version keeps the player.
 *
 * @param string $html  Block output.
 * @param array  $block Parsed block.
 * @return string
 */
function npmp_email_embed_render_block( $html, $block ) {
	$name = (string) ( $block['blockName'] ?? '' );
	if ( 0 !== strpos( $name, 'core/embed' ) && 0 !== strpos( $name, 'core-embed/' ) ) {
		return $html;
	}
	if ( ! function_exists( 'npmp_email_is_rendering' ) || ! npmp_email_is_rendering() || npmp_newsletter_web_render() ) {
		return $html;
	}
	$attrs    = (array) ( $block['attrs'] ?? array() );
	$source   = (string) ( $attrs['url'] ?? '' );
	$provider = (string) ( $attrs['providerNameSlug'] ?? ( 0 === strpos( $name, 'core-embed/' ) ? substr( $name, 11 ) : '' ) );
	$type     = (string) ( $attrs['type'] ?? '' );
	$swap     = npmp_email_embed_html( $source, npmp_email_embed_target(), $provider, $type );
	if ( '' === $swap ) {
		return npmp_email_embed_swap_iframes( $html, npmp_email_embed_target() );
	}
	$caption = '';
	if ( preg_match( '#<figcaption\b[^>]*>(.*?)</figcaption>#is', (string) $html, $c ) && '' !== trim( wp_strip_all_tags( $c[1] ) ) ) {
		$caption = '<p class="npmp-email-embed-caption">' . wp_kses_post( trim( $c[1] ) ) . '</p>';
	}
	return '<div class="npmp-email-embed">' . $swap . $caption . '</div>';
}
add_filter( 'render_block', 'npmp_email_embed_render_block', 20, 2 );
