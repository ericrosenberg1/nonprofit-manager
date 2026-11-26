<?php
/**
 * The newsletter queue tries a failed send again, and a slow batch can't
 * outlive its run lock (NPM-2, NPM-5, NPM-6).
 *
 * Through 2026.11.26 a send that failed was marked failed for good, so one
 * minute of mail server trouble dropped that run's batch and the newsletter
 * read sent once nothing else waited. The table got attempts and retry_at, a
 * failed row waits 5, 15, then 45 minutes between tries, and only the fourth
 * failure marks it failed. The drain's query got the status_queued index,
 * through a schema version option, since the old one-time flag could never
 * reach an existing table. A batch now stops starting sends after 240
 * seconds and refreshes its lock every 30, since a run past the lock's 300
 * seconds let the next tick mail the same rows again.
 *
 * This loads the real drain, lock helpers, and schema upgrade against a fake
 * $wpdb that keeps the options and queue rows in arrays, with wp_mail() and
 * dbDelta() stubbed. The real database, the real SMTP conversation, and the
 * upgrade of a 2026.11.26 table: tests/rig/newsletter-queue-cron.php.
 *
 * Run: php tests/test-newsletter-queue-retry.php
 */

// npmp_create_newsletter_queue_table() requires wp-admin/includes/upgrade.php
// from ABSPATH. dbDelta() is stubbed below, so an empty file stands in.
$t_abspath = sys_get_temp_dir() . '/npmp-queue-retry-' . getmypid() . '/';
@mkdir( $t_abspath . 'wp-admin/includes', 0700, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test scratch folder.
file_put_contents( $t_abspath . 'wp-admin/includes/upgrade.php', "<?php\n" );
register_shutdown_function(
	static function () use ( $t_abspath ) {
		@unlink( $t_abspath . 'wp-admin/includes/upgrade.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $t_abspath . 'wp-admin/includes' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $t_abspath . 'wp-admin' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $t_abspath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
);
define( 'ABSPATH', $t_abspath );

require_once __DIR__ . '/bootstrap.php';

define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['t_options']    = array();
$GLOBALS['t_transients'] = array();
$GLOBALS['t_meta']       = array();
$GLOBALS['t_filters']    = array();
$GLOBALS['t_mail_plan']  = array();  // address => list of results, one per try. Runs out: true.
$GLOBALS['t_mail_log']   = array();  // addresses, one per try.
$GLOBALS['t_on_mail']    = null;     // Called after each try.
$GLOBALS['t_dbdelta']    = array();  // SQL handed to dbDelta().
$GLOBALS['t_alter_ok']   = true;     // Whether dbDelta() can change the table.
$GLOBALS['t_actions']    = array();  // hook => callbacks added at load.
$GLOBALS['t_ajax']       = false;    // wp_doing_ajax().
$GLOBALS['t_can']        = true;     // current_user_can().

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $hook ][] = $cb;
}
function wp_doing_ajax() {
	return $GLOBALS['t_ajax'];
}
function current_user_can( $cap ) {
	return $GLOBALS['t_can'];
}
function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $hook ][] = $cb;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['t_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) {}
function do_shortcode( $text ) {
	return '';
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_email( $v ) {
	return strtolower( trim( (string) $v ) );
}
function absint( $v ) {
	return abs( (int) $v );
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function get_transient( $name ) {
	return $GLOBALS['t_transients'][ $name ] ?? false;
}
function set_transient( $name, $value, $ttl = 0 ) {
	$GLOBALS['t_transients'][ $name ] = $value;
	return true;
}
function delete_transient( $name ) {
	unset( $GLOBALS['t_transients'][ $name ] );
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	return true;
}
function current_time( $type, $gmt = false ) {
	return 'mysql' === $type ? '2026-11-22 10:00:00' : time();
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['t_meta'][ $id ][ $key ] = $value;
	return true;
}
function get_post( $id ) {
	return (object) array(
		'ID'           => (int) $id,
		'post_type'    => 'npmp_newsletter',
		'post_content' => '<p>Hello</p>',
	);
}
function get_post_type( $id ) {
	return 'npmp_newsletter';
}
function get_the_title( $id ) {
	return 'Rig letter';
}
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		$item  = (array) $item;
		$out[] = $item[ $field ] ?? null;
	}
	return $out;
}
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$GLOBALS['t_mail_log'][] = $to;
	$plan                    = &$GLOBALS['t_mail_plan'][ $to ];
	$ok                      = $plan ? array_shift( $plan ) : true;
	if ( $GLOBALS['t_on_mail'] ) {
		( $GLOBALS['t_on_mail'] )( $to );
	}
	return $ok;
}
function dbDelta( $sql ) {
	$GLOBALS['t_dbdelta'][] = $sql;
	if ( $GLOBALS['t_alter_ok'] ) {
		$GLOBALS['wpdb']->columns = array_unique( array_merge( $GLOBALS['wpdb']->columns, array( 'attempts', 'retry_at' ) ) );
		$GLOBALS['wpdb']->indexes = array_unique( array_merge( $GLOBALS['wpdb']->indexes, array( 'status_queued' ) ) );
	}
	return array();
}

