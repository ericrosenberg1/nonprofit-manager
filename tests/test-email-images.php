<?php
/**
 * Images and links in sent email (ROS-4261, ROS-4272).
 *
 * Three separate ways an image a user put in an email body never reached the
 * inbox, or arrived linked to nowhere:
 *
 * 1. npmp_email_prepare_images() in includes/email/helpers.php. A newsletter
 *    body goes through the_content, so core hands back browser-only
 *    attributes (srcset, sizes="auto, ...", loading, decoding,
 *    fetchpriority), and leaves a relative src relative. Nothing resolves a
 *    relative URL once the message has left the site.
 * 2. npmp_apply_newsletter_template() in includes/email-newsletter/templates.php.
 *    [email_content] is a shortcode, so the_content expanded it into the
 *    preview box before str_replace() could swap the body in, and the whole
 *    body was dropped.
 * 3. npmp_email_prepare_links() in includes/email/helpers.php. The link on an
 *    image, usually the image's own media file, is stored as typed, so it can
 *    be relative in exactly the way the src was, and dies the same way
 *    (ROS-4272).
 *
 * Run: php tests/test-email-images.php
 */

require_once __DIR__ . '/bootstrap.php';

function home_url( $path = '' ) {
	return 'https://u3acommunities.org' . $path;
}

require_once dirname( __DIR__ ) . '/includes/email/helpers.php';

/* ---------------------------------------------------------------------------
 * npmp_email_absolute_image_url()
 * ------------------------------------------------------------------------ */

$base = 'https://u3acommunities.org';

check(
	'absolute https src untouched',
	'https://cdn.example.org/logo.png',
	npmp_email_absolute_image_url( 'https://cdn.example.org/logo.png', $base )
);
check(
	'root-relative src resolved',
	'https://u3acommunities.org/wp-content/uploads/logo.png',
	npmp_email_absolute_image_url( '/wp-content/uploads/logo.png', $base )
);
check(
	'document-relative src resolved',
	'https://u3acommunities.org/wp-content/uploads/logo.png',
	npmp_email_absolute_image_url( 'wp-content/uploads/logo.png', $base )
);
check(
	'protocol-relative src takes the site scheme',
	'https://u3acommunities.org/logo.png',
	npmp_email_absolute_image_url( '//u3acommunities.org/logo.png', $base )
);
check(
	'protocol-relative src on an http site',
	'http://example.org/logo.png',
	npmp_email_absolute_image_url( '//example.org/logo.png', 'http://example.org' )
);
check(
	'data URI left alone',
	'data:image/png;base64,iVBORw0KGgo=',
	npmp_email_absolute_image_url( 'data:image/png;base64,iVBORw0KGgo=', $base )
);
check(
	'cid attachment reference left alone',
	'cid:logo@npmp',
	npmp_email_absolute_image_url( 'cid:logo@npmp', $base )
);
check(
	'empty src left alone',
	'',
	npmp_email_absolute_image_url( '', $base )
);
check(
	'no base URL means no rewrite',
	'/wp-content/uploads/logo.png',
	npmp_email_absolute_image_url( '/wp-content/uploads/logo.png', '' )
);

/* ---------------------------------------------------------------------------
 * npmp_email_prepare_images()
 * ------------------------------------------------------------------------ */

// Exactly what WordPress 7.1 hands back for a core/image block.
$rendered = '<figure class="wp-block-image size-large"><img loading="lazy" decoding="async" '
	. 'width="600" height="200" src="https://u3acommunities.org/wp-content/uploads/logo.png" '
	. 'alt="U3A logo" class="wp-image-5" '
	. 'srcset="https://u3acommunities.org/wp-content/uploads/logo.png 600w, https://u3acommunities.org/wp-content/uploads/logo-300x100.png 300w" '
	. 'sizes="auto, (max-width: 600px) 100vw, 600px" /></figure>';

$safe = npmp_email_prepare_images( $rendered );

