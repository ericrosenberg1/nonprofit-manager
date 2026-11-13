<?php
/**
 * File path: includes/import/fluentcrm-importer.php
 *
 * Importer and Switch Plugins source for FluentCRM (folder fluent-crm). Read
 * from FluentCRM 3.3.1's own source:
 *
 * - Contacts live in {prefix}fc_subscribers: id, user_id, first_name,
 *   last_name, email (unique), status, created_at. created_at holds site-local
 *   time, since FluentCRM's models stamp current_time( 'mysql' ).
 * - status is subscribed, pending, unsubscribed, transactional, bounced,
 *   complained, or spammed (filter fluent_crm/contact_statuses).
 * - Lists ({prefix}fc_lists) and tags ({prefix}fc_tags) have an id and a title.
 *   A contact is on one when {prefix}fc_subscriber_pivot has a row with its
 *   subscriber_id, the object_id, and object_type FluentCrm\App\Models\Lists
 *   or FluentCrm\App\Models\Tag.
 * - Custom field definitions are one row in {prefix}fc_meta (object_type
 *   option, key contact_custom_fields): a serialized list of label, slug,
 *   type, and options. Values are {prefix}fc_subscriber_meta rows with
 *   object_type custom_field, key the slug, and the value (serialized for a
 *   multiple choice).
 * - Sender settings are the email_settings part of the
 *   fluentcrm-global-settings option: from_name, from_email, reply_to_email.
 *   Double opt-in is set per form and per list, so no global switch matches
 *   ours.
 * - FluentCRM ships no uninstall code and its deactivation only clears its
 *   scheduled events, so its data stays after you turn it off or delete it.
 *   Its own Reset Database tool (Settings, Experimental) drops the fc_ tables.
 *
 * Read by name and nothing else: the tables and columns above and three keys
 * of email_settings. Never read: mailer or SMTP settings, licence keys, API
 * keys, campaigns, automations, sequences, forms, or reports. A contact tied
 * to a WordPress user comes over as a contact, and the user stays as it was.
 *
 * Contacts go through the MailPoet importer's writer
 * (npmp_mailpoet_import_subscriber()) with source fluent-crm, so status
 * merging, names, signup dates, lists as groups (Pro), and the free total
 * match the other newsletter importers. Tags join the contact's Tags field
 * after the writer runs. Custom fields come over through Pro
 * (extra-fields.php).
 *
 * Data removal stays unavailable: FluentCRM Pro and FluentCRM's add-ons keep
 * their data in the same fc_ tables, and fc_meta holds both.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_FCRM_BATCH' ) ) {
	define( 'NPMP_FCRM_BATCH', 100 );
}
if ( ! defined( 'NPMP_FCRM_SECONDS' ) ) {
	define( 'NPMP_FCRM_SECONDS', 20 );
}
if ( ! defined( 'NPMP_FCRM_LIST_MODEL' ) ) {
	define( 'NPMP_FCRM_LIST_MODEL', 'FluentCrm\\App\\Models\\Lists' );
	define( 'NPMP_FCRM_TAG_MODEL', 'FluentCrm\\App\\Models\\Tag' );
}

// =====================================================================
// Pure helpers, covered by tests/test-fluentcrm-importer.php
// =====================================================================

/**
 * Status groups you can keep or leave out, key => FluentCRM statuses.
 *
 * @return array<string,string[]>
 */
function npmp_fcrm_status_groups() {
	return array(
		'subscribed'    => array( 'subscribed' ),
		'pending'       => array( 'pending' ),
		'unsubscribed'  => array( 'unsubscribed' ),
		'transactional' => array( 'transactional' ),
		'bounced'       => array( 'bounced', 'complained', 'spammed' ),
	);
}

/**
 * The group for a stored status. A status we don't know waits as pending.
 *
 * @param mixed $raw fc_subscribers.status.
 * @return string
 */
function npmp_fcrm_status_group( $raw ) {
	$raw = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
	foreach ( npmp_fcrm_status_groups() as $group => $statuses ) {
		if ( in_array( $raw, $statuses, true ) ) {
			return $group;
		}
	}
	return 'pending';
}

/**
 * The MailPoet status a FluentCRM status reads as, for the shared writer.
 *
 * Subscribed stays subscribed, pending waits as Pending, unsubscribed stays
 * unsubscribed, and bounced, complained, and spammed come over as
 * unsubscribed. Transactional means FluentCRM sends that person receipts
 * and other notices but no marketing, so the contact comes over as Inactive
 * and gets no newsletters.
 *
 * @param mixed $raw fc_subscribers.status.
 * @return string
 */
function npmp_fcrm_mailpoet_status( $raw ) {
	$map = array(
		'subscribed'    => 'subscribed',
		'pending'       => 'unconfirmed',
		'unsubscribed'  => 'unsubscribed',
		'transactional' => 'inactive',
		'bounced'       => 'bounced',
	);
	return $map[ npmp_fcrm_status_group( $raw ) ];
}

/**
 * Sender settings in the shape npmp_mailpoet_settings_plan() reads.
 *
 * @param mixed $global fluentcrm-global-settings.
 * @return array{from_name:string,from_email:string,reply_to:string,double_optin:null}
 */
