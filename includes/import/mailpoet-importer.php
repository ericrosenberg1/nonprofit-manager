<?php
/**
 * File path: includes/import/mailpoet-importer.php
 *
 * Importer for sites switching from MailPoet.
 *
 * Five screens: 1. Import counts every MailPoet subscriber, list, and sender
 * setting into a per-user staging summary, 2. Review shows what each status
 * becomes with a checkbox per status and per list, 3. Approve confirms what
 * moves and which settings change, 4. Migrate creates or updates the contacts,
 * and 5. Deactivate lists the pages that still hold a MailPoet form and turns
 * MailPoet and its add-ons off. Reading and migrating run in batches of 100
 * over admin-ajax so a site with 50,000 subscribers never times out.
 *
 * Everything comes straight from MailPoet's own tables ({prefix}mailpoet_*),
 * so the importer works whether MailPoet is on or already off. MailPoet's data
 * is never changed or deleted, so you can switch MailPoet back on.
 *
 * Schema read from MailPoet 5.45.2 (lib/Migrations/Db/Migration_20221028_105818.php):
 * subscribers (email, first_name, last_name, status, created_at, confirmed_at,
 * deleted_at), segments (name, type, deleted_at), subscriber_segment
 * (subscriber_id, segment_id, status), settings (name, value). MailPoet writes
 * its timestamps in UTC into TIMESTAMP columns, so the importer reads them with
 * UNIX_TIMESTAMP() and never depends on the database session's time zone.
 *
 * Lists become groups through the hook the CSV import already fires,
 * npmp_import_row_imported, with the list names in $record['groups']. Pro's
 * groups listen there. Without Pro the contacts still come over.
 *
 * Loaded with the members module (nonprofit-manager.php), since contacts are
 * its data.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Subscribers read or migrated per request. */
if ( ! defined( 'NPMP_MAILPOET_BATCH' ) ) {
	define( 'NPMP_MAILPOET_BATCH', 100 );
}

// =====================================================================
// Pure helpers (no database), covered by tests/test-mailpoet-importer.php
// =====================================================================

/**
 * MailPoet's subscriber statuses (SubscriberEntity::STATUS_*), plus 'other'
 * for a value this importer doesn't know, in the order the review shows them.
 *
 * @return string[]
 */
function npmp_mailpoet_status_keys() {
	return array( 'subscribed', 'unconfirmed', 'inactive', 'unsubscribed', 'bounced', 'other' );
}

/**
 * Normalise a stored MailPoet status to one of npmp_mailpoet_status_keys().
 *
 * @param mixed $raw Value of mailpoet_subscribers.status.
 * @return string
 */
function npmp_mailpoet_status_key( $raw ) {
	$raw = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
	return in_array( $raw, array( 'subscribed', 'unconfirmed', 'inactive', 'unsubscribed', 'bounced' ), true ) ? $raw : 'other';
}

/**
 * The contact status a MailPoet status becomes.
 *
 * Only a confirmed MailPoet subscriber becomes a subscribed contact. Someone
 * who never confirmed waits as Pending, the same as an unconfirmed signup
 * here. Bounced has no status of its own here, so it becomes Unsubscribed,
 * which keeps mail away from a dead address. A status this importer doesn't
 * know becomes Pending, which no newsletter reaches.
 *
 * @param mixed $raw Value of mailpoet_subscribers.status.
 * @return string subscribed|pending|inactive|unsubscribed
 */
function npmp_mailpoet_map_status( $raw ) {
	$map = array(
		'subscribed'   => 'subscribed',
		'unconfirmed'  => 'pending',
		'inactive'     => 'inactive',
		'unsubscribed' => 'unsubscribed',
		'bounced'      => 'unsubscribed',
		'other'        => 'pending',
	);
	return $map[ npmp_mailpoet_status_key( $raw ) ];
}

/**
 * The status an existing contact should change to, or '' to leave it alone.
 *
 * An unsubscribe on either side wins. A contact waiting as Pending becomes
 * subscribed when MailPoet holds their confirmation. Every other status
 * someone chose here (Donor, Volunteer, Partner, Inactive) stays.
 *
 * @param string $existing The contact's status here.
 * @param string $incoming The mapped MailPoet status (npmp_mailpoet_map_status()).
 * @return string New status, or '' for no change.
 */
function npmp_mailpoet_merge_status( $existing, $incoming ) {
	$existing = '' === (string) $existing ? 'subscribed' : (string) $existing;
	$incoming = (string) $incoming;
	if ( 'unsubscribed' === $existing ) {
		return '';
	}
	if ( 'unsubscribed' === $incoming ) {
		return 'unsubscribed';
	}
	if ( 'pending' === $existing && 'subscribed' === $incoming ) {
		return 'subscribed';
	}
	return '';
}

/**
 * Join a MailPoet first and last name into one contact name.
 *
 * @param mixed $first first_name.
 * @param mixed $last  last_name.
 * @return string
 */
function npmp_mailpoet_full_name( $first, $last ) {
	$name = ( is_scalar( $first ) ? (string) $first : '' ) . ' ' . ( is_scalar( $last ) ? (string) $last : '' );
	return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
}

/**
 * The name to save on an existing contact, or '' to keep the one it has.
 *
 * A name someone typed here stays. MailPoet's name only fills a blank, and a
 * contact whose name is just their email address counts as blank.
 *
 * @param string $existing_name  Name on the contact.
 * @param string $existing_email Email on the contact.
 * @param string $incoming_name  Name from MailPoet.
 * @return string
 */
function npmp_mailpoet_merge_name( $existing_name, $existing_email, $incoming_name ) {
	$existing_name = trim( (string) $existing_name );
	$incoming_name = trim( (string) $incoming_name );
	if ( '' === $incoming_name ) {
		return '';
	}
	if ( '' === $existing_name || 0 === strcasecmp( $existing_name, trim( (string) $existing_email ) ) ) {
		return $incoming_name;
	}
	return '';
}

/**
 * Pick the day a MailPoet subscriber first joined.
 *
 * The earlier of created_at and confirmed_at, as Unix time. A form signup has
 * created_at first. Someone imported into MailPoet with their history has an
 * older confirmed_at. Zero, empty, and negative values don't count.
 *
 * @param mixed $created   UNIX_TIMESTAMP(created_at), or 0.
 * @param mixed $confirmed UNIX_TIMESTAMP(confirmed_at), or 0.
 * @return string Unix time as digits, or '' when neither is usable.
 */
function npmp_mailpoet_pick_added( $created, $confirmed ) {
	$times = array();
	foreach ( array( $created, $confirmed ) as $value ) {
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$times[] = (int) $value;
		}
	}
	return $times ? (string) min( $times ) : '';
}

/**
 * Read one mailpoet_settings value.
 *
 * MailPoet serializes arrays with PHP's serialize() and stores plain strings
 * as they are (SettingsRepository::createOrUpdateByName()). Objects are never
 * rebuilt.
 *
 * @param mixed $raw Stored value.
 * @return mixed Array, string, or null.
 */
function npmp_mailpoet_parse_setting( $raw ) {
	if ( null === $raw || is_array( $raw ) ) {
		return $raw;
	}
	if ( ! is_string( $raw ) ) {
		return is_scalar( $raw ) ? (string) $raw : null;
	}
	$trimmed = trim( $raw );
	if ( preg_match( '/^(a|s|i|b|d):/', $trimmed ) || 'N;' === $trimmed ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- MailPoet's own format, read with classes off.
		$value = @unserialize( $trimmed, array( 'allowed_classes' => false ) );
		if ( false !== $value || 'b:0;' === $trimmed ) {
			return is_object( $value ) ? null : $value;
		}
	}
	return $raw;
}

/**
 * Pull the settings this importer can use out of mailpoet_settings rows.
 *
 * @param array $rows Setting name => stored value, for sender, reply_to, and signup_confirmation.
 * @return array{from_name:string,from_email:string,reply_to:string,double_optin:?bool}
 *               double_optin is null when MailPoet saved no signup_confirmation row.
 */
function npmp_mailpoet_read_settings( array $rows ) {
	$clean = static function ( $value ) {
		return is_scalar( $value ) ? trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) ) : '';
	};

	$sender = npmp_mailpoet_parse_setting( $rows['sender'] ?? null );
	$reply  = npmp_mailpoet_parse_setting( $rows['reply_to'] ?? null );
	$signup = npmp_mailpoet_parse_setting( $rows['signup_confirmation'] ?? null );

	$reply_address = is_array( $reply ) ? $clean( $reply['address'] ?? '' ) : '';
	$reply_name    = is_array( $reply ) ? $clean( $reply['name'] ?? '' ) : '';

	$optin = null;
	if ( is_array( $signup ) && array_key_exists( 'enabled', $signup ) ) {
		$enabled = $signup['enabled'];
		$optin   = ! ( false === $enabled || null === $enabled || in_array( strtolower( trim( (string) ( is_scalar( $enabled ) ? $enabled : '' ) ) ), array( '', '0', 'false', 'no', 'off' ), true ) );
	}

	return array(
		'from_name'    => is_array( $sender ) ? $clean( $sender['name'] ?? '' ) : '',
		'from_email'   => is_array( $sender ) ? $clean( $sender['address'] ?? '' ) : '',
		'reply_to'     => '' === $reply_address ? '' : ( '' !== $reply_name ? $reply_name . ' <' . $reply_address . '>' : $reply_address ),
		'double_optin' => $optin,
	);
}

/**
 * Line up MailPoet's settings against the ones saved here.
 *
 * One row per setting MailPoet holds. A row's state is 'same' (nothing to
 * do), 'change' (a checkbox on the approve screen), or 'unsupported' (no
 * matching setting here, shown and skipped). A change starts checked only
 * when nobody has saved that setting here yet. Switching signup confirmation
 * off never starts checked, since it opens the signup form to addresses whose
 * owners never agreed.
 *
 * @param array $mailpoet npmp_mailpoet_read_settings() output.
 * @param array $current  from_name, from_email, double_optin => array{value:mixed,set:bool}.
 * @return array<string,array{key:string,mailpoet:mixed,current:mixed,state:string,checked:bool}>
 */
function npmp_mailpoet_settings_plan( array $mailpoet, array $current ) {
	$plan = array();

	foreach ( array( 'from_name', 'from_email' ) as $key ) {
		$theirs = (string) ( $mailpoet[ $key ] ?? '' );
		if ( '' === $theirs ) {
			continue;
		}
		$ours = (string) ( $current[ $key ]['value'] ?? '' );
		$same = 'from_email' === $key ? 0 === strcasecmp( $theirs, $ours ) : $theirs === $ours;
		$plan[ $key ] = array(
			'key'      => $key,
			'mailpoet' => $theirs,
			'current'  => $ours,
			'state'    => $same ? 'same' : 'change',
			'checked'  => ! $same && empty( $current[ $key ]['set'] ),
		);
	}

	if ( '' !== (string) ( $mailpoet['reply_to'] ?? '' ) ) {
		$plan['reply_to'] = array(
			'key'      => 'reply_to',
			'mailpoet' => (string) $mailpoet['reply_to'],
			'current'  => '',
			'state'    => 'unsupported',
			'checked'  => false,
		);
	}

	if ( isset( $mailpoet['double_optin'] ) && is_bool( $mailpoet['double_optin'] ) ) {
		$theirs = $mailpoet['double_optin'];
		$ours   = ! empty( $current['double_optin']['value'] );
		$plan['double_optin'] = array(
			'key'      => 'double_optin',
			'mailpoet' => $theirs,
			'current'  => $ours,
			'state'    => $theirs === $ours ? 'same' : 'change',
			'checked'  => $theirs !== $ours && $theirs && empty( $current['double_optin']['set'] ),
		);
	}

	return $plan;
}

