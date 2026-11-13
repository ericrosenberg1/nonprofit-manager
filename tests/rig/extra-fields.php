<?php
/**
 * Real-WordPress run of extra fields as Pro custom fields
 * (includes/import/extra-fields.php, Pro's includes/custom-fields/import-target.php).
 *
 * Two sources:
 *   CSV through the wizard's own calls (start the job, import the file page,
 *   fold the counts), with columns for a new dropdown, date, yes-or-no box,
 *   long text, and one mapped to a dropdown you already have.
 *   MailPoet through the staged importer, with fields made by MailPoet's own
 *   API (date, select, checkbox, partial date, number text, long text) and
 *   one value written straight to its table to stand for old, bad data.
 *
 * With Pro active: fields are made once, values land, a bad value is skipped
 * and counted, a field you had keeps its choices, and a second run makes no
 * new field and no new contact. With Pro inactive: the mapping drops every
 * custom field column, the MailPoet checklist shows locked, and nothing is
 * written.
 *
 * Needs a throwaway WordPress with this plugin and MailPoet (5.45.2 verified)
 * active, `wp` on PATH. Run it twice, once with Pro on and once off:
 *   wp eval-file tests/rig/extra-fields.php
 *
 * It deletes every contact, field, and MailPoet row it made and puts the
 * options it touched back.
 */

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );
$pro    = npmp_import_extra_fields_supported();
$domain = 'rig-extra.example.org';
$mails  = 0;
add_filter(
	'pre_wp_mail',
	function () use ( &$mails ) {
		++$mails;
		return true;
	}
);
echo $pro ? "Pro active\n" : "Pro inactive\n";

// What this run changes, to put back at the end.
$saved_options = array();
foreach ( array( 'npmp_custom_field_definitions', 'npmp_pro_import_made_fields', 'npmp_import_extra_plan_mailpoet', 'npmp_import_extra_counts_mailpoet', 'npmp_mailpoet_imported', 'npmp_mailpoet_stage' ) as $name ) {
	$saved_options[ $name ] = get_option( $name, null );
}
$contacts_before = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) );
$cf_table        = $wpdb->prefix . 'npmp_custom_fields';
$has_cf_table    = $cf_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cf_table ) );
$cf_rows_before  = $has_cf_table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cf_table}" ) : 0;
$manager         = $pro ? NPMP_Custom_Fields_Manager::get_instance() : null;
$values_for      = function ( $email ) use ( $manager ) {
	$member = NPMP_Member_Manager::get_instance()->get_member_by_email( $email );
	if ( ! $member ) {
		return null;
	}
	return $manager ? $manager->get_field_values( 'member', (int) $member->id ) : array();
};
$field_by_label  = function ( $label ) use ( $manager ) {
	if ( ! $manager ) {
		return null;
	}
	foreach ( $manager->get_field_definitions( 'member' ) as $field ) {
		if ( $field['label'] === $label ) {
			return $field;
		}
	}
	return null;
};

// A dropdown you already have, which an import must never change.
$region_id = '';
if ( $pro ) {
	$region_id = (string) $manager->save_field_definition(
		array(
			'label'        => 'Rig Region',
			'type'         => 'dropdown',
			'entity_types' => array( 'member' ),
			'options'      => array( 'North', 'South' ),
		)
	);
}

