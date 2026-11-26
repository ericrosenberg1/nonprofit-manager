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
 *   - Keeps the contact, its gifts, and its old table rows when the filter
 *     npmp_privacy_erase_holds names a reason (Pro: an active dues or
 *     recurring plan), and says so. Queue and tracking rows still go.
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
	 * contact exists, and names nobody.
	 *
	 * @var string[]
	 */
	const KEEP_META = array( 'npmp_email', 'npmp_status', '_npmp_free_import' );

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
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_title'   => $title,
					'post_content' => '',
					'post_excerpt' => '',
				)
			);
			$changed = true;
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
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => __( 'Erased on request', 'nonprofit-manager' ),
				'post_content' => '',
				'post_excerpt' => '',
			)
		);
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
	 * Newsletter and digest queue rows for this address, any status.
	 *
	 * @param string $email Email address.
	 * @return int
	 */
	public function count_queue( $email ) {
		global $wpdb;
		$count = 0;
		foreach ( array( 'npmp_newsletter_queue', 'npmp_digest_queue' ) as $name ) {
			if ( $this->has_table( $name ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$name} WHERE LOWER( email ) = %s", strtolower( $email ) ) );
			}
		}
		return $count;
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
	 * Delete this address's newsletter and digest queue rows, any status.
	 *
	 * @param string $email Email address.
	 * @return int Rows deleted.
	 */
	public function delete_queue( $email ) {
		global $wpdb;
		$deleted = 0;
		foreach ( array( 'npmp_newsletter_queue', 'npmp_digest_queue' ) as $name ) {
			if ( $this->has_table( $name ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$name} WHERE LOWER( email ) = %s", strtolower( $email ) ) );
			}
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
			if ( $this->has_table( 'npmp_newsletter_' . $kind ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers.
				$deleted += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}npmp_newsletter_{$kind} WHERE user_id IN ({$in})" );
			}
		}
		return $deleted;
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
		$data = npmp_privacy_pair( $data, __( 'Notes', 'nonprofit-manager' ), wp_strip_all_tags( (string) $get( 'npmp_notes' ) ) );
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

		$items[] = array(
			'group_id'          => 'npmp-contact',
			'group_label'       => __( 'Contact record', 'nonprofit-manager' ),
			'group_description' => __( 'What this site keeps about you in Nonprofit Manager.', 'nonprofit-manager' ),
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

	/**
	 * Filter the reasons an erase request must keep this person's contact
	 * record and gifts. Pro names an active dues or recurring plan here,
	 * since the plan's billing reads them. Each reason is a sentence the
	 * admin reads on Erase Personal Data.
	 *
	 * @param string[] $holds       Reasons, none by default.
	 * @param string   $email       Address in the request.
	 * @param int[]    $contact_ids Contacts with the address.
	 */
	$holds = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'npmp_privacy_erase_holds', array(), $email, $ids ) ), 'strlen' ) );

	// Queued and sent newsletter rows, digest rows, opens, and clicks name
	// the person and nothing bills from them, so they go even with a hold.
	if ( $store->delete_queue( $email ) > 0 ) {
		$removed = true;
	}
	if ( $store->delete_tracking( $ids ) > 0 ) {
		$removed = true;
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
	return $p( __( 'Nonprofit Manager suggests the text below. Change this text to match what your group collects and how long you keep each record.', 'nonprofit-manager' ), 'privacy-policy-tutorial' )
		. '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nonprofit-manager' ) . ' </strong>'
		. $p( __( 'When you sign up for our emails, join, or give, we keep your name, your email address, and the details you give us, such as your postal address, phone number, and membership level. For each gift we keep the date, amount, currency, and frequency for our accounting records.', 'nonprofit-manager' ) )
		. $p( __( 'When you give online, you pay through Stripe, PayPal, or Venmo, and that company receives your payment and the details you enter. If our forms use spam protection, Cloudflare Turnstile or Google reCAPTCHA checks each form you send.', 'nonprofit-manager' ) )
		. $p( __( 'You can ask for a copy of the personal data we keep about you, or ask us to erase that data. When we erase your data, we delete your contact record and keep your gift records without your name or email address. If you unsubscribed, we keep only your email address and that choice, so we never email you again.', 'nonprofit-manager' ) );
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
