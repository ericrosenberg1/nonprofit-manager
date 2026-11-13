<?php
/**
 * Public newsletter archive: the [npmp_newsletters] shortcode, the
 * Newsletters block, the "Show in the public archive" box on each
 * newsletter, and the public page for one newsletter.
 *
 * Nothing goes public by itself. A newsletter shows up only when it finished
 * sending and someone who can send newsletters ticked its box (meta
 * `_npmp_newsletter_public`). Every newsletter starts unticked, the ones sent
 * before this file existed included. Event emails built by the scheduled
 * emails sweep stay out of lists unless the list asks for them with
 * event_emails="yes", and each still needs its own box ticked.
 *
 * The public page renders the shared part of the email
 * (NPMP_Newsletter_Manager::render_base_content()) and never the
 * per-recipient part: [first_name]-style tags get their defaults, and there's
 * no footer, unsubscribe link, open pixel, or tracked link.
 * npmp_newsletter_archive_scrub() then strips any token a pasted link
 * carried. Pages answer at /newsletter/<id>/<slug>/ (or ?npmp_newsletter=<id>
 * on plain permalinks) inside the theme's page template, carry noindex unless
 * the owner turns on indexing under Newsletter Settings, and answer 404 for
 * anything not listable. A theme whose template never prints the content
 * gets the standalone page instead (npmp_newsletter_archive_template_redirect()).
 *
 * Groups (Pro): group="..." needs a handler on
 * `npmp_newsletter_archive_groups_supported` and
 * `npmp_newsletter_archive_query_args`. Without one, a list that asks for a
 * group shows nothing, so a group page never lists every public newsletter.
 *
 * Pure parts and their tests: archive-pure.php, tests/test-newsletter-archive.php.
 * Real WordPress: tests/rig/newsletter-archive.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------
 * Which newsletters are public
 * ------------------------------------------------------------------ */

/**
 * Whether a newsletter has a public page.
 *
 * @param WP_Post|int|null $post Newsletter.
 * @return bool
 */
function npmp_newsletter_archive_is_public( $post ) {
	$post = get_post( $post );
	if ( ! $post || 'npmp_newsletter' !== $post->post_type ) {
		return false;
	}
	return npmp_newsletter_archive_listable(
		$post->post_status,
		(string) get_post_meta( $post->ID, '_npmp_newsletter_status', true ),
		get_post_meta( $post->ID, NPMP_NEWSLETTER_PUBLIC_META, true )
	);
}

/**
 * Tag values for a reader we don't know: every tag empty, so
 * [first_name default="Friend"] becomes "Friend" and [first_name] nothing.
 * Pro's tags come through the same filter a send uses, with no contact.
 *
 * @return array<string,string>
 */
function npmp_newsletter_archive_tag_values() {
	$values = function_exists( 'npmp_email_recipient_tag_values' ) ? npmp_email_recipient_tag_values( '' ) : array(
		'first_name' => '',
		'last_name'  => '',
		'name'       => '',
	);
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Plugin's own filter (prefixed npmp_).
	$values = (array) apply_filters( 'npmp_email_recipient_tags', $values, null, '' );
	// Whatever a filter put in, nobody's value reaches a public page.
	return array_fill_keys( array_keys( $values ), '' );
}

/**
 * Fill recipient tags with their defaults.
 *
 * @param string $text Text.
 * @param bool   $html True for HTML.
 * @return string
 */
function npmp_newsletter_archive_fill_tags( $text, $html ) {
	if ( ! function_exists( 'npmp_email_fill_recipient_tags' ) ) {
		return (string) $text;
	}
	return npmp_email_fill_recipient_tags( (string) $text, npmp_newsletter_archive_tag_values(), $html );
}

/**
 * A newsletter's subject for a page, tags filled, as plain text.
 *
 * @param int $id Newsletter ID.
 * @return string
 */
function npmp_newsletter_archive_title( $id ) {
	$post  = get_post( $id );
	$title = $post ? (string) $post->post_title : '';
	$title = npmp_newsletter_archive_fill_tags( $title, false );
	$title = trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	return '' !== $title ? $title : __( 'Newsletter', 'nonprofit-manager' );
}

/**
 * When a newsletter went out (site-local MySQL time), '' if never.
 *
 * @param int $id Newsletter ID.
 * @return string
 */
function npmp_newsletter_archive_sent_at( $id ) {
	return (string) get_post_meta( $id, '_npmp_newsletter_queued_at', true );
}

/* ---------------------------------------------------------------------
 * Public page
 * ------------------------------------------------------------------ */

/**
 * The rewrite regex for the public page.
 *
 * @return string
 */
function npmp_newsletter_archive_rewrite_regex() {
	return '^newsletter/([0-9]+)(?:/[^/]+)?/?$';
}

/**
 * Register the public page's route and its query var.
 *
 * @return void
 */
function npmp_newsletter_archive_rewrite() {
	add_rewrite_rule( npmp_newsletter_archive_rewrite_regex(), 'index.php?npmp_newsletter=$matches[1]', 'top' );
}
add_action( 'init', 'npmp_newsletter_archive_rewrite' );

/**
 * Add the query var.
 *
 * @param string[] $vars Query vars.
 * @return string[]
 */
function npmp_newsletter_archive_query_vars( $vars ) {
	$vars[] = 'npmp_newsletter';
	return $vars;
}
add_filter( 'query_vars', 'npmp_newsletter_archive_query_vars' );

/**
 * Rebuild the rewrite rules once when ours is missing (first load after an
 * update, or after something else rebuilt them with newsletters off).
 *
 * @return void
 */