/**
 * Which kinds of MailPoet form does this content hold?
 *
 * Finds the [mailpoet_form] shortcode and any MailPoet block
 * (<!-- wp:mailpoet/subscription-form-block ... -->). Both render nothing
 * once MailPoet is off.
 *
 * @param mixed $content Post content or widget text.
 * @return string[] Any of 'shortcode' and 'block'.
 */
function npmp_mailpoet_form_kinds( $content ) {
	$content = is_scalar( $content ) ? (string) $content : '';
	$kinds   = array();
	if ( preg_match( '/\[mailpoet_form(?![a-z0-9_-])/i', $content ) ) {
		$kinds[] = 'shortcode';
	}
	if ( preg_match( '#<!--\s+wp:mailpoet/[a-z0-9-]+#i', $content ) ) {
		$kinds[] = 'block';
	}
	return $kinds;
}

/**
 * Build the `groups` cell for a contact's MailPoet lists.
 *
 * The CSV import's groups column splits on semicolons when it finds one, so a
 * list named "Arts, Crafts" stays one group. The trailing semicolon makes
 * sure a single name with a comma isn't split.
 *
 * @param string[] $names List names.
 * @return string "Walking Group; Arts, Crafts;" or ''.
 */
function npmp_mailpoet_groups_cell( array $names ) {
	$out = array();
	foreach ( $names as $name ) {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( ';', ',', is_scalar( $name ) ? (string) $name : '' ) ) );
		if ( '' !== $name && ! isset( $out[ strtolower( $name ) ] ) ) {
			$out[ strtolower( $name ) ] = $name;
		}
	}
	return $out ? implode( '; ', $out ) . ';' : '';
}

/**
 * Keep only positive whole numbers, once each.
 *
 * @param mixed $ids Raw IDs.
 * @return int[]
 */
function npmp_mailpoet_clean_ids( $ids ) {
	$out = array();
	foreach ( (array) $ids as $id ) {
		if ( is_scalar( $id ) && preg_match( '/^\d+$/', trim( (string) $id ) ) && (int) $id > 0 ) {
			$out[ (int) $id ] = (int) $id;
		}
	}
	return array_values( $out );
}

/**
 * Does this plugin need MailPoet to run?
 *
 * True when the plugin says so in its Requires Plugins header, when its folder
 * carries MailPoet's name (mailpoet-premium, add-on-contact-form-7-mailpoet),
 * or when its name starts with "MailPoet".
 *
 * @param string $file Plugin basename, such as 'mailpoet-premium/mailpoet-premium.php'.
 * @param array  $data Plugin header data from get_plugins().
 * @return bool
 */
function npmp_mailpoet_plugin_needs_mailpoet( $file, array $data ) {
	$folder = strtolower( (string) strtok( (string) $file, '/' ) );
	if ( 'mailpoet' === $folder ) {
		return false;
	}

	$requires = array_map( 'trim', explode( ',', strtolower( (string) ( $data['RequiresPlugins'] ?? '' ) ) ) );
	if ( in_array( 'mailpoet', $requires, true ) ) {
		return true;
	}
	if ( false !== strpos( $folder, 'mailpoet' ) ) {
		return true;
	}
	return 0 === stripos( trim( (string) ( $data['Name'] ?? '' ) ), 'MailPoet' );
}

// =====================================================================
// State
// =====================================================================

/**
 * Full name of one of MailPoet's tables.
 *
 * @param string $name subscribers, segments, subscriber_segment, or settings.
 * @return string
 */
function npmp_mailpoet_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'mailpoet_' . preg_replace( '/[^a-z_]/', '', (string) $name );
}

/**
 * Does one of MailPoet's tables exist on this site?
 *
 * @param string $name Table, see npmp_mailpoet_table().
 * @return bool
 */
function npmp_mailpoet_has_table( $name = 'subscribers' ) {
	global $wpdb;
	static $exists = array();
	// Only a table that is there gets remembered. A missing one is asked for
	// again, so a table made later in the request is found.
	if ( empty( $exists[ $name ] ) ) {
		$table           = npmp_mailpoet_table( $name );
		$exists[ $name ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	return $exists[ $name ];
}

/**
 * Is MailPoet running on this request?
 *
 * @return bool
 */
function npmp_mailpoet_is_active() {
	return defined( 'MAILPOET_VERSION' );
}

/**
 * Plugin basename of MailPoet, or '' when it isn't installed.
 *
 * @return string
 */
function npmp_mailpoet_plugin_file() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( 0 === strpos( $file, 'mailpoet/' ) ) {
			return $file;
		}
	}
	return '';
}

/**
 * Show the importer? Yes while MailPoet runs or sits in the plugins folder,
 * and afterwards once anything came over. On the importer's own screen and
 * the Import screen it also looks for MailPoet's tables, which covers a site
 * that deleted the plugin and kept the data. That costs one query, so it
 * stays off every other screen.
 *
 * @return bool
 */
function npmp_mailpoet_importer_visible() {
	if ( npmp_mailpoet_is_active() || (int) get_option( 'npmp_mailpoet_imported', 0 ) > 0 ) {
		return true;
	}
	if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/mailpoet/mailpoet.php' ) ) {
		return true;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads which screen this is.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	return in_array( $page, array( 'npmp_mailpoet_import', 'npmp_import' ), true ) && npmp_mailpoet_has_table();
}

/**
 * Will lists become groups on this site?
 *
 * Groups come with Pro, which listens on npmp_import_row_imported for a
 * `groups` cell (includes/groups/import.php there).
 *
 * @return bool
 */
function npmp_mailpoet_groups_supported() {
	/**
	 * Filter whether MailPoet lists can become groups.
	 *
	 * @param bool $supported True when something handles the `groups` cell of
	 *                        npmp_import_row_imported.
	 */
	return (bool) apply_filters( 'npmp_mailpoet_groups_supported', false !== has_action( 'npmp_import_row_imported', 'npmp_pro_groups_import_row' ) );
}

/**
 * Option name for a user's staging summary.
 *
 * One non-autoloaded option per user, so two admins never overwrite each
 * other. It holds counts, list names, and 20 sample rows, never the whole
 * subscriber list: the migrate step reads each batch from MailPoet's tables.
 *
 * @param int $user_id User ID, 0 for the current user.
 * @return string
 */
function npmp_mailpoet_stage_option( $user_id = 0 ) {
	return 'npmp_mailpoet_stage_' . ( $user_id ? (int) $user_id : get_current_user_id() );
}

/**
 * Empty results for a migration run.
 *
 * @return array
 */
function npmp_mailpoet_empty_results() {
	return array(
		'created'           => 0,
		'updated'           => 0,
		'unchanged'         => 0,
		'kept_unsubscribed' => 0,
		'invalid'           => 0,
		'left'              => 0,
		'failed'            => array(),
		'cap_reached'       => false,
	);
}

/**
 * Read the current user's staging summary.
 *
 * @return array
 */
function npmp_mailpoet_get_stage() {
	$stage = get_option( npmp_mailpoet_stage_option(), array() );
	$stage = array_merge(
		array(
			'cursor'           => 0,
			'read'             => 0,
			'total'            => 0,
			'done'             => false,
			'counts'           => array(),
			'plan'             => array(
				'new'           => 0,
				'existing'      => 0,
				'stay_unsubbed' => 0,
				'invalid'       => 0,
			),
			'lists'            => array(),
			'settings'         => array(),
			'sample'           => array(),
			'skip_statuses'    => array(),
			'skip_lists'       => array(),
			'reviewed'         => false,
			'approved'         => false,
			'apply_settings'   => array(),
			'settings_done'    => false,
			'settings_applied' => array(),
			'migrate_cursor'   => 0,
			'position'         => 0,
			'handled'          => 0,
			'migrated'         => false,
			'results'          => npmp_mailpoet_empty_results(),
			'deactivated'      => array(),
		),
		is_array( $stage ) ? $stage : array()
	);
	$stage['results'] = array_merge( npmp_mailpoet_empty_results(), (array) $stage['results'] );
	return $stage;
}

/**
 * Save the current user's staging summary.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_mailpoet_save_stage( array $stage ) {
	update_option( npmp_mailpoet_stage_option(), $stage, false );
}

/**
 * Throw away the current user's staging summary. Imported contacts stay.
 *
 * @return void
 */
function npmp_mailpoet_reset_stage() {
	delete_option( npmp_mailpoet_stage_option() );
}

// =====================================================================
// Step 1: read MailPoet into staging
// =====================================================================

/**
 * Count the MailPoet subscribers the importer will read. Trashed ones
 * (deleted_at set) stay behind.
 *
 * @return int
 */