check( 'srcset dropped', false, strpos( $safe, 'srcset' ) );
check( 'sizes dropped', false, strpos( $safe, 'sizes=' ) );
check( 'loading dropped', false, strpos( $safe, 'loading=' ) );
check( 'decoding dropped', false, strpos( $safe, 'decoding=' ) );
check( 'src kept', true, false !== strpos( $safe, 'src="https://u3acommunities.org/wp-content/uploads/logo.png"' ) );
check( 'alt kept', true, false !== strpos( $safe, 'alt="U3A logo"' ) );
check( 'width kept', true, false !== strpos( $safe, 'width="600"' ) );
check( 'height kept', true, false !== strpos( $safe, 'height="200"' ) );
check( 'figure wrapper kept', true, false !== strpos( $safe, '<figure class="wp-block-image size-large">' ) );

check(
	'fetchpriority dropped',
	false,
	strpos( npmp_email_prepare_images( '<img fetchpriority="high" src="https://x.test/a.png">' ), 'fetchpriority' )
);

check(
	'relative src in a body is absolutised',
	true,
	false !== strpos(
		npmp_email_prepare_images( '<p>Hi</p><img src="/wp-content/uploads/logo.png" alt="x" />' ),
		'src="https://u3acommunities.org/wp-content/uploads/logo.png"'
	)
);

check(
	'single-quoted src handled',
	true,
	false !== strpos(
		npmp_email_prepare_images( "<img src='/wp-content/uploads/logo.png' alt='x' />" ),
		"src='https://u3acommunities.org/wp-content/uploads/logo.png'"
	)
);

check(
	'two images both handled',
	2,
	preg_match_all(
		'#src="https://u3acommunities\.org/a/[12]\.png"#',
		npmp_email_prepare_images( '<img src="/a/1.png" srcset="x 1w"><img src="/a/2.png" loading="lazy">' )
	)
);

check(
	'body with no image returned unchanged',
	'<p>No pictures here.</p>',
	npmp_email_prepare_images( '<p>No pictures here.</p>' )
);
check( 'empty body returned unchanged', '', npmp_email_prepare_images( '' ) );

// A tracking pixel is already absolute and carries no browser attributes, so
// it has to come back untouched.
$pixel = '<img src="https://u3acommunities.org/?npmp_open=1" alt="" width="1" height="1" style="display:none;" />';
check( 'tracking pixel untouched', $pixel, npmp_email_prepare_images( $pixel ) );

// A link around the image must survive, since click tracking rewrites hrefs
// by exact string match afterwards.
check(
	'surrounding link untouched',
	true,
	false !== strpos(
		npmp_email_prepare_images( '<a href="https://u3acommunities.org/news"><img src="/a.png" loading="lazy"></a>' ),
		'<a href="https://u3acommunities.org/news">'
	)
);

/* ---------------------------------------------------------------------------
 * The [email_content] template placeholder
 * ------------------------------------------------------------------------ */

$templates_src = file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/templates.php' );

check(
	'placeholder shortcode is a named callback',
	1,
	preg_match( '/function npmp_email_content_placeholder_shortcode\(/', $templates_src )
);
check(
	'placeholder shortcode still registered',
	1,
	preg_match( "/add_shortcode\('email_content', 'npmp_email_content_placeholder_shortcode'\)/", $templates_src )
);
check(
	'template render switches the shortcode off first',
	1,
	preg_match( "/remove_shortcode\('email_content'\)/", $templates_src )
);
check(
	'template render puts the shortcode back',
	1,
	preg_match( "/add_shortcode\('email_content', 'npmp_email_content_placeholder_shortcode'\);\s*\n\s*}/", $templates_src )
);
check(
	'a template with no placeholder keeps the body',
	1,
	preg_match( "/false === strpos\(\\\$template_content, '\[email_content\]'\)/", $templates_src )
);

/* ---------------------------------------------------------------------------
 * Both send paths call the image helper
 * ------------------------------------------------------------------------ */