function npmp_newsletter_archive_maybe_flush() {
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		return;
	}
	$rules = get_option( 'rewrite_rules' );
	if ( is_array( $rules ) && ! isset( $rules[ npmp_newsletter_archive_rewrite_regex() ] ) ) {
		flush_rewrite_rules( false );
	}
}
add_action( 'init', 'npmp_newsletter_archive_maybe_flush', 99 );

/**
 * The public page's URL.
 *
 * @param int $id   Newsletter ID.
 * @param int $from Page the visitor came from, for the back link (0 for none).
 * @return string
 */
function npmp_newsletter_archive_url( $id, $from = 0 ) {
	$id = (int) $id;
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		$url = add_query_arg( 'npmp_newsletter', $id, home_url( '/' ) );
	} else {
		$url = home_url( '/' . npmp_newsletter_archive_path( $id, sanitize_title( npmp_newsletter_archive_title( $id ) ) ) );
	}
	if ( $from ) {
		$url = add_query_arg( 'npmp_from', (int) $from, $url );
	}
	return $url;
}

/**
 * Whether public pages may be indexed by search engines (off by default).
 *
 * @return bool
 */
function npmp_newsletter_archive_indexable() {
	/**
	 * Filter whether search engines may index public newsletter pages.
	 *
	 * @param bool $indexable The Newsletter Settings checkbox, off by default.
	 */
	return (bool) apply_filters( 'npmp_newsletter_archive_indexable', (bool) get_option( 'npmp_newsletter_archive_index', false ) );
}

/**
 * The newsletter body for the web: the shared part of the email with tag
 * defaults, the preheader dropped, and every recipient trace scrubbed.
 *
 * @param int $id Newsletter ID.
 * @return string
 */
function npmp_newsletter_archive_body( $id ) {
	if ( ! class_exists( 'NPMP_Newsletter_Manager' ) || ! method_exists( 'NPMP_Newsletter_Manager', 'render_base_content' ) ) {
		return '';
	}
	$html = (string) NPMP_Newsletter_Manager::render_base_content( (int) $id );
	// The preheader is hidden inbox text, so it has no place on a page.
	$html = (string) preg_replace( '#^<span style="display:none;[^"]*">.*?</span>#s', '', $html );
	$html = npmp_newsletter_archive_fill_tags( $html, true );
	return npmp_newsletter_archive_scrub( $html );
}

/**
 * The back link: the page the visitor came from when it's a public page,
 * otherwise the home page.
 *
 * @return array { url: string, label: string }
 */
function npmp_newsletter_archive_back_link() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only link target, checked below.
	$from = isset( $_GET['npmp_from'] ) ? absint( $_GET['npmp_from'] ) : 0;
	if ( $from && 'publish' === get_post_status( $from ) && is_post_publicly_viewable( $from ) && empty( get_post( $from )->post_password ) ) {
		return array(
			'url'   => (string) get_permalink( $from ),
			/* translators: %s: title of the page that lists the newsletters. */
			'label' => sprintf( __( 'Back to %s', 'nonprofit-manager' ), get_the_title( $from ) ),
		);
	}
	return array(
		'url'   => home_url( '/' ),
		/* translators: %s: site name. */
		'label' => sprintf( __( 'Back to %s', 'nonprofit-manager' ), get_bloginfo( 'name' ) ),
	);
}

/**
 * The whole public page for one newsletter.
 *
 * @param int $id Newsletter ID.
 * @return string
 */
function npmp_newsletter_archive_page_html( $id ) {
	$title     = npmp_newsletter_archive_title( $id );
	$sent_at   = npmp_newsletter_archive_sent_at( $id );
	$back      = npmp_newsletter_archive_back_link();
	$robots    = npmp_newsletter_archive_indexable() ? 'index, follow' : 'noindex, follow';
	$canonical = npmp_newsletter_archive_url( $id );

	$out  = '<!DOCTYPE html><html ' . get_language_attributes() . '><head>';
	$out .= '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
	$out .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
	$out .= '<meta name="robots" content="' . esc_attr( $robots ) . '">';
	$out .= '<title>' . esc_html( $title . ' | ' . get_bloginfo( 'name' ) ) . '</title>';
	$out .= '<link rel="canonical" href="' . esc_url( $canonical ) . '">';
	$out .= '<style>' . npmp_newsletter_archive_page_css() . '</style>';
	$out .= '</head><body class="npmp-newsletter-view">';
	$out .= '<header class="npmp-nlv-bar"><a href="' . esc_url( $back['url'] ) . '">&larr; ' . esc_html( $back['label'] ) . '</a></header>';
	$out .= '<main class="npmp-nlv-main"><article class="npmp-nlv-article">';
	$out .= '<h1 class="npmp-nlv-title">' . esc_html( $title ) . '</h1>'; // A front-end h1: the public page, not an admin screen.
	if ( '' !== $sent_at ) {
		$out .= '<p class="npmp-nlv-date"><time datetime="' . esc_attr( mysql2date( 'Y-m-d', $sent_at ) ) . '">' . esc_html(
			sprintf(
				/* translators: %s: date the newsletter went out. */
				__( 'Sent %s', 'nonprofit-manager' ),
				mysql2date( get_option( 'date_format' ), $sent_at )
			)
		) . '</time></p>';
	}
	$out .= '<div class="npmp-nlv-body">' . npmp_newsletter_archive_body( $id ) . '</div>';
	$out .= '</article></main></body></html>';
	return $out;
}

/**
 * Styles for the public page. The email keeps its own inline styles, so this
 * only frames it, in the plugin's accent.
 *
 * @return string
 */
