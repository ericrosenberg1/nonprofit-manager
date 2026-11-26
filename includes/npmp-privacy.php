<?php
/**
 * WordPress's own personal data tools: Tools, Export Personal Data and
 * Tools, Erase Personal Data.
 *
 * Loaded on every request, whatever modules are on, since a site that
 * switched a module off still holds the data it stored before.
 *
 * Exporters (core filter wp_privacy_personal_data_exporters):
 *   nonprofit-manager-contact  contact record and email subscription, one page.
 *   nonprofit-manager-gifts    gift records, NPMP_PRIVACY_PAGE_SIZE a page.
 *
 * Eraser (core filter wp_privacy_personal_data_erasers), npmp_privacy_erase():
 *   - Deletes every contact with the address, its newsletter and digest
 *     queue rows (pending and sent), its open and click rows, the signup
 *     confirmation key (contact meta), and the old members and contacts
 *     table rows.
 *   - Keeps each gift for the books: strips the name and email from the
 *     gift and the payer details from its payment log row, and keeps the
 *     date, amount, currency, frequency, gateway, and transaction ID.
 *   - Keeps a published contact whose status is unsubscribed with only its
 *     email and status (filter npmp_privacy_keep_unsubscribed, default
 *     true), so a later import or signup never mails the address again.
 *   - Keeps the contact, its gifts, and its old table rows, and says why,
 *     when Pro's plan table holds a row that can still charge (active,
 *     failed, or lapsed), when Pro data is left and Pro's eraser isn't on
 *     the list (Pro off or older), or when the filter npmp_privacy_erase_holds
 *     names a reason. We read Pro's tables directly, with no Pro code. Queue
 *     and tracking rows still go, on the first page only.
 *   - Stores no name on an erased gift. The Donations screen prints
 *     "Erased on request" from _npmp_privacy_erased, in the reader's language.
 *   - Never calls a payment gateway and never touches a WordPress user.
 *
 * Every database read and write goes through NPMP_Privacy_Store, so the
 * unit test (tests/test-privacy.php) runs the rules on an in-memory store
 * and the rig (tests/rig/privacy.php) runs them on a real WordPress.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_PRIVACY_PAGE_SIZE' ) ) {
	// Gifts handled in one exporter or eraser request.
	define( 'NPMP_PRIVACY_PAGE_SIZE', 100 );
}

/**
 * Database access for the privacy tools, on WordPress.
 */
class NPMP_Privacy_Store {

	/**
	 * Contact meta a kept unsubscribed contact keeps. _npmp_free_import is a
	 * yes or no mark that keeps the free import total right while the
	 * contact exists, and names nobody. _npmp_privacy_erased records when we
	 * cut the record down.
	 *
	 * @var string[]
	 */
	const KEEP_META = array( 'npmp_email', 'npmp_status', '_npmp_free_import', '_npmp_privacy_erased' );

	/**
	 * Tables that exist, asked once a request.
	 *
	 * @var array<string,bool>
	 */
	private $tables = array();

	/**
	 * Whether a table with this prefix-less name exists.
	 *
	 * @param string $name Table name without the prefix.
	 * @return bool
	 */
	public function has_table( $name ) {
		global $wpdb;
		if ( ! isset( $this->tables[ $name ] ) ) {
			$table = $wpdb->prefix . $name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->tables[ $name ] = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		}
		return $this->tables[ $name ];
	}

