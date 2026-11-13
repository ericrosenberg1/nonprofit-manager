<?php
/**
 * Past newsletters from MailPoet and The Newsletter Plugin, the parts with
 * no database (includes/import/past-newsletters-pure.php): the MailPoet
 * block converter, the tag conversion, and the scrub that keeps tokens,
 * addresses, and tracking out of an imported body.
 *
 * The WordPress side runs in tests/rig/past-newsletters.php.
 *
 * Run: php tests/test-past-newsletters.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/import/past-newsletters-pure.php';

$ctx = array(
	'site_name'   => 'Rig Club',
	'home_url'    => 'https://club.example.org',
	'site_url'    => 'https://club.example.org',
	'subject'     => 'March news',
	'date_values' => npmp_past_nl_date_values( gmmktime( 12, 0, 0, 3, 4, 2025 ) ),
);

echo "MailPoet tags\n";
check( 'first name with a pipe default', 'Hi [first_name default="reader"],', npmp_past_nl_mailpoet_tags( 'Hi [subscriber:firstname | default:reader],', $ctx ) );
check( 'first name with a WordPress-style default', 'Hi [first_name default="friend"]', npmp_past_nl_mailpoet_tags( 'Hi [subscriber:firstname default="friend"]', $ctx ) );
check( 'first name, no default', '[first_name]', npmp_past_nl_mailpoet_tags( '[subscriber:firstname]', $ctx ) );
check( 'last name', '[last_name default="Smith"]', npmp_past_nl_mailpoet_tags( '[subscriber:lastname | default:Smith]', $ctx ) );
check( 'display name', '[name]', npmp_past_nl_mailpoet_tags( '[subscriber:displayname]', $ctx ) );
check( 'email goes', 'Sent to .', npmp_past_nl_mailpoet_tags( 'Sent to [subscriber:email].', $ctx ) );
check( 'custom field goes', 'x', npmp_past_nl_mailpoet_tags( 'x[subscriber:cf_3 | default:none]', $ctx ) );
check( 'subscriber count goes', '', npmp_past_nl_mailpoet_tags( '[subscriber:count]', $ctx ) );
check( 'subject', 'March news', npmp_past_nl_mailpoet_tags( '[newsletter:subject]', $ctx ) );
check( 'post title goes', '', npmp_past_nl_mailpoet_tags( '[newsletter:post_title]', $ctx ) );
check( 'site title', 'Rig Club', npmp_past_nl_mailpoet_tags( '[site:title]', $ctx ) );
check( 'site link', '<a href="https://club.example.org">Rig Club</a>', npmp_past_nl_mailpoet_tags( '[site:homepage_link]', $ctx ) );
check( 'send date', '4 March 2025', npmp_past_nl_mailpoet_tags( '[date:d] [date:mtext] [date:y]', $ctx ) );
check( 'a link tag in text goes', 'Leave: ', npmp_past_nl_mailpoet_tags( 'Leave: [link:subscription_unsubscribe_url]', $ctx ) );
check( 'a link tag in an href stays for the scrub', '<a href="[link:subscription_unsubscribe_url]">x</a>', npmp_past_nl_mailpoet_tags( '<a href="[link:subscription_unsubscribe_url]">x</a>', $ctx ) );
check( 'plain brackets stay', 'See [1] and [note]', npmp_past_nl_mailpoet_tags( 'See [1] and [note]', $ctx ) );

echo "The Newsletter Plugin's tags\n";
$tctx = $ctx + array(
	'timestamp'    => gmmktime( 12, 0, 0, 3, 4, 2025 ),
	'name_default' => 'friend',
);
check( '{name} becomes first name with the no-name text', 'Hi [first_name default="friend"]', npmp_past_nl_tnp_tags( 'Hi {name}', $tctx ) );
check( '{surname}', '[last_name]', npmp_past_nl_tnp_tags( '{surname}', $tctx ) );
check( '{full_name}', '[name default="friend"]', npmp_past_nl_tnp_tags( '{full_name}', $tctx ) );
check( '{email} goes', 'to ', npmp_past_nl_tnp_tags( 'to {email}', $tctx ) );
check( '{token} goes', '', npmp_past_nl_tnp_tags( '{token}', $tctx ) );
check( '{profile_2} goes', '', npmp_past_nl_tnp_tags( '{profile_2}', $tctx ) );
check( '{blog_title}', 'Rig Club', npmp_past_nl_tnp_tags( '{blog_title}', $tctx ) );
check( '{date}', 'March 4, 2025', npmp_past_nl_tnp_tags( '{date}', $tctx ) );
check( '{date_Y}', '2025', npmp_past_nl_tnp_tags( '{date_Y}', $tctx ) );
check( 'an unknown {word} stays', 'Use {this}', npmp_past_nl_tnp_tags( 'Use {this}', $tctx ) );
check( 'a link tag in an href stays for the scrub', '<a href="{unsubscription_url}">x</a>', npmp_past_nl_tnp_tags( '<a href="{unsubscription_url}">x</a>', $tctx ) );
check( 'a link tag in text goes to #', '#', npmp_past_nl_tnp_tags( '{profile_url}', $tctx ) );
check( 'encoded tag in an href stays', '<a href="https://x.test/?e=%7Bemail%7D">x</a>', npmp_past_nl_tnp_tags( '<a href="https://x.test/?e=%7Bemail%7D">x</a>', $tctx ) );
check( 'plugin shortcodes go', 'ab', npmp_past_nl_drop_plugin_shortcodes( 'a[newsletter_form]b[/newsletter]' ) );
check( 'mailpoet shortcodes go but tags stay', '[mailpoet_click_data]-ab', npmp_past_nl_drop_plugin_shortcodes( '[mailpoet_page][mailpoet_click_data]-ab' ) );

echo "Links\n";
$map = array(
	'h1' => 'https://example.org/story',
	'h2' => '[link:subscription_unsubscribe_url]',
	'h3' => '[link:newsletter_view_in_browser_url]',
);
check( 'MailPoet click hash restores the link', array( 'action' => 'replace', 'url' => 'https://example.org/story' ), npmp_past_nl_classify_link( '[mailpoet_click_data]-h1', array( 'link_map' => $map ) ) );
check( 'MailPoet click hash to unsubscribe goes', 'remove', npmp_past_nl_classify_link( '[mailpoet_click_data]-h2', array( 'link_map' => $map ) )['action'] );
check( 'MailPoet click hash to view in browser goes', 'remove', npmp_past_nl_classify_link( '[mailpoet_click_data]-h3', array( 'link_map' => $map ) )['action'] );
check( 'MailPoet click hash with no row unwraps', 'unwrap', npmp_past_nl_classify_link( '[mailpoet_click_data]-zz', array( 'link_map' => $map ) )['action'] );
$data = rtrim( strtr( base64_encode( json_encode( array( 5, 'tok', 9, 'h1', false ) ) ), '+/', '-_' ), '=' );
check( 'MailPoet router click link restores', array( 'action' => 'replace', 'url' => 'https://example.org/story' ), npmp_past_nl_classify_link( 'https://club.example.org/?mailpoet_router&endpoint=track&action=click&data=' . $data, array( 'link_map' => $map ) ) );
check( 'MailPoet router unsubscribe goes', 'remove', npmp_past_nl_classify_link( 'https://club.example.org/?mailpoet_router&amp;endpoint=subscription&amp;action=unsubscribe&amp;data=abc', array() )['action'] );
check( 'MailPoet view in browser goes', 'remove', npmp_past_nl_classify_link( 'https://club.example.org/?mailpoet_router&endpoint=view_in_browser&action=view&data=abc', array() )['action'] );
$nltr = base64_encode( 'pat@example.test;12;https://example.org/a?b=1;Read more;sig' );
check( 'Newsletter nltr restores', array( 'action' => 'replace', 'url' => 'https://example.org/a?b=1' ), npmp_past_nl_classify_link( 'https://club.example.org/?nltr=' . rawurlencode( $nltr ), array() ) );
check( 'Newsletter ajax nltr restores', 'replace', npmp_past_nl_classify_link( 'https://club.example.org/wp-admin/admin-ajax.php?action=tnptr&nltr=' . rawurlencode( $nltr ), array() )['action'] );
$rest = rtrim( strtr( base64_encode( 'https://example.org/b' ), '+/', '-_' ), '=' );
check( 'Newsletter REST click restores', array( 'action' => 'replace', 'url' => 'https://example.org/b' ), npmp_past_nl_classify_link( 'https://club.example.org/wp-json/tnp/l/3/12/' . $rest . '/sig', array() ) );
check( 'Newsletter unsubscribe action goes', 'remove', npmp_past_nl_classify_link( 'https://club.example.org/?na=u&nk=12-abc', array() )['action'] );
check( 'Newsletter view online goes', 'remove', npmp_past_nl_classify_link( 'https://club.example.org/?na=v&id=4', array() )['action'] );
check( 'Newsletter unsubscribe tag goes', 'remove', npmp_past_nl_classify_link( '{unsubscription_url}', array() )['action'] );
check( 'an ordinary link stays', 'keep', npmp_past_nl_classify_link( 'https://example.org/page?id=3', array() )['action'] );
check( 'a javascript link unwraps', 'unwrap', npmp_past_nl_classify_link( 'javascript:alert(1)', array() )['action'] );
check( 'MailPoet open pixel', true, npmp_past_nl_is_pixel( '[mailpoet_open_data]' ) );
check( 'Newsletter open pixel', true, npmp_past_nl_is_pixel( 'https://club.example.org/?noti=abc' ) );
check( 'Newsletter REST pixel', true, npmp_past_nl_is_pixel( 'https://club.example.org/wp-json/tnp/o/abc' ) );
check( 'a real image is no pixel', false, npmp_past_nl_is_pixel( 'https://club.example.org/wp-content/uploads/a.jpg', '600', '400' ) );
check( 'Newsletter list numbers from options', array( 1, 3 ), npmp_past_nl_tnp_email_lists( serialize( array( 'lists' => array( '3', '1' ) ) ) ) );
check( 'Newsletter list numbers from the old preferences column', array( 2, 5 ), npmp_past_nl_tnp_email_lists( '', '5,2' ) );
check( 'serialized object never builds', array( 'x' => array( '__PHP_Incomplete_Class_Name' => 'Evil' ) ), json_decode( json_encode( npmp_past_nl_tnp_options( 'a:1:{s:1:"x";O:4:"Evil":0:{}}' ) ), true ) );

echo "The scrub on a rendered MailPoet email\n";
$rendered = '<!DOCTYPE html><html><head><title>t</title><style>body{color:red}</style><script>alert(1)</script></head>'
	. '<body style="background-color:#eeeeee;behavior:url(x)"><!--[if mso]><table><tr><td><![endif]-->'
	. '<p><a href="[mailpoet_click_data]-h3">View in browser</a></p>'
	. '<h1 onclick="steal()">Hello [first_name]</h1>'
	. '<p>Read <a href="[mailpoet_click_data]-h1" data-x="1">the story</a> and <a href="[mailpoet_click_data]-zz">this</a>.</p>'
	. '<img src="/wp-content/uploads/photo.jpg" alt="Photo" width="600" srcset="a 1x">'
	. '<img src="https://club.example.org/wp-content/plugins/mailpoet/assets/img/newsletter_editor/social-icons/01-social/Facebook.png" alt="facebook">'
	. '<a href="https://www.mailpoet.com/?ref=free-plan-user-email&utm_source=x"><img src="https://club.example.org/wp-content/plugins/mailpoet/assets/img/mailpoet_logo_newsletter.png" alt="MailPoet"></a>'
	. '<form action="x"><input name="e"></form><iframe src="https://evil.test"></iframe>'
	. '<p><a href="[mailpoet_click_data]-h2">Unsubscribe</a> | <a href="https://club.example.org/?mailpoet_router&endpoint=subscription&action=manage&data=xyz">Manage</a></p>'
	. '<img src="[mailpoet_open_data]" width="1" height="1" alt="">'
	. '<div style="width:expression(alert(1));color:blue">x</div>'
	. '</body></html>';
$out = npmp_past_nl_sanitize(
	$rendered,
	array(
		'link_map'       => $map,
		'plugin_folders' => array( 'mailpoet' ),
		'site_url'       => 'https://club.example.org',
	)
);
$h   = $out['html'];
check( 'no script', false, stripos( $h, 'script' ) !== false );
check( 'no style element', false, stripos( $h, '<style' ) !== false );
check( 'no title', false, stripos( $h, '<title' ) !== false );
check( 'no comments', false, strpos( $h, '<!--' ) !== false );
check( 'no form or input', false, (bool) preg_match( '/<(form|input|iframe)/i', $h ) );
check( 'no on* attributes', false, stripos( $h, 'onclick' ) !== false );
check( 'no data-* attributes', false, stripos( $h, 'data-x' ) !== false );
check( 'the story link is restored', true, false !== strpos( $h, 'href="https://example.org/story"' ) );
check( 'the unknown hash unwraps to its text', true, false !== strpos( $h, 'and this.' ) );
check( 'view in browser link and text go', false, stripos( $h, 'View in browser' ) !== false );
check( 'unsubscribe and manage go', false, (bool) preg_match( '/Unsubscribe|Manage/', $h ) );
check( 'open pixel goes', 1, $out['pixels'] );
check( 'relative image gets the site URL', true, false !== strpos( $h, 'src="https://club.example.org/wp-content/uploads/photo.jpg"' ) );
check( 'srcset goes', false, strpos( $h, 'srcset' ) !== false );
check( 'plugin-folder social icon becomes its alt text', true, false !== strpos( $h, 'Facebook' ) && false === strpos( $h, 'plugins/mailpoet' ) );
check( 'MailPoet badge goes', false, stripos( $h, 'mailpoet.com' ) !== false );
check( 'body background kept', true, 0 === strpos( $h, '<div style="background-color:#eeeeee">' ) );
check( 'expression() dropped, color kept', true, false !== strpos( $h, '<div style="color:blue">x</div>' ) );
check( 'leak finder finds nothing', array(), npmp_past_nl_find_leaks( $h, array( 'pat@example.test' ) ) );
check( 'leak finder spots a leftover tag', array( 'MailPoet tracking tag' ), npmp_past_nl_find_leaks( '<a href="[mailpoet_click_data]-x">a</a>' ) );
check( 'leak finder spots an address', array( 'Reader address pat@example.test' ), npmp_past_nl_find_leaks( 'hi Pat@example.test', array( 'pat@example.test' ) ) );
check( 'leak finder spots a Newsletter action link', array( 'Newsletter action link' ), npmp_past_nl_find_leaks( '<a href="https://x.test/?na=u&amp;nk=1">a</a>' ) );

echo "The scrub on a Newsletter Plugin body\n";
$tnp = '<p>Hi [first_name]</p><p><a href="https://club.example.org/?nltr=' . rawurlencode( $nltr ) . '">Read more</a></p>'
	. '<p><a href="{unsubscription_url}">Unsubscribe</a> <a href="https://club.example.org/?na=p&nk=1-x">Profile</a></p>'
	. '<img src="https://club.example.org/?noti=Zm9v" width="1" height="1">';
$out = npmp_past_nl_sanitize( $tnp, array( 'plugin_folders' => array( 'newsletter' ) ) );
check( 'tracked link restored', true, false !== strpos( $out['html'], 'href="https://example.org/a?b=1"' ) );
check( 'unsubscribe and profile go', false, (bool) preg_match( '/Unsubscribe|Profile/', $out['html'] ) );
check( 'no leaks', array(), npmp_past_nl_find_leaks( $out['html'], array( 'pat@example.test' ) ) );
check( 'an empty body stays empty', '', npmp_past_nl_sanitize( '   ' )['html'] );
check( 'block wrapper', "<!-- wp:html -->\n<p>x</p>\n<!-- /wp:html -->", npmp_past_nl_wrap_block( '<p>x</p>' ) );

echo "MailPoet block converter\n";
$body = array(
	'content'      => array(
		'type'   => 'container',
		'orientation' => 'vertical',
		'styles' => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
		'blocks' => array(
			array(
				'type'   => 'header',
				'text'   => '<p><a href="[link:newsletter_view_in_browser_url]">View this in your browser.</a></p>',
				'styles' => array(
					'block' => array( 'backgroundColor' => '#333333' ),
					'text'  => array( 'fontColor' => '#ffffff', 'textAlign' => 'center' ),
				),
			),
			array(
				'type'        => 'container',
				'orientation' => 'horizontal',
				'columnLayout' => '2_1',
				'blocks'      => array(
					array(
						'type'   => 'container',
						'orientation' => 'vertical',
						'blocks' => array(
							array( 'type' => 'text', 'text' => '<h1>Hello [subscriber:firstname | default:reader]</h1><p>Body <a href="https://example.org">link</a></p>' ),
							array( 'type' => 'image', 'src' => '/wp-content/uploads/a.png', 'alt' => 'A', 'width' => '320px', 'link' => 'https://example.org/img', 'styles' => array( 'block' => array( 'textAlign' => 'left' ) ) ),
						),
					),
					array(
						'type'   => 'container',
						'orientation' => 'vertical',
						'blocks' => array(
							array( 'type' => 'button', 'text' => 'Donate', 'url' => 'https://example.org/give', 'styles' => array( 'block' => array( 'backgroundColor' => '#2ea1cd', 'fontColor' => '#ffffff', 'borderRadius' => '5px', 'textAlign' => 'center' ) ) ),
							array( 'type' => 'divider', 'styles' => array( 'block' => array( 'borderStyle' => 'dashed', 'borderWidth' => '2px', 'borderColor' => '#999999', 'padding' => '10px' ) ) ),
							array( 'type' => 'spacer', 'styles' => array( 'block' => array( 'height' => '40px' ) ) ),
						),
					),
				),
			),
			array(
				'type'  => 'social',
				'icons' => array(
					array( 'type' => 'socialIcon', 'iconType' => 'facebook', 'link' => 'https://facebook.com/club', 'image' => 'https://club.example.org/wp-content/plugins/mailpoet/assets/img/newsletter_editor/social-icons/01-social/Facebook.png', 'text' => 'Facebook' ),
				),
			),
			array( 'type' => 'automatedLatestContentLayout', 'text' => '' ),
			array( 'type' => 'coupon', 'text' => 'SAVE10' ),
			array(
				'type' => 'footer',
				'text' => '<p><a href="[link:subscription_unsubscribe_url]">Unsubscribe</a> | <a href="[link:subscription_manage_url]">Manage your subscription</a><br>Rig Club, 1 Main St</p>',
			),
		),
	),
	'globalStyles' => array(
		'text'    => array( 'fontColor' => '#000000', 'fontFamily' => 'Arial', 'fontSize' => '16px' ),
		'h1'      => array( 'fontColor' => '#111111', 'fontSize' => '30px' ),
		'link'    => array( 'fontColor' => '#21759B', 'textDecoration' => 'underline' ),
		'wrapper' => array( 'backgroundColor' => '#ffffff' ),
		'body'    => array( 'backgroundColor' => '#eeeeee' ),
	),
);
$r = npmp_past_nl_mailpoet_render( wp_json_encode_for_test( $body ), array( 'site_url' => 'https://club.example.org' ) );
check( 'two blocks lost', array( 'automatedLatestContentLayout', 'coupon' ), $r['lost'] );
check( 'lost-type scan agrees', array( 'automatedLatestContentLayout', 'coupon' ), npmp_past_nl_mailpoet_lost_types( $body ) );
check( 'columns become a wrapping flex row', true, false !== strpos( $r['html'], 'display:flex;flex-wrap:wrap' ) && false !== strpos( $r['html'], 'flex:2 1 220px' ) );
check( 'h1 picks up the template color', true, false !== strpos( $r['html'], '<h1 style="color:#111111;font-size:30px">' ) );
check( 'link picks up the template color', true, false !== strpos( $r['html'], '<a href="https://example.org" style="color:#21759B;text-decoration:underline">' ) );
check( 'image gets the site URL and its link', true, false !== strpos( $r['html'], '<a href="https://example.org/img"><img src="https://club.example.org/wp-content/uploads/a.png" alt="A" width="320"' ) );
check( 'button keeps its color', true, false !== strpos( $r['html'], 'background-color:#2ea1cd' ) && false !== strpos( $r['html'], '>Donate</a>' ) );
check( 'divider keeps its style', true, false !== strpos( $r['html'], 'border-top-style:dashed' ) );
check( 'spacer height', true, false !== strpos( $r['html'], 'height:40px' ) );
check( 'coupon text kept', true, false !== strpos( $r['html'], 'SAVE10' ) );
check( 'wrapper and body backgrounds', true, 0 === strpos( $r['html'], '<div style="background-color:#eeeeee"><div style="max-width:660px' ) );
$clean = npmp_past_nl_sanitize(
	npmp_past_nl_mailpoet_tags( $r['html'], $ctx ),
	array(
		'plugin_folders' => array( 'mailpoet' ),
		'site_url'       => 'https://club.example.org',
	)
);
check( 'converted body: view-in-browser gone', false, stripos( $clean['html'], 'browser' ) !== false );
check( 'converted body: unsubscribe and manage gone, address kept', true, false === stripos( $clean['html'], 'Unsubscribe' ) && false === stripos( $clean['html'], 'Manage' ) && false !== strpos( $clean['html'], 'Rig Club, 1 Main St' ) );
check( 'converted body: name tag converted', true, false !== strpos( $clean['html'], '[first_name default="reader"]' ) );
check( 'converted body: social icon is a text link', true, false !== strpos( $clean['html'], '<a href="https://facebook.com/club">Facebook </a>' ) );
check( 'converted body: no leaks', array(), npmp_past_nl_find_leaks( $clean['html'] ) );
check( 'bad JSON gives nothing', '', npmp_past_nl_mailpoet_render( '{nope' )['html'] );


echo "Subjects, block editor tags, stored bodies\n";
check( 'subject name tag becomes its default', 'March news for friend', npmp_past_nl_plain_subject( npmp_past_nl_mailpoet_tags( 'March news for [subscriber:firstname | default:friend]', $ctx ) ) );
check( 'subject name tag with no default goes', 'Hi, March news', npmp_past_nl_plain_subject( npmp_past_nl_mailpoet_tags( 'Hi [subscriber:firstname], March news', $ctx ) ) );
check( 'subject entities decode', 'Tom & Jerry', npmp_past_nl_plain_subject( 'Tom &amp; Jerry' ) );
check( 'block editor first name', '<p>Hi [first_name default="pal"]</p>', npmp_past_nl_mailpoet_block_tags( '<p>Hi <!--[mailpoet/subscriber-firstname default="pal"]--></p>' ) );
check( 'block editor email goes', '<p>x</p>', npmp_past_nl_mailpoet_block_tags( '<p>x[mailpoet/subscriber-email]</p>' ) );
check( 'stored body as JSON', '<p>a</p>', npmp_past_nl_mailpoet_rendered_html( json_encode( array( 'html' => '<p>a</p>', 'text' => 'a' ) ) ) );
check( 'stored body serialized', '<p>b</p>', npmp_past_nl_mailpoet_rendered_html( serialize( array( 'html' => '<p>b</p>' ) ) ) );
check( 'stored body cleaned up by MailPoet', '', npmp_past_nl_mailpoet_rendered_html( null ) );
check( 'hidden preheader span goes', '<p>x</p>', npmp_past_nl_sanitize( '<span style="display:none !important">pre</span><p>x</p>' )['html'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );

/**
 * JSON for the converter without WordPress.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_for_test( $data ) {
	return (string) json_encode( $data );
}