function npmp_newsletter_archive_page_css() {
	return 'body.npmp-newsletter-view{margin:0;background:#f6f7f7;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.6}'
		. '.npmp-nlv-bar{max-width:680px;margin:0 auto;padding:16px}'
		. '.npmp-nlv-bar a{color:#0f766e;font-weight:600;text-decoration:none}'
		. '.npmp-nlv-bar a:hover,.npmp-nlv-bar a:focus{color:#115e59;text-decoration:underline}'
		. '.npmp-nlv-main{max-width:680px;margin:0 auto 32px;padding:0 16px}'
		. '.npmp-nlv-article{background:#fff;border:1px solid #dcdfe3;border-top:4px solid #0f766e;border-radius:8px;padding:24px 16px;overflow-wrap:anywhere}'
		. '.npmp-nlv-title{margin:0 0 4px;font-size:1.75rem;line-height:1.25;color:#111827}'
		. '.npmp-nlv-date{margin:0 0 20px;color:#0f766e;font-weight:600;white-space:nowrap}'
		. '.npmp-nlv-body a{color:#0f766e}'
		. '.npmp-nlv-body a:hover,.npmp-nlv-body a:focus{color:#115e59}'
		. '.npmp-nlv-body img{max-width:100%;height:auto}'
		. '.npmp-nlv-body table{max-width:100%}'
		. '@media (min-width:600px){.npmp-nlv-article{padding:32px}}';
}

/**
 * The page content inside the theme: the back link, the send date, and the
 * scrubbed body.
 *
 * @param int $id Newsletter ID.
 * @return string
 */
function npmp_newsletter_archive_theme_content( $id ) {
	$sent_at = npmp_newsletter_archive_sent_at( $id );
	$back    = npmp_newsletter_archive_back_link();

	$out  = '<div class="npmp-newsletter-view">';
	$out .= '<p class="npmp-nlv-back"><a href="' . esc_url( $back['url'] ) . '">&larr; ' . esc_html( $back['label'] ) . '</a></p>';
	if ( '' !== $sent_at ) {
		$out .= '<p class="npmp-nlv-date"><time datetime="' . esc_attr( mysql2date( 'Y-m-d', $sent_at ) ) . '">' . esc_html(
			sprintf(
				/* translators: %s: date the newsletter went out. */
				__( 'Sent %s', 'nonprofit-manager' ),
				mysql2date( get_option( 'date_format' ), $sent_at )
			)
		) . '</time></p>';
	}
	$out .= '<div class="npmp-nlv-body">' . npmp_newsletter_archive_body( $id ) . '</div>';
	$out .= '</div>';
	return $out;
}

/**
 * The newsletter the theme is showing on this request, and its finished
 * content. Empty outside a public newsletter page.
 *
 * @param array|null $set { id: int, html: string, printed: bool } to store, or null to read.
 * @return array
 */
function npmp_newsletter_archive_view( $set = null ) {
	static $view = array();
	if ( is_array( $set ) ) {
		$view = $set;
	}
	return $view;
}

/**
 * Whether the theme should host the page. On by default. Return false from
 * the `npmp_newsletter_archive_use_theme` filter to serve the standalone page.
 *
 * @param int $id Newsletter ID.
 * @return bool
 */
function npmp_newsletter_archive_use_theme( $id ) {
	/**
	 * Filter whether a public newsletter shows inside the theme.
	 *
	 * @param bool $use_theme True to use the theme's page template.
	 * @param int  $id        Newsletter ID.
	 */
	return (bool) apply_filters( 'npmp_newsletter_archive_use_theme', true, (int) $id );
}

/**
 * Answer the public page, or a 404 for anything that isn't public.
 *
 * Inside the theme, the main query becomes one page whose ID is the
 * newsletter's and whose content is a marker. The finished content (built
 * here, before the query changes, so the body passes the_content once, as
 * the email's did) replaces whatever the_content made of the marker, so
 * comments, share buttons, related posts, and the plugin's own page
 * injections never reach the page. If the theme's template never prints the
 * content, the standalone page goes out instead.
 *
 * @return void
 */