/** A prepared statement: the SQL as written plus its values. */
class T_Query {
	public $sql;
	public $args;
	public function __construct( $sql, $args ) {
		$this->sql  = $sql;
		$this->args = $args;
	}
}

/**
 * Just enough of $wpdb for the lock helpers, the drain, and the schema check:
 * the options table and the queue table, matched on the statements those
 * functions write.
 */
class T_Wpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $opts    = array();   // option_name => option_value, the live rows.
	public $rows    = array();   // queue id => row.
	public $columns = array( 'id', 'newsletter_id', 'user_id', 'email', 'status', 'queued_at', 'sent_at' );
	public $indexes = array( 'PRIMARY', 'newsletter_status' );
	public $selects = array();   // Batch SQL the drain ran.

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return new T_Query( $sql, $args );
	}

	public function get_charset_collate() {
		return '';
	}

	public function query( $q ) {
		$sql  = $q instanceof T_Query ? $q->sql : (string) $q;
		$args = $q instanceof T_Query ? $q->args : array();
		if ( false !== strpos( $sql, 'INSERT IGNORE INTO wp_options' ) ) {
			if ( array_key_exists( $args[0], $this->opts ) ) {
				return 0;
			}
			$this->opts[ $args[0] ] = $args[1];
			return 1;
		}
		if ( false !== strpos( $sql, 'UPDATE wp_options SET option_value' ) ) {
			list( $value, $name, $was ) = $args;
			if ( ! array_key_exists( $name, $this->opts ) || (string) $this->opts[ $name ] !== (string) $was || (string) $value === (string) $was ) {
				return 0;
			}
			$this->opts[ $name ] = $value;
			return 1;
		}
		throw new Exception( 'Unexpected query: ' . $sql );
	}

	public function get_var( $q ) {
		$sql  = $q instanceof T_Query ? $q->sql : (string) $q;
		$args = $q instanceof T_Query ? $q->args : array();
		if ( false !== strpos( $sql, 'SELECT option_value FROM wp_options' ) ) {
			return $this->opts[ $args[0] ] ?? null;
		}
		if ( false !== strpos( $sql, "SELECT COUNT(*) FROM wp_npmp_newsletter_queue WHERE newsletter_id = %d AND status = 'pending'" ) ) {
			return (string) count(
				array_filter(
					$this->rows,
					static fn( $r ) => (int) $r->newsletter_id === (int) $args[0] && 'pending' === $r->status
				)
			);
		}
		throw new Exception( 'Unexpected get_var: ' . $sql );
	}

	public function get_results( $q ) {
		$sql  = $q instanceof T_Query ? $q->sql : (string) $q;
		$args = $q instanceof T_Query ? $q->args : array();
		if ( 0 === strpos( $sql, 'SHOW COLUMNS FROM wp_npmp_newsletter_queue' ) ) {
			return array_map( static fn( $c ) => (object) array( 'Field' => $c ), $this->columns );
		}
		if ( 0 === strpos( $sql, 'SHOW INDEX FROM wp_npmp_newsletter_queue' ) ) {
			return array_map( static fn( $k ) => (object) array( 'Key_name' => $k ), $this->indexes );
		}
		if ( false !== strpos( $sql, "FROM wp_npmp_newsletter_queue WHERE status = 'pending'" ) ) {
			$this->selects[] = $sql;
			$retry           = false !== strpos( $sql, 'retry_at' );
			if ( $retry && ( ! in_array( 'retry_at', $this->columns, true ) || ! in_array( 'attempts', $this->columns, true ) ) ) {
				return null; // Unknown column, the way MySQL answers.
			}
			$now   = $retry ? $args[0] : null;
			$limit = (int) end( $args );
			$rows  = array_filter(
				$this->rows,
				static fn( $r ) => 'pending' === $r->status && ( ! $retry || null === $r->retry_at || $r->retry_at <= $now )
			);
			usort( $rows, static fn( $a, $b ) => array( $a->queued_at, $a->id ) <=> array( $b->queued_at, $b->id ) );
			$out = array();
			foreach ( array_slice( $rows, 0, $limit ) as $r ) {
				$copy = clone $r;
				if ( ! $retry ) {
					unset( $copy->attempts );
				}
				$out[] = $copy;
			}
			return $out;
		}
		throw new Exception( 'Unexpected get_results: ' . $sql );
	}

	/** The drain's fresh read of one row right before its send (no contact here). */
	public function get_row( $q ) {
		$sql  = $q instanceof T_Query ? $q->sql : (string) $q;
		$args = $q instanceof T_Query ? $q->args : array();
		if ( false !== strpos( $sql, 'FROM wp_npmp_newsletter_queue q LEFT JOIN' ) ) {
			$r = $this->rows[ (int) $args[2] ] ?? null;
			return $r ? (object) array( 'queue_status' => $r->status, 'contact_post' => null, 'contact_status' => null, 'contact_flag' => null ) : null;
		}
		throw new Exception( 'Unexpected get_row: ' . $sql );
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( 'wp_npmp_newsletter_queue' !== $table ) {
			throw new Exception( 'Unexpected update: ' . $table );
		}
		$id = (int) $where['id'];
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		foreach ( $data as $column => $value ) {
			if ( ! in_array( $column, $this->columns, true ) ) {
				return false; // Unknown column.
			}
		}
		foreach ( $data as $column => $value ) {
			$this->rows[ $id ]->$column = $value;
		}
		return 1;
	}

	public function delete( $table, $where, $formats = null ) {
		if ( 'wp_options' === $table ) {
			$name = $where['option_name'];
			if ( ! array_key_exists( $name, $this->opts ) ) {
				return 0;
			}
			if ( isset( $where['option_value'] ) && (string) $this->opts[ $name ] !== (string) $where['option_value'] ) {
				return 0;
			}
			unset( $this->opts[ $name ] );
			return 1;
		}
		if ( 'wp_npmp_newsletter_queue' === $table ) {
			$n = 0;
			foreach ( $this->rows as $id => $r ) {
				$match = true;
				foreach ( $where as $column => $value ) {
					$match = $match && (string) $r->$column === (string) $value;
				}
				if ( $match ) {
					unset( $this->rows[ $id ] );
					++$n;
				}
			}
			return $n;
		}
		throw new Exception( 'Unexpected delete: ' . $table );
	}

	/** Put rows in the queue for one newsletter, pending, never tried. */
	public function seed( $newsletter_id, $emails ) {
		foreach ( $emails as $email ) {
			$id                = count( $this->rows ) ? max( array_keys( $this->rows ) ) + 1 : 1;
			$this->rows[ $id ] = (object) array(
				'id'            => $id,
				'newsletter_id' => $newsletter_id,
				'user_id'       => 0,
				'email'         => $email,
				'status'        => 'pending',
				'queued_at'     => '2026-11-22 09:00:00',
				'sent_at'       => null,
				'attempts'      => 0,
				'retry_at'      => null,
			);
		}
	}

	/** The row for one address. */
	public function row( $email ) {
		foreach ( $this->rows as $r ) {
			if ( $r->email === $email ) {
				return $r;
			}
		}
		return null;
	}
}

