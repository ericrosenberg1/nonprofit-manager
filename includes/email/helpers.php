<?php
/**
 * Shared helpers for Nonprofit Manager email handling.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default email settings.
 *
 * @return array
 */
function npmp_email_default_settings() {
	return array(
		'provider'        => 'wordpress',
		'from_email'      => get_option( 'admin_email' ) ?: 'noreply@example.com',
		'from_name'       => get_option( 'blogname' ) ?: 'Nonprofit Manager',
		'force_from'      => 1,
		// Off by default: the From above rewrites only Nonprofit Manager's
		// own mail. On, it rewrites every wp_mail() message on the site.
		'from_all_mail'   => 0,
		'set_return_path' => 1,
		'smtp'            => array(
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'auth'       => 1,
			'username'   => '',
			'password'   => '',
			'auto_tls'   => 1,
		),
		'aws'             => array(
			'region' => 'us-east-1',
			'port'   => 587,
		),
	);
}

/**
 * Retrieve email settings (with migration from legacy options).
 *
 * @return array
 */
function npmp_email_get_settings() {
	$settings = get_option( 'npmp_email_settings' );

	if ( ! is_array( $settings ) ) {
		$settings = npmp_email_default_settings();
		npmp_email_migrate_legacy_settings( $settings );
	} else {
		$defaults           = npmp_email_default_settings();
		$settings           = wp_parse_args( $settings, $defaults );
		$settings['smtp']   = wp_parse_args( $settings['smtp'] ?? array(), $defaults['smtp'] );
		$settings['aws']    = wp_parse_args( $settings['aws'] ?? array(), $defaults['aws'] );

		// Validate provider. SparkPost left in 2026.09.20 (Pro dropped its
		// sender in 2026.05.9), so a saved 'sparkpost' reads as WordPress
		// default mail, which is what those sites were already getting.
		$valid_providers = array( 'wordpress', 'smtp', 'aws_ses', 'brevo', 'sendgrid', 'mailgun', 'postmark' );
		$settings['provider'] = in_array( $settings['provider'] ?? '', $valid_providers, true )
			? $settings['provider']
			: 'wordpress';
	}

	// Ensure all values have proper defaults to prevent null errors
	$settings['from_name']  = ! empty( $settings['from_name'] ) ? $settings['from_name'] : ( get_option( 'blogname' ) ?: 'Nonprofit Manager' );
	$settings['from_email'] = ! empty( $settings['from_email'] ) ? $settings['from_email'] : ( get_option( 'admin_email' ) ?: 'noreply@example.com' );
	$settings['provider']   = ! empty( $settings['provider'] ) ? $settings['provider'] : 'wordpress';

	// Ensure nested arrays have defaults
	if ( ! isset( $settings['smtp'] ) || ! is_array( $settings['smtp'] ) ) {
		$settings['smtp'] = array();
	}
	$smtp_defaults = npmp_email_default_settings()['smtp'];
	foreach ( $smtp_defaults as $key => $default_value ) {
		// Ensure each SMTP value is never null
		if ( ! isset( $settings['smtp'][ $key ] ) || is_null( $settings['smtp'][ $key ] ) ) {
			$settings['smtp'][ $key ] = $default_value;
		}
		// Ensure string values are actually strings, not null
		if ( is_string( $default_value ) && ! is_string( $settings['smtp'][ $key ] ) ) {
			$settings['smtp'][ $key ] = (string) $settings['smtp'][ $key ];
		}
	}

	if ( ! isset( $settings['aws'] ) || ! is_array( $settings['aws'] ) ) {
		$settings['aws'] = array();
	}
	$settings['aws']['region'] = $settings['aws']['region'] ?? 'us-east-1';
	$settings['aws']['port']   = in_array( (int) ( $settings['aws']['port'] ?? 587 ), npmp_email_get_aws_smtp_ports(), true ) ? (int) $settings['aws']['port'] : 587;

	return $settings;
}

/**
 * Persist settings to the database.
 *
 * @param array $settings Sanitised settings.
 * @return void
 */
