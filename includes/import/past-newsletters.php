<?php
/**
 * File path: includes/import/past-newsletters.php
 *
 * Past newsletters: the part of the MailPoet and The Newsletter Plugin
 * imports that brings over what you already sent, so your history and your
 * public archive come with you.
 *
 * - It runs after contacts and lists, from its own checkbox on each
 *   importer (off by default), in batches you can stop and resume. Running
 *   it twice adds nothing twice: each newsletter carries _npmp_import_key
 *   ("mailpoet:12" or "newsletter:12").
 * - Only standard newsletters that finished sending come over. Drafts,
 *   scheduled ones, welcome and automatic emails, post notifications, and
 *   automations stay behind.
 * - It reads the old tables straight from $wpdb and never runs the old
 *   plugin's code, so it works with that plugin switched off.
 * - Each one becomes an npmp_newsletter with its subject, its send date,
 *   the status "sent", no queue rows, and no numbers (open and click stats
 *   stay behind, and we never make up a 0). It's out of the public archive
 *   until you tick it.
 * - It never sends an email. includes/email-newsletter/imported.php keeps
 *   every send path closed for an imported newsletter.
 * - Images keep their old addresses. Nothing gets downloaded.
 * - With Pro, each old list that already has a group of the same name puts
 *   the newsletter in that group (npmp_past_newsletter_imported).
 *
 * The body work (block conversion, tags, the scrub) is in
 * past-newsletters-pure.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/past-newsletters-pure.php';

if ( ! defined( 'NPMP_PAST_NL_BATCH' ) ) {
	define( 'NPMP_PAST_NL_BATCH', 20 );
}
if ( ! defined( 'NPMP_PAST_NL_SECONDS' ) ) {
	define( 'NPMP_PAST_NL_SECONDS', 8 );
}

// =====================================================================
// Sources
// =====================================================================

/**
 * The sources this part reads, slug => callbacks.
 *
 * @return array<string,array>
 */
function npmp_past_nl_sources() {
	return array(
		'mailpoet'   => array(
			'name'      => 'MailPoet',
			'available' => 'npmp_past_nl_mailpoet_available',
			'count'     => 'npmp_past_nl_mailpoet_count',
			'read'      => 'npmp_past_nl_mailpoet_read',
			'folders'   => array( 'mailpoet', 'mailpoet-premium' ),
		),
		'newsletter' => array(
			'name'      => 'The Newsletter Plugin',
			'available' => 'npmp_past_nl_tnp_available',
			'count'     => 'npmp_past_nl_tnp_count',
			'read'      => 'npmp_past_nl_tnp_read',
			'folders'   => array( 'newsletter' ),
		),
	);
}

/**
 * One source, or null.
 *
 * @param string $slug Source slug.
 * @return array|null
 */
function npmp_past_nl_source( $slug ) {
	$all = npmp_past_nl_sources();
	return $all[ $slug ] ?? null;
}

/**
 * Whether this site can hold newsletters (the Newsletters feature is on).
 *
 * @return bool
 */
function npmp_past_nl_can_write() {
	return post_type_exists( 'npmp_newsletter' );
}

/**
 * A table's full name when it exists.
 *
 * @param string $name Name after the prefix.
 * @return string
 */
function npmp_past_nl_table( $name ) {
	global $wpdb;
	$full = $wpdb->prefix . $name;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
	return $full === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ) ? $full : '';
}

/**
 * Whether a table has a column.
 *
 * @param string $table  Full table name.
 * @param string $column Column.
 * @return bool
 */
