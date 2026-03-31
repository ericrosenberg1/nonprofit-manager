<?php
/**
 * File path: includes/npmp-social-sharing.php
 *
 * Loader for the Social Sharing module (free tier).
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/social-sharing/class-social-share-manager.php';
require_once __DIR__ . '/social-sharing/networks/facebook.php';
require_once __DIR__ . '/social-sharing/networks/x-twitter.php';
require_once __DIR__ . '/social-sharing/admin-social.php';
require_once __DIR__ . '/social-sharing/share-hooks.php';
