<?php
/**
 * Handles permalink structure modes and 301 redirects from original Blogger URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Redirect {

	const OPTION_MODE         = 'bm_permalink_mode';
	const OPTION_FLUSHED      = 'bm_rewrite_flushed';
	const OPTION_MODE_A_RULES = 'bm_mode_a_rules';

	/**
	 * Register redirect hooks. Rules are rebuilt from the saved mode on every
	 * request so they stay in sync with the option.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rules' ) );
		// fix_rules_on_flush rebuilds our rules while flush_rewrite_rules() is
		// generating them; rules registered on init reflect the mode saved at
		// request start, so a mid-request mode switch (AJAX wizard) would
		// otherwise persist the previous mode's rules.
		add_action( 'generate_rewrite_rules', array( __CLASS__, 'fix_rules_on_flush' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		// Priority 1 so the redirect runs before redirect_canonical (priority 10),
		// which would otherwise 301 the old URL to its trailing-slash variant.
		add_action( 'template_redirect', array( __CLASS__, 'handle_redirect' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_flush_rewrite' ) );
	}

	/**
	 * Switch the permalink mode and force a one-time rewrite flush on the next
	 * admin request so the new rules take effect.
	 *
	 * @param string $mode 'a' (keep original URLs) or 'b' (root slug URLs + 301).
	 */
	public static function set_mode( $mode ) {
		if ( ! in_array( $mode, array( 'a', 'b' ), true ) ) {
			return;
		}
		update_option( self::OPTION_MODE, $mode, false );
		if ( 'a' === $mode ) {
			self::refresh_mode_a_rules();
		} else {
			delete_option( self::OPTION_MODE_A_RULES );
		}
		delete_option( self::OPTION_FLUSHED );
	}

	/**
	 * Rebuild the Mode A per-URL rules: one literal rule per published post
	 * whose _bm_filename path date (YYYY/MM) differs from its post_date.
	 * post_date is stored in the site timezone, so the comparison uses the
	 * same timezone on both sides. Posts with matching dates already resolve
	 * through the permalink structure itself. The result is saved with
	 * autoload disabled and merged into mode_rules('a').
	 *
	 * @return array<string, string> Regex => query rules.
	 */
	public static function refresh_mode_a_rules() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup on a plugin-internal meta key.
			"SELECT p.ID, p.post_date, m.meta_value AS filename
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE m.meta_key = '_bm_filename'
				AND m.meta_value <> ''
				AND p.post_type = 'post'
				AND p.post_status = 'publish'"
		);

		$rules = array();
		foreach ( (array) $rows as $row ) {
			if ( ! preg_match( '#^/(\d{4})/(\d{2})/([^/]+?\.html)$#', $row->filename, $m ) ) {
				continue;
			}
			if ( substr( $row->post_date, 0, 7 ) === $m[1] . '-' . $m[2] ) {
				continue;
			}
			$rules[ '^' . $m[1] . '/' . $m[2] . '/' . preg_quote( $m[3], '/' ) . '$' ] = 'index.php?bm_redirect_id=' . (int) $row->ID;
		}

		update_option( self::OPTION_MODE_A_RULES, $rules, false );
		return $rules;
	}

	/**
	 * Number of redirect entries currently available: posts/pages carrying a
	 * Blogger filename plus the saved Mode A per-URL rules. Drives whether the
	 * redirect manager is shown at all.
	 *
	 * @return int
	 */
	public static function data_count() {
		if ( 'a' === get_option( self::OPTION_MODE, '' ) ) {
			// Mode A: hanya halaman yang berubah URL, plus post dengan rule per-URL.
			$saved = get_option( self::OPTION_MODE_A_RULES, array() );
			return count( self::export_map() ) + ( is_array( $saved ) ? count( $saved ) : 0 );
		}

		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup on a plugin-internal meta key.
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_bm_filename' AND meta_value <> ''"
		);
	}

	/**
	 * Register rewrite tags and rules for the active mode.
	 */
	public static function register_rules() {
		$rules = self::active_rules();
		if ( empty( $rules ) ) {
			return;
		}

		add_rewrite_tag( '%bm_redirect_page%', '([^&]+)' );
		add_rewrite_tag( '%bm_redirect_id%', '(\d+)' );
		add_rewrite_tag( '%bm_redirect_slug%', '([^&]+)' );

		foreach ( $rules as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * All redirect rules that should be active right now, from the saved mode.
	 *
	 * @return array<string, string>
	 */
	private static function active_rules() {
		$mode = get_option( self::OPTION_MODE, '' );
		if ( 'a' === $mode || 'b' === $mode ) {
			return self::mode_rules( $mode );
		}
		return array();
	}

	/**
	 * Regex => query rules for a mode. Pages always get the /p/slug.html rule
	 * because pages never match the dated post structure. Mode A additionally
	 * merges the saved per-URL rules (OPTION_MODE_A_RULES, rebuilt by
	 * refresh_mode_a_rules()) for posts whose b:filename date differs from
	 * their post date; identical URLs resolve through the permalink structure
	 * itself. Mode B matches every Blogger .html URL, dated or not, and
	 * redirects to the root slug URL.
	 *
	 * @param string $mode 'a' or 'b'.
	 * @return array<string, string>
	 */
	private static function mode_rules( $mode ) {
		if ( 'a' === $mode ) {
			// Mode A mempertahankan URL asli untuk post; halaman statis Blogger
			// (struktur /p/...) tidak dipertahankan, jadi tetap diarahkan.
			$rules = array( '^p/([^/]+?)\.html$' => 'index.php?bm_redirect_page=$matches[1]' );
			$saved = get_option( self::OPTION_MODE_A_RULES, array() );
			if ( is_array( $saved ) ) {
				$rules = array_merge( $rules, $saved );
			}
			return $rules;
		}

		$rules = array( '^p/([^/]+?)\.html$' => 'index.php?bm_redirect_page=$matches[1]' );

		$rules['^(\d{4}/\d{2}/)?([^/]+?)\.html$'] = 'index.php?bm_redirect_slug=$matches[2]';
		return $rules;
	}

	/**
	 * Correct the persisted rule set while it is being generated. Rules added
	 * on init come from the mode saved at request start; after a mid-request
	 * mode switch the in-memory additions are stale, so drop every query
	 * carrying our bm_redirect_ marker and prepend the current mode's rules.
	 *
	 * @param WP_Rewrite $wp_rewrite Rewrite instance, passed by reference.
	 */
	public static function fix_rules_on_flush( $wp_rewrite ) {
		$kept = array();
		foreach ( (array) $wp_rewrite->rules as $regex => $query ) {
			if ( false === strpos( $query, 'bm_redirect_' ) ) {
				$kept[ $regex ] = $query;
			}
		}

		$ours  = self::active_rules();
		$wp_rewrite->rules = array_merge( $ours, $kept );
	}

	/**
	 * Expose the redirect query vars to WP_Query.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'bm_redirect_id';
		$vars[] = 'bm_redirect_slug';
		$vars[] = 'bm_redirect_page';
		return $vars;
	}

	/**
	 * Send the 301 for a matched redirect rule. The post permalink is resolved
	 * live so target URLs follow the current permalink structure; when the
	 * target no longer exists the Blogger pattern is rebuilt as a fallback.
	 */
	public static function handle_redirect() {
		$post_id = (int) get_query_var( 'bm_redirect_id' );
		if ( $post_id ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				wp_safe_redirect( $url, 301 );
				exit;
			}
		}

		$slug = get_query_var( 'bm_redirect_slug' );
		if ( $slug ) {
			$post = get_page_by_path( $slug, OBJECT, 'post' );
			$url  = $post ? get_permalink( $post ) : home_url( user_trailingslashit( $slug ) );
			wp_safe_redirect( $url, 301 );
			exit;
		}

		$page_slug = get_query_var( 'bm_redirect_page' );
		if ( $page_slug ) {
			$page = get_page_by_path( $page_slug, OBJECT, 'page' );
			$url  = $page ? get_permalink( $page ) : home_url( user_trailingslashit( $page_slug ) );
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}

	/**
	 * Flush rewrite rules once after the mode changes. Flushing on every
	 * request is expensive, so a flag option tracks that it already ran.
	 */
	public static function maybe_flush_rewrite() {
		$mode = get_option( self::OPTION_MODE, '' );
		if ( '' === $mode || get_option( self::OPTION_FLUSHED ) ) {
			return;
		}
		flush_rewrite_rules();
		update_option( self::OPTION_FLUSHED, 1, false );
	}

	/**
	 * Rows for the redirect export: every imported post/page with a Blogger
	 * filename (old Blogger path => current permalink).
	 *
	 * @return array[] List of array( source, target ).
	 */
	public static function export_rows() {
		$rows = array();
		foreach ( self::export_map() as $source => $target ) {
			$rows[] = array( $source, $target );
		}
		return $rows;
	}

	/**
	 * Combined old URL => new URL map for display: every imported post/page
	 * carrying a Blogger filename (old path => current permalink). In mode A
	 * posts keep their original URL, so only pages are listed.
	 *
	 * @return array<string, string>
	 */
	public static function export_map() {
		global $wpdb;

		if ( 'a' === get_option( self::OPTION_MODE, '' ) ) {
			// Mode A: post mempertahankan URL asli, hanya halaman yang berubah.
			$map  = array();
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup on a plugin-internal meta key.
				"SELECT p.ID, m.meta_value AS filename
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE m.meta_key = '_bm_filename'
					AND m.meta_value <> ''
					AND p.post_type = 'page'
					AND p.post_status = 'publish'"
			);
			foreach ( (array) $rows as $row ) {
				$permalink = get_permalink( $row->ID );
				if ( $permalink ) {
					$map[ '/' . ltrim( $row->filename, '/' ) ] = $permalink;
				}
			}
			return $map;
		}

		$map  = array();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup on a plugin-internal meta key.
			"SELECT p.ID, m.meta_value AS filename
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE m.meta_key = '_bm_filename'
				AND m.meta_value <> ''
				AND p.post_status = 'publish'"
		);

		foreach ( (array) $rows as $row ) {
			$permalink = get_permalink( $row->ID );
			if ( $permalink ) {
				$map[ $row->filename ] = $permalink;
			}
		}
		return $map;
	}

	/**
	 * Slugs of imported posts that collide with a published static page. In
	 * mode B the page wins the routing, so the post becomes unreachable at its
	 * slug; the redirect still fires but lands on the page.
	 *
	 * @return string[]
	 */
	public static function slug_conflicts() {
		global $wpdb;

		$slugs = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup on a plugin-internal meta key.
			"SELECT DISTINCT p.post_name
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bm_source_id'
			INNER JOIN {$wpdb->posts} pg ON pg.post_name = p.post_name AND pg.post_type = 'page' AND pg.post_status = 'publish'
			WHERE p.post_type = 'post' AND p.post_status = 'publish'"
		);
		return array_map( 'strval', (array) $slugs );
	}

}