function npmp_newsletter_archive_template_redirect() {
	$id = absint( get_query_var( 'npmp_newsletter' ) );
	if ( ! $id ) {
		return;
	}
	global $wp_query;
	if ( ! npmp_newsletter_archive_is_public( $id ) ) {
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		// Otherwise core may guess a post from the slug and 301 to it.
		remove_action( 'template_redirect', 'redirect_canonical' );
		return;
	}
	status_header( 200 );
	if ( ! npmp_newsletter_archive_indexable() && ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, follow' );
	}

	if ( ! npmp_newsletter_archive_use_theme( $id ) ) {
		echo npmp_newsletter_archive_page_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts and the scrubbed newsletter body.
		exit;
	}

	$html = npmp_newsletter_archive_theme_content( $id );
	npmp_newsletter_archive_view(
		array(
			'id'      => $id,
			'html'    => $html,
			'printed' => false,
		)
	);

	$source = get_post( $id );
	$sent   = npmp_newsletter_archive_sent_at( $id );
	$date   = '' !== $sent ? $sent : $source->post_date;
	$page   = new WP_Post(
		(object) array(
			'ID'                    => $id,
			'post_author'           => 0,
			'post_date'             => $date,
			'post_date_gmt'         => get_gmt_from_date( $date ),
			'post_content'          => '<!-- npmp-newsletter-view -->',
			'post_title'            => npmp_newsletter_archive_title( $id ),
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			// Not the title's slug, so a theme's page-{slug} template never catches it.
			'post_name'             => 'newsletter-' . $id,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $date,
			'post_modified_gmt'     => get_gmt_from_date( $date ),
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => npmp_newsletter_archive_url( $id ),
			'menu_order'            => 0,
			'post_type'             => 'page',
			'post_mime_type'        => '',
			'comment_count'         => 0,
			'filter'                => 'raw',
		)
	);

	$wp_query->init_query_flags();
	$wp_query->posts             = array( $page );
	$wp_query->post              = $page;
	$wp_query->post_count        = 1;
	$wp_query->found_posts       = 1;
	$wp_query->max_num_pages     = 1;
	$wp_query->current_post      = -1;
	$wp_query->is_page           = true;
	$wp_query->is_singular       = true;
	$wp_query->queried_object    = $page;
	$wp_query->queried_object_id = $id;
	$GLOBALS['post']             = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The page the theme renders.

	// Core would 301 a page whose permalink differs from the request, print
	// a canonical, shortlink, and REST link for the stored newsletter, and
	// ask for comments. None of that applies to this page.
	remove_action( 'template_redirect', 'redirect_canonical' );
	remove_action( 'wp_head', 'rel_canonical' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
	remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
	remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );

	add_filter( 'the_content', 'npmp_newsletter_archive_filter_content', PHP_INT_MAX );
	add_filter( 'the_title', 'npmp_newsletter_archive_filter_title', 10, 2 );
	add_filter( 'comments_open', 'npmp_newsletter_archive_filter_closed', PHP_INT_MAX, 2 );
	add_filter( 'pings_open', 'npmp_newsletter_archive_filter_closed', PHP_INT_MAX, 2 );
	add_filter( 'comments_array', 'npmp_newsletter_archive_filter_no_comments', PHP_INT_MAX, 2 );
	add_filter( 'get_comments_number', 'npmp_newsletter_archive_filter_no_count', PHP_INT_MAX, 2 );
	add_filter( 'post_type_link', 'npmp_newsletter_archive_filter_link', 10, 2 );
	add_filter( 'page_link', 'npmp_newsletter_archive_filter_link', 10, 2 );
	add_filter( 'wp_robots', 'npmp_newsletter_archive_filter_robots', PHP_INT_MAX );
	add_filter( 'template_include', 'npmp_newsletter_archive_template_include', PHP_INT_MAX );
	add_action( 'wp_head', 'npmp_newsletter_archive_canonical' );
	add_action( 'wp_enqueue_scripts', 'npmp_newsletter_archive_enqueue_view' );
}
add_action( 'template_redirect', 'npmp_newsletter_archive_template_redirect', 1 );

/**
 * Whether an ID is the newsletter on this page.
 *
 * @param mixed $id Post ID or post.
 * @return bool
 */
function npmp_newsletter_archive_is_view_id( $id ) {
	$view = npmp_newsletter_archive_view();
	if ( empty( $view['id'] ) ) {
		return false;
	}
	if ( $id instanceof WP_Post ) {
		$id = $id->ID;
	}
	return (int) $id === (int) $view['id'];
}

/**
 * Put the finished content in place of what the_content made of the marker.
 * Only the page itself is replaced, never another post's content.
 *
 * @param string $content Filtered content.
 * @return string
 */
function npmp_newsletter_archive_filter_content( $content ) {
	$post = get_post();
	if ( ! $post || 'page' !== $post->post_type || ! npmp_newsletter_archive_is_view_id( $post ) ) {
		return $content;
	}
	$view            = npmp_newsletter_archive_view();
	$view['printed'] = true;
	npmp_newsletter_archive_view( $view );
	return $view['html'];
}

/**
 * The newsletter's title, tags filled, wherever the theme asks for it by ID.
 *
 * @param string $title Title.
 * @param int    $id    Post ID.
 * @return string
 */
function npmp_newsletter_archive_filter_title( $title, $id = 0 ) {
	return npmp_newsletter_archive_is_view_id( $id ) ? esc_html( npmp_newsletter_archive_title( (int) $id ) ) : $title;
}

/**
 * No comments or pings on a newsletter page.
 *
 * @param bool  $open Open.
 * @param mixed $id   Post ID.
 * @return bool
 */
function npmp_newsletter_archive_filter_closed( $open, $id = 0 ) {
	return npmp_newsletter_archive_is_view_id( $id ) ? false : $open;
}

/**
 * No comments listed on a newsletter page.
 *
 * @param array $comments Comments.
 * @param int   $id       Post ID.
 * @return array
 */
function npmp_newsletter_archive_filter_no_comments( $comments, $id = 0 ) {
	return npmp_newsletter_archive_is_view_id( $id ) ? array() : $comments;
}

/**
 * A comment count of zero on a newsletter page.
 *
 * @param int $count Count.
 * @param int $id    Post ID.
 * @return int
 */
function npmp_newsletter_archive_filter_no_count( $count, $id = 0 ) {
	return npmp_newsletter_archive_is_view_id( $id ) ? 0 : $count;
}

/**
 * The public URL wherever the theme links the page.
 *
 * @param string      $link Link.
 * @param WP_Post|int $post Post.
 * @return string
 */
function npmp_newsletter_archive_filter_link( $link, $post ) {
	return npmp_newsletter_archive_is_view_id( $post ) ? npmp_newsletter_archive_url( npmp_newsletter_archive_view()['id'] ) : $link;
}

/**
 * Robots meta through core's wp_robots: noindex unless the owner allows it.
 *
 * @param array $robots Directives.
 * @return array
 */
function npmp_newsletter_archive_filter_robots( $robots ) {
	if ( npmp_newsletter_archive_indexable() ) {
		return $robots;
	}
	unset( $robots['index'] );
	$robots['noindex'] = true;
	$robots['follow']  = true;
	return $robots;
}

/**
 * The canonical link for the page.
 *
 * @return void
 */
function npmp_newsletter_archive_canonical() {
	$view = npmp_newsletter_archive_view();
	if ( ! empty( $view['id'] ) ) {
		echo '<link rel="canonical" href="' . esc_url( npmp_newsletter_archive_url( $view['id'] ) ) . '" />' . "\n";
	}
}

/**
 * The page's stylesheet.
 *
 * @return void
 */
function npmp_newsletter_archive_enqueue_view() {
	npmp_newsletter_archive_register_style();
	wp_enqueue_style( 'npmp-newsletter-archive' );
}

