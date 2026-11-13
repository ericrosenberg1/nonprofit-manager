<?php
/**
 * File path: includes/import/past-newsletters-pure.php
 *
 * Past newsletters from MailPoet and The Newsletter Plugin, the parts that
 * need no database: turning MailPoet's saved block structure into HTML,
 * converting each plugin's personal tags into ours, and the scrub that keeps
 * every trace of a reader, a tracker, or the old plugin's endpoints out of
 * the body. The WordPress side is past-newsletters.php.
 *
 * What the scrub does to an old body (npmp_past_nl_sanitize()):
 *   - drops script, style, form, frame, embed, object, and every other
 *     element outside a fixed list, with their content, and unwraps tags it
 *     doesn't know,
 *   - keeps only presentational attributes, and drops any style with
 *     expression(), url(javascript:), or behavior,
 *   - turns a tracked link back into the link it wraps, from MailPoet's
 *     newsletter_links rows or the target inside The Newsletter Plugin's
 *     tracking URL, and unwraps a tracked link it can't resolve,
 *   - takes out unsubscribe, manage-subscription, view-in-browser, and
 *     confirm links with their text, since they point at the old plugin and
 *     may carry a reader's token,
 *   - takes out open-tracking pixels and the "Sent with MailPoet" badge,
 *   - swaps an image served from the old plugin's own folder (social icons)
 *     for its alt text, since the image goes when the plugin does,
 *   - takes out every HTML comment, including Outlook's conditional ones.
 *
 * Unit tested by tests/test-past-newsletters.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Tags
// =====================================================================

/**
 * Escape text for HTML without WordPress.
 *
 * @param string $text Text.
 * @return string
 */
function npmp_past_nl_esc( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * A default value for one of our name tags, quoted.
 *
 * @param string $value Default text.
 * @return string Like ` default="reader"`, or ''.
 */
function npmp_past_nl_tag_default( $value ) {
	$value = trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\"'" );
	$value = trim( str_replace( array( '"', '[', ']' ), '', $value ) );
	return '' === $value ? '' : ' default="' . npmp_past_nl_esc( $value ) . '"';
}

/**
 * Date parts MailPoet's [date:...] tags print, for one send date.
 *
 * @param int $timestamp Unix time of the send.
 * @return array<string,string>
 */
function npmp_past_nl_date_values( $timestamp ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return array();
	}
	return array(
		'd'        => gmdate( 'j', $timestamp ),
		'dordinal' => gmdate( 'jS', $timestamp ),
		'dtext'    => gmdate( 'l', $timestamp ),
		'm'        => gmdate( 'n', $timestamp ),
		'mtext'    => gmdate( 'F', $timestamp ),
		'y'        => gmdate( 'Y', $timestamp ),
		'date'     => gmdate( 'F j, Y', $timestamp ),
	);
}

/**
 * Turn MailPoet's tags into ours, or drop them.
 *
 * Both of MailPoet's spellings: [subscriber:firstname | default:reader] and
 * [subscriber:firstname default="reader"].
 *
 * - First name, last name, and display name become [first_name],
 *   [last_name], and [name], with the same default.
 * - [newsletter:subject] becomes the subject, [site:title] the site name,
 *   [site:homepage_url] the home page, [date:...] the send date.
 * - Everything else (email, subscriber count, custom fields, post titles,
 *   links outside an href) goes.
 *
 * A [link:...] inside an href stays for the scrub, which removes the link.
 *
 * @param string $html Body or subject.
 * @param array  $ctx  site_name, home_url, subject, date_values.
 * @return string
 */
function npmp_past_nl_mailpoet_tags( $html, array $ctx = array() ) {
	$html = (string) $html;
	if ( false === strpos( $html, '[' ) ) {
		return $html;
	}
	$dates = isset( $ctx['date_values'] ) && is_array( $ctx['date_values'] ) ? $ctx['date_values'] : array();

	return (string) preg_replace_callback(
		'/(\bhref\s*=\s*["\']\s*)?\[(subscriber|newsletter|site|date|link|subscription|mailpoet_\w+):([a-z0-9_]+)([^\]]*)\]/i',
		static function ( $m ) use ( $ctx, $dates ) {
			if ( '' !== $m[1] ) {
				return $m[0];
			}
			$cat    = strtolower( $m[2] );
			$action = strtolower( $m[3] );
			$rest   = (string) $m[4];
			$def    = '';
			if ( preg_match( '/\|\s*default\s*:\s*(.*)$/is', $rest, $d ) || preg_match( '/\bdefault\s*=\s*(["\']?)(.*?)\1\s*$/is', $rest, $d2 ) ) {
				$def = isset( $d2 ) && isset( $d2[2] ) ? $d2[2] : $d[1];
			}
			if ( 'subscriber' === $cat ) {
				$map = array(
					'firstname'   => 'first_name',
					'lastname'    => 'last_name',
					'displayname' => 'name',
				);
				return isset( $map[ $action ] ) ? '[' . $map[ $action ] . npmp_past_nl_tag_default( $def ) . ']' : '';
			}
			if ( 'newsletter' === $cat ) {
				return 'subject' === $action ? npmp_past_nl_esc( (string) ( $ctx['subject'] ?? '' ) ) : '';
			}
			if ( 'site' === $cat ) {
				$name = (string) ( $ctx['site_name'] ?? '' );
				$home = (string) ( $ctx['home_url'] ?? '' );
				if ( 'title' === $action ) {
					return npmp_past_nl_esc( $name );
				}
				if ( 'homepage_url' === $action ) {
					return npmp_past_nl_esc( $home );
				}
				if ( 'homepage_link' === $action && '' !== $home ) {
					return '<a href="' . npmp_past_nl_esc( $home ) . '">' . npmp_past_nl_esc( '' !== $name ? $name : $home ) . '</a>';
				}
				return '';
			}
			if ( 'date' === $cat ) {
				return isset( $dates[ $action ] ) ? npmp_past_nl_esc( $dates[ $action ] ) : '';
			}
			return '';
		},
		$html
	);
}

/**
 * The Newsletter Plugin's per-reader and link tags (TNP_Module::replace()).
 *
 * @return array{name:string[],last:string[],full:string[],drop:string[],url:string[]}
 */
function npmp_past_nl_tnp_tag_sets() {
	return array(
		'name' => array( 'name', 'first_name' ),
		'last' => array( 'surname', 'last_name' ),
		'full' => array( 'full_name' ),
		'drop' => array( 'email', 'token', 'id', 'ip', 'key', 'title', 'email_id', 'email_key', 'unlock_url' ),
		'url'  => array( 'unsubscription_url', 'unsubscription_confirm_url', 'profile_url', 'email_url', 'subscription_confirm_url', 'activation_url', 'unlock_url' ),
	);
}

