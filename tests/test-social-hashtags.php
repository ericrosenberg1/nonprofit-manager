<?php
/**
 * Event tags as hashtags in social shares (Pete at U3A asked for it).
 *
 * An event's tags (taxonomy npmp_event_tag) go out as hashtags on every
 * network that takes text, free and Pro, inside each network's limit. The
 * WordPress side runs in tests/rig/social-hashtags.php.
 *
 * Run: php tests/test-social-hashtags.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/social-sharing/hashtags.php';

echo "== One tag ==\n";
check( 'One word keeps its case', '#walking', npmp_social_hashtag( 'walking' ) );
check( 'iPhone stays iPhone', '#iPhone', npmp_social_hashtag( 'iPhone' ) );
check( 'Words become CamelCase', '#CommunityGarden', npmp_social_hashtag( 'community garden' ) );
check( 'Hyphens and punctuation go', '#LifelongLearning', npmp_social_hashtag( 'lifelong-learning!' ) );
check( 'Apostrophe joins the word', '#WomensGroup', npmp_social_hashtag( "women's group" ) );
check( 'Curly apostrophe too', '#WomensGroup', npmp_social_hashtag( "women\u{2019}s group" ) );
check( 'A leading # is not doubled', '#U3A', npmp_social_hashtag( '#U3A' ) );
check( 'Entity from WordPress decodes', '#ArtsCrafts', npmp_social_hashtag( 'Arts &amp; Crafts' ) );
check( 'Accents stay', '#CaféCulture', npmp_social_hashtag( 'café culture' ) );
check( 'Digits only: no hashtag', '', npmp_social_hashtag( '2026' ) );
check( 'Letters and digits', '#U3A2026', npmp_social_hashtag( 'U3A 2026' ) );
check( 'Punctuation only: no hashtag', '', npmp_social_hashtag( '!!' ) );

echo "== Several tags ==\n";
check( 'Order kept, repeats dropped', array( '#Walking', '#HistoryGroup' ), npmp_social_hashtags( array( 'Walking', 'history group', 'walking', 'History-Group', '2026' ) ) );
check( 'Nothing in, nothing out', array(), npmp_social_hashtags( array() ) );

echo "== Share text ==\n";
$tpl  = "{title}\n\n{excerpt}\n\n{url}";
$data = array(
	'title'    => 'Heritage walk',
	'excerpt'  => 'Meet at the station.',
	'url'      => 'https://example.org/events/walk/',
	'hashtags' => array( '#Walking', '#HistoryGroup' ),
);
check( 'Hashtags on their own line at the end', "Heritage walk\n\nMeet at the station.\n\nhttps://example.org/events/walk/\n\n#Walking #HistoryGroup", npmp_social_share_text( $tpl, $data ) );
check( '{hashtags} puts them in place', "Heritage walk #Walking #HistoryGroup\n\nhttps://example.org/events/walk/", npmp_social_share_text( "{title} {hashtags}\n\n{url}", $data ) );
check( '{hashtags} with none leaves no gap', "Heritage walk\n\nhttps://example.org/events/walk/", npmp_social_share_text( "{title}\n\n{hashtags}\n\n{url}", array_merge( $data, array( 'hashtags' => array() ) ) ) );
check( 'No hashtags: the text as before', "Heritage walk\n\nMeet at the station.\n\nhttps://example.org/events/walk/", npmp_social_share_text( $tpl, array_merge( $data, array( 'hashtags' => array() ) ) ) );

// The cut before hashtags (format_for_network until the release after 2026.11.20).
function old_format( $template, $post_data, $limit ) {
	$text = str_replace( array( '{title}', '{excerpt}', '{url}' ), array( $post_data['title'], $post_data['excerpt'], $post_data['url'] ), $template );
	if ( strlen( $text ) > $limit ) {
		$available = $limit - strlen( $post_data['url'] ) - strlen( $post_data['title'] ) - 4;
		$short     = ( $available > 10 ) ? mb_substr( $post_data['excerpt'], 0, $available - 3 ) . '...' : '';
		$text      = $post_data['title'] . "\n\n" . $short . "\n\n" . $post_data['url'];
		$text      = mb_substr( $text, 0, $limit );
	}
	return $text;
}
$long = array_merge( $data, array( 'excerpt' => str_repeat( 'Bring water and good shoes. ', 30 ), 'hashtags' => array() ) );
foreach ( array( 280, 300, 500 ) as $limit ) {
	check( "No hashtags, cut to $limit: same as before", old_format( $tpl, $long, $limit ), npmp_social_share_text( $tpl, $long, $limit ) );
}

$long['hashtags'] = array( '#Walking', '#HistoryGroup', '#U3A' );
foreach ( array( 'x_twitter' => 280, 'bluesky' => 300, 'mastodon' => 500, 'threads' => 500 ) as $net => $limit ) {
	$text = npmp_social_share_text( $tpl, $long, $limit );
	check( "$net: within $limit characters", true, mb_strlen( $text, 'UTF-8' ) <= $limit );
	check( "$net: keeps the link", true, false !== strpos( $text, $long['url'] ) );
	check( "$net: ends with every hashtag", true, '#Walking #HistoryGroup #U3A' === substr( $text, -strlen( '#Walking #HistoryGroup #U3A' ) ) );
	check( "$net: keeps the title", 0, strpos( $text, 'Heritage walk' ) );
}

$crowded = array_merge(
	$long,
	array(
		'title'    => str_repeat( 'Long title ', 20 ),
		'hashtags' => array( '#Walking', '#HistoryGroup', '#AVeryLongHashtagThatWillNotFitAnywhere' ),
	)
);
$text = npmp_social_share_text( $tpl, $crowded, 280 );
check( 'Crowded: within 280', true, mb_strlen( $text, 'UTF-8' ) <= 280 );
check( 'Crowded: link kept', true, false !== strpos( $text, $crowded['url'] ) );
check( 'Crowded: the hashtags that fit, in order', true, false !== strpos( $text, '#Walking #HistoryGroup' ) && false === strpos( $text, '#AVeryLong' ) );

$multibyte = array_merge( $long, array( 'excerpt' => str_repeat( 'Café für alle. ', 40 ) ) );
check( 'Multibyte: counted in characters', true, mb_strlen( npmp_social_share_text( $tpl, $multibyte, 300 ), 'UTF-8' ) <= 300 && mb_strlen( npmp_social_share_text( $tpl, $multibyte, 300 ), 'UTF-8' ) > 280 );

echo "== Wiring ==\n";
$root     = dirname( __DIR__ );
$manager  = file_get_contents( $root . '/includes/social-sharing/class-social-share-manager.php' );
$calendar = file_get_contents( $root . '/includes/npmp-calendar.php' );
$loader   = file_get_contents( $root . '/includes/npmp-social-sharing.php' );
$blocks   = file_get_contents( $root . '/includes/npmp-content-blocks.php' );
$admin    = file_get_contents( $root . '/includes/social-sharing/admin-social.php' );
check( 'Every network gets its text from npmp_social_share_text()', 1, substr_count( $manager, 'npmp_social_share_text( (string) $settings[\'share_template\'], $post_data, $limit )' ) );
check( 'Post data carries the hashtags', 1, substr_count( $manager, "'hashtags'  => \$this->get_hashtags( \$post_id )" ) );
check( 'Events map to npmp_event_tag', 1, substr_count( $manager, "array( 'npmp_event' => 'npmp_event_tag' )" ) );
check( 'Loader loads the helpers first', true, strpos( $loader, 'hashtags.php' ) < strpos( $loader, 'class-social-share-manager.php' ) );
check( 'Tags are flat', 1, preg_match( "/register_taxonomy\(\s*'npmp_event_tag',.*?'hierarchical'\s*=> false,/s", $calendar ) );
check( 'Tags show in the block editor', 1, preg_match( "/register_taxonomy\(\s*'npmp_event_tag',.*?'show_in_rest'\s*=> true,/s", $calendar ) );
check( 'Registered on init with the events', 1, substr_count( $calendar, "\tnpmp_register_event_tag_taxonomy();\n}" ) );
check( 'Events menu links the Tags screen', 2, substr_count( $calendar, "'edit-tags.php?taxonomy=npmp_event_tag&post_type=npmp_event'" ) );
check( 'Visitor X button carries the hashtags', 1, substr_count( $blocks, "\$href .= '&hashtags='" ) );
check( 'Settings name {hashtags}', 1, substr_count( $admin, '{hashtags}' ) > 0 ? 1 : 0 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