/**
 * Run the theme's template inside archive-theme-template.php, which falls
 * back to the standalone page when the template never prints the content.
 *
 * @param string $template Template path.
 * @return string
 */
function npmp_newsletter_archive_template_include( $template ) {
	$view = npmp_newsletter_archive_view();
	if ( empty( $view['id'] ) || ! is_string( $template ) || '' === $template ) {
		return $template;
	}
	$view['template'] = $template;
	npmp_newsletter_archive_view( $view );
	return __DIR__ . '/archive-theme-template.php';
}

/**
 * Print the buffered theme page, or the standalone page when the theme's
 * template never printed the newsletter.
 *
 * @param string $html The theme's page.
 * @return void
 */
function npmp_newsletter_archive_finish( $html ) {
	$view = npmp_newsletter_archive_view();
	if ( ! empty( $view['printed'] ) ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The theme's own page.
		return;
	}
	if ( ! headers_sent() ) {
		header( 'X-NPMP-Newsletter-View: standalone' );
	}
	echo npmp_newsletter_archive_page_html( (int) $view['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts and the scrubbed newsletter body.
}

/* ---------------------------------------------------------------------
 * The list
 * ------------------------------------------------------------------ */

/**
 * [npmp_newsletters] attributes. Add-ons extend them through
 * `npmp_newsletters_shortcode_defaults`.
 *
 * @return array
 */
function npmp_newsletters_shortcode_defaults() {
	$defaults = array(
		'limit'          => 10,
		'topic'          => '',
		// Needs Pro. Without it a list that names a group shows nothing.
		'group'          => '',
		'order'          => '',
		'layout'         => '',
		'columns'        => '',
		'date'           => '',
		'excerpt'        => '',
		'excerpt_length' => '',
		'readmore'       => '',
		'pagination'     => '',
		'event_emails'   => '',
	);
	/**
	 * Filter the attributes [npmp_newsletters] and the Newsletters block accept.
	 *
	 * @param array $defaults Attribute defaults.
	 */
	$filtered = apply_filters( 'npmp_newsletters_shortcode_defaults', $defaults );
	return array_merge( $defaults, is_array( $filtered ) ? $filtered : array() );
}

/**
 * WP_Query arguments for one page of the list.
 *
 * @param array $atts     Attributes after defaults.
 * @param array $settings Resolved settings.
 * @param int   $paged    Page number.
 * @return array|null Null when the list must stay empty (a group without Pro).
 */
function npmp_newsletter_archive_query_args( $atts, $settings, $paged = 1 ) {
	$meta_query = array(
		'relation' => 'AND',
		array(
			'key'   => NPMP_NEWSLETTER_PUBLIC_META,
			'value' => '1',
		),
		array(
			'key'   => '_npmp_newsletter_status',
			'value' => 'sent',
		),
		'sent_at' => array(
			'key'     => '_npmp_newsletter_queued_at',
			'compare' => 'EXISTS',
		),
	);
	if ( ! $settings['event_emails'] ) {
		$meta_query[] = array(
			'key'     => '_npmp_newsletter_scheduled_event',
			'compare' => 'NOT EXISTS',
		);
	}

	$args = array(
		'post_type'           => 'npmp_newsletter',
		'post_status'         => npmp_newsletter_archive_post_statuses(),
		'posts_per_page'      => (int) $settings['limit'],
		'paged'               => max( 1, (int) $paged ),
		'no_found_rows'       => ! $settings['pagination'],
		'ignore_sticky_posts' => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The public flag and send status live in post meta.
		'meta_query'          => $meta_query,
		'orderby'             => array(
			'sent_at' => 'asc' === $settings['order'] ? 'ASC' : 'DESC',
			'ID'      => 'asc' === $settings['order'] ? 'ASC' : 'DESC',
		),
	);

	$topic = isset( $atts['topic'] ) ? trim( (string) $atts['topic'] ) : '';
	if ( '' !== $topic ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Topics are a taxonomy.
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'npmp_newsletter_topic',
				'field'    => 'slug',
				'terms'    => array_map( 'sanitize_title', array_filter( array_map( 'trim', explode( ',', $topic ) ) ) ),
			),
		);
	}

	$group = isset( $atts['group'] ) ? trim( (string) $atts['group'] ) : '';
	/**
	 * Whether something handles group="..." on the newsletter list. Pro
	 * returns true. Without it a list that names a group stays empty.
	 *
	 * @param bool $supported False in free.
	 */
	if ( '' !== $group && ! apply_filters( 'npmp_newsletter_archive_groups_supported', false ) ) {
		return null;
	}

	/**
	 * Filter the WP_Query arguments of a public newsletter list. Pro narrows
	 * to the newsletters sent to the groups in `$context['atts']['group']`.
	 * Never widen past the public flag and the sent status.
	 *
	 * @param array $args    WP_Query arguments.
	 * @param array $context { atts: array, settings: array }.
	 */
	$filtered = apply_filters(
		'npmp_newsletter_archive_query_args',
		$args,
		array(
			'atts'     => $atts,
			'settings' => $settings,
		)
	);
	if ( ! is_array( $filtered ) ) {
		return $args;
	}
	// The public flag and the send status always stay.
	$filtered['meta_query']  = $args['meta_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Same clause as above.
	$filtered['post_type']   = 'npmp_newsletter';
	$filtered['post_status'] = npmp_newsletter_archive_post_statuses();
	return $filtered;
}

/**
 * Register the list's stylesheet.
 *
 * @return string Handle.
 */
function npmp_newsletter_archive_register_style() {
	$handle = 'npmp-newsletter-archive';
	if ( ! wp_style_is( $handle, 'registered' ) ) {
		$rel = 'assets/css/npmp-newsletter-archive.css';
		wp_register_style(
			$handle,
			plugins_url( $rel, dirname( __DIR__, 2 ) . '/nonprofit-manager.php' ),
			array(),
			function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( $rel ) : null
		);
	}
	return $handle;
}

/**
 * Render the list.
 *
 * @param array $atts      Attributes after defaults.
 * @param bool  $for_block True from the block.
 * @return string
 */
function npmp_newsletter_archive_render( $atts, $for_block = false ) {
	$atts      = (array) $atts;
	$settings  = npmp_newsletter_archive_settings( $atts );
	$in_editor = defined( 'REST_REQUEST' ) && REST_REQUEST;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page number.
	$paged = ( $settings['pagination'] && isset( $_GET['npmp_nl_page'] ) ) ? max( 1, absint( $_GET['npmp_nl_page'] ) ) : 1;
	$args  = npmp_newsletter_archive_query_args( $atts, $settings, $paged );
	$query = null === $args ? null : new WP_Query( $args );

	wp_enqueue_style( npmp_newsletter_archive_register_style() );

	if ( ! $query || ! $query->have_posts() ) {
		// Visitors see nothing. Editors get a hint in the block preview.
		if ( $in_editor ) {
			return '<p class="npmp-newsletters-empty">' . esc_html__( 'No public newsletters yet. Open a sent newsletter and tick "Show in the public archive" to list the newsletter here.', 'nonprofit-manager' ) . '</p>';
		}
		return '';
	}

	$classes = array(
		'npmp-newsletters',
		'npmp-newsletters--' . $settings['layout'],
		'npmp-newsletters--cols-' . (int) $settings['columns'],
	);
	if ( $for_block && ! $in_editor && function_exists( 'get_block_wrapper_attributes' ) ) {
		$root = get_block_wrapper_attributes( array( 'class' => implode( ' ', $classes ) ) );
	} else {
		$root = 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
	}

	$from = (int) get_queried_object_id();
	$out  = '<div ' . $root . '><div class="npmp-newsletters-items">';

	foreach ( $query->posts as $post ) {
		$title = npmp_newsletter_archive_title( $post->ID );
		$url   = npmp_newsletter_archive_url( $post->ID, $from );
		$sent  = npmp_newsletter_archive_sent_at( $post->ID );

		$out .= '<article class="npmp-newsletter-item">';
		$out .= '<h3 class="npmp-newsletter-title"><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h3>';
		if ( $settings['date'] && '' !== $sent ) {
			$out .= '<p class="npmp-newsletter-date"><time datetime="' . esc_attr( mysql2date( 'Y-m-d', $sent ) ) . '">' . esc_html( mysql2date( get_option( 'date_format' ), $sent ) ) . '</time></p>';
		}
		if ( $settings['excerpt'] ) {
			$text = npmp_newsletter_archive_text( npmp_newsletter_archive_fill_tags( $post->post_content, false ) );
			$text = npmp_newsletter_archive_trim_words( $text, $settings['excerpt_length'] );
			/**
			 * Filter a newsletter's excerpt in a public list.
			 *
			 * @param string  $text Plain text.
			 * @param WP_Post $post Newsletter.
			 */
			$text = (string) apply_filters( 'npmp_newsletter_archive_excerpt', $text, $post );
			if ( '' !== $text ) {
				$out .= '<div class="npmp-newsletter-excerpt"><p>' . esc_html( $text ) . '</p></div>';
			}
		}
		if ( $settings['readmore'] ) {
			$out .= '<p class="npmp-newsletter-more"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Read the newsletter', 'nonprofit-manager' ) . '<span class="screen-reader-text"> ' . esc_html( $title ) . '</span></a></p>';
		}
		$out .= '</article>';
	}
	$out .= '</div>';

	if ( $settings['pagination'] ) {
		$pager = npmp_newsletter_archive_pager( (int) $query->found_posts, (int) $settings['limit'], $paged );
		if ( $pager['prev'] || $pager['next'] ) {
			$newest_first = 'asc' !== $settings['order'];
			$out         .= '<nav class="npmp-newsletters-pager" aria-label="' . esc_attr__( 'More newsletters', 'nonprofit-manager' ) . '">';
			if ( $pager['prev'] ) {
				$label = $newest_first ? __( 'Newer newsletters', 'nonprofit-manager' ) : __( 'Older newsletters', 'nonprofit-manager' );
				$link  = 1 === $pager['prev'] ? remove_query_arg( 'npmp_nl_page' ) : add_query_arg( 'npmp_nl_page', $pager['prev'] );
				$out  .= '<a class="npmp-newsletters-prev" href="' . esc_url( $link ) . '">&larr; ' . esc_html( $label ) . '</a>';
			}
			if ( $pager['next'] ) {
				$label = $newest_first ? __( 'Older newsletters', 'nonprofit-manager' ) : __( 'Newer newsletters', 'nonprofit-manager' );
				$out  .= '<a class="npmp-newsletters-next" href="' . esc_url( add_query_arg( 'npmp_nl_page', $pager['next'] ) ) . '">' . esc_html( $label ) . ' &rarr;</a>';
			}
			$out .= '</nav>';
		}
	}

	return $out . '</div>';
}