/**
 * Turn The Newsletter Plugin's {tags} into ours, or drop them.
 *
 * {name} and {first_name} become [first_name] (with the plugin's "no name"
 * text as the default), {surname} and {last_name} [last_name], {full_name}
 * [name]. {blog_title}, {blog_url}, {home_url}, {blog_description}, the
 * {company_...} tags, {email_subject}, and {date...} get their values.
 * Every other tag goes. A link tag inside an href stays for the scrub.
 *
 * @param string $html Body or subject.
 * @param array  $ctx  site_name, home_url, site_description, subject,
 *                     timestamp, name_default, company (name, address, legal).
 * @return string
 */
function npmp_past_nl_tnp_tags( $html, array $ctx = array() ) {
	$html = (string) $html;
	if ( false === strpos( $html, '{' ) && false === stripos( $html, '%7B' ) ) {
		return $html;
	}
	$sets    = npmp_past_nl_tnp_tag_sets();
	$default = npmp_past_nl_tag_default( (string) ( $ctx['name_default'] ?? '' ) );
	$company = isset( $ctx['company'] ) && is_array( $ctx['company'] ) ? $ctx['company'] : array();
	$ts      = (int) ( $ctx['timestamp'] ?? 0 );

	return (string) preg_replace_callback(
		'/(\b(?:href|src)\s*=\s*["\'][^"\']*?)?(?:\{|%7B)([a-z0-9_]+)(?:\}|%7D)/i',
		static function ( $m ) use ( $sets, $default, $company, $ctx, $ts ) {
			$tag = strtolower( $m[2] );
			if ( '' !== $m[1] ) {
				// Inside a link: the scrub removes the link for a link tag, and a
				// value tag still gets its value.
				if ( in_array( $tag, $sets['url'], true ) || in_array( $tag, $sets['drop'], true ) ) {
					return $m[0];
				}
			}
			$prefix = (string) $m[1];
			if ( in_array( $tag, $sets['name'], true ) ) {
				return $prefix . '[first_name' . $default . ']';
			}
			if ( in_array( $tag, $sets['last'], true ) ) {
				return $prefix . '[last_name]';
			}
			if ( in_array( $tag, $sets['full'], true ) ) {
				return $prefix . '[name' . $default . ']';
			}
			if ( 'blog_title' === $tag ) {
				return $prefix . npmp_past_nl_esc( (string) ( $ctx['site_name'] ?? '' ) );
			}
			if ( 'blog_url' === $tag || 'home_url' === $tag ) {
				return $prefix . npmp_past_nl_esc( (string) ( $ctx['home_url'] ?? '' ) );
			}
			if ( 'blog_description' === $tag ) {
				return $prefix . npmp_past_nl_esc( (string) ( $ctx['site_description'] ?? '' ) );
			}
			if ( 'email_subject' === $tag || 'subject' === $tag ) {
				return $prefix . npmp_past_nl_esc( (string) ( $ctx['subject'] ?? '' ) );
			}
			if ( 0 === strpos( $tag, 'company_' ) ) {
				return $prefix . npmp_past_nl_esc( (string) ( $company[ substr( $tag, 8 ) ] ?? '' ) );
			}
			if ( 'date' === $tag && $ts > 0 ) {
				return $prefix . npmp_past_nl_esc( gmdate( 'F j, Y', $ts ) );
			}
			if ( in_array( $tag, $sets['url'], true ) ) {
				return $prefix . '#';
			}
			if ( in_array( $tag, $sets['drop'], true ) || 0 === strpos( $tag, 'profile_' ) ) {
				return $prefix;
			}
			// A word in braces we don't know, like {this} in a sentence, stays.
			return $m[0];
		},
		(string) preg_replace_callback(
			// {date_FORMAT}: a PHP date format after the underscore.
			'/\{date_([^}]{1,20})\}/',
			static function ( $m ) use ( $ts ) {
				return $ts > 0 ? npmp_past_nl_esc( gmdate( $m[1], $ts ) ) : '';
			},
			$html
		)
	);
}

/**
 * Old plugins' own shortcodes ([newsletter_...], [mailpoet_...]), which
 * print nothing once the plugin is gone.
 *
 * @param string $html Body.
 * @return string
 */
function npmp_past_nl_drop_plugin_shortcodes( $html ) {
	// The tracking tags ([mailpoet_click_data]-HASH, [mailpoet_open_data]) stay for the scrub.
	return (string) preg_replace( '/\[\/?(?!mailpoet_(?:click|open)_data\])(?:newsletter|tnp|mailpoet)(?:_[a-z0-9_]+)?(?:\s[^\]]*)?\]/i', '', (string) $html );
}

// =====================================================================
// Links
// =====================================================================

/**
 * Query arguments of a URL, decoded.
 *
 * @param string $url URL (may hold &amp;).
 * @return array<string,string>
 */
function npmp_past_nl_query( $url ) {
	$url   = str_replace( '&amp;', '&', (string) $url );
	$query = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, no WordPress.
	$out   = array();
	if ( '' === $query ) {
		return $out;
	}
	foreach ( explode( '&', $query ) as $pair ) {
		if ( '' === $pair ) {
			continue;
		}
		$parts                                       = explode( '=', $pair, 2 );
		$out[ strtolower( rawurldecode( $parts[0] ) ) ] = isset( $parts[1] ) ? rawurldecode( str_replace( '+', ' ', $parts[1] ) ) : '';
	}
	return $out;
}

/**
 * A URL that leads somewhere a reader can open.
 *
 * @param string $url URL.
 * @return bool
 */
function npmp_past_nl_is_web_url( $url ) {
	return (bool) preg_match( '#^(https?:)?//[^/\s]+#i', trim( (string) $url ) );
}

/**
 * What to do with one link (href) from an old newsletter.
 *
 * @param string $url URL as it sits in the attribute.
 * @param array  $ctx link_map (MailPoet hash => URL).
 * @return array{action:string,url:string} action keep, replace, unwrap, or remove.
 */