function npmp_fcrm_read_settings( $global ) {
	$email = is_array( $global ) && isset( $global['email_settings'] ) && is_array( $global['email_settings'] ) ? $global['email_settings'] : array();
	$clean = static function ( $value ) {
		return is_scalar( $value ) ? trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) ) : '';
	};
	return array(
		'from_name'    => $clean( $email['from_name'] ?? '' ),
		'from_email'   => $clean( $email['from_email'] ?? '' ),
		'reply_to'     => $clean( $email['reply_to_email'] ?? '' ),
		'double_optin' => null,
	);
}

/**
 * One FluentCRM custom field as an extra field.
 *
 * @param array $field label, slug, type, options.
 * @return array{type:string,options:array,note:string}
 */
function npmp_fcrm_extra_field_shape( array $field ) {
	$options = array();
	foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
		if ( is_scalar( $option ) && '' !== trim( (string) $option ) ) {
			$options[] = trim( (string) $option );
		}
	}
	$map  = array(
		'text'         => 'text',
		'textarea'     => 'textarea',
		'number'       => 'number',
		'select-one'   => 'dropdown',
		'radio'        => 'dropdown',
		'select-multi' => 'checkbox',
		'checkbox'     => 'checkbox',
		'date'         => 'date',
	);
	$raw  = (string) ( $field['type'] ?? 'text' );
	$type = $map[ $raw ] ?? 'text';
	return array(
		'type'    => $type,
		'options' => in_array( $type, array( 'dropdown', 'checkbox' ), true ) ? $options : array(),
		'note'    => 'date_time' === $raw ? __( 'A date and time in FluentCRM, so it comes over as text.', 'nonprofit-manager' ) : '',
	);
}

/**
 * Tags after adding new ones, in the Tags field's comma-separated form.
 *
 * Letter case doesn't make a new tag, and the contact's own tags come first.
 *
 * @param string   $current Contact's tags.
 * @param string[] $add     Tags to add.
 * @return string
 */
function npmp_fcrm_merge_tags( $current, array $add ) {
	$out = array();
	foreach ( array_merge( preg_split( '/[,;]/', (string) $current ), $add ) as $tag ) {
		$tag = trim( wp_strip_all_tags( (string) $tag ) );
		$tag = str_replace( array( ',', ';' ), ' ', $tag );
		if ( '' !== $tag && ! isset( $out[ strtolower( $tag ) ] ) ) {
			$out[ strtolower( $tag ) ] = $tag;
		}
	}
	return implode( ',', $out );
}

// =====================================================================
// Reading FluentCRM's data
// =====================================================================

/**
 * A FluentCRM table name when it exists, or ''.
 *
 * @param string $name subscribers, lists, tags, subscriber_pivot, subscriber_meta, or meta.
 * @return string
 */
function npmp_fcrm_table( $name = 'subscribers' ) {
	global $wpdb;
	static $found = array();
	$table = $wpdb->prefix . 'fc_' . preg_replace( '/[^a-z_]/', '', (string) $name );
	if ( empty( $found[ $table ] ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
		$found[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
	return $found[ $table ] ? $table : '';
}

/**
 * Lists or tags, id => title.
 *
 * @param string $kind lists or tags.
 * @return array<int,string>
 */
function npmp_fcrm_names( $kind ) {
	global $wpdb;
	$table = npmp_fcrm_table( 'tags' === $kind ? 'tags' : 'lists' );
	if ( ! $table ) {
		return array();
	}
	$out = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$table} ORDER BY title ASC LIMIT %d", 1000 ) ) as $row ) {
		$title = trim( wp_strip_all_tags( (string) $row->title ) );
		if ( '' !== $title ) {
			$out[ (int) $row->id ] = $title;
		}
	}
	return $out;
}

/**
 * Contacts by status group.
 *
 * @return array<string,int>
 */
function npmp_fcrm_counts() {
	global $wpdb;
	$out   = array_fill_keys( array_keys( npmp_fcrm_status_groups() ), 0 );
	$table = npmp_fcrm_table();
	if ( ! $table ) {
		return $out;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $row ) {
		$out[ npmp_fcrm_status_group( $row['status'] ) ] += (int) $row['n'];
	}
	return $out;
}

/**
 * One batch of contacts, oldest ID first, in the MailPoet writer's shape,
 * with each contact's list and tag names.
 *
 * @param int $after Last contact ID read.
 * @param int $limit Rows.
 * @return object[] id, email, first_name, last_name, status, group, created, confirmed, lists, tags.
 */
function npmp_fcrm_read_rows( $after, $limit ) {
	global $wpdb;
	$table = npmp_fcrm_table();
	if ( ! $table ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, email, first_name, last_name, status, created_at FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after, max( 1, (int) $limit ) ) );
	if ( ! $rows ) {
		return array();
	}
	$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	$on    = array();
	$pivot = npmp_fcrm_table( 'subscriber_pivot' );
	if ( $pivot ) {
		$lists = npmp_fcrm_names( 'lists' );
		$tags  = npmp_fcrm_names( 'tags' );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table from $wpdb->prefix, placeholders built above.
		$links = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, object_id, object_type FROM {$pivot} WHERE subscriber_id IN ($in) AND object_type IN (%s, %s) ORDER BY id ASC", array_merge( $ids, array( NPMP_FCRM_LIST_MODEL, NPMP_FCRM_TAG_MODEL ) ) ) );
		foreach ( (array) $links as $link ) {
			$kind = NPMP_FCRM_LIST_MODEL === $link->object_type ? 'lists' : 'tags';
			$name = 'lists' === $kind ? ( $lists[ (int) $link->object_id ] ?? '' ) : ( $tags[ (int) $link->object_id ] ?? '' );
			if ( '' !== $name ) {
				$on[ (int) $link->subscriber_id ][ $kind ][ $name ] = $name;
			}
		}
	}
	$out = array();
	foreach ( $rows as $row ) {
		$created = (string) $row->created_at;
		// Site-local time, so read it as the site's zone.
		$ts    = ( '' !== $created && 0 !== strpos( $created, '0000' ) ) ? strtotime( get_gmt_from_date( $created ) . ' UTC' ) : 0;
		$out[] = (object) array(
			'id'         => (int) $row->id,
			'email'      => (string) $row->email,
			'first_name' => (string) $row->first_name,
			'last_name'  => (string) $row->last_name,
			'status'     => npmp_fcrm_mailpoet_status( $row->status ),
			'group'      => npmp_fcrm_status_group( $row->status ),
			'created'    => $ts ? (int) $ts : 0,
			'confirmed'  => 0,
			'lists'      => array_values( $on[ (int) $row->id ]['lists'] ?? array() ),
			'tags'       => array_values( $on[ (int) $row->id ]['tags'] ?? array() ),
		);
	}
	return $out;
}