/**
 * [npmp_newsletters] handler.
 *
 * @param array|string $atts Attributes.
 * @return string
 */
function npmp_newsletters_shortcode( $atts ) {
	$atts = shortcode_atts( npmp_newsletters_shortcode_defaults(), $atts, 'npmp_newsletters' );
	return npmp_newsletter_archive_render( $atts );
}
add_shortcode( 'npmp_newsletters', 'npmp_newsletters_shortcode' );

/* ---------------------------------------------------------------------
 * Block
 * ------------------------------------------------------------------ */

/**
 * Register the Newsletters block.
 *
 * @return void
 */
function npmp_newsletter_archive_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	$plugin_file = dirname( __DIR__, 2 ) . '/nonprofit-manager.php';
	$script_rel  = 'assets/js/npmp-newsletter-archive-block.js';
	wp_register_script(
		'npmp-newsletter-archive-block',
		plugins_url( $script_rel, $plugin_file ),
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
		function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( $script_rel ) : null,
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'npmp-newsletter-archive-block', 'nonprofit-manager' );
	}

	register_block_type(
		'nonprofit-manager/newsletters',
		array(
			'editor_script'   => 'npmp-newsletter-archive-block',
			'style'           => npmp_newsletter_archive_register_style(),
			'render_callback' => 'npmp_newsletter_archive_block_render',
			'supports'        => array(
				'html'       => false,
				'align'      => array( 'wide', 'full' ),
				'anchor'     => true,
				'color'      => array(
					'background' => true,
					'text'       => true,
					'link'       => true,
				),
				'typography' => array(
					'fontSize'   => true,
					'lineHeight' => true,
				),
				'spacing'    => array(
					'margin'  => true,
					'padding' => true,
				),
			),
			'attributes'      => array(
				'limit'         => array(
					'type'    => 'number',
					'default' => 10,
				),
				'topic'         => array(
					'type'    => 'string',
					'default' => '',
				),
				'order'         => array(
					'type'    => 'string',
					'default' => '',
				),
				'layout'        => array(
					'type'    => 'string',
					'default' => '',
				),
				'columns'       => array(
					'type'    => 'number',
					'default' => 0,
				),
				'date'          => array(
					'type'    => 'string',
					'default' => '',
				),
				'excerpt'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'excerptLength' => array(
					'type'    => 'number',
					'default' => 0,
				),
				'readmore'      => array(
					'type'    => 'string',
					'default' => '',
				),
				'pagination'    => array(
					'type'    => 'string',
					'default' => '',
				),
				'eventEmails'   => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);
}
add_action( 'init', 'npmp_newsletter_archive_register_block' );

/**
 * Render the block through the same code as the shortcode.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function npmp_newsletter_archive_block_render( $attributes ) {
	$attributes = (array) $attributes;
	$limit      = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 10;
	$atts       = array(
		'limit'        => $limit < 1 ? -1 : min( 100, $limit ),
		'topic'        => ! empty( $attributes['topic'] ) ? sanitize_text_field( (string) $attributes['topic'] ) : '',
		'event_emails' => empty( $attributes['eventEmails'] ) ? 'no' : 'yes',
	);
	foreach ( array( 'order', 'layout', 'columns', 'date', 'excerpt', 'readmore', 'pagination' ) as $key ) {
		if ( isset( $attributes[ $key ] ) && '' !== $attributes[ $key ] && 0 !== $attributes[ $key ] ) {
			$atts[ $key ] = $attributes[ $key ];
		}
	}
	if ( ! empty( $attributes['excerptLength'] ) ) {
		$atts['excerpt_length'] = (int) $attributes['excerptLength'];
	}

	/**
	 * Filter the attributes the Newsletters block hands to the list. Pro
	 * registers a `group` block attribute and copies it across here.
	 *
	 * @param array $atts       Attributes for npmp_newsletter_archive_render().
	 * @param array $attributes The block's own attributes.
	 */
	$atts = (array) apply_filters( 'npmp_newsletters_block_atts', $atts, $attributes );
	$atts = array_merge( npmp_newsletters_shortcode_defaults(), $atts );

	return npmp_newsletter_archive_render( $atts, true );
}

