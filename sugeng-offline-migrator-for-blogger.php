<?php
/**
 * Plugin Name: Sugeng Offline Migrator for Blogger
 * Description: Migrasi Blogger ke WordPress dari Google Takeout secara offline. Konten, gambar, komentar, dan redirect 301 dari backup, tanpa blog online.
 * Version: 0.1.3
 * Author: Sugeng.id
 * Author URI: https://sugeng.id
 * License: GPL-2.0-or-later
 * Text Domain: sugeng-offline-migrator-for-blogger
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMIG_VERSION', '0.1.3' );
define( 'BMIG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMIG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once BMIG_PLUGIN_DIR . 'includes/class-bm-bootstrap.php';

/**
 * Rebuild the saved Mode A per-URL redirect rules on activation so they stay
 * in sync with previously imported content.
 */
function bmig_activate() {
	require_once BMIG_PLUGIN_DIR . 'includes/class-bm-redirect.php';
	BMIG_Redirect::refresh_mode_a_rules();
}
register_activation_hook( __FILE__, 'bmig_activate' );

/**
 * Flush rewrite rules on deactivation so the plugin's redirect rules are gone
 * from the active rule set.
 */
function bmig_deactivate() {
	wp_clear_scheduled_hook( 'bmig_cleanup_stale_uploads' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'bmig_deactivate' );