// =====================================================================
echo "\nCSV\n";
// =====================================================================
$csv  = "Email,First Name,Shirt,Birthday,Volunteer,Story,Chapter\n";
$csv .= "ada@{$domain},Ada,M,07/04/1990,Yes,\"Line one\nLine two\",North\n";
$csv .= "ben@{$domain},Ben,XXL,1985-12-01,no,Short note,East\n";
$csv .= "cal@{$domain},Cal,S,someday,yes,,South\n";
$csv .= "dee@{$domain},Dee,,,,,\n";
$path = wp_tempnam( 'npmp-rig-extra.csv' );
file_put_contents( $path, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$import  = NPMP_Import_Manager::get_instance();
$preview = $import->get_file_preview( $path, 'csv' );
$columns = npmp_import_extra_wizard_columns( (array) $preview['headers'], (array) $preview['rows'], (array) $preview['auto_map'] );
$labels  = wp_list_pluck( $columns, 'label' );
$ok( 'the five unmatched columns are offered', array( 2 => 'Shirt', 3 => 'Birthday', 4 => 'Volunteer', 5 => 'Story', 6 => 'Chapter' ) === $labels, $labels );
$ok( 'types guessed from the rows', array( 2 => 'text', 3 => 'text', 4 => 'checkbox', 5 => 'textarea' ) === array_slice( wp_list_pluck( $columns, 'type' ), 0, 4, true ), wp_list_pluck( $columns, 'type' ) );
$payload = npmp_import_extra_wizard_payload( $columns );
$ok( 'payload says whether Pro is here', $pro === $payload['supported'] );
$ok( 'without Pro the payload carries the badge', $pro || false !== strpos( $payload['badge'], 'import_custom_fields' ), $payload['badge'] );
if ( $pro ) {
	$ok( 'with Pro your Region field is a target', isset( $payload['targets'][ $region_id ] ) );
}

$run_csv = function () use ( $import, $path, $region_id ) {
	$mapping = array(
		0 => 'email',
		1 => 'first_name',
		2 => 'cf_new',
		3 => 'cf_new',
		4 => 'cf_new',
		5 => 'cf_new',
		6 => $region_id ? 'cf:' . $region_id : 'cf:npmp_cf_rig_region',
	);
	$_POST['extra_label'] = array( 2 => 'Rig Shirt', 3 => 'Rig Birthday', 4 => 'Rig Volunteer', 5 => 'Rig Notes' );
	$_POST['extra_type']  = array( 2 => 'dropdown', 3 => 'date', 4 => 'checkbox', 5 => 'textarea' );
	$options              = array(
		'duplicate_handling' => 'update',
		'default_status'     => 'subscribed',
		'source'             => 'Rig CSV',
	);
	list( $mapping, $options ) = npmp_import_extra_start_job( $mapping, $options );
	unset( $_POST['extra_label'], $_POST['extra_type'] );
	$page   = $import->import_file_page( 'csv', $path, $mapping, $options, 0, 200 );
	$totals = npmp_import_extra_merge_job_totals( $page['page_stats'] );
	return array( $mapping, $options, $totals );
};

list( $mapping, $options, $totals ) = $run_csv();
$ok( 'four contacts made', 4 === (int) $totals['imported'], $totals );
if ( $pro ) {
	$shirt = $field_by_label( 'Rig Shirt' );
	$bday  = $field_by_label( 'Rig Birthday' );
	$vol   = $field_by_label( 'Rig Volunteer' );
	$notes = $field_by_label( 'Rig Notes' );
	$ok( 'four fields made with the owner types', $shirt && $bday && $vol && $notes && 'dropdown' === $shirt['type'] && 'date' === $bday['type'] && 'checkbox' === $vol['type'] && 'textarea' === $notes['type'], array( $shirt, $bday, $vol, $notes ) );
	$ok( 'mapping points at the made fields', 'cf:' . $shirt['id'] === $mapping[2] && 'cf:' . $region_id === $mapping[6], $mapping );
	$ok( 'the new dropdown took each choice once', array( 'M', 'XXL', 'S' ) === $shirt['options'], $shirt['options'] );
	$ada = $values_for( "ada@{$domain}" );
	$ben = $values_for( "ben@{$domain}" );
	$cal = $values_for( "cal@{$domain}" );
	$dee = $values_for( "dee@{$domain}" );
	$ok( 'Ada: US date, yes, two-line note, North', '1990-07-04' === ( $ada[ $bday['id'] ] ?? '' ) && '1' === ( $ada[ $vol['id'] ] ?? '' ) && "Line one\nLine two" === ( $ada[ $notes['id'] ] ?? '' ) && 'North' === ( $ada[ $region_id ] ?? '' ), $ada );
	$ok( 'Ben: Y-m-d date, XXL, no box saved, East skipped', '1985-12-01' === ( $ben[ $bday['id'] ] ?? '' ) && 'XXL' === ( $ben[ $shirt['id'] ] ?? '' ) && ! isset( $ben[ $vol['id'] ] ) && ! isset( $ben[ $region_id ] ), $ben );
	$ok( 'Cal: bad date skipped, South kept', ! isset( $cal[ $bday['id'] ] ) && 'South' === ( $cal[ $region_id ] ?? '' ), $cal );
	$ok( 'Dee: empty cells write nothing', array() === $dee, $dee );
	$want = array( $bday['id'] => 1, $region_id => 1 );
	$got  = $totals['extra']['skipped'];
	ksort( $want );
	ksort( $got );
	$ok( 'skips counted per field', $want === $got, $totals['extra'] );
	$ok( 'values counted', 11 === (int) $totals['extra']['written'], $totals['extra'] );
	$ok( 'result lines name the field', false !== strpos( implode( ' | ', $totals['extra_lines'] ), 'skipped for Rig Birthday' ), $totals['extra_lines'] );
	$region = $manager->get_field_definition( $region_id );
	$ok( 'your Region field kept its choices', array( 'North', 'South' ) === $region['options'], $region['options'] );

	echo "\nCSV again\n";
	$defs_before = count( $manager->get_field_definitions( 'member' ) );
	list( $mapping2, $options2, $totals2 ) = $run_csv();
	$ok( 'no new field', count( $manager->get_field_definitions( 'member' ) ) === $defs_before );
	$ok( 'same fields reused', $mapping2 === $mapping, array( $mapping, $mapping2 ) );
	$ok( 'no new contact, four updated', 0 === (int) $totals2['imported'] && 4 === (int) $totals2['updated'], $totals2 );
	$ok( 'every value unchanged', 0 === (int) $totals2['extra']['written'] && 11 === (int) $totals2['extra']['unchanged'], $totals2['extra'] );
} else {
	$ok( 'every custom field column dropped', array( 0 => 'email', 1 => 'first_name', 2 => '', 3 => '', 4 => '', 5 => '', 6 => '' ) === $mapping, $mapping );
	$ok( 'no counts', ! isset( $totals['extra'] ), $totals );
	$ok( 'no custom field rows written', ! $has_cf_table || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cf_table}" ) === $cf_rows_before );
}
wp_delete_file( $path );

// =====================================================================
echo "\nMailPoet\n";
// =====================================================================
$mp_ok = class_exists( '\MailPoet\API\API' ) && npmp_mailpoet_has_table( 'custom_fields' );
$ok( 'MailPoet is active with its tables', $mp_ok );
$mp_fields = array();
$mp_subs   = array();
if ( $mp_ok ) {
	$api = \MailPoet\API\API::MP( 'v1' );
	foreach ( array(
		'date'   => array( 'name' => 'Rig MP Birthday', 'type' => 'date', 'params' => array( 'date_type' => 'year_month_day', 'date_format' => 'MM/DD/YYYY' ) ),
		'month'  => array( 'name' => 'Rig MP Month', 'type' => 'date', 'params' => array( 'date_type' => 'year_month', 'date_format' => 'MM/YYYY' ) ),
		'check'  => array( 'name' => 'Rig MP Volunteer', 'type' => 'checkbox', 'params' => array( 'values' => array( array( 'value' => 'Yes, I volunteer' ) ) ) ),
		'select' => array( 'name' => 'Rig MP Color', 'type' => 'select', 'params' => array( 'values' => array( array( 'value' => 'Red' ), array( 'value' => 'Blue' ) ) ) ),
		'region' => array( 'name' => 'Rig MP Region', 'type' => 'radio', 'params' => array( 'values' => array( array( 'value' => 'North' ), array( 'value' => 'South' ) ) ) ),
		'number' => array( 'name' => 'Rig MP Age', 'type' => 'text', 'params' => array( 'validate' => 'number' ) ),
		'area'   => array( 'name' => 'Rig MP Story', 'type' => 'textarea', 'params' => array() ),
	) as $key => $def ) {
		$made              = $api->addSubscriberField( $def );
		$mp_fields[ $key ] = (int) preg_replace( '/\D/', '', (string) $made['id'] ); // The API answers cf_<id>.
	}
	$cf = function ( $key ) use ( $mp_fields ) {
		return 'cf_' . $mp_fields[ $key ];
	};
	foreach ( array(
		'amy' => array( $cf( 'date' ) => '07/04/1990', $cf( 'month' ) => '03/2021', $cf( 'check' ) => '1', $cf( 'select' ) => 'Blue', $cf( 'region' ) => 'North', $cf( 'number' ) => '42', $cf( 'area' ) => "One\nTwo" ),
		'bo'  => array( $cf( 'select' ) => 'Red', $cf( 'region' ) => 'South' ),
		'cy'  => array(),
	) as $who => $values ) {
		$sub             = $api->addSubscriber( array_merge( array( 'email' => "{$who}@{$domain}", 'first_name' => ucfirst( $who ), 'status' => 'subscribed' ), $values ), array(), array( 'send_confirmation_email' => false, 'schedule_welcome_email' => false ) );
		$mp_subs[ $who ] = (int) $sub['id'];
	}
	// Old data MailPoet's API would refuse today: a choice its field doesn't
	// list and a date nobody can read.
	$link = npmp_mailpoet_table( 'subscriber_custom_field' );
	$wpdb->insert( $link, array( 'subscriber_id' => $mp_subs['cy'], 'custom_field_id' => $mp_fields['region'], 'value' => 'East' ) );
	$wpdb->insert( $link, array( 'subscriber_id' => $mp_subs['cy'], 'custom_field_id' => $mp_fields['date'], 'value' => 'whenever' ) );
	$wpdb->insert( $link, array( 'subscriber_id' => $mp_subs['cy'], 'custom_field_id' => $mp_fields['select'], 'value' => 'Green' ) );

	$offered = npmp_mailpoet_extra_fields();
	$k       = function ( $key ) use ( $mp_fields ) {
		return 'mp_' . $mp_fields[ $key ];
	};
	$ok( 'seven MailPoet fields offered', 7 === count( array_intersect_key( $offered, array_flip( array_map( $k, array_keys( $mp_fields ) ) ) ) ), array_keys( $offered ) );
	$types = array();
	foreach ( $mp_fields as $key => $id ) {
		$types[ $key ] = $offered[ $k( $key ) ]['type'];
	}
	$ok( 'MailPoet types mapped', array( 'date' => 'date', 'month' => 'text', 'check' => 'checkbox', 'select' => 'dropdown', 'region' => 'dropdown', 'number' => 'number', 'area' => 'textarea' ) === $types, $types );
	$ok( 'select keeps its choices', array( 'Red', 'Blue' ) === $offered[ $k( 'select' ) ]['options'], $offered[ $k( 'select' ) ] );
	$ok( 'samples come from the table', in_array( 'Blue', $offered[ $k( 'select' ) ]['samples'], true ), $offered[ $k( 'select' ) ]['samples'] );

	ob_start();
	do_action( 'npmp_import_extra_fields_choices', 'mailpoet' );
	$html = ob_get_clean();
	$ok( 'review screen lists the fields', false !== strpos( $html, 'Rig MP Birthday' ) && false !== strpos( $html, 'New field: Rig MP Birthday (Date)' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
	$ok( 'nothing ticked before you choose', false === strpos( $html, 'checked' ) );
	if ( ! $pro ) {
		$ok( 'without Pro the boxes are locked', substr_count( $html, 'disabled' ) >= 14 && false !== strpos( $html, 'import_custom_fields' ) );
	}

	// The owner ticks everything but Story, and sends Region to your own field.
	$_POST['npmp_extra_on']     = array( $k( 'date' ), $k( 'month' ), $k( 'check' ), $k( 'select' ), $k( 'region' ), $k( 'number' ) );
	$_POST['npmp_extra_target'] = array( $k( 'region' ) => $region_id ? $region_id : 'new' );
	do_action( 'npmp_import_extra_fields_save', 'mailpoet' );
	unset( $_POST['npmp_extra_on'], $_POST['npmp_extra_target'] );
	$plan = get_option( 'npmp_import_extra_plan_mailpoet' );
	if ( $pro ) {
		$ok( 'saving makes no field yet', ! $field_by_label( 'Rig MP Birthday' ) );
		$ok( 'plan holds six picks', 6 === count( $plan['plan'] ) && array( 'field' => $region_id ) === $plan['plan'][ $k( 'region' ) ], $plan );
	} else {
		$ok( 'without Pro no plan is saved', false === $plan, $plan );
	}

	$run_mp = function () {
		$guard = 0;
		do {
			$r = npmp_mailpoet_stage_batch( 0 === $guard, 50 );
			++$guard;
		} while ( empty( $r['done'] ) && $guard < 20 );
		npmp_mailpoet_set_review( npmp_mailpoet_status_keys(), array() );
		npmp_mailpoet_approve( array() );
		$guard = 0;
		do {
			$r = npmp_mailpoet_migrate_batch( 50 );
			++$guard;
		} while ( empty( $r['done'] ) && $guard < 20 );
		// The staged counts are written at shutdown, so read this request's.
		return npmp_import_extra_request_counts( null, true );
	};
	$counts = $run_mp();
	$ok( 'three MailPoet contacts here', $values_for( "amy@{$domain}" ) !== null && $values_for( "bo@{$domain}" ) !== null && $values_for( "cy@{$domain}" ) !== null );
	if ( $pro ) {
		$mbd   = $field_by_label( 'Rig MP Birthday' );
		$mmon  = $field_by_label( 'Rig MP Month' );
		$mchk  = $field_by_label( 'Rig MP Volunteer' );
		$mcol  = $field_by_label( 'Rig MP Color' );
		$mage  = $field_by_label( 'Rig MP Age' );
		$story = $field_by_label( 'Rig MP Story' );
		$ok( 'five fields made, Region went to yours, Story stayed behind', $mbd && $mmon && $mchk && $mcol && $mage && ! $story && ! $field_by_label( 'Rig MP Region' ) );
		$amy = $values_for( "amy@{$domain}" );
		$bo  = $values_for( "bo@{$domain}" );
		$cy  = $values_for( "cy@{$domain}" );
		$ok( 'Amy: every value', '1990-07-04' === ( $amy[ $mbd['id'] ] ?? '' ) && '03/2021' === ( $amy[ $mmon['id'] ] ?? '' ) && '1' === ( $amy[ $mchk['id'] ] ?? '' ) && 'Blue' === ( $amy[ $mcol['id'] ] ?? '' ) && 'North' === ( $amy[ $region_id ] ?? '' ) && '42' === ( $amy[ $mage['id'] ] ?? '' ), $amy );
		$ok( 'Bo: Red and South', 'Red' === ( $bo[ $mcol['id'] ] ?? '' ) && 'South' === ( $bo[ $region_id ] ?? '' ), $bo );
		$ok( 'Cy: Green joins the new Color field, East and the bad date skipped', 'Green' === ( $cy[ $mcol['id'] ] ?? '' ) && ! isset( $cy[ $region_id ] ) && ! isset( $cy[ $mbd['id'] ] ), $cy );
		$ok( 'Color took Green as a choice', array( 'Red', 'Blue', 'Green' ) === $mcol['options'], $mcol['options'] );
		$want = array( $mbd['id'] => 1, $region_id => 1 );
		$got  = $counts['skipped'];
		ksort( $want );
		ksort( $got );
		$ok( 'skips counted', $want === $got, $counts );
		$ok( 'MailPoet values counted', 9 === (int) $counts['written'], $counts );

		echo "\nMailPoet again\n";
		$defs_before = count( $manager->get_field_definitions( 'member' ) );
		$contacts    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" );
		$counts2     = $run_mp();
		$ok( 'no new field on a rerun', count( $manager->get_field_definitions( 'member' ) ) === $defs_before );
		$ok( 'no new contact on a rerun', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) === $contacts );
		$ok( 'nothing new written', 0 === (int) $counts2['written'] && 9 === (int) $counts2['unchanged'], $counts2 );
	} else {
		$ok( 'without Pro nothing is written', 0 === (int) $counts['written'] && ( ! $has_cf_table || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cf_table}" ) === $cf_rows_before ), $counts );
	}
}
$ok( 'no email sent', 0 === $mails, $mails );

// =====================================================================
echo "\nCleanup\n";
// =====================================================================
$new_contacts = array_diff( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) ), $contacts_before );
foreach ( $new_contacts as $id ) {
	wp_delete_post( $id, true );
}
if ( $mp_subs ) {
	$in = implode( ',', array_map( 'intval', $mp_subs ) );
	$wpdb->query( "DELETE FROM " . npmp_mailpoet_table( 'subscriber_custom_field' ) . " WHERE subscriber_id IN ($in)" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM " . npmp_mailpoet_table( 'subscriber_segment' ) . " WHERE subscriber_id IN ($in)" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM " . npmp_mailpoet_table( 'subscribers' ) . " WHERE id IN ($in)" ); // phpcs:ignore
}
if ( $mp_fields ) {
	$in = implode( ',', array_map( 'intval', $mp_fields ) );
	$wpdb->query( "DELETE FROM " . npmp_mailpoet_table( 'custom_fields' ) . " WHERE id IN ($in)" ); // phpcs:ignore
}
if ( $manager ) {
	$keep = wp_list_pluck( (array) $saved_options['npmp_custom_field_definitions'], 'id' );
	foreach ( $manager->get_field_definitions() as $field ) {
		if ( ! in_array( $field['id'], $keep, true ) ) {
			$manager->delete_field_definition( $field['id'] );
		}
	}
}
foreach ( $saved_options as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
$ok( 'contacts back to before', array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) ) == $contacts_before ); // phpcs:ignore Universal.Operators.StrictComparisons
$ok( 'custom field rows back to before', ! $has_cf_table || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cf_table}" ) === $cf_rows_before );

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
