<?php
/**
 * File path: includes/npmp-post-type-labels.php
 *
 * Full, translated labels for the plugin's post types.
 *
 * WordPress fills any label a post type leaves out with the word "Post", so a
 * new newsletter used to say "Add Post" and "Post published." Each string is
 * its own __() call so translators see whole phrases. The wording follows
 * core 7.1 ("Add Event", not "Add New Event"). tests/test-post-type-labels.php
 * checks every key for every type.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The label keys every plugin post type sets.
 *
 * @return string[]
 */
function npmp_post_type_label_keys() {
	return array(
		'name',
		'singular_name',
		'menu_name',
		'add_new',
		'add_new_item',
		'edit_item',
		'new_item',
		'view_item',
		'view_items',
		'search_items',
		'not_found',
		'not_found_in_trash',
		'all_items',
		'item_published',
		'item_published_privately',
		'item_reverted_to_draft',
		'item_scheduled',
		'item_updated',
		'item_link',
		'item_link_description',
	);
}

/**
 * Labels for one of the plugin's post types.
 *
 * @param string $post_type npmp_newsletter, npmp_nl_template, npmp_event, or npmp_email_body.
 * @return array
 */
function npmp_post_type_labels( $post_type ) {
	switch ( $post_type ) {
		case 'npmp_newsletter':
			return array(
				'name'                     => __( 'Newsletters', 'nonprofit-manager' ),
				'singular_name'            => __( 'Newsletter', 'nonprofit-manager' ),
				'menu_name'                => __( 'Newsletters', 'nonprofit-manager' ),
				'add_new'                  => __( 'Add Newsletter', 'nonprofit-manager' ),
				'add_new_item'             => __( 'Add Newsletter', 'nonprofit-manager' ),
				'edit_item'                => __( 'Edit Newsletter', 'nonprofit-manager' ),
				'new_item'                 => __( 'New Newsletter', 'nonprofit-manager' ),
				'view_item'                => __( 'View Newsletter', 'nonprofit-manager' ),
				'view_items'               => __( 'View Newsletters', 'nonprofit-manager' ),
				'search_items'             => __( 'Search Newsletters', 'nonprofit-manager' ),
				'not_found'                => __( 'No newsletters found.', 'nonprofit-manager' ),
				'not_found_in_trash'       => __( 'No newsletters found in Trash.', 'nonprofit-manager' ),
				'all_items'                => __( 'All Newsletters', 'nonprofit-manager' ),
				'item_published'           => __( 'Newsletter published.', 'nonprofit-manager' ),
				'item_published_privately' => __( 'Newsletter published privately.', 'nonprofit-manager' ),
				'item_reverted_to_draft'   => __( 'Newsletter reverted to draft.', 'nonprofit-manager' ),
				'item_scheduled'           => __( 'Newsletter scheduled.', 'nonprofit-manager' ),
				'item_updated'             => __( 'Newsletter updated.', 'nonprofit-manager' ),
				'item_link'                => __( 'Newsletter Link', 'nonprofit-manager' ),
				'item_link_description'    => __( 'A link to a newsletter.', 'nonprofit-manager' ),
			);

		case 'npmp_nl_template':
			return array(
				'name'                     => __( 'Newsletter Templates', 'nonprofit-manager' ),
				'singular_name'            => __( 'Newsletter Template', 'nonprofit-manager' ),
				'menu_name'                => __( 'Newsletter Templates', 'nonprofit-manager' ),
				'add_new'                  => __( 'Add Template', 'nonprofit-manager' ),
				'add_new_item'             => __( 'Add Template', 'nonprofit-manager' ),
				'edit_item'                => __( 'Edit Template', 'nonprofit-manager' ),
				'new_item'                 => __( 'New Template', 'nonprofit-manager' ),
				'view_item'                => __( 'View Template', 'nonprofit-manager' ),
				'view_items'               => __( 'View Templates', 'nonprofit-manager' ),
				'search_items'             => __( 'Search Templates', 'nonprofit-manager' ),
				'not_found'                => __( 'No templates found.', 'nonprofit-manager' ),
				'not_found_in_trash'       => __( 'No templates found in Trash.', 'nonprofit-manager' ),
				'all_items'                => __( 'All Templates', 'nonprofit-manager' ),
				'item_published'           => __( 'Template saved.', 'nonprofit-manager' ),
				'item_published_privately' => __( 'Template saved privately.', 'nonprofit-manager' ),
				'item_reverted_to_draft'   => __( 'Template reverted to draft.', 'nonprofit-manager' ),
				'item_scheduled'           => __( 'Template scheduled.', 'nonprofit-manager' ),
				'item_updated'             => __( 'Template updated.', 'nonprofit-manager' ),
				'item_link'                => __( 'Template Link', 'nonprofit-manager' ),
				'item_link_description'    => __( 'A link to a newsletter template.', 'nonprofit-manager' ),
			);

		case 'npmp_event':
			return array(
				'name'                     => __( 'Events', 'nonprofit-manager' ),
				'singular_name'            => __( 'Event', 'nonprofit-manager' ),
				'menu_name'                => __( 'Events', 'nonprofit-manager' ),
				'add_new'                  => __( 'Add Event', 'nonprofit-manager' ),
				'add_new_item'             => __( 'Add Event', 'nonprofit-manager' ),
				'edit_item'                => __( 'Edit Event', 'nonprofit-manager' ),
				'new_item'                 => __( 'New Event', 'nonprofit-manager' ),
				'view_item'                => __( 'View Event', 'nonprofit-manager' ),
				'view_items'               => __( 'View Events', 'nonprofit-manager' ),
				'search_items'             => __( 'Search Events', 'nonprofit-manager' ),
				'not_found'                => __( 'No events found.', 'nonprofit-manager' ),
				'not_found_in_trash'       => __( 'No events found in Trash.', 'nonprofit-manager' ),
				'all_items'                => __( 'All Events', 'nonprofit-manager' ),
				'archives'                 => __( 'Event Archives', 'nonprofit-manager' ),
				'attributes'               => __( 'Event Attributes', 'nonprofit-manager' ),
				'insert_into_item'         => __( 'Insert into event', 'nonprofit-manager' ),
				'uploaded_to_this_item'    => __( 'Uploaded to this event', 'nonprofit-manager' ),
				'item_published'           => __( 'Event published.', 'nonprofit-manager' ),
				'item_published_privately' => __( 'Event published privately.', 'nonprofit-manager' ),
				'item_reverted_to_draft'   => __( 'Event reverted to draft.', 'nonprofit-manager' ),
				'item_scheduled'           => __( 'Event scheduled.', 'nonprofit-manager' ),
				'item_updated'             => __( 'Event updated.', 'nonprofit-manager' ),
				'item_link'                => __( 'Event Link', 'nonprofit-manager' ),
				'item_link_description'    => __( 'A link to an event.', 'nonprofit-manager' ),
			);

		case 'npmp_email_body':
			return array(
				'name'                     => __( 'Scheduled Email Bodies', 'nonprofit-manager' ),
				'singular_name'            => __( 'Scheduled Email Body', 'nonprofit-manager' ),
				'menu_name'                => __( 'Scheduled Email Bodies', 'nonprofit-manager' ),
				'add_new'                  => __( 'Add Email Body', 'nonprofit-manager' ),
				'add_new_item'             => __( 'Write a Scheduled Email Body', 'nonprofit-manager' ),
				'edit_item'                => __( 'Edit Scheduled Email Body', 'nonprofit-manager' ),
				'new_item'                 => __( 'New Scheduled Email Body', 'nonprofit-manager' ),
				'view_item'                => __( 'View Scheduled Email Body', 'nonprofit-manager' ),
				'view_items'               => __( 'View Scheduled Email Bodies', 'nonprofit-manager' ),
				'search_items'             => __( 'Search Scheduled Email Bodies', 'nonprofit-manager' ),
				'not_found'                => __( 'No scheduled email bodies yet.', 'nonprofit-manager' ),
				'not_found_in_trash'       => __( 'No scheduled email bodies found in Trash.', 'nonprofit-manager' ),
				'all_items'                => __( 'All Scheduled Email Bodies', 'nonprofit-manager' ),
				'item_published'           => __( 'Email body saved.', 'nonprofit-manager' ),
				'item_published_privately' => __( 'Email body saved privately.', 'nonprofit-manager' ),
				'item_reverted_to_draft'   => __( 'Email body reverted to draft.', 'nonprofit-manager' ),
				'item_scheduled'           => __( 'Email body scheduled.', 'nonprofit-manager' ),
				'item_updated'             => __( 'Email body updated.', 'nonprofit-manager' ),
				'item_link'                => __( 'Email Body Link', 'nonprofit-manager' ),
				'item_link_description'    => __( 'A link to a scheduled email body.', 'nonprofit-manager' ),
			);
	}
	return array();
}