$GLOBALS['wpdb'] = new T_Wpdb();

require_once dirname( __DIR__ ) . '/includes/email/helpers.php';
require_once dirname( __DIR__ ) . '/includes/activation-hooks.php';
require_once dirname( __DIR__ ) . '/includes/email-newsletter/class-newsletter-manager.php';

/**
 * Start a case: an empty queue on the current schema, no mail plans, no
 * filters.
 *
 * @param bool $current Whether the table is on the current schema.
 */
function fresh( $current = true ) {
	global $wpdb;
	$wpdb                    = new T_Wpdb();
	$GLOBALS['t_options']    = array( 'npmp_newsletter_rate_limit' => 10 );
	$GLOBALS['t_transients'] = array();
	$GLOBALS['t_meta']       = array();
	$GLOBALS['t_filters']    = array();
	$GLOBALS['t_mail_plan']  = array();
	$GLOBALS['t_mail_log']   = array();
	$GLOBALS['t_on_mail']    = null;
	$GLOBALS['t_dbdelta']    = array();
	$GLOBALS['t_alter_ok']   = true;
	if ( $current ) {
		$wpdb->columns                                         = array_merge( $wpdb->columns, array( 'attempts', 'retry_at' ) );
		$wpdb->indexes[]                                       = 'status_queued';
		$GLOBALS['t_options']['npmp_newsletter_queue_db_version'] = NPMP_NEWSLETTER_QUEUE_DB_VERSION;
	}
}