/**
 * Sender settings next to yours.
 *
 * @return array npmp_mailpoet_settings_plan() output.
 */
function npmp_fcrm_settings_plan() {
	return npmp_mailpoet_settings_plan( npmp_fcrm_read_settings( get_option( 'fluentcrm-global-settings', array() ) ), npmp_mailpoet_current_settings() );
}

// =====================================================================
// Custom fields, offered through extra-fields.php
// =====================================================================

add_filter( 'npmp_import_extra_field_sources', 'npmp_fcrm_extra_field_source' );

/**
 * Register FluentCRM as a staged source of extra fields.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_fcrm_extra_field_source( $sources ) {
	$sources                = is_array( $sources ) ? $sources : array();
	$sources['fluent-crm'] = array(
		'name'   => 'FluentCRM',
		'fields' => 'npmp_fcrm_extra_fields',
		'values' => 'npmp_fcrm_extra_values',
	);
	return $sources;
}

/**
 * FluentCRM's contact custom fields with three sample values each.
 *
 * @return array key => array{label,type,options,samples,note}
 */
function npmp_fcrm_extra_fields() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache  = array();
	$meta   = npmp_fcrm_table( 'meta' );
	$values = npmp_fcrm_table( 'subscriber_meta' );
	if ( ! $meta || ! $values ) {
		return $cache;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix.
	$defs = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta} WHERE object_type = %s AND `key` = %s LIMIT 1", 'option', 'contact_custom_fields' ) ) );
	foreach ( is_array( $defs ) ? array_slice( $defs, 0, 200 ) : array() as $field ) {
		if ( ! is_array( $field ) || empty( $field['slug'] ) ) {
			continue;
		}
		$slug    = (string) $field['slug'];
		$samples = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT value FROM {$values} WHERE object_type = %s AND `key` = %s AND value <> '' ORDER BY subscriber_id ASC LIMIT %d", 'custom_field', $slug, 3 ) ) as $raw ) {
			$value     = maybe_unserialize( $raw );
			$samples[] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		$label                       = trim( wp_strip_all_tags( (string) ( $field['label'] ?? $slug ) ) );
		$cache[ 'fc_' . md5( $slug ) ] = array_merge(
			npmp_fcrm_extra_field_shape( $field ),
			array(
				'label'   => '' !== $label ? $label : $slug,
				'slug'    => $slug,
				'samples' => $samples,
			)
		);
	}
	// phpcs:enable
	return $cache;
}

/**
 * One contact's values for the wanted fields, read for a window of contacts
 * at a time.
 *
 * @param int   $subscriber_id FluentCRM contact ID.
 * @param array $keys          Wanted keys (fc_<md5 of slug>).
 * @return array key => value (a list for a multiple choice)
 */
function npmp_fcrm_extra_values( $subscriber_id, array $keys ) {
	static $window = array(
		'from'   => 0,
		'to'     => -1,
		'keys'   => '',
		'values' => array(),
	);
	$fields = npmp_fcrm_extra_fields();
	$slugs  = array();
	foreach ( $keys as $key ) {
		if ( isset( $fields[ $key ] ) ) {
			$slugs[ $fields[ $key ]['slug'] ] = (string) $key;
		}
	}
	$table         = npmp_fcrm_table( 'subscriber_meta' );
	$subscriber_id = (int) $subscriber_id;
	if ( ! $slugs || ! $table || $subscriber_id < 1 ) {
		return array();
	}
	ksort( $slugs );
	$sig = implode( ',', array_keys( $slugs ) );
	if ( $subscriber_id < $window['from'] || $subscriber_id > $window['to'] || $sig !== $window['keys'] ) {
		global $wpdb;
		$to   = $subscriber_id + 499;
		$in   = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$args = array_merge( array( 'custom_field', $subscriber_id, $to ), array_keys( $slugs ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table from $wpdb->prefix, placeholders built above.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, `key` AS slug, value FROM {$table} WHERE object_type = %s AND subscriber_id BETWEEN %d AND %d AND `key` IN ($in)", $args ) );
		$values = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $slugs[ $row->slug ] ) ) {
				$value = maybe_unserialize( (string) $row->value );
				$values[ (int) $row->subscriber_id ][ $slugs[ $row->slug ] ] = is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : (string) $value;
			}
		}
		$window = array(
			'from'   => $subscriber_id,
			'to'     => $to,
			'keys'   => $sig,
			'values' => $values,
		);
	}
	return $window['values'][ $subscriber_id ] ?? array();
}