function npmp_email_update_settings( $settings ) {
	update_option( 'npmp_email_settings', $settings );
}

/**
 * Tracks whether execution is currently inside a Nonprofit Manager-originated
 * wp_mail() call. Backed by a counter rather than a bool so a reentrant
 * wp_mail() call (e.g. a hook fired during a send that itself sends mail)
 * doesn't clear the flag early while the outer send is still in progress.
 *
 * @param int $delta Amount to adjust the depth by (0 to just read it).
 * @return int Current depth.
 */
function npmp_mail_send_depth( $delta = 0 ) {
	static $depth = 0;
	$depth += $delta;
	return $depth;
}

/**
 * Whether the current call stack is inside an npmp_send_mail() call.
 * npmp_email_filter_from_address()/_name() in smtp.php use this so the
 * force_from setting only rewrites mail this plugin sends, not every
 * wp_mail() call on the site (core notifications, other plugins, etc).
 *
 * @return bool
 */
function npmp_is_internal_mail_send() {
	return npmp_mail_send_depth( 0 ) > 0;
}

/**
 * Whether the configured From name and address belong on the message being
 * sent right now: always for Nonprofit Manager's own mail, and for every
 * message on the site only when the admin turned on "Send all site email from
 * this address". Core notices and other plugins' mail keep their own From
 * otherwise, even though the SMTP connection still carries them.
 *
 * @param array|null $settings Email settings, read fresh when omitted.
 * @return bool
 */
function npmp_email_from_applies( $settings = null ) {
	if ( npmp_is_internal_mail_send() ) {
		return true;
	}

	if ( null === $settings ) {
		$settings = npmp_email_get_settings();
	}

	return ! empty( $settings['from_all_mail'] );
}

/**
 * Send mail as Nonprofit Manager. Use this instead of calling wp_mail()
 * directly anywhere in the plugin, so the force_from setting (see
 * includes/email/smtp.php) only ever rewrites the plugin's own mail.
 *
 * @param string|array $to          Recipient(s).
 * @param string       $subject     Subject line.
 * @param string       $message     Message body.
 * @param string|array $headers     Optional headers.
 * @param string|array $attachments Optional attachments.
 * @return bool Whether the email was sent successfully.
 */
function npmp_send_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	npmp_mail_send_depth( 1 );
	npmp_mail_from_name_override( npmp_mail_header_from_name( $headers ) );
	try {
		return wp_mail( $to, $subject, $message, $headers, $attachments );
	} finally {
		npmp_mail_from_name_override( false );
		npmp_mail_send_depth( -1 );
	}
}

/**
 * The sender name the npmp_send_mail() call in progress asked for, or ''.
 *
 * A caller that passes a "From: Art Group <hello@example.org>" header keeps
 * that display name, while the configured From address still applies, so SPF
 * and DKIM keep passing. Pro uses this for each group's sender name, and its
 * API senders read it too. Calls nest, so this keeps a stack.
 *
 * @param string|false|null $set A name to push, false to pop, null to read.
 * @return string
 */
function npmp_mail_from_name_override( $set = null ) {
	static $stack = array();
	if ( null === $set ) {
		return $stack ? (string) end( $stack ) : '';
	}
	if ( false === $set ) {
		array_pop( $stack );
		return '';
	}
	$stack[] = (string) $set;
	return (string) $set;
}

/**
 * The display name in a "From: Name <address>" header, or ''.
 *
 * @param string|array $headers Headers as wp_mail() takes them.
 * @return string
 */
function npmp_mail_header_from_name( $headers ) {
	$lines = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
	foreach ( (array) $lines as $line ) {
		if ( preg_match( '/^\s*From:\s*(.*?)\s*<[^>]+>\s*$/i', (string) $line, $m ) ) {
			$name = trim( preg_replace( '/[\r\n]+/', ' ', strip_tags( $m[1] ) ), " \t\"'" );
			return $name;
		}
	}
	return '';
}