/** Pretend the wait ran out for every row waiting on a retry. */
function time_passes() {
	foreach ( $GLOBALS['wpdb']->rows as $r ) {
		if ( null !== $r->retry_at ) {
			$r->retry_at = gmdate( 'Y-m-d H:i:s', time() - 1 );
		}
	}
}

/** Seconds from now until a row's retry_at. */
function wait_of( $row ) {
	return strtotime( $row->retry_at . ' UTC' ) - time();
}

function sends_to( $email ) {
	return count( array_keys( $GLOBALS['t_mail_log'], $email, true ) );
}

echo "What a failed send becomes\n";
$d = NPMP_Newsletter_Manager::retry_delays();
check( 'three retries by default', array( 300, 900, 2700 ), $d );
check( 'the first failure waits 5 minutes', array( 'status' => 'pending', 'attempts' => 1, 'delay' => 300 ), NPMP_Newsletter_Manager::after_failed_send( 0, $d ) );
check( 'the second waits 15', array( 'status' => 'pending', 'attempts' => 2, 'delay' => 900 ), NPMP_Newsletter_Manager::after_failed_send( 1, $d ) );
check( 'the third waits 45', array( 'status' => 'pending', 'attempts' => 3, 'delay' => 2700 ), NPMP_Newsletter_Manager::after_failed_send( 2, $d ) );
check( 'the fourth is final', array( 'status' => 'failed', 'attempts' => 4, 'delay' => 0 ), NPMP_Newsletter_Manager::after_failed_send( 3, $d ) );
check( 'with no retries the first failure is final', 'failed', NPMP_Newsletter_Manager::after_failed_send( 0, array() )['status'] );
add_filter( 'npmp_newsletter_retry_delays', static fn() => 'nonsense' );
check( 'a filter that returns junk means no retries', array(), NPMP_Newsletter_Manager::retry_delays() );
$GLOBALS['t_filters'] = array();
add_filter( 'npmp_newsletter_retry_delays', static fn() => array( -60, '120' ) );
check( 'and a negative wait counts as none', array( 60, 120 ), NPMP_Newsletter_Manager::retry_delays() );
$GLOBALS['t_filters'] = array();