function npmp_mailpoet_count_subscribers() {
	global $wpdb;
	if ( ! npmp_mailpoet_has_table() ) {
		return 0;
	}
	$table = npmp_mailpoet_table( 'subscribers' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no input.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL" );
}

/**
 * MailPoet's own lists (segments of type default), with how many subscribers
 * each one mails. Trashed lists, WordPress Users, WooCommerce lists, and
 * dynamic segments stay behind.
 *
 * @return array<int,array{name:string,count:int}> Keyed by segment ID.
 */
function npmp_mailpoet_read_lists() {
	global $wpdb;
	if ( ! npmp_mailpoet_has_table( 'segments' ) ) {
		return array();
	}
	$segments = npmp_mailpoet_table( 'segments' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$segments} WHERE type = %s AND deleted_at IS NULL ORDER BY name ASC LIMIT %d", 'default', 500 ) );
	$lists = array();
	foreach ( (array) $rows as $row ) {
		$lists[ (int) $row->id ] = array(
			'name'  => trim( (string) $row->name ),
			'count' => 0,
		);
	}

	if ( $lists && npmp_mailpoet_has_table( 'subscriber_segment' ) ) {
		$links       = npmp_mailpoet_table( 'subscriber_segment' );
		$subscribers = npmp_mailpoet_table( 'subscribers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix.
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT ss.segment_id AS id, COUNT(*) AS n FROM {$links} ss INNER JOIN {$subscribers} s ON s.id = ss.subscriber_id WHERE ss.status = %s AND s.deleted_at IS NULL GROUP BY ss.segment_id", 'subscribed' ) );
		foreach ( (array) $counts as $row ) {
			if ( isset( $lists[ (int) $row->id ] ) ) {
				$lists[ (int) $row->id ]['count'] = (int) $row->n;
			}
		}
	}
	return $lists;
}

/**
 * Read MailPoet's sender, reply-to, and signup confirmation settings.
 * Sending method, SMTP, and API key rows are never read.
 *
 * @return array npmp_mailpoet_read_settings() output.
 */
function npmp_mailpoet_read_mailpoet_settings() {
	global $wpdb;
	$rows = array();
	if ( npmp_mailpoet_has_table( 'settings' ) ) {
		$table = npmp_mailpoet_table( 'settings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT name, value FROM {$table} WHERE name IN ( %s, %s, %s )", 'sender', 'reply_to', 'signup_confirmation' ) );
		foreach ( (array) $found as $row ) {
			$rows[ (string) $row->name ] = $row->value;
		}
	}
	return npmp_mailpoet_read_settings( $rows );
}

/**
 * The matching settings saved here, and whether anyone has saved each one.
 *
 * @return array from_name, from_email, double_optin => array{value:mixed,set:bool}.
 */
function npmp_mailpoet_current_settings() {
	$raw_email = get_option( 'npmp_email_settings' );
	$raw_forms = get_option( 'npmp_membership_form_settings', array() );
	$email     = function_exists( 'npmp_email_get_settings' ) ? npmp_email_get_settings() : array();
	$forms     = function_exists( 'npmp_get_membership_form_settings' ) ? npmp_get_membership_form_settings( true ) : array( 'double_optin' => 1 );

	return array(
		'from_name'    => array(
			'value' => (string) ( $email['from_name'] ?? '' ),
			'set'   => ( is_array( $raw_email ) && ! empty( $raw_email['from_name'] ) ) || '' !== (string) get_option( 'npmp_email_from_name', '' ),
		),
		'from_email'   => array(
			'value' => (string) ( $email['from_email'] ?? '' ),
			'set'   => ( is_array( $raw_email ) && ! empty( $raw_email['from_email'] ) ) || '' !== (string) get_option( 'npmp_email_from_email', '' ),
		),
		'double_optin' => array(
			'value' => ! empty( $forms['double_optin'] ),
			'set'   => is_array( $raw_forms ) && array_key_exists( 'double_optin', $raw_forms ),
		),
	);
}

/**
 * The settings plan for this site right now.
 *
 * @return array npmp_mailpoet_settings_plan() output.
 */
function npmp_mailpoet_current_plan() {
	return npmp_mailpoet_settings_plan( npmp_mailpoet_read_mailpoet_settings(), npmp_mailpoet_current_settings() );
}

/**
 * Find the contacts that already hold these email addresses, in one query.
 *
 * @param string[] $emails Email addresses.
 * @return array<string,array{id:int,status:string}> Keyed by lowercase email.
 */
function npmp_mailpoet_find_contacts( array $emails ) {
	global $wpdb;
	$emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', array_map( 'strval', $emails ) ) ) ) );
	if ( ! $emails ) {
		return array();
	}
	$in = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a placeholder list.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.post_id AS id, e.meta_value AS email, s.meta_value AS status FROM {$wpdb->postmeta} e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id LEFT JOIN {$wpdb->postmeta} s ON s.post_id = e.post_id AND s.meta_key = 'npmp_status' WHERE e.meta_key = 'npmp_email' AND p.post_type = 'npmp_contact' AND p.post_status = 'publish' AND e.meta_value IN ($in) ORDER BY e.post_id DESC", $emails ) );

	$found = array();
	foreach ( (array) $rows as $row ) {
		// Ordered newest first, so the oldest contact for an address wins.
		$found[ strtolower( (string) $row->email ) ] = array(
			'id'     => (int) $row->id,
			'status' => '' === (string) $row->status ? 'subscribed' : (string) $row->status,
		);
	}
	return $found;
}

/**
 * Read one batch of MailPoet subscribers, oldest ID first.
 *
 * @param int $after Last subscriber ID already read.
 * @param int $limit Rows to read.
 * @return object[] Rows with id, email, first_name, last_name, status, created, confirmed.
 */
function npmp_mailpoet_read_subscribers( $after, $limit ) {
	global $wpdb;
	if ( ! npmp_mailpoet_has_table() ) {
		return array();
	}
	$table = npmp_mailpoet_table( 'subscribers' );
	// UNIX_TIMESTAMP() of a TIMESTAMP column is the same instant whatever time
	// zone the session uses. The CASE keeps an empty date at 0.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, email, first_name, last_name, status, CASE WHEN created_at IS NULL THEN 0 ELSE UNIX_TIMESTAMP(created_at) END AS created, CASE WHEN confirmed_at IS NULL THEN 0 ELSE UNIX_TIMESTAMP(confirmed_at) END AS confirmed FROM {$table} WHERE id > %d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", (int) $after, max( 1, (int) $limit ) ) );
}

/**
 * Read the next batch of subscribers into the current user's staging summary.
 *
 * @param bool $restart Start a fresh summary.
 * @param int  $limit   Subscribers per batch.
 * @return array{done:bool,read:int,total:int}
 */
function npmp_mailpoet_stage_batch( $restart = false, $limit = NPMP_MAILPOET_BATCH ) {
	if ( $restart ) {
		npmp_mailpoet_reset_stage();
	}
	$stage = npmp_mailpoet_get_stage();

	if ( $stage['done'] ) {
		return array(
			'done'  => true,
			'read'  => (int) $stage['read'],
			'total' => (int) $stage['total'],
		);
	}

	if ( ! $stage['read'] ) {
		$stage['total']    = npmp_mailpoet_count_subscribers();
		$stage['lists']    = npmp_mailpoet_read_lists();
		$stage['settings'] = npmp_mailpoet_read_mailpoet_settings();
	}

	$limit = max( 1, (int) $limit );
	$rows  = npmp_mailpoet_read_subscribers( (int) $stage['cursor'], $limit );

	if ( $rows ) {
		$existing = npmp_mailpoet_find_contacts( wp_list_pluck( $rows, 'email' ) );
		foreach ( $rows as $row ) {
			$key                     = npmp_mailpoet_status_key( $row->status );
			$stage['counts'][ $key ] = (int) ( $stage['counts'][ $key ] ?? 0 ) + 1;
			$email                   = sanitize_email( (string) $row->email );

			if ( '' === $email || ! is_email( $email ) ) {
				++$stage['plan']['invalid'];
			} elseif ( isset( $existing[ strtolower( $email ) ] ) ) {
				++$stage['plan']['existing'];
				if ( 'unsubscribed' === $existing[ strtolower( $email ) ]['status'] && 'unsubscribed' !== npmp_mailpoet_map_status( $row->status ) ) {
					++$stage['plan']['stay_unsubbed'];
				}
			} else {
				++$stage['plan']['new'];
			}

			if ( count( $stage['sample'] ) < 20 ) {
				$stage['sample'][] = array(
					'name'   => npmp_mailpoet_full_name( $row->first_name, $row->last_name ),
					'email'  => (string) $row->email,
					'status' => $key,
				);
			}
		}
		$last            = end( $rows );
		$stage['cursor'] = (int) $last->id;
		$stage['read']  += count( $rows );
	}

	if ( count( $rows ) < $limit ) {
		$stage['done']  = true;
		$stage['total'] = (int) $stage['read'];
	}
	npmp_mailpoet_save_stage( $stage );

	return array(
		'done'  => (bool) $stage['done'],
		'read'  => (int) $stage['read'],
		'total' => max( (int) $stage['total'], (int) $stage['read'] ),
	);
}

// =====================================================================
// Steps 2 and 3: review and approve
// =====================================================================

/**
 * Save which statuses and lists come over.
 *
 * @param string[] $keep_statuses MailPoet status keys you left checked.
 * @param int[]    $keep_lists    List IDs you left checked.
 * @return void
 */
function npmp_mailpoet_set_review( array $keep_statuses, array $keep_lists ) {
	$stage                  = npmp_mailpoet_get_stage();
	$keep_statuses          = array_intersect( npmp_mailpoet_status_keys(), array_map( 'strval', $keep_statuses ) );
	$stage['skip_statuses'] = array_values( array_diff( array_keys( $stage['counts'] ), $keep_statuses ) );
	// Without groups the list checkboxes are greyed and post nothing, so the
	// lists stay selected for the day groups arrive.
	if ( npmp_mailpoet_groups_supported() ) {
		$stage['skip_lists'] = array_values( array_diff( array_keys( $stage['lists'] ), npmp_mailpoet_clean_ids( $keep_lists ) ) );
	}
	$stage['reviewed']      = true;
	$stage['approved']      = false;
	npmp_mailpoet_save_stage( $stage );
}

/**
 * How many subscribers the checked statuses hold.
 *
 * @param array $stage Stage.
 * @return int
 */
function npmp_mailpoet_selected_count( array $stage ) {
	return (int) array_sum( array_diff_key( (array) $stage['counts'], array_flip( (array) $stage['skip_statuses'] ) ) );
}

/**
 * Lists that will become groups: checked, and only when groups are available.
 *
 * @param array $stage Stage.
 * @return array<int,string> Segment ID => list name.
 */
function npmp_mailpoet_selected_lists( array $stage ) {
	if ( ! npmp_mailpoet_groups_supported() ) {
		return array();
	}
	$lists = array_diff_key( (array) $stage['lists'], array_flip( (array) $stage['skip_lists'] ) );
	return array_filter( wp_list_pluck( $lists, 'name' ), 'strlen' );
}

/**
 * Approve the selection and queue it for the migrate step.
 *
 * @param string[] $settings Setting keys you ticked (from_name, from_email, double_optin).
 * @return int Subscribers queued.
 */
function npmp_mailpoet_approve( array $settings = array() ) {
	$stage                     = npmp_mailpoet_get_stage();
	$stage['apply_settings']   = array_values( array_intersect( array( 'from_name', 'from_email', 'double_optin' ), array_map( 'sanitize_key', $settings ) ) );
	$stage['settings_done']    = false;
	$stage['settings_applied'] = array();
	$stage['migrate_cursor']   = 0;
	$stage['position']         = 0;
	$stage['handled']          = 0;
	$stage['approved']         = true;
	$stage['migrated']         = false;
	$stage['results']          = npmp_mailpoet_empty_results();
	npmp_mailpoet_save_stage( $stage );
	return npmp_mailpoet_selected_count( $stage );
}

// =====================================================================
// Step 4: migrate
// =====================================================================

/**
 * Copy the ticked MailPoet settings over.
 *
 * Reads MailPoet's values again, so nothing a browser posted is ever saved.
 * A setting that already matches, or one you left unticked, stays as it is.
 *
 * Options written: npmp_email_settings[from_name] and [from_email] (with the
 * older npmp_email_from_name and npmp_email_from_email copies the Email
 * Settings screen also keeps), and npmp_membership_form_settings[double_optin].
 *
 * @param string[] $keys Setting keys to apply.
 * @return array<string,mixed> Key => the value now saved.
 */
function npmp_mailpoet_apply_settings( array $keys ) {
	$plan    = npmp_mailpoet_current_plan();
	$applied = array();

	foreach ( $keys as $key ) {
		if ( empty( $plan[ $key ] ) || 'change' !== $plan[ $key ]['state'] ) {
			continue;
		}
		$value = $plan[ $key ]['mailpoet'];

		if ( 'double_optin' === $key ) {
			$forms                 = get_option( 'npmp_membership_form_settings', array() );
			$forms                 = is_array( $forms ) ? $forms : array();
			$forms['double_optin'] = $value ? 1 : 0;
			update_option( 'npmp_membership_form_settings', $forms );
			if ( function_exists( 'npmp_get_membership_form_settings' ) ) {
				npmp_get_membership_form_settings( true );
			}
			$applied[ $key ] = (bool) $value;
			continue;
		}

		if ( ! function_exists( 'npmp_email_get_settings' ) ) {
			continue;
		}
		$value = 'from_email' === $key ? sanitize_email( (string) $value ) : sanitize_text_field( (string) $value );
		if ( '' === $value || ( 'from_email' === $key && ! is_email( $value ) ) ) {
			continue;
		}
		$email         = npmp_email_get_settings();
		$email[ $key ] = $value;
		npmp_email_update_settings( $email );
		update_option( 'from_email' === $key ? 'npmp_email_from_email' : 'npmp_email_from_name', $value );
		$applied[ $key ] = $value;
	}
	return $applied;
}

/**
 * Which lists each subscriber in a batch is subscribed to.
 *
 * @param int[]             $subscriber_ids MailPoet subscriber IDs.
 * @param array<int,string> $lists          Segment ID => name, the lists that come over.
 * @return array<int,string[]> Subscriber ID => list names.
 */
function npmp_mailpoet_read_memberships( array $subscriber_ids, array $lists ) {
	global $wpdb;
	$subscriber_ids = npmp_mailpoet_clean_ids( $subscriber_ids );
	if ( ! $subscriber_ids || ! $lists || ! npmp_mailpoet_has_table( 'subscriber_segment' ) ) {
		return array();
	}
	$table = npmp_mailpoet_table( 'subscriber_segment' );
	$in    = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );
	$args  = array_merge( $subscriber_ids, array( 'subscribed' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name from $wpdb->prefix, $in is a placeholder list.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, segment_id FROM {$table} WHERE subscriber_id IN ($in) AND status = %s ORDER BY subscriber_id ASC, segment_id ASC", $args ) );

	$out = array();
	foreach ( (array) $rows as $row ) {
		if ( isset( $lists[ (int) $row->segment_id ] ) ) {
			$out[ (int) $row->subscriber_id ][] = $lists[ (int) $row->segment_id ];
		}
	}
	return $out;
}

/**
 * Create or update one contact from a MailPoet subscriber.
 *
 * @param object     $row      Subscriber row from npmp_mailpoet_read_subscribers().
 * @param array|null $existing array{id:int,status:string} from npmp_mailpoet_find_contacts(), or null.
 * @param string[]   $lists    Names of the lists this subscriber is subscribed to.
 * @return array{result:string,member_id:int,message:string,kept_unsubscribed:bool}
 *               result is created, updated, unchanged, invalid, or error.
 */
function npmp_mailpoet_import_subscriber( $row, $existing = null, array $lists = array() ) {
	$out = array(
		'result'            => 'error',
		'member_id'         => 0,
		'message'           => '',
		'kept_unsubscribed' => false,
	);

	$email = sanitize_email( (string) $row->email );
	if ( '' === $email || ! is_email( $email ) ) {
		$out['result'] = 'invalid';
		return $out;
	}

	$members  = NPMP_Member_Manager::get_instance();
	$name     = npmp_mailpoet_full_name( $row->first_name, $row->last_name );
	$incoming = npmp_mailpoet_map_status( $row->status );
	$added    = npmp_mailpoet_pick_added( $row->created, $row->confirmed );
	$member   = $existing ? $members->get_member_by_id( (int) $existing['id'] ) : null;

	if ( ! $member ) {
		$data = array(
			'name'   => $name,
			'email'  => $email,
			'status' => $incoming,
			'source' => 'mailpoet',
		);
		if ( '' !== $added ) {
			$data['created_at'] = $added;
		}
		$new_id = $members->add_member( $data );
		if ( is_wp_error( $new_id ) && 'npmp_member_exists' === $new_id->get_error_code() ) {
			// Same address in another letter case. Treat it as the existing contact.
			$member = $members->get_member_by_email( $email );
		} elseif ( is_wp_error( $new_id ) ) {
			$out['message'] = $new_id->get_error_message();
			return $out;
		} else {
			$out['result']    = 'created';
			$out['member_id'] = (int) $new_id;
			$final_status     = $incoming;
		}
	}

	if ( $member ) {
		$update     = array();
		$new_status = npmp_mailpoet_merge_status( $member->status, $incoming );
		$new_name   = npmp_mailpoet_merge_name( $member->name, $member->email, $name );
		if ( '' !== $new_status ) {
			$update['status'] = $new_status;
		}
		if ( '' !== $new_name ) {
			$update['name'] = $new_name;
		}
		// update_member() only ever moves the Added date earlier.
		$parsed = '' !== $added && function_exists( 'npmp_import_parse_added_date' ) ? npmp_import_parse_added_date( $added ) : null;
		if ( $parsed && ( empty( $member->created_at ) || strtotime( $parsed['local'] ) < strtotime( $member->created_at ) ) ) {
			$update['created_at'] = $added;
		}

		if ( $update ) {
			$result = $members->update_member( (int) $member->id, $update );
			if ( is_wp_error( $result ) ) {
				$out['message'] = $result->get_error_message();
				return $out;
			}
		}
		$out['result']            = $update ? 'updated' : 'unchanged';
		$out['member_id']         = (int) $member->id;
		$out['kept_unsubscribed'] = 'unsubscribed' === $member->status && 'unsubscribed' !== $incoming;
		$final_status             = '' !== $new_status ? $new_status : $member->status;
	}

	if ( ! $out['member_id'] ) {
		return $out;
	}

	$record = array(
		'name'   => $name,
		'email'  => $email,
		'status' => $final_status,
		'source' => 'mailpoet',
	);
	// Someone who unsubscribed joins no group.
	$cell = 'unsubscribed' === $final_status ? '' : npmp_mailpoet_groups_cell( $lists );
	if ( '' !== $cell ) {
		$record['groups'] = $cell;
	}

	/**
	 * The CSV import's own hook (class-import-manager.php), so everything
	 * that follows an imported row follows a MailPoet subscriber too. Pro's
	 * groups read $record['groups'] here: they make each group that doesn't
	 * exist yet and add the contact, and they never put someone back in a
	 * group they left by email.
	 */
	do_action(
		'npmp_import_row_imported',
		(int) $out['member_id'],
		$record,
		array(
			'mailpoet_id' => (int) $row->id,
			'lists'       => array_values( $lists ),
		),
		array(),
		'created' === $out['result'] ? 'imported' : 'updated',
		array(
			'source'             => 'mailpoet',
			'duplicate_handling' => 'update',
		)
	);

	return $out;
}

/**
 * Migrate the next batch of subscribers.
 *
 * The free version moves up to npmp_import_max_rows() contacts, the same cap
 * every other import source has. Pro lifts it.
 *
 * @param int $limit Subscribers per batch.
 * @return array{done:bool,position:int,total:int,created:int}
 */
function npmp_mailpoet_migrate_batch( $limit = NPMP_MAILPOET_BATCH ) {
	$stage = npmp_mailpoet_get_stage();
	$total = (int) $stage['total'];

	if ( ! $stage['approved'] || $stage['migrated'] || ! class_exists( 'NPMP_Member_Manager' ) ) {
		return array(
			'done'     => (bool) $stage['migrated'],
			'position' => (int) $stage['position'],
			'total'    => $total,
			'created'  => (int) $stage['results']['created'],
		);
	}

	if ( ! $stage['settings_done'] ) {
		$stage['settings_applied'] = npmp_mailpoet_apply_settings( (array) $stage['apply_settings'] );
		$stage['settings_done']    = true;
	}

	$limit  = max( 1, (int) $limit );
	$max    = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;
	$skip   = array_flip( (array) $stage['skip_statuses'] );
	$lists  = npmp_mailpoet_selected_lists( $stage );
	$before = (int) $stage['results']['created'];
	$rows   = npmp_mailpoet_read_subscribers( (int) $stage['migrate_cursor'], $limit );

	if ( $rows ) {
		$existing    = npmp_mailpoet_find_contacts( wp_list_pluck( $rows, 'email' ) );
		$memberships = npmp_mailpoet_read_memberships( wp_list_pluck( $rows, 'id' ), $lists );

		foreach ( $rows as $row ) {
			if ( isset( $skip[ npmp_mailpoet_status_key( $row->status ) ] ) ) {
				++$stage['results']['left'];
			} elseif ( (int) $stage['handled'] >= $max ) {
				$stage['results']['cap_reached'] = true;
				break;
			} else {
				$email  = strtolower( sanitize_email( (string) $row->email ) );
				$result = npmp_mailpoet_import_subscriber( $row, $existing[ $email ] ?? null, $memberships[ (int) $row->id ] ?? array() );

				if ( 'invalid' === $result['result'] ) {
					++$stage['results']['invalid'];
				} elseif ( 'error' === $result['result'] ) {
					if ( count( $stage['results']['failed'] ) < 50 ) {
						$stage['results']['failed'][] = array(
							'email' => (string) $row->email,
							'error' => $result['message'],
						);
					}
				} else {
					++$stage['results'][ $result['result'] ];
					++$stage['handled'];
					if ( $result['kept_unsubscribed'] ) {
						++$stage['results']['kept_unsubscribed'];
					}
				}
			}
			$stage['migrate_cursor'] = (int) $row->id;
			++$stage['position'];
		}
	}

	if ( count( $rows ) < $limit || $stage['results']['cap_reached'] ) {
		$stage['migrated'] = true;
	}
	npmp_mailpoet_save_stage( $stage );

	// Autoloaded, so the menu check costs no query once MailPoet is gone. An
	// import that only updated contacts still counts as one.
	$had = (int) get_option( 'npmp_mailpoet_imported', 0 );
	$now = $had + (int) $stage['results']['created'] - $before;
	if ( $now < 1 && $stage['handled'] ) {
		$now = 1;
	}
	if ( $now !== $had ) {
		update_option( 'npmp_mailpoet_imported', $now, true );
	}

	return array(
		'done'     => (bool) $stage['migrated'],
		'position' => (int) $stage['position'],
		'total'    => max( $total, (int) $stage['position'] ),
		'created'  => (int) $stage['results']['created'],
	);
}

// =====================================================================
// Step 5: signup forms left on the site, then deactivate MailPoet
// =====================================================================

/**
 * Published posts, pages, and block templates that hold a MailPoet form.
 *
 * Lists them only. The importer never rewrites your content.
 *
 * @param int $limit Most rows to return.
 * @return array<int,array{id:int,title:string,type:string,kinds:string[]}>
 */
function npmp_mailpoet_find_form_posts( $limit = 200 ) {
	global $wpdb;
	$types = array_values( array_diff( array_merge( get_post_types( array( 'public' => true ) ), array( 'wp_block', 'wp_template', 'wp_template_part' ) ), array( 'attachment' ) ) );
	if ( ! $types ) {
		return array();
	}
	$in        = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$shortcode = '%' . $wpdb->esc_like( '[mailpoet_form' ) . '%';
	$block     = '%' . $wpdb->esc_like( '<!-- wp:mailpoet/' ) . '%';
	$args      = array_merge( $types, array( $shortcode, $block, max( 1, (int) $limit ) ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a placeholder list.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_type ASC, post_title ASC LIMIT %d", $args ) );

	$out = array();
	foreach ( (array) $rows as $row ) {
		$kinds = npmp_mailpoet_form_kinds( $row->post_content );
		if ( $kinds ) {
			$out[] = array(
				'id'    => (int) $row->ID,
				'title' => '' !== trim( (string) $row->post_title ) ? (string) $row->post_title : '#' . (int) $row->ID,
				'type'  => (string) $row->post_type,
				'kinds' => $kinds,
			);
		}
	}
	return $out;
}

/**
 * How many widgets in your sidebars hold a MailPoet form: MailPoet's own
 * widget, or a block, text, or HTML widget with the shortcode or block in it.
 *
 * @return int
 */
function npmp_mailpoet_count_form_widgets() {
	$sidebars = get_option( 'sidebars_widgets', array() );
	$cache    = array();
	$count    = 0;
	foreach ( (array) $sidebars as $sidebar => $widgets ) {
		if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widgets ) ) {
			continue;
		}
		foreach ( $widgets as $widget_id ) {
			if ( ! is_string( $widget_id ) || ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
				continue;
			}
			if ( 'mailpoet_form' === $m[1] ) {
				++$count;
				continue;
			}
			if ( ! in_array( $m[1], array( 'block', 'text', 'custom_html' ), true ) ) {
				continue;
			}
			if ( ! isset( $cache[ $m[1] ] ) ) {
				$cache[ $m[1] ] = (array) get_option( 'widget_' . $m[1], array() );
			}
			$instance = $cache[ $m[1] ][ (int) $m[2] ] ?? array();
			$content  = is_array( $instance ) ? ( $instance['content'] ?? ( $instance['text'] ?? '' ) ) : '';
			if ( npmp_mailpoet_form_kinds( $content ) ) {
				++$count;
			}
		}
	}
	return $count;
}

/**
 * Active plugins the deactivate step turns off: add-ons first, MailPoet last.
 *
 * @return array<string,string> Basename => plugin name.
 */
function npmp_mailpoet_plugins_to_deactivate() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all      = get_plugins();
	$mailpoet = npmp_mailpoet_plugin_file();
	$out      = array();
	foreach ( $all as $file => $data ) {
		if ( $file !== $mailpoet && is_plugin_active( $file ) && npmp_mailpoet_plugin_needs_mailpoet( $file, $data ) ) {
			$out[ $file ] = $data['Name'];
		}
	}
	if ( $mailpoet && is_plugin_active( $mailpoet ) ) {
		$out[ $mailpoet ] = $all[ $mailpoet ]['Name'];
	}
	return $out;
}

/**
 * Deactivate MailPoet and every active plugin that needs it. Deletes nothing,
 * so MailPoet's subscribers, lists, emails, and settings stay in the database.
 *
 * @return array<string,string> Plugins turned off, basename => name.
 */
function npmp_mailpoet_deactivate() {
	$plugins = npmp_mailpoet_plugins_to_deactivate();
	if ( ! $plugins ) {
		return array();
	}
	deactivate_plugins( array_keys( $plugins ) );

	$stage                = npmp_mailpoet_get_stage();
	$stage['deactivated'] = array_values( $plugins );
	npmp_mailpoet_save_stage( $stage );

	return array_filter(
		$plugins,
		static function ( $file ) {
			return ! is_plugin_active( $file );
		},
		ARRAY_FILTER_USE_KEY
	);
}

// =====================================================================
// Admin
// =====================================================================

if ( is_admin() ) {
	add_action( 'admin_menu', 'npmp_mailpoet_register_menu', 12 );
	add_action( 'admin_notices', 'npmp_mailpoet_admin_notice' );
	add_action( 'admin_enqueue_scripts', 'npmp_mailpoet_enqueue' );
	add_action( 'npmp_import_page_notices', 'npmp_mailpoet_import_screen_card' );
	add_action( 'wp_ajax_npmp_mailpoet_stage', 'npmp_mailpoet_ajax_stage' );
	add_action( 'wp_ajax_npmp_mailpoet_migrate', 'npmp_mailpoet_ajax_migrate' );
	add_action( 'admin_post_npmp_mailpoet_stage', 'npmp_mailpoet_post_stage' );
	add_action( 'admin_post_npmp_mailpoet_review', 'npmp_mailpoet_post_review' );
	add_action( 'admin_post_npmp_mailpoet_approve', 'npmp_mailpoet_post_approve' );
	add_action( 'admin_post_npmp_mailpoet_migrate', 'npmp_mailpoet_post_migrate' );
	add_action( 'admin_post_npmp_mailpoet_deactivate', 'npmp_mailpoet_post_deactivate' );
	add_action( 'admin_post_npmp_mailpoet_reset', 'npmp_mailpoet_post_reset' );
	add_action( 'admin_post_npmp_mailpoet_dismiss', 'npmp_mailpoet_post_dismiss' );
}

/**
 * URL of the importer screen.
 *
 * @param int   $step Step to open, 0 for wherever you left off.
 * @param array $args Extra query args.
 * @return string
 */
function npmp_mailpoet_page_url( $step = 0, array $args = array() ) {
	if ( $step ) {
		$args['step'] = (int) $step;
	}
	return add_query_arg( $args, admin_url( 'admin.php?page=npmp_mailpoet_import' ) );
}

/**
 * URL of the member list.
 *
 * @return string
 */
function npmp_mailpoet_members_url() {
	return admin_url( 'admin.php?page=npmp_members' );
}

/**
 * Register the importer screen next to Import, under Members.
 *
 * @return void
 */
function npmp_mailpoet_register_menu() {
	if ( ! npmp_mailpoet_importer_visible() ) {
		return;
	}
	$parent = function_exists( 'npmp_admin_menu_slug_exists' ) && npmp_admin_menu_slug_exists( 'npmp_membership' ) ? 'npmp_membership' : 'npmp_main';
	add_submenu_page(
		$parent,
		__( 'Import from MailPoet', 'nonprofit-manager' ),
		__( 'Import from MailPoet', 'nonprofit-manager' ),
		'manage_options',
		'npmp_mailpoet_import',
		'npmp_mailpoet_render_page'
	);
}

/**
 * The notice on the member and newsletter screens while MailPoet is active.
 *
 * @return void
 */
function npmp_mailpoet_admin_notice() {
	if ( ! npmp_mailpoet_is_active() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_user_meta( get_current_user_id(), 'npmp_mailpoet_notice_dismissed', true ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	$screens = array( 'toplevel_page_npmp_main', 'toplevel_page_npmp_membership', 'membership_page_npmp_members', 'toplevel_page_npmp-newsletters' );
	if ( ! in_array( $screen->id, $screens, true ) ) {
		return;
	}

	$stage   = npmp_mailpoet_get_stage();
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=npmp_mailpoet_dismiss' ), 'npmp_mailpoet_dismiss' );
	echo '<div class="notice notice-info npmp-tec-notice"><p>';
	if ( $stage['migrated'] ) {
		esc_html_e( 'You moved your MailPoet contacts. You can turn off MailPoet now and keep all of its data.', 'nonprofit-manager' );
		echo '</p><p><a class="button button-primary" href="' . esc_url( npmp_mailpoet_page_url( 5 ) ) . '">' . esc_html__( 'Turn off MailPoet', 'nonprofit-manager' ) . '</a> ';
	} else {
		echo esc_html(
			sprintf(
				/* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */
				__( 'You have MailPoet running. You can move its subscribers, lists, and sender settings into %s in five steps.', 'nonprofit-manager' ),
				npmp_label( 'app_name' )
			)
		);
		echo '</p><p><a class="button button-primary" href="' . esc_url( npmp_mailpoet_page_url() ) . '">' . esc_html__( 'Move my subscribers', 'nonprofit-manager' ) . '</a> ';
	}
	echo '<a class="button-link" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Not now', 'nonprofit-manager' ) . '</a></p></div>';
}

/**
 * A card at the top of the member Import screen.
 *
 * @return void
 */
function npmp_mailpoet_import_screen_card() {
	if ( ! npmp_mailpoet_importer_visible() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-info inline npmp-tec-card"><p><strong>' . esc_html__( 'Switching from MailPoet?', 'nonprofit-manager' ) . '</strong> ';
	esc_html_e( 'You can bring over every subscriber with their signup date, your lists, and your sender settings, with no file to export.', 'nonprofit-manager' );
	echo ' <a href="' . esc_url( npmp_mailpoet_page_url() ) . '">' . esc_html__( 'Import from MailPoet', 'nonprofit-manager' ) . '</a></p></div>';
}

/**
 * Load the batch runner's script and styles on the importer's own screen.
 * It shares both files with The Events Calendar importer.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_mailpoet_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_mailpoet_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-tec-import.js' ) : '1';
	wp_enqueue_style( 'npmp-tec-import', $base . 'assets/css/npmp-tec-import.css', array(), $ver );
	wp_enqueue_script( 'npmp-tec-import', $base . 'assets/js/npmp-tec-import.js', array(), $ver, true );
}

/**
 * Stop unless the user can import and the nonce checks out.
 *
 * @param string $cap Capability.
 * @return void
 */
function npmp_mailpoet_check_request( $cap = 'manage_options' ) {
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'npmp_mailpoet_import', 'npmp_mailpoet_nonce' );
}

/**
 * AJAX: read one batch.
 *
 * @return void
 */
function npmp_mailpoet_ajax_stage() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ) ), 403 );
	}
	check_ajax_referer( 'npmp_mailpoet_import', 'npmp_mailpoet_nonce' );
	if ( ! npmp_mailpoet_has_table() ) {
		wp_send_json_error( array( 'message' => __( 'This site has no MailPoet data to read.', 'nonprofit-manager' ) ) );
	}
	wp_send_json_success( npmp_mailpoet_stage_batch( ! empty( $_POST['restart'] ) ) );
}

