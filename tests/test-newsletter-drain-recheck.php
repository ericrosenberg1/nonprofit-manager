<?php
/**
 * A drain skips a reader who left after it read its batch, and tracking
 * writes nothing for an erased contact.
 *
 * The newsletter drain (and the weekly digest drain) read their batch and the
 * contacts once, then send for up to 240 seconds. A contact erased in that
 * time, a reader who unsubscribed, or a row another request settled still got
 * mail, and a signed open or click link in an erased contact's old email
 * wrote an opens or clicks row for a contact that no longer exists. Each send
 * now reads the row and its contact again by primary key, and the trackers
 * check the contact first.
 *
 * This runs the real drains, helpers, and tracker against a fake $wpdb that
 * keeps the queue rows and contacts in arrays, and a mail hook that changes
 * the data in the middle of a batch the way a second request would. The real
 * database and a slow SMTP sink: tests/rig/newsletter-queue-cron.php.
 *
 * Run: php tests/test-newsletter-drain-recheck.php
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
$GLOBALS['t_contacts']   = array();  // contact id => email, post_status, status, digest flag.
$GLOBALS['t_cleared']    = array();
$GLOBALS['t_inserts']    = array();  // Tracking rows written.
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
	if ( '_npmp_weekly_digest' === $key && isset( $GLOBALS['t_contacts'][ $id ] ) ) {
		return $GLOBALS['t_contacts'][ $id ]['flag'] ?? '';
	}
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function delete_option( $name ) {
	unset( $GLOBALS['t_options'][ $name ] );
	return true;
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
	return isset( $GLOBALS['t_contacts'][ $id ] ) ? 'npmp_contact' : 'npmp_newsletter';
}
function get_post_status( $id ) {
	return isset( $GLOBALS['t_contacts'][ $id ] ) ? $GLOBALS['t_contacts'][ $id ]['post_status'] : false;
}
function add_shortcode( $tag, $cb ) {}
function esc_url_raw( $url ) {
	return (string) $url;
}
function wp_cache_get( $key, $group = '' ) {
	return false;
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	return true;
}
function wp_cache_flush() {}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['t_cleared'][] = $hook;
}
function wp_next_scheduled( $hook ) {
	return false;
}
function wp_schedule_event( $time, $recurrence, $hook ) {}
function is_email( $v ) {
	return false !== strpos( (string) $v, '@' );
}
function npmp_email_recipient_footer( $email ) {
	return '';
}

/** What lookup_emails() answers, read from the contacts at the moment of the call. */
class NPMP_Member_Manager {
	public static function get_instance() {
		return new self();
	}
	public function lookup_emails( array $emails ) {
		$found = array();
		foreach ( $GLOBALS['t_contacts'] as $id => $c ) {
			if ( 'publish' === $c['post_status'] && in_array( $c['email'], $emails, true ) ) {
				$found[ $c['email'] ] = array( 'id' => $id, 'status' => $c['status'] );
			}
		}
		return $found;
	}
	public function get_member_by_id( $id ) {
		return null;
	}
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
	public $digest   = array();  // digest queue id => row.
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $opts    = array();   // option_name => option_value, the live rows.
	public $rows    = array();   // queue id => row.
	public $columns = array( 'id', 'newsletter_id', 'user_id', 'email', 'status', 'queued_at', 'sent_at' );
	public $indexes = array( 'PRIMARY', 'newsletter_status' );
	public $selects = array();   // Batch SQL the drain ran.
	public $row_reads = 0;       // Fresh reads before a send.

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
		if ( false !== strpos( $sql, 'INSERT IGNORE INTO wp_npmp_newsletter_opens' ) ) {
			$GLOBALS['t_inserts'][] = array( 'wp_npmp_newsletter_opens', $args );
			return 1;
		}
		throw new Exception( 'Unexpected query: ' . $sql );
	}

	public function get_var( $q ) {
		$sql  = $q instanceof T_Query ? $q->sql : (string) $q;
		$args = $q instanceof T_Query ? $q->args : array();
		if ( "SELECT COUNT(*) FROM wp_npmp_digest_queue WHERE status = 'pending'" === $sql ) {
			return (string) count( array_filter( $this->digest, static fn( $r ) => 'pending' === $r->status ) );
		}
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
		if ( false !== strpos( $sql, "SELECT id, email FROM wp_npmp_digest_queue WHERE status = 'pending'" ) ) {
			return array_slice( array_values( array_filter( $this->digest, static fn( $r ) => 'pending' === $r->status ) ), 0, (int) end( $args ) );
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

	/** The fresh read of one row and its contact right before a send. */
	public function get_row( $q ) {
		$sql  = $q instanceof T_Query ? $q->sql : (string) $q;
		$args = $q instanceof T_Query ? $q->args : array();
		$news = false !== strpos( $sql, 'FROM wp_npmp_newsletter_queue q LEFT JOIN' );
		$dig  = false !== strpos( $sql, 'FROM wp_npmp_digest_queue q LEFT JOIN' );
		if ( ! $news && ! $dig ) {
			throw new Exception( 'Unexpected get_row: ' . $sql );
		}
		$this->row_reads++;
		$r = ( $news ? $this->rows : $this->digest )[ (int) $args[2] ] ?? null;
		if ( ! $r ) {
			return null;
		}
		$c = $GLOBALS['t_contacts'][ (int) $args[0] ] ?? null;
		return (object) array(
			'queue_status'   => $r->status,
			'contact_post'   => $c ? $c['post_status'] : null,
			'contact_status' => $c ? $c['status'] : null,
			'contact_flag'   => $c ? ( $c['flag'] ?? null ) : null,
		);
	}

	public function insert( $table, $data, $formats = null ) {
		$GLOBALS['t_inserts'][] = array( $table, $data );
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( 'wp_npmp_digest_queue' === $table ) {
			$id = (int) $where['id'];
			if ( ! isset( $this->digest[ $id ] ) ) {
				return 0;
			}
			foreach ( $data as $column => $value ) {
				$this->digest[ $id ]->$column = $value;
			}
			return 1;
		}
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
		if ( 'wp_npmp_digest_queue' === $table ) {
			unset( $this->digest[ (int) $where['id'] ] );
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
require_once dirname( __DIR__ ) . '/includes/email-newsletter/class-newsletter-tracker.php';
require_once dirname( __DIR__ ) . '/includes/npmp-subscription-preferences.php';

/** Start a case: an empty queue on the current schema, no contacts, no mail plans. */
function fresh() {
	global $wpdb;
	$wpdb                    = new T_Wpdb();
	$wpdb->columns           = array_merge( $wpdb->columns, array( 'attempts', 'retry_at' ) );
	$wpdb->indexes[]         = 'status_queued';
	$GLOBALS['t_options']    = array(
		'npmp_newsletter_rate_limit'       => 10,
		'npmp_newsletter_queue_db_version' => NPMP_NEWSLETTER_QUEUE_DB_VERSION,
		'npmp_newsletter_track_opens'      => false,
		'npmp_digest_pending_content'      => array( 'subject' => 'Digest', 'content' => '<p>News</p>' ),
	);
	$GLOBALS['t_transients'] = array();
	$GLOBALS['t_meta']       = array();
	$GLOBALS['t_filters']    = array();
	$GLOBALS['t_mail_plan']  = array();
	$GLOBALS['t_mail_log']   = array();
	$GLOBALS['t_on_mail']    = null;
	$GLOBALS['t_contacts']   = array();
	$GLOBALS['t_cleared']    = array();
	$GLOBALS['t_inserts']    = array();
}

/** A subscribed contact, and the queue row that mails them. */
function reader( $n, $kind = 'newsletter' ) {
	global $wpdb;
	$email                          = "r$n@example.org";
	$GLOBALS['t_contacts'][ 100 + $n ] = array( 'email' => $email, 'post_status' => 'publish', 'status' => 'subscribed', 'flag' => '1' );
	if ( 'digest' === $kind ) {
		$id            = count( $wpdb->digest ) + 1;
		$wpdb->digest[ $id ] = (object) array( 'id' => $id, 'email' => $email, 'status' => 'pending', 'queued_at' => '2026-11-22 09:00:00' );
		return $id;
	}
	$wpdb->seed( 7, array( $email ) );
	$wpdb->row( $email )->user_id = 100 + $n;
	return $wpdb->row( $email )->id;
}

function mailed( $n ) {
	return in_array( "r$n@example.org", $GLOBALS['t_mail_log'], true );
}

echo "A contact erased during the batch gets no mail\n";
fresh();
foreach ( array( 1, 2, 3 ) as $n ) {
	reader( $n );
}
$GLOBALS['t_on_mail'] = static function ( $to ) {
	if ( 'r1@example.org' === $to ) {
		unset( $GLOBALS['t_contacts'][ 102 ] ); // An admin erases Bea while Ann's mail is out.
	}
};
NPMP_Newsletter_Manager::process_queue();
check( 'Ann went out', true, mailed( 1 ) );
check( 'Bea, erased after the batch was read, got nothing', false, mailed( 2 ) );
check( 'her queue row is gone', null, $wpdb->row( 'r2@example.org' ) );
check( 'Cy went out', true, mailed( 3 ) );
check( 'three readers, two mails', 2, count( $GLOBALS['t_mail_log'] ) );

echo "A reader who unsubscribes during the batch gets no mail\n";
fresh();
foreach ( array( 1, 2, 3 ) as $n ) {
	reader( $n );
}
$GLOBALS['t_on_mail'] = static function ( $to ) {
	if ( 'r1@example.org' === $to ) {
		$GLOBALS['t_contacts'][ 103 ]['status'] = 'unsubscribed';
	}
};
NPMP_Newsletter_Manager::process_queue();
check( 'Ann and Bea went out', array( true, true ), array( mailed( 1 ), mailed( 2 ) ) );
check( 'Cy, who unsubscribed after the batch was read, got nothing', false, mailed( 3 ) );
check( 'his row is gone, so Reports counts only who we mailed', null, $wpdb->row( 'r3@example.org' ) );
check( 'the others stay sent', array( 'sent', 'sent' ), array( $wpdb->row( 'r1@example.org' )->status, $wpdb->row( 'r2@example.org' )->status ) );

echo "A contact who goes back to unconfirmed gets no mail either\n";
fresh();
foreach ( array( 1, 2 ) as $n ) {
	reader( $n );
}
$GLOBALS['t_on_mail'] = static function ( $to ) {
	if ( 'r1@example.org' === $to ) {
		$GLOBALS['t_contacts'][ 102 ]['status'] = 'pending';
	}
};
NPMP_Newsletter_Manager::process_queue();
check( 'Bea got nothing', false, mailed( 2 ) );

echo "A row another request deleted or settled gets no mail\n";
fresh();
foreach ( array( 1, 2, 3, 4 ) as $n ) {
	reader( $n );
}
$GLOBALS['t_on_mail'] = static function ( $to ) {
	global $wpdb;
	if ( 'r1@example.org' === $to ) {
		unset( $wpdb->rows[ $wpdb->row( 'r2@example.org' )->id ] ); // A privacy erase deleted Bea's row.
		$wpdb->row( 'r3@example.org' )->status = 'sent';            // A second run already mailed Cy.
	}
};
NPMP_Newsletter_Manager::process_queue();
check( 'Ann went out', true, mailed( 1 ) );
check( 'Bea, whose row is gone, got nothing', false, mailed( 2 ) );
check( 'Cy was not mailed a second time', false, mailed( 3 ) );
check( 'Cy\'s row stays as the other run left it', 'sent', $wpdb->row( 'r3@example.org' )->status );
check( 'Dee went out', true, mailed( 4 ) );

echo "The recheck is one read per send, and the rest still holds\n";
fresh();
foreach ( array( 1, 2, 3 ) as $n ) {
	reader( $n );
}
$GLOBALS['t_mail_plan']['r2@example.org'] = array( false );
NPMP_Newsletter_Manager::process_queue();
check( 'one fresh read for each of three sends', 3, $wpdb->row_reads );
check( 'a failed send still waits for a retry', 'pending', $wpdb->row( 'r2@example.org' )->status );
check( 'with its try counted', 1, (int) $wpdb->row( 'r2@example.org' )->attempts );
check( 'the others went out once', array( 'sent', 'sent' ), array( $wpdb->row( 'r1@example.org' )->status, $wpdb->row( 'r3@example.org' )->status ) );

echo "A contact with no status meta still counts as subscribed\n";
fresh();
reader( 1 );
$GLOBALS['t_contacts'][ 101 ]['status'] = '';
$got = npmp_queue_recheck_send( 'wp_npmp_newsletter_queue', 1, 101 );
check( 'read as subscribed', 'subscribed', $got['contact']['status'] );
check( 'and as existing', true, $got['contact']['exists'] );
$got = npmp_queue_recheck_send( 'wp_npmp_newsletter_queue', 1, 0 );
check( 'a row with no contact ID checks the row only', null, $got['contact'] );
check( 'and finds it pending', 'pending', $got['row'] );
$got = npmp_queue_recheck_send( 'wp_npmp_newsletter_queue', 99, 101 );
check( 'a row that is gone reads as gone', null, $got['row'] );

echo "The weekly digest drain checks each reader again too\n";
fresh();
foreach ( array( 1, 2, 3, 4 ) as $n ) {
	reader( $n, 'digest' );
}
$GLOBALS['t_on_mail'] = static function ( $to ) {
	global $wpdb;
	if ( 'r1@example.org' === $to ) {
		unset( $GLOBALS['t_contacts'][ 102 ] );                          // Bea is erased.
		$GLOBALS['t_contacts'][ 103 ]['status'] = 'unsubscribed';       // Cy unsubscribes.
		$GLOBALS['t_contacts'][ 104 ]['flag']   = '0';                   // Dee turns the digest off.
	}
};
$GLOBALS['wpdb']->digest[1]->status = 'pending';
npmp_drain_digest_batch();
check( 'Ann got the digest', true, mailed( 1 ) );
check( 'Bea, erased, did not', false, mailed( 2 ) );
check( 'Cy, unsubscribed, did not', false, mailed( 3 ) );
check( 'Dee, digest off, did not', false, mailed( 4 ) );
check( 'one digest went out', 1, count( $GLOBALS['t_mail_log'] ) );
check( 'Ann\'s row reads sent', 'sent', $wpdb->digest[1]->status );
check( 'the other rows are gone', array( 1 ), array_keys( $wpdb->digest ) );

fresh();
reader( 1, 'digest' );
reader( 2, 'digest' );
$GLOBALS['t_on_mail'] = static function ( $to ) {
	global $wpdb;
	if ( 'r1@example.org' === $to ) {
		unset( $wpdb->digest[2] ); // Someone cleared Bea's row.
	}
};
npmp_drain_digest_batch();
check( 'a digest row deleted mid-batch gets no mail', false, mailed( 2 ) );

echo "Open and click tracking write nothing for an erased contact\n";
fresh();
$GLOBALS['t_contacts'][ 105 ] = array( 'email' => 'r5@example.org', 'post_status' => 'publish', 'status' => 'subscribed', 'flag' => '1' );
$tracker                      = NPMP_Newsletter_Tracker::get_instance();
check( 'an open for a real contact is recorded', true, $tracker->track_open( 7, 105 ) );
check( 'in one write', 1, count( $GLOBALS['t_inserts'] ) );
check( 'a click for a real contact is recorded', true, $tracker->track_click( 7, 105, 'https://example.com/x' ) );
check( 'in a second write', 2, count( $GLOBALS['t_inserts'] ) );
check( 'an open for a contact that does not exist is not', false, $tracker->track_open( 7, 999 ) );
check( 'a click for one is not', false, $tracker->track_click( 7, 999, 'https://example.com/x' ) );
unset( $GLOBALS['t_contacts'][ 105 ] );
check( 'nor for the contact once erased', false, $tracker->track_open( 7, 105 ) );
check( 'nor a user ID of 0', false, $tracker->track_click( 7, 0, 'https://example.com/x' ) );
$GLOBALS['t_contacts'][ 106 ] = array( 'email' => 'r6@example.org', 'post_status' => 'trash', 'status' => 'subscribed', 'flag' => '1' );
check( 'nor a trashed contact', false, $tracker->track_open( 7, 106 ) );
check( 'no row landed for any of them', 2, count( $GLOBALS['t_inserts'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