/**
 * Take a send queue's run lock, or say another run holds it.
 *
 * The newsletter and digest queues drain on a per-minute cron. WordPress's
 * own cron lock lasts 60 seconds and a system cron running
 * `wp cron event run --due-now` skips it, so a slow batch could overlap the
 * next tick and both runs read the same pending rows and mailed them twice.
 * INSERT IGNORE on the options table is atomic, so only one run gets the row.
 * A lock older than $ttl seconds belongs to a run that died and is taken over.
 *
 * @param string $name Queue name, e.g. 'newsletter'.
 * @param int    $ttl  Seconds before a held lock counts as stale.
 * @return bool True when this run holds the lock.
 */
function npmp_mail_queue_lock( $name, $ttl = 300 ) {
	global $wpdb;
	$option = 'npmp_queue_lock_' . sanitize_key( $name );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock, must skip the options cache.
	$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $option, (string) time() ) );
	if ( $got ) {
		return true;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the live lock, not a cached copy.
	$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	if ( $since && $since > time() - (int) $ttl ) {
		return false;
	}

	// Stale. Take it over only if nobody else did in the meantime.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the lock row.
	return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $option, (string) $since ) );
}

/**
 * Release a lock taken with npmp_mail_queue_lock().
 *
 * @param string $name Queue name.
 * @return void
 */
function npmp_mail_queue_unlock( $name ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Pairs with the direct insert above.
	$wpdb->delete( $wpdb->options, array( 'option_name' => 'npmp_queue_lock_' . sanitize_key( $name ) ), array( '%s' ) );
	wp_cache_delete( 'npmp_queue_lock_' . sanitize_key( $name ), 'options' );
}

/**
 * Attempt to migrate legacy option values.
 *
 * @param array &$settings Current settings array (passed by reference).
 * @return void
 */
function npmp_email_migrate_legacy_settings( &$settings ) {
	$method     = get_option( 'npmp_email_method', 'wp_mail' );
	$from_email = get_option( 'npmp_email_from_email', get_option( 'admin_email' ) );
	$from_name  = get_option( 'npmp_email_from_name', get_option( 'blogname' ) );

	$settings['from_email'] = $from_email;
	$settings['from_name']  = $from_name;

	if ( 'smtp' === $method ) {
		$settings['provider']          = 'smtp';
		$settings['smtp']['host']      = get_option( 'npmp_smtp_host', '' );
		$settings['smtp']['port']      = (int) get_option( 'npmp_smtp_port', 587 );
		$settings['smtp']['encryption'] = get_option( 'npmp_smtp_encryption', 'tls' );
		$settings['smtp']['auth']      = (int) get_option( 'npmp_smtp_auth', 1 );
		$settings['smtp']['username']  = get_option( 'npmp_smtp_username', '' );
		$settings['smtp']['password']  = get_option( 'npmp_smtp_password', '' );
	}
}

/**
 * Provider choices for the settings select.
 *
 * @param bool $include_pro Whether to include Pro providers.
 * @return array
 */
function npmp_email_get_provider_choices( $include_pro = false ) {
	$choices = array(
		'wordpress' => __( 'WordPress Default', 'nonprofit-manager' ),
		'smtp'      => __( 'Custom SMTP', 'nonprofit-manager' ),
	);

	// Pro providers (API-based integrations) - alphabetized
	if ( npmp_is_pro() || $include_pro ) {
		$pro_providers = array(
			'aws_ses'   => __( 'Amazon SES', 'nonprofit-manager' ),
			'brevo'     => __( 'Brevo (Sendinblue)', 'nonprofit-manager' ),
			'mailgun'   => __( 'Mailgun', 'nonprofit-manager' ),
			'postmark'  => __( 'Postmark', 'nonprofit-manager' ),
			'sendgrid'  => __( 'SendGrid', 'nonprofit-manager' ),
		);

		// Add "(Pro)" suffix for free version users
		if ( ! npmp_is_pro() ) {
			foreach ( $pro_providers as $key => $label ) {
				$pro_providers[ $key ] = $label . ' ' . __( '(Pro)', 'nonprofit-manager' );
			}
		}

		$choices = array_merge( $choices, $pro_providers );
	}

	return $choices;
}

/**
 * Retrieve available AWS regions.
 *
 * @return array
 */
