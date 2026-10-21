<?php
/**
 * File path: includes/npmp-org-type.php
 *
 * Kind of group the site runs (nonprofit, club, school group, team, faith
 * community, neighborhood or HOA) and the words each one sees.
 *
 * The owner picks the kind in the setup wizard or General Settings and it is
 * stored in one option, npmp_org_type. npmp_org_labels() is the single map
 * from that choice to the words on screen ("Donors" or "Supporters",
 * "Donations" or "Contributions", "Membership Levels" or "Dues Levels").
 * Screens ask for a word with npmp_label( 'donors' ) and so on.
 *
 * Only the words people read change. Database tables, option keys, post
 * types, capabilities, hooks and REST routes keep their nonprofit names.
 *
 * Call npmp_label() from init or later, never at file load, so translations
 * are loaded first.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

const NPMP_ORG_TYPE_OPTION  = 'npmp_org_type';
const NPMP_ORG_TYPE_DEFAULT = 'nonprofit';

/**
 * The kinds of group an owner can pick, in the order the wizard lists them.
 *
 * @return array slug => label.
 */
function npmp_org_types() {
	return array(
		'nonprofit' => __( 'Nonprofit', 'nonprofit-manager' ),
		'club'      => __( 'Club or association', 'nonprofit-manager' ),
		'school'    => __( 'School group (PTO, PTA, booster club)', 'nonprofit-manager' ),
		'sports'    => __( 'Sports team or league', 'nonprofit-manager' ),
		'faith'     => __( 'Faith community', 'nonprofit-manager' ),
		'hoa'       => __( 'Neighborhood or HOA', 'nonprofit-manager' ),
	);
}

/**
 * Normalize a posted or stored kind to a known slug.
 *
 * Anything unknown falls back to nonprofit, which shows the labels every
 * site had before this setting existed.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function npmp_sanitize_org_type( $value ) {
	$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
	return in_array( $value, array( 'nonprofit', 'club', 'school', 'sports', 'faith', 'hoa' ), true ) ? $value : NPMP_ORG_TYPE_DEFAULT;
}

/**
 * The kind of group this site runs.
 *
 * @return string One of the npmp_org_types() slugs.
 */
function npmp_get_org_type() {
	return npmp_sanitize_org_type( get_option( NPMP_ORG_TYPE_OPTION, NPMP_ORG_TYPE_DEFAULT ) );
}

/**
 * Save the kind of group.
 *
 * @param mixed $value Raw value.
 * @return string The slug saved.
 */
function npmp_set_org_type( $value ) {
	$type = npmp_sanitize_org_type( $value );
	update_option( NPMP_ORG_TYPE_OPTION, $type );
	return $type;
}

/**
 * Every word that changes with the kind of group.
 *
 * Keys:
 *   app_name     Admin menu and screen title ("Nonprofit Manager").
 *   org, org_lc, orgs_lc   The group itself, for headings and sentences.
 *   donors, donor, donors_lc            People who give.
 *   donations, donation, donations_lc, donation_lc   What they give.
 *   members, member, members_lc, member_lc   People who belong.
 *   levels, level, levels_lc, level_lc       The npmp_membership_levels list.
 *
 * The nonprofit column matches the words the plugin used before this map
 * existed. Each string is a full __() call so translators see every word.
 *
 * @param string|null $type A npmp_org_types() slug. Null reads the site's.
 * @return array
 */
