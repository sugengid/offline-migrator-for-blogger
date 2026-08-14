<?php
/**
 * Chunked AJAX endpoints for the migration wizard: upload/extract, then one
 * small batch per request so the import survives shared hosting limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Ajax {

	const OPTION_JOB    = 'bm_job';
	const CONTENT_BATCH = 25;
	const MEDIA_BATCH   = 10;

	/**
	 * Register AJAX endpoints.
	 */
	public static function init() {
		add_action( 'wp_ajax_bm_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'wp_ajax_bm_summary', array( __CLASS__, 'handle_summary' ) );
		add_action( 'wp_ajax_bm_step', array( __CLASS__, 'handle_step' ) );
	}

	/**
	 * Summary of the stored job for the admin page, so a finished or
	 * interrupted job can be shown or resumed after a page reload.
	 *
	 * @return array|null
	 */
	public static function job_summary() {
		$job = get_option( self::OPTION_JOB );
		if ( ! is_array( $job ) || empty( $job['phase'] ) ) {
			return null;
		}
		return array(
			'phase'     => $job['phase'],
			'done'      => 'done' === $job['phase'],
			'blog'      => isset( $job['blog'] ) ? $job['blog'] : '',
			'mode'      => isset( $job['mode'] ) ? $job['mode'] : '',
			'report'    => isset( $job['report'] ) ? $job['report'] : null,
			'source'    => isset( $job['source'] ) ? $job['source'] : null,
			'blogs_rel' => isset( $job['blogs_rel'] ) ? $job['blogs_rel'] : '',
			'percent'   => self::job_percent( $job ),
		);
	}

	/**
	 * Shared guard for every endpoint.
	 */
	private static function verify_request() {
		check_ajax_referer( 'bm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Akses ditolak.', 'bloggermigrator' ) ), 403 );
		}
	}

	/**
	 * Give one batch request a little more room without assuming ini access.
	 */
	private static function extend_limits() {
		$disabled = (string) ini_get( 'disable_functions' );
		if ( function_exists( 'set_time_limit' ) && false === strpos( $disabled, 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		wp_raise_memory_limit( 'admin' );
	}

	/**
	 * Handle the Takeout archive upload (or a dev-only folder path), extract
	 * it, and return the blogs found inside.
	 */
	public static function handle_upload() {
		self::verify_request();
		self::extend_limits();

		$path_input = isset( $_POST['takeout_path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['takeout_path'] ) ) ) : '';
		if ( '' !== $path_input ) {
			if ( ! apply_filters( 'bm_allow_path_input', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Input path dinonaktifkan.', 'bloggermigrator' ) ), 403 );
			}
			$root = realpath( $path_input );
			if ( ! $root || ! is_dir( $root ) ) {
				wp_send_json_error( array( 'message' => __( 'Folder tidak ditemukan.', 'bloggermigrator' ) ) );
			}
			self::respond_source( 'abs', $root, $root );
		}

		if ( empty( $_FILES['bm_zip'] ) || ! isset( $_FILES['bm_zip']['error'] ) || UPLOAD_ERR_OK !== $_FILES['bm_zip']['error'] ) {
			wp_send_json_error( array( 'message' => __( 'Upload gagal. Periksa batas upload PHP dan ukuran file.', 'bloggermigrator' ) ) );
		}

		$file = $_FILES['bm_zip'];

		$max_mb = (int) apply_filters( 'bm_max_zip_mb', 512 );
		if ( $file['size'] > $max_mb * MB_IN_BYTES ) {
			wp_send_json_error(
				array( 'message' => sprintf( __( 'Arsip melebihi batas %d MB.', 'bloggermigrator' ), $max_mb ) )
			);
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'zip', 'tgz', 'gz' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'File harus berupa arsip zip atau tgz.', 'bloggermigrator' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Daftar mime bawaan situs hanya mengenal application/x-gzip, sedangkan
		// finfo melaporkan application/gzip, jadi izinkan tipe ini khusus selama
		// upload wizard berjalan.
		$allow_gzip = function ( $mimes ) {
			$mimes['tgz'] = 'application/gzip';
			$mimes['gz']  = 'application/gzip';
			return $mimes;
		};
		add_filter( 'upload_mimes', $allow_gzip );
		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'zip' => 'application/zip',
					'tgz' => 'application/gzip',
					'gz'  => 'application/gzip',
				),
			)
		);
		remove_filter( 'upload_mimes', $allow_gzip );
		if ( ! empty( $uploaded['error'] ) ) {
			wp_send_json_error( array( 'message' => $uploaded['error'] ) );
		}

		$upload_dir = wp_upload_dir();
		$work       = trailingslashit( $upload_dir['basedir'] ) . 'bloggermigrator/job-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $work ) ) {
			wp_send_json_error( array( 'message' => __( 'Gagal membuat folder kerja di uploads.', 'bloggermigrator' ) ) );
		}

		$bm_base = trailingslashit( $upload_dir['basedir'] ) . 'bloggermigrator';
		if ( ! file_exists( $bm_base . '/index.php' ) ) {
			file_put_contents( $bm_base . '/index.php', '<?php // Silence is golden.' );
		}

		// PharData hanya membuka tar terkompresi dari nama berakhiran .tgz,
		// jadi file upload distandardkan ke source.zip atau source.tgz.
		$source_ext  = 'gz' === $extension ? 'tgz' : $extension;
		$source_path = $work . '/source.' . $source_ext;
		if ( ! rename( $uploaded['file'], $source_path ) ) {
			self::cleanup_work_dir( $work );
			wp_send_json_error( array( 'message' => __( 'Gagal memindahkan file upload.', 'bloggermigrator' ) ) );
		}

		$format = self::is_valid_archive( $source_path );
		if ( ! $format ) {
			self::cleanup_work_dir( $work );
			if ( 'tgz' === self::archive_format( $source_path ) && ! class_exists( 'PharData' ) ) {
				wp_send_json_error( array( 'message' => __( 'Hosting tidak mendukung ekstraksi arsip tgz (PharData tidak tersedia). Unduh ulang Takeout dalam format zip lalu upload lagi.', 'bloggermigrator' ) ) );
			}
			wp_send_json_error( array( 'message' => __( 'File bukan arsip yang valid atau arsip korup. Unduh ulang file Takeout lalu coba lagi.', 'bloggermigrator' ) ) );
		}

		if ( ! self::archive_entries_safe( $source_path, $format ) ) {
			self::cleanup_work_dir( $work );
			wp_send_json_error( array( 'message' => __( 'Arsip berisi path yang tidak aman atau tidak bisa dibaca.', 'bloggermigrator' ) ) );
		}

		WP_Filesystem();
		$extract = $work . '/extract';
		wp_mkdir_p( $extract );
		if ( 'zip' === $format ) {
			$result = unzip_file( $source_path, $extract );
			if ( is_wp_error( $result ) ) {
				self::cleanup_work_dir( $work );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		} else {
			try {
				$phar = new PharData( $source_path );
				$phar->extractTo( $extract );
			} catch ( Throwable $e ) {
				self::cleanup_work_dir( $work );
				wp_send_json_error( array( 'message' => __( 'Arsip tgz tidak bisa diekstrak (korup atau format tidak didukung). Unduh ulang file Takeout lalu coba lagi.', 'bloggermigrator' ) ) );
			}
		}
		@unlink( $source_path );

		$rel = ltrim( substr( $extract, strlen( trailingslashit( $upload_dir['basedir'] ) ) ), '/' );
		self::respond_source( 'uploads', $rel, $extract );
	}

	/**
	 * Detect the archive format from the first two magic bytes: "PK" for zip,
	 * 0x1f8b for gzip (tgz/tar.gz).
	 *
	 * @param string $path Archive file path.
	 * @return string|false 'zip', 'tgz', or false when unrecognized.
	 */
	private static function archive_format( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		$magic = fread( $handle, 2 );
		fclose( $handle );
		if ( 'PK' === $magic ) {
			return 'zip';
		}
		if ( "\x1f\x8b" === $magic ) {
			return 'tgz';
		}
		return false;
	}

	/**
	 * Confirm the uploaded archive is readable and intact. Zips must pass
	 * ZipArchive::CHECKCONS when the extension is available. Tarballs are
	 * opened with PharData and every entry is iterated so truncated uploads
	 * fail before extraction. Returns the detected format.
	 *
	 * @param string $path Archive file path.
	 * @return string|false 'zip', 'tgz', or false when invalid.
	 */
	private static function is_valid_archive( $path ) {
		$format = self::archive_format( $path );
		if ( ! $format ) {
			return false;
		}

		if ( 'zip' === $format ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip    = new ZipArchive();
				$result = $zip->open( $path, ZipArchive::CHECKCONS );
				if ( true !== $result ) {
					return false;
				}
				$zip->close();
			}
			return 'zip';
		}

		if ( ! class_exists( 'PharData' ) ) {
			return false;
		}
		try {
			$phar    = new PharData( $path );
			$entries = new RecursiveIteratorIterator( $phar );
			foreach ( $entries as $entry ) {
				// Iterasi saja: entry korup memicu exception saat dibaca.
			}
		} catch ( Throwable $e ) {
			return false;
		}
		return 'tgz';
	}

	/**
	 * Validate that no archive entry escapes the extract directory.
	 *
	 * @param string $path   Archive file path.
	 * @param string $format Archive format from is_valid_archive().
	 * @return bool
	 */
	private static function archive_entries_safe( $path, $format ) {
		if ( 'tgz' === $format ) {
			if ( ! class_exists( 'PharData' ) ) {
				return true;
			}
			try {
				$phar    = new PharData( $path );
				$entries = new RecursiveIteratorIterator( $phar );
				foreach ( $entries as $entry ) {
					$name = str_replace( '\\', '/', $entries->getSubPathName() );
					if ( 0 === strpos( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
						return false;
					}
					if ( method_exists( $entry, 'isLink' ) && $entry->isLink() ) {
						return false;
					}
				}
			} catch ( Throwable $e ) {
				return false;
			}
			return true;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return true;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return false;
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name ) {
				continue;
			}
			$name = str_replace( '\\', '/', $name );
			if ( 0 === strpos( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
				$zip->close();
				return false;
			}
		}
		$zip->close();
		return true;
	}

	/**
	 * Locate the Blogs directory, scan it, and answer the upload request.
	 *
	 * @param string $type Source type: 'uploads' or 'abs'.
	 * @param string $ref  Path relative to the uploads basedir, or absolute for 'abs'.
	 * @param string $root Absolute source root to scan.
	 */
	private static function respond_source( $type, $ref, $root ) {
		$blogs_root = self::find_blogs_root( $root );
		if ( ! $blogs_root ) {
			wp_send_json_error( array( 'message' => __( 'Struktur Takeout tidak ditemukan (Blogs/*/feed.atom).', 'bloggermigrator' ) ) );
		}

		$blogs = self::scan_blogs( $blogs_root );
		if ( empty( $blogs ) ) {
			wp_send_json_error( array( 'message' => __( 'Tidak ada feed.atom yang bisa dibaca di folder Blogs.', 'bloggermigrator' ) ) );
		}

		wp_send_json_success(
			array(
				'source'         => array(
					'type' => $type,
					'rel'  => 'uploads' === $type ? $ref : '',
					'path' => 'abs' === $type ? $ref : '',
				),
				'blogs_root_rel' => ltrim( substr( $blogs_root, strlen( $root ) ), '/' ),
				'blogs'          => $blogs,
			)
		);
	}

	/**
	 * Find the Takeout directory named "Blogs" that holds per-blog feed.atom
	 * folders, searching a few levels deep because zips usually nest under
	 * Takeout/Blogger/.
	 *
	 * @param string $root Directory to search.
	 * @return string Absolute path, empty when not found.
	 */
	private static function find_blogs_root( $root ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		$iterator->setMaxDepth( 4 );

		foreach ( $iterator as $file ) {
			if ( ! $file->isDir() || 'Blogs' !== $file->getFilename() ) {
				continue;
			}
			if ( ! empty( glob( trailingslashit( $file->getPathname() ) . '*/feed.atom' ) ) ) {
				return $file->getPathname();
			}
		}
		return '';
	}

	/**
	 * List blogs under the Blogs root.
	 *
	 * @param string $blogs_root Blogs directory path.
	 * @return array[] List of arrays with a name key.
	 */
	private static function scan_blogs( $blogs_root ) {
		$blogs = array();
		foreach ( (array) glob( trailingslashit( $blogs_root ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$feed = $dir . '/feed.atom';
			if ( ! is_readable( $feed ) ) {
				continue;
			}
			$blogs[] = array(
				'name' => basename( $dir ),
			);
		}
		return $blogs;
	}

	/**
	 * Dispatch job actions: start, step, reset.
	 */
	public static function handle_step() {
		self::verify_request();
		self::extend_limits();

		$action = isset( $_POST['step_action'] ) ? sanitize_key( $_POST['step_action'] ) : '';
		switch ( $action ) {
			case 'start':
				self::step_start();
				break;
			case 'step':
				self::step_run();
				break;
			case 'reset':
				$job = get_option( self::OPTION_JOB );
				if ( is_array( $job ) ) {
					self::cleanup_job_work_dir( $job );
				}
				delete_option( self::OPTION_JOB );
				delete_option( BM_Media::OPTION_INVENTORY );
				wp_send_json_success( array( 'reset' => true ) );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Aksi tidak dikenal.', 'bloggermigrator' ) ) );
		}
	}

	/**
	 * Count posts, pages, comments, and image URLs of a chosen blog feed so
	 * the wizard can preview what a migration will import.
	 */
	public static function handle_summary() {
		self::verify_request();
		self::extend_limits();

		$blog      = isset( $_POST['blog'] ) ? sanitize_text_field( wp_unslash( $_POST['blog'] ) ) : '';
		$type      = isset( $_POST['source_type'] ) ? sanitize_key( $_POST['source_type'] ) : '';
		$rel       = isset( $_POST['source_rel'] ) ? sanitize_text_field( wp_unslash( $_POST['source_rel'] ) ) : '';
		$path      = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$blogs_rel = isset( $_POST['blogs_rel'] ) ? sanitize_text_field( wp_unslash( $_POST['blogs_rel'] ) ) : '';

		$root = self::resolve_source_root( $type, $rel, $path );
		if ( is_wp_error( $root ) ) {
			wp_send_json_error( array( 'message' => $root->get_error_message() ) );
		}

		$feed = self::feed_path( $root, $blogs_rel, $blog );
		if ( ! $feed ) {
			wp_send_json_error( array( 'message' => __( 'feed.atom tidak ditemukan untuk blog terpilih.', 'bloggermigrator' ) ) );
		}

		$parser = new BM_Parser();
		$items  = $parser->parse( $feed );
		if ( is_wp_error( $items ) ) {
			wp_send_json_error( array( 'message' => $items->get_error_message() ) );
		}

		wp_send_json_success( self::summarize_items( $items ) );
	}

	/**
	 * Aggregate content and image counts from parsed feed items.
	 *
	 * @param array $items Parsed items.
	 * @return array Counts with posts, pages, comments, images keys.
	 */
	private static function summarize_items( array $items ) {
		$posts = $pages = $comments = 0;
		$urls  = array();

		foreach ( $items as $item ) {
			switch ( $item['type'] ) {
				case 'post':
					$posts++;
					self::collect_content_image_urls( $item['content'], $urls );
					break;
				case 'page':
					$pages++;
					self::collect_content_image_urls( $item['content'], $urls );
					break;
				case 'comment':
					$comments++;
					break;
			}
		}

		return array(
			'posts'    => $posts,
			'pages'    => $pages,
			'comments' => $comments,
			'images'   => count( $urls ),
		);
	}

	/**
	 * Collect unique image URLs from one content block, ignoring <pre> code
	 * placeholders the same way the media pass does.
	 *
	 * @param string $content HTML content.
	 * @param array  $urls    Running unique URL index, modified by reference.
	 */
	private static function collect_content_image_urls( $content, array &$urls ) {
		$content = preg_replace( '/<pre.*?<\/pre>/is', '', (string) $content );
		if ( preg_match_all( BM_Media::URL_PATTERN, $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$urls[ $url ] = true;
			}
		}
	}

	/**
	 * Create a fresh job from the wizard configuration.
	 */
	private static function step_start() {
		$blog           = isset( $_POST['blog'] ) ? sanitize_text_field( wp_unslash( $_POST['blog'] ) ) : '';
		$mode           = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		$media_album    = ! empty( $_POST['media_album'] ) ? 1 : 0;
		$media_external = ! empty( $_POST['media_external'] ) ? 1 : 0;
		$to_blocks      = ! empty( $_POST['to_blocks'] ) ? 1 : 0;
		$type           = isset( $_POST['source_type'] ) ? sanitize_key( $_POST['source_type'] ) : '';
		$rel            = isset( $_POST['source_rel'] ) ? sanitize_text_field( wp_unslash( $_POST['source_rel'] ) ) : '';
		$path           = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$blogs_rel      = isset( $_POST['blogs_rel'] ) ? sanitize_text_field( wp_unslash( $_POST['blogs_rel'] ) ) : '';

		if ( ! in_array( $mode, array( 'a', 'b' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Mode permalink tidak valid.', 'bloggermigrator' ) ) );
		}

		$root = self::resolve_source_root( $type, $rel, $path );
		if ( is_wp_error( $root ) ) {
			wp_send_json_error( array( 'message' => $root->get_error_message() ) );
		}

		$feed = self::feed_path( $root, $blogs_rel, $blog );
		if ( ! $feed ) {
			wp_send_json_error(
				array( 'message' => sprintf( __( 'feed.atom tidak ditemukan di folder "%s". Pilih blog lain atau periksa isi zip Takeout.', 'bloggermigrator' ), $blog ) )
			);
		}

		$parser = new BM_Parser();
		$items  = $parser->parse( $feed );
		if ( is_wp_error( $items ) ) {
			wp_send_json_error( array( 'message' => $items->get_error_message() ) );
		}

		$items = self::sort_items( $items );

		$job = array(
			'phase'          => 'content',
			'cursor'         => 0,
			'blog'           => $blog,
			'blogs_rel'      => trim( $blogs_rel, '/' ),
			'source'         => array(
				'type' => $type,
				'rel'  => 'uploads' === $type ? $rel : '',
				'path' => 'abs' === $type ? $path : '',
			),
			'mode'           => $mode,
			'media_album'    => $media_album,
			'media_external' => $media_external,
			'to_blocks'      => $to_blocks,
			'total_items'    => count( $items ),
			'stats'          => array(
				'content' => array(
					'imported' => 0,
					'skipped'  => 0,
					'errors'   => 0,
				),
				'media'   => array(
					'album_files'   => 0,
					'candidates'    => 0,
					'created'       => 0,
					'reused'        => 0,
					'album'         => 0,
					'external'      => 0,
					'unmatched'     => array(),
					'import_errors' => array(),
				),
				'replace' => array(
					'posts_updated' => 0,
					'urls_replaced' => 0,
				),
			),
			'started_at'     => time(),
			'report'         => null,
		);

		self::write_items_file( $job, $items );

		update_option( self::OPTION_JOB, $job, false );

		wp_send_json_success(
			self::job_status(
				$job,
				array( sprintf( __( 'Job dimulai: %1$d entry feed untuk blog "%2$s".', 'bloggermigrator' ), count( $items ), $blog ) )
			)
		);
	}

	/**
	 * Run exactly one batch of the current phase.
	 */
	private static function step_run() {
		$job = get_option( self::OPTION_JOB );
		if ( ! is_array( $job ) || empty( $job['phase'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Tidak ada job aktif.', 'bloggermigrator' ) ) );
		}

		$log = array();
		switch ( $job['phase'] ) {
			case 'content':
				$job = self::run_content_batch( $job, $log );
				break;
			case 'media':
				$job = self::run_media_batch( $job, $log );
				break;
			case 'media_replace':
				$job = self::run_replace( $job, $log );
				break;
			case 'redirect':
				$job = self::run_redirect( $job, $log );
				break;
		}

		update_option( self::OPTION_JOB, $job, false );
		if ( 'done' === $job['phase'] ) {
			self::cleanup_job_work_dir( $job );
			delete_option( BM_Media::OPTION_INVENTORY );
		}
		wp_send_json_success( self::job_status( $job, $log ) );
	}

	/**
	 * Import one batch of content items. The feed is parsed once at job start
	 * into items.json inside the job work directory; each batch then reads one
	 * slice from that file. Jobs without items.json (dev path sources, jobs
	 * started before this cache existed) fall back to re-parsing the feed.
	 * Items are sorted posts/pages first, so every comment finds its parent
	 * post within the same job regardless of the original feed order.
	 *
	 * @param array $job Job state.
	 * @param array $log Log lines, appended by reference.
	 * @return array Updated job.
	 */
	private static function run_content_batch( array $job, array &$log ) {
		$items = self::load_items_file( $job );
		if ( false === $items ) {
			$feed   = self::feed_path_for_job( $job );
			$parser = new BM_Parser();
			$items  = $parser->parse( $feed );
			if ( is_wp_error( $items ) ) {
				wp_send_json_error( array( 'message' => $items->get_error_message() ) );
			}
			$items = self::sort_items( $items );
		}

		$slice    = array_slice( $items, $job['cursor'], self::CONTENT_BATCH );
		$importer = new BM_Importer();
		$stats    = $importer->import_chunk( $slice );

		$job['stats']['content']['imported'] += $stats['imported'];
		$job['stats']['content']['skipped']  += $stats['skipped'];
		$job['stats']['content']['errors']   += $stats['errors'];
		$job['cursor']                       += count( $slice );

		$log[] = sprintf(
			__( 'Konten: %1$d/%2$d entry diproses (batch: %3$d baru, %4$d dilewati).', 'bloggermigrator' ),
			min( $job['cursor'], $job['total_items'] ),
			$job['total_items'],
			$stats['imported'],
			$stats['skipped']
		);

		if ( $job['cursor'] >= $job['total_items'] ) {
			$job['phase']  = self::job_has_media( $job ) ? 'media' : 'redirect';
			$job['cursor'] = 0;
			$log[]         = __( 'Fase konten selesai.', 'bloggermigrator' );
		}
		return $job;
	}

	/**
	 * Whether the job imports images from any source.
	 *
	 * @param array $job Job state.
	 * @return bool
	 */
	private static function job_has_media( array $job ) {
		return ! empty( $job['media_album'] ) || ! empty( $job['media_external'] );
	}

	/**
	 * Import one batch of image candidates into the media library. The album
	 * pass resolves files from the Takeout Albums folder; the external pass
	 * downloads still-live URLs that the album pass did not resolve and records
	 * dead URLs as failures.
	 *
	 * @param array $job Job state.
	 * @param array $log Log lines, appended by reference.
	 * @return array Updated job.
	 */
	private static function run_media_batch( array $job, array &$log ) {
		$album    = ! empty( $job['media_album'] );
		$external = ! empty( $job['media_external'] );
		$albums   = $album ? self::albums_path_for_job( $job ) : '';
		$media    = new BM_Media();
		$setup    = $media->setup( $albums, $job['blog'], $album, $external );
		if ( is_wp_error( $setup ) ) {
			$log[]         = $setup->get_error_message() . ' ' . __( 'Fase media dilewati.', 'bloggermigrator' );
			$job['phase']  = 'redirect';
			$job['cursor'] = 0;
			return $job;
		}

		$job['stats']['media']['album_files'] = $setup['album_files'];
		$job['stats']['media']['candidates']  = $setup['candidates'];

		if ( 0 === $setup['candidates'] ) {
			$log[]         = __( 'Tidak ada URL gambar di konten hasil impor. Fase media dilewati.', 'bloggermigrator' );
			$job['phase']  = 'media_replace';
			$job['cursor'] = 0;
			return $job;
		}

		$batch = $media->import_batch( $job['cursor'], self::MEDIA_BATCH );
		$job['stats']['media']['created']       += $batch['attachments_created'];
		$job['stats']['media']['reused']        += $batch['attachments_reused'];
		$job['stats']['media']['album']         += $batch['album'];
		$job['stats']['media']['external']      += $batch['external'];
		$job['stats']['media']['unmatched']      = array_merge( $job['stats']['media']['unmatched'], $batch['unmatched'] );
		$job['stats']['media']['import_errors']  = array_merge( $job['stats']['media']['import_errors'], $batch['import_errors'] );
		$job['cursor']                          += $batch['processed'];

		$log[] = sprintf(
			__( 'Media: %1$d/%2$d kandidat gambar diproses (total %3$d attachment baru).', 'bloggermigrator' ),
			min( $job['cursor'], $setup['candidates'] ),
			$setup['candidates'],
			$job['stats']['media']['created']
		);

		if ( $job['cursor'] >= $setup['candidates'] ) {
			$job['phase']  = 'media_replace';
			$job['cursor'] = 0;
		}
		return $job;
	}

	/**
	 * Finalize content for the chosen output format. With blocks enabled the
	 * plain HTML is converted to block markup now that images are imported, so
	 * image blocks resolve their attachment id and local URL. Otherwise image
	 * URLs are rewritten in place.
	 *
	 * @param array $job Job state.
	 * @param array $log Log lines, appended by reference.
	 * @return array Updated job.
	 */
	private static function run_replace( array $job, array &$log ) {
		// Fase ini memproses semua post dalam satu request. extend_limits()
		// sudah memberi 300 detik, tapi blog sangat besar tetap bisa timeout
		// di sini; chunking fase ini belum diimplementasikan.
		if ( ! empty( $job['to_blocks'] ) ) {
			$updated = BM_Blocks::convert_imported_posts();
			$job['stats']['replace'] = array(
				'posts_updated' => $updated,
				'urls_replaced' => 0,
			);
			$log[] = sprintf(
				__( 'Konversi blocks: %1$d konten diupdate.', 'bloggermigrator' ),
				$updated
			);
		} else {
			$media = new BM_Media();
			$stats = $media->replace_urls();

			$job['stats']['replace'] = $stats;
			$log[] = sprintf(
				__( 'Replace URL: %1$d konten diupdate, %2$d URL diganti.', 'bloggermigrator' ),
				$stats['posts_updated'],
				$stats['urls_replaced']
			);
		}

		$job['phase']  = 'redirect';
		$job['cursor'] = 0;
		return $job;
	}

	/**
	 * Apply the permalink mode, flush rewrites once, then build the report.
	 *
	 * @param array $job Job state.
	 * @param array $log Log lines, appended by reference.
	 * @return array Updated job.
	 */
	private static function run_redirect( array $job, array &$log ) {
		global $wp_rewrite;

		if ( 'a' === $job['mode'] ) {
			$wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%postname%.html' );
		} else {
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
		}

		BM_Redirect::set_mode( $job['mode'] );
		flush_rewrite_rules();
		update_option( BM_Redirect::OPTION_FLUSHED, 1, false );

		$log[] = sprintf(
			__( 'Redirect mode %s aktif, rewrite rules di-flush.', 'bloggermigrator' ),
			strtoupper( $job['mode'] )
		);

		if ( 'b' === $job['mode'] ) {
			$conflicts = BM_Redirect::slug_conflicts();
			if ( ! empty( $conflicts ) ) {
				$log[] = sprintf(
					__( 'Peringatan: %1$d slug post bentrok dengan halaman statis (%2$s). Halaman menang di routing; redirect tetap dipasang.', 'bloggermigrator' ),
					count( $conflicts ),
					implode( ', ', $conflicts )
				);
			}
		}

		$job['phase']       = 'done';
		$job['finished_at'] = time();
		$job['report']      = self::build_report( $job );
		return $job;
	}

	/**
	 * Build the final report. Content and attachment totals are read from the
	 * database so repeated runs report the real end state, not just what the
	 * last run inserted.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private static function build_report( array $job ) {
		global $wpdb;

		$type_counts = $wpdb->get_results(
			"SELECT p.post_type, COUNT(*) AS total
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE m.meta_key = '_bm_source_id'
			GROUP BY p.post_type",
			OBJECT_K
		);

		$comments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = '_bm_source_id'"
		);

		$attachments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bm_source_file'"
		);

		$map = (array) get_option( BM_Media::OPTION_MAP, array() );

		return array(
			'posts'            => isset( $type_counts['post'] ) ? (int) $type_counts['post']->total : 0,
			'pages'            => isset( $type_counts['page'] ) ? (int) $type_counts['page']->total : 0,
			'comments'         => $comments,
			'attachments'      => $attachments,
			'images_matched'   => count( $map ),
			'images_album'     => $job['stats']['media']['album'],
			'images_external'  => $job['stats']['media']['external'],
			'images_unmatched' => $job['stats']['media']['unmatched'],
			'images_failed'    => $job['stats']['media']['import_errors'],
			'posts_updated'    => $job['stats']['replace']['posts_updated'],
			'urls_replaced'    => $job['stats']['replace']['urls_replaced'],
			'mode'             => $job['mode'],
			'slug_conflicts'   => 'b' === $job['mode'] ? BM_Redirect::slug_conflicts() : array(),
			'duration'         => time() - $job['started_at'],
		);
	}

	/**
	 * Shape the job state for the JS progress loop.
	 *
	 * @param array $job Job state.
	 * @param array $log Log lines produced by this request.
	 * @return array
	 */
	private static function job_status( array $job, array $log ) {
		$status = array(
			'phase'   => $job['phase'],
			'percent' => self::job_percent( $job ),
			'log'     => $log,
			'done'    => 'done' === $job['phase'],
		);
		if ( ! empty( $job['report'] ) ) {
			$status['report'] = $job['report'];
		}
		return $status;
	}

	/**
	 * Weighted overall progress: content dominates, media and the short tail
	 * phases share the rest. Without media, content fills almost everything.
	 *
	 * @param array $job Job state.
	 * @return int
	 */
	private static function job_percent( array $job ) {
		$media_span  = self::job_has_media( $job ) ? 30 : 0;
		$content_end = 95 - $media_span;

		switch ( $job['phase'] ) {
			case 'content':
				$ratio = $job['total_items'] > 0 ? $job['cursor'] / $job['total_items'] : 1;
				return (int) round( min( 1, $ratio ) * $content_end );
			case 'media':
				$total = max( 1, (int) $job['stats']['media']['candidates'] );
				$ratio = min( 1, $job['cursor'] / $total );
				return (int) round( $content_end + $ratio * $media_span );
			case 'media_replace':
				return 96;
			case 'redirect':
				return 98;
			case 'done':
				return 100;
		}
		return 0;
	}

	/**
	 * Sort parsed items so posts and pages come before comments while keeping
	 * the original feed order inside each group. Deterministic for the same
	 * feed, so batches sliced from items.json or a fallback parse stay aligned.
	 *
	 * @param array $items Parsed items.
	 * @return array
	 */
	private static function sort_items( array $items ) {
		$decorated = array();
		foreach ( $items as $index => $item ) {
			$decorated[] = array(
				'group' => 'comment' === $item['type'] ? 1 : 0,
				'index' => $index,
				'item'  => $item,
			);
		}
		usort(
			$decorated,
			function ( $a, $b ) {
				if ( $a['group'] !== $b['group'] ) {
					return $a['group'] - $b['group'];
				}
				return $a['index'] - $b['index'];
			}
		);
		return array_map(
			function ( $row ) {
				return $row['item'];
			},
			$decorated
		);
	}

	/**
	 * Write the parsed and sorted feed items to items.json in the job work
	 * directory so content batches do not re-parse the feed. Only upload
	 * sources own a work directory; for dev path sources this is a no-op and
	 * batches fall back to parsing. When the content is not valid UTF-8,
	 * wp_json_encode fails and the cache is skipped the same way.
	 *
	 * @param array $job   Job state.
	 * @param array $items Sorted parsed items.
	 */
	private static function write_items_file( array $job, array $items ) {
		$dir = self::work_dir_for_job( $job );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$payload = array(
			'stats' => self::summarize_items( $items ),
			'items' => $items,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return;
		}
		file_put_contents( $dir . '/items.json', $json );
	}

	/**
	 * Read the cached parsed items written by write_items_file().
	 *
	 * @param array $job Job state.
	 * @return array|false Item list, false when no usable cache exists.
	 */
	private static function load_items_file( array $job ) {
		$dir = self::work_dir_for_job( $job );
		if ( '' === $dir ) {
			return false;
		}
		$path = $dir . '/items.json';
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$json = file_get_contents( $path );
		$data = $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return false;
		}
		return $data['items'];
	}

	/**
	 * Resolve the job work directory (uploads/bloggermigrator/job-XXXX) from the
	 * stored source reference and remove it. Only upload sources own a work
	 * directory; dev path sources are left untouched.
	 *
	 * @param array $job Job state.
	 */
	private static function cleanup_job_work_dir( array $job ) {
		$dir = self::work_dir_for_job( $job );
		if ( '' !== $dir ) {
			self::cleanup_work_dir( $dir );
		}
	}

	/**
	 * Absolute path of the job work directory, empty for dev path sources or
	 * malformed references.
	 *
	 * @param array $job Job state.
	 * @return string
	 */
	private static function work_dir_for_job( array $job ) {
		if ( empty( $job['source']['type'] ) || 'uploads' !== $job['source']['type'] || empty( $job['source']['rel'] ) ) {
			return '';
		}
		$parts = explode( '/', trim( $job['source']['rel'], '/' ) );
		if ( count( $parts ) < 2 || 'bloggermigrator' !== $parts[0] || preg_match( '#(^|/)\.\.(/|$)#', $parts[1] ) || false !== strpos( $parts[1], '/' ) ) {
			return '';
		}
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'bloggermigrator/' . $parts[1];
	}

	/**
	 * Delete a work directory recursively, refusing anything outside the
	 * uploads base directory.
	 *
	 * @param string $work Work directory path.
	 */
	private static function cleanup_work_dir( $work ) {
		$upload = wp_upload_dir();
		$base   = realpath( $upload['basedir'] );
		$work   = realpath( $work );
		if ( ! $base || ! $work || 0 !== strpos( $work, trailingslashit( $base ) ) || ! is_dir( $work ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $work );
	}

	/**
	 * Resolve the source root from a stored or submitted reference. Upload
	 * sources must stay inside the uploads directory; absolute paths are only
	 * honored when the dev filter enables them.
	 *
	 * @param string $type 'uploads' or 'abs'.
	 * @param string $rel  Path relative to the uploads basedir.
	 * @param string $path Absolute path for 'abs'.
	 * @return string|WP_Error
	 */
	private static function resolve_source_root( $type, $rel, $path ) {
		if ( 'abs' === $type ) {
			if ( ! apply_filters( 'bm_allow_path_input', false ) ) {
				return new WP_Error( 'bm_path_disabled', __( 'Input path dinonaktifkan.', 'bloggermigrator' ) );
			}
			$root = realpath( $path );
			if ( ! $root || ! is_dir( $root ) ) {
				return new WP_Error( 'bm_path_missing', __( 'Folder sumber tidak ditemukan.', 'bloggermigrator' ) );
			}
			return $root;
		}

		if ( 'uploads' !== $type ) {
			return new WP_Error( 'bm_source_type', __( 'Tipe sumber tidak valid.', 'bloggermigrator' ) );
		}

		$rel = trim( (string) $rel, '/' );
		if ( '' === $rel || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return new WP_Error( 'bm_source_rel', __( 'Path sumber tidak valid.', 'bloggermigrator' ) );
		}

		$upload = wp_upload_dir();
		$base   = realpath( $upload['basedir'] );
		$root   = realpath( trailingslashit( $upload['basedir'] ) . $rel );
		if ( ! $base || ! $root || 0 !== strpos( $root, trailingslashit( $base ) ) || ! is_dir( $root ) ) {
			return new WP_Error( 'bm_source_missing', __( 'Folder hasil ekstrak tidak ditemukan.', 'bloggermigrator' ) );
		}
		return $root;
	}

	/**
	 * Build and validate the feed.atom path for a blog inside a source root.
	 *
	 * @param string $root      Source root (realpath).
	 * @param string $blogs_rel Blogs directory relative to the root.
	 * @param string $blog      Blog folder name.
	 * @return string Feed path, empty when invalid.
	 */
	private static function feed_path( $root, $blogs_rel, $blog ) {
		$blogs_rel = trim( (string) $blogs_rel, '/' );
		if ( preg_match( '#(^|/)\.\.(/|$)#', $blogs_rel ) || preg_match( '#(^|/)\.\.(/|$)#', $blog ) || false !== strpos( $blog, '/' ) ) {
			return '';
		}
		$feed = $root . ( '' !== $blogs_rel ? '/' . $blogs_rel : '' ) . '/' . $blog . '/feed.atom';
		$real = realpath( $feed );
		if ( ! $real || 0 !== strpos( $real, $root ) || ! is_readable( $real ) ) {
			return '';
		}
		return $real;
	}

	/**
	 * Feed path for the running job, failing the request when unreadable.
	 *
	 * @param array $job Job state.
	 * @return string
	 */
	private static function feed_path_for_job( array $job ) {
		$root = self::resolve_source_root( $job['source']['type'], $job['source']['rel'], $job['source']['path'] );
		if ( is_wp_error( $root ) ) {
			wp_send_json_error( array( 'message' => $root->get_error_message() ) );
		}
		$feed = self::feed_path( $root, $job['blogs_rel'], $job['blog'] );
		if ( ! $feed ) {
			wp_send_json_error(
				array( 'message' => sprintf( __( 'feed.atom tidak ditemukan di folder "%s". Folder sumber mungkin sudah berubah.', 'bloggermigrator' ), $job['blog'] ) )
			);
		}
		return $feed;
	}

	/**
	 * Albums path for the running job: the Albums directory sits next to the
	 * Blogs directory in a Takeout export.
	 *
	 * @param array $job Job state.
	 * @return string
	 */
	private static function albums_path_for_job( array $job ) {
		$root = self::resolve_source_root( $job['source']['type'], $job['source']['rel'], $job['source']['path'] );
		if ( is_wp_error( $root ) ) {
			wp_send_json_error( array( 'message' => $root->get_error_message() ) );
		}
		$blogs_root = $root . ( '' !== $job['blogs_rel'] ? '/' . $job['blogs_rel'] : '' );
		return dirname( $blogs_root ) . '/Albums';
	}
}
