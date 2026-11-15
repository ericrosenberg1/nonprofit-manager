<?php
/**
 * Real-WordPress check of a group newsletter's audience through the block
 * editor's save, with free and Pro active.
 *
 * The block editor saves the post over REST, then posts the sidebar boxes
 * to post.php with meta-box-loader=1: the hidden base form plus each meta
 * box location's form, checked boxes only, disabled inputs left out. This
 * rig reads the edit screen over HTTP, sends that same request with the
 * boxes ticked the way an author ticks them, and reads the edit screen and
 * the Newsletter Archive screen back. Pete at U3A unticked All Members,
 * ticked one group, and saved: before the fix the archive said "All
 * subscribed members" and the editor came back with All Members ticked.
 *
 * Covers: one group and no level, a group with All Members left ticked, a
 * level plus a group, no group, the group's public archive list, and
 * Duplicate keeping the group.
 *
 * Run inside a throwaway WordPress with members and newsletters on, free
 * and Pro active, and the site served at its home URL (php -S works):
 *   wp eval-file tests/rig/newsletter-group-audience.php
 * It deletes the newsletters and groups it made on exit. To compare with an
 * older free, point the plugin's symlink at it and restart php -S: the
 * server's realpath cache keeps serving the old target for two minutes.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! class_exists( 'NPMP_Newsletter_Manager' ) || ! function_exists( 'npmp_newsletter_send_controls_html' ) ) {
	echo "  FAIL  newsletters must be on (npmp_enabled_features)\n";
	exit( 1 );
}
if ( ! function_exists( 'npmp_pro_ensure_group' ) ) {
	echo "  FAIL  Pro must be active for this rig\n";
	exit( 1 );
}

$rig_admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$rig_admin = $rig_admin[0];
wp_set_current_user( $rig_admin->ID );

// Two groups of our own, deleted on exit unless they were here before.
$rig_made_groups = array();
$rig_group       = static function ( $name ) use ( &$rig_made_groups ) {
	$term = get_term_by( 'name', $name, 'npmp_group' );
	if ( $term ) {
		return (int) $term->term_id;
	}
	$id                = (int) npmp_pro_ensure_group( $name );
	$rig_made_groups[] = $id;
	return $id;
};
$art  = $rig_group( 'Rig Art Club' );
$walk = $rig_group( 'Rig Walking' );
update_option( 'npmp_membership_levels', array_values( array_unique( array_merge( (array) get_option( 'npmp_membership_levels', array() ), array( 'Rig Gold' ) ) ) ) );

$rig_posts = array();
register_shutdown_function(
	static function () use ( &$rig_posts, &$rig_made_groups ) {
		foreach ( $rig_posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( get_posts( array( 'post_type' => 'npmp_newsletter', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids', 's' => 'Rig audience' ) ) as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $rig_made_groups as $id ) {
			wp_delete_term( $id, 'npmp_group' );
		}
	}
);

// Logged-in cookies for the admin, as a browser holds them.
$rig_expire  = time() + HOUR_IN_SECONDS;
$rig_cookies = array(
	new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $rig_admin->ID, $rig_expire, 'auth' ) ) ),
	new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $rig_admin->ID, $rig_expire, 'logged_in' ) ) ),
);

$rig_get = static function ( $url ) use ( $rig_cookies ) {
	$res = wp_remote_get( $url, array( 'cookies' => $rig_cookies, 'timeout' => 60, 'redirection' => 0 ) );
	return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
};

/**
 * The fields a form posts: checked boxes only, no disabled inputs.
 *
 * @param DOMXPath $xp   Page.
 * @param DOMNode  $form Form.
 * @return array[] List of [ name, value, input type, class ].
 */
