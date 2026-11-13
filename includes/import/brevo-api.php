<?php
/**
 * File path: includes/import/brevo-api.php
 *
 * Brevo (formerly Sendinblue) contacts import: a small v3 API client, the
 * row shape the import wizard reads, and the way the pasted API key is kept
 * for the length of one import.
 *
 * Brevo docs this follows, read 2026-11-13:
 * - Lists:          https://developers.brevo.com/reference/get-lists
 * - List contacts:  https://developers.brevo.com/reference/get-contacts-from-list
 *                   (limit up to 500, offset, sort asc|desc by creation date)
 * - All contacts:   https://developers.brevo.com/reference/get-contacts
 * - Attributes:     https://developers.brevo.com/reference/get-attributes
 *                   (type text|date|float|id|boolean|multiple-choice|user,
 *                   category normal|transactional|category|calculated|global)
 * - Rate limits:    https://developers.brevo.com/docs/api-limits (429) and
 *                   https://developers.brevo.com/docs/limit-headers
 *                   (x-sib-ratelimit-reset counts down in seconds)
 * - Errors:         https://developers.brevo.com/docs/how-it-works (401 for a
 *                   wrong key or an IP missing from Authorised IPs, 403)
 *
 * Nobody has run this against the live Brevo API. The rig
 * tests/rig/brevo-import.php drives it through a pre_http_request fake.
 *
 * The key. The owner pastes it, it goes to Brevo in the api-key header and
 * nowhere else. Between the import's requests it lives in a transient sealed
 * with a secret that only the owner's browser holds, so the database alone
 * never has it. The transient lasts 15 minutes past the last request and the
 * import deletes it when it finishes or stops. No message, option, or log
 * line ever carries the key.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_BREVO_API' ) ) {
	define( 'NPMP_BREVO_API', 'https://api.brevo.com/v3' );
}
if ( ! defined( 'NPMP_BREVO_JOB_TTL' ) ) {
	define( 'NPMP_BREVO_JOB_TTL', 15 * 60 );
}

/**
 * Brevo's own contact attributes that have a home on the contact already.
 *
 * @return array Attribute name => contact field.
 */
function npmp_brevo_known_attributes() {
	return array(
		'FIRSTNAME' => 'first_name',
		'LASTNAME'  => 'last_name',
		'PRENOM'    => 'first_name',
		'NOM'       => 'last_name',
		'SMS'       => 'phone',
	);
}

/**
 * The plain message for a failed Brevo request. Never carries the key or
 * anything Brevo sent back, so nothing in it can echo the key.
 *
 * @param int    $status HTTP status, or 0 when the request never got an answer.
 * @param string $body   Brevo's message, read only to tell the IP case apart.
 * @param int    $wait   Seconds Brevo asked us to wait (429).
 * @return array{code:string,message:string,retry:bool,retry_after:int}
 */
