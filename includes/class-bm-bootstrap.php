<?php
/**
 * Bootstraps Offline Migrator for Blogger: loads core classes and registers hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMIG_Bootstrap {
	public static function init() {
		require_once BMIG_PLUGIN_DIR . 'includes/class-bm-parser.php';
		require_once BMIG_PLUGIN_DIR . 'includes/class-bm-blocks.php';
		require_once BMIG_PLUGIN_DIR . 'includes/class-bm-importer.php';
		require_once BMIG_PLUGIN_DIR . 'includes/class-bm-media.php';
		require_once BMIG_PLUGIN_DIR . 'includes/class-bm-redirect.php';
		BMIG_Redirect::init();

		if ( is_admin() ) {
			require_once BMIG_PLUGIN_DIR . 'includes/class-bm-ajax.php';
			require_once BMIG_PLUGIN_DIR . 'includes/class-bm-admin.php';
			BMIG_Ajax::init();
			BMIG_Admin::init();
		}
	}
}

add_action( 'plugins_loaded', array( 'BMIG_Bootstrap', 'init' ) );