	/**
	 * Every contact with this address, in any post status, oldest first.
	 *
	 * npmp_email has no index of its own, so this reads every npmp_email row
	 * once. That's one query per request, never one per contact.
	 *
	 * @param string $email Email address.
	 * @return array<int,array{id:int,status:string,post_status:string}>
	 */
	public function contacts( $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_status AS post_status, COALESCE( s.meta_value, '' ) AS status
				 FROM {$wpdb->postmeta} e
				 INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
				 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'npmp_status'
				 WHERE e.meta_key = 'npmp_email' AND p.post_type = 'npmp_contact' AND LOWER( e.meta_value ) = %s
				 ORDER BY p.ID ASC",
				strtolower( $email )
			),
			ARRAY_A
		);
		$out = array();
		$ids = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['id'];
			if ( isset( $ids[ $id ] ) ) {
				continue;
			}
			$ids[ $id ] = true;
			$out[]      = array(
				'id'          => $id,
				'status'      => '' === (string) $row['status'] ? 'subscribed' : (string) $row['status'],
				'post_status' => (string) $row['post_status'],
			);
		}
		return $out;
	}

	/**
	 * A contact's stored fields for the export.
	 *
	 * @param int $id Contact post ID.
	 * @return array{title:string,content:string,date:string,modified:string,meta:array<string,mixed>}|null
	 */
	public function contact_record( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'npmp_contact' !== $post->post_type ) {
			return null;
		}
		$meta = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			$meta[ $key ] = maybe_unserialize( is_array( $values ) ? reset( $values ) : $values );
		}
		return array(
			'title'    => (string) $post->post_title,
			'content'  => (string) $post->post_content,
			'date'     => (string) $post->post_date,
			'modified' => (string) $post->post_modified,
			'meta'     => $meta,
		);
	}

	/**
	 * Delete a contact for good. WordPress removes its meta and terms, and
	 * our own deleted_post listeners give its free import place back.
	 *
	 * @param int    $id    Contact post ID.
	 * @param string $email Address, for the caches.
	 * @return bool
	 */
	public function delete_contact( $id, $email ) {
		$done = (bool) wp_delete_post( (int) $id, true );
		$this->forget_contact( $id, $email );
		return $done;
	}

	/**
	 * Strip a contact down to its email and status.
	 *
	 * @param int    $id    Contact post ID.
	 * @param string $email The contact's stored address.
	 * @return bool Whether anything changed.
	 */
	public function strip_contact( $id, $email ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return false;
		}
		$changed = false;
		foreach ( array_keys( (array) get_post_meta( $post->ID ) ) as $key ) {
			if ( in_array( $key, self::KEEP_META, true ) ) {
				continue;
			}
			delete_post_meta( $post->ID, $key );
			$changed = true;
		}
		$stored = (string) get_post_meta( $post->ID, 'npmp_email', true );
		$title  = '' !== $stored ? $stored : $email;
		if ( $title !== $post->post_title || '' !== $post->post_content || '' !== $post->post_excerpt ) {
			// We keep the record's added and last-changed dates: we store no
			// unsubscribe date of its own, so the last change before the erase
			// is the nearest record of the person's choice.
			$keep_dates = static function ( $data ) use ( $post ) {
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;
				return $data;
			};
			add_filter( 'wp_insert_post_data', $keep_dates, 99 );
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_title'   => $title,
					'post_content' => '',
					'post_excerpt' => '',
				)
			);
			remove_filter( 'wp_insert_post_data', $keep_dates, 99 );
			$changed = true;
		}
		if ( $changed && '' === (string) get_post_meta( $post->ID, '_npmp_privacy_erased', true ) ) {
			update_post_meta( $post->ID, '_npmp_privacy_erased', gmdate( 'Y-m-d H:i:s' ) );
		}
		// Pro's groups. Free registers no taxonomy on contacts.
		$taxonomies = get_object_taxonomies( 'npmp_contact' );
		if ( $taxonomies ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomies, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_delete_object_term_relationships( $post->ID, $taxonomies );
				$changed = true;
			}
		}
		$this->forget_contact( $id, $email );
		return $changed;
	}

	/**
	 * Clear what the member manager remembers about a contact.
	 *
	 * @param int    $id    Contact post ID.
	 * @param string $email Address.
	 * @return void
	 */
	private function forget_contact( $id, $email ) {
		wp_cache_delete( 'npmp_member_' . (int) $id, 'npmp_members' );
		wp_cache_delete( 'npmp_member_email_' . strtolower( (string) $email ), 'npmp_members' );
		wp_cache_delete( 'npmp_members_all', 'npmp_members' );
		if ( class_exists( 'NPMP_Member_Manager' ) ) {
			NPMP_Member_Manager::get_instance()->forget_email( (string) $email );
		}
	}

	/**
	 * One page of this address's gifts for the export, oldest first.
	 *
	 * @param string $email  Email address.
	 * @param int    $limit  Rows.
	 * @param int    $offset Rows to skip.
	 * @return array<int,array{id:int,date:string,amount:string,currency:string,frequency:string,gateway:string}>
	 */
	public function gifts( $email, $limit, $offset ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_date AS date,
				        COALESCE( a.meta_value, '' ) AS amount, COALESCE( c.meta_value, '' ) AS currency,
				        COALESCE( f.meta_value, '' ) AS frequency, COALESCE( g.meta_value, '' ) AS gateway
				 FROM {$wpdb->postmeta} e
				 INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
				 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_npmp_donation_amount'
				 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_npmp_donation_currency'
				 LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_npmp_donation_frequency'
				 LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = '_npmp_donation_gateway'
				 WHERE e.meta_key = '_npmp_donation_email' AND p.post_type = 'npmp_donation' AND p.post_status <> 'auto-draft'
				   AND LOWER( e.meta_value ) = %s
				 ORDER BY p.ID ASC
				 LIMIT %d OFFSET %d",
				strtolower( $email ),
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row['id'] = (int) $row['id'];
			$out[]     = $row;
		}
		return $out;
	}

	/**
	 * IDs of gifts that still carry this address, oldest first.
	 *
	 * @param string $email Email address.
	 * @param int    $limit Rows.
	 * @return int[]
	 */
	public function gift_ids_to_strip( $email, $limit ) {
		return wp_list_pluck( $this->gifts( $email, $limit, 0 ), 'id' );
	}

	/**
	 * Take the name, email, and payer details off a gift, and keep the
	 * date, amount, currency, frequency, gateway, and transaction ID.
	 *
	 * @param int $id Gift post ID.
	 * @return void
	 */
	public function strip_gift( $id ) {
		global $wpdb;
		$id = (int) $id;
		delete_post_meta( $id, '_npmp_donation_email' );
		// We store no name. WordPress refuses a post with no title and no
		// content unless we say an empty one is fine, for this write only.
		add_filter( 'wp_insert_post_empty_content', '__return_false', 99 );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => '',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);
		remove_filter( 'wp_insert_post_empty_content', '__return_false', 99 );
		update_post_meta( $id, '_npmp_privacy_erased', gmdate( 'Y-m-d H:i:s' ) );
		if ( $this->has_table( 'npmp_payment_log' ) ) {
			// The gateway's full answer can hold the payer's name, email, and
			// address. The order ID, capture ID, and status stay for disputes.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}npmp_payment_log SET raw_response = NULL WHERE donation_id = %d AND raw_response IS NOT NULL", $id ) );
		}
		if ( function_exists( 'npmp_dashboard_donations_changed' ) ) {
			npmp_dashboard_donations_changed();
		}
	}

	/**
	 * Pro's plan rows for these contacts, read straight from Pro's table, so
	 * the answer holds with Pro off, on an older Pro, or on a newer one.
	 *
	 * @param int[] $ids Contact IDs.
	 * @return array<int,array{id:int,status:string,frequency:string,payment_method:string}>
	 */
	public function plans( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! $this->has_table( 'npmp_recurring_donations' ) ) {
			return array();
		}
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers.
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id IN ({$in}) ORDER BY id", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'             => (int) $row['id'],
				'status'         => (string) ( $row['status'] ?? '' ),
				'frequency'      => (string) ( $row['frequency'] ?? '' ),
				'payment_method' => (string) ( $row['payment_method'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * How many of Pro's rows name this person: custom field values, group
	 * memberships, automation log rows, queued automation emails, Mailer
	 * queue rows, and plans that still carry a payment method. Only Pro's
	 * eraser clears these.
	 *
	 * @param string $email Email address.
	 * @param int[]  $ids   Contact IDs.
	 * @return int
	 */
	public function pro_leftovers( $email, array $ids ) {
		global $wpdb;
		$lower = strtolower( $email );
		$ids   = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$in    = $ids ? implode( ',', $ids ) : '0';
		$count = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers, table names fixed.
		if ( $ids && $this->has_table( 'npmp_custom_fields' ) ) {
			$count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_custom_fields WHERE entity_type = 'member' AND entity_id IN ({$in})" );
		}
		if ( $ids ) {
			$count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE t.taxonomy = 'npmp_group' AND r.object_id IN ({$in})" );
		}
		if ( $this->has_table( 'npmp_automation_log' ) ) {
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_automation_log WHERE LOWER( recipient_email ) = %s", $lower ) );
		}
		if ( $this->has_table( 'npmp_mailer_queue' ) ) {
			$like = '%' . $wpdb->esc_like( $lower ) . '%';
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}npmp_mailer_queue WHERE LOWER( payload ) LIKE %s", $like ) ) as $payload ) {
				$data = json_decode( (string) $payload, true );
				// A pending delete is Pro's request to the Mailer, not data to erase.
				if ( is_array( $data ) && empty( $data['deleted'] ) && strtolower( (string) ( $data['email'] ?? '' ) ) === $lower ) {
					++$count;
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $this->plans( $ids ) as $plan ) {
			if ( '' !== $plan['payment_method'] ) {
				++$count;
			}
		}
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks['npmp_send_automation_email'] ?? array() ) as $event ) {
				if ( strtolower( (string) ( $event['args'][1] ?? '' ) ) === $lower ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Primary keys of rows that match, read without locking anything, so the
	 * delete that follows locks only these rows. The email column has no
	 * index, so a LOWER() match reads the whole table once. We do that once
	 * per request, never once per page.
	 *
	 * @param string $table Prefix-less table name.
	 * @param string $where SQL condition, already prepared.
	 * @return int[]
	 */
	private function ids_where( $table, $where ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, prepared condition.
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}{$table} WHERE {$where}" ) );
	}

	/**
	 * Delete rows by primary key, 500 at a time.
	 *
	 * @param string $table Prefix-less table name.
	 * @param int[]  $ids   Row IDs.
	 * @return int Rows deleted.
	 */
	private function delete_ids( $table, array $ids ) {
		global $wpdb;
		$deleted = 0;
		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, integer IDs.
			$deleted += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE id IN (" . implode( ',', $chunk ) . ')' );
		}
		return $deleted;
	}

	/**
	 * Newsletters sent to this address, for the export.
	 *
	 * @param string $email Email address.
	 * @return int
	 */
	public function count_sent( $email ) {
		global $wpdb;
		if ( ! $this->has_table( 'npmp_newsletter_queue' ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE status = 'sent' AND LOWER( email ) = %s", strtolower( $email ) ) );
	}

	/**
	 * Delete this person's newsletter and digest queue rows, any status:
	 * rows with the address in any letter case, and newsletter rows that
	 * name one of the contacts.
	 *
	 * @param string $email Email address.
	 * @param int[]  $ids   Contact IDs.
	 * @return int Rows deleted.
	 */
	public function delete_queue( $email, array $ids = array() ) {
		global $wpdb;
		$match   = $wpdb->prepare( 'LOWER( email ) = %s', strtolower( $email ) );
		$ids     = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$deleted = 0;
		if ( $this->has_table( 'npmp_newsletter_queue' ) ) {
			$where    = $ids ? '( ' . $match . ' OR user_id IN (' . implode( ',', $ids ) . ') )' : $match;
			$deleted += $this->delete_ids( 'npmp_newsletter_queue', $this->ids_where( 'npmp_newsletter_queue', $where ) );
		}
		if ( $this->has_table( 'npmp_digest_queue' ) ) {
			$deleted += $this->delete_ids( 'npmp_digest_queue', $this->ids_where( 'npmp_digest_queue', $match ) );
		}
		return $deleted;
	}

	/**
	 * Open and click rows for these contacts.
	 *
	 * @param int[] $ids Contact IDs.
	 * @return array{opens:int,clicks:int}
	 */
	public function count_tracking( array $ids ) {
		global $wpdb;
		$out = array(
			'opens'  => 0,
			'clicks' => 0,
		);
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return $out;
		}
		$in = implode( ',', $ids );
		foreach ( array( 'opens', 'clicks' ) as $kind ) {
			if ( $this->has_table( 'npmp_newsletter_' . $kind ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers.
				$out[ $kind ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_{$kind} WHERE user_id IN ({$in})" );
			}
		}
		return $out;
	}

	/**
	 * Delete open and click rows for these contacts.
	 *
	 * @param int[] $ids Contact IDs.
	 * @return int Rows deleted.
	 */
	public function delete_tracking( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in      = implode( ',', $ids );
		$deleted = 0;
		foreach ( array( 'opens', 'clicks' ) as $kind ) {
			$table = 'npmp_newsletter_' . $kind;
			if ( $this->has_table( $table ) ) {
				// Opens lead their unique key with user_id. Clicks have no index on
				// it, so we read once and delete by primary key.
				$deleted += $this->delete_ids( $table, $this->ids_where( $table, "user_id IN ({$in})" ) );
			}
		}
		return $deleted;
	}

	/**
	 * Rows in the tables from before contacts and gifts became posts, for the
	 * export. We leave out each row's own ID and the old signup token.
	 *
	 * @param string $email Email address.
	 * @return array<string,array<int,array<string,string>>> Rows by table name.
	 */
	public function legacy_rows( $email ) {
		global $wpdb;
		$out = array();
		foreach ( array( 'npmp_members', 'npmp_contacts', 'npmp_donations' ) as $name ) {
			if ( ! $this->has_table( $name ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$name} WHERE LOWER( email ) = %s ORDER BY id", strtolower( $email ) ), ARRAY_A );
			foreach ( $rows as $row ) {
				unset( $row['id'], $row['token'] );
				$out[ $name ][] = array_map( 'strval', array_map( static function ( $v ) { return null === $v ? '' : $v; }, $row ) );
			}
		}
		return $out;
	}

	/**
	 * Clean the tables from before contacts and gifts became posts. The
	 * one-time migrations copied them and left the rows behind.
	 *
	 * @param string $email Email address.
	 * @return array{removed:int,retained:int}
	 */
	public function erase_legacy( $email ) {
		global $wpdb;
		$lower = strtolower( $email );
		$out   = array(
			'removed'  => 0,
			'retained' => 0,
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $this->has_table( 'npmp_members' ) ) {
			$out['removed'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}npmp_members WHERE LOWER( email ) = %s", $lower ) );
		}
		if ( $this->has_table( 'npmp_contacts' ) ) {
			$out['removed'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}npmp_contacts WHERE LOWER( email ) = %s", $lower ) );
		}
		if ( $this->has_table( 'npmp_donations' ) ) {
			$out['retained'] += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}npmp_donations SET name = '', email = '' WHERE LOWER( email ) = %s", $lower ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $out;
	}
}

/**
 * The store the privacy tools use. Tests hand in their own.
 *
 * @param object|null $store Replacement store, or null to read.
 * @return NPMP_Privacy_Store|object
 */
function npmp_privacy_store( $store = null ) {
	static $current = null;
	if ( null !== $store ) {
		$current = $store;
	}
	if ( null === $current ) {
		$current = new NPMP_Privacy_Store();
	}
	return $current;
}

/**
 * Register our exporters with WordPress.
 *
 * @param array $exporters Core's exporter list.
 * @return array
 */
function npmp_privacy_register_exporters( $exporters ) {
	$exporters['nonprofit-manager-contact'] = array(
		'exporter_friendly_name' => __( 'Nonprofit Manager contacts', 'nonprofit-manager' ),
		'callback'               => 'npmp_privacy_export_contact',
	);
	$exporters['nonprofit-manager-gifts']   = array(
		'exporter_friendly_name' => __( 'Nonprofit Manager gifts', 'nonprofit-manager' ),
		'callback'               => 'npmp_privacy_export_gifts',
	);
	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'npmp_privacy_register_exporters' );

/**
 * Register our eraser with WordPress.
 *
 * @param array $erasers Core's eraser list.
 * @return array
 */
function npmp_privacy_register_erasers( $erasers ) {
	$erasers['nonprofit-manager'] = array(
		'eraser_friendly_name' => __( 'Nonprofit Manager', 'nonprofit-manager' ),
		'callback'             => 'npmp_privacy_erase',
	);
	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'npmp_privacy_register_erasers' );

/**
 * A word from the label map, or the default when the map isn't loaded.
 *
 * @param string $key      npmp_label() key.
 * @param string $fallback Default word.
 * @return string
 */
function npmp_privacy_label( $key, $fallback ) {
	return function_exists( 'npmp_label' ) ? (string) npmp_label( $key ) : $fallback;
}

/**
 * One name and value pair, or nothing when the value is empty.
 *
 * @param array  $data  Pairs so far.
 * @param string $name  Field name.
 * @param mixed  $value Value.
 * @return array
 */
function npmp_privacy_pair( array $data, $name, $value ) {
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $k => $v ) {
			$parts[] = is_int( $k ) ? (string) $v : $k . ': ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
		}
		$value = implode( ', ', $parts );
	}
	$value = trim( (string) $value );
	if ( '' !== $value ) {
		$data[] = array(
			'name'  => $name,
			'value' => $value,
		);
	}
	return $data;
}

/**
 * The words for a contact status.
 *
 * @param string $status Stored status.
 * @return string
 */
function npmp_privacy_status_label( $status ) {
	$labels = array(
		'subscribed'   => __( 'Active', 'nonprofit-manager' ),
		'pending'      => __( 'Pending', 'nonprofit-manager' ),
		'donor'        => npmp_privacy_label( 'donor', __( 'Donor', 'nonprofit-manager' ) ),
		'volunteer'    => __( 'Volunteer', 'nonprofit-manager' ),
		'partner'      => __( 'Partner', 'nonprofit-manager' ),
		'inactive'     => __( 'Inactive', 'nonprofit-manager' ),
		'unsubscribed' => __( 'Unsubscribed', 'nonprofit-manager' ),
	);
	return $labels[ $status ] ?? (string) $status;
}

/**
 * Yes or No for a stored flag, or '' when never set.
 *
 * @param mixed $value Stored value.
 * @return string
 */
function npmp_privacy_yes_no( $value ) {
	if ( '' === $value || null === $value ) {
		return '';
	}
	return ( ! empty( $value ) && 'no' !== $value && '0' !== (string) $value ) ? __( 'Yes', 'nonprofit-manager' ) : __( 'No', 'nonprofit-manager' );
}

/**
 * Exporter: every contact with the address, plus its email subscription.
 *
 * @param string $email Email address from the request.
 * @param int    $page  Page, from 1. One page holds everything.
 * @return array{data:array,done:bool}
 */
function npmp_privacy_export_contact( $email, $page = 1 ) {
	$email = trim( (string) $email );
	$store = npmp_privacy_store();
	$items = array();
	$ids   = array();

	foreach ( $store->contacts( $email ) as $contact ) {
		$record = $store->contact_record( $contact['id'] );
		if ( ! $record ) {
			continue;
		}
		$ids[] = (int) $contact['id'];
		$m     = $record['meta'];
		$get   = static function ( $key ) use ( $m ) {
			return $m[ $key ] ?? '';
		};

		$data = array();
		$data = npmp_privacy_pair( $data, __( 'Name', 'nonprofit-manager' ), $record['title'] );
		$data = npmp_privacy_pair( $data, __( 'Email', 'nonprofit-manager' ), $get( 'npmp_email' ) );
		$data = npmp_privacy_pair( $data, __( 'Status', 'nonprofit-manager' ), npmp_privacy_status_label( $contact['status'] ) );
		$data = npmp_privacy_pair( $data, npmp_privacy_label( 'level', __( 'Membership Level', 'nonprofit-manager' ) ), $get( 'npmp_membership_level' ) );
		$data = npmp_privacy_pair( $data, __( 'Tags', 'nonprofit-manager' ), $get( 'npmp_tags' ) );
		$data = npmp_privacy_pair( $data, __( 'Phone', 'nonprofit-manager' ), $get( 'npmp_phone' ) );
		$data = npmp_privacy_pair( $data, __( 'Mobile', 'nonprofit-manager' ), $get( 'npmp_mobile' ) );
		$data = npmp_privacy_pair( $data, __( 'Address line 1', 'nonprofit-manager' ), $get( 'npmp_address_line1' ) );
		$data = npmp_privacy_pair( $data, __( 'Address line 2', 'nonprofit-manager' ), $get( 'npmp_address_line2' ) );
		$data = npmp_privacy_pair( $data, __( 'City', 'nonprofit-manager' ), $get( 'npmp_city' ) );
		$data = npmp_privacy_pair( $data, __( 'State or region', 'nonprofit-manager' ), $get( 'npmp_state' ) );
		$data = npmp_privacy_pair( $data, __( 'Postal code', 'nonprofit-manager' ), $get( 'npmp_postal_code' ) );
		$data = npmp_privacy_pair( $data, __( 'Country', 'nonprofit-manager' ), $get( 'npmp_country' ) );
		/**
		 * Filter whether the export includes a contact's Notes. Your team
		 * writes Notes, and a note can name other people, so a site can
		 * leave them out and send what the person may see by hand.
		 *
		 * @param bool   $include    Default true.
		 * @param int    $contact_id Contact post ID.
		 * @param string $email      Address in the request.
		 */
		if ( apply_filters( 'npmp_privacy_export_notes', true, (int) $contact['id'], $email ) ) {
			$data = npmp_privacy_pair( $data, __( 'Notes', 'nonprofit-manager' ), wp_strip_all_tags( (string) $get( 'npmp_notes' ) ) );
		}
		$data = npmp_privacy_pair( $data, __( 'Source', 'nonprofit-manager' ), $get( 'npmp_source' ) );
		$data = npmp_privacy_pair( $data, __( 'Added', 'nonprofit-manager' ), $record['date'] );
		$data = npmp_privacy_pair( $data, __( 'Last updated', 'nonprofit-manager' ), $record['modified'] );
		$data = npmp_privacy_pair( $data, __( 'Last contacted', 'nonprofit-manager' ), $get( 'npmp_last_contacted' ) );
		$data = npmp_privacy_pair( $data, __( 'Last gift', 'nonprofit-manager' ), $get( 'npmp_last_donation_at' ) );
		$data = npmp_privacy_pair( $data, __( 'Number of gifts', 'nonprofit-manager' ), $get( 'npmp_donation_count' ) );
		$total = $get( 'npmp_donation_totals' );
		if ( ! is_array( $total ) || ! $total ) {
			$amount = $get( 'npmp_donation_total' );
			$total  = '' === (string) $amount ? '' : trim( $amount . ' ' . $get( 'npmp_donation_currency' ) );
		}
		$data = npmp_privacy_pair( $data, __( 'Total given', 'nonprofit-manager' ), $total );
		$data = npmp_privacy_pair( $data, __( 'Erased on request', 'nonprofit-manager' ), $get( '_npmp_privacy_erased' ) );

		$items[] = array(
			'group_id'          => 'npmp-contact',
			'group_label'       => __( 'Contact record', 'nonprofit-manager' ),
			'group_description' => __( 'What we keep about you in Nonprofit Manager.', 'nonprofit-manager' ),
			'item_id'           => 'npmp-contact-' . (int) $contact['id'],
			'data'              => $data,
		);

		$sub = array();
		$sub = npmp_privacy_pair( $sub, __( 'Email status', 'nonprofit-manager' ), npmp_privacy_status_label( $contact['status'] ) );
		$sub = npmp_privacy_pair( $sub, __( 'Weekly digest', 'nonprofit-manager' ), npmp_privacy_yes_no( $get( '_npmp_weekly_digest' ) ) );
		$sub = npmp_privacy_pair( $sub, __( 'New post emails', 'nonprofit-manager' ), npmp_privacy_yes_no( $get( '_npmp_notify_posts' ) ) );
		$sub = npmp_privacy_pair( $sub, __( 'New event emails', 'nonprofit-manager' ), npmp_privacy_yes_no( $get( '_npmp_notify_events' ) ) );
		$sub = npmp_privacy_pair( $sub, __( 'Waiting for email confirmation', 'nonprofit-manager' ), is_array( $get( '_npmp_signup_confirm' ) ) ? __( 'Yes', 'nonprofit-manager' ) : '' );
		$items[] = array(
			'group_id'          => 'npmp-subscription',
			'group_label'       => __( 'Email subscription', 'nonprofit-manager' ),
			'group_description' => __( 'Your email choices on this site.', 'nonprofit-manager' ),
			'item_id'           => 'npmp-subscription-' . (int) $contact['id'],
			'data'              => $sub,
		);
	}

	// Rows in the tables from before contacts and gifts became posts. The
	// eraser deletes or blanks these too, so the person sees them first.
	$legacy_labels = array(
		'npmp_members'   => __( 'Older contact record', 'nonprofit-manager' ),
		'npmp_contacts'  => __( 'Older email signup', 'nonprofit-manager' ),
		'npmp_donations' => __( 'Older gift record', 'nonprofit-manager' ),
	);
	foreach ( $store->legacy_rows( $email ) as $table => $rows ) {
		foreach ( array_values( $rows ) as $n => $row ) {
			$data = array();
			foreach ( $row as $column => $value ) {
				$data = npmp_privacy_pair( $data, ucfirst( str_replace( '_', ' ', (string) $column ) ), $value );
			}
			$items[] = array(
				'group_id'          => 'npmp-legacy',
				'group_label'       => __( 'Older records', 'nonprofit-manager' ),
				'group_description' => __( 'Records from an earlier version of Nonprofit Manager.', 'nonprofit-manager' ),
				'item_id'           => 'npmp-legacy-' . $table . '-' . ( $n + 1 ),
				'data'              => npmp_privacy_pair( $data, __( 'Kind', 'nonprofit-manager' ), $legacy_labels[ $table ] ?? $table ),
			);
		}
	}

	$sent     = $store->count_sent( $email );
	$tracking = $store->count_tracking( $ids );
	if ( $sent || $tracking['opens'] || $tracking['clicks'] ) {
		$activity = array();
		$activity = npmp_privacy_pair( $activity, __( 'Newsletters sent to you', 'nonprofit-manager' ), $sent ? (string) $sent : '' );
		$activity = npmp_privacy_pair( $activity, __( 'Newsletter opens recorded', 'nonprofit-manager' ), $tracking['opens'] ? (string) $tracking['opens'] : '' );
		$activity = npmp_privacy_pair( $activity, __( 'Newsletter link clicks recorded', 'nonprofit-manager' ), $tracking['clicks'] ? (string) $tracking['clicks'] : '' );
		$items[]  = array(
			'group_id'    => 'npmp-subscription',
			'group_label' => __( 'Email subscription', 'nonprofit-manager' ),
			'item_id'     => 'npmp-newsletter-activity',
			'data'        => $activity,
		);
	}

	return array(
		'data' => $items,
		'done' => true,
	);
}

/**
 * Exporter: the address's gifts, a page at a time, never a card number or
 * a gateway's raw answer.
 *
 * @param string $email Email address from the request.
 * @param int    $page  Page, from 1.
 * @return array{data:array,done:bool}
 */
function npmp_privacy_export_gifts( $email, $page = 1 ) {
	$page  = max( 1, (int) $page );
	$limit = (int) NPMP_PRIVACY_PAGE_SIZE;
	$rows  = npmp_privacy_store()->gifts( trim( (string) $email ), $limit, ( $page - 1 ) * $limit );
	$items = array();
	$label = npmp_privacy_label( 'donations', __( 'Donations', 'nonprofit-manager' ) );

	foreach ( $rows as $row ) {
		$currency = '' !== (string) $row['currency'] ? (string) $row['currency'] : 'USD';
		$data     = array();
		$data     = npmp_privacy_pair( $data, __( 'Date', 'nonprofit-manager' ), $row['date'] );
		$data     = npmp_privacy_pair( $data, __( 'Amount', 'nonprofit-manager' ), number_format( (float) $row['amount'], 2, '.', '' ) );
		$data     = npmp_privacy_pair( $data, __( 'Currency', 'nonprofit-manager' ), $currency );
		$data     = npmp_privacy_pair( $data, __( 'Frequency', 'nonprofit-manager' ), function_exists( 'npmp_frequency_label' ) ? npmp_frequency_label( $row['frequency'] ) : $row['frequency'] );
		$data     = npmp_privacy_pair( $data, __( 'Payment method', 'nonprofit-manager' ), function_exists( 'npmp_gateway_label' ) ? npmp_gateway_label( $row['gateway'] ) : $row['gateway'] );
		$items[]  = array(
			'group_id'    => 'npmp-gifts',
			'group_label' => $label,
			'item_id'     => 'npmp-gift-' . (int) $row['id'],
			'data'        => $data,
		);
	}

	return array(
		'data' => $items,
		'done' => count( $rows ) < $limit,
	);
}

/**
 * Whether a contact stays, cut down to its email and Unsubscribed status.
 *
 * @param array  $contact Contact row from the store.
 * @param string $email   Address.
 * @return bool
 */
function npmp_privacy_keeps_unsubscribed( array $contact, $email ) {
	if ( 'unsubscribed' !== $contact['status'] || 'publish' !== $contact['post_status'] ) {
		return false;
	}
	/**
	 * Filter whether an erase request keeps an unsubscribed contact's email
	 * and status, so a later import or signup never mails the address again.
	 * Return false to delete the contact like any other.
	 *
	 * @param bool   $keep       Default true.
	 * @param int    $contact_id Contact post ID.
	 * @param string $email      Address in the request.
	 */
	return (bool) apply_filters( 'npmp_privacy_keep_unsubscribed', true, (int) $contact['id'], $email );
}

/**
 * Plan statuses that can still produce a charge. Pro writes five statuses
 * to npmp_recurring_donations: active and failed (Stripe retries the card)
 * can charge. lapsed can too: Pro marks a dues row lapsed when failures
 * reach its limit while Stripe may still retry, and also after Stripe
 * deletes the subscription, and the row can't tell the two apart. paused
 * means "Stop after this period" (cancel_at_period_end at Stripe), and
 * cancelled is closed: neither charges unless someone acts. Pro's
 * npmp_pro_privacy_plan_bills() uses the same list.
 *
 * @return string[]
 */
function npmp_privacy_billing_statuses() {
	return array( 'active', 'failed', 'lapsed' );
}

/**
 * Whether a plan row can still charge the person.
 *
 * @param array $plan Row with status and frequency.
 * @return bool
 */
function npmp_privacy_plan_can_charge( array $plan ) {
	return 'one_time' !== (string) ( $plan['frequency'] ?? '' ) && in_array( (string) ( $plan['status'] ?? '' ), npmp_privacy_billing_statuses(), true );
}

/**
 * Whether Pro's eraser is on the list core runs for this request. Pro
 * registers it at priority 9, so core runs it before ours.
 *
 * @return bool
 */
function npmp_privacy_pro_eraser_present() {
	$erasers = (array) apply_filters( 'wp_privacy_personal_data_erasers', array() );
	return isset( $erasers['nonprofit-manager-pro'] );
}

/**
 * Our own reasons to keep the contact and gifts, read from Pro's tables
 * without Pro's code, so they hold with Pro off, old, or current.
 *
 * @param string $email Address.
 * @param int[]  $ids   Contact IDs.
 * @return string[]
 */
function npmp_privacy_hold_reasons( $email, array $ids ) {
	$store    = npmp_privacy_store();
	$pro_on   = defined( 'NPMP_PRO_VERSION' );
	$gifts    = npmp_privacy_label( 'donations_lc', __( 'donations', 'nonprofit-manager' ) );
	$statuses = array();
	foreach ( $store->plans( $ids ) as $plan ) {
		if ( npmp_privacy_plan_can_charge( $plan ) ) {
			$statuses[ $plan['status'] ] = true;
		}
	}
	$reasons = array();
	foreach ( array_keys( $statuses ) as $status ) {
		if ( 'failed' === $status ) {
			/* translators: %s: lowercase plural word for donations, such as donations or contributions. */
			$what = __( 'This person has a recurring plan with a failed payment that Stripe may still retry, so you keep their contact record and %s for billing.', 'nonprofit-manager' );
		} elseif ( 'lapsed' === $status ) {
			/* translators: %s: lowercase plural word for donations, such as donations or contributions. */
			$what = __( 'This person has a lapsed plan that Stripe may still retry, so you keep their contact record and %s for billing.', 'nonprofit-manager' );
		} else {
			/* translators: %s: lowercase plural word for donations, such as donations or contributions. */
			$what = __( 'This person has an active recurring plan, so you keep their contact record and %s for billing.', 'nonprofit-manager' );
		}
		$reasons[] = sprintf( $what, $gifts ) . ' ' . ( $pro_on
			? __( 'Cancel the plan on the Recurring Donations or Membership Dues screen, then run this request a second time.', 'nonprofit-manager' )
			: __( 'Activate Nonprofit Manager Pro, cancel the plan on the Recurring Donations or Membership Dues screen, then run this request a second time.', 'nonprofit-manager' ) );
	}
	if ( ! $reasons && ! npmp_privacy_pro_eraser_present() && $store->pro_leftovers( $email, $ids ) > 0 ) {
		$reasons[] = sprintf(
			/* translators: %s: lowercase plural word for donations, such as donations or contributions. */
			__( 'This person has data from Nonprofit Manager Pro, such as plans, custom fields, or groups, so you keep their contact record and %s for now. Activate or update Nonprofit Manager Pro, then run this request a second time.', 'nonprofit-manager' ),
			$gifts
		);
	}
	return $reasons;
}

/**
 * Eraser: see the top of this file for the rules. Each request strips up to
 * NPMP_PRIVACY_PAGE_SIZE gifts and answers done=false while gifts remain.
 * The contacts go in the request that finds no gifts left. A second run
 * finds nothing left to change.
 *
 * @param string $email Email address from the request.
 * @param int    $page  Page, from 1.
 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
 */
function npmp_privacy_erase( $email, $page = 1 ) {
	$email    = trim( (string) $email );
	$page     = max( 1, (int) $page );
	$store    = npmp_privacy_store();
	$removed  = false;
	$retained = false;
	$messages = array();

	if ( '' === $email ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$contacts = $store->contacts( $email );
	$ids      = array_map( 'intval', wp_list_pluck( $contacts, 'id' ) );

	$holds = npmp_privacy_hold_reasons( $email, $ids );

	/**
	 * Filter the reasons an erase request must keep this person's contact
	 * record and gifts. We add our own first: a Pro plan that can still
	 * charge, or Pro data that only Pro's eraser clears while that eraser
	 * isn't here. Each reason is a sentence the admin reads on Erase
	 * Personal Data.
	 *
	 * @param string[] $holds       Reasons.
	 * @param string   $email       Address in the request.
	 * @param int[]    $contact_ids Contacts with the address.
	 */
	$holds = array_values( array_unique( array_filter( array_map( 'strval', (array) apply_filters( 'npmp_privacy_erase_holds', $holds, $email, $ids ) ), 'strlen' ) ) );

	// Queued and sent newsletter rows, digest rows, opens, and clicks name
	// the person and nothing bills from them, so they go even with a hold.
	// Each needs a read of a table with no index on the address, so we do
	// it on the first page only.
	if ( 1 === $page ) {
		if ( $store->delete_queue( $email, $ids ) > 0 ) {
			$removed = true;
		}
		if ( $store->delete_tracking( $ids ) > 0 ) {
			$removed = true;
		}
	}

	if ( $holds ) {
		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => $holds,
			'done'           => true,
		);
	}

	$limit    = (int) NPMP_PRIVACY_PAGE_SIZE;
	$gift_ids = $store->gift_ids_to_strip( $email, $limit );
	foreach ( $gift_ids as $gift_id ) {
		$store->strip_gift( $gift_id );
	}
	if ( count( $gift_ids ) >= $limit ) {
		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array(),
			'done'           => false,
		);
	}
	// Gifts stripped on this page, or on an earlier page of this request.
	if ( $gift_ids || $page > 1 ) {
		$removed    = true;
		$retained   = true;
		$messages[] = sprintf(
			/* translators: %s: lowercase plural word for donations, such as donations or contributions. */
			__( 'You keep this person\'s %s for your accounting records, with the date, amount, currency, and frequency, and without the name, email, or payer details.', 'nonprofit-manager' ),
			npmp_privacy_label( 'donations_lc', __( 'donations', 'nonprofit-manager' ) )
		);
	}

	$kept = false;
	foreach ( $contacts as $contact ) {
		if ( ! $kept && npmp_privacy_keeps_unsubscribed( $contact, $email ) ) {
			$kept = true;
			if ( $store->strip_contact( $contact['id'], $email ) ) {
				$removed = true;
			}
			$retained   = true;
			$messages[] = __( 'You keep this email address and its Unsubscribed status, so nobody emails the address after a later import or signup. You erased the rest of the contact record.', 'nonprofit-manager' );
			continue;
		}
		if ( $store->delete_contact( $contact['id'], $email ) ) {
			$removed = true;
		}
	}

	$legacy = $store->erase_legacy( $email );
	if ( $legacy['removed'] > 0 || $legacy['retained'] > 0 ) {
		$removed = true;
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => $retained,
		'messages'       => $messages,
		'done'           => true,
	);
}

/**
 * Suggested text for the site's privacy policy (Settings, Privacy).
 *
 * @return string HTML.
 */
function npmp_privacy_policy_text() {
	$p = static function ( $text, $class = '' ) {
		return '<p' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $text ) . '</p>';
	};
	return $p( __( 'Nonprofit Manager suggests the text below. Change this text to match what your group collects, which services you use, and how long you keep each record.', 'nonprofit-manager' ), 'privacy-policy-tutorial' )
		. '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nonprofit-manager' ) . ' </strong>'
		. $p( __( 'When you sign up for our emails, join, or give, we keep your name, your email address, and the details you give us, such as your postal address, phone number, and membership level. We may also add contacts from a list we bring over from another service we used.', 'nonprofit-manager' ) )
		. $p( __( 'When you give online, you pay through Stripe, PayPal, or Venmo, and that company receives your payment and the details you enter. For each gift we keep the date, amount, currency, frequency, and the payment company\'s transaction ID for our accounting records. If our forms use spam protection, Cloudflare Turnstile or Google reCAPTCHA checks each form you send.', 'nonprofit-manager' ) )
		. $p( __( 'We send our emails through the email service we set up, such as our own mail server, Amazon SES, SendGrid, Mailgun, Postmark, Brevo, or Nonprofit Mailer, and that service receives your email address with each message. If we use Nonprofit Mailer, we also keep a copy of our contact list in Nonprofit Mailer. If we turn on newsletter tracking, we record when you open a newsletter and which links you click.', 'nonprofit-manager' ) )
		. $p( __( 'You can ask for a copy of the personal data we keep about you, or ask us to erase that data. When we erase your data, we delete your contact record or cut the record down, and we take your name and email address off your gift records. We keep each gift\'s date, amount, currency, frequency, and transaction ID, and the amount, frequency, and dates of any ended recurring plan, for our accounting records. Each payment company and email service keeps its own records under its own policy. While we can still charge you through a recurring plan, we keep your record until you or we cancel the plan. If you unsubscribed, we keep your email address and that choice, so we can keep honoring your choice.', 'nonprofit-manager' ) );
}