$manager_src = file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/class-newsletter-manager.php' );
check(
	'newsletter render prepares images',
	1,
	preg_match( '/npmp_email_prepare_images\( \$content \)/', $manager_src )
);

$sched_src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-scheduled-emails.php' );
check(
	'event email test prepares images',
	1,
	preg_match( '/npmp_email_prepare_images\( \$html \)/', $sched_src )
);

/* ---------------------------------------------------------------------------
 * npmp_email_prepare_links() -- the link on an image, and every other link
 * ------------------------------------------------------------------------ */

check(
	'absolute https href untouched',
	'https://u3acommunities.org/news',
	npmp_email_absolute_url( 'https://u3acommunities.org/news', $base )
);
check(
	'root-relative href resolved',
	'https://u3acommunities.org/news',
	npmp_email_absolute_url( '/news', $base )
);
check(
	'document-relative href resolved',
	'https://u3acommunities.org/newsletter/archive',
	npmp_email_absolute_url( 'newsletter/archive', $base )
);
check(
	'protocol-relative href takes the site scheme',
	'https://u3acommunities.org/join',
	npmp_email_absolute_url( '//u3acommunities.org/join', $base )
);
check(
	'mailto href untouched',
	'mailto:pete@plord.co.uk',
	npmp_email_absolute_url( 'mailto:pete@plord.co.uk', $base )
);
check(
	'tel href untouched',
	'tel:+441234567890',
	npmp_email_absolute_url( 'tel:+441234567890', $base )
);
check(
	'in-message anchor untouched',
	'#top',
	npmp_email_absolute_url( '#top', $base )
);
check(
	'unfilled placeholder untouched',
	'[event_url]',
	npmp_email_absolute_url( '[event_url]', $base )
);
check(
	'empty href untouched',
	'',
	npmp_email_absolute_url( '', $base )
);

// The reported shape: an image linked to its own media file, where the link
// was typed into the editor root-relative.
check(
	'link on an image made absolute',
	'<figure><a href="https://u3acommunities.org/news"><img src="https://u3acommunities.org/wp-content/uploads/logo.png" alt="" /></a></figure>',
	npmp_email_prepare_links(
		npmp_email_prepare_images( '<figure><a href="/news"><img src="/wp-content/uploads/logo.png" alt="" loading="lazy" /></a></figure>' )
	)
);
check(
	'single-quoted href resolved',
	"<a href='https://u3acommunities.org/news'>x</a>",
	npmp_email_prepare_links( "<a href='/news'>x</a>" )
);
check(
	'every link in a body resolved, one pass',
	2,
	preg_match_all(
		'#href="https://u3acommunities\.org/#',
		npmp_email_prepare_links( '<a href="/a">a</a><p>words</p><a href="b">b</a>' )
	)
);
check(
	'a body with no links returned unchanged',
	'<p>No links here.</p>',
	npmp_email_prepare_links( '<p>No links here.</p>' )
);
check( 'empty body returned unchanged by the link pass', '', npmp_email_prepare_links( '' ) );

// <address> starts with "a" too, and must not be read as a link tag.
check(
	'address element untouched',
	'<address>12 High St</address>',
	npmp_email_prepare_links( '<address>12 High St</address>' )
);

// Click tracking only follows an http or https link, so the absolute pass has
// to run first or a relative link is never tracked either.
$manager_order = file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/class-newsletter-manager.php' );
check(
	'links are absolute before click tracking runs',
	true,
	strpos( $manager_order, 'npmp_email_prepare_links( $content )' ) < strpos( $manager_order, 'self::track_links(' )
);

/* ---------------------------------------------------------------------------
 * Both send paths call the link helper too
 * ------------------------------------------------------------------------ */

check(
	'newsletter render prepares links',
	1,
	preg_match( '/npmp_email_prepare_links\( \$content \)/', $manager_src )
);
check(
	'event email test prepares links',
	1,
	preg_match( '/npmp_email_prepare_links\( \$html \)/', $sched_src )
);

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