function npmp_brevo_error( $status, $body = '', $wait = 0 ) {
	$status = (int) $status;
	$out    = array(
		'code'        => 'npmp_brevo_error',
		'message'     => '',
		'retry'       => false,
		'retry_after' => 0,
	);
	if ( 0 === $status ) {
		$out['code']        = 'npmp_brevo_timeout';
		$out['message']     = __( 'Brevo took too long to answer. We paused your import, and you can resume the import from the same spot.', 'nonprofit-manager' );
		$out['retry']       = true;
		$out['retry_after'] = 5;
	} elseif ( 401 === $status && false !== stripos( (string) $body, 'ip' ) && false !== stripos( (string) $body, 'unrecogni' ) ) {
		$out['code']    = 'npmp_brevo_ip';
		$out['message'] = __( 'Brevo turned down this website\'s IP address. In Brevo, open Security, then Authorised IPs, and add this server or turn the check off, then paste the key again.', 'nonprofit-manager' );
	} elseif ( 401 === $status ) {
		$out['code']    = 'npmp_brevo_key';
		$out['message'] = __( 'Brevo didn\'t accept that API key. Copy it again from SMTP & API, then API keys, in your Brevo account.', 'nonprofit-manager' );
	} elseif ( 403 === $status ) {
		$out['code']    = 'npmp_brevo_permission';
		$out['message'] = __( 'Brevo won\'t let that key read contacts. Make a key with access to contacts, or ask the account owner for a new key.', 'nonprofit-manager' );
	} elseif ( 404 === $status ) {
		$out['code']    = 'npmp_brevo_missing';
		$out['message'] = __( 'Brevo couldn\'t find that list. Fetch your lists again and pick one.', 'nonprofit-manager' );
	} elseif ( 429 === $status ) {
		$wait               = max( 1, min( 3600, (int) $wait ) );
		$out['code']        = 'npmp_brevo_rate';
		$out['message']     = sprintf(
			/* translators: %d: seconds to wait */
			_n( 'Brevo asked us to slow down. We resume your import in %d second.', 'Brevo asked us to slow down. We resume your import in %d seconds.', $wait, 'nonprofit-manager' ),
			$wait
		);
		$out['retry']       = true;
		$out['retry_after'] = $wait;
	} elseif ( $status >= 500 ) {
		$out['code']        = 'npmp_brevo_server';
		$out['message']     = __( 'Brevo had a problem on its side. We paused your import, and you can resume the import from the same spot.', 'nonprofit-manager' );
		$out['retry']       = true;
		$out['retry_after'] = 10;
	} else {
		$out['message'] = sprintf(
			/* translators: %d: HTTP status code */
			__( 'Brevo answered with error %d. Check the key and the list, then try again.', 'nonprofit-manager' ),
			$status
		);
	}
	return $out;
}

/**
 * One GET to Brevo.
 *
 * @param string $api_key The owner's key, sent only in the api-key header.
 * @param string $path    Path under /v3, with a leading slash.
 * @param array  $query   Query arguments.
 * @return array|WP_Error Decoded body, or an error whose data has retry and retry_after.
 */
function npmp_brevo_get( $api_key, $path, array $query = array() ) {
	$api_key = trim( (string) $api_key );
	if ( '' === $api_key ) {
		$e = npmp_brevo_error( 401 );
		return new WP_Error( $e['code'], $e['message'], $e );
	}
	$url      = NPMP_BREVO_API . $path . ( $query ? '?' . http_build_query( $query, '', '&' ) : '' );
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'api-key' => $api_key,
				'accept'  => 'application/json',
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		// The transport's own message can name the URL. Ours names nothing.
		$e = npmp_brevo_error( 0 );
		return new WP_Error( $e['code'], $e['message'], $e );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );
	$body   = json_decode( $raw, true );
	if ( 200 !== $status ) {
		$said = is_array( $body ) && isset( $body['message'] ) && is_scalar( $body['message'] ) ? (string) $body['message'] : '';
		$e    = npmp_brevo_error( $status, $said, (int) wp_remote_retrieve_header( $response, 'x-sib-ratelimit-reset' ) );
		return new WP_Error( $e['code'], $e['message'], $e );
	}
	if ( ! is_array( $body ) ) {
		$e = npmp_brevo_error( 502 );
		return new WP_Error( $e['code'], $e['message'], $e );
	}
	return $body;
}

/**
 * Every contact list in the account, 50 a page.
 *
 * @param string $api_key Key.
 * @return array|WP_Error [ { id, name, member_count } ].
 */