/**
 * AJAX: migrate one batch.
 *
 * @return void
 */
function npmp_mailpoet_ajax_migrate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ) ), 403 );
	}
	check_ajax_referer( 'npmp_mailpoet_import', 'npmp_mailpoet_nonce' );
	wp_send_json_success( npmp_mailpoet_migrate_batch() );
}

/**
 * Run batches for up to 20 seconds. The no-JavaScript fallback.
 *
 * @param callable $batch Batch function returning an array with 'done'.
 * @return void
 */
function npmp_mailpoet_run_for_a_while( $batch ) {
	$until = microtime( true ) + 20;
	do {
		$result = call_user_func( $batch );
	} while ( empty( $result['done'] ) && microtime( true ) < $until );
}

/**
 * Form: read subscribers without JavaScript.
 *
 * @return void
 */
function npmp_mailpoet_post_stage() {
	npmp_mailpoet_check_request();
	$restart = ! empty( $_POST['restart'] );
	npmp_mailpoet_run_for_a_while(
		static function () use ( &$restart ) {
			$result  = npmp_mailpoet_stage_batch( $restart );
			$restart = false;
			return $result;
		}
	);
	wp_safe_redirect( npmp_mailpoet_page_url() );
	exit;
}

/**
 * Form: save the review screen.
 *
 * @return void
 */
