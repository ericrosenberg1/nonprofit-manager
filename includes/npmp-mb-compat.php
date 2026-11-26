<?php
/**
 * File path: includes/npmp-mb-compat.php
 *
 * Fallbacks for mbstring functions that WordPress does not polyfill. A host
 * can ship PHP without the mbstring extension, and WordPress then covers only
 * mb_substr() and mb_strlen() (6.0 and up) plus mb_chr() and mb_ord() (not in
 * 6.8.2, in 7.1). Every other mb_* call needs one of these helpers. With
 * mbstring loaded, each helper calls the mb_* function, so output is
 * unchanged.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_mb_upper' ) ) {
	/**
	 * Uppercase a string. Without mbstring only ASCII letters change, so an
	 * accented letter keeps its case.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function npmp_mb_upper( $text ) {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) $text, 'UTF-8' ) : strtoupper( (string) $text );
	}
}

if ( ! function_exists( 'npmp_mb_lower' ) ) {
	/**
	 * Lowercase a string. Without mbstring only ASCII letters change, so an
	 * accented letter keeps its case.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function npmp_mb_lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}
}

if ( ! function_exists( 'npmp_encode_numeric_entities' ) ) {
	/**
	 * Write every non-ASCII UTF-8 character as a numeric entity (&#233;),
	 * so DOMDocument::loadHTML() reads it correctly. Matches
	 * mb_encode_numericentity() with the map 0x80 to 0x10FFFF, including its
	 * "?" for each invalid UTF-8 byte sequence.
	 *
	 * @param string $text UTF-8 text.
	 * @return string ASCII text.
	 */
	function npmp_encode_numeric_entities( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_encode_numericentity' ) ) {
			return mb_encode_numericentity( $text, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
		}
		// A whole valid sequence first, then the longest valid start of a
		// broken one, then any single stray byte.
		$pattern = '/[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
			. '|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}'
			. '|\xE0[\xA0-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]|\xED[\x80-\x9F]|\xF0[\x90-\xBF][\x80-\xBF]?|[\xF1-\xF3][\x80-\xBF]{0,2}|\xF4[\x80-\x8F][\x80-\xBF]?'
			. '|[\x80-\xFF]/';
		return preg_replace_callback(
			$pattern,
			static function ( $m ) {
				$bytes = $m[0];
				$lead  = ord( $bytes[0] );
				$len   = strlen( $bytes );
				if ( $lead >= 0xC2 && $lead <= 0xDF && 2 === $len ) {
					$code = ( ( $lead & 0x1F ) << 6 ) | ( ord( $bytes[1] ) & 0x3F );
				} elseif ( $lead >= 0xE0 && $lead <= 0xEF && 3 === $len ) {
					$code = ( ( $lead & 0x0F ) << 12 ) | ( ( ord( $bytes[1] ) & 0x3F ) << 6 ) | ( ord( $bytes[2] ) & 0x3F );
				} elseif ( $lead >= 0xF0 && $lead <= 0xF4 && 4 === $len ) {
					$code = ( ( $lead & 0x07 ) << 18 ) | ( ( ord( $bytes[1] ) & 0x3F ) << 12 ) | ( ( ord( $bytes[2] ) & 0x3F ) << 6 ) | ( ord( $bytes[3] ) & 0x3F );
				} else {
					return '?';
				}
				return '&#' . $code . ';';
			},
			$text
		);
	}
}