echo "One minute of mail server trouble no longer drops the batch\n";
fresh();
$wpdb->seed( 7, array( 'ann@example.org', 'bob@example.org', 'cy@example.org' ) );
$GLOBALS['t_mail_plan']['ann@example.org'] = array( false );
NPMP_Newsletter_Manager::process_queue();
$ann = $wpdb->row( 'ann@example.org' );
check( 'the drain asks only for rows whose wait ran out', true, false !== strpos( end( $wpdb->selects ), '( retry_at IS NULL OR retry_at <= %s )' ) );
check( 'Ann failed once and waits', 'pending', $ann->status );
check( 'with one try counted', 1, (int) $ann->attempts );
check( 'about 5 minutes out', true, abs( wait_of( $ann ) - 300 ) <= 2 );
check( 'and no sent time, since nothing went out', null, $ann->sent_at );
check( 'Bob went out', 'sent', $wpdb->row( 'bob@example.org' )->status );
check( 'counted as one try', 1, (int) $wpdb->row( 'bob@example.org' )->attempts );
check( 'the newsletter does not read sent while Ann waits', '', get_post_meta( 7, '_npmp_newsletter_status', true ) );
check( 'the lock is free again', array(), $wpdb->opts );

NPMP_Newsletter_Manager::process_queue();
check( 'a run inside the wait leaves Ann alone', 1, sends_to( 'ann@example.org' ) );

time_passes();
NPMP_Newsletter_Manager::process_queue();
$ann = $wpdb->row( 'ann@example.org' );
check( 'after the wait Ann goes out', 'sent', $ann->status );
check( 'on her second try', 2, (int) $ann->attempts );
check( 'mailed twice in all, once that failed', 2, sends_to( 'ann@example.org' ) );
check( 'Bob got one copy', 1, sends_to( 'bob@example.org' ) );
check( 'now the newsletter reads sent', 'sent', get_post_meta( 7, '_npmp_newsletter_status', true ) );

echo "A send that keeps failing is failed after the fourth try\n";
fresh();
$wpdb->seed( 8, array( 'dee@example.org' ) );
$GLOBALS['t_mail_plan']['dee@example.org'] = array( false, false, false, false, false );
$waits                                     = array();
for ( $i = 1; $i <= 4; $i++ ) {
	NPMP_Newsletter_Manager::process_queue();
	$dee = $wpdb->row( 'dee@example.org' );
	if ( 'pending' === $dee->status ) {
		$waits[] = (int) round( wait_of( $dee ) / 60 );
		check( "still waiting after try $i", '', get_post_meta( 8, '_npmp_newsletter_status', true ) );
	}
	time_passes();
}
check( 'the waits grow: 5, 15, 45 minutes', array( 5, 15, 45 ), $waits );
check( 'then the row is failed', 'failed', $dee->status );
check( 'after four tries', 4, (int) $dee->attempts );
check( 'with the time we gave up', '2026-11-22 10:00:00', $dee->sent_at );
check( 'and the newsletter reads sent, so Reports counts the failure', 'sent', get_post_meta( 8, '_npmp_newsletter_status', true ) );
NPMP_Newsletter_Manager::process_queue();
check( 'nobody tries a failed row again', 4, sends_to( 'dee@example.org' ) );

echo "A down mail server stops the batch after three failures in a row\n";
fresh();
$all = array( 'a1@example.org', 'a2@example.org', 'a3@example.org', 'a4@example.org', 'a5@example.org' );
$wpdb->seed( 9, $all );
foreach ( $all as $email ) {
	$GLOBALS['t_mail_plan'][ $email ] = array( false );
}
NPMP_Newsletter_Manager::process_queue();
check( 'three tries', 3, count( $GLOBALS['t_mail_log'] ) );
check( 'the fourth reader was never tried', 0, (int) $wpdb->row( 'a4@example.org' )->attempts );
check( 'and keeps no wait', null, $wpdb->row( 'a4@example.org' )->retry_at );
NPMP_Newsletter_Manager::process_queue();
check( 'the next run reaches the two left', array( 'a4@example.org', 'a5@example.org' ), array_slice( $GLOBALS['t_mail_log'], 3 ) );