function npmp_past_nl_has_column( $table, $column ) {
	global $wpdb;
	static $cache = array();
	if ( ! isset( $cache[ $table ] ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$cache[ $table ] = array_flip( (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
	}
	return isset( $cache[ $table ][ $column ] );
}

/**
 * Placeholders for an IN () list.
 *
 * @param array  $values Values.
 * @param string $type   %d or %s.
 * @return string
 */
function npmp_past_nl_in( array $values, $type = '%d' ) {
	return implode( ',', array_fill( 0, max( 1, count( $values ) ), $type ) );
}

/**
 * A MySQL UTC datetime as a Unix time.
 *
 * @param string|null $value Datetime.
 * @return int 0 when empty.
 */
function npmp_past_nl_utc( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value || 0 === strpos( $value, '0000' ) ) {
		return 0;
	}
	$ts = strtotime( $value . ' UTC' );
	return $ts ? (int) $ts : 0;
}

/**
 * What the tag conversion needs to know about this site.
 *
 * @return array
 */
function npmp_past_nl_site_context() {
	return array(
		'site_name'        => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'home_url'         => home_url( '/' ),
		'site_url'         => site_url(),
		'site_description' => html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
	);
}

// =====================================================================
// MailPoet (wp_mailpoet_newsletters and friends)
// =====================================================================

/**
 * MailPoet's newsletters table.
 *
 * @return string
 */
function npmp_past_nl_mailpoet_table() {
	return npmp_past_nl_table( 'mailpoet_newsletters' );
}

/**
 * Whether MailPoet left newsletters to read.
 *
 * @return bool
 */
function npmp_past_nl_mailpoet_available() {
	return '' !== npmp_past_nl_mailpoet_table();
}

/**
 * The WHERE clause for newsletters that come over: standard ones that
 * finished sending and aren't in MailPoet's trash.
 *
 * @return string
 */
function npmp_past_nl_mailpoet_where() {
	return "n.type = 'standard' AND n.status = 'sent' AND n.deleted_at IS NULL";
}

/**
 * How many MailPoet newsletters come over.
 *
 * @return int
 */
function npmp_past_nl_mailpoet_count() {
	global $wpdb;
	$table = npmp_past_nl_mailpoet_table();
	if ( ! $table ) {
		return 0;
	}
	$where = npmp_past_nl_mailpoet_where();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix, fixed clause.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} n WHERE {$where}" );
}

/**
 * MailPoet newsletters every status and type has, for the Verify step.
 *
 * @return array{sent:int,other:int}
 */
function npmp_past_nl_mailpoet_split() {
	global $wpdb;
	$table = npmp_past_nl_mailpoet_table();
	if ( ! $table ) {
		return array(
			'sent'  => 0,
			'other' => 0,
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix.
	$all  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE type <> 'notification_history'" );
	$sent = npmp_past_nl_mailpoet_count();
	return array(
		'sent'  => $sent,
		'other' => max( 0, $all - $sent ),
	);
}

/**
 * Read MailPoet newsletters after an ID, ready for the writer.
 *
 * Prefers the email MailPoet stored when it sent, with its click-tracking
 * links resolved from newsletter_links. MailPoet deletes that copy after 30
 * days by default, so for older ones it falls back to the block editor's
 * saved structure (body) or, for the newer block email editor, the
 * mailpoet_email post.
 *
 * @param int $after Last old ID handled.
 * @param int $limit Rows.
 * @return array[]
 */
function npmp_past_nl_mailpoet_read( $after, $limit ) {
	global $wpdb;
	$table = npmp_past_nl_mailpoet_table();
	if ( ! $table ) {
		return array();
	}
	$where   = npmp_past_nl_mailpoet_where();
	$post_id = npmp_past_nl_has_column( $table, 'wp_post_id' ) ? ', n.wp_post_id' : ', 0 AS wp_post_id';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix, fixed clause.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT n.id, n.subject, n.preheader, n.body, n.sent_at, n.updated_at{$post_id} FROM {$table} n WHERE {$where} AND n.id > %d ORDER BY n.id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	if ( ! $rows ) {
		return array();
	}
	$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	$in  = npmp_past_nl_in( $ids );

	// The copy MailPoet stored at send time, newest completed send first.
	$rendered = array();
	$queues   = npmp_past_nl_table( 'mailpoet_sending_queues' );
	$tasks    = npmp_past_nl_table( 'mailpoet_scheduled_tasks' );
	if ( $queues && $tasks ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Tables from $wpdb->prefix, one placeholder per ID.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT q.id AS queue_id, q.newsletter_id, q.newsletter_rendered_body AS body, t.processed_at FROM {$queues} q JOIN {$tasks} t ON t.id = q.task_id WHERE q.newsletter_id IN ({$in}) AND t.status = 'completed' ORDER BY q.id DESC", $ids ), ARRAY_A );
		foreach ( (array) $found as $q ) {
			$nid = (int) $q['newsletter_id'];
			if ( isset( $rendered[ $nid ] ) && '' !== $rendered[ $nid ]['html'] ) {
				continue;
			}
			$rendered[ $nid ] = array(
				'queue_id'  => (int) $q['queue_id'],
				'html'      => npmp_past_nl_mailpoet_rendered_html( $q['body'] ),
				'processed' => npmp_past_nl_utc( $q['processed_at'] ),
			);
		}
	}

	// Click links for the stored copies.
	$maps  = array();
	$links = npmp_past_nl_table( 'mailpoet_newsletter_links' );
	$qids  = array();
	foreach ( $rendered as $r ) {
		if ( '' !== $r['html'] ) {
			$qids[] = $r['queue_id'];
		}
	}
	if ( $links && $qids ) {
		$qin = npmp_past_nl_in( $qids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table from $wpdb->prefix, one placeholder per ID.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT queue_id, hash, url FROM {$links} WHERE queue_id IN ({$qin})", $qids ), ARRAY_A ) as $l ) {
			$maps[ (int) $l['queue_id'] ][ (string) $l['hash'] ] = (string) $l['url'];
		}
	}

	// Lists each one went to.
	$lists    = array();
	$segments = npmp_past_nl_table( 'mailpoet_segments' );
	$joins    = npmp_past_nl_table( 'mailpoet_newsletter_segment' );
	if ( $segments && $joins ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Tables from $wpdb->prefix, one placeholder per ID.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ns.newsletter_id, s.name FROM {$joins} ns JOIN {$segments} s ON s.id = ns.segment_id WHERE ns.newsletter_id IN ({$in}) ORDER BY s.name", $ids ), ARRAY_A ) as $s ) {
			$lists[ (int) $s['newsletter_id'] ][] = (string) $s['name'];
		}
	}

	$out = array();
	foreach ( $rows as $row ) {
		$id     = (int) $row['id'];
		$stored = $rendered[ $id ] ?? null;
		$sent   = npmp_past_nl_utc( $row['sent_at'] );
		if ( ! $sent && $stored ) {
			$sent = $stored['processed'];
		}
		if ( ! $sent ) {
			$sent = npmp_past_nl_utc( $row['updated_at'] );
		}
		$item = array(
			'id'        => $id,
			'subject'   => (string) $row['subject'],
			'preheader' => (string) $row['preheader'],
			'sent_ts'   => $sent,
			'lists'     => array_values( array_unique( $lists[ $id ] ?? array() ) ),
			'kind'      => 'html',
			'body'      => '',
			'link_map'  => array(),
		);
		if ( $stored && '' !== $stored['html'] ) {
			$item['body']     = $stored['html'];
			$item['link_map'] = $maps[ $stored['queue_id'] ] ?? array();
		} elseif ( '' !== trim( (string) $row['body'] ) && is_array( json_decode( (string) $row['body'], true ) ) ) {
			$item['kind'] = 'mailpoet_json';
			$item['body'] = (string) $row['body'];
		} elseif ( (int) $row['wp_post_id'] > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the raw post, no filters or old plugin code.
			$item['body'] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'mailpoet_email'", (int) $row['wp_post_id'] ) );
		}
		$out[] = $item;
	}
	return $out;
}

/**
 * How many MailPoet newsletters will lose some formatting: the ones with no
 * stored copy whose saved structure holds a block we can't rebuild (latest
 * posts, products, coupons). Read 50 at a time, cached for 10 minutes.
 *
 * @return int
 */
function npmp_past_nl_mailpoet_lost_preview() {
	global $wpdb;
	$table = npmp_past_nl_mailpoet_table();
	if ( ! $table ) {
		return 0;
	}
	$key    = 'npmp_past_nl_mp_lost';
	$cached = get_transient( $key );
	$total  = npmp_past_nl_mailpoet_count();
	if ( is_array( $cached ) && (int) ( $cached['total'] ?? -1 ) === $total ) {
		return (int) $cached['lost'];
	}
	$where = npmp_past_nl_mailpoet_where();
	$after = 0;
	$lost  = 0;
	$until = microtime( true ) + 10;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix, fixed clause.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT n.id, n.body FROM {$table} n WHERE {$where} AND n.id > %d ORDER BY n.id ASC LIMIT 50", $after ), ARRAY_A );
		if ( ! $rows ) {
			break;
		}
		$ids    = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$stored = npmp_past_nl_mailpoet_stored_ids( $ids );
		foreach ( $rows as $row ) {
			$after = (int) $row['id'];
			if ( ! isset( $stored[ $after ] ) && npmp_past_nl_mailpoet_lost_types( (string) $row['body'] ) ) {
				++$lost;
			}
		}
	} while ( count( $rows ) >= 50 && microtime( true ) < $until );
	set_transient(
		$key,
		array(
			'total' => $total,
			'lost'  => $lost,
		),
		10 * MINUTE_IN_SECONDS
	);
	return $lost;
}

/**
 * Which of these newsletters still have MailPoet's stored copy.
 *
 * @param int[] $ids Newsletter IDs.
 * @return array<int,bool>
 */
function npmp_past_nl_mailpoet_stored_ids( array $ids ) {
	global $wpdb;
	$queues = npmp_past_nl_table( 'mailpoet_sending_queues' );
	if ( ! $queues || ! $ids ) {
		return array();
	}
	$in = npmp_past_nl_in( $ids );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table from $wpdb->prefix, one placeholder per ID.
	$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT newsletter_id FROM {$queues} WHERE newsletter_id IN ({$in}) AND newsletter_rendered_body IS NOT NULL AND newsletter_rendered_body <> ''", $ids ) );
	return array_fill_keys( array_map( 'intval', (array) $found ), true );
}

// =====================================================================
// The Newsletter Plugin (wp_newsletter_emails)
// =====================================================================

/**
 * The Newsletter Plugin's emails table.
 *
 * @return string
 */
function npmp_past_nl_tnp_table() {
	return npmp_past_nl_table( 'newsletter_emails' );
}

/**
 * Whether The Newsletter Plugin left emails to read.
 *
 * @return bool
 */
function npmp_past_nl_tnp_available() {
	return '' !== npmp_past_nl_tnp_table();
}

/**
 * The WHERE clause for emails that come over: regular newsletters
 * ("message") that finished sending. Automated, follow-up, and welcome
 * series use other types.
 *
 * @return string
 */
function npmp_past_nl_tnp_where() {
	return "e.type = 'message' AND e.status = 'sent'";
}

/**
 * How many Newsletter Plugin emails come over.
 *
 * @return int
 */
function npmp_past_nl_tnp_count() {
	global $wpdb;
	$table = npmp_past_nl_tnp_table();
	if ( ! $table ) {
		return 0;
	}
	$where = npmp_past_nl_tnp_where();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix, fixed clause.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} e WHERE {$where}" );
}

