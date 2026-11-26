<?php
/**
 * Fallbacks for mbstring functions that WordPress does not polyfill.
 *
 * A host can run PHP with no mbstring extension. This test runs the fixed
 * paths twice, once here with mbstring and once in a child PHP process with
 * the mb functions switched off (disable_functions), and compares them.
 *
 * Run: php tests/test-mbstring-fallbacks.php
 */

$mb_off = 'iconv,mb_strtoupper,mb_strtolower,mb_encode_numericentity,mb_strpos,mb_ord,mb_chr,mb_convert_encoding,mb_substr,mb_strlen';

if ( false !== getenv( 'NPMP_MB_CHILD' ) ) {
	// Child: print what the fixed paths return as JSON.
	require_once __DIR__ . '/bootstrap.php';
	if ( ! function_exists( 'mb_substr' ) ) {
		// WordPress supplies these two, so the child gets the same.
		function mb_substr( $s, $start, $len = null, $enc = null ) {
			return implode( '', array_slice( preg_split( '//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY ), $start, $len ) );
		}
		function mb_strlen( $s, $enc = null ) {
			return count( preg_split( '//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY ) );
		}
	}
	require_once dirname( __DIR__ ) . '/includes/social-sharing/hashtags.php';
	require_once dirname( __DIR__ ) . '/includes/import/gift-csv-parse.php';
	$corpus = require __DIR__ . '/fixtures/mbstring-corpus.php';
	$cp1252 = array();
	for ( $i = 0x80; $i <= 0xFF; $i++ ) {
		$cp1252[] = bin2hex( npmp_gift_csv_utf8( 'a' . chr( $i ) . 'b' ) );
	}
	echo json_encode(
		array(
			'has_mb'   => array_map( 'function_exists', array( 'mb_strtoupper', 'mb_strtolower', 'mb_encode_numericentity' ) ),
			'entities' => array_map( 'bin2hex', array_map( 'npmp_encode_numeric_entities', $corpus['bytes'] ) ),
			'hashtag'  => array_map( 'npmp_social_hashtag', $corpus['tags'] ),
			'hashtags' => npmp_social_hashtags( $corpus['tags'] ),
			'cp1252'   => $cp1252,
		)
	);
	exit( 0 );
}

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/social-sharing/hashtags.php';
$corpus = require __DIR__ . '/fixtures/mbstring-corpus.php';

/**
 * Run this file as a child PHP process and decode what it prints.
 *
 * @param string $disable disable_functions value, or ''.
 * @return array|null
 */
function npmp_mb_child( $disable ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ( '' !== $disable ? ' -d disable_functions=' . escapeshellarg( $disable ) : '' ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1';
	$out = shell_exec( 'NPMP_MB_CHILD=1 ' . $cmd );
	$dec = json_decode( (string) $out, true );
	if ( ! is_array( $dec ) ) {
		echo "  child output: $out\n";
		return null;
	}
	return $dec;
}

echo "== With mbstring ==\n";
$mb_has = extension_loaded( 'mbstring' );
check( 'mbstring is loaded here', true, $mb_has );
$on = npmp_mb_child( '' );
check( 'Child with mbstring runs', true, is_array( $on ) );
check( 'mb functions exist with mbstring on', array( true, true, true ), $on['has_mb'] ?? null );

echo "== Entity encoder against mb_encode_numericentity ==\n";
$map = array( 0x80, 0x10FFFF, 0, 0x1FFFFF );
$bad = 0;
foreach ( $corpus['bytes'] as $i => $bytes ) {
	if ( mb_encode_numericentity( $bytes, $map, 'UTF-8' ) !== npmp_encode_numeric_entities( $bytes ) ) {
		$bad++;
	}
}
check( 'mbstring path matches mb_encode_numericentity on ' . count( $corpus['bytes'] ) . ' strings', 0, $bad );

// The preg path runs when mbstring is absent. Run it here too, by pattern, through the child below.
echo "== Child with the mb functions disabled ==\n";
$off = npmp_mb_child( $mb_off );
check( 'Child without mbstring runs', true, is_array( $off ) );
check( 'function_exists reads false for the three mb functions', array( false, false, false ), $off['has_mb'] ?? null );
$bad = 0;
foreach ( $corpus['bytes'] as $i => $bytes ) {
	$want = bin2hex( mb_encode_numericentity( $bytes, $map, 'UTF-8' ) );
	if ( ( $off['entities'][ $i ] ?? null ) !== $want ) {
		$bad++;
		echo '  differs: ' . bin2hex( $bytes ) . " want $want got " . ( $off['entities'][ $i ] ?? 'none' ) . "\n";
	}
}
check( 'Entities without mbstring are byte-identical on every corpus string', 0, $bad );
// Without mbstring the case folding is ASCII-only. Every tag that starts a word with a non-ASCII letter may differ, and only in that letter.
$diff = array();
foreach ( $corpus['tags'] as $i => $tag ) {
	if ( $on['hashtag'][ $i ] !== ( $off['hashtag'][ $i ] ?? null ) ) {
		$diff[] = $tag;
	}
}
check( 'Only the non-ASCII word-start tag differs without mbstring', array( "\u{E9}a b" ), $diff );
check( 'With mbstring, an accented word start is uppercased', '#ÉaB', $on['hashtag'][5] ?? null );
check( 'Without mbstring, it keeps its case (ASCII-only folding)', '#éaB', $off['hashtag'][5] ?? null );

echo "== Windows-1252 gift CSV with no mbstring and no iconv ==\n";
$want = array();
for ( $i = 0x80; $i <= 0xFF; $i++ ) {
	$want[] = bin2hex( 'a' . mb_convert_encoding( chr( $i ), 'UTF-8', 'Windows-1252' ) . 'b' );
}
check( 'All 128 high bytes convert like mb_convert_encoding', $want, $off['cp1252'] ?? null );

echo "== Hashtags ==\n";
check( 'Words become CamelCase without mbstring', '#CommunityGarden', npmp_social_hashtag( 'community garden' ) );
check( 'Case-insensitive repeats dropped (ASCII)', array( '#Walking' ), npmp_social_hashtags( array( 'Walking', 'walking' ) ) );
check( 'Hashtag list without mbstring equals the list with it, bar that tag', array_values( array_diff( $on['hashtags'], array( '#ÉaB' ) ) ), array_values( array_diff( $off['hashtags'] ?? array(), array( '#éaB' ) ) ) );

echo "== Newsletter call site ==\n";
$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/class-newsletter-manager.php' );
check( 'track_links() calls the helper', true, false !== strpos( $src, 'loadHTML( npmp_encode_numeric_entities( $content ) )' ) );
check( 'No bare mb_encode_numericentity() call left', 0, preg_match( '/^[^\/*\n]*\bmb_encode_numericentity\(/m', $src ) );

echo "\nPassed: $pass, Failed: $fail\n";
exit( $fail ? 1 : 0 );