// =====================================================================
// The import
// =====================================================================

/**
 * Empty results.
 *
 * @return array
 */
function npmp_fcrm_empty_results() {
	return array(
		'created'           => 0,
		'updated'           => 0,
		'unchanged'         => 0,
		'invalid'           => 0,
		'left'              => 0,
		'over_limit'        => 0,
		'cap_reached'       => false,
		'kept_unsubscribed' => 0,
		'tagged'            => 0,
		'failed'            => array(),
		'settings'          => array(),
	);
}

/**
 * Saved progress.
 *
 * @return array{cursor:int,running:bool,finished_at:int,handled:int,skip:string[],settings:string[],results:array}
 */
function npmp_fcrm_state() {
	$state = get_option( 'npmp_fcrm_import', array() );
	$state = is_array( $state ) ? $state : array();
	return array(
		'cursor'      => (int) ( $state['cursor'] ?? 0 ),
		'running'     => ! empty( $state['running'] ),
		'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
		'handled'     => (int) ( $state['handled'] ?? 0 ),
		'skip'        => array_values( array_intersect( (array) ( $state['skip'] ?? array() ), array_keys( npmp_fcrm_status_groups() ) ) ),
		'settings'    => array_values( array_map( 'strval', (array) ( $state['settings'] ?? array() ) ) ),
		'results'     => array_merge( npmp_fcrm_empty_results(), (array) ( $state['results'] ?? array() ) ),
	);
}

/**
 * Save progress.
 *
 * @param array $state State.
 * @return void
 */
function npmp_fcrm_save_state( array $state ) {
	update_option( 'npmp_fcrm_import', $state, false );
}

/**
 * Start a fresh run.
 *
 * @param string[] $skip     Status groups to leave out.
 * @param string[] $settings Settings plan keys to copy.
 * @return void
 */
function npmp_fcrm_start( array $skip, array $settings ) {
	$state             = npmp_fcrm_state();
	$state['cursor']   = 0;
	$state['running']  = true;
	$state['handled']  = 0;
	$state['skip']     = array_values( array_intersect( array_map( 'strval', $skip ), array_keys( npmp_fcrm_status_groups() ) ) );
	$state['settings'] = array_values( array_map( 'sanitize_key', $settings ) );
	$state['results']  = npmp_fcrm_empty_results();

	$state['results']['settings'] = npmp_import_apply_settings_plan( npmp_fcrm_settings_plan(), $state['settings'] );
	if ( function_exists( 'npmp_import_extra_reset_counts' ) ) {
		npmp_import_extra_reset_counts( 'fluent-crm' );
	}
	npmp_fcrm_save_state( $state );
}

/**
 * Import batches until the contacts run out or the time is up.
 *
 * @param int $seconds Time budget.
 * @param int $limit   Rows per batch.
 * @return array State after the run.
 */
function npmp_fcrm_run( $seconds = NPMP_FCRM_SECONDS, $limit = NPMP_FCRM_BATCH ) {
	// One run at a time, so a reload or a second tab can't make a contact twice.
	if ( ! npmp_mail_queue_lock( 'import_fluent-crm', 120 ) ) {
		return npmp_fcrm_state();
	}
	try {
		return npmp_fcrm_run_locked( $seconds, $limit );
	} finally {
		npmp_mail_queue_unlock( 'import_fluent-crm' );
	}
}

/**
 * The body of npmp_fcrm_run(), run while holding its lock.
 *
 * @see npmp_fcrm_run()
 * @return array State after the run.
 */