function npmp_rig_form_fields( $xp, $form ) {
	$out = array();
	foreach ( $xp->query( './/input | .//textarea | .//select', $form ) as $el ) {
		$name = $el->getAttribute( 'name' );
		if ( '' === $name || $el->hasAttribute( 'disabled' ) ) {
			continue;
		}
		$type = strtolower( $el->getAttribute( 'type' ) );
		if ( 'textarea' === $el->nodeName ) {
			$out[] = array( $name, $el->textContent, 'textarea', '' );
		} elseif ( 'select' === $el->nodeName ) {
			$value = '';
			foreach ( $xp->query( './/option', $el ) as $opt ) {
				if ( $opt->hasAttribute( 'selected' ) || '' === $value ) {
					$value = $opt->hasAttribute( 'value' ) ? $opt->getAttribute( 'value' ) : $opt->textContent;
				}
			}
			$out[] = array( $name, $value, 'select', '' );
		} elseif ( in_array( $type, array( 'submit', 'button', 'image', 'file' ), true ) ) {
			continue;
		} else {
			$out[] = array( $name, $el->getAttribute( 'value' ), $type, $el->getAttribute( 'class' ), $el->hasAttribute( 'checked' ) );
		}
	}
	return $out;
}

/**
 * The edit screen, parsed.
 *
 * @param string $html Page.
 * @return array{xp:DOMXPath,url:string}
 */
function npmp_rig_parse( $html ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( $html );
	libxml_clear_errors();
	$url = preg_match( '/var _wpMetaBoxUrl = ("[^"]*")/', $html, $m ) ? (string) json_decode( $m[1] ) : '';
	return array( 'xp' => new DOMXPath( $doc ), 'url' => $url );
}

/**
 * Save the sidebar the way the block editor does after its REST save.
 *
 * @param int      $id     Newsletter.
 * @param string[] $levels Level boxes ticked (__all__ for All Members).
 * @param int[]    $groups Group boxes ticked.
 * @return string Problem, or '' when the request went through.
 */
