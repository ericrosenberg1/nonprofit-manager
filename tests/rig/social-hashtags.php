<?php
/**
 * Real-WordPress check of event tags and hashtags in social shares
 * (includes/npmp-calendar.php, includes/social-sharing/hashtags.php).
 *
 * Events get a flat Event Tags taxonomy in the editor and the REST API. When
 * we share a tagged event, every connected network's request carries the
 * tags as hashtags, inside the network's limit, and a post without tags
 * shares as before. The visitor share bar's X button on an event page
 * carries them too (over HTTP).
 *
 * Every network API is faked with pre_http_request, and we never call a
 * live one. Run it twice, with Pro on (Bluesky, Mastodon, Threads, Nextdoor,
 * and Reddit too) and off:
 *   wp eval-file tests/rig/social-hashtags.php
 * The site must answer at its home URL (php -S works) for the HTTP check.
 * Needs the calendar module on. It turns social sharing on for the run and
 * puts every setting and post back on exit.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_register_event_tag_taxonomy' ) || ! class_exists( 'NPMP_Social_Share_Manager' ) ) {
	echo "  FAIL  calendar and social modules must be on (npmp_enabled_features)\n";
	exit( 1 );
}
$pro = function_exists( 'npmp_pro_register_social_networks' );
echo $pro ? "Pro is active\n" : "Pro is inactive\n";

$rig_made = array();
$rig_keep = array();
foreach ( array( 'npmp_social_accounts', 'npmp_social_settings' ) as $opt ) {
	$rig_keep[ $opt ] = get_option( $opt, null );
}
register_shutdown_function(
	static function () use ( &$rig_made, $rig_keep ) {
		foreach ( $rig_made as $rig_id ) {
			wp_delete_post( $rig_id, true );
		}
		foreach ( get_terms( array( 'taxonomy' => 'npmp_event_tag', 'hide_empty' => false, 'fields' => 'ids' ) ) as $rig_term ) {
			wp_delete_term( $rig_term, 'npmp_event_tag' );
		}
		foreach ( $rig_keep as $opt => $val ) {
			null === $val ? delete_option( $opt ) : update_option( $opt, $val );
		}
		delete_transient( 'npmp_nextdoor_pending' );
	}
);

echo "Taxonomy\n";
$tax = get_taxonomy( 'npmp_event_tag' );
$ok( 'Event tags exist', (bool) $tax );
$ok( 'Flat, like post tags', $tax && ! $tax->hierarchical );
$ok( 'In the block editor (REST)', $tax && $tax->show_in_rest );
$ok( 'On events only', $tax && array( 'npmp_event' ) === $tax->object_type );
$ok( 'No public archive', $tax && ! $tax->public && false === $tax->rewrite );
$ok( 'Column on All Events', $tax && $tax->show_admin_column );
$routes = rest_get_server()->get_routes();
$ok( 'REST route for the tags', isset( $routes[ '/wp/v2/' . ( $tax->rest_base ? $tax->rest_base : 'npmp_event_tag' ) ] ) );

// Every network this site knows, connected with fake credentials.
$creds = array(
	'facebook_page' => array( 'page_access_token' => 'rig' ),
	'x_twitter'     => array( 'api_key' => 'k', 'api_secret' => 's', 'access_token' => 't', 'access_token_secret' => 'ts' ),
	'bluesky'       => array( 'handle' => 'rig.bsky.social', 'app_password' => 'p' ),
	'mastodon'      => array( 'instance_url' => 'https://mastodon.social', 'access_token' => 't' ),
	'threads'       => array( 'access_token' => 't' ),
	'nextdoor'      => array( 'enabled' => '1' ),
	'reddit'        => array( 'client_id' => 'c', 'client_secret' => 's', 'username' => 'u', 'password' => 'p', 'subreddit' => 'test' ),
);
$networks = NPMP_Social_Share_Manager::get_instance()->get_registered_networks();
update_option( 'npmp_social_accounts', array_intersect_key( $creds, $networks ) );
update_option( 'npmp_social_settings', array( 'auto_share' => false, 'share_template' => "{title}\n\n{excerpt}\n\n{url}" ) );

$GLOBALS['rig_sent'] = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$GLOBALS['rig_sent'][] = array(
			'url'  => $url,
			'body' => is_array( $args['body'] ?? null ) ? $args['body'] : (string) ( $args['body'] ?? '' ),
		);
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'accessJwt' => 'j', 'did' => 'did:plc:rig', 'id' => '1', 'access_token' => 'a', 'json' => array( 'errors' => array(), 'data' => array( 'url' => 'https://x' ) ), 'uri' => 'at://x', 'cid' => 'c', 'data' => array( 'id' => '1' ) ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);

// The text each network sent, from its last request to its API host.
$hosts = array(
	'facebook_page' => 'graph.facebook.com',
	'x_twitter'     => 'api.x.com',
	'bluesky'       => 'createRecord',
	'mastodon'      => 'mastodon.social',
	'threads'       => 'graph.threads.net/v1.0/me/threads?',
	'reddit'        => 'oauth.reddit.com',
);
$sent_text = static function ( $net ) use ( $hosts ) {
	if ( 'nextdoor' === $net ) {
		$pending = get_transient( 'npmp_nextdoor_pending' );
		return is_array( $pending ) ? (string) $pending['text'] : '';
	}
	foreach ( array_reverse( $GLOBALS['rig_sent'] ) as $req ) {
		if ( false === strpos( $req['url'], $hosts[ $net ] ) && ! ( 'threads' === $net && false !== strpos( $req['url'], 'graph.threads.net' ) && is_array( $req['body'] ) && isset( $req['body']['text'] ) ) ) {
			continue;
		}
		$body = $req['body'];
		if ( is_string( $body ) ) {
			$json = json_decode( $body, true );
			if ( is_array( $json ) ) {
				return (string) ( $json['text'] ?? $json['status'] ?? ( $json['record']['text'] ?? '' ) );
			}
			parse_str( $body, $body );
		}
		return (string) ( $body['message'] ?? $body['status'] ?? $body['text'] ?? $body['title'] ?? '' );
	}
	return '';
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

$event = (int) wp_insert_post(
	array(
		'post_type'    => 'npmp_event',
		'post_status'  => 'publish',
		'post_title'   => 'Heritage walk',
		'post_excerpt' => str_repeat( 'Bring water and good shoes. ', 20 ),
		'post_content' => '[npmp_social_share networks="x,facebook"]',
	)
);
$rig_made[] = $event;
wp_set_object_terms( $event, array( 'walking', 'history group', 'Walking', '2026' ), 'npmp_event_tag' );

echo "Share a tagged event\n";
$results = NPMP_Social_Share_Manager::get_instance()->share_post( $event );
$limits  = apply_filters( 'npmp_social_char_limits', array( 'x_twitter' => 280, 'bluesky' => 300, 'mastodon' => 500 ) );
foreach ( array_keys( get_option( 'npmp_social_accounts' ) ) as $net ) {
	$text = $sent_text( $net );
	if ( 'reddit' === $net ) {
		$ok( 'reddit: title only, as before', 'Heritage walk' === $text );
		continue;
	}
	$ok( "$net: shared", true === ( $results[ $net ] ?? null ) );
	$ok( "$net: carries #walking and #HistoryGroup once each", 1 === substr_count( strtolower( $text ), '#walking' ) && 1 === substr_count( $text, '#HistoryGroup' ) );
	$ok( "$net: no hashtag from digits alone", false === strpos( $text, '#2026' ) );
	$ok( "$net: keeps the link", false !== strpos( $text, get_permalink( $event ) ) );
	if ( isset( $limits[ $net ] ) ) {
		$ok( "$net: within {$limits[ $net ]} characters", mb_strlen( $text, 'UTF-8' ) <= (int) $limits[ $net ] );
	}
}

echo "A post without tags shares as before\n";
$post = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Rig post', 'post_content' => 'Hello there.' ) );
$rig_made[]           = $post;
$GLOBALS['rig_sent'] = array();
NPMP_Social_Share_Manager::get_instance()->share_post( $post );
$ok( 'x_twitter: no hashtags', isset( $creds['x_twitter'] ) && "Rig post\n\nHello there.\n\n" . get_permalink( $post ) === $sent_text( 'x_twitter' ) );

echo "Visitor share bar on the event page (HTTP)\n";
// The fake above answers every request, so take it off to reach the site.
remove_all_filters( 'pre_http_request' );
$res  = wp_remote_get( get_permalink( $event ), array( 'timeout' => 30 ) );
$html = (string) wp_remote_retrieve_body( $res );
$ok( 'Event page answers 200', 200 === (int) wp_remote_retrieve_response_code( $res ) );
$ok( 'X button carries the hashtags', (bool) preg_match( '#twitter\.com/intent/tweet\?[^"]*&(amp;|\#038;)?hashtags=(HistoryGroup%2Cwalking|walking%2CHistoryGroup)"#', $html ) );
$ok( 'Facebook button unchanged', (bool) preg_match( '#href="https://www.facebook.com/sharer/sharer.php\?u=[^"&]+"#', $html ) );

printf( "\n%s\n", $fails ? "$fails FAILED" : 'ALL PASSED' );
exit( $fails ? 1 : 0 );
