<?php
/**
 * Per-recipient tags in a newsletter: [first_name], [last_name], [name], each
 * with an optional default (includes/email/helpers.php).
 *
 * Asked for in the wordpress.org thread "Can you add more template
 * shortcodes ?". Until 2026.11.3 a newsletter had no way to greet a reader by
 * name. The send path is checked on a real WordPress by
 * tests/rig/email-tags.php.
 *
 * Run: php tests/test-email-tags.php
 */

require_once __DIR__ . '/bootstrap.php';

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function home_url( $path = '' ) {
	return 'https://example.org' . $path;
}

require_once dirname( __DIR__ ) . '/includes/email/helpers.php';

echo "Splitting a contact's one name\n";
check( 'first and last', array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'name' => 'Ada Lovelace' ), npmp_email_recipient_tag_values( 'Ada Lovelace' ) );
check( 'three words: the rest is the last name', 'King Byron', npmp_email_recipient_tag_values( 'Ada King Byron' )['last_name'] );
check( 'one word', array( 'first_name' => 'Cher', 'last_name' => '', 'name' => 'Cher' ), npmp_email_recipient_tag_values( 'Cher' ) );
check( 'extra spaces collapse', 'Ada Lovelace', npmp_email_recipient_tag_values( "  Ada \t Lovelace " )['name'] );
check( 'a name that is the email counts as none', '', npmp_email_recipient_tag_values( 'ada@example.org' )['first_name'] );
check( 'no name', '', npmp_email_recipient_tag_values( '' )['name'] );

$ada  = npmp_email_recipient_tag_values( 'Ada Lovelace' );
$none = npmp_email_recipient_tag_values( '' );

echo "Filling tags\n";
check( 'plain tag', '<p>Hi Ada,</p>', npmp_email_fill_recipient_tags( '<p>Hi [first_name],</p>', $ada ) );
check( 'all three', 'Ada / Lovelace / Ada Lovelace', npmp_email_fill_recipient_tags( '[first_name] / [last_name] / [name]', $ada ) );
check( 'default unused when there is a name', 'Hi Ada', npmp_email_fill_recipient_tags( 'Hi [first_name default="Member"]', $ada ) );
check( 'default used when there is no name', 'Hi Member', npmp_email_fill_recipient_tags( 'Hi [first_name default="Member"]', $none ) );
check( 'no name, no default: empty', 'Hi ,', npmp_email_fill_recipient_tags( 'Hi [first_name],', $none ) );
check( 'single quotes', 'Hi friend', npmp_email_fill_recipient_tags( "Hi [first_name default='friend']", $none ) );
check( 'curly quotes from wptexturize', 'Hi Member', npmp_email_fill_recipient_tags( "Hi [first_name default=\u{201D}Member\u{201D}]", $none ) );
check( 'entity quotes from wptexturize', 'Hi Member', npmp_email_fill_recipient_tags( 'Hi [first_name default=&#8221;Member&#8221;]', $none ) );
check( 'default with a space', 'Dear fellow member', npmp_email_fill_recipient_tags( 'Dear [first_name default="fellow member"]', $none ) );
check( 'value escaped for HTML', 'Hi Bob &amp; Sue', npmp_email_fill_recipient_tags( 'Hi [name]', npmp_email_recipient_tag_values( 'Bob & Sue' ) ) );
check( 'default escaped for HTML', 'Hi &lt;b&gt;', npmp_email_fill_recipient_tags( 'Hi [first_name default="<b>"]', $none ) );
check( 'subject stays plain', 'Hi Bob & Sue', npmp_email_fill_recipient_tags( 'Hi [name]', npmp_email_recipient_tag_values( 'Bob & Sue' ), false ) );
check( 'other tags untouched', '[event_title] [unsubscribe_url] [names]', npmp_email_fill_recipient_tags( '[event_title] [unsubscribe_url] [names]', $ada ) );
check( 'no brackets, no work', 'Plain', npmp_email_fill_recipient_tags( 'Plain', $ada ) );
check( 'an added tag works', 'Member since 2019', npmp_email_fill_recipient_tags( 'Member since [joined default="long ago"]', array( 'joined' => '2019' ) ) );
check( 'a tag key with a slash is quoted safely', 'x', npmp_email_fill_recipient_tags( '[a/b]', array( 'a/b' => 'x' ) ) );

echo "Wiring\n";
$manager = file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/class-newsletter-manager.php' );
check( 'body fills tags per recipient', 1, substr_count( $manager, '$content = self::fill_recipient_tags( $content, $user_id, $email, true );' ) );
check( 'queued send fills the subject', 1, substr_count( $manager, 'self::personalize_subject( get_the_title( $newsletter_id ), $user_id, $email )' ) );
check( 'test send fills the subject', 1, substr_count( $manager, 'self::personalize_subject( get_the_title( $newsletter_id ), null, $user_email )' ) );
check( 'Pro can add tags', 1, substr_count( $manager, "'npmp_email_recipient_tags'" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