/* ---------------------------------------------------------------------
 * The checkbox on each newsletter
 * ------------------------------------------------------------------ */

/**
 * Add the "Public archive" box to the newsletter editor's sidebar.
 *
 * @return void
 */
function npmp_newsletter_archive_meta_box() {
	add_meta_box(
		'npmp_newsletter_public_archive',
		__( 'Public archive', 'nonprofit-manager' ),
		'npmp_newsletter_archive_meta_box_html',
		'npmp_newsletter',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes_npmp_newsletter', 'npmp_newsletter_archive_meta_box' );

/**
 * Whether the newsletter goes to fewer people than everyone subscribed.
 *
 * @param int $id Newsletter ID.
 * @return bool
 */
function npmp_newsletter_archive_is_narrow( $id ) {
	$levels = get_post_meta( $id, '_npmp_newsletter_levels', true );
	$levels = is_array( $levels ) ? array_diff( $levels, array( '__all__', '' ) ) : array();
	$groups = get_post_meta( $id, '_npmp_newsletter_groups', true );
	return ! empty( $levels ) || ! empty( $groups );
}

/**
 * The box.
 *
 * @param WP_Post $post Newsletter.
 * @return void
 */
function npmp_newsletter_archive_meta_box_html( $post ) {
	$checked  = '1' === (string) get_post_meta( $post->ID, NPMP_NEWSLETTER_PUBLIC_META, true );
	$can      = function_exists( 'npmp_newsletter_send_capability' ) ? current_user_can( npmp_newsletter_send_capability() ) : current_user_can( 'edit_others_posts' );
	$sent     = 'sent' === (string) get_post_meta( $post->ID, '_npmp_newsletter_status', true );

	wp_nonce_field( 'npmp_newsletter_public_' . $post->ID, 'npmp_newsletter_public_nonce' );
	echo '<p><label><input type="checkbox" name="npmp_newsletter_public" value="1"' . checked( $checked, true, false ) . disabled( $can, false, false ) . '> ' . esc_html__( 'Show in the public archive', 'nonprofit-manager' ) . '</label></p>';
	echo '<p class="description">' . esc_html__( 'Anyone can read the newsletter on your site, including people who never got the email. We leave names, email addresses, and unsubscribe links out of the public copy.', 'nonprofit-manager' ) . '</p>';
	if ( npmp_newsletter_archive_is_narrow( $post->ID ) ) {
		echo '<p class="description"><strong>' . esc_html(
			sprintf(
				/* translators: %s: who the newsletter goes to, such as "Gold" or a group name. */
				__( 'You send this newsletter only to %s. Leave the box unticked if you wrote anything here just for those readers.', 'nonprofit-manager' ),
				function_exists( 'npmp_newsletter_get_audience_label' ) ? npmp_newsletter_get_audience_label( $post->ID ) : __( 'part of your list', 'nonprofit-manager' )
			)
		) . '</strong></p>';
	}
	if ( ! $can ) {
		echo '<p class="description">' . esc_html__( 'An editor, an administrator, or a Membership Manager can change this setting.', 'nonprofit-manager' ) . '</p>';
	}
	if ( $checked && $sent && npmp_newsletter_archive_is_public( $post ) ) {
		echo '<p><a href="' . esc_url( npmp_newsletter_archive_url( $post->ID ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View the public page', 'nonprofit-manager' ) . '</a></p>';
	} elseif ( $checked ) {
		echo '<p class="description">' . esc_html__( 'You\'ll see the newsletter in the archive once we finish sending the emails.', 'nonprofit-manager' ) . '</p>';
	}
	echo '<p class="description">' . esc_html__( 'List public newsletters on any page with the Newsletters block or [npmp_newsletters].', 'nonprofit-manager' ) . '</p>';
}

/**
 * Save the box. Only when the box's own nonce came with the request, so a
 * save from anywhere else leaves the flag alone.
 *
 * @param int $post_id Newsletter ID.
 * @return void
 */
function npmp_newsletter_archive_save( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['npmp_newsletter_public_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_newsletter_public_nonce'] ) ), 'npmp_newsletter_public_' . $post_id ) ) {
		return;
	}
	$cap = function_exists( 'npmp_newsletter_send_capability' ) ? npmp_newsletter_send_capability() : 'edit_others_posts';
	if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $cap ) ) {
		return;
	}
	if ( ! empty( $_POST['npmp_newsletter_public'] ) ) {
		update_post_meta( $post_id, NPMP_NEWSLETTER_PUBLIC_META, '1' );
	} else {
		delete_post_meta( $post_id, NPMP_NEWSLETTER_PUBLIC_META );
	}
}
add_action( 'save_post_npmp_newsletter', 'npmp_newsletter_archive_save' );