function npmp_brevo_get_lists( $api_key ) {
	$lists  = array();
	$offset = 0;
	do {
		$body = npmp_brevo_get( $api_key, '/contacts/lists', array( 'limit' => 50, 'offset' => $offset, 'sort' => 'asc' ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$page = isset( $body['lists'] ) && is_array( $body['lists'] ) ? $body['lists'] : array();
		foreach ( $page as $list ) {
			if ( ! isset( $list['id'] ) ) {
				continue;
			}
			$lists[] = array(
				'id'           => (int) $list['id'],
				'name'         => isset( $list['name'] ) ? (string) $list['name'] : '',
				'member_count' => isset( $list['uniqueSubscribers'] ) ? (int) $list['uniqueSubscribers'] : ( isset( $list['totalSubscribers'] ) ? (int) $list['totalSubscribers'] : 0 ),
			);
		}
		$offset += count( $page );
		$total   = isset( $body['count'] ) ? (int) $body['count'] : 0;
	} while ( $page && $offset < $total && $offset < 5000 );
	return $lists;
}

/**
 * List id => name, for the Lists column.
 *
 * @param array $lists From npmp_brevo_get_lists().
 * @return array
 */
function npmp_brevo_list_names( array $lists ) {
	$out = array();
	foreach ( $lists as $list ) {
		if ( isset( $list['id'] ) ) {
			$out[ (int) $list['id'] ] = (string) ( $list['name'] ?? '' );
		}
	}
	return $out;
}

/**
 * The contact attributes worth a column: Brevo's normal and category ones.
 * Transactional, calculated, and global attributes describe orders and
 * formulas, not the person.
 *
 * @param string $api_key Key.
 * @return array|WP_Error Attribute rows as Brevo sent them.
 */
function npmp_brevo_get_attributes( $api_key ) {
	$body = npmp_brevo_get( $api_key, '/contacts/attributes' );
	if ( is_wp_error( $body ) ) {
		return $body;
	}
	return npmp_brevo_pick_attributes( isset( $body['attributes'] ) && is_array( $body['attributes'] ) ? $body['attributes'] : array() );
}

/**
 * Keep the attributes a contact can carry.
 *
 * @param array $attributes From Brevo.
 * @return array
 */
function npmp_brevo_pick_attributes( array $attributes ) {
	$out = array();
	foreach ( $attributes as $attr ) {
		$name     = isset( $attr['name'] ) ? strtoupper( trim( (string) $attr['name'] ) ) : '';
		$category = isset( $attr['category'] ) ? (string) $attr['category'] : 'normal';
		if ( '' === $name || ! in_array( $category, array( 'normal', 'category' ), true ) ) {
			continue;
		}
		$attr['name'] = $name;
		$out[ $name ] = $attr;
	}
	return array_values( $out );
}

/**
 * The custom field an attribute becomes.
 *
 * @param array $attr One attribute.
 * @return array{type:string,options:array,label:string}
 */
function npmp_brevo_attribute_shape( array $attr ) {
	$type    = isset( $attr['type'] ) ? (string) $attr['type'] : 'text';
	$label   = ucwords( strtolower( str_replace( '_', ' ', (string) ( $attr['name'] ?? '' ) ) ) );
	$options = array();
	if ( ! empty( $attr['enumeration'] ) && is_array( $attr['enumeration'] ) ) {
		foreach ( $attr['enumeration'] as $e ) {
			$opt = trim( (string) ( $e['label'] ?? ( $e['valueStr'] ?? '' ) ) );
			if ( '' !== $opt ) {
				$options[] = $opt;
			}
		}
		return array( 'type' => 'dropdown', 'options' => array_values( array_unique( $options ) ), 'label' => $label );
	}
	switch ( $type ) {
		case 'date':
			return array( 'type' => 'date', 'options' => array(), 'label' => $label );
		case 'float':
			return array( 'type' => 'number', 'options' => array(), 'label' => $label );
		case 'boolean':
			return array( 'type' => 'checkbox', 'options' => array(), 'label' => $label );
		case 'multiple-choice':
			foreach ( (array) ( $attr['multiCategoryOptions'] ?? array() ) as $opt ) {
				$opt = trim( (string) $opt );
				if ( '' !== $opt ) {
					$options[] = $opt;
				}
			}
			return array( 'type' => 'checkbox', 'options' => array_values( array_unique( $options ) ), 'label' => $label );
	}
	return array( 'type' => 'text', 'options' => array(), 'label' => $label );
}

/**
 * One attribute value as the wizard cell holds it.
 *
 * @param array $attr One attribute.
 * @param mixed $raw  The contact's value.
 * @return string|array
 */
function npmp_brevo_attribute_value( array $attr, $raw ) {
	if ( null === $raw ) {
		return '';
	}
	if ( ! empty( $attr['enumeration'] ) && is_array( $attr['enumeration'] ) && is_scalar( $raw ) ) {
		foreach ( $attr['enumeration'] as $e ) {
			if ( (string) ( $e['value'] ?? '' ) === (string) $raw || (string) ( $e['valueStr'] ?? '' ) === (string) $raw ) {
				return (string) ( $e['label'] ?? $raw );
			}
		}
		return (string) $raw;
	}
	if ( is_bool( $raw ) ) {
		return $raw ? 'yes' : 'no';
	}
	if ( is_array( $raw ) ) {
		return array_values( array_filter( array_map( 'strval', array_filter( $raw, 'is_scalar' ) ), 'strlen' ) );
	}
	return is_scalar( $raw ) ? (string) $raw : '';
}

/**
 * Brevo's answer on whether this person still takes email. A blocklisted
 * address, or one that left the list being imported, comes over as
 * unsubscribed. Brevo has no pending state on a contact, so everyone else
 * is subscribed.
 *
 * @param array $contact One contact.
 * @param int   $list_id The list being imported, 0 for every contact.
 * @return string
 */
function npmp_brevo_status( array $contact, $list_id = 0 ) {
	if ( ! empty( $contact['emailBlacklisted'] ) ) {
		return 'unsubscribed';
	}
	$left = isset( $contact['listUnsubscribed'] ) && is_array( $contact['listUnsubscribed'] ) ? array_map( 'intval', $contact['listUnsubscribed'] ) : array();
	if ( $list_id && in_array( (int) $list_id, $left, true ) ) {
		return 'unsubscribed';
	}
	return 'subscribed';
}

/**
 * The wizard's columns, in order: four of Brevo's own, then each attribute.
 *
 * @param array $attributes From npmp_brevo_pick_attributes().
 * @param bool  $groups     Whether Pro's Groups field exists.
 * @return array{headers:array,column_order:array,auto_map:array,extra_known:array}
 */
function npmp_brevo_columns( array $attributes, $groups = false ) {
	$headers      = array( __( 'Email', 'nonprofit-manager' ), __( 'Status', 'nonprofit-manager' ), __( 'Added', 'nonprofit-manager' ), __( 'Lists', 'nonprofit-manager' ) );
	$column_order = array( 'email', 'status', 'created_at', 'lists' );
	$auto_map     = array(
		0 => 'email',
		1 => 'status',
		2 => 'created_at',
		3 => $groups ? 'groups' : '',
	);
	$extra_known  = array();
	$known        = npmp_brevo_known_attributes();
	foreach ( $attributes as $attr ) {
		$name           = (string) $attr['name'];
		$headers[]      = $name;
		$column_order[] = 'attr:' . $name;
		$i              = count( $column_order ) - 1;
		$auto_map[ $i ] = isset( $known[ $name ] ) ? $known[ $name ] : '';
		if ( ! isset( $known[ $name ] ) ) {
			$extra_known[ $i ] = npmp_brevo_attribute_shape( $attr );
		}
	}
	return compact( 'headers', 'column_order', 'auto_map', 'extra_known' );
}

/**
 * One Brevo contact as a named wizard row.
 *
 * @param array $contact    One contact.
 * @param int   $list_id    The list being imported, 0 for every contact.
 * @param array $attributes From npmp_brevo_pick_attributes().
 * @param array $list_names Id => name.
 * @return array
 */
function npmp_brevo_contact_row( array $contact, $list_id, array $attributes, array $list_names ) {
	// Someone on the blocklist joins no group, the same as the MailPoet writer.
	$lists = array();
	$left  = isset( $contact['listUnsubscribed'] ) && is_array( $contact['listUnsubscribed'] ) ? array_map( 'intval', $contact['listUnsubscribed'] ) : array();
	foreach ( ( empty( $contact['emailBlacklisted'] ) ? (array) ( $contact['listIds'] ?? array() ) : array() ) as $id ) {
		$id = (int) $id;
		if ( isset( $list_names[ $id ] ) && '' !== $list_names[ $id ] && ! in_array( $id, $left, true ) ) {
			$lists[] = str_replace( ',', ' ', $list_names[ $id ] );
		}
	}
	$row   = array(
		'email'      => isset( $contact['email'] ) ? (string) $contact['email'] : '',
		'status'     => npmp_brevo_status( $contact, $list_id ),
		'created_at' => isset( $contact['createdAt'] ) ? (string) $contact['createdAt'] : '',
		'lists'      => implode( ',', $lists ),
	);
	$given = isset( $contact['attributes'] ) && is_array( $contact['attributes'] ) ? array_change_key_case( $contact['attributes'], CASE_UPPER ) : array();
	foreach ( $attributes as $attr ) {
		$name                   = (string) $attr['name'];
		$row[ 'attr:' . $name ] = npmp_brevo_attribute_value( $attr, $given[ $name ] ?? null );
	}
	return $row;
}

/**
 * A preview row in column order, for the table on the mapping screen.
 *
 * @param array $row          From npmp_brevo_contact_row().
 * @param array $column_order Keys.
 * @return array
 */
function npmp_brevo_preview_cells( array $row, array $column_order ) {
	$out = array();
	foreach ( $column_order as $key ) {
		$v     = $row[ $key ] ?? '';
		$out[] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
	}
	return $out;
}

/**
 * One page of contacts, oldest first so a new signup during the import
 * lands at the end instead of shifting the pages.
 *
 * @param string $api_key Key.
 * @param int    $list_id List, 0 for every contact.
 * @param int    $offset  First contact.
 * @param int    $limit   Up to 500.
 * @return array|WP_Error { contacts: [], count: int }
 */
function npmp_brevo_get_contacts( $api_key, $list_id, $offset, $limit ) {
	$query = array(
		'limit'  => max( 1, min( 500, (int) $limit ) ),
		'offset' => max( 0, (int) $offset ),
		'sort'   => 'asc',
	);
	$path  = $list_id ? '/contacts/lists/' . (int) $list_id . '/contacts' : '/contacts';
	$body  = npmp_brevo_get( $api_key, $path, $query );
	if ( is_wp_error( $body ) ) {
		return $body;
	}
	return array(
		'contacts' => isset( $body['contacts'] ) && is_array( $body['contacts'] ) ? array_values( $body['contacts'] ) : array(),
		'count'    => isset( $body['count'] ) ? (int) $body['count'] : 0,
	);
}

// =====================================================================
// The key between requests
// =====================================================================

/**
 * Seal the key with a fresh secret. The site keeps the box, the browser
 * keeps the secret.
 *
 * @param string $api_key Key.
 * @return array{box:string,secret:string}
 */
function npmp_brevo_seal( $api_key ) {
	$secret = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$box    = sodium_crypto_secretbox( (string) $api_key, $nonce, $secret );
	$out    = array(
		'box'    => sodium_bin2hex( $nonce . $box ),
		'secret' => sodium_bin2hex( $secret ),
	);
	sodium_memzero( $secret );
	return $out;
}

/**
 * Open the box with the browser's secret.
 *
 * @param string $box    From npmp_brevo_seal().
 * @param string $secret Hex secret from the browser.
 * @return string The key, or '' when the secret doesn't fit.
 */
function npmp_brevo_unseal( $box, $secret ) {
	$box    = (string) $box;
	$secret = (string) $secret;
	if ( ! preg_match( '/^[0-9a-f]+$/', $box ) || ! preg_match( '/^[0-9a-f]{64}$/', $secret ) ) {
		return '';
	}
	$raw = sodium_hex2bin( $box );
	if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return '';
	}
	$key   = sodium_hex2bin( $secret );
	$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
	sodium_memzero( $key );
	return false === $plain ? '' : $plain;
}

/**
 * Transient name for one import's Brevo details.
 *
 * @param string $token Job token.
 * @return string
 */
function npmp_brevo_job_key( $token ) {
	return 'npmp_import_brevo_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
}

/**
 * Save one import's Brevo details: the sealed key, the list, and the columns.
 *
 * @param string $token Job token.
 * @param array  $job   Details.
 * @return void
 */
function npmp_brevo_save_job( $token, array $job ) {
	set_transient( npmp_brevo_job_key( $token ), $job, NPMP_BREVO_JOB_TTL );
}