function npmp_fcrm_run_locked( $seconds = NPMP_FCRM_SECONDS, $limit = NPMP_FCRM_BATCH ) {
	$state = npmp_fcrm_state();
	if ( ! $state['running'] || ! class_exists( 'NPMP_Member_Manager' ) ) {
		return $state;
	}
	$members = NPMP_Member_Manager::get_instance();
	$max     = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;
	$skip    = array_flip( $state['skip'] );
	$start   = microtime( true );
	$done    = false;

	do {
		$rows = npmp_fcrm_read_rows( $state['cursor'], $limit );
		if ( $rows ) {
			$existing = npmp_mailpoet_find_contacts( wp_list_pluck( $rows, 'email' ) );
		}
		foreach ( $rows as $row ) {
			if ( isset( $skip[ $row->group ] ) ) {
				++$state['results']['left'];
				$state['cursor'] = $row->id;
				continue;
			}
			if ( $state['handled'] >= $max ) {
				$state['results']['cap_reached'] = true;
				$done                            = true;
				break;
			}
			$email = strtolower( sanitize_email( $row->email ) );
			$known = $existing[ $email ] ?? null;
			if ( ! $known && is_email( $email ) && function_exists( 'npmp_import_free_remaining' ) && npmp_import_free_remaining() < 1 ) {
				$state['results']['cap_reached'] = true;
				++$state['results']['over_limit'];
				$state['cursor'] = $row->id;
				continue;
			}
			$result = npmp_mailpoet_import_subscriber( $row, $known, $row->lists, 'fluent-crm' );
			if ( 'invalid' === $result['result'] ) {
				++$state['results']['invalid'];
			} elseif ( 'error' === $result['result'] ) {
				if ( count( $state['results']['failed'] ) < 50 ) {
					$state['results']['failed'][] = array(
						'email' => $row->email,
						'error' => $result['message'],
					);
				}
			} else {
				++$state['results'][ $result['result'] ];
				++$state['handled'];
				if ( ! $known ) {
					$existing[ $email ] = array(
						'id'     => (int) $result['member_id'],
						'status' => '',
					);
				}
				if ( $result['kept_unsubscribed'] ) {
					++$state['results']['kept_unsubscribed'];
				}
				if ( $row->tags && npmp_fcrm_add_tags( $members, (int) $result['member_id'], $row->tags ) ) {
					++$state['results']['tagged'];
				}
			}
			$state['cursor'] = $row->id;
		}
		if ( count( $rows ) < $limit ) {
			$done = true;
		}
		npmp_fcrm_save_state( $state );
	} while ( ! $done && ( microtime( true ) - $start ) < $seconds );

	if ( $done ) {
		$state['running']     = false;
		$state['finished_at'] = time();
		npmp_fcrm_save_state( $state );
		update_option( 'npmp_fcrm_imported', 1, true );
	}
	return $state;
}

/**
 * Add FluentCRM tags to a contact's Tags field. Tags you already gave the
 * contact stay. The write runs as an import, so Pro's automations stay quiet.
 *
 * @param NPMP_Member_Manager $members   Member manager.
 * @param int                 $member_id Contact ID.
 * @param string[]            $tags      Tag names.
 * @return bool Whether the contact gained a tag.
 */
function npmp_fcrm_add_tags( $members, $member_id, array $tags ) {
	$member = $member_id ? $members->get_member_by_id( $member_id ) : null;
	if ( ! $member ) {
		return false;
	}
	$current = (string) ( $member->tags ?? '' );
	$merged  = npmp_fcrm_merge_tags( $current, $tags );
	if ( strtolower( $merged ) === strtolower( npmp_fcrm_merge_tags( $current, array() ) ) ) {
		return false;
	}
	// As an import, so Pro's "tag added" automations send nobody an email.
	$write = static function () use ( $members, $member_id, $merged ) {
		return $members->update_member( $member_id, array( 'tags' => $merged ) );
	};
	$done  = function_exists( 'npmp_import_run_as_import' ) ? npmp_import_run_as_import( $write ) : $write();
	return ! is_wp_error( $done );
}

/**
 * Contacts the importer made.
 *
 * @return int
 */
function npmp_fcrm_count_contacts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only count.
	return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_source' AND pm.meta_value = 'fluent-crm' AND p.post_type = 'npmp_contact' AND p.post_status = 'publish'" );
}

/**
 * FluentCRM contacts whose address isn't on any contact here.
 *
 * @return int
 */
function npmp_fcrm_count_left_behind() {
	$table = npmp_fcrm_table();
	if ( ! $table ) {
		return 0;
	}
	return npmp_import_count_emails_not_on_contacts( "SELECT s.email FROM {$table} s" );
}

// =====================================================================
// Switch Plugins source
// =====================================================================

add_filter( 'npmp_import_sources', 'npmp_fcrm_register_hub_source' );

/**
 * FluentCRM, FluentCRM Pro, and installed plugins that require FluentCRM,
 * basename => name.
 *
 * @return array<string,string>
 */
function npmp_fcrm_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$requires = array_map( 'trim', explode( ',', (string) ( $data['RequiresPlugins'] ?? '' ) ) );
		if ( 'fluent-crm/fluent-crm.php' === $file || 0 === strpos( $file, 'fluentcampaign-pro/' ) || in_array( 'fluent-crm', $requires, true ) ) {
			$out[ $file ] = (string) $data['Name'];
		}
	}
	return $out;
}

