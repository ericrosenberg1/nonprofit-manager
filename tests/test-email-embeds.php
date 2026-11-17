<?php
/**
 * Embeds in a newsletter's email version (includes/email-newsletter/email-embeds.php).
 *
 * Pete on wordpress.org (topic "sharing-videos-and-other-content-in-a-newsletter")
 * found YouTube videos and Google Slides blank in the email and fine on the
 * web version. In the email, each embed becomes a link (to the web version
 * when there is one), and a YouTube video gets its thumbnail. Checks the video
 * ID, the kind of embed, the link text, the markup, and the iframe swap. The
 * real render, a send, and the web page are in tests/rig/email-embeds.php.
 *
 * Run: php tests/test-email-embeds.php
 */

require_once __DIR__ . '/bootstrap.php';
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true;
	}
}
require_once dirname( __DIR__ ) . '/includes/email-newsletter/email-embeds.php';

echo "YouTube video IDs\n";
check( 'watch URL', 'dQw4w9WgXcQ', npmp_email_embed_youtube_id( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s' ) );
check( 'watch URL with escaped ampersand', 'dQw4w9WgXcQ', npmp_email_embed_youtube_id( 'https://www.youtube.com/watch?feature=share&amp;v=dQw4w9WgXcQ' ) );
check( 'short link', 'dQw4w9WgXcQ', npmp_email_embed_youtube_id( 'https://youtu.be/dQw4w9WgXcQ?si=abc' ) );
check( 'embed URL', 'dQw4w9WgXcQ', npmp_email_embed_youtube_id( 'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed' ) );
check( 'privacy-mode embed', 'dQw4w9WgXcQ', npmp_email_embed_youtube_id( '//www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ) );
check( 'Shorts', 'dQw4w9WgXcQ', npmp_email_embed_youtube_id( 'https://youtube.com/shorts/dQw4w9WgXcQ' ) );
check( 'mobile site', 'dQw4w9WgXcQ', npmp_email_embed_youtube_id( 'https://m.youtube.com/watch?v=dQw4w9WgXcQ' ) );
check( 'a playlist has no video ID', '', npmp_email_embed_youtube_id( 'https://www.youtube.com/playlist?list=PL123' ) );
check( 'a look-alike host is refused', '', npmp_email_embed_youtube_id( 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ' ) );
check( 'Vimeo is not YouTube', '', npmp_email_embed_youtube_id( 'https://vimeo.com/76979871' ) );
check( 'a bad ID is refused', '', npmp_email_embed_youtube_id( 'https://youtu.be/short' ) );

echo "Kinds and labels\n";
check( 'Google Slides are slides', 'slides', npmp_email_embed_kind( 'https://docs.google.com/presentation/d/e/2PACX-1/embed?start=false' ) );
check( 'YouTube is a video', 'video', npmp_email_embed_kind( 'https://youtu.be/dQw4w9WgXcQ' ) );
check( 'Vimeo by URL is a video', 'video', npmp_email_embed_kind( 'https://player.vimeo.com/video/76979871' ) );
check( 'a block type of video counts', 'video', npmp_email_embed_kind( 'https://example.org/clip', '', 'video' ) );
check( 'a provider slug counts', 'video', npmp_email_embed_kind( 'https://example.org/x', 'dailymotion' ) );
check( 'a SlideShare provider is slides', 'slides', npmp_email_embed_kind( 'https://example.org/x', 'slideshare' ) );
check( 'a post embed is other', 'other', npmp_email_embed_kind( 'https://example.org/2026/11/post/', 'wordpress', 'rich' ) );
check( 'video label', 'Watch the video', npmp_email_embed_label( 'video' ) );
check( 'slides label', 'View the slides', npmp_email_embed_label( 'slides' ) );
check( 'other label', 'View the embedded content', npmp_email_embed_label( 'other' ) );

echo "Markup\n";
$web  = 'https://example.org/newsletter/12/november/';
$html = npmp_email_embed_html( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $web, 'youtube', 'video' );
check( 'a YouTube video gets its thumbnail', true, false !== strpos( $html, 'src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"' ) );
check( 'the thumbnail links to the web version', true, false !== strpos( $html, '<a href="' . $web . '"><img' ) );
check( 'and so does the text', true, false !== strpos( $html, '<a href="' . $web . '">Watch the video</a>' ) );
check( 'no iframe in the email', false, stripos( $html, '<iframe' ) );
$html = npmp_email_embed_html( 'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed', '' );
check( 'with no web version, a YouTube link goes to the watch page', true, false !== strpos( $html, 'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"' ) );
$html = npmp_email_embed_html( 'https://docs.google.com/presentation/d/e/2PACX-1/embed?start=false', '' );
check( 'slides with no web version link to the slides', true, false !== strpos( $html, 'href="https://docs.google.com/presentation/d/e/2PACX-1/embed?start=false">View the slides</a>' ) );
check( 'slides get no thumbnail', false, strpos( $html, '<img' ) );
$html = npmp_email_embed_html( 'https://vimeo.com/76979871', $web, 'vimeo', 'video' );
check( 'Vimeo is a plain link to the web version', '<p class="npmp-email-embed-link"><a href="' . $web . '">Watch the video</a></p>', $html );
check( 'a javascript source with no web version gives nothing', '', npmp_email_embed_html( 'javascript:alert(1)', '' ) );
check( 'a quote in the source can\'t break out', false, strpos( npmp_email_embed_html( 'https://example.org/a"onmouseover="x', '' ), 'onmouseover="x' ) );

echo "Iframes\n";
$in  = '<p>Before</p><iframe src="https://docs.google.com/presentation/d/e/2PACX-1/embed?start=false" width="960" height="569" allowfullscreen="true"></iframe><p>After</p>';
$out = npmp_email_embed_swap_iframes( $in, $web );
check( 'a Slides iframe becomes a link to the web version', '<p>Before</p><p class="npmp-email-embed-link"><a href="' . $web . '">View the slides</a></p><p>After</p>', $out );
$out = npmp_email_embed_swap_iframes( "<IFRAME width='560' SRC='https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'></IFRAME>" );
check( 'a single-quoted YouTube iframe gets the thumbnail and the watch link', true, false !== strpos( $out, 'hqdefault.jpg' ) && false !== strpos( $out, 'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"' ) && false === stripos( $out, 'iframe' ) );
check( 'an iframe with no source goes away', '<p>x</p>', npmp_email_embed_swap_iframes( '<p>x</p><iframe></iframe>' ) );
check( 'HTML with no iframe stays byte for byte', '<p>Hi <a href="https://x">x</a></p>', npmp_email_embed_swap_iframes( '<p>Hi <a href="https://x">x</a></p>' ) );

echo "Render context\n";
check( 'no web render by default', false, npmp_newsletter_web_render() );
npmp_newsletter_web_render( 1 );
check( 'inside the web render', true, npmp_newsletter_web_render() );
npmp_newsletter_web_render( -1 );
check( 'after it', false, npmp_newsletter_web_render() );
npmp_email_embed_target( $web );
npmp_email_embed_target( '' );
check( 'nested targets: the inner one wins', '', npmp_email_embed_target() );
npmp_email_embed_target( false );
check( 'and the outer one comes back', $web, npmp_email_embed_target() );
npmp_email_embed_target( false );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
