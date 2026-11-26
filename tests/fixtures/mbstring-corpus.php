<?php
/**
 * Strings for tests/test-mbstring-fallbacks.php: valid UTF-8 (accents, CJK,
 * emoji, range edges), invalid UTF-8, and seeded random bytes.
 */

$valid = array(
	'', 'plain ascii', "caf\u{E9} cr\u{E8}me", "\u{4E2D}\u{6587}\u{6F22}\u{5B57}", "\u{1F600} party \u{1F389}",
	"\u{80}", "\u{7FF}", "\u{800}", "\u{FFFF}", "\u{10000}", "\u{10FFFF}", "\u{D7FF}", "\u{E000}",
	"na\u{EF}ve \u{2019}quote\u{2019} \u{2014} dash", "<p>Caf\u{E9} &amp; \u{65E5}\u{672C}</p>", "a\u{301}",
);
$invalid = array(
	"a\xFFb", "a\xC3(b", "\xE2\x82", "x\xED\xA0\x80y", "\xF4\x90\x80\x80", "\xC0\x80", "\xC1\xBF", "\x80", "\xBF\x80",
	"\xE0\x80\x80", "\xE0\x9F\xBF", "\xF0\x80\x80\x80", "\xF0\x8F\xBF\xBF", "\xF5\x80\x80\x80", "\xFE\xFF", "\xF0\x9F\x98", "\xF0\x9F", "\xF0",
	"ok\xE4\xB8", "\xE4\xB8\xADx\xE4", "\xED\x9F\xBF\xED\xA0\x80",
);
mt_srand( 20261126 );
$random = array();
for ( $i = 0; $i < 3000; $i++ ) {
	$s = '';
	$n = mt_rand( 1, 12 );
	for ( $j = 0; $j < $n; $j++ ) {
		$s .= chr( mt_rand( 0, 3 ) ? mt_rand( 0x80, 0xFF ) : mt_rand( 0x20, 0x7E ) );
	}
	$random[] = $s;
}

return array(
	'bytes' => array_merge( $valid, $invalid, $random ),
	'tags'  => array( 'community garden', 'Walking', 'history group', 'walking', 'caf' . "\u{E9}" . ' culture', "\u{E9}a b", 'u3a 2026', "women's group", '#U3A', "\u{4E2D}\u{6587} \u{6F22}\u{5B57}", 'Arts &amp; Crafts' ),
);