function npmp_past_nl_classify_link( $url, array $ctx = array() ) {
	$raw   = trim( str_replace( '&amp;', '&', (string) $url ) );
	$map   = isset( $ctx['link_map'] ) && is_array( $ctx['link_map'] ) ? $ctx['link_map'] : array();
	$depth = (int) ( $ctx['_depth'] ?? 0 );
	$out   = static function ( $action, $u = '' ) {
		return array(
			'action' => $action,
			'url'    => (string) $u,
		);
	};

	if ( '' === $raw || '#' === $raw[0] ) {
		return $out( 'keep', $raw );
	}
	if ( preg_match( '/^\s*(javascript|vbscript|data|file):/i', $raw ) ) {
		return $out( 'unwrap' );
	}

	// A link MailPoet hashed for click tracking: [mailpoet_click_data]-HASH.
	if ( preg_match( '/^\[mailpoet_click_data\]-(\w+)$/i', $raw, $m ) ) {
		$target = isset( $map[ $m[1] ] ) ? (string) $map[ $m[1] ] : '';
		if ( '' === $target ) {
			return $out( 'unwrap' );
		}
		if ( $depth > 2 ) {
			return $out( 'unwrap' );
		}
		$inner = npmp_past_nl_classify_link( $target, array_merge( $ctx, array( '_depth' => $depth + 1 ) ) );
		return 'keep' === $inner['action'] ? $out( 'replace', $target ) : $inner;
	}
	if ( 0 === stripos( $raw, '[mailpoet_' ) ) {
		return $out( 'unwrap' );
	}

	// MailPoet's link tags: unsubscribe, manage, view in browser, re-engage.
	if ( preg_match( '/^\[link:/i', $raw ) || preg_match( '/^\[subscription:/i', $raw ) ) {
		return $out( 'remove' );
	}
	// The Newsletter Plugin's link tags, left in by the tag pass.
	if ( preg_match( '/(\{|%7B)(unsubscription_url|unsubscription_confirm_url|profile_url|email_url|subscription_confirm_url|activation_url|unlock_url)(\}|%7D)/i', $raw ) ) {
		return $out( 'remove' );
	}
	if ( preg_match( '/(\{|%7B)(email|token|id|key|ip|email_key)(\}|%7D)/i', $raw ) ) {
		return $out( 'unwrap' );
	}

	$q = npmp_past_nl_query( $raw );

	// MailPoet's router: ?mailpoet_router&endpoint=track|subscription|view_in_browser&data=...
	if ( array_key_exists( 'mailpoet_router', $q ) || ( isset( $q['endpoint'] ) && preg_match( '/mailpoet/i', $raw ) ) ) {
		$endpoint = strtolower( (string) ( $q['endpoint'] ?? '' ) );
		if ( 'track' === $endpoint && 'click' === strtolower( (string) ( $q['action'] ?? '' ) ) && ! empty( $q['data'] ) ) {
			$hash = npmp_past_nl_mailpoet_router_hash( $q['data'] );
			if ( '' !== $hash && isset( $map[ $hash ] ) && $depth <= 2 ) {
				$inner = npmp_past_nl_classify_link( (string) $map[ $hash ], array_merge( $ctx, array( '_depth' => $depth + 1 ) ) );
				return 'keep' === $inner['action'] ? $out( 'replace', (string) $map[ $hash ] ) : $inner;
			}
			return $out( 'unwrap' );
		}
		return $out( 'remove' );
	}

	// The Newsletter Plugin's click tracking: ?nltr=base64(email;user;url;anchor;signature).
	if ( isset( $q['nltr'] ) ) {
		$target = npmp_past_nl_tnp_nltr_target( $q['nltr'] );
		if ( '' !== $target && $depth <= 2 ) {
			$inner = npmp_past_nl_classify_link( $target, array_merge( $ctx, array( '_depth' => $depth + 1 ) ) );
			return 'keep' === $inner['action'] ? $out( 'replace', $target ) : $inner;
		}
		return $out( 'unwrap' );
	}
	// Its REST tracking: /wp-json/tnp/l/<email>/<user>/<base64url(url)>/<signature>.
	$route = isset( $q['rest_route'] ) ? (string) $q['rest_route'] : (string) parse_url( $raw, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.
	if ( preg_match( '#/tnp/l/[^/]+/[^/]+/([A-Za-z0-9_-]+)(?:/|$)#', $route, $m ) ) {
		$target = (string) base64_decode( strtr( $m[1], '-_', '+/' ), true );
		if ( npmp_past_nl_is_web_url( $target ) && $depth <= 2 ) {
			return $out( 'replace', $target );
		}
		return $out( 'unwrap' );
	}
	// Its action links: ?na=u (unsubscribe), p (profile), v (view online), c (confirm), and the rest.
	if ( isset( $q['na'] ) || isset( $q['nk'] ) || isset( $q['nek'] ) ) {
		return $out( 'remove' );
	}

	return $out( 'keep', $raw );
}

/**
 * The link hash in a MailPoet tracking URL's data argument.
 *
 * MailPoet stores [subscriber_id, token, queue_id, link_hash, preview] as
 * base64 JSON (Links::createUrlDataObject()).
 *
 * @param string $data data argument.
 * @return string
 */
function npmp_past_nl_mailpoet_router_hash( $data ) {
	$json = base64_decode( strtr( rawurldecode( (string) $data ), '-_', '+/' ), true );
	$arr  = is_string( $json ) ? json_decode( $json, true ) : null;
	if ( ! is_array( $arr ) ) {
		return '';
	}
	$hash = $arr[3] ?? ( $arr['link_hash'] ?? '' );
	return is_scalar( $hash ) && preg_match( '/^\w+$/', (string) $hash ) ? (string) $hash : '';
}

/**
 * The real link inside The Newsletter Plugin's nltr argument.
 *
 * @param string $value nltr value.
 * @return string '' when it isn't a web link.
 */
function npmp_past_nl_tnp_nltr_target( $value ) {
	$decoded = base64_decode( rawurldecode( (string) $value ), true );
	if ( ! is_string( $decoded ) ) {
		return '';
	}
	$parts = explode( ';', $decoded );
	if ( count( $parts ) < 4 ) {
		return '';
	}
	// email;user;url;anchor;signature, and the URL may itself hold a ";".
	$tail   = count( $parts ) >= 5 ? 2 : 1;
	$target = implode( ';', array_slice( $parts, 2, count( $parts ) - 2 - $tail ) );
	return npmp_past_nl_is_web_url( $target ) ? $target : '';
}

/**
 * Whether an image is an open-tracking pixel.
 *
 * @param string $src    Image src.
 * @param string $width  width attribute.
 * @param string $height height attribute.
 * @return bool
 */
function npmp_past_nl_is_pixel( $src, $width = '', $height = '' ) {
	$src = str_replace( '&amp;', '&', trim( (string) $src ) );
	if ( '' === $src || 0 === stripos( $src, '[mailpoet_open' ) ) {
		return true;
	}
	$q = npmp_past_nl_query( $src );
	if ( isset( $q['noti'] ) || ( array_key_exists( 'mailpoet_router', $q ) && 'track' === strtolower( (string) ( $q['endpoint'] ?? '' ) ) ) ) {
		return true;
	}
	if ( preg_match( '#/tnp/o/#', $src ) || ( isset( $q['rest_route'] ) && false !== strpos( (string) $q['rest_route'], '/tnp/o/' ) ) ) {
		return true;
	}
	return '1' === trim( (string) $width ) && '1' === trim( (string) $height ) && ! preg_match( '#\.(png|jpe?g|gif|webp)(\?|$)#i', (string) parse_url( $src, PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.
}

/**
 * Whether a URL points inside one of the old plugin's own folders, which go
 * when the plugin is deleted.
 *
 * @param string   $url     URL.
 * @param string[] $folders Plugin folder names, such as mailpoet.
 * @return bool
 */
function npmp_past_nl_in_plugin_folder( $url, array $folders ) {
	$path = (string) parse_url( str_replace( '&amp;', '&', (string) $url ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.
	foreach ( $folders as $folder ) {
		if ( '' !== $folder && preg_match( '#/wp-content/plugins/' . preg_quote( $folder, '#' ) . '/#i', $path ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a link is MailPoet's own "Sent with MailPoet" badge link.
 *
 * @param string $url URL.
 * @return bool
 */
function npmp_past_nl_is_badge_link( $url ) {
	$url  = str_replace( '&amp;', '&', (string) $url );
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper.
	return ( 'www.mailpoet.com' === $host || 'mailpoet.com' === $host ) && false !== strpos( $url, 'ref=free-plan' );
}

// =====================================================================
// The scrub
// =====================================================================

/**
 * Elements kept as they are. Anything else not on the drop list is unwrapped.
 *
 * @return array<string,bool>
 */
function npmp_past_nl_allowed_tags() {
	return array_fill_keys(
		array( 'a', 'abbr', 'address', 'b', 'big', 'blockquote', 'br', 'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'li', 'mark', 'ol', 'p', 'pre', 'q', 's', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul' ),
		true
	);
}

/**
 * Elements dropped with everything inside them.
 *
 * @return array<string,bool>
 */
function npmp_past_nl_dropped_tags() {
	return array_fill_keys(
		array( 'script', 'style', 'link', 'meta', 'title', 'head', 'base', 'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'label', 'fieldset', 'legend', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'param', 'noscript', 'template', 'svg', 'math', 'canvas', 'audio', 'video', 'source', 'track', 'map', 'area', 'portal', 'dialog' ),
		true
	);
}

/**
 * Attributes kept on any element.
 *
 * @return array<string,bool>
 */
function npmp_past_nl_allowed_attributes() {
	return array_fill_keys(
		array( 'href', 'src', 'alt', 'title', 'width', 'height', 'style', 'align', 'valign', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'color', 'face', 'size', 'dir', 'lang', 'role', 'scope', 'target' ),
		true
	);
}

/**
 * A style attribute with anything that can run code or fetch a URL taken out.
 *
 * @param string $style Style attribute.
 * @return string
 */
function npmp_past_nl_safe_style( $style ) {
	$out = array();
	foreach ( explode( ';', html_entity_decode( (string) $style, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $rule ) {
		$rule = trim( $rule );
		if ( '' === $rule || false === strpos( $rule, ':' ) ) {
			continue;
		}
		$plain = strtolower( preg_replace( '/[\s\\\\]+/', '', $rule ) );
		if ( preg_match( '/expression\(|javascript:|vbscript:|behavio(u)?r:|-moz-binding|@import|url\(/', $plain ) ) {
			continue;
		}
		if ( 0 === strpos( $plain, 'mso-' ) ) {
			continue;
		}
		$out[] = $rule;
	}
	return implode( ';', $out );
}

/**
 * The inside of the body element of a whole HTML document, and the body's
 * own style. A fragment comes back as it is.
 *
 * @param string $html HTML.
 * @return array{body:string,style:string}
 */
function npmp_past_nl_extract_body( $html ) {
	$html = (string) $html;
	if ( preg_match( '#<body\b([^>]*)>(.*)</body>#is', $html, $m ) ) {
		$style = '';
		if ( preg_match( '/\bstyle\s*=\s*(["\'])(.*?)\1/is', $m[1], $s ) ) {
			$style = $s[2];
		}
		$bg = '';
		if ( '' === $style && preg_match( '/\bbgcolor\s*=\s*(["\'])(#?[a-z0-9]+)\1/i', $m[1], $b ) ) {
			$bg = 'background-color:' . $b[2];
		}
		return array(
			'body'  => $m[2],
			'style' => '' !== $style ? $style : $bg,
		);
	}
	// A document with no body element: drop the head and the wrapper tags.
	$html = (string) preg_replace( '#<head\b.*?</head>#is', '', $html );
	$html = (string) preg_replace( '#</?(html|body)\b[^>]*>|<!DOCTYPE[^>]*>#i', '', $html );
	return array(
		'body'  => $html,
		'style' => '',
	);
}

/**
 * Scrub an old newsletter body. See the file header for what goes.
 *
 * @param string $html HTML, a whole document or a fragment.
 * @param array  $ctx  link_map (MailPoet hash => URL), plugin_folders
 *                     (string[]), site_url (for a relative image src).
 * @return array{html:string,restored:int,unwrapped:int,removed_links:int,pixels:int,plugin_images:int,dropped:int}
 */
function npmp_past_nl_sanitize( $html, array $ctx = array() ) {
	$stats = array(
		'html'          => '',
		'restored'      => 0,
		'unwrapped'     => 0,
		'removed_links' => 0,
		'pixels'        => 0,
		'plugin_images' => 0,
		'dropped'       => 0,
	);
	$parts = npmp_past_nl_extract_body( (string) $html );
	$body  = trim( (string) $parts['body'] );
	if ( '' === $body ) {
		return $stats;
	}
	if ( ! class_exists( 'DOMDocument' ) ) {
		// No DOM extension: plain text is the only safe thing left.
		$stats['html'] = '<p>' . nl2br( npmp_past_nl_esc( trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) preg_replace( '#<(script|style|head)\b.*?</\1>#is', '', $body ) ) ) ) ) ) . '</p>';
		return $stats;
	}

	$folders = isset( $ctx['plugin_folders'] ) ? array_filter( (array) $ctx['plugin_folders'], 'is_string' ) : array();
	$site    = rtrim( (string) ( $ctx['site_url'] ?? '' ), '/' );
	$allowed = npmp_past_nl_allowed_tags();
	$drop    = npmp_past_nl_dropped_tags();
	$attrs   = npmp_past_nl_allowed_attributes();

	$doc      = new DOMDocument( '1.0', 'UTF-8' );
	$previous = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><html><body><div id="npmp-past-root">' . $body . '</div></body></html>', LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$root = $doc->getElementById( 'npmp-past-root' );
	if ( ! $root ) {
		return $stats;
	}

	$walk = static function ( DOMNode $node ) use ( &$walk, &$stats, $doc, $allowed, $drop, $attrs, $folders, $site, $ctx ) {
		$children = array();
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( XML_COMMENT_NODE === $child->nodeType || XML_PI_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$node->removeChild( $child );
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			/** @var DOMElement $child */
			$tag = strtolower( $child->nodeName );
			if ( isset( $drop[ $tag ] ) ) {
				$node->removeChild( $child );
				++$stats['dropped'];
				continue;
			}

			// Hidden text (MailPoet's preheader span, tracking spans) has no place on a page.
			if ( preg_match( '/display\s*:\s*none/i', $child->getAttribute( 'style' ) ) ) {
				$node->removeChild( $child );
				++$stats['dropped'];
				continue;
			}

			if ( 'a' === $tag ) {
				$href = $child->getAttribute( 'href' );
				if ( npmp_past_nl_is_badge_link( $href ) ) {
					$node->removeChild( $child );
					++$stats['removed_links'];
					continue;
				}
				$verdict = npmp_past_nl_classify_link( $href, $ctx );
				if ( 'remove' === $verdict['action'] ) {
					$node->removeChild( $child );
					++$stats['removed_links'];
					continue;
				}
				if ( 'unwrap' === $verdict['action'] ) {
					$child->removeAttribute( 'href' );
					++$stats['unwrapped'];
					$walk( $child );
					while ( $child->firstChild ) {
						$node->insertBefore( $child->firstChild, $child );
					}
					$node->removeChild( $child );
					continue;
				}
				if ( 'replace' === $verdict['action'] ) {
					$child->setAttribute( 'href', $verdict['url'] );
					++$stats['restored'];
				}
			}

			if ( 'img' === $tag ) {
				$src = trim( $child->getAttribute( 'src' ) );
				if ( npmp_past_nl_is_pixel( $src, $child->getAttribute( 'width' ), $child->getAttribute( 'height' ) ) ) {
					$node->removeChild( $child );
					++$stats['pixels'];
					continue;
				}
				if ( npmp_past_nl_in_plugin_folder( $src, $folders ) ) {
					$alt = trim( $child->getAttribute( 'alt' ) );
					++$stats['plugin_images'];
					if ( '' === $alt || preg_match( '/logo|badge|mailpoet/i', $alt . ' ' . $src ) && preg_match( '/mailpoet_logo|badge/i', $src ) ) {
						$node->removeChild( $child );
					} else {
						$node->replaceChild( $doc->createTextNode( ucfirst( $alt ) . ' ' ), $child );
					}
					continue;
				}
				if ( preg_match( '/^\s*(javascript|vbscript|data|file):/i', $src ) || '' === $src ) {
					$node->removeChild( $child );
					continue;
				}
				if ( '/' === substr( $src, 0, 1 ) && '/' !== substr( $src, 1, 1 ) && '' !== $site ) {
					$child->setAttribute( 'src', $site . $src );
				}
				$child->removeAttribute( 'srcset' );
			}

			// Attributes.
			$names = array();
			foreach ( $child->attributes as $attr ) {
				$names[] = $attr->nodeName;
			}
			foreach ( $names as $name ) {
				$lower = strtolower( $name );
				if ( ! isset( $attrs[ $lower ] ) ) {
					$child->removeAttribute( $name );
					continue;
				}
				if ( 'style' === $lower ) {
					$clean = npmp_past_nl_safe_style( $child->getAttribute( $name ) );
					if ( '' === $clean ) {
						$child->removeAttribute( $name );
					} else {
						$child->setAttribute( $name, $clean );
					}
				} elseif ( 'target' === $lower ) {
					$child->setAttribute( $name, '_blank' );
				} elseif ( 'href' === $lower || 'src' === $lower ) {
					if ( preg_match( '/^\s*(javascript|vbscript|data|file):/i', $child->getAttribute( $name ) ) ) {
						$child->removeAttribute( $name );
					}
				}
			}
			if ( 'a' === $tag && $child->hasAttribute( 'target' ) ) {
				$child->setAttribute( 'rel', 'noopener' );
			}

			$walk( $child );

			if ( ! isset( $allowed[ $tag ] ) ) {
				while ( $child->firstChild ) {
					$node->insertBefore( $child->firstChild, $child );
				}
				$node->removeChild( $child );
			}
		}
	};
	$walk( $root );

	$out = '';
	foreach ( $root->childNodes as $child ) {
		$out .= $doc->saveHTML( $child );
	}
	$out = trim( $out );
	// Tags left with no text, such as "| " after a removed unsubscribe link.
	$out = (string) preg_replace( '~<(p|span|div|td)\b([^>]*)>(?:\s|&nbsp;|&#160;|\xC2\xA0|\|)*</\1>~iu', '<$1$2></$1>', $out );

	$style = npmp_past_nl_safe_style( (string) $parts['style'] );
	if ( '' !== $style && '' !== $out ) {
		$out = '<div style="' . npmp_past_nl_esc( $style ) . '">' . $out . '</div>';
	}
	$stats['html'] = $out;
	return $stats;
}

/**
 * Wrap HTML in a Custom HTML block, so the block editor keeps the layout
 * and WordPress adds no paragraphs of its own.
 *
 * @param string $html HTML.
 * @return string
 */
function npmp_past_nl_wrap_block( $html ) {
	$html = trim( (string) $html );
	return '' === $html ? '' : "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
}

/**
 * Traces of a reader or an old plugin left in an imported body, for tests
 * and the rig. Empty means clean.
 *
 * @param string   $html   Body.
 * @param string[] $emails Readers' addresses that must never appear.
 * @return string[] What was found.
 */
function npmp_past_nl_find_leaks( $html, array $emails = array() ) {
	$html  = (string) $html;
	$plain = str_replace( '&amp;', '&', $html );
	$found = array();
	$needles = array(
		'[mailpoet_'       => 'MailPoet tracking tag',
		'mailpoet_router'  => 'MailPoet endpoint',
		'[subscriber:'     => 'MailPoet reader tag',
		'[link:'           => 'MailPoet link tag',
		'[newsletter:'     => 'MailPoet newsletter tag',
		'nltr='            => 'Newsletter click tracking',
		'noti='            => 'Newsletter open tracking',
		'/tnp/'            => 'Newsletter REST tracking',
		'unsubscription_'  => 'Newsletter unsubscribe tag',
		'{token}'          => 'Newsletter token tag',
		'{email}'          => 'Newsletter email tag',
		'%7Btoken%7D'      => 'Newsletter token tag',
	);
	foreach ( $needles as $needle => $label ) {
		if ( false !== stripos( $plain, $needle ) ) {
			$found[] = $label;
		}
	}
	if ( preg_match( '/[?&](na|nk|nek|ni|nt)=/i', $plain ) ) {
		$found[] = 'Newsletter action link';
	}
	if ( preg_match( '/[?&](token|subscriber_token|link_token|data)=/i', $plain ) ) {
		$found[] = 'Token in a link';
	}
	foreach ( $emails as $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email && false !== stripos( $plain, $email ) ) {
			$found[] = 'Reader address ' . $email;
		}
	}
	return array_values( array_unique( $found ) );
}

// =====================================================================
// MailPoet's saved block structure (newsletters.body)
// =====================================================================

/**
 * Block types the converter renders faithfully.
 *
 * @return string[]
 */
function npmp_past_nl_mailpoet_known_blocks() {
	return array( 'container', 'text', 'image', 'button', 'divider', 'spacer', 'header', 'footer', 'social', 'socialIcon' );
}

/**
 * Block types in a body that the converter can't render faithfully.
 *
 * @param mixed $body JSON string or decoded array.
 * @return string[] Type names, one each.
 */
function npmp_past_nl_mailpoet_lost_types( $body ) {
	$body = is_string( $body ) ? json_decode( $body, true ) : $body;
	if ( ! is_array( $body ) || ! isset( $body['content'] ) || ! is_array( $body['content'] ) ) {
		return array();
	}
	$known = array_flip( npmp_past_nl_mailpoet_known_blocks() );
	$lost  = array();
	$visit = static function ( $block ) use ( &$visit, &$lost, $known ) {
		if ( ! is_array( $block ) ) {
			return;
		}
		$type = isset( $block['type'] ) && is_string( $block['type'] ) ? $block['type'] : '';
		if ( '' !== $type && ! isset( $known[ $type ] ) ) {
			$lost[ $type ] = true;
		}
		foreach ( (array) ( $block['blocks'] ?? array() ) as $child ) {
			$visit( $child );
		}
	};
	$visit( $body['content'] );
	return array_keys( $lost );
}

/**
 * A CSS value from MailPoet's styles, or '' when it isn't a plain value.
 *
 * @param mixed $value Value.
 * @return string
 */
function npmp_past_nl_css_value( $value ) {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	return preg_match( '/^[#a-z0-9 .,%"\'-]{1,80}$/i', $value ) && ! preg_match( '/expression|javascript|url/i', $value ) ? $value : '';
}

/**
 * Build a style attribute from property => value, skipping empty values.
 *
 * @param array<string,mixed> $rules Rules.
 * @return string Escaped attribute, with the leading space, or ''.
 */
function npmp_past_nl_style_attr( array $rules ) {
	$out = array();
	foreach ( $rules as $prop => $value ) {
		$value = npmp_past_nl_css_value( $value );
		if ( '' !== $value && 'transparent' !== strtolower( $value ) ) {
			$out[] = $prop . ':' . $value;
		}
	}
	return $out ? ' style="' . npmp_past_nl_esc( implode( ';', $out ) ) . '"' : '';
}

/**
 * Add the template's heading and link colors to the tags in a text block that
 * carry no color of their own.
 *
 * @param string $html   Text block HTML.
 * @param array  $global globalStyles.
 * @return string
 */
function npmp_past_nl_mailpoet_text_styles( $html, array $global ) {
	$html = (string) $html;
	foreach ( array( 'h1', 'h2', 'h3', 'a' ) as $tag ) {
		$key = 'a' === $tag ? 'link' : $tag;
		if ( empty( $global[ $key ] ) || ! is_array( $global[ $key ] ) ) {
			continue;
		}
		$g     = $global[ $key ];
		$rules = array(
			'color'           => $g['fontColor'] ?? '',
			'font-family'     => 'a' === $tag ? '' : ( $g['fontFamily'] ?? '' ),
			'font-size'       => 'a' === $tag ? '' : ( $g['fontSize'] ?? '' ),
			'text-decoration' => 'a' === $tag ? ( $g['textDecoration'] ?? '' ) : '',
		);
		$add   = trim( npmp_past_nl_style_attr( $rules ) );
		if ( '' === $add ) {
			continue;
		}
		$inner = substr( $add, 7, -1 ); // The rules without style="...".
		$html  = (string) preg_replace_callback(
			'#<' . $tag . '\b([^>]*)>#i',
			static function ( $m ) use ( $tag, $inner ) {
				if ( preg_match( '/\bstyle\s*=\s*(["\'])(.*?)\1/is', $m[1], $s ) ) {
					if ( preg_match( '/(^|;)\s*color\s*:/i', $s[2] ) ) {
						return $m[0];
					}
					$merged = rtrim( $s[2], '; ' ) . ';' . html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					return '<' . $tag . str_replace( $s[0], 'style="' . npmp_past_nl_esc( $merged ) . '"', $m[1] ) . '>';
				}
				return '<' . $tag . $m[1] . ' style="' . $inner . '">';
			},
			$html
		);
	}
	return $html;
}

/**
 * Turn MailPoet's saved block structure into web HTML. Never runs MailPoet.
 *
 * Containers become stacked sections, side-by-side containers become
 * columns that wrap on a phone, and text, image, button, divider, spacer,
 * header, footer, and social blocks keep their colors, sizes, and
 * alignment. Any other block (latest posts, products, coupons) comes over
 * as its text, if it has any, and counts as lost formatting.
 *
 * @param mixed $body JSON string or decoded array.
 * @param array $ctx  site_url (for a relative image src), plugin_folders.
 * @return array{html:string,lost:string[]}
 */
function npmp_past_nl_mailpoet_render( $body, array $ctx = array() ) {
	$body = is_string( $body ) ? json_decode( $body, true ) : $body;
	if ( ! is_array( $body ) || ! isset( $body['content'] ) || ! is_array( $body['content'] ) ) {
		return array(
			'html' => '',
			'lost' => array(),
		);
	}
	$global = isset( $body['globalStyles'] ) && is_array( $body['globalStyles'] ) ? $body['globalStyles'] : array();
	$site   = rtrim( (string) ( $ctx['site_url'] ?? '' ), '/' );
	$known  = array_flip( npmp_past_nl_mailpoet_known_blocks() );
	$lost   = array();

	$render = static function ( $block ) use ( &$render, &$lost, $global, $site, $known ) {
		if ( ! is_array( $block ) ) {
			return '';
		}
		$type   = isset( $block['type'] ) && is_string( $block['type'] ) ? $block['type'] : '';
		$styles = isset( $block['styles'] ) && is_array( $block['styles'] ) ? $block['styles'] : array();
		$bs     = isset( $styles['block'] ) && is_array( $styles['block'] ) ? $styles['block'] : array();
		$ts     = isset( $styles['text'] ) && is_array( $styles['text'] ) ? $styles['text'] : array();
		$align  = in_array( $bs['textAlign'] ?? '', array( 'left', 'right', 'center' ), true ) ? $bs['textAlign'] : '';

		switch ( $type ) {
			case 'container':
				$children = array();
				foreach ( (array) ( $block['blocks'] ?? array() ) as $child ) {
					$children[] = $render( $child );
				}
				$bg = array( 'background-color' => $bs['backgroundColor'] ?? '' );
				if ( 'horizontal' === ( $block['orientation'] ?? '' ) && count( $children ) > 1 ) {
					$weights = array_fill( 0, count( $children ), 1 );
					if ( is_string( $block['columnLayout'] ?? null ) && preg_match( '/^\d+(_\d+)+$/', $block['columnLayout'] ) ) {
						$parts = array_map( 'intval', explode( '_', $block['columnLayout'] ) );
						if ( count( $parts ) === count( $children ) ) {
							$weights = $parts;
						}
					}
					$cols = '';
					foreach ( $children as $i => $col ) {
						$cols .= '<div style="flex:' . max( 1, (int) $weights[ $i ] ) . ' 1 220px;min-width:0">' . $col . '</div>';
					}
					return '<div' . npmp_past_nl_style_attr( $bg + array( 'display' => 'flex', 'flex-wrap' => 'wrap', 'gap' => '0 20px' ) ) . '>' . $cols . '</div>';
				}
				$inner = implode( '', $children );
				$attr  = npmp_past_nl_style_attr( $bg );
				return '' === $attr ? $inner : '<div' . $attr . '>' . $inner . '</div>';

			case 'text':
				$html = isset( $block['text'] ) && is_string( $block['text'] ) ? $block['text'] : '';
				return '<div style="padding:0 20px">' . npmp_past_nl_mailpoet_text_styles( $html, $global ) . '</div>';

			case 'header':
			case 'footer':
				$html = isset( $block['text'] ) && is_string( $block['text'] ) ? $block['text'] : '';
				$link = isset( $styles['link'] ) && is_array( $styles['link'] ) ? array( 'link' => $styles['link'] ) : array();
				$html = npmp_past_nl_mailpoet_text_styles( $html, $link + $global );
				return '<div' . npmp_past_nl_style_attr(
					array(
						'background-color' => $bs['backgroundColor'] ?? '',
						'color'            => $ts['fontColor'] ?? '',
						'font-family'      => $ts['fontFamily'] ?? '',
						'font-size'        => $ts['fontSize'] ?? '',
						'text-align'       => $ts['textAlign'] ?? '',
						'padding'          => '10px 20px',
					)
				) . '>' . $html . '</div>';

			case 'image':
				$src = isset( $block['src'] ) && is_string( $block['src'] ) ? trim( $block['src'] ) : '';
				if ( '' === $src ) {
					return '';
				}
				if ( '/' === substr( $src, 0, 1 ) && '/' !== substr( $src, 1, 1 ) && '' !== $site ) {
					$src = $site . $src;
				}
				$width = (int) preg_replace( '/\D/', '', (string) ( $block['width'] ?? '' ) );
				$img   = '<img src="' . npmp_past_nl_esc( $src ) . '" alt="' . npmp_past_nl_esc( is_scalar( $block['alt'] ?? '' ) ? (string) ( $block['alt'] ?? '' ) : '' ) . '"' . ( $width > 0 ? ' width="' . $width . '"' : '' ) . ' style="max-width:100%;height:auto;display:inline-block">';
				$link  = isset( $block['link'] ) && is_string( $block['link'] ) ? trim( $block['link'] ) : '';
				if ( '' !== $link ) {
					$img = '<a href="' . npmp_past_nl_esc( $link ) . '">' . $img . '</a>';
				}
				$pad = empty( $block['fullWidth'] ) ? '10px 20px' : '0';
				return '<div' . npmp_past_nl_style_attr(
					array(
						'text-align' => '' !== $align ? $align : 'center',
						'padding'    => $pad,
					)
				) . '>' . $img . '</div>';

			case 'button':
				$text = isset( $block['text'] ) && is_scalar( $block['text'] ) ? (string) $block['text'] : '';
				$url  = isset( $block['url'] ) && is_string( $block['url'] ) ? trim( $block['url'] ) : '';
				if ( '' === trim( $text ) ) {
					return '';
				}
				$a = npmp_past_nl_style_attr(
					array(
						'display'          => 'inline-block',
						'background-color' => $bs['backgroundColor'] ?? '',
						'color'            => $bs['fontColor'] ?? '',
						'font-family'      => $bs['fontFamily'] ?? '',
						'font-size'        => $bs['fontSize'] ?? '',
						'font-weight'      => $bs['fontWeight'] ?? 'bold',
						'line-height'      => $bs['lineHeight'] ?? '',
						'border-radius'    => $bs['borderRadius'] ?? '',
						'border-width'     => $bs['borderWidth'] ?? '',
						'border-style'     => $bs['borderStyle'] ?? '',
						'border-color'     => $bs['borderColor'] ?? '',
						'min-width'        => $bs['width'] ?? '',
						'padding'          => '0 20px',
						'text-align'       => 'center',
						'text-decoration'  => 'none',
					)
				);
				$inner = '' !== $url ? '<a href="' . npmp_past_nl_esc( $url ) . '"' . $a . '>' . npmp_past_nl_esc( $text ) . '</a>' : '<span' . $a . '>' . npmp_past_nl_esc( $text ) . '</span>';
				return '<div' . npmp_past_nl_style_attr(
					array(
						'text-align' => '' !== $align ? $align : 'center',
						'padding'    => '10px 20px',
					)
				) . '>' . $inner . '</div>';

			case 'divider':
				$hr = npmp_past_nl_style_attr(
					array(
						'border'           => '0',
						'border-top-width' => $bs['borderWidth'] ?? '1px',
						'border-top-style' => $bs['borderStyle'] ?? 'solid',
						'border-top-color' => $bs['borderColor'] ?? '#aaaaaa',
						'margin'           => '0',
					)
				);
				return '<div' . npmp_past_nl_style_attr(
					array(
						'background-color' => $bs['backgroundColor'] ?? '',
						'padding'          => ( $bs['padding'] ?? '13px' ) . ' 20px',
					)
				) . '><hr' . $hr . '></div>';

			case 'spacer':
				$height = (int) preg_replace( '/\D/', '', (string) ( $bs['height'] ?? '20' ) );
				return '<div' . npmp_past_nl_style_attr(
					array(
						'height'           => max( 1, min( 400, $height ) ) . 'px',
						'background-color' => $bs['backgroundColor'] ?? '',
					)
				) . '></div>';

			case 'social':
				$links = array();
				foreach ( (array) ( $block['icons'] ?? array() ) as $icon ) {
					if ( ! is_array( $icon ) ) {
						continue;
					}
					$url   = isset( $icon['link'] ) && is_string( $icon['link'] ) ? trim( $icon['link'] ) : '';
					$label = isset( $icon['text'] ) && is_string( $icon['text'] ) && '' !== trim( $icon['text'] ) ? trim( $icon['text'] ) : ucfirst( (string) ( $icon['iconType'] ?? '' ) );
					$image = isset( $icon['image'] ) && is_string( $icon['image'] ) ? trim( $icon['image'] ) : '';
					if ( '' === $label && '' === $image ) {
						continue;
					}
					$w     = (int) preg_replace( '/\D/', '', (string) ( $icon['width'] ?? '32' ) );
					$inner = '' !== $image ? '<img src="' . npmp_past_nl_esc( $image ) . '" alt="' . npmp_past_nl_esc( $label ) . '" width="' . max( 16, min( 64, $w ) ) . '" style="display:inline-block;border:0">' : npmp_past_nl_esc( $label );
					$links[] = '' !== $url && '#' !== $url ? '<a href="' . npmp_past_nl_esc( $url ) . '">' . $inner . '</a>' : $inner;
				}
				if ( ! $links ) {
					return '';
				}
				return '<p' . npmp_past_nl_style_attr(
					array(
						'text-align' => '' !== $align ? $align : 'center',
						'padding'    => '10px 20px',
						'margin'     => '0',
					)
				) . '>' . implode( ' ', $links ) . '</p>';

			case 'socialIcon':
				return '';
		}

		if ( '' !== $type && ! isset( $known[ $type ] ) ) {
			$lost[ $type ] = true;
		}
		$out = '';
		if ( isset( $block['text'] ) && is_string( $block['text'] ) && '' !== trim( $block['text'] ) ) {
			$out .= '<div style="padding:0 20px">' . $block['text'] . '</div>';
		}
		foreach ( (array) ( $block['blocks'] ?? array() ) as $child ) {
			$out .= $render( $child );
		}
		return $out;
	};

	$inner = $render( $body['content'] );
	$text  = isset( $global['text'] ) && is_array( $global['text'] ) ? $global['text'] : array();
	$wrap  = npmp_past_nl_style_attr(
		array(
			'max-width'        => '660px',
			'margin'           => '0 auto',
			'background-color' => $global['wrapper']['backgroundColor'] ?? '',
			'color'            => $text['fontColor'] ?? '',
			'font-family'      => $text['fontFamily'] ?? '',
			'font-size'        => $text['fontSize'] ?? '',
			'line-height'      => '1.6',
		)
	);
	$html = '<div' . $wrap . '>' . $inner . '</div>';
	$bg   = npmp_past_nl_style_attr( array( 'background-color' => $global['body']['backgroundColor'] ?? '' ) );
	if ( '' !== $bg ) {
		$html = '<div' . $bg . '>' . $html . '</div>';
	}
	return array(
		'html' => $html,
		'lost' => array_keys( $lost ),
	);
}

// =====================================================================
// The Newsletter Plugin's options column
// =====================================================================

/**
 * Read The Newsletter Plugin's options column (PHP serialized) without
 * building any object it names.
 *
 * @param mixed $raw Stored value.
 * @return array
 */
function npmp_past_nl_tnp_options( $raw ) {
	if ( is_array( $raw ) ) {
		return $raw;
	}
	$raw = is_string( $raw ) ? trim( $raw ) : '';
	if ( '' === $raw ) {
		return array();
	}
	if ( '{' === $raw[0] || '[' === $raw[0] ) {
		$json = json_decode( $raw, true );
		return is_array( $json ) ? $json : array();
	}
	if ( ! preg_match( '/^a:\d+:\{/', $raw ) ) {
		return array();
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes false: no object ever gets built.
	$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
	return is_array( $value ) ? $value : array();
}

/**
 * The list numbers a Newsletter Plugin email went to.
 *
 * Newer versions keep them in options['lists'], older ones in the
 * preferences column (a comma list, which counted only when
 * options['preferences_status'] was empty).
 *
 * @param mixed  $options     options column.
 * @param string $preferences preferences column.
 * @return int[]
 */
function npmp_past_nl_tnp_email_lists( $options, $preferences = '' ) {
	$options = npmp_past_nl_tnp_options( $options );
	$ids     = array();
	if ( isset( $options['lists'] ) ) {
		$ids = (array) $options['lists'];
	} elseif ( '' !== trim( (string) $preferences ) && empty( $options['preferences_status'] ) ) {
		$ids = explode( ',', (string) $preferences );
	}
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	sort( $ids );
	return $ids;
}

// =====================================================================
// Subjects and MailPoet's block email editor
// =====================================================================

/**
 * MailPoet's newer block email editor writes personal tags as
 * [mailpoet/subscriber-firstname default="x"], sometimes inside an HTML
 * comment. Name tags become ours, and the rest go.
 *
 * @param string $html Body.
 * @return string
 */
function npmp_past_nl_mailpoet_block_tags( $html ) {
	return (string) preg_replace_callback(
		'#(?:<!--\s*)?\[mailpoet/([a-z0-9_-]+)([^\]]*)\](?:\s*-->)?#i',
		static function ( $m ) {
			$map = array(
				'subscriber-firstname' => 'first_name',
				'subscriber-lastname'  => 'last_name',
			);
			$tag = strtolower( $m[1] );
			if ( ! isset( $map[ $tag ] ) ) {
				return '';
			}
			$def = preg_match( '/\bdefault\s*=\s*(["\'])(.*?)\1/i', $m[2], $d ) ? $d[2] : '';
			return '[' . $map[ $tag ] . npmp_past_nl_tag_default( $def ) . ']';
		},
		(string) $html
	);
}

/**
 * A subject fit for a post title: a name tag becomes its default word (or
 * goes), and every other tag goes.
 *
 * @param string $subject Subject with our tags already converted.
 * @return string
 */
function npmp_past_nl_plain_subject( $subject ) {
	$subject = (string) preg_replace_callback(
		'/\[(?:first_name|last_name|name)(?:\s+default="([^"]*)")?\]/',
		static function ( $m ) {
			return isset( $m[1] ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		},
		(string) $subject
	);
	$subject = html_entity_decode( strip_tags( $subject ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$subject = trim( (string) preg_replace( '/\s+/u', ' ', $subject ) );
	// "Hi , here's March" after a name with no default goes.
	return trim( (string) preg_replace( '/\s+([,.!?:])/u', '$1', $subject ), " ,\t" );
}

/**
 * MailPoet's stored rendered email: JSON in current versions, PHP
 * serialized in older ones. Never builds an object.
 *
 * @param mixed $raw newsletter_rendered_body.
 * @return string The HTML part, or ''.
 */
function npmp_past_nl_mailpoet_rendered_html( $raw ) {
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return '';
	}
	$value = json_decode( $raw, true );
	if ( ! is_array( $value ) && preg_match( '/^a:\d+:\{/', $raw ) ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes false: no object ever gets built.
		$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
	}
	return is_array( $value ) && isset( $value['html'] ) && is_string( $value['html'] ) ? $value['html'] : '';
}