function npmp_mailpoet_post_review() {
	npmp_mailpoet_check_request();
	$statuses = array_map( 'sanitize_key', (array) wp_unslash( $_POST['npmp_mailpoet_statuses'] ?? array() ) );
	$lists    = npmp_mailpoet_clean_ids( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['npmp_mailpoet_lists'] ?? array() ) ) );
	npmp_mailpoet_set_review( $statuses, $lists );
	wp_safe_redirect( npmp_mailpoet_page_url( 3 ) );
	exit;
}

/**
 * Form: approve and start the migration.
 *
 * @return void
 */
function npmp_mailpoet_post_approve() {
	npmp_mailpoet_check_request();
	$settings = array_keys( array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['npmp_mailpoet_settings'] ?? array() ) ) ) );
	npmp_mailpoet_approve( array_map( 'sanitize_key', $settings ) );
	wp_safe_redirect( npmp_mailpoet_page_url( 4 ) );
	exit;
}

/**
 * Form: migrate without JavaScript.
 *
 * @return void
 */
function npmp_mailpoet_post_migrate() {
	npmp_mailpoet_check_request();
	npmp_mailpoet_run_for_a_while( 'npmp_mailpoet_migrate_batch' );
	wp_safe_redirect( npmp_mailpoet_page_url( 4 ) );
	exit;
}