/**
 * Newsletter Plugin emails in every status and type, for the Verify step.
 *
 * @return array{sent:int,other:int}
 */
function npmp_past_nl_tnp_split() {
	global $wpdb;
	$table = npmp_past_nl_tnp_table();
	if ( ! $table ) {
		return array(
			'sent'  => 0,
			'other' => 0,
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix.
	$all  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$sent = npmp_past_nl_tnp_count();
	return array(
		'sent'  => $sent,
		'other' => max( 0, $all - $sent ),
	);
}

/**
 * Read Newsletter Plugin emails after an ID, ready for the writer.
 *
 * @param int $after Last old ID handled.
 * @param int $limit Rows.
 * @return array[]
 */
function npmp_past_nl_tnp_read( $after, $limit ) {
	global $wpdb;
	$table = npmp_past_nl_tnp_table();
	if ( ! $table ) {
		return array();
	}
	$where = npmp_past_nl_tnp_where();
	$pref  = npmp_past_nl_has_column( $table, 'preferences' ) ? ', e.preferences' : ", '' AS preferences";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix, fixed clause.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.subject, e.message, e.send_on, e.created, e.options{$pref} FROM {$table} e WHERE {$where} AND e.id > %d ORDER BY e.id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	$names = function_exists( 'npmp_tnp_lists' ) ? npmp_tnp_lists() : array();
	$out   = array();
	foreach ( (array) $rows as $row ) {
		$options = npmp_past_nl_tnp_options( $row['options'] );
		$lists   = array();
		foreach ( npmp_past_nl_tnp_email_lists( $options, (string) $row['preferences'] ) as $n ) {
			if ( isset( $names[ $n ] ) ) {
				$lists[] = (string) $names[ $n ];
			}
		}
		$sent  = (int) $row['send_on'];
		$out[] = array(
			'id'        => (int) $row['id'],
			'subject'   => (string) $row['subject'],
			'preheader' => is_string( $options['preheader'] ?? null ) ? $options['preheader'] : '',
			'sent_ts'   => $sent > 0 ? $sent : npmp_past_nl_utc( $row['created'] ),
			'lists'     => $lists,
			'kind'      => 'html',
			'body'      => (string) $row['message'],
			'link_map'  => array(),
		);
	}
	return $out;
}

/**
 * The company tags The Newsletter Plugin fills from its main settings. Only
 * these three keys get read.
 *
 * @return array{name:string,address:string,legal:string}
 */
function npmp_past_nl_tnp_company() {
	$main = get_option( 'newsletter_main', array() );
	$main = is_array( $main ) ? $main : array();
	return array(
		'name'    => is_string( $main['footer_title'] ?? null ) ? $main['footer_title'] : '',
		'address' => is_string( $main['footer_contact'] ?? null ) ? $main['footer_contact'] : '',
		'legal'   => is_string( $main['footer_legal'] ?? null ) ? $main['footer_legal'] : '',
	);
}

// =====================================================================
// State
// =====================================================================

/**
 * Option that holds one source's run.
 *
 * @param string $slug Source slug.
 * @return string
 */
function npmp_past_nl_state_option( $slug ) {
	return 'npmp_past_nl_' . sanitize_key( $slug );
}

/**
 * Empty results.
 *
 * @return array
 */
function npmp_past_nl_empty_results() {
	return array(
		'created'  => 0,
		'existing' => 0,
		'lost'     => 0,
		'groups'   => 0,
		'failed'   => array(),
	);
}

/**
 * One source's run.
 *
 * @param string $slug Source slug.
 * @return array{chosen:bool,cursor:int,done:bool,finished_at:int,total:int,position:int,results:array}
 */
function npmp_past_nl_state( $slug ) {
	$raw = get_option( npmp_past_nl_state_option( $slug ), array() );
	$raw = is_array( $raw ) ? $raw : array();
	return array(
		'chosen'      => ! empty( $raw['chosen'] ),
		'cursor'      => (int) ( $raw['cursor'] ?? 0 ),
		'done'        => ! empty( $raw['done'] ),
		'finished_at' => (int) ( $raw['finished_at'] ?? 0 ),
		'total'       => (int) ( $raw['total'] ?? 0 ),
		'position'    => (int) ( $raw['position'] ?? 0 ),
		'results'     => array_merge( npmp_past_nl_empty_results(), is_array( $raw['results'] ?? null ) ? $raw['results'] : array() ),
	);
}

/**
 * Save one source's run.
 *
 * @param string $slug  Source slug.
 * @param array  $state State.
 * @return void
 */
function npmp_past_nl_save_state( $slug, array $state ) {
	update_option( npmp_past_nl_state_option( $slug ), $state, false );
}

/**
 * Record the owner's choice and start over from the first newsletter.
 * Starting over is safe: the import key skips any already here.
 *
 * @param string $slug Source slug.
 * @param bool   $on   Whether to bring newsletters over.
 * @return void
 */
function npmp_past_nl_choose( $slug, $on ) {
	$source = npmp_past_nl_source( $slug );
	if ( ! $source ) {
		return;
	}
	$state                = npmp_past_nl_state( $slug );
	$state['chosen']      = (bool) $on;
	$state['cursor']      = 0;
	$state['position']    = 0;
	$state['done']        = ! $on;
	$state['finished_at'] = 0;
	$state['total']       = $on ? (int) npmp_import_hub_call( $source['count'], 0 ) : 0;
	$state['results']     = npmp_past_nl_empty_results();
	npmp_past_nl_save_state( $slug, $state );
}

/**
 * How many newsletters from a source are here.
 *
 * @param string $slug Source slug.
 * @return int
 */
function npmp_past_nl_count_imported( $slug ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count by import mark.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_npmp_import_source' AND m.meta_value = %s AND p.post_type = 'npmp_newsletter' AND p.post_status <> 'trash'", $slug ) );
}

/**
 * How many imported newsletters from a source are public.
 *
 * @param string $slug Source slug.
 * @return int
 */
function npmp_past_nl_count_public( $slug ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count by import mark and the public flag.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->postmeta} pub ON pub.post_id = m.post_id AND pub.meta_key = %s AND pub.meta_value = '1' WHERE m.meta_key = '_npmp_import_source' AND m.meta_value = %s", defined( 'NPMP_NEWSLETTER_PUBLIC_META' ) ? NPMP_NEWSLETTER_PUBLIC_META : '_npmp_newsletter_public', $slug ) );
}

// =====================================================================
// The run
// =====================================================================

/**
 * Import key for one old newsletter.
 *
 * @param string $slug Source slug.
 * @param int    $id   Old ID.
 * @return string
 */
function npmp_past_nl_key( $slug, $id ) {
	return $slug . ':' . (int) $id;
}

/**
 * Which of these import keys are already here.
 *
 * @param string[] $keys Keys.
 * @return array<string,int> Key => post ID.
 */
function npmp_past_nl_existing( array $keys ) {
	global $wpdb;
	if ( ! $keys ) {
		return array();
	}
	$in = npmp_past_nl_in( $keys, '%s' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder per key.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS k, m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_npmp_import_key' AND p.post_type = 'npmp_newsletter' AND m.meta_value IN ({$in})", $keys ), ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ (string) $row['k'] ] = (int) $row['post_id'];
	}
	return $out;
}

/**
 * Build the post body and title for one old newsletter.
 *
 * @param string $slug Source slug.
 * @param array  $row  From the source's reader.
 * @param array  $site npmp_past_nl_site_context(), plus company for TNP.
 * @return array{title:string,preheader:string,content:string,lost:bool}
 */
function npmp_past_nl_build( $slug, array $row, array $site ) {
	$source = npmp_past_nl_source( $slug );
	$ts     = (int) $row['sent_ts'];
	$ctx    = $site + array(
		'subject'     => '',
		'timestamp'   => $ts,
		'date_values' => npmp_past_nl_date_values( $ts ),
	);
	$lost   = false;
	$body   = (string) $row['body'];

	if ( 'mailpoet' === $slug ) {
		$subject        = npmp_past_nl_mailpoet_tags( npmp_past_nl_mailpoet_block_tags( (string) $row['subject'] ), $ctx );
		$ctx['subject'] = npmp_past_nl_plain_subject( $subject );
		if ( 'mailpoet_json' === $row['kind'] ) {
			$built = npmp_past_nl_mailpoet_render( $body, $site );
			$body  = $built['html'];
			$lost  = ! empty( $built['lost'] );
		}
		$body = npmp_past_nl_mailpoet_tags( npmp_past_nl_mailpoet_block_tags( $body ), $ctx );
	} else {
		$subject        = npmp_past_nl_tnp_tags( (string) $row['subject'], $ctx );
		$ctx['subject'] = npmp_past_nl_plain_subject( $subject );
		$body           = npmp_past_nl_tnp_tags( $body, $ctx );
	}
	$body  = npmp_past_nl_drop_plugin_shortcodes( $body );
	$clean = npmp_past_nl_sanitize(
		$body,
		array(
			'link_map'       => (array) $row['link_map'],
			'plugin_folders' => $source ? $source['folders'] : array(),
			'site_url'       => $site['site_url'] ?? '',
		)
	);
	$title     = '' !== $ctx['subject'] ? $ctx['subject'] : __( '(no subject)', 'nonprofit-manager' );
	$preheader = (string) $row['preheader'];
	$preheader = 'mailpoet' === $slug ? npmp_past_nl_mailpoet_tags( npmp_past_nl_mailpoet_block_tags( $preheader ), $ctx ) : npmp_past_nl_tnp_tags( $preheader, $ctx );
	return array(
		'title'     => $title,
		'preheader' => npmp_past_nl_plain_subject( $preheader ),
		'content' => npmp_past_nl_wrap_block( $clean['html'] ),
		'lost'    => $lost,
	);
}

/**
 * Write one old newsletter as an npmp_newsletter.
 *
 * @param string $slug Source slug.
 * @param array  $row  From the source's reader.
 * @param array  $site Site context.
 * @return array{status:string,post_id:int,lost:bool,groups:int,error:string}
 */
function npmp_past_nl_write( $slug, array $row, array $site ) {
	$built = npmp_past_nl_build( $slug, $row, $site );
	$ts    = (int) $row['sent_ts'] > 0 ? (int) $row['sent_ts'] : time();
	$gmt   = gmdate( 'Y-m-d H:i:s', $ts );
	$local = get_date_from_gmt( $gmt );
	$out   = array(
		'status'  => 'failed',
		'post_id' => 0,
		'lost'    => $built['lost'],
		'groups'  => 0,
		'error'   => '',
	);
	if ( '' === trim( wp_strip_all_tags( $built['content'] ) ) && false === stripos( $built['content'], '<img' ) ) {
		$out['error'] = __( 'You\'d get an empty newsletter, since nothing was left in its content.', 'nonprofit-manager' );
		return $out;
	}

	$source  = npmp_past_nl_source( $slug );
	$name    = $source ? $source['name'] : $slug;
	$lists   = array_values( array_filter( array_map( 'strval', (array) $row['lists'] ) ) );
	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'     => 'npmp_newsletter',
				'post_status'   => 'publish',
				'post_title'    => $built['title'],
				'post_content'  => $built['content'],
				'post_date'     => $local,
				'post_date_gmt' => $gmt,
				'post_author'   => get_current_user_id(),
				'meta_input'    => array(
					'_npmp_import_key'                => npmp_past_nl_key( $slug, $row['id'] ),
					'_npmp_import_source'             => $slug,
					'_npmp_import_old_id'             => (int) $row['id'],
					'_npmp_newsletter_status'         => 'sent',
					'_npmp_newsletter_queued_at'      => $local,
					'_npmp_newsletter_template'       => 'none',
					'_npmp_newsletter_preheader'      => sanitize_textarea_field( $built['preheader'] ),
					'_npmp_newsletter_audience_label' => $lists
						/* translators: 1: plugin name, such as MailPoet, 2: list names. */
						? sprintf( __( '%1$s lists: %2$s', 'nonprofit-manager' ), $name, implode( ', ', $lists ) )
						/* translators: %s: plugin name, such as MailPoet. */
						: sprintf( __( 'Sent from %s', 'nonprofit-manager' ), $name ),
					'_npmp_import_lost_formatting'    => $built['lost'] ? '1' : '',
				),
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		$out['error'] = is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'WordPress couldn\'t save the newsletter.', 'nonprofit-manager' );
		return $out;
	}

	/**
	 * Fires after a past newsletter comes over. Pro puts it in the groups
	 * that match its old lists and returns how many through
	 * npmp_past_newsletter_groups.
	 *
	 * @param int      $post_id New newsletter.
	 * @param string[] $lists   Names of the old lists it went to.
	 * @param string   $slug    Source slug.
	 */
	do_action( 'npmp_past_newsletter_imported', (int) $post_id, $lists, $slug );

	$out['status']  = 'created';
	$out['post_id'] = (int) $post_id;
	$groups         = get_post_meta( $post_id, '_npmp_newsletter_groups', true );
	$out['groups']  = is_array( $groups ) ? count( $groups ) : 0;
	return $out;
}