/**
 * Add FluentCRM to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_fcrm_register_hub_source( $sources ) {
	$sources['fluent-crm'] = array(
		'name'              => 'FluentCRM',
		'covers'            => __( 'Contacts, signup dates, lists, tags, custom fields, and sender settings', 'nonprofit-manager' ),
		'feature'           => __( 'Contacts and newsletters', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array( __( 'Turn each list into a group', 'nonprofit-manager' ), __( 'Bring over custom fields', 'nonprofit-manager' ) ),
		'plugin_folders'    => array( 'fluent-crm', 'fluentcampaign-pro' ),
		'has_data'          => function () {
			return '' !== npmp_fcrm_table();
		},
		'is_imported'       => function () {
			return (bool) get_option( 'npmp_fcrm_imported', 0 );
		},
		'can_import'        => function () {
			return '' !== npmp_fcrm_table() ? true : __( 'You need FluentCRM\'s contact table to import, and this site doesn\'t have one. Install FluentCRM, turn it on once so it sets up its tables, and come back to this screen.', 'nonprofit-manager' );
		},
		'import_running'    => function () {
			return npmp_fcrm_state()['running'];
		},
		'import_url'        => 'npmp_fcrm_url',
		'import_summary'    => function () {
			$made = npmp_fcrm_count_contacts();
			if ( ! $made ) {
				return __( 'You brought over your FluentCRM contacts\' details and your sender settings.', 'nonprofit-manager' );
			}
			return sprintf(
				/* translators: %s: number of contacts. */
				_n( 'You have %s new contact from FluentCRM.', 'You have %s new contacts from FluentCRM.', $made, 'nonprofit-manager' ),
				number_format_i18n( $made )
			);
		},
		'counts'            => 'npmp_fcrm_hub_counts',
		'checklist'         => array(
			__( 'Open a few of your new contacts and check their names, emails, tags, and signup dates.', 'nonprofit-manager' ),
			__( 'Check the sender name and address in your email settings.', 'nonprofit-manager' ),
			__( 'Rebuild any FluentCRM automation or email sequence you still need, since we leave those in FluentCRM.', 'nonprofit-manager' ),
			__( 'Put a new signup form on every page that had a FluentCRM form.', 'nonprofit-manager' ),
			__( 'Send a test newsletter to yourself.', 'nonprofit-manager' ),
		),
		'installed_plugins' => 'npmp_fcrm_installed_plugins',
		'data_removal'      => array(
			'reason' => __( 'FluentCRM\'s developers keep the data for FluentCRM Pro and its add-ons in the same tables as FluentCRM, and we can\'t safely tell those rows apart. FluentCRM never deletes its own data, so you can leave it in place.', 'nonprofit-manager' ),
		),
	);
	return $sources;
}

/**
 * Old and new counts for the Verify step.
 *
 * @return array[]
 */
function npmp_fcrm_hub_counts() {
	$left      = npmp_fcrm_count_left_behind();
	$capped    = function_exists( 'npmp_import_is_capped' ) ? npmp_import_is_capped() : false;
	$places    = function_exists( 'npmp_import_free_remaining' ) ? npmp_import_free_remaining() : PHP_INT_MAX;
	$left_note = __( 'Contacts you chose to leave out stay in FluentCRM.', 'nonprofit-manager' );
	if ( $left && $capped && $places < $left ) {
		$left_note = sprintf(
			/* translators: %s: free import limit, such as 100. */
			__( 'In the free version you can import %s contacts in all, so the rest are still only in FluentCRM.', 'nonprofit-manager' ),
			number_format_i18n( defined( 'NPMP_IMPORT_FREE_MAX_ROWS' ) ? NPMP_IMPORT_FREE_MAX_ROWS : npmp_import_max_rows() )
		);
	}
	return array(
		array(
			'label' => __( 'Contacts', 'nonprofit-manager' ),
			'old'   => array_sum( npmp_fcrm_counts() ),
			'new'   => npmp_fcrm_count_contacts(),
			'note'  => __( 'Someone who was already your contact keeps where they first came from, so they don\'t count here.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'FluentCRM contacts not in your contacts', 'nonprofit-manager' ),
			'old'   => $left,
			'new'   => null,
			'note'  => $left ? $left_note : '',
		),
		array(
			'label' => __( 'Lists', 'nonprofit-manager' ),
			'old'   => count( npmp_fcrm_names( 'lists' ) ),
			'new'   => null,
			'note'  => npmp_mailpoet_groups_supported()
				? __( 'Each list became a group.', 'nonprofit-manager' )
				: __( 'Lists become groups with Pro.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Tags', 'nonprofit-manager' ),
			'old'   => count( npmp_fcrm_names( 'tags' ) ),
			'new'   => null,
			'note'  => __( 'Each contact\'s tags joined their Tags field.', 'nonprofit-manager' ),
		),
	);
}

// =====================================================================
// Screen
// =====================================================================

add_action( 'admin_menu', 'npmp_fcrm_register_menu', 30 );
add_action( 'admin_head', 'npmp_fcrm_hide_menu' );
add_action( 'admin_post_npmp_fcrm_import', 'npmp_fcrm_post' );
add_action( 'admin_enqueue_scripts', 'npmp_fcrm_enqueue' );

/**
 * URL of the screen.
 *
 * @return string
 */
function npmp_fcrm_url() {
	return admin_url( 'admin.php?page=npmp_fcrm_import' );
}

/**
 * Register the screen. It opens from Switch Plugins.
 *
 * @return void
 */
function npmp_fcrm_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Import from FluentCRM', 'nonprofit-manager' ),
		__( 'Import from FluentCRM', 'nonprofit-manager' ),
		'manage_options',
		'npmp_fcrm_import',
		'npmp_fcrm_render_page'
	);
}

/**
 * Take the screen out of the menu on admin_head.
 *
 * @return void
 */
function npmp_fcrm_hide_menu() {
	remove_submenu_page( 'npmp_main', 'npmp_fcrm_import' );
}

/**
 * The hub's styles and its carry-on script.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_fcrm_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_fcrm_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * Handle Import and each carry-on request.
 *
 * @return void
 */