function npmp_email_get_aws_regions() {
	return array(
		'us-east-1'      => __( 'US East (N. Virginia)', 'nonprofit-manager' ),
		'us-east-2'      => __( 'US East (Ohio)', 'nonprofit-manager' ),
		'us-west-1'      => __( 'US West (N. California)', 'nonprofit-manager' ),
		'us-west-2'      => __( 'US West (Oregon)', 'nonprofit-manager' ),
		'ca-central-1'   => __( 'Canada (Central)', 'nonprofit-manager' ),
		'eu-central-1'   => __( 'Europe (Frankfurt)', 'nonprofit-manager' ),
		'eu-west-1'      => __( 'Europe (Ireland)', 'nonprofit-manager' ),
		'eu-west-2'      => __( 'Europe (London)', 'nonprofit-manager' ),
		'eu-west-3'      => __( 'Europe (Paris)', 'nonprofit-manager' ),
		'eu-north-1'     => __( 'Europe (Stockholm)', 'nonprofit-manager' ),
		'ap-south-1'     => __( 'Asia Pacific (Mumbai)', 'nonprofit-manager' ),
		'ap-northeast-1' => __( 'Asia Pacific (Tokyo)', 'nonprofit-manager' ),
		'ap-northeast-2' => __( 'Asia Pacific (Seoul)', 'nonprofit-manager' ),
		'ap-southeast-1' => __( 'Asia Pacific (Singapore)', 'nonprofit-manager' ),
		'ap-southeast-2' => __( 'Asia Pacific (Sydney)', 'nonprofit-manager' ),
		'sa-east-1'      => __( 'South America (Sao Paulo)', 'nonprofit-manager' ),
	);
}

/**
 * Ports Amazon SES accepts SMTP on. 2587 (STARTTLS) and 2465 (TLS wrapper)
 * work on hosts that block 587 and 465, DigitalOcean droplets among them.
 *
 * @return int[]
 */
function npmp_email_get_aws_smtp_ports() {
	return array( 587, 2587, 465, 2465 );
}

/**
 * Turn an IAM secret access key into the Amazon SES SMTP password for a
 * region (AWS's documented SigV4 derivation, version byte 4). The SMTP
 * username is the access key ID itself.
 *
 * @param string $secret_key IAM secret access key.
 * @param string $region     SES region, e.g. us-west-2.
 * @return string Base64 SMTP password, or '' without a key.
 */
