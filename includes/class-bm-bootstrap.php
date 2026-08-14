<?php
/**
 * Bootstraps BloggerMigrator: loads core classes and registers hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Bootstrap {
	public static function init() {
		load_plugin_textdomain( 'bloggermigrator', false, dirname( plugin_basename( BM_PLUGIN_DIR . 'bloggermigrator.php' ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Bundled translations for non-w.org installs; w.org loads its own automatically.

		require_once BM_PLUGIN_DIR . 'includes/class-bm-parser.php';
		require_once BM_PLUGIN_DIR . 'includes/class-bm-blocks.php';
		require_once BM_PLUGIN_DIR . 'includes/class-bm-importer.php';
		require_once BM_PLUGIN_DIR . 'includes/class-bm-media.php';
		require_once BM_PLUGIN_DIR . 'includes/class-bm-redirect.php';
		BM_Redirect::init();

		if ( is_admin() ) {
			require_once BM_PLUGIN_DIR . 'includes/class-bm-ajax.php';
			require_once BM_PLUGIN_DIR . 'includes/class-bm-admin.php';
			BM_Ajax::init();
			BM_Admin::init();
		}
	}
}

add_action( 'plugins_loaded', array( 'BM_Bootstrap', 'init' ) );