/**
 * Form: deactivate MailPoet and its add-ons.
 *
 * @return void
 */
function npmp_mailpoet_post_deactivate() {
	npmp_mailpoet_check_request( 'activate_plugins' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	npmp_mailpoet_deactivate();
	wp_safe_redirect( npmp_mailpoet_page_url( 5, array( 'off' => 1 ) ) );
	exit;
}

/**
 * Form: start over with a fresh summary.
 *
 * @return void
 */
function npmp_mailpoet_post_reset() {
	npmp_mailpoet_check_request();
	npmp_mailpoet_reset_stage();
	wp_safe_redirect( npmp_mailpoet_page_url( 1 ) );
	exit;
}

/**
 * Link: hide the notice for this user.
 *
 * @return void
 */
function npmp_mailpoet_post_dismiss() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'npmp_mailpoet_dismiss' );
	update_user_meta( get_current_user_id(), 'npmp_mailpoet_notice_dismissed', 1 );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=npmp_main' ) );
	exit;
}

/**
 * The furthest step you can open with the summary as it stands.
 *
 * @param array $stage Stage.
 * @return int
 */
function npmp_mailpoet_max_step( array $stage ) {
	if ( ! $stage['done'] ) {
		return 1;
	}
	if ( ! $stage['reviewed'] ) {
		return 2;
	}
	if ( ! $stage['approved'] ) {
		return 3;
	}
	if ( ! $stage['migrated'] ) {
		return 4;
	}
	return 5;
}

/**
 * Render a hidden nonce plus an action for an admin-post form.
 *
 * @param string $action admin-post action.
 * @return void
 */
function npmp_mailpoet_form_fields( $action ) {
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( 'npmp_mailpoet_import', 'npmp_mailpoet_nonce' );
}

/**
 * What to call each MailPoet status on screen.
 *
 * @return array<string,string>
 */
function npmp_mailpoet_status_labels() {
	return array(
		'subscribed'   => __( 'Subscribed', 'nonprofit-manager' ),
		'unconfirmed'  => __( 'Unconfirmed', 'nonprofit-manager' ),
		'inactive'     => __( 'Inactive', 'nonprofit-manager' ),
		'unsubscribed' => __( 'Unsubscribed', 'nonprofit-manager' ),
		'bounced'      => __( 'Bounced', 'nonprofit-manager' ),
		'other'        => __( 'Other', 'nonprofit-manager' ),
	);
}

/**
 * What to call a contact status on screen, in the member list's own words.
 *
 * @param string $status Contact status.
 * @return string
 */
function npmp_mailpoet_contact_status_label( $status ) {
	$labels = class_exists( 'NPMP_Member_Manager' ) ? NPMP_Member_Manager::get_instance()->get_statuses() : array();
	return (string) ( $labels[ $status ] ?? ucfirst( $status ) );
}

/**
 * The importer screen.
 *
 * @return void
 */
function npmp_mailpoet_render_page() {
	npmp_verify_admin_access( 'manage_options' );

	$stage = npmp_mailpoet_get_stage();
	$max   = npmp_mailpoet_max_step( $stage );
	$step  = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : $max; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
	if ( $step < 1 || $step > $max || ( $max >= 4 && $step < 4 ) ) {
		$step = $max;
	}

	$labels = array(
		1 => __( 'Import', 'nonprofit-manager' ),
		2 => __( 'Review', 'nonprofit-manager' ),
		3 => __( 'Approve', 'nonprofit-manager' ),
		4 => __( 'Migrate', 'nonprofit-manager' ),
		5 => __( 'Turn off MailPoet', 'nonprofit-manager' ),
	);

	echo '<div class="wrap npmp-tec-wrap npmp-mailpoet-wrap">';
	echo '<h1>' . esc_html__( 'Import from MailPoet', 'nonprofit-manager' ) . '</h1>';
	/* translators: 1: step number, 2: total steps, 3: step name. */
	echo '<p class="npmp-tec-progress-line">' . esc_html( sprintf( __( 'Step %1$d of %2$d: %3$s', 'nonprofit-manager' ), $step, 5, $labels[ $step ] ) ) . '</p>';
	echo '<ol class="npmp-tec-steps">';
	foreach ( $labels as $n => $label ) {
		$class = $n === $step ? 'is-current' : ( $n < $step ? 'is-done' : '' );
		echo '<li class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</li>';
	}
	echo '</ol>';

	switch ( $step ) {
		case 2:
			npmp_mailpoet_render_review( $stage );
			break;
		case 3:
			npmp_mailpoet_render_approve( $stage );
			break;
		case 4:
			npmp_mailpoet_render_migrate( $stage );
			break;
		case 5:
			npmp_mailpoet_render_deactivate( $stage );
			break;
		default:
			npmp_mailpoet_render_import( $stage );
	}

	if ( $stage['read'] ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-tec-reset">';
		npmp_mailpoet_form_fields( 'npmp_mailpoet_reset' );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Start over with a fresh count', 'nonprofit-manager' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Contacts you already moved stay put.', 'nonprofit-manager' ) . '</span></form>';
	}
	echo '</div>';
}

/**
 * A progress bar the shared script drives through admin-ajax, with a plain
 * form underneath for browsers without JavaScript.
 *
 * @param string $ajax_action wp_ajax action.
 * @param string $post_action admin-post fallback action.
 * @param string $button      Button label.
 * @param string $next_url    Where to go when done.
 * @param bool   $autostart   Start without a click.
 * @param bool   $restart     Send restart=1 on the first request.
 * @return void
 */
function npmp_mailpoet_render_runner( $ajax_action, $post_action, $button, $next_url, $autostart = false, $restart = false ) {
	printf(
		'<div class="npmp-tec-runner" data-ajax-url="%1$s" data-action="%2$s" data-nonce="%3$s" data-nonce-name="npmp_mailpoet_nonce" data-next="%4$s" data-autostart="%5$s" data-restart="%6$s" data-label-progress="%7$s" data-label-error="%8$s">',
		esc_url( admin_url( 'admin-ajax.php' ) ),
		esc_attr( $ajax_action ),
		esc_attr( wp_create_nonce( 'npmp_mailpoet_import' ) ),
		esc_url( $next_url ),
		$autostart ? '1' : '0',
		$restart ? '1' : '0',
		/* translators: 1: subscribers done so far, 2: total subscribers. Keep the placeholders. */
		esc_attr__( '%1$s of %2$s subscribers', 'nonprofit-manager' ),
		esc_attr__( 'Something went wrong. Reload the page to pick up where it stopped.', 'nonprofit-manager' )
	);
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-tec-runner-form">';
	npmp_mailpoet_form_fields( $post_action );
	if ( $restart ) {
		echo '<input type="hidden" name="restart" value="1">';
	}
	echo '<button type="submit" class="button button-primary button-hero npmp-tec-runner-start">' . esc_html( $button ) . '</button></form>';
	echo '<div class="npmp-tec-bar" hidden><div class="npmp-tec-bar-fill"></div></div>';
	echo '<p class="npmp-tec-bar-text" aria-live="polite"></p>';
	echo '</div>';
}

/**
 * Step 1: count the subscribers.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_mailpoet_render_import( array $stage ) {
	if ( ! npmp_mailpoet_has_table() ) {
		echo '<p>' . esc_html__( 'This site has no MailPoet data to read. If you deleted MailPoet with its data, you can still bring your subscribers in from a CSV file on the Import screen.', 'nonprofit-manager' ) . '</p>';
		return;
	}

	$total = npmp_mailpoet_count_subscribers();
	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: number of subscribers. */
			_n( 'You have %s subscriber in MailPoet.', 'You have %s subscribers in MailPoet.', $total, 'nonprofit-manager' ),
			number_format_i18n( $total )
		)
	) . '</p>';
	echo '<p>' . esc_html__( 'You bring over each subscriber\'s email, name, status, and signup date, plus your lists and your sender settings. You leave past newsletters, automations, stats, and WooCommerce segments in MailPoet.', 'nonprofit-manager' ) . '</p>';
	echo '<p>' . esc_html__( 'First you count your subscribers and lists. Nothing moves yet, and MailPoet keeps all of its data. You pick what moves on the next screen.', 'nonprofit-manager' ) . '</p>';
	if ( ! npmp_mailpoet_is_active() ) {
		echo '<p class="description">' . esc_html__( 'MailPoet is off, so you read its data straight from your database.', 'nonprofit-manager' ) . '</p>';
	}
	if ( $stage['read'] && ! $stage['done'] ) {
		/* translators: 1: subscribers read so far, 2: total subscribers. */
		echo '<p>' . esc_html( sprintf( __( 'You read %1$s of %2$s subscribers so far. Keep going to finish the count.', 'nonprofit-manager' ), number_format_i18n( $stage['read'] ), number_format_i18n( $total ) ) ) . '</p>';
	}
	npmp_mailpoet_render_runner(
		'npmp_mailpoet_stage',
		'npmp_mailpoet_stage',
		$stage['read'] ? __( 'Keep reading', 'nonprofit-manager' ) : __( 'Read my subscribers', 'nonprofit-manager' ),
		npmp_mailpoet_page_url( 2 ),
		false,
		! $stage['read']
	);
}