/**
 * Bring over newsletters for a while. Safe to call again and again.
 *
 * @param string $slug    Source slug.
 * @param int    $seconds Time budget.
 * @param int    $limit   Newsletters per read.
 * @return array{position:int,total:int,done:bool,busy?:bool}
 */
function npmp_past_nl_run( $slug, $seconds = NPMP_PAST_NL_SECONDS, $limit = NPMP_PAST_NL_BATCH ) {
	$state = npmp_past_nl_state( $slug );
	$out   = static function ( array $s, $busy = false ) {
		$r = array(
			'position' => $s['position'],
			'total'    => $s['total'],
			'done'     => $s['done'],
		);
		if ( $busy ) {
			$r['busy'] = true;
		}
		return $r;
	};
	$source = npmp_past_nl_source( $slug );
	if ( ! $source || ! $state['chosen'] || $state['done'] ) {
		return $out( $state );
	}
	if ( ! npmp_past_nl_can_write() ) {
		$state['done']                 = true;
		$state['finished_at']          = time();
		$state['results']['failed'][]  = array(
			'id'    => 0,
			'error' => __( 'Turn on Newsletters in Nonprofit Manager\'s settings first.', 'nonprofit-manager' ),
		);
		npmp_past_nl_save_state( $slug, $state );
		return $out( $state );
	}
	if ( function_exists( 'npmp_mail_queue_lock' ) && ! npmp_mail_queue_lock( 'import_past_' . $slug, 120 ) ) {
		return $out( $state, true );
	}
	try {
		$site = npmp_past_nl_site_context();
		if ( 'newsletter' === $slug ) {
			$site['company'] = npmp_past_nl_tnp_company();
		}
		$until = microtime( true ) + max( 1, (int) $seconds );
		do {
			$state = npmp_past_nl_state( $slug );
			$rows  = (array) call_user_func( $source['read'], $state['cursor'], $limit );
			if ( ! $rows ) {
				$state['done']        = true;
				$state['finished_at'] = time();
				$state['position']    = $state['total'];
				npmp_past_nl_save_state( $slug, $state );
				break;
			}
			$keys = array();
			foreach ( $rows as $row ) {
				$keys[] = npmp_past_nl_key( $slug, $row['id'] );
			}
			$have = npmp_past_nl_existing( $keys );
			foreach ( $rows as $row ) {
				$state['cursor'] = max( $state['cursor'], (int) $row['id'] );
				++$state['position'];
				if ( isset( $have[ npmp_past_nl_key( $slug, $row['id'] ) ] ) ) {
					++$state['results']['existing'];
					continue;
				}
				$done = npmp_past_nl_write( $slug, $row, $site );
				if ( 'created' === $done['status'] ) {
					++$state['results']['created'];
					$state['results']['lost']   += $done['lost'] ? 1 : 0;
					$state['results']['groups'] += $done['groups'] ? 1 : 0;
				} elseif ( count( $state['results']['failed'] ) < 50 ) {
					$state['results']['failed'][] = array(
						'id'      => (int) $row['id'],
						'subject' => npmp_past_nl_plain_subject( wp_strip_all_tags( (string) $row['subject'] ) ),
						'error'   => $done['error'],
					);
				}
			}
			$state['total'] = max( $state['total'], $state['position'] );
			npmp_past_nl_save_state( $slug, $state );
		} while ( microtime( true ) < $until );
	} finally {
		if ( function_exists( 'npmp_mail_queue_unlock' ) ) {
			npmp_mail_queue_unlock( 'import_past_' . $slug );
		}
	}
	return $out( npmp_past_nl_state( $slug ) );
}