function npmp_fcrm_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), 403 );
	}
	check_admin_referer( 'npmp_fcrm_import', 'npmp_fcrm_nonce' );
	if ( ! empty( $_POST['restart'] ) ) {
		$keep     = isset( $_POST['keep'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['keep'] ) ) : array();
		$settings = isset( $_POST['settings'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['settings'] ) ) : array();
		npmp_fcrm_start( array_diff( array_keys( npmp_fcrm_status_groups() ), $keep ), $settings );
		do_action( 'npmp_import_extra_fields_save', 'fluent-crm' );
	}
	npmp_fcrm_run();
	wp_safe_redirect( npmp_fcrm_url() );
	exit;
}

/**
 * Hidden fields for the forms.
 *
 * @return void
 */
function npmp_fcrm_form_fields() {
	echo '<input type="hidden" name="action" value="npmp_fcrm_import">';
	wp_nonce_field( 'npmp_fcrm_import', 'npmp_fcrm_nonce' );
}

/**
 * Labels for the status groups.
 *
 * @return array<string,string>
 */
function npmp_fcrm_status_labels() {
	return array(
		'subscribed'    => __( 'Subscribed', 'nonprofit-manager' ),
		'pending'       => __( 'Pending (they come over as Pending)', 'nonprofit-manager' ),
		'unsubscribed'  => __( 'Unsubscribed', 'nonprofit-manager' ),
		'transactional' => __( 'Transactional only (they come over as Inactive and get no newsletters)', 'nonprofit-manager' ),
		'bounced'       => __( 'Bounced, complained, or marked as spam (they come over as Unsubscribed)', 'nonprofit-manager' ),
	);
}

/**
 * The screen.
 *
 * @return void
 */
function npmp_fcrm_render_page() {
	npmp_verify_admin_access( 'manage_options' );
	$state = npmp_fcrm_state();
	echo '<div class="wrap npmp-hub-wrap">';
	echo '<h1>' . esc_html__( 'Import from FluentCRM', 'nonprofit-manager' ) . '</h1>';
	echo '<hr class="wp-header-end">';
	npmp_import_hub_back_link( 'fluent-crm' );

	if ( ! npmp_fcrm_table() ) {
		echo '<p>' . esc_html__( 'You need FluentCRM\'s contact table to import, and this site doesn\'t have one.', 'nonprofit-manager' ) . '</p></div>';
		return;
	}

	if ( $state['running'] ) {
		npmp_fcrm_render_results( $state['results'] );
		echo '<p>' . esc_html__( 'Importing in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-npmp-hub-continue="1">';
		npmp_fcrm_form_fields();
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form></div>';
		return;
	}

	if ( $state['finished_at'] ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Your import is done.', 'nonprofit-manager' ) . '</p></div>';
		npmp_fcrm_render_results( $state['results'] );
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_import_hub_url( 'fluent-crm' ) ) . '">' . esc_html__( 'Finish on Switch Plugins', 'nonprofit-manager' ) . '</a></p>';
	}

	$counts = npmp_fcrm_counts();
	$lists  = npmp_fcrm_names( 'lists' );
	$tags   = npmp_fcrm_names( 'tags' );
	$plan   = npmp_fcrm_settings_plan();

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_fcrm_form_fields();
	echo '<input type="hidden" name="restart" value="1">';

	echo '<h2>' . esc_html__( 'Who comes over', 'nonprofit-manager' ) . '</h2><fieldset class="npmp-hub-checklist">';
	foreach ( npmp_fcrm_status_labels() as $key => $label ) {
		printf(
			'<p><label><input type="checkbox" name="keep[]" value="%1$s"%2$s> %3$s: %4$s</label></p>',
			esc_attr( $key ),
			checked( ! in_array( $key, $state['skip'], true ), true, false ),
			esc_html( $label ),
			esc_html( number_format_i18n( $counts[ $key ] ) )
		);
	}
	echo '</fieldset>';
	echo '<ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Each FluentCRM contact becomes a contact with their name, email, and signup date, or joins the contact you already have with that email.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Someone who unsubscribed on either side stays unsubscribed.', 'nonprofit-manager' ) . '</li>';
	if ( $tags ) {
		/* translators: %s: tag names. */
		echo '<li>' . esc_html( sprintf( __( 'Each contact\'s tags join their Tags field: %s.', 'nonprofit-manager' ), implode( ', ', $tags ) ) ) . '</li>';
	}
	if ( $lists ) {
		$names = implode( ', ', $lists );
		echo '<li>' . esc_html(
			npmp_mailpoet_groups_supported()
				/* translators: %s: list names. */
				? sprintf( __( 'Each list becomes a group, and each contact joins the groups for their lists: %s.', 'nonprofit-manager' ), $names )
				/* translators: %s: list names. */
				: sprintf( __( 'We leave your lists in FluentCRM, since you need Pro for groups: %s.', 'nonprofit-manager' ), $names )
		) . '</li>';
	}
	echo '<li>' . esc_html__( 'A contact tied to a WordPress user comes over as a contact, and the user stays as it is.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'No contact gets an email.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	if ( function_exists( 'npmp_is_pro' ) && ! npmp_is_pro() && function_exists( 'npmp_import_free_remaining' ) ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: free total of new contacts, 2: places left. */
				__( 'With the free plugin, you can add up to %1$s new contacts through imports, and you have %2$s places left. A FluentCRM contact who\'s already your contact doesn\'t use a place.', 'nonprofit-manager' ),
				number_format_i18n( NPMP_IMPORT_FREE_MAX_ROWS ),
				number_format_i18n( npmp_import_free_remaining() )
			)
		) . '</p>';
	}

	do_action( 'npmp_import_extra_fields_choices', 'fluent-crm' );

	echo '<h2>' . esc_html__( 'Settings', 'nonprofit-manager' ) . '</h2>';
	$names = array(
		'from_name'  => __( 'Sender name', 'nonprofit-manager' ),
		'from_email' => __( 'Sender email', 'nonprofit-manager' ),
		'reply_to'   => __( 'Reply-to address', 'nonprofit-manager' ),
	);
	if ( ! $plan ) {
		echo '<p>' . esc_html__( 'FluentCRM has no sender settings saved.', 'nonprofit-manager' ) . '</p>';
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $plan as $key => $row ) {
		if ( ! isset( $names[ $key ] ) ) {
			continue;
		}
		$label = $names[ $key ] . ': ' . (string) $row['mailpoet'];
		if ( 'change' === $row['state'] ) {
			printf(
				'<li><label><input type="checkbox" name="settings[]" value="%1$s"%2$s> %3$s</label></li>',
				esc_attr( $key ),
				checked( $row['checked'], true, false ),
				esc_html( $label )
			);
		} elseif ( 'same' === $row['state'] ) {
			/* translators: %s: setting and value. */
			echo '<li>' . esc_html( sprintf( __( '%s (you already have the same setting)', 'nonprofit-manager' ), $label ) ) . '</li>';
		} else {
			/* translators: %s: setting and value. */
			echo '<li>' . esc_html( sprintf( __( '%s (Nonprofit Manager has no matching setting, so this one stays in FluentCRM)', 'nonprofit-manager' ), $label ) ) . '</li>';
		}
	}
	echo '<li>' . esc_html__( 'Double opt-in: FluentCRM sets double opt-in for each form and list, so we leave your own signup confirmation setting alone.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	echo '<h2>' . esc_html__( 'What you leave in the old plugin', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Campaigns, email history, and open and click reports.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Automations and funnels, email sequences, and forms.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Companies, notes, and activity on each contact.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Mailer settings, license keys, and add-on settings.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	$label = $state['finished_at'] ? __( 'Import again', 'nonprofit-manager' ) : __( 'Import contacts', 'nonprofit-manager' );
	echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p>';
	if ( $state['finished_at'] ) {
		echo '<p class="description">' . esc_html__( 'Running it again updates the contacts you have and adds anyone new.', 'nonprofit-manager' ) . '</p>';
	}
	echo '</form></div>';
}