fresh();
$all = array( 'b1@example.org', 'b2@example.org', 'b3@example.org', 'b4@example.org', 'b5@example.org', 'b6@example.org', 'b7@example.org' );
$wpdb->seed( 10, $all );
foreach ( array( 'b1', 'b2', 'b4', 'b5', 'b6' ) as $who ) {
	$GLOBALS['t_mail_plan'][ "$who@example.org" ] = array( false );
}
NPMP_Newsletter_Manager::process_queue();
check( 'a success between failures starts the count over', 6, count( $GLOBALS['t_mail_log'] ) );
check( 'so the seventh waits for the next run', 0, (int) $wpdb->row( 'b7@example.org' )->attempts );

echo "The drain upgrades the table before it sends\n";
fresh( false );
$wpdb->seed( 16, array( 'up@example.org' ) );
$GLOBALS['t_mail_plan']['up@example.org'] = array( false );
NPMP_Newsletter_Manager::process_queue();
check( 'it ran the upgrade once', 1, count( $GLOBALS['t_dbdelta'] ) );
check( 'and recorded the version', NPMP_NEWSLETTER_QUEUE_DB_VERSION, get_option( 'npmp_newsletter_queue_db_version' ) );
check( 'then drained with retries', true, false !== strpos( end( $wpdb->selects ), 'retry_at' ) );
check( 'so the failed send waits for another try', 'pending', $wpdb->row( 'up@example.org' )->status );
check( 'and both locks are free', array(), $wpdb->opts );
NPMP_Newsletter_Manager::process_queue();
check( 'the next drain leaves the table alone', 1, count( $GLOBALS['t_dbdelta'] ) );

echo "While another request upgrades the table, the drain runs the 2026.11.26 way\n";
fresh( false );
$wpdb->opts['npmp_queue_lock_newsletter_queue_schema'] = (string) time();
$wpdb->seed( 11, array( 'old@example.org', 'ok@example.org' ) );
$GLOBALS['t_mail_plan']['old@example.org'] = array( false );
NPMP_Newsletter_Manager::process_queue();
check( 'the query names no new column', false, strpos( end( $wpdb->selects ), 'retry_at' ) );
check( 'the failure is final at once', 'failed', $wpdb->row( 'old@example.org' )->status );
check( 'and the other reader still goes out', 'sent', $wpdb->row( 'ok@example.org' )->status );
check( 'with no ALTER of its own', 0, count( $GLOBALS['t_dbdelta'] ) );

echo "A slow batch stops before its lock goes stale\n";
fresh();
$all = array( 'c1@example.org', 'c2@example.org', 'c3@example.org', 'c4@example.org', 'c5@example.org' );
$wpdb->seed( 12, $all );
add_filter( 'npmp_mail_queue_batch_seconds', static fn( $s, $queue ) => 'newsletter' === $queue ? 0.15 : $s, 10, 2 );
$GLOBALS['t_on_mail'] = static function () {
	usleep( 100000 );
};
NPMP_Newsletter_Manager::process_queue();
$tried = count( $GLOBALS['t_mail_log'] );
check( 'it stopped partway', true, $tried >= 1 && $tried < 5 );
check( 'the readers it never reached wait untouched', true, 'pending' === $wpdb->row( 'c5@example.org' )->status && 0 === (int) $wpdb->row( 'c5@example.org' )->attempts );
check( 'the default budget is 240 seconds', 240, NPMP_Newsletter_Manager::BATCH_SECONDS );