// =====================================================================
// Screens: the choice, the runner, the results
// =====================================================================

add_action( 'npmp_import_approve_parts', 'npmp_past_nl_render_choice' );
add_action( 'npmp_import_after_migrate', 'npmp_past_nl_render_after' );
add_action( 'admin_post_npmp_mailpoet_approve', 'npmp_past_nl_capture_mailpoet', 5 );
add_action( 'admin_post_npmp_tnp_import', 'npmp_past_nl_capture_tnp', 5 );
add_action( 'wp_ajax_npmp_past_newsletters_mailpoet', 'npmp_past_nl_ajax' );
add_action( 'wp_ajax_npmp_past_newsletters_newsletter', 'npmp_past_nl_ajax' );
add_action( 'admin_post_npmp_past_newsletters', 'npmp_past_nl_post' );
add_action( 'admin_enqueue_scripts', 'npmp_past_nl_enqueue' );
add_filter( 'npmp_import_sources', 'npmp_past_nl_hub_sources', 50 );

/**
 * The batch runner's script on The Newsletter Plugin's screen. MailPoet's
 * screen loads it already.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_past_nl_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_tnp_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-tec-import.js' ) : '1';
	wp_enqueue_style( 'npmp-tec-import', $base . 'assets/css/npmp-tec-import.css', array(), $ver );
	wp_enqueue_script( 'npmp-tec-import', $base . 'assets/js/npmp-tec-import.js', array(), $ver, true );
}

/**
 * The "Past newsletters" checkbox on each importer's approve form.
 *
 * @param string $slug Source slug.
 * @return void
 */