/**
 * Results of the last run.
 *
 * @param array $r Results.
 * @return void
 */
function npmp_fcrm_render_results( array $r ) {
	$lines = array(
		/* translators: %s: number of contacts. */
		sprintf( __( 'New contacts: %s', 'nonprofit-manager' ), number_format_i18n( $r['created'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts updated: %s', 'nonprofit-manager' ), number_format_i18n( $r['updated'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts with nothing to change: %s', 'nonprofit-manager' ), number_format_i18n( $r['unchanged'] ) ),
	);
	if ( $r['tagged'] ) {
		/* translators: %s: number of contacts. */
		$lines[] = sprintf( __( 'Contacts who got new tags: %s', 'nonprofit-manager' ), number_format_i18n( $r['tagged'] ) );
	}
	if ( $r['left'] ) {
		/* translators: %s: number of contacts. */
		$lines[] = sprintf( __( 'FluentCRM contacts you left out: %s', 'nonprofit-manager' ), number_format_i18n( $r['left'] ) );
	}
	if ( $r['over_limit'] ) {
		/* translators: %s: number of contacts. */
		$lines[] = sprintf( __( 'FluentCRM contacts past the free limit: %s. Pro brings them over.', 'nonprofit-manager' ), number_format_i18n( $r['over_limit'] ) );
	}
	if ( $r['kept_unsubscribed'] ) {
		/* translators: %s: number of contacts. */
		$lines[] = sprintf( __( 'Contacts who stay unsubscribed here: %s', 'nonprofit-manager' ), number_format_i18n( $r['kept_unsubscribed'] ) );
	}
	if ( $r['invalid'] ) {
		/* translators: %s: number of contacts. */
		$lines[] = sprintf( __( 'FluentCRM contacts we skipped for a missing or broken email: %s', 'nonprofit-manager' ), number_format_i18n( $r['invalid'] ) );
	}
	if ( $r['settings'] ) {
		/* translators: %s: number of settings. */
		$lines[] = sprintf( __( 'Settings copied: %s', 'nonprofit-manager' ), number_format_i18n( count( (array) $r['settings'] ) ) );
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $lines as $line ) {
		echo '<li>' . esc_html( $line ) . '</li>';
	}
	foreach ( (array) $r['failed'] as $fail ) {
		echo '<li>' . esc_html( $fail['email'] . ': ' . $fail['error'] ) . '</li>';
	}
	echo '</ul>';
	do_action( 'npmp_import_extra_fields_results', 'fluent-crm' );
}