echo "A batch that lost its lock stops and leaves the new holder's lock alone\n";
fresh();
$wpdb->seed( 13, array( 'd1@example.org', 'd2@example.org', 'd3@example.org' ) );
$GLOBALS['t_on_mail'] = static function () {
	// Another run decided this one died and took the lock over, and our
	// last refresh was 31 seconds ago.
	$GLOBALS['wpdb']->opts['npmp_queue_lock_newsletter'] = 'theirs';
	npmp_mail_queue_lock_stamp( 'newsletter', (string) ( time() - 31 ) );
};
NPMP_Newsletter_Manager::process_queue();
check( 'one send, then it stopped', 1, count( $GLOBALS['t_mail_log'] ) );
check( 'the other run keeps its lock', 'theirs', $wpdb->opts['npmp_queue_lock_newsletter'] ?? null );

echo "The run lock\n";
fresh();
check( 'the first run takes it', true, npmp_mail_queue_lock( 'q' ) );
$mine = $wpdb->opts['npmp_queue_lock_q'];
check( 'and remembers its stamp', $mine, npmp_mail_queue_lock_stamp( 'q' ) );
check( 'a second run is turned away', false, npmp_mail_queue_lock( 'q' ) );
check( 'a fresh lock needs no refresh', true, npmp_mail_queue_batch_continues( 'q', microtime( true ), 240 ) );
$wpdb->opts['npmp_queue_lock_q'] = (string) ( time() - 40 );
npmp_mail_queue_lock_stamp( 'q', (string) ( time() - 40 ) );
check( 'a lock 40 seconds old gets refreshed', true, npmp_mail_queue_batch_continues( 'q', microtime( true ), 240 ) );
check( 'to now', (string) time(), $wpdb->opts['npmp_queue_lock_q'] );
check( 'so the next tick still sees it held', false, npmp_mail_queue_lock( 'q' ) );
check( 'a spent budget stops the batch', false, npmp_mail_queue_batch_continues( 'q', microtime( true ) - 241, 240 ) );
npmp_mail_queue_unlock( 'q' );
check( 'unlock frees it', false, isset( $wpdb->opts['npmp_queue_lock_q'] ) );

$wpdb->opts['npmp_queue_lock_q'] = (string) ( time() - 400 );
check( 'a stale lock is taken over', true, npmp_mail_queue_lock( 'q' ) );
npmp_mail_queue_lock_stamp( 'q', (string) ( time() - 400 ) );
$wpdb->opts['npmp_queue_lock_q'] = 'thief';
check( 'a holder whose lock was taken learns it on refresh', false, npmp_mail_queue_lock_refresh( 'q' ) );
check( 'and stops', false, npmp_mail_queue_batch_continues( 'q', microtime( true ), 240 ) );
npmp_mail_queue_unlock( 'q' );
check( 'its unlock leaves the new lock in place', 'thief', $wpdb->opts['npmp_queue_lock_q'] ?? null );
unset( $wpdb->opts['npmp_queue_lock_q'] );
check( 'a request that never locked still clears the row, as before', true, npmp_mail_queue_lock( 'q' ) );
npmp_mail_queue_lock_stamp( 'q', '' );
npmp_mail_queue_unlock( 'q' );
check( 'cleared', false, isset( $wpdb->opts['npmp_queue_lock_q'] ) );

echo "The table upgrade\n";
check( 'a 2026.11.26 table lacks three things', array( 'attempts', 'retry_at', 'status_queued' ), npmp_newsletter_queue_schema_missing( array( 'id', 'newsletter_id', 'user_id', 'email', 'status', 'queued_at', 'sent_at' ), array( 'PRIMARY', 'newsletter_status' ) ) );
check( 'a current one lacks nothing', array(), npmp_newsletter_queue_schema_missing( array( 'id', 'attempts', 'RETRY_AT' ), array( 'status_queued' ) ) );