function npmp_past_nl_render_choice( $slug ) {
	$source = npmp_past_nl_source( $slug );
	if ( ! $source || ! npmp_import_hub_call( $source['available'], false ) ) {
		return;
	}
	$count = (int) npmp_import_hub_call( $source['count'], 0 );
	$state = npmp_past_nl_state( $slug );
	echo '<h2>' . esc_html__( 'Past newsletters', 'nonprofit-manager' ) . '</h2>';
	if ( ! $count ) {
		/* translators: %s: plugin name, such as MailPoet. */
		echo '<p>' . esc_html( sprintf( __( 'You have no sent newsletters in %s.', 'nonprofit-manager' ), $source['name'] ) ) . '</p>';
		return;
	}
	if ( ! npmp_past_nl_can_write() ) {
		echo '<p>' . esc_html__( 'To bring your sent newsletters, turn on Newsletters in Nonprofit Manager\'s settings.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	printf(
		'<p><label><input type="checkbox" name="npmp_past_newsletters" value="1"%1$s> %2$s</label></p>',
		checked( $state['chosen'] && $state['done'] ? false : $state['chosen'], true, false ),
		esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( 'Bring over %s newsletter you already sent', 'Bring over %s newsletters you already sent', $count, 'nonprofit-manager' ),
				number_format_i18n( $count )
			)
		)
	);
	echo '<ul class="ul-disc">';
	echo '<li>' . esc_html__( 'Each one keeps its subject, its send date, and its content, and nobody gets an email.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'They stay out of your public archive until you pick which ones to show.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'You leave open and click numbers, drafts, scheduled newsletters, and automatic emails in the old plugin.', 'nonprofit-manager' ) . '</li>';
	if ( function_exists( 'npmp_mailpoet_groups_supported' ) && npmp_mailpoet_groups_supported() ) {
		echo '<li>' . esc_html__( 'Each newsletter joins the groups named like the lists you sent it to in the old plugin.', 'nonprofit-manager' ) . '</li>';
	}
	echo '<li>' . esc_html__( 'You keep each image at its old address, so if you delete an image later, readers see a gap in the newsletter.', 'nonprofit-manager' ) . '</li>';
	if ( 'mailpoet' === $slug ) {
		$lost = npmp_past_nl_mailpoet_lost_preview();
		if ( $lost ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: %s: number of newsletters. */
					_n( '%s newsletter loses some formatting, since it held latest posts, products, or coupons. You keep its text.', '%s newsletters lose some formatting, since they held latest posts, products, or coupons. You keep their text.', $lost, 'nonprofit-manager' ),
					number_format_i18n( $lost )
				)
			) . '</li>';
		}
	}
	echo '</ul>';
}