function npmp_email_ses_smtp_password( $secret_key, $region ) {
	$secret_key = trim( (string) $secret_key );
	if ( '' === $secret_key ) {
		return '';
	}

	$signature = hash_hmac( 'sha256', '11111111', 'AWS4' . $secret_key, true );
	foreach ( array( (string) $region, 'ses', 'aws4_request', 'SendRawEmail' ) as $part ) {
		$signature = hash_hmac( 'sha256', $part, $signature, true );
	}

	return base64_encode( chr( 4 ) . $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- AWS's documented SMTP password format.
}

/**
 * The SMTP connection details for the selected provider, or null when the
 * provider doesn't send over SMTP (WordPress default, or a Pro API provider).
 *
 * Custom SMTP uses the SMTP settings as entered. Amazon SES builds them from
 * the region, the chosen port and the IAM access key pair, which Pro stores
 * (encrypted). Without Pro, or with a key this site can no longer decrypt,
 * Amazon SES returns null and mail falls back to WordPress's default mailer.
 *
 * @param array $settings Email settings.
 * @return array|null
 */
function npmp_email_get_transport_smtp( $settings ) {
	$provider = $settings['provider'] ?? 'wordpress';

	if ( 'smtp' === $provider ) {
		return $settings['smtp'];
	}

	if ( 'aws_ses' !== $provider || ! npmp_is_pro() ) {
		return null;
	}

	if ( function_exists( 'npmp_pro_secret_option_unreadable' ) && npmp_pro_secret_option_unreadable( 'npmp_aws_ses_secret_key' ) ) {
		return null;
	}

	$region = sanitize_key( $settings['aws']['region'] ?? 'us-east-1' );
	$port   = (int) ( $settings['aws']['port'] ?? 587 );
	if ( ! in_array( $port, npmp_email_get_aws_smtp_ports(), true ) ) {
		$port = 587;
	}

	return array(
		'host'       => npmp_email_get_aws_smtp_host( $region ),
		'port'       => $port,
		// 465 and 2465 wrap the whole connection in TLS. 587 and 2587 start
		// plain and upgrade with STARTTLS.
		'encryption' => in_array( $port, array( 465, 2465 ), true ) ? 'ssl' : 'tls',
		'auth'       => 1,
		'username'   => trim( (string) get_option( 'npmp_aws_ses_access_key', '' ) ),
		'password'   => npmp_email_ses_smtp_password( get_option( 'npmp_aws_ses_secret_key', '' ), $region ),
		'auto_tls'   => 1,
	);
}

/**
 * Generate the Amazon SES SMTP host for a region.
 *
 * @param string $region AWS region.
 * @return string
 */
function npmp_email_get_aws_smtp_host( $region ) {
	$region = sanitize_key( $region );
	return sprintf( 'email-smtp.%s.amazonaws.com', $region );
}

/**
 * Store the latest email result.
 *
 * @param string $status  success|error|warning.
 * @param array  $details Additional context.
 * @return void
 */
function npmp_email_record_result( $status, $details = array() ) {
	$log = array(
		'status'    => sanitize_key( $status ),
		'details'   => $details,
		'timestamp' => time(),
	);

	// autoload=false: this option is rewritten on every email the site sends
	// (newsletter cron ticks included). Autoloading it meant every send
	// invalidated the alloptions cache sitewide for a value only the email
	// status screen reads.
	update_option( 'npmp_email_last_result', $log, false );
}

/**
 * Determine if the current provider requires SMTP credentials.
 *
 * @param array $settings Settings array.
 * @return bool
 */
function npmp_email_provider_requires_smtp( $settings ) {
	// Only Custom SMTP uses the SMTP fields on the settings screen. Amazon SES
	// sends over SMTP too, but npmp_email_get_transport_smtp() builds its
	// connection from the region and the IAM key pair.
	return 'smtp' === $settings['provider'];
}

/**
 * Helper to detect if SMTP configuration appears complete.
 *
 * @param array $settings Settings array.
 * @return bool
 */
function npmp_email_smtp_is_configured( $settings ) {
	if ( ! npmp_email_provider_requires_smtp( $settings ) ) {
		return true;
	}

	$host     = trim( $settings['smtp']['host'] );
	$username = trim( $settings['smtp']['username'] );
	$password = trim( $settings['smtp']['password'] );
	$port     = (int) $settings['smtp']['port'];

	return ( '' !== $host && '' !== $username && '' !== $password && $port > 0 );
}

/**
 * Make the images in an email body survive a mail client.
 *
 * Newsletter and event-email bodies are rendered through the `the_content`
 * filters, so every `<img>` arrives dressed for a browser: core adds
 * `srcset`, `sizes="auto, ..."`, `loading="lazy"`, `decoding="async"` and
 * sometimes `fetchpriority`. None of those mean anything in email, no mail
 * client implements responsive images, and a client that reads `srcset` or
 * `sizes` before `src` can end up showing nothing at all. A relative or
 * protocol-relative `src` is worse: it has no page to resolve against once
 * the message leaves the site, so the image is simply missing (ROS-4261).
 *
 * So: drop the browser-only attributes and resolve every `src` to an
 * absolute URL. `data:` and `cid:` sources are left alone, since both are
 * already self-contained.
 *
 * @param string $html     Email body HTML.
 * @param string $base_url Site URL to resolve relative sources against.
 *                         Defaults to home_url().
 * @return string
 */
function npmp_email_prepare_images( $html, $base_url = '' ) {
	$html = (string) $html;
	if ( '' === $html || false === stripos( $html, '<img' ) ) {
		return $html;
	}

	if ( '' === $base_url ) {
		$base_url = function_exists( 'home_url' ) ? (string) home_url() : '';
	}
	$base_url = rtrim( $base_url, '/' );

	// An image set to expand on click arrives with core's lightbox button. An
	// email carries no script or stylesheet to hide it, so it shows as a stray
	// empty button beside the image.
	$html = (string) preg_replace( '/<button\b[^>]*\blightbox-trigger\b[^>]*>.*?<\/button>/is', '', $html );

	return (string) preg_replace_callback(
		'/<img\b[^>]*>/i',
		static function ( $m ) use ( $base_url ) {
			return npmp_email_prepare_image_tag( $m[0], $base_url );
		},
		$html
	);
}

/**
 * One `<img>` tag, made email-safe. Split out from
 * npmp_email_prepare_images() so it can be tested on its own.
 *
 * @param string $tag      The whole `<img ...>` tag.
 * @param string $base_url Site URL without a trailing slash.
 * @return string
 */
function npmp_email_prepare_image_tag( $tag, $base_url ) {
	// Browser-only attributes. Quoted, single-quoted and bare forms, plus the
	// valueless shape a hand-written body can carry.
	$tag = (string) preg_replace(
		'/\s+(?:srcset|sizes|loading|decoding|fetchpriority|data-wp-[a-z0-9_-]+)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i',
		'',
		$tag
	);

	return (string) preg_replace_callback(
		'/(\ssrc\s*=\s*)("([^"]*)"|\'([^\']*)\')/i',
		static function ( $m ) use ( $base_url ) {
			$double = '"' === $m[2][0];
			$quote  = $double ? '"' : "'";
			$url    = $double ? $m[3] : ( isset( $m[4] ) ? $m[4] : '' );
			return $m[1] . $quote . npmp_email_absolute_image_url( $url, $base_url ) . $quote;
		},
		$tag
	);
}

/**
 * Resolve one image source against the site URL.
 *
 * Kept as its own name because it is the image half of the pair and reads
 * that way at the call site. The rules are shared with links.
 *
 * @param string $url      Source as written in the body.
 * @param string $base_url Site URL without a trailing slash.
 * @return string
 */
function npmp_email_absolute_image_url( $url, $base_url ) {
	return npmp_email_absolute_url( $url, $base_url );
}

/**
 * Make the links in an email body survive a mail client.
 *
 * The same trouble the images had, one tag over. A link typed into the image
 * block's link field, or into any link field, is stored exactly as typed:
 * `/news` and `newsletter/archive` are both legal there and both resolve in
 * the editor, against the page the editor is on. An email has no page, so a
 * mail client has nothing to resolve them against and the link is dead. An
 * image linked to its own media file is the common case, which is what Eric
 * asked for on ROS-4272: the email has to link back to the image's real
 * public URL.
 *
 * `mailto:`, `tel:` and any other scheme are left alone. So is a `#fragment`,
 * which points inside the message itself, and so is a value still holding an
 * unfilled `[token]` or `{token}` placeholder, where guessing a site URL
 * would only bury the token in a longer broken link.
 *
 * @param string $html     Email body HTML.
 * @param string $base_url Site URL to resolve relative links against.
 *                         Defaults to home_url().
 * @return string
 */
function npmp_email_prepare_links( $html, $base_url = '' ) {
	$html = (string) $html;
	if ( '' === $html || false === stripos( $html, '<a' ) ) {
		return $html;
	}

	if ( '' === $base_url ) {
		$base_url = function_exists( 'home_url' ) ? (string) home_url() : '';
	}
	$base_url = rtrim( $base_url, '/' );

	return (string) preg_replace_callback(
		'/<a\b[^>]*>/i',
		static function ( $m ) use ( $base_url ) {
			return npmp_email_prepare_link_tag( $m[0], $base_url );
		},
		$html
	);
}

/**
 * One `<a>` tag, made email-safe. Split out from npmp_email_prepare_links()
 * so it can be tested on its own.
 *
 * @param string $tag      The whole `<a ...>` tag.
 * @param string $base_url Site URL without a trailing slash.
 * @return string
 */
function npmp_email_prepare_link_tag( $tag, $base_url ) {
	return (string) preg_replace_callback(
		'/(\shref\s*=\s*)("([^"]*)"|\'([^\']*)\')/i',
		static function ( $m ) use ( $base_url ) {
			$double = '"' === $m[2][0];
			$quote  = $double ? '"' : "'";
			$url    = $double ? $m[3] : ( isset( $m[4] ) ? $m[4] : '' );
			return $m[1] . $quote . npmp_email_absolute_url( $url, $base_url ) . $quote;
		},
		$tag
	);
}

/**
 * Resolve one URL written in an email body against the site URL.
 *
 * @param string $url      URL as written in the body.
 * @param string $base_url Site URL without a trailing slash.
 * @return string
 */
function npmp_email_absolute_url( $url, $base_url ) {
	$url     = trim( (string) $url );
	$trimmed = ltrim( $url );

	if ( '' === $trimmed ) {
		return $url;
	}

	// Points inside this message, not at the site.
	if ( '#' === $trimmed[0] ) {
		return $trimmed;
	}

	// A placeholder nothing filled in. Resolving it would only make the
	// broken link longer.
	if ( '[' === $trimmed[0] || '{' === $trimmed[0] ) {
		return $trimmed;
	}

	// Already self-contained, or already absolute.
	if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)#i', $trimmed ) ) {
		return $trimmed;
	}

	if ( '' === $base_url ) {
		return $trimmed;
	}

	// Protocol-relative: //host/path takes the site's scheme.
	if ( 0 === strpos( $trimmed, '//' ) ) {
		$scheme = preg_match( '#^(https?)://#i', $base_url, $m ) ? strtolower( $m[1] ) : 'https';
		return $scheme . ':' . $trimmed;
	}

	// Root-relative and document-relative both resolve against the site root.
	// A newsletter has no page of its own to be relative to.
	return $base_url . '/' . ltrim( $trimmed, '/' );
}