/**
 * Step 2: what each status becomes, your lists, and a sample.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_mailpoet_render_review( array $stage ) {
	if ( ! $stage['read'] ) {
		echo '<p>' . esc_html__( 'MailPoet has no subscribers to move.', 'nonprofit-manager' ) . '</p>';
		return;
	}

	$skip_statuses = array_flip( (array) $stage['skip_statuses'] );
	$skip_lists    = array_flip( (array) $stage['skip_lists'] );
	$labels        = npmp_mailpoet_status_labels();
	$groups        = npmp_mailpoet_groups_supported();

	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: number of subscribers. */
			_n( 'You read %s subscriber. Uncheck any status you don\'t want to move.', 'You read %s subscribers. Uncheck any status you don\'t want to move.', (int) $stage['read'], 'nonprofit-manager' ),
			number_format_i18n( (int) $stage['read'] )
		)
	) . '</p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-mailpoet-review-form">';
	npmp_mailpoet_form_fields( 'npmp_mailpoet_review' );

	echo '<div class="npmp-tec-table-wrap"><table class="widefat striped npmp-tec-table"><thead><tr>';
	echo '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Move', 'nonprofit-manager' ) . '</span></td>';
	echo '<th scope="col">' . esc_html__( 'Status in MailPoet', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Subscribers', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Status after the move', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( npmp_mailpoet_status_keys() as $key ) {
		$count = (int) ( $stage['counts'][ $key ] ?? 0 );
		if ( ! $count ) {
			continue;
		}
		printf(
			'<tr><th scope="row" class="check-column"><input type="checkbox" name="npmp_mailpoet_statuses[]" value="%1$s" %2$s aria-label="%3$s"></th><td><strong>%4$s</strong></td><td>%5$s</td><td>%6$s</td></tr>',
			esc_attr( $key ),
			checked( ! isset( $skip_statuses[ $key ] ), true, false ),
			/* translators: %s: MailPoet status name, such as Subscribed. */
			esc_attr( sprintf( __( 'Move %s subscribers', 'nonprofit-manager' ), $labels[ $key ] ) ),
			esc_html( $labels[ $key ] ),
			esc_html( number_format_i18n( $count ) ),
			esc_html( npmp_mailpoet_contact_status_label( npmp_mailpoet_map_status( $key ) ) )
		);
	}
	echo '</tbody></table></div>';

	echo '<ul class="ul-disc">';
	echo '<li>' . esc_html__( 'Only confirmed subscribers get your newsletters after the move. Unconfirmed ones wait as Pending until they sign up again and confirm.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Keep Unsubscribed and Bounced checked. You bring those people over as Unsubscribed, so a later import can\'t put them back on your list.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Subscribers in MailPoet\'s trash stay in MailPoet.', 'nonprofit-manager' ) . '</li>';
	if ( $stage['plan']['existing'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of contacts. */
				_n( '%s subscriber is already a contact here. You update that contact and make no duplicate.', '%s subscribers are already contacts here. You update those contacts and make no duplicates.', (int) $stage['plan']['existing'], 'nonprofit-manager' ),
				number_format_i18n( (int) $stage['plan']['existing'] )
			)
		) . '</li>';
	}
	if ( $stage['plan']['stay_unsubbed'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of contacts. */
				_n( '%s of them already unsubscribed here, and stays unsubscribed.', '%s of them already unsubscribed here, and they stay unsubscribed.', (int) $stage['plan']['stay_unsubbed'], 'nonprofit-manager' ),
				number_format_i18n( (int) $stage['plan']['stay_unsubbed'] )
			)
		) . '</li>';
	}
	if ( $stage['plan']['invalid'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of subscribers. */
				_n( '%s subscriber has a broken email address, so that one stays in MailPoet.', '%s subscribers have broken email addresses, so they stay in MailPoet.', (int) $stage['plan']['invalid'], 'nonprofit-manager' ),
				number_format_i18n( (int) $stage['plan']['invalid'] )
			)
		) . '</li>';
	}
	echo '</ul>';

	echo '<h2>' . esc_html__( 'Lists', 'nonprofit-manager' ) . '</h2>';
	if ( ! $stage['lists'] ) {
		echo '<p>' . esc_html__( 'MailPoet has no lists of its own to move.', 'nonprofit-manager' ) . '</p>';
	} else {
		if ( $groups ) {
			echo '<p>' . esc_html__( 'Each checked list becomes a group with the same name. A subscriber on a list joins that list\'s group. Someone who left a list stays out of its group.', 'nonprofit-manager' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Lists become groups, and groups come with Pro. Your contacts still come over without their lists.', 'nonprofit-manager' );
			echo ' <a href="' . esc_url( npmp_get_upgrade_url( 'groups' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Pro', 'nonprofit-manager' ) . '</a></p>';
		}
		echo '<div class="npmp-tec-table-wrap"><table class="widefat striped npmp-tec-table"><thead><tr>';
		echo '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Move', 'nonprofit-manager' ) . '</span></td>';
		echo '<th scope="col">' . esc_html__( 'List', 'nonprofit-manager' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Subscribed', 'nonprofit-manager' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $stage['lists'] as $id => $list ) {
			printf(
				'<tr><th scope="row" class="check-column"><input type="checkbox" name="npmp_mailpoet_lists[]" value="%1$d" %2$s %3$s aria-label="%4$s"></th><td><strong>%5$s</strong></td><td>%6$s</td></tr>',
				(int) $id,
				checked( $groups && ! isset( $skip_lists[ (int) $id ] ), true, false ),
				disabled( $groups, false, false ),
				/* translators: %s: list name. */
				esc_attr( sprintf( __( 'Turn %s into a group', 'nonprofit-manager' ), $list['name'] ) ),
				esc_html( $list['name'] ),
				esc_html( number_format_i18n( (int) $list['count'] ) )
			);
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( 'You leave the WordPress Users list, WooCommerce lists, and segments in MailPoet.', 'nonprofit-manager' ) . '</p>';
	}

	if ( $stage['sample'] ) {
		echo '<details class="npmp-tec-left"><summary>' . esc_html(
			sprintf(
				/* translators: %s: number of subscribers. */
				_n( 'See the first %s subscriber', 'See the first %s subscribers', count( $stage['sample'] ), 'nonprofit-manager' ),
				number_format_i18n( count( $stage['sample'] ) )
			)
		) . '</summary><table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Name', 'nonprofit-manager' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Email', 'nonprofit-manager' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status in MailPoet', 'nonprofit-manager' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $stage['sample'] as $row ) {
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['email'] ) . '</td><td>' . esc_html( $labels[ $row['status'] ] ?? $row['status'] ) . '</td></tr>';
		}
		echo '</tbody></table></details>';
	}

	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'nonprofit-manager' ) . '</button></p></form>';
}

/**
 * Show one setting's value on the approve screen.
 *
 * @param string $key   Setting key.
 * @param mixed  $value Value.
 * @return string
 */
function npmp_mailpoet_setting_display( $key, $value ) {
	if ( 'double_optin' === $key ) {
		return $value ? __( 'On', 'nonprofit-manager' ) : __( 'Off', 'nonprofit-manager' );
	}
	return '' === (string) $value ? __( 'Not set', 'nonprofit-manager' ) : (string) $value;
}