/**
 * Hand the suggested text to Settings, Privacy.
 *
 * @return void
 */
function npmp_privacy_policy_content() {
	if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
		wp_add_privacy_policy_content( __( 'Nonprofit Manager', 'nonprofit-manager' ), wp_kses_post( npmp_privacy_policy_text() ) );
	}
}
add_action( 'admin_init', 'npmp_privacy_policy_content' );

/**
 * The note on General Settings that points to WordPress's own tools.
 *
 * @return void
 */
function npmp_privacy_settings_card() {
	$export = current_user_can( 'export_others_personal_data' ) ? admin_url( 'export-personal-data.php' ) : '';
	$erase  = current_user_can( 'erase_others_personal_data' ) ? admin_url( 'erase-personal-data.php' ) : '';
	$link   = static function ( $url, $text ) {
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
	};
	echo '<div class="npmp-settings-card">';
	echo '<h2>' . esc_html__( 'Personal data requests', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . sprintf(
		/* translators: 1: link to Tools, Export Personal Data, 2: link to Tools, Erase Personal Data. */
		esc_html__( 'When someone asks for a copy of their data or asks you to erase their data, use %1$s or %2$s under Tools. You\'ll find their contact record, gifts, and email history from Nonprofit Manager in both.', 'nonprofit-manager' ),
		$link( $export, __( 'Export Personal Data', 'nonprofit-manager' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $link.
		$link( $erase, __( 'Erase Personal Data', 'nonprofit-manager' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $link.
	) . '</p>';
	echo '<p class="description">' . esc_html(
		sprintf(
			/* translators: %s: lowercase plural word for donations, such as donations or contributions. */
			__( 'When you erase someone, you keep the date, amount, currency, and frequency of their %s for your accounting records, and you keep an unsubscribed address so nobody can email that address by mistake.', 'nonprofit-manager' ),
			npmp_privacy_label( 'donations_lc', __( 'donations', 'nonprofit-manager' ) )
		)
	) . '</p>';
	echo '</div>';
}