/**
 * The per-recipient tags a newsletter can use, for one contact.
 *
 * A contact stores one name, so first_name is its first word and last_name
 * the rest. A contact saved without a name has its email as the title, which
 * counts as no name.
 *
 * @param string $name Contact name.
 * @return array<string,string> Tag => raw value.
 */
function npmp_email_recipient_tag_values( $name ) {
	$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
	if ( false !== strpos( $name, '@' ) ) {
		$name = '';
	}
	$parts = '' === $name ? array() : explode( ' ', $name, 2 );
	return array(
		'first_name' => $parts[0] ?? '',
		'last_name'  => $parts[1] ?? '',
		'name'       => $name,
	);
}

/**
 * Swap per-recipient tags like [first_name default="Member"] for their values.
 *
 * Runs after the_content, which curls the quotes of a tag WordPress doesn't
 * know (default=&#8221;Member&#8221;), so the default may sit in straight,
 * curly or entity quotes. A tag with no value and no default becomes empty.
 *
 * @param string               $text   Body (HTML) or subject (plain text).
 * @param array<string,string> $values Tag => raw value.
 * @param bool                 $html   True to escape values for HTML.
 * @return string
 */
function npmp_email_fill_recipient_tags( $text, $values, $html = true ) {
	$text = (string) $text;
	if ( '' === $text || false === strpos( $text, '[' ) || empty( $values ) ) {
		return $text;
	}
	$names   = implode(
		'|',
		array_map(
			function ( $key ) {
				return preg_quote( (string) $key, '/' );
			},
			array_keys( (array) $values )
		)
	);
	$pattern = '/\[(' . $names . ')(?:\s+default\s*=\s*([^\]]*))?\s*\]/u';

	return (string) preg_replace_callback(
		$pattern,
		function ( $m ) use ( $values, $html ) {
			$value = trim( (string) ( $values[ $m[1] ] ?? '' ) );
			if ( '' === $value && isset( $m[2] ) ) {
				$value = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$value = trim( $value, " \t\"'\u{201C}\u{201D}\u{2018}\u{2019}\u{2032}\u{2033}" );
			}
			return $html ? esc_html( $value ) : wp_strip_all_tags( $value );
		},
		$text
	);
}
