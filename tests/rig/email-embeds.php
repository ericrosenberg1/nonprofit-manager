<?php
/**
 * Real-WordPress check of embeds in a newsletter's email version (Pete, topic
 * "sharing-videos-and-other-content-in-a-newsletter").
 *
 * Usage, with the newsletters module on:
 *   python3 tests/rig/smtp-sink.py 10426 /tmp/npmp-embeds-sink.jsonl &
 *   php -S <siteurl host:port> -t <wp dir> &        (for the web page)
 *   NPMP_RIG_SINK=/tmp/npmp-embeds-sink.jsonl NPMP_RIG_SINK_PORT=10426 wp eval-file tests/rig/email-embeds.php
 *
 * A newsletter with a YouTube embed block, a Vimeo embed block, and a Google
 * Slides iframe in a Custom HTML block. oEmbed answers come from a local fake,
 * so nothing reaches YouTube. Checks the email render and a real test send
 * through custom SMTP (thumbnail, links to the web version, no iframe), the
 * same newsletter unticked (links go to the source), and the web page over
 * HTTP (players kept). Deletes what it makes and restores email settings.
 */

$fails  = 0;
$passes = 0;
$ok     = function ( $label, $cond ) use ( &$fails, &$passes ) {
	if ( ! $cond ) {
		$fails++;
	} else {
		$passes++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_email_embed_render_block' ) || ! class_exists( 'NPMP_Newsletter_Manager' ) ) {
	update_option( 'npmp_enabled_features', array_merge( (array) get_option( 'npmp_enabled_features', array() ), array( 'members' => true, 'newsletters' => true ) ) );
	echo "  NOTE  turned on members and newsletters, run the rig again\n";
	exit( 1 );
}
$sink_file = getenv( 'NPMP_RIG_SINK' ) ?: '/tmp/npmp-embeds-sink.jsonl';
$sink_port = (int) ( getenv( 'NPMP_RIG_SINK_PORT' ) ?: 10426 );
wp_set_current_user( 1 );

// A local oEmbed answer, so the web render shows a player without the network.
add_filter(
	'pre_oembed_result',
	static function ( $result, $url ) {
		if ( preg_match( '#youtube|vimeo#', $url ) ) {
			return '<iframe title="Rig player" src="' . esc_url( 'https://player.example/embed?u=' . rawurlencode( $url ) ) . '" width="500" height="281"></iframe>';
		}
		return $result;
	},
	10,
	2
);

$yt     = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
$vimeo  = 'https://vimeo.com/76979871';
$slides = 'https://docs.google.com/presentation/d/e/2PACX-1vRig/embed?start=false&amp;loop=false';
$body   = '<!-- wp:paragraph --><p>Our talk is below.</p><!-- /wp:paragraph -->'
	. '<!-- wp:embed {"url":"' . $yt . '","type":"video","providerNameSlug":"youtube","responsive":true} --><figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\n" . $yt . "\n" . '</div><figcaption class="wp-element-caption">Our November talk</figcaption></figure><!-- /wp:embed -->'
	. '<!-- wp:embed {"url":"' . $vimeo . '","type":"video","providerNameSlug":"vimeo"} --><figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo"><div class="wp-block-embed__wrapper">' . "\n" . $vimeo . "\n" . '</div></figure><!-- /wp:embed -->'
	. '<!-- wp:html --><iframe src="' . $slides . '" frameborder="0" width="960" height="569" allowfullscreen="true"></iframe><!-- /wp:html -->'
	. '<!-- wp:paragraph --><p>See you soon.</p><!-- /wp:paragraph -->';
$id = wp_insert_post(
	array(
		'post_type'    => 'npmp_newsletter',
		'post_status'  => 'publish',
		'post_title'   => 'November talk',
		'post_content' => $body,
	)
);
update_post_meta( $id, '_npmp_newsletter_template', 'none' );
update_post_meta( $id, '_npmp_newsletter_public', '1' );
update_post_meta( $id, '_npmp_newsletter_status', 'sent' );
$web = npmp_newsletter_archive_url( $id );

echo "Email version, newsletter public\n";
$email = (string) NPMP_Newsletter_Manager::render_base_content( $id );
$ok( 'no iframe in the email', false === stripos( $email, '<iframe' ) );
$ok( 'the YouTube thumbnail links to the web version', false !== strpos( $email, '<a href="' . esc_url( $web ) . '"><img src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"' ) );
$ok( 'two "Watch the video" links and one "View the slides"', 2 === substr_count( $email, '>Watch the video</a>' ) && 1 === substr_count( $email, '>View the slides</a>' ) );
$ok( 'every swapped link goes to the web version', 4 === substr_count( $email, 'href="' . esc_url( $web ) . '"' ) );
$ok( 'the caption stays', false !== strpos( $email, 'Our November talk' ) );
$ok( 'the text around the embeds stays', false !== strpos( $email, 'Our talk is below.' ) && false !== strpos( $email, 'See you soon.' ) );

echo "Email version, newsletter not public\n";
update_post_meta( $id, '_npmp_newsletter_public', '0' );
$private = (string) NPMP_Newsletter_Manager::render_base_content( $id );
$ok( 'YouTube links to the watch page', false !== strpos( $private, 'href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"' ) );
$ok( 'Vimeo links to Vimeo', false !== strpos( $private, 'href="' . esc_url( $vimeo ) . '">Watch the video' ) );
$ok( 'the slides link to the slides', false !== strpos( $private, 'docs.google.com/presentation/d/e/2PACX-1vRig/embed?start=false' ) && false !== strpos( $private, '>View the slides</a>' ) );
$ok( 'nothing links to the web version', false === strpos( $private, esc_url( $web ) ) );
update_post_meta( $id, '_npmp_newsletter_public', '1' );

echo "A test send over custom SMTP\n";
$saved = get_option( 'npmp_email_settings' );
update_option(
	'npmp_email_settings',
	array(
		'provider'        => 'smtp',
		'from_email'      => 'hello@npmp-rig.test',
		'from_name'       => 'Rig Council',
		'force_from'      => 0,
		'from_all_mail'   => 0,
		'set_return_path' => 0,
		'smtp'            => array(
			'host'       => '127.0.0.1',
			'port'       => $sink_port,
			'encryption' => 'none',
			'auth'       => 1,
			'username'   => 'rig-user',
			'password'   => 'rig-pass',
			'auto_tls'   => 0,
		),
	)
);
try {
	@unlink( $sink_file );
	$sent = NPMP_Newsletter_Manager::send_test_email( $id, 'admin@example.test' );
	clearstatcache();
	$lines = file_exists( $sink_file ) ? file( $sink_file, FILE_IGNORE_NEW_LINES ) : array();
	$msg   = $lines ? json_decode( end( $lines ), true ) : null;
	$raw   = $msg ? (string) $msg['data'] : '';
	$plain = quoted_printable_decode( $raw );
	if ( preg_match( '/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $b ) ) {
		$plain = base64_decode( preg_replace( '/\s+/', '', $b[1] ) );
	}
	$ok( 'the test email went out', $sent && '' !== $raw );
	$ok( 'the received email has the thumbnail and no iframe', false !== strpos( $plain, 'i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' ) && false === stripos( $plain, '<iframe' ) );
	$ok( 'and the slides link', false !== strpos( $plain, 'View the slides' ) );
} finally {
	if ( false === $saved ) {
		delete_option( 'npmp_email_settings' );
	} else {
		update_option( 'npmp_email_settings', $saved );
	}
}

echo "Web version\n";
$page = npmp_newsletter_archive_body( $id );
$ok( 'the web body keeps the players and the slides iframe', 2 === substr_count( $page, 'player.example/embed' ) && false !== strpos( $page, 'docs.google.com/presentation/d/e/2PACX-1vRig/embed' ) );
$ok( 'the web body has no stand-in links', false === strpos( $page, 'Watch the video' ) && false === strpos( $page, 'hqdefault.jpg' ) );
$after = (string) NPMP_Newsletter_Manager::render_base_content( $id );
$ok( 'an email render after the web render still swaps', false === stripos( $after, '<iframe' ) && false !== strpos( $after, 'Watch the video' ) );
$r = wp_remote_get( $web, array( 'timeout' => 20 ) );
if ( is_wp_error( $r ) ) {
	echo '  SKIP  no web server answers at ' . esc_url_raw( home_url( '/' ) ) . "\n";
} else {
	$html = wp_remote_retrieve_body( $r );
	$ok( 'the public page answers', 200 === wp_remote_retrieve_response_code( $r ) && false !== strpos( $html, 'Our talk is below.' ) );
	$ok( 'the public page keeps the slides iframe and no stand-in link', false !== strpos( $html, 'docs.google.com/presentation/d/e/2PACX-1vRig/embed' ) && false === strpos( $html, 'View the slides' ) );
}

wp_delete_post( $id, true );
echo "\n{$passes} passed, {$fails} failed\n";
exit( $fails ? 1 : 0 );
