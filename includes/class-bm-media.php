<?php
/**
 * Migrates images into the media library from the Takeout Albums folder and/or
 * still-live external URLs, then rewrites image URLs inside imported content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Media {

	const OPTION_MAP = 'bm_img_map';

	const OPTION_INVENTORY = 'bm_media_inventory';

	const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' );

	/**
	 * Image URL pattern. Parentheses are allowed inside the URL because Blogger
	 * file names legitimately contain them (e.g. StockSnap_G3YOGRBLF3%20(1).jpg);
	 * the extension anchor still terminates the match before a closing delimiter.
	 */
	const URL_PATTERN = '#https?://[^\s"\'\\\\<>]+?\.(?:png|jpe?g|gif|webp|svg)(?:\?[^\s"\'\\\\<>]*)?#i';

	/**
	 * Album inventory: lowercase file name => list of array( path, top ).
	 * "top" is the first folder name below the Albums root, used to resolve
	 * name clashes between folders.
	 *
	 * @var array
	 */
	private $inventory = array();

	/**
	 * Original image URL => new attachment URL.
	 *
	 * @var array
	 */
	private $map = array();

	/**
	 * Album file path => attachment id, per run.
	 *
	 * @var array
	 */
	private $file_attachments = array();

	/**
	 * Candidate image URLs collected by setup() for chunked runs.
	 *
	 * @var array
	 */
	private $candidates = array();

	/**
	 * Preferred top album folder resolved by setup() for chunked runs.
	 *
	 * @var string
	 */
	private $preferred = '';

	/**
	 * Whether to resolve image URLs against the Takeout Albums files.
	 *
	 * @var bool
	 */
	private $use_album = true;

	/**
	 * Whether to download image URLs that the album pass did not resolve.
	 *
	 * @var bool
	 */
	private $use_external = false;

	/**
	 * Run the full media migration: inventory, attachment import, then content
	 * replacement. Idempotent via the bm_img_map option and the _bm_source_file
	 * attachment meta.
	 *
	 * @param string $albums_path Path to the Takeout Albums folder.
	 * @param string $blog_name   Optional blog name; folders containing it win
	 *                            when a file name exists in several folders.
	 * @return array|WP_Error Stats array, or WP_Error when the path is invalid.
	 */
	public function run( $albums_path, $blog_name = '' ) {
		$albums_path = rtrim( (string) $albums_path, '/\\' );
		if ( ! is_dir( $albums_path ) ) {
			return new WP_Error( 'bm_media_no_dir', __( 'Folder album tidak ditemukan.', 'bloggermigrator' ) );
		}

		$stats = array(
			'album_files'         => 0,
			'candidates'          => 0,
			'matched'             => 0,
			'album'               => 0,
			'external'            => 0,
			'unmatched'           => array(),
			'import_errors'       => array(),
			'attachments_created' => 0,
			'attachments_reused'  => 0,
			'posts_updated'       => 0,
			'urls_replaced'       => 0,
		);

		$this->build_inventory( $albums_path, $stats );
		$this->map = (array) get_option( self::OPTION_MAP, array() );

		$post_ids   = $this->get_imported_post_ids();
		$candidates = $this->collect_candidates( $post_ids );
		$preferred  = $this->detect_preferred_folder( $candidates, $blog_name );

		add_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );
		foreach ( $candidates as $url ) {
			$this->import_candidate( $url, $preferred, $stats );
		}
		remove_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );

		update_option( self::OPTION_MAP, $this->map, false );

		$this->replace_in_posts( $post_ids, $stats );

		$stats['candidates'] = count( $candidates );
		$stats['matched']    = $stats['candidates'] - count( $stats['unmatched'] ) - count( $stats['import_errors'] );

		return $stats;
	}

	/**
	 * Prepare a chunked media run: load the stored URL map and collect the
	 * candidate URLs from imported content. The album pass additionally validates
	 * the Albums path, builds the file inventory, and resolves the preferred
	 * album folder. Must be called before import_batch() on the same instance.
	 * Inventory, candidates, and preferred folder are cached in the
	 * bm_media_inventory option (autoload off) so later batches skip the
	 * directory walk and content scan; the cache is rebuilt when it is missing
	 * or its key does not match the current job parameters.
	 *
	 * @param string $albums_path Path to the Takeout Albums folder (album pass only).
	 * @param string $blog_name   Optional blog name for folder preference.
	 * @param bool   $album       Resolve URLs against the Albums folder.
	 * @param bool   $external    Download URLs the album pass did not resolve.
	 * @return array|WP_Error Array with album_files and candidates counts, or WP_Error.
	 */
	public function setup( $albums_path, $blog_name = '', $album = true, $external = false ) {
		$this->use_album    = (bool) $album;
		$this->use_external = (bool) $external;
		$this->map          = (array) get_option( self::OPTION_MAP, array() );

		$cache_key = md5( (string) $albums_path . '|' . (string) $blog_name . '|' . (int) $this->use_album . '|' . (int) $this->use_external . '|' . home_url() );
		$cached    = get_option( self::OPTION_INVENTORY );
		if ( is_array( $cached )
			&& isset( $cached['key'] ) && $cached['key'] === $cache_key
			&& isset( $cached['inventory'], $cached['candidates'], $cached['preferred'], $cached['album_files'] )
			&& is_array( $cached['inventory'] ) && is_array( $cached['candidates'] )
		) {
			$this->inventory  = $cached['inventory'];
			$this->candidates = $cached['candidates'];
			$this->preferred  = (string) $cached['preferred'];
			return array(
				'album_files' => (int) $cached['album_files'],
				'candidates'  => count( $this->candidates ),
			);
		}

		$this->inventory  = array();
		$this->candidates = $this->collect_candidates( $this->get_imported_post_ids() );
		$album_files      = 0;

		if ( $this->use_album ) {
			$albums_path = rtrim( (string) $albums_path, '/\\' );
			if ( ! is_dir( $albums_path ) ) {
				return new WP_Error( 'bm_media_no_dir', __( 'Folder album tidak ditemukan.', 'bloggermigrator' ) );
			}

			$stats = array( 'album_files' => 0 );
			$this->build_inventory( $albums_path, $stats );
			$this->preferred = $this->detect_preferred_folder( $this->candidates, $blog_name );
			$album_files     = $stats['album_files'];
		}

		update_option(
			self::OPTION_INVENTORY,
			array(
				'key'         => $cache_key,
				'inventory'   => $this->inventory,
				'candidates'  => $this->candidates,
				'preferred'   => $this->preferred,
				'album_files' => $album_files,
			),
			false
		);

		return array(
			'album_files' => $album_files,
			'candidates'  => count( $this->candidates ),
		);
	}

	/**
	 * Import one slice of the candidate list prepared by setup(). The URL map
	 * is persisted after every batch so interrupted runs resume cleanly.
	 *
	 * @param int $offset Candidate offset.
	 * @param int $limit  Batch size.
	 * @return array Batch stats: processed, attachments_created, attachments_reused, unmatched, import_errors.
	 */
	public function import_batch( $offset, $limit ) {
		$stats = array(
			'processed'           => 0,
			'attachments_created' => 0,
			'attachments_reused'  => 0,
			'album'               => 0,
			'external'            => 0,
			'unmatched'           => array(),
			'import_errors'       => array(),
		);

		$slice = array_slice( $this->candidates, $offset, $limit );

		add_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );
		foreach ( $slice as $url ) {
			$this->import_candidate( $url, $this->preferred, $stats );
		}
		remove_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );

		update_option( self::OPTION_MAP, $this->map, false );

		$stats['processed'] = count( $slice );
		return $stats;
	}

	/**
	 * Rewrite image URLs in imported content using the stored URL map.
	 * Requires setup() first so the map is loaded.
	 *
	 * @return array Stats: posts_updated, urls_replaced.
	 */
	public function replace_urls() {
		if ( empty( $this->map ) ) {
			$this->map = (array) get_option( self::OPTION_MAP, array() );
		}
		$stats = array(
			'posts_updated' => 0,
			'urls_replaced' => 0,
		);
		$this->replace_in_posts( $this->get_imported_post_ids(), $stats );
		return $stats;
	}

	/**
	 * Permit image mime types that WordPress blocks by default (notably SVG)
	 * while importing album files.
	 *
	 * @param array $mimes Allowed mime types.
	 * @return array
	 */
	public function allow_image_mimes( $mimes ) {
		if ( ! isset( $mimes['svg'] ) ) {
			$mimes['svg'] = 'image/svg+xml';
		}
		if ( ! isset( $mimes['webp'] ) ) {
			$mimes['webp'] = 'image/webp';
		}
		return $mimes;
	}

	/**
	 * Walk the Albums tree and index every image file by lowercase name.
	 * .json sidecar files are skipped by the extension whitelist.
	 *
	 * @param string $albums_path Albums root path.
	 * @param array  $stats       Running stats, modified by reference.
	 */
	private function build_inventory( $albums_path, array &$stats ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $albums_path, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, self::IMAGE_EXTENSIONS, true ) ) {
				continue;
			}
			$relative = explode( '/', trim( substr( $file->getPathname(), strlen( $albums_path ) ), '/' ) );
			$this->inventory[ strtolower( $file->getFilename() ) ][] = array(
				'path' => $file->getPathname(),
				'top'  => $relative[0],
			);
			$stats['album_files']++;
		}

		ksort( $this->inventory );
		foreach ( $this->inventory as &$entries ) {
			usort(
				$entries,
				function ( $a, $b ) {
					return strcmp( $a['path'], $b['path'] );
				}
			);
		}
		unset( $entries );
	}

	/**
	 * Get ids of posts and pages imported from Blogger (marked _bm_source_id).
	 *
	 * @return int[]
	 */
	private function get_imported_post_ids() {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup on a plugin-internal meta key.
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bm_source_id'"
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Collect unique image URLs from imported content. <pre> blocks are removed
	 * first so tutorial code placeholders are never treated as real images. URLs
	 * already pointing at this site are skipped so repeated runs stay clean.
	 *
	 * @param int[] $post_ids Imported post ids.
	 * @return string[] Unique candidate URLs.
	 */
	private function collect_candidates( array $post_ids ) {
		$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$candidates = array();
		foreach ( $post_ids as $post_id ) {
			$content = get_post_field( 'post_content', $post_id );
			$content = preg_replace( '/<pre.*?<\/pre>/is', '', $content );
			if ( preg_match_all( self::URL_PATTERN, $content, $matches ) ) {
				foreach ( $matches[0] as $url ) {
					if ( $site_host && wp_parse_url( $url, PHP_URL_HOST ) === $site_host ) {
						continue;
					}
					$candidates[ $url ] = true;
				}
			}
		}
		return array_keys( $candidates );
	}

	/**
	 * Normalize an image URL to its file name: drop the query string, turn "+"
	 * into spaces, then rawurldecode repeatedly because Takeout URLs are
	 * sometimes double-encoded (e.g. %25281%2529.jpg).
	 *
	 * @param string $url Image URL.
	 * @return string File name.
	 */
	private function fname( $url ) {
		$name = basename( strtok( $url, '?' ) );
		$name = str_replace( '+', ' ', $name );
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = rawurldecode( $name );
			if ( $decoded === $name ) {
				break;
			}
			$name = $decoded;
		}
		return $name;
	}

	/**
	 * Pick the album folder most likely belonging to the migrated blog: the one
	 * holding the most candidate file names. When $blog_name is given, only
	 * folders containing it are considered.
	 *
	 * @param string[] $candidates Candidate URLs.
	 * @param string   $blog_name  Optional blog name filter.
	 * @return string Preferred top folder name, empty when undetermined.
	 */
	private function detect_preferred_folder( array $candidates, $blog_name ) {
		$scores = array();
		foreach ( $candidates as $url ) {
			$key = strtolower( $this->fname( $url ) );
			if ( empty( $this->inventory[ $key ] ) ) {
				continue;
			}
			foreach ( $this->inventory[ $key ] as $entry ) {
				$top = $entry['top'];
				$scores[ $top ] = isset( $scores[ $top ] ) ? $scores[ $top ] + 1 : 1;
			}
		}

		if ( '' !== $blog_name ) {
			$filtered = array();
			foreach ( $scores as $top => $score ) {
				if ( false !== stripos( $top, $blog_name ) ) {
					$filtered[ $top ] = $score;
				}
			}
			if ( ! empty( $filtered ) ) {
				$scores = $filtered;
			}
		}

		if ( empty( $scores ) ) {
			return '';
		}

		arsort( $scores );
		return (string) key( $scores );
	}

	/**
	 * Resolve one candidate URL and record the URL mapping. The album pass runs
	 * first when enabled; URLs not found in the album fall through to the
	 * external download pass when that is enabled. URLs already present in the
	 * stored map are left untouched so repeated runs stay cheap.
	 *
	 * @param string $url       Candidate image URL.
	 * @param string $preferred Preferred top folder for name clashes.
	 * @param array  $stats     Running stats, modified by reference.
	 */
	private function import_candidate( $url, $preferred, array &$stats ) {
		if ( isset( $this->map[ $url ] ) ) {
			return;
		}

		if ( $this->use_album ) {
			$key = strtolower( $this->fname( $url ) );
			if ( ! empty( $this->inventory[ $key ] ) ) {
				$this->import_candidate_album( $url, $this->inventory[ $key ], $preferred, $stats );
				return;
			}
			if ( ! $this->use_external ) {
				$stats['unmatched'][ $url ] = __( 'Host eksternal, file tidak ada di album.', 'bloggermigrator' );
				return;
			}
		}

		if ( $this->use_external ) {
			$this->import_candidate_external( $url, $stats );
		}
	}

	/**
	 * Import one album file as an attachment and map the candidate URL to it.
	 *
	 * @param string $url       Candidate image URL.
	 * @param array  $entries   Inventory entries for the matching file name.
	 * @param string $preferred Preferred top folder for name clashes.
	 * @param array  $stats     Running stats, modified by reference.
	 */
	private function import_candidate_album( $url, array $entries, $preferred, array &$stats ) {
		$path          = $this->pick_path( $entries, $preferred );
		$attachment_id = $this->attachment_for_file( $path );

		if ( $attachment_id ) {
			$stats['attachments_reused']++;
		} else {
			$attachment_id = $this->import_attachment( $path );
			if ( ! $attachment_id ) {
				$stats['import_errors'][ $url ] = __( 'Gagal mengimpor file ke media library.', 'bloggermigrator' );
				return;
			}
			add_post_meta( $attachment_id, '_bm_source_url', $url );
			$this->file_attachments[ $path ] = $attachment_id;
			$stats['attachments_created']++;
		}

		$new_url = wp_get_attachment_url( $attachment_id );
		if ( $new_url ) {
			$this->map[ $url ] = $new_url;
			$stats['album']++;
		}
	}

	/**
	 * Download one external image URL into the media library. Any host is
	 * attempted, including blogspot URLs that are still alive; URLs that fail
	 * to download are recorded as failures. Attachments previously downloaded
	 * from the same URL are reused via the _bm_source_url meta.
	 *
	 * @param string $url   Candidate image URL.
	 * @param array  $stats Running stats, modified by reference.
	 */
	private function import_candidate_external( $url, array &$stats ) {
		$existing = $this->attachment_for_source_url( $url );
		if ( $existing ) {
			$new_url = wp_get_attachment_url( $existing );
			if ( $new_url ) {
				$this->map[ $url ] = $new_url;
				$stats['external']++;
			}
			$stats['attachments_reused']++;
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! self::is_external_url_safe( $url ) ) {
			$stats['import_errors'][ $url ] = __( 'URL diblokir: host tidak diizinkan.', 'bloggermigrator' );
			return;
		}

		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) {
			$stats['import_errors'][ $url ] = sprintf(
				/* translators: %s: download error message */
				__( 'Gagal mengunduh: %s', 'bloggermigrator' ),
				$tmp->get_error_message()
			);
			return;
		}

		$filetype = wp_check_filetype( $this->fname( $url ) );
		if ( empty( $filetype['type'] ) || 0 !== strpos( $filetype['type'], 'image/' ) ) {
			wp_delete_file( $tmp );
			$stats['import_errors'][ $url ] = __( 'File hasil unduhan bukan gambar yang didukung.', 'bloggermigrator' );
			return;
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $this->fname( $url ),
				'tmp_name' => $tmp,
			),
			0
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			$stats['import_errors'][ $url ] = sprintf(
				/* translators: %s: sideload error message */
				__( 'Gagal menyimpan ke media library: %s', 'bloggermigrator' ),
				$attachment_id->get_error_message()
			);
			return;
		}

		add_post_meta( $attachment_id, '_bm_source_url', $url );
		$stats['attachments_created']++;

		$new_url = wp_get_attachment_url( $attachment_id );
		if ( $new_url ) {
			$this->map[ $url ] = $new_url;
			$stats['external']++;
		}
	}

	/**
	 * Reject private or local hosts before an external download.
	 *
	 * @param string $url Candidate URL.
	 * @return bool True when the URL may be downloaded.
	 */
	private static function is_external_url_safe( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) || 'local' === substr( $host, -6 ) ) {
			return false;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP );
		if ( false !== $ip ) {
			$prefixes = array( '0.', '10.', '127.', '169.254.', '172.16.', '172.17.', '172.18.', '172.19.', '172.2', '172.3', '192.168.', 'fe80:', 'fc', 'fd' );
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $host, $prefix ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Find an attachment previously downloaded from the same external URL.
	 *
	 * @param string $url Source image URL.
	 * @return int Attachment id, 0 when none.
	 */
	private function attachment_for_source_url( $url ) {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared lookup on a plugin-internal meta key.
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bm_source_url' AND meta_value = %s LIMIT 1",
				$url
			)
		);
	}

	/**
	 * Choose one file among inventory entries sharing a name. The preferred
	 * folder wins; otherwise the first entry (paths are sorted) is used.
	 *
	 * @param array  $entries   Inventory entries for one file name.
	 * @param string $preferred Preferred top folder.
	 * @return string File path.
	 */
	private function pick_path( array $entries, $preferred ) {
		if ( '' !== $preferred ) {
			foreach ( $entries as $entry ) {
				if ( $entry['top'] === $preferred ) {
					return $entry['path'];
				}
			}
		}
		return $entries[0]['path'];
	}

	/**
	 * Find an attachment previously imported from the same album file.
	 *
	 * @param string $path Album file path.
	 * @return int Attachment id, 0 when none.
	 */
	private function attachment_for_file( $path ) {
		global $wpdb;
		if ( isset( $this->file_attachments[ $path ] ) ) {
			return $this->file_attachments[ $path ];
		}
		$attachment_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared lookup on a plugin-internal meta key.
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bm_source_file' AND meta_value = %s LIMIT 1",
				$path
			)
		);
		$this->file_attachments[ $path ] = $attachment_id;
		return $attachment_id;
	}

	/**
	 * Copy a local album file into the uploads directory and register it as an
	 * attachment with generated metadata.
	 *
	 * @param string $path Album file path.
	 * @return int Attachment id, 0 on failure.
	 */
	private function import_attachment( $path ) {
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return 0;
		}

		$bits = wp_upload_bits( basename( $path ), null, $contents );
		if ( ! empty( $bits['error'] ) ) {
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $bits['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => $bits['type'],
				'guid'           => $bits['url'],
			),
			$bits['file']
		);

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $bits['file'] );
		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta( $attachment_id, '_bm_source_file', $path );
		return $attachment_id;
	}

	/**
	 * Rewrite image URLs in every imported post. Only segments outside <pre>
	 * blocks are touched.
	 *
	 * @param int[] $post_ids Imported post ids.
	 * @param array $stats    Running stats, modified by reference.
	 */
	private function replace_in_posts( array $post_ids, array &$stats ) {
		$stem_map = $this->build_stem_map();

		foreach ( $post_ids as $post_id ) {
			$content  = get_post_field( 'post_content', $post_id );
			$segments = preg_split( '/(<pre.*?<\/pre>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
			$changed  = false;

			foreach ( $segments as $index => $segment ) {
				if ( $index % 2 === 1 ) {
					continue;
				}
				$replaced = $this->replace_in_segment( $segment, $stem_map, $stats );
				if ( $replaced !== $segment ) {
					$segments[ $index ] = $replaced;
					$changed            = true;
				}
			}

			if ( $changed ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( implode( '', $segments ) ),
					)
				);
				$stats['posts_updated']++;
			}
		}
	}

	/**
	 * Replace mapped URLs in one content segment, then retry remaining image
	 * URLs by stem so files renamed by the WordPress sanitizer (parentheses
	 * stripped, dedup suffixes, changed extension) still match.
	 *
	 * @param string $segment  Content segment outside <pre>.
	 * @param array  $stem_map Stem => new attachment URL.
	 * @param array  $stats    Running stats, modified by reference.
	 * @return string
	 */
	private function replace_in_segment( $segment, array $stem_map, array &$stats ) {
		foreach ( $this->map as $old_url => $new_url ) {
			if ( false !== strpos( $segment, $old_url ) ) {
				$count                 = 0;
				$segment               = str_replace( $old_url, $new_url, $segment, $count );
				$stats['urls_replaced'] += $count;
			}
		}

		if ( preg_match_all( self::URL_PATTERN, $segment, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				if ( isset( $this->map[ $url ] ) ) {
					continue;
				}
				$stem = $this->stem( $this->fname( $url ) );
				if ( isset( $stem_map[ $stem ] ) && $stem_map[ $stem ] !== $url ) {
					$count                 = 0;
					$segment               = str_replace( $url, $stem_map[ $stem ], $segment, $count );
					$stats['urls_replaced'] += $count;
				}
			}
		}

		return $segment;
	}

	/**
	 * Build a stem => new URL index from the current URL map.
	 *
	 * @return array
	 */
	private function build_stem_map() {
		$stem_map = array();
		foreach ( $this->map as $old_url => $new_url ) {
			$stem_map[ $this->stem( $this->fname( $old_url ) ) ] = $new_url;
		}
		return $stem_map;
	}

	/**
	 * Reduce a file name to its stem: lowercase, no extension, no non-alnum
	 * characters. Stems survive both URL encoding and WordPress file name
	 * sanitization, so variants of the same image collapse to one key.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function stem( $name ) {
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( preg_replace( '/\.[^.]+$/', '', $name ) ) );
	}
}