function npmp_org_labels( $type = null ) {
	$type = null === $type ? npmp_get_org_type() : npmp_sanitize_org_type( $type );

	switch ( $type ) {
		case 'club':
			$labels = array(
				'app_name'     => __( 'Club Manager', 'nonprofit-manager' ),
				'org'          => __( 'Club', 'nonprofit-manager' ),
				'org_lc'       => __( 'club', 'nonprofit-manager' ),
				'orgs_lc'      => __( 'clubs', 'nonprofit-manager' ),
				'donors'       => __( 'Supporters', 'nonprofit-manager' ),
				'donor'        => __( 'Supporter', 'nonprofit-manager' ),
				'donors_lc'    => __( 'supporters', 'nonprofit-manager' ),
				'donations'    => __( 'Contributions', 'nonprofit-manager' ),
				'donation'     => __( 'Contribution', 'nonprofit-manager' ),
				'donations_lc' => __( 'contributions', 'nonprofit-manager' ),
				'donation_lc'  => __( 'contribution', 'nonprofit-manager' ),
				'levels'       => __( 'Dues Levels', 'nonprofit-manager' ),
				'level'        => __( 'Dues Level', 'nonprofit-manager' ),
				'level_lc'     => __( 'dues level', 'nonprofit-manager' ),
				'levels_lc'    => __( 'dues levels', 'nonprofit-manager' ),
			);
			break;

		case 'school':
			$labels = array(
				'app_name'     => __( 'Group Manager', 'nonprofit-manager' ),
				'org'          => __( 'Group', 'nonprofit-manager' ),
				'org_lc'       => __( 'group', 'nonprofit-manager' ),
				'orgs_lc'      => __( 'groups', 'nonprofit-manager' ),
				'donors'       => __( 'Supporters', 'nonprofit-manager' ),
				'donor'        => __( 'Supporter', 'nonprofit-manager' ),
				'donors_lc'    => __( 'supporters', 'nonprofit-manager' ),
				'donations'    => __( 'Donations', 'nonprofit-manager' ),
				'donation'     => __( 'Donation', 'nonprofit-manager' ),
				'donations_lc' => __( 'donations', 'nonprofit-manager' ),
				'donation_lc'  => __( 'donation', 'nonprofit-manager' ),
				'levels'       => __( 'Membership Levels', 'nonprofit-manager' ),
				'level'        => __( 'Membership Level', 'nonprofit-manager' ),
				'level_lc'     => __( 'membership level', 'nonprofit-manager' ),
				'levels_lc'    => __( 'membership levels', 'nonprofit-manager' ),
			);
			break;

		case 'sports':
			$labels = array(
				'app_name'     => __( 'Team Manager', 'nonprofit-manager' ),
				'org'          => __( 'Team', 'nonprofit-manager' ),
				'org_lc'       => __( 'team', 'nonprofit-manager' ),
				'orgs_lc'      => __( 'teams', 'nonprofit-manager' ),
				'donors'       => __( 'Supporters', 'nonprofit-manager' ),
				'donor'        => __( 'Supporter', 'nonprofit-manager' ),
				'donors_lc'    => __( 'supporters', 'nonprofit-manager' ),
				'donations'    => __( 'Contributions', 'nonprofit-manager' ),
				'donation'     => __( 'Contribution', 'nonprofit-manager' ),
				'donations_lc' => __( 'contributions', 'nonprofit-manager' ),
				'donation_lc'  => __( 'contribution', 'nonprofit-manager' ),
				'levels'       => __( 'Dues Levels', 'nonprofit-manager' ),
				'level'        => __( 'Dues Level', 'nonprofit-manager' ),
				'level_lc'     => __( 'dues level', 'nonprofit-manager' ),
				'levels_lc'    => __( 'dues levels', 'nonprofit-manager' ),
			);
			break;

		case 'faith':
			$labels = array(
				'app_name'     => __( 'Congregation Manager', 'nonprofit-manager' ),
				'org'          => __( 'Congregation', 'nonprofit-manager' ),
				'org_lc'       => __( 'congregation', 'nonprofit-manager' ),
				'orgs_lc'      => __( 'congregations', 'nonprofit-manager' ),
				'donors'       => __( 'Givers', 'nonprofit-manager' ),
				'donor'        => __( 'Giver', 'nonprofit-manager' ),
				'donors_lc'    => __( 'givers', 'nonprofit-manager' ),
				'donations'    => __( 'Gifts', 'nonprofit-manager' ),
				'donation'     => __( 'Gift', 'nonprofit-manager' ),
				'donations_lc' => __( 'gifts', 'nonprofit-manager' ),
				'donation_lc'  => __( 'gift', 'nonprofit-manager' ),
				'levels'       => __( 'Membership Levels', 'nonprofit-manager' ),
				'level'        => __( 'Membership Level', 'nonprofit-manager' ),
				'level_lc'     => __( 'membership level', 'nonprofit-manager' ),
				'levels_lc'    => __( 'membership levels', 'nonprofit-manager' ),
			);
			break;

		case 'hoa':
			$labels = array(
				'app_name'     => __( 'Association Manager', 'nonprofit-manager' ),
				'org'          => __( 'Association', 'nonprofit-manager' ),
				'org_lc'       => __( 'association', 'nonprofit-manager' ),
				'orgs_lc'      => __( 'associations', 'nonprofit-manager' ),
				'donors'       => __( 'Contributors', 'nonprofit-manager' ),
				'donor'        => __( 'Contributor', 'nonprofit-manager' ),
				'donors_lc'    => __( 'contributors', 'nonprofit-manager' ),
				'donations'    => __( 'Contributions', 'nonprofit-manager' ),
				'donation'     => __( 'Contribution', 'nonprofit-manager' ),
				'donations_lc' => __( 'contributions', 'nonprofit-manager' ),
				'donation_lc'  => __( 'contribution', 'nonprofit-manager' ),
				'levels'       => __( 'Dues Levels', 'nonprofit-manager' ),
				'level'        => __( 'Dues Level', 'nonprofit-manager' ),
				'level_lc'     => __( 'dues level', 'nonprofit-manager' ),
				'levels_lc'    => __( 'dues levels', 'nonprofit-manager' ),
			);
			break;

		default:
			$labels = array(
				'app_name'     => __( 'Nonprofit Manager', 'nonprofit-manager' ),
				'org'          => __( 'Nonprofit', 'nonprofit-manager' ),
				'org_lc'       => __( 'nonprofit', 'nonprofit-manager' ),
				'orgs_lc'      => __( 'nonprofits', 'nonprofit-manager' ),
				'donors'       => __( 'Donors', 'nonprofit-manager' ),
				'donor'        => __( 'Donor', 'nonprofit-manager' ),
				'donors_lc'    => __( 'donors', 'nonprofit-manager' ),
				'donations'    => __( 'Donations', 'nonprofit-manager' ),
				'donation'     => __( 'Donation', 'nonprofit-manager' ),
				'donations_lc' => __( 'donations', 'nonprofit-manager' ),
				'donation_lc'  => __( 'donation', 'nonprofit-manager' ),
				'levels'       => __( 'Membership Levels', 'nonprofit-manager' ),
				'level'        => __( 'Membership Level', 'nonprofit-manager' ),
				'level_lc'     => __( 'membership level', 'nonprofit-manager' ),
				'levels_lc'    => __( 'membership levels', 'nonprofit-manager' ),
			);
			break;
	}

	// Every kind of group calls the people who belong "Members". The keys
	// stay in the map so a kind can change them later in one place.
	$labels['members']    = __( 'Members', 'nonprofit-manager' );
	$labels['member']     = __( 'Member', 'nonprofit-manager' );
	$labels['members_lc'] = __( 'members', 'nonprofit-manager' );
	$labels['member_lc']  = __( 'member', 'nonprofit-manager' );

	/**
	 * Filter the words shown for a kind of group.
	 *
	 * @param array  $labels Key => word, see npmp_org_labels().
	 * @param string $type   The npmp_org_types() slug.
	 */
	if ( function_exists( 'apply_filters' ) ) {
		$filtered = apply_filters( 'npmp_org_labels', $labels, $type );
		if ( is_array( $filtered ) ) {
			$labels = array_merge( $labels, array_map( 'strval', $filtered ) );
		}
	}

	return $labels;
}

/**
 * One word from the label map for this site's kind of group.
 *
 * @param string $key A npmp_org_labels() key, e.g. 'donors'.
 * @return string The word, or the key itself if the map has no such entry.
 */
function npmp_label( $key ) {
	$labels = npmp_org_labels();
	return isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key;
}

/**
 * Keep a top-level menu's screen IDs fixed when its title changes.
 *
 * WordPress names every submenu screen after the parent's menu title
 * (sanitize_title() of it), so "Club Manager" or a translated "Membership"
 * would turn nonprofit-manager_page_npmp_general_settings into
 * club-manager_page_npmp_general_settings and break every hook and screen
 * check keyed on the old ID. Call right after add_menu_page().
 *
 * @param string $menu_slug Top-level menu slug.
 * @param string $hook_base The ID prefix the plugin has always used.
 * @return void
 */
function npmp_pin_menu_hook( $menu_slug, $hook_base ) {
	global $admin_page_hooks;
	if ( is_array( $admin_page_hooks ) ) {
		$admin_page_hooks[ $menu_slug ] = $hook_base; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Pinning our own menu's hook name, the way WooCommerce does.
	}
}