/**
 * Step 3: approve, with a checkbox per setting. This form starts the migration.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_mailpoet_render_approve( array $stage ) {
	$count = npmp_mailpoet_selected_count( $stage );
	if ( ! $count ) {
		echo '<p>' . esc_html__( 'You unchecked every status, so nothing will move.', 'nonprofit-manager' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( npmp_mailpoet_page_url( 2 ) ) . '">' . esc_html__( 'Back to review', 'nonprofit-manager' ) . '</a></p>';
		return;
	}

	$lists = npmp_mailpoet_selected_lists( $stage );
	$max   = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;

	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: number of subscribers. */
			_n( 'You\'re moving %s MailPoet subscriber into your contacts.', 'You\'re moving %s MailPoet subscribers into your contacts.', $count, 'nonprofit-manager' ),
			number_format_i18n( $count )
		)
	) . '</p>';
	echo '<ul class="ul-disc">';
	echo '<li>' . esc_html__( 'Each contact keeps their email, name, status, and the date they first signed up.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Nobody who unsubscribed or bounced becomes subscribed, and a contact who already unsubscribed here stays unsubscribed.', 'nonprofit-manager' ) . '</li>';
	if ( $lists ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of lists. */
				_n( '%s list becomes a group.', '%s lists become groups.', count( $lists ), 'nonprofit-manager' ),
				number_format_i18n( count( $lists ) )
			)
		) . '</li>';
	} elseif ( $stage['lists'] && ! npmp_mailpoet_groups_supported() ) {
		echo '<li>' . esc_html__( 'Your lists stay in MailPoet, since groups come with Pro.', 'nonprofit-manager' ) . '</li>';
	}
	echo '<li>' . esc_html__( 'MailPoet keeps its own copy, so you can switch it back on if something looks wrong.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	if ( $count > $max ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: 1: free import limit, 2: number of subscribers. */
				__( 'You can move %1$s contacts with the free version. With Pro you can move all %2$s in one pass.', 'nonprofit-manager' ),
				number_format_i18n( $max ),
				number_format_i18n( $count )
			)
		) . ' <a href="' . esc_url( npmp_get_upgrade_url( 'import_cap' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Pro', 'nonprofit-manager' ) . '</a></p></div>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-tec-migrate-form">';
	npmp_mailpoet_form_fields( 'npmp_mailpoet_approve' );

	$plan = npmp_mailpoet_current_plan();
	echo '<h2>' . esc_html__( 'Settings', 'nonprofit-manager' ) . '</h2>';
	if ( ! $plan ) {
		echo '<p>' . esc_html__( 'MailPoet has no sender settings saved, so yours stay as they are.', 'nonprofit-manager' ) . '</p>';
	} else {
		$names = array(
			'from_name'    => __( 'From name', 'nonprofit-manager' ),
			'from_email'   => __( 'From address', 'nonprofit-manager' ),
			'reply_to'     => __( 'Reply-to address', 'nonprofit-manager' ),
			'double_optin' => __( 'Confirm new subscribers by email', 'nonprofit-manager' ),
		);
		echo '<p>' . esc_html__( 'Tick a setting to copy MailPoet\'s value over yours. An unticked setting stays as it is.', 'nonprofit-manager' ) . '</p>';
		echo '<div class="npmp-tec-table-wrap"><table class="widefat striped npmp-tec-table"><thead><tr>';
		echo '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Copy', 'nonprofit-manager' ) . '</span></td>';
		echo '<th scope="col">' . esc_html__( 'Setting', 'nonprofit-manager' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'In MailPoet', 'nonprofit-manager' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Here now', 'nonprofit-manager' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $plan as $key => $row ) {
			echo '<tr><th scope="row" class="check-column">';
			if ( 'change' === $row['state'] ) {
				printf(
					'<input type="checkbox" name="npmp_mailpoet_settings[%1$s]" value="1" %2$s aria-label="%3$s">',
					esc_attr( $key ),
					checked( $row['checked'], true, false ),
					/* translators: %s: setting name, such as From name. */
					esc_attr( sprintf( __( 'Copy %s from MailPoet', 'nonprofit-manager' ), $names[ $key ] ) )
				);
			}
			echo '</th><td><strong>' . esc_html( $names[ $key ] ) . '</strong></td>';
			echo '<td>' . esc_html( npmp_mailpoet_setting_display( $key, $row['mailpoet'] ) ) . '</td><td>';
			if ( 'unsupported' === $row['state'] ) {
				esc_html_e( 'No matching setting here, so you skip this one. Replies go to your From address.', 'nonprofit-manager' );
			} elseif ( 'same' === $row['state'] ) {
				echo esc_html( npmp_mailpoet_setting_display( $key, $row['current'] ) ) . ' <span class="description">' . esc_html__( '(already matches)', 'nonprofit-manager' ) . '</span>';
			} else {
				echo esc_html( npmp_mailpoet_setting_display( $key, $row['current'] ) );
				if ( 'double_optin' === $key && ! $row['mailpoet'] ) {
					echo '<br><span class="description">' . esc_html__( 'With this off, anyone can put a stranger\'s address on your list.', 'nonprofit-manager' ) . '</span>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '<p class="description">' . esc_html__( 'You never copy MailPoet\'s sending method, SMTP password, or API keys. Set up sending under Email Settings.', 'nonprofit-manager' ) . '</p>';

	echo '<p><a class="button" href="' . esc_url( npmp_mailpoet_page_url( 2 ) ) . '">' . esc_html__( 'Back to review', 'nonprofit-manager' ) . '</a> ';
	echo '<button type="submit" class="button button-primary">' . esc_html(
		sprintf(
			/* translators: %s: number of subscribers. */
			_n( 'Approve and move %s subscriber', 'Approve and move %s subscribers', min( $count, $max ), 'nonprofit-manager' ),
			number_format_i18n( min( $count, $max ) )
		)
	) . '</button></p></form>';
}

/**
 * Step 4: the migration's progress, then what happened.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_mailpoet_render_migrate( array $stage ) {
	if ( ! $stage['migrated'] ) {
		echo '<p>' . esc_html__( 'Keep this page open while your subscribers move. They go 100 at a time.', 'nonprofit-manager' ) . '</p>';
		npmp_mailpoet_render_runner( 'npmp_mailpoet_migrate', 'npmp_mailpoet_migrate', __( 'Move my subscribers', 'nonprofit-manager' ), npmp_mailpoet_page_url( 4 ), true );
		return;
	}

	$r = $stage['results'];
	echo '<div class="notice notice-success inline"><p>' . esc_html(
		sprintf(
			/* translators: %s: number of contacts. */
			_n( 'You added %s new contact.', 'You added %s new contacts.', (int) $r['created'], 'nonprofit-manager' ),
			number_format_i18n( (int) $r['created'] )
		)
	) . '</p></div>';

	echo '<ul class="ul-disc">';
	if ( $r['updated'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of contacts. */
				_n( 'You updated %s contact who was already here.', 'You updated %s contacts who were already here.', (int) $r['updated'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['updated'] )
			)
		) . '</li>';
	}
	if ( $r['unchanged'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of contacts. */
				_n( '%s contact was already here and up to date.', '%s contacts were already here and up to date.', (int) $r['unchanged'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['unchanged'] )
			)
		) . '</li>';
	}
	if ( $r['kept_unsubscribed'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of contacts. */
				_n( '%s contact had already unsubscribed here, and stays unsubscribed.', '%s contacts had already unsubscribed here, and they stay unsubscribed.', (int) $r['kept_unsubscribed'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['kept_unsubscribed'] )
			)
		) . '</li>';
	}
	if ( $r['invalid'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of subscribers. */
				_n( '%s subscriber stayed in MailPoet with a broken email address.', '%s subscribers stayed in MailPoet with broken email addresses.', (int) $r['invalid'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['invalid'] )
			)
		) . '</li>';
	}
	$lists = npmp_mailpoet_selected_lists( $stage );
	if ( $lists ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: comma-separated list names. */
				_n( 'This list is now a group: %s.', 'These lists are now groups: %s.', count( $lists ), 'nonprofit-manager' ),
				implode( ', ', $lists )
			)
		) . '</li>';
	} elseif ( $stage['lists'] && ! npmp_mailpoet_groups_supported() ) {
		echo '<li>' . esc_html__( 'Your lists stayed in MailPoet, since groups come with Pro. You can run this import again after you add Pro to bring them over.', 'nonprofit-manager' ) . '</li>';
	}
	$setting_names = array(
		'from_name'    => __( 'From name', 'nonprofit-manager' ),
		'from_email'   => __( 'From address', 'nonprofit-manager' ),
		'double_optin' => __( 'Confirm new subscribers by email', 'nonprofit-manager' ),
	);
	foreach ( (array) $stage['settings_applied'] as $key => $value ) {
		if ( isset( $setting_names[ $key ] ) ) {
			/* translators: 1: setting name, 2: its new value. */
			echo '<li>' . esc_html( sprintf( __( 'You set %1$s to %2$s.', 'nonprofit-manager' ), $setting_names[ $key ], npmp_mailpoet_setting_display( $key, $value ) ) ) . '</li>';
		}
	}
	echo '</ul>';

	if ( $r['cap_reached'] ) {
		$max = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : 0;
		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: %s: free import limit. */
				__( 'You moved the first %s contacts, the limit in the free version. The rest are still in MailPoet. With Pro you can run this import again and move everyone.', 'nonprofit-manager' ),
				number_format_i18n( $max )
			)
		) . ' <a href="' . esc_url( npmp_get_upgrade_url( 'import_cap' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Pro', 'nonprofit-manager' ) . '</a></p></div>';
	}
	if ( $r['failed'] ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These subscribers didn\'t move:', 'nonprofit-manager' ) . '</p><ul class="ul-disc">';
		foreach ( $r['failed'] as $fail ) {
			echo '<li>' . esc_html( $fail['email'] . ': ' . $fail['error'] ) . '</li>';
		}
		echo '</ul></div>';
	}
	echo '<p><a class="button" href="' . esc_url( npmp_mailpoet_members_url() ) . '">' . esc_html__( 'See your contacts', 'nonprofit-manager' ) . '</a> ';
	echo '<a class="button button-primary" href="' . esc_url( npmp_mailpoet_page_url( 5 ) ) . '">' . esc_html__( 'Continue', 'nonprofit-manager' ) . '</a></p>';
}

/**
 * The pages that still hold a MailPoet signup form, each with an edit link.
 *
 * @return void
 */
function npmp_mailpoet_render_forms_list() {
	$posts   = npmp_mailpoet_find_form_posts();
	$widgets = npmp_mailpoet_count_form_widgets();
	if ( ! $posts && ! $widgets ) {
		echo '<p>' . esc_html__( 'You have no MailPoet signup form on a published page, post, or widget.', 'nonprofit-manager' ) . '</p>';
		return;
	}

	echo '<h2>' . esc_html__( 'Signup forms to replace', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . wp_kses(
		sprintf(
			/* translators: %s: the signup form shortcode. */
			__( 'A MailPoet form shows nothing once MailPoet is off. Open each of these and put %s where the MailPoet form is, or add the Email Signup block.', 'nonprofit-manager' ),
			'<code>[npmp_email_signup]</code>'
		),
		array( 'code' => array() )
	) . '</p>';
	if ( $posts ) {
		echo '<ul class="ul-disc">';
		foreach ( $posts as $post ) {
			$type = get_post_type_object( $post['type'] );
			$link = get_edit_post_link( $post['id'] );
			echo '<li>';
			if ( $link ) {
				echo '<a href="' . esc_url( $link ) . '">' . esc_html( $post['title'] ) . '</a>';
			} else {
				echo esc_html( $post['title'] );
			}
			echo ' <span class="description">(' . esc_html( $type ? $type->labels->singular_name : $post['type'] ) . ')</span></li>';
		}
		echo '</ul>';
	}
	if ( $widgets ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of widgets. */
				_n( 'You also have %s widget with a MailPoet form.', 'You also have %s widgets with a MailPoet form.', $widgets, 'nonprofit-manager' ),
				number_format_i18n( $widgets )
			)
		) . ' <a href="' . esc_url( admin_url( 'widgets.php' ) ) . '">' . esc_html__( 'Open Widgets', 'nonprofit-manager' ) . '</a></p>';
	}
}

/**
 * Step 5: the forms left on the site, then turn MailPoet off, with a confirm
 * screen that names every plugin.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_mailpoet_render_deactivate( array $stage ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		npmp_mailpoet_render_forms_list();
		echo '<p>' . esc_html__( 'Ask someone who can manage plugins on this site to turn off MailPoet.', 'nonprofit-manager' ) . '</p>';
		return;
	}

	$plugins = npmp_mailpoet_plugins_to_deactivate();
	if ( ! $plugins ) {
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'MailPoet is off. Its subscribers, lists, emails, and settings are still in your database, so you can turn it back on from Plugins if something looks wrong.', 'nonprofit-manager' ) . '</p></div>';
		if ( $stage['deactivated'] ) {
			echo '<p>' . esc_html__( 'You turned off:', 'nonprofit-manager' ) . '</p><ul class="ul-disc">';
			foreach ( $stage['deactivated'] as $name ) {
				echo '<li>' . esc_html( $name ) . '</li>';
			}
			echo '</ul>';
		}
		npmp_mailpoet_render_forms_list();
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_mailpoet_members_url() ) . '">' . esc_html__( 'See your contacts', 'nonprofit-manager' ) . '</a></p>';
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only. The deactivate form below carries the nonce.
	$confirm = ! empty( $_GET['confirm'] );
	if ( ! $confirm ) {
		echo '<p>' . esc_html__( 'You moved your subscribers. Once you check a few of the new contacts, you can turn off MailPoet so it stops loading on every page.', 'nonprofit-manager' ) . '</p>';
		if ( $stage['results']['cap_reached'] ) {
			echo '<p><strong>' . esc_html__( 'Some subscribers are still only in MailPoet. Move the rest first, or leave MailPoet running.', 'nonprofit-manager' ) . '</strong></p>';
		}
		echo '<p>' . esc_html__( 'You delete nothing here. MailPoet keeps its subscribers, lists, emails, and settings, so you can turn it back on from Plugins.', 'nonprofit-manager' ) . '</p>';
		npmp_mailpoet_render_forms_list();
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_mailpoet_page_url( 5, array( 'confirm' => 1 ) ) ) . '">' . esc_html__( 'Turn off MailPoet', 'nonprofit-manager' ) . '</a></p>';
		return;
	}

	echo '<p>' . esc_html(
		_n( 'You\'re about to turn off this plugin:', 'You\'re about to turn off these plugins:', count( $plugins ), 'nonprofit-manager' )
	) . '</p><ul class="ul-disc">';
	foreach ( $plugins as $name ) {
		echo '<li><strong>' . esc_html( $name ) . '</strong></li>';
	}
	echo '</ul>';
	if ( count( $plugins ) > 1 ) {
		echo '<p>' . esc_html__( 'The others can\'t run without MailPoet, so you turn them off together.', 'nonprofit-manager' ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_mailpoet_form_fields( 'npmp_mailpoet_deactivate' );
	echo '<p><a class="button" href="' . esc_url( npmp_mailpoet_page_url( 5 ) ) . '">' . esc_html__( 'Cancel', 'nonprofit-manager' ) . '</a> ';
	echo '<button type="submit" class="button button-primary">' . esc_html(
		sprintf(
			/* translators: %s: number of plugins. */
			_n( 'Yes, turn off %s plugin', 'Yes, turn off %s plugins', count( $plugins ), 'nonprofit-manager' ),
			number_format_i18n( count( $plugins ) )
		)
	) . '</button></p></form>';
}