fresh( false );
$GLOBALS['t_options']['npmp_newsletter_queue_table_created'] = 1;
npmp_maybe_create_newsletter_queue_table();
check( 'a site with only the old flag gets the upgrade', 1, count( $GLOBALS['t_dbdelta'] ) );
$sql = (string) ( $GLOBALS['t_dbdelta'][0] ?? '' );
check( 'with an attempts column', true, false !== strpos( $sql, 'attempts TINYINT UNSIGNED NOT NULL DEFAULT 0' ) );
check( 'a retry_at column', true, false !== strpos( $sql, 'retry_at DATETIME NULL' ) );
check( 'and the drain index', true, false !== strpos( $sql, 'KEY status_queued (status,queued_at,id,retry_at)' ) );
check( 'as CREATE TABLE, which dbDelta can alter', true, false !== strpos( $sql, 'CREATE TABLE wp_npmp_newsletter_queue (' ) );
check( 'and records the version', NPMP_NEWSLETTER_QUEUE_DB_VERSION, get_option( 'npmp_newsletter_queue_db_version' ) );
check( 'so the drain uses retries', true, npmp_newsletter_queue_has_retries() );
check( 'and frees its lock', array(), $wpdb->opts );
npmp_maybe_create_newsletter_queue_table();
check( 'the next load does nothing', 1, count( $GLOBALS['t_dbdelta'] ) );

fresh( false );
$wpdb->opts['npmp_queue_lock_newsletter_queue_schema'] = (string) time();
npmp_maybe_create_newsletter_queue_table();
check( 'while another request upgrades, this one waits its turn', 0, count( $GLOBALS['t_dbdelta'] ) );
check( 'and records nothing', false, get_option( 'npmp_newsletter_queue_db_version' ) );
$wpdb->seed( 14, array( 'mid@example.org' ) );
$GLOBALS['t_mail_plan']['mid@example.org'] = array( false );
NPMP_Newsletter_Manager::process_queue();
check( 'its drain still sends, the old way', 'failed', $wpdb->row( 'mid@example.org' )->status );

fresh( false );
$GLOBALS['t_alter_ok'] = false;
npmp_maybe_create_newsletter_queue_table();
check( 'a database that refuses the change gets no version', false, get_option( 'npmp_newsletter_queue_db_version' ) );
check( 'and a pause before the next try', 1, get_transient( 'npmp_newsletter_queue_db_retry_later' ) );
check( 'with the lock free', array(), $wpdb->opts );
npmp_maybe_create_newsletter_queue_table();
check( 'so the next load skips it', 1, count( $GLOBALS['t_dbdelta'] ) );
$wpdb->seed( 15, array( 'still@example.org' ) );
NPMP_Newsletter_Manager::process_queue();
check( 'and newsletters keep going out', 'sent', $wpdb->row( 'still@example.org' )->status );

fresh( false );
npmp_upgrade_newsletter_queue_table();
check( 'activation upgrades even when the version option is missing', NPMP_NEWSLETTER_QUEUE_DB_VERSION, get_option( 'npmp_newsletter_queue_db_version' ) );

echo "Never on a visitor's page load\n";
$loaded = static function ( $hook ) {
	return array_filter( $GLOBALS['t_actions'][ $hook ] ?? array(), 'is_string' );
};
check( 'nothing upgrades on plugins_loaded', false, in_array( 'npmp_maybe_create_newsletter_queue_table', $loaded( 'plugins_loaded' ), true ) );
check( 'nor on init', false, in_array( 'npmp_maybe_create_newsletter_queue_table', $loaded( 'init' ), true ) );
check( 'admin screens do', true, in_array( 'npmp_maybe_upgrade_newsletter_queue_in_admin', $loaded( 'admin_init' ), true ) );
fresh( false );
npmp_maybe_upgrade_newsletter_queue_in_admin();
check( 'an admin opening a screen runs it', 1, count( $GLOBALS['t_dbdelta'] ) );
fresh( false );
$GLOBALS['t_ajax'] = true;
npmp_maybe_upgrade_newsletter_queue_in_admin();
check( 'an Ajax request from a visitor\'s form does not', 0, count( $GLOBALS['t_dbdelta'] ) );
$GLOBALS['t_ajax'] = false;
$GLOBALS['t_can']  = false;
npmp_maybe_upgrade_newsletter_queue_in_admin();
check( 'nor does an admin-post form from someone who can\'t manage options', 0, count( $GLOBALS['t_dbdelta'] ) );
$GLOBALS['t_can'] = true;

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