/**
 * Save the checkbox from the MailPoet approve form, before MailPoet's
 * handler redirects.
 *
 * @return void
 */
function npmp_past_nl_capture_mailpoet() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'npmp_mailpoet_import', 'npmp_mailpoet_nonce' );
	npmp_past_nl_choose( 'mailpoet', ! empty( $_POST['npmp_past_newsletters'] ) );
}

/**
 * Save the checkbox from The Newsletter Plugin's form. Only the first post
 * of a run carries restart, and only that one holds the choice.
 *
 * @return void
 */
function npmp_past_nl_capture_tnp() {
	if ( ! current_user_can( 'manage_options' ) || empty( $_POST['restart'] ) ) {
		return;
	}
	check_admin_referer( 'npmp_tnp_import', 'npmp_tnp_nonce' );
	npmp_past_nl_choose( 'newsletter', ! empty( $_POST['npmp_past_newsletters'] ) );
}

/**
 * Where to come back to after a run.
 *
 * @param string $slug Source slug.
 * @return string
 */
function npmp_past_nl_return_url( $slug ) {
	if ( 'mailpoet' === $slug && function_exists( 'npmp_mailpoet_page_url' ) ) {
		return npmp_mailpoet_page_url( 4 );
	}
	return function_exists( 'npmp_tnp_url' ) ? npmp_tnp_url() : admin_url();
}

/**
 * AJAX: one stretch of the run.
 *
 * @return void
 */
function npmp_past_nl_ajax() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ) ), 403 );
	}
	check_ajax_referer( 'npmp_past_newsletters', 'npmp_past_nonce' );
	// The runner script sends only the action, so the source rides in its name.
	$slug = substr( sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ), strlen( 'npmp_past_newsletters_' ) );
	if ( ! npmp_past_nl_source( $slug ) ) {
		wp_send_json_error( array( 'message' => __( 'That importer doesn\'t exist.', 'nonprofit-manager' ) ) );
	}
	wp_send_json_success( npmp_past_nl_run( $slug ) );
}

/**
 * Form: the run without JavaScript.
 *
 * @return void
 */
function npmp_past_nl_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'npmp_past_newsletters', 'npmp_past_nonce' );
	$slug = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
	if ( npmp_past_nl_source( $slug ) ) {
		npmp_past_nl_run( $slug, 20 );
	}
	wp_safe_redirect( npmp_past_nl_return_url( $slug ) );
	exit;
}

/**
 * The runner, or what came over, under each importer's results.
 *
 * @param string $slug Source slug.
 * @return void
 */
function npmp_past_nl_render_after( $slug ) {
	$source = npmp_past_nl_source( $slug );
	$state  = npmp_past_nl_state( $slug );
	if ( ! $source || ! $state['chosen'] ) {
		return;
	}
	echo '<h2>' . esc_html__( 'Past newsletters', 'nonprofit-manager' ) . '</h2>';
	if ( ! $state['done'] ) {
		echo '<p>' . esc_html__( 'Keep this page open while we copy your sent newsletters. Nobody gets an email.', 'nonprofit-manager' ) . '</p>';
		$nonce = wp_create_nonce( 'npmp_past_newsletters' );
		printf(
			'<div class="npmp-tec-runner" data-ajax-url="%1$s" data-action="npmp_past_newsletters_%2$s" data-nonce="%3$s" data-nonce-name="npmp_past_nonce" data-next="%4$s" data-autostart="1" data-restart="0" data-label-progress="%5$s" data-label-error="%6$s">',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( $slug ),
			esc_attr( $nonce ),
			esc_url( npmp_past_nl_return_url( $slug ) ),
			/* translators: 1: newsletters done so far, 2: total newsletters. Keep the placeholders. */
			esc_attr__( '%1$s of %2$s newsletters', 'nonprofit-manager' ),
			esc_attr__( 'Something went wrong. Reload the page to pick up where it stopped.', 'nonprofit-manager' )
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-tec-runner-form">';
		echo '<input type="hidden" name="action" value="npmp_past_newsletters"><input type="hidden" name="source" value="' . esc_attr( $slug ) . '">';
		wp_nonce_field( 'npmp_past_newsletters', 'npmp_past_nonce' );
		echo '<button type="submit" class="button button-primary npmp-tec-runner-start">' . esc_html__( 'Bring over my newsletters', 'nonprofit-manager' ) . '</button></form>';
		echo '<div class="npmp-tec-bar" hidden><div class="npmp-tec-bar-fill"></div></div>';
		echo '<p class="npmp-tec-bar-text" aria-live="polite"></p></div>';
		return;
	}
	npmp_past_nl_render_results( $slug, $state['results'] );
}

/**
 * What a finished run brought over, and how to make them public.
 *
 * @param string $slug Source slug.
 * @param array  $r    Results.
 * @return void
 */
function npmp_past_nl_render_results( $slug, array $r ) {
	$here = npmp_past_nl_count_imported( $slug );
	echo '<ul class="ul-disc">';
	echo '<li>' . esc_html(
		sprintf(
			/* translators: %s: number of newsletters. */
			_n( 'You brought over %s sent newsletter.', 'You brought over %s sent newsletters.', (int) $r['created'], 'nonprofit-manager' ),
			number_format_i18n( (int) $r['created'] )
		)
	) . '</li>';
	if ( $r['existing'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( 'You already had %s from an earlier import, so we left that one as it was.', 'You already had %s from an earlier import, so we left those as they were.', (int) $r['existing'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['existing'] )
			)
		) . '</li>';
	}
	if ( $r['lost'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( '%s lost some formatting, since it held latest posts, products, or coupons. You kept its text.', '%s lost some formatting, since they held latest posts, products, or coupons. You kept their text.', (int) $r['lost'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['lost'] )
			)
		) . '</li>';
	}
	if ( $r['groups'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( '%s went into the groups that match its old lists.', '%s went into the groups that match their old lists.', (int) $r['groups'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['groups'] )
			)
		) . '</li>';
	}
	echo '</ul>';
	if ( $r['failed'] ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'We couldn\'t copy these newsletters:', 'nonprofit-manager' ) . '</p><ul class="ul-disc">';
		foreach ( (array) $r['failed'] as $fail ) {
			echo '<li>' . esc_html( trim( ( $fail['subject'] ?? '' ) . ': ' . ( $fail['error'] ?? '' ), ': ' ) ) . '</li>';
		}
		echo '</ul></div>';
	}
	if ( $here ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of newsletters. */
				_n( 'Your %s imported newsletter is out of the public archive for now. To show the newsletter, open Newsletter Archive, tick its box, and pick "Show in the public archive" from Bulk actions.', 'Your %s imported newsletters are out of the public archive for now. To show them, open Newsletter Archive, tick the ones you want, and pick "Show in the public archive" from Bulk actions.', $here, 'nonprofit-manager' ),
				number_format_i18n( $here )
			)
		) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=npmp_newsletter_archive' ) ) . '">' . esc_html__( 'Open Newsletter Archive', 'nonprofit-manager' ) . '</a></p>';
	}
}