/* ---------------------------------------------------------------------
 * Newsletter Settings section
 * ------------------------------------------------------------------ */

/**
 * Add the archive section to Newsletter Settings.
 *
 * @return void
 */
function npmp_newsletter_archive_settings_init() {
	register_setting(
		'npmp_newsletter_settings',
		'npmp_newsletter_archive_index',
		array(
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		)
	);
	add_settings_section(
		'npmp_newsletter_archive',
		__( 'Public newsletter archive', 'nonprofit-manager' ),
		'npmp_newsletter_archive_settings_intro',
		'npmp_newsletter_settings'
	);
	add_settings_field(
		'npmp_newsletter_archive_index',
		__( 'Search engines', 'nonprofit-manager' ),
		'npmp_newsletter_archive_index_field',
		'npmp_newsletter_settings',
		'npmp_newsletter_archive'
	);
}
add_action( 'admin_init', 'npmp_newsletter_archive_settings_init', 20 );

/**
 * Intro and shortcode help for the section.
 *
 * @return void
 */
function npmp_newsletter_archive_settings_intro() {
	echo '<p>' . esc_html__( 'You can list past newsletters on any page. You\'ll see only sent newsletters with "Show in the public archive" ticked, and we leave that box unticked on every newsletter. Add the Newsletters block, or one of these shortcodes:', 'nonprofit-manager' ) . '</p>';
	$rows = array(
		'[npmp_newsletters]'                            => __( 'The 10 newest public newsletters, with older ones a click away.', 'nonprofit-manager' ),
		'[npmp_newsletters limit="5" pagination="no"]'  => __( 'The five newest, with no links to older ones.', 'nonprofit-manager' ),
		'[npmp_newsletters topic="events"]'             => __( 'Only newsletters with that topic (its slug).', 'nonprofit-manager' ),
		'[npmp_newsletters group="computing"]'          => __( 'Only newsletters sent to that group. Groups need Nonprofit Manager Pro.', 'nonprofit-manager' ),
		'[npmp_newsletters order="asc" layout="cards"]' => __( 'Oldest first, as cards. Layouts are list, grid, and cards.', 'nonprofit-manager' ),
		'[npmp_newsletters excerpt="no" date="no"]'     => __( 'Titles only.', 'nonprofit-manager' ),
		'[npmp_newsletters event_emails="yes"]'         => __( 'Also list event emails you made public.', 'nonprofit-manager' ),
	);
	echo '<table class="widefat striped npmp-settings-narrow"><thead><tr><th scope="col">' . esc_html__( 'Shortcode', 'nonprofit-manager' ) . '</th><th scope="col">' . esc_html__( 'What it shows', 'nonprofit-manager' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $code => $text ) {
		echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $text ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/**
 * The indexing checkbox.
 *
 * @return void
 */
function npmp_newsletter_archive_index_field() {
	echo '<label><input type="checkbox" name="npmp_newsletter_archive_index" value="1"' . checked( (bool) get_option( 'npmp_newsletter_archive_index', false ), true, false ) . '> ' . esc_html__( 'Let search engines index public newsletters', 'nonprofit-manager' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Off by default, so search engines leave public newsletter pages out of their results and readers find each page through your links.', 'nonprofit-manager' ) . '</p>';
}