$rig_save = static function ( $id, $levels, $groups ) use ( $rig_get, $rig_cookies ) {
	$html = $rig_get( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
	$page = npmp_rig_parse( $html );
	if ( '' === $page['url'] ) {
		return 'no _wpMetaBoxUrl on the edit screen (not the block editor?)';
	}
	$xp    = $page['xp'];
	$forms = $xp->query( '//form[contains(@class,"metabox-base-form") or contains(@class,"metabox-location-")]' );
	$pairs = array();
	foreach ( $forms as $form ) {
		foreach ( npmp_rig_form_fields( $xp, $form ) as $f ) {
			list( $name, $value, $type ) = $f;
			if ( 'checkbox' === $type || 'radio' === $type ) {
				if ( 'npmp_newsletter_levels[]' === $name ) {
					// The author's ticks, with the editor script's rule: a
					// level box is enabled once All Members is off.
					if ( ! in_array( $value, $levels, true ) ) {
						continue;
					}
				} elseif ( 'npmp_newsletter_groups[]' === $name ) {
					if ( ! in_array( (int) $value, $groups, true ) ) {
						continue;
					}
				} elseif ( empty( $f[4] ) ) {
					continue;
				}
			}
			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( $value );
		}
	}
	// The level boxes print disabled while All Members is ticked, and the
	// editor script enables them when it comes off. Add what was ticked.
	foreach ( $levels as $level ) {
		$pair = rawurlencode( 'npmp_newsletter_levels[]' ) . '=' . rawurlencode( $level );
		if ( ! in_array( $pair, $pairs, true ) ) {
			$pairs[] = $pair;
		}
	}
	$res = wp_remote_post(
		$page['url'],
		array(
			'cookies'     => $rig_cookies,
			'timeout'     => 60,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'        => implode( '&', $pairs ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	return in_array( $code, array( 200, 302 ), true ) ? '' : 'HTTP ' . $code;
};

/**
 * The edit screen's audience box after a reload.
 *
 * @param int $id Newsletter.
 * @return array{all:?bool,groups:int[],label:string}
 */
$rig_read_editor = static function ( $id ) use ( $rig_get ) {
	$page = npmp_rig_parse( $rig_get( admin_url( 'post.php?post=' . $id . '&action=edit' ) ) );
	$xp   = $page['xp'];
	$all  = $xp->query( '//input[contains(@class,"npmp-newsletter-all")]' )->item( 0 );
	$out  = array(
		'all'    => $all ? $all->hasAttribute( 'checked' ) : null,
		'groups' => array(),
		'label'  => '',
	);
	foreach ( $xp->query( '//input[@name="npmp_newsletter_groups[]"][@checked]' ) as $el ) {
		$out['groups'][] = (int) $el->getAttribute( 'value' );
	}
	$label = $xp->query( '//*[contains(@class,"npmp-newsletter-audience-label")]' )->item( 0 );
	$out['label'] = $label ? trim( $label->textContent ) : '';
	return $out;
};

/**
 * The Audience cell of a newsletter's row on the Newsletter Archive screen.
 *
 * @param string $title Newsletter title.
 * @return string
 */
$rig_archive_cell = static function ( $title ) use ( $rig_get ) {
	$page = npmp_rig_parse( $rig_get( admin_url( 'admin.php?page=npmp_newsletter_archive' ) ) );
	$xp   = $page['xp'];
	$head = array();
	foreach ( $xp->query( '//table//thead//tr/*' ) as $th ) {
		$head[] = trim( $th->textContent );
	}
	$col = array_search( 'Audience', $head, true );
	foreach ( $xp->query( '//table//tbody/tr' ) as $tr ) {
		if ( false === strpos( $tr->textContent, $title ) ) {
			continue;
		}
		$cells = $xp->query( './th|./td', $tr );
		return false === $col || ! $cells->item( $col ) ? '(no Audience column)' : trim( $cells->item( $col )->textContent );
	}
	return '(row not found)';
};

$rig_new = static function ( $title ) use ( &$rig_posts ) {
	$id          = (int) wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->' ) );
	$rig_posts[] = $id;
	return $id;
};

echo "Pete: All Members off, one group, no level, saved from the block editor\n";
$id   = $rig_new( 'Rig audience one group' );
$err  = $rig_save( $id, array(), array( $art ) );
$ok( 'meta-box-loader save went through' . ( $err ? " ($err)" : '' ), '' === $err );
wp_cache_flush();
$ok( 'group saved', array( $art ) === get_post_meta( $id, '_npmp_newsletter_groups', true ) );
$ok( 'no level saved', array() === get_post_meta( $id, '_npmp_newsletter_levels', true ) );
$want = 'Members of Rig Art Club';
$got  = (string) get_post_meta( $id, '_npmp_newsletter_audience_label', true );
$ok( "stored label is \"$want\" (got \"$got\")", $want === $got );
$cell = $rig_archive_cell( 'Rig audience one group' );
$ok( "Newsletter Archive's Audience column is \"$want\" (got \"$cell\")", $want === $cell );
$ed = $rig_read_editor( $id );
$ok( 'editor reloads with All Members unticked', false === $ed['all'] );
$ok( 'editor reloads with the group ticked', array( $art ) === $ed['groups'] );
$ok( "editor's Current selection is \"$want\" (got \"{$ed['label']}\")", $want === $ed['label'] );

// Sent and public, so the group's public list can show it.
update_post_meta( $id, '_npmp_newsletter_status', 'sent' );
update_post_meta( $id, '_npmp_newsletter_public', '1' );
update_post_meta( $id, '_npmp_newsletter_queued_at', current_time( 'mysql' ) );
$art_slug  = get_term( $art, 'npmp_group' )->slug;
$walk_slug = get_term( $walk, 'npmp_group' )->slug;
$ok( "the group's public list includes it", false !== strpos( do_shortcode( '[npmp_newsletters group="' . $art_slug . '"]' ), 'Rig audience one group' ) );
$ok( "another group's public list leaves it out", false === strpos( do_shortcode( '[npmp_newsletters group="' . $walk_slug . '"]' ), 'Rig audience one group' ) );

echo "Saving again from the reloaded editor keeps the group\n";
$err = $rig_save( $id, array(), array( $art ) );
wp_cache_flush();
$ok( 'group still saved' . ( $err ? " ($err)" : '' ), array( $art ) === get_post_meta( $id, '_npmp_newsletter_groups', true ) );

echo "Duplicate keeps the group\n";
// The link from the Newsletter Archive screen, so its nonce belongs to the
// browser's session.
$dup_page = npmp_rig_parse( $rig_get( admin_url( 'admin.php?page=npmp_newsletter_archive' ) ) );
$dup_link = '';
foreach ( $dup_page['xp']->query( '//a[contains(@href,"npmp_duplicate_newsletter")]' ) as $a ) {
	if ( false !== strpos( $a->getAttribute( 'href' ), 'newsletter_id=' . $id . '&' ) ) {
		$dup_link = $a->getAttribute( 'href' );
	}
}
$ok( 'Duplicate link on the Newsletter Archive screen', '' !== $dup_link );
if ( $dup_link ) {
	wp_remote_get( $dup_link, array( 'cookies' => $rig_cookies, 'timeout' => 60, 'redirection' => 0 ) );
}
$dup = get_posts( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'title' => 'Rig audience one group (Copy)', 'numberposts' => 1, 'fields' => 'ids' ) );
if ( $dup ) {
	$rig_posts[] = (int) $dup[0];
}
wp_cache_flush();
$ok( 'a copy was made', (bool) $dup );
$ok( 'the copy keeps the group', $dup && array( $art ) === get_post_meta( (int) $dup[0], '_npmp_newsletter_groups', true ) );

echo "All Members left ticked with a group: members of the group, as the send does\n";
$id  = $rig_new( 'Rig audience all plus group' );
$err = $rig_save( $id, array( '__all__' ), array( $art ) );
wp_cache_flush();
$ok( 'saved' . ( $err ? " ($err)" : '' ), '' === $err );
$ok( 'group saved', array( $art ) === get_post_meta( $id, '_npmp_newsletter_groups', true ) );
$ok( 'levels collapse to none', array() === get_post_meta( $id, '_npmp_newsletter_levels', true ) );
$cell = $rig_archive_cell( 'Rig audience all plus group' );
$ok( "Audience column is \"$want\" (got \"$cell\")", $want === $cell );
$ed = $rig_read_editor( $id );
$ok( 'editor reloads with All Members unticked', false === $ed['all'] );

echo "A level plus a group\n";
$id  = $rig_new( 'Rig audience level plus group' );
$err = $rig_save( $id, array( 'Rig Gold' ), array( $walk ) );
wp_cache_flush();
$want = 'Rig Gold in Rig Walking';
$cell = $rig_archive_cell( 'Rig audience level plus group' );
$ok( "Audience column is \"$want\" (got \"$cell\")" . ( $err ? " ($err)" : '' ), $want === $cell );
$ed = $rig_read_editor( $id );
$ok( 'editor reloads with All Members unticked', false === $ed['all'] );

echo "No group: everyone, as before\n";
$id  = $rig_new( 'Rig audience everyone' );
$err = $rig_save( $id, array( '__all__' ), array() );
wp_cache_flush();
$want = 'All subscribed members';
$ok( 'no group saved' . ( $err ? " ($err)" : '' ), '' === get_post_meta( $id, '_npmp_newsletter_groups', true ) );
$cell = $rig_archive_cell( 'Rig audience everyone' );
$ok( "Audience column is \"$want\" (got \"$cell\")", $want === $cell );
$ed = $rig_read_editor( $id );
$ok( 'editor reloads with All Members ticked', true === $ed['all'] );

echo "\n" . ( $fails ? "$fails FAILED\n" : "all passed\n" );
exit( $fails ? 1 : 0 );