// =====================================================================
// Switch Plugins: counts, summary, checklist, and what Remove data deletes
// =====================================================================

/**
 * Add the newsletters to the MailPoet and The Newsletter Plugin sources.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_past_nl_hub_sources( $sources ) {
	foreach ( array( 'mailpoet', 'newsletter' ) as $slug ) {
		if ( empty( $sources[ $slug ] ) || ! is_array( $sources[ $slug ] ) ) {
			continue;
		}
		$src    = $sources[ $slug ];
		$counts = $src['counts'] ?? null;
		$sum    = $src['import_summary'] ?? null;

		$src['covers'] = __( 'Subscribers, signup dates, lists, sender settings, and sent newsletters', 'nonprofit-manager' );

		$src['counts'] = static function () use ( $counts, $slug ) {
			$rows = (array) npmp_import_hub_call( $counts, array() );
			$row  = npmp_past_nl_count_row( $slug );
			if ( $row ) {
				$rows[] = $row;
			}
			return $rows;
		};

		$src['import_summary'] = static function () use ( $sum, $slug ) {
			$text = (string) npmp_import_hub_call( $sum, '' );
			$made = npmp_past_nl_count_imported( $slug );
			if ( $made ) {
				$text = trim(
					$text . ' ' . sprintf(
						/* translators: %s: number of newsletters. */
						_n( 'You also brought over %s sent newsletter.', 'You also brought over %s sent newsletters.', $made, 'nonprofit-manager' ),
						number_format_i18n( $made )
					)
				);
			}
			return $text;
		};

		$src['checklist']   = (array) ( $src['checklist'] ?? array() );
		$src['checklist'][] = __( 'Open a few of your past newsletters and check that they look right, then pick the ones to show in your public archive.', 'nonprofit-manager' );

		if ( isset( $src['data_removal']['describe'] ) ) {
			$describe                         = $src['data_removal']['describe'];
			$src['data_removal']['describe'] = static function () use ( $describe, $slug ) {
				$lines = (array) npmp_import_hub_call( $describe, array() );
				$left  = npmp_past_nl_count_left_behind( $slug );
				if ( $left ) {
					$lines[] = sprintf(
						/* translators: %s: number of newsletters. */
						_n( '%s sent newsletter you never brought over, which you lose for good', '%s sent newsletters you never brought over, which you lose for good', $left, 'nonprofit-manager' ),
						number_format_i18n( $left )
					);
				}
				return $lines;
			};
		}
		$sources[ $slug ] = $src;
	}
	return $sources;
}

/**
 * Sent newsletters in the old plugin that aren't here.
 *
 * @param string $slug Source slug.
 * @return int
 */
function npmp_past_nl_count_left_behind( $slug ) {
	$source = npmp_past_nl_source( $slug );
	if ( ! $source || ! npmp_import_hub_call( $source['available'], false ) ) {
		return 0;
	}
	return max( 0, (int) npmp_import_hub_call( $source['count'], 0 ) - npmp_past_nl_count_imported( $slug ) );
}

/**
 * The Verify step's row for newsletters.
 *
 * @param string $slug Source slug.
 * @return array|null
 */
function npmp_past_nl_count_row( $slug ) {
	$source = npmp_past_nl_source( $slug );
	if ( ! $source || ! npmp_import_hub_call( $source['available'], false ) ) {
		return null;
	}
	$split = 'mailpoet' === $slug ? npmp_past_nl_mailpoet_split() : npmp_past_nl_tnp_split();
	$here  = npmp_past_nl_count_imported( $slug );
	$note  = $split['other']
		? sprintf(
			/* translators: %s: number of emails. */
			_n( 'You leave %s draft, scheduled, or automatic email in the old plugin.', 'You leave %s drafts, scheduled, and automatic emails in the old plugin.', $split['other'], 'nonprofit-manager' ),
			number_format_i18n( $split['other'] )
		)
		: '';
	if ( ! $here && $split['sent'] ) {
		$note = trim( __( 'Run the import again and tick "Past newsletters" to bring your sent newsletters.', 'nonprofit-manager' ) . ' ' . $note );
	}
	return array(
		'label' => __( 'Sent newsletters', 'nonprofit-manager' ),
		'old'   => $split['sent'],
		'new'   => $here,
		'note'  => $note,
	);
}
