<?php
/**
 * Admin wizard page: menu, render, and asset enqueue.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMIG_Admin {

	const MENU_SLUG = 'offline-migrator-for-blogger';

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_bmig_export_redirects', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Add the top-level Offline Migrator for Blogger menu.
	 */
	public static function register_menu() {
		add_menu_page(
			'Offline Migrator for Blogger',
			'Offline Migrator for Blogger',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-migrate'
		);
	}

	/**
	 * Load wizard assets only on the plugin page. Version follows filemtime so
	 * browsers pick up changes immediately during development.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'bmig-admin',
			BMIG_PLUGIN_URL . 'assets/bmig-admin.css',
			array(),
			(string) filemtime( BMIG_PLUGIN_DIR . 'assets/bmig-admin.css' )
		);
		wp_enqueue_script(
			'bmig-admin',
			BMIG_PLUGIN_URL . 'assets/bmig-admin.js',
			array(),
			(string) filemtime( BMIG_PLUGIN_DIR . 'assets/bmig-admin.js' ),
			true
		);
		wp_localize_script(
			'bmig-admin',
			'bmigAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'bmig_nonce' ),
				'homeUrl'    => home_url( '/' ),
				'maxZipMb'   => (int) apply_filters( 'bmig_max_zip_mb', 512 ),
				'phpLimitMb' => self::php_upload_limit_mb(),
				'job'        => BMIG_Ajax::job_summary(),
				'strings'    => array(
					'requestFailed'    => __( 'Request gagal. Periksa koneksi lalu coba lagi.', 'offline-migrator-for-blogger' ),
					'pickZip'          => __( 'Pilih file arsip Takeout dulu.', 'offline-migrator-for-blogger' ),
					'zipTooLarge'      => __( 'Arsip melebihi batas plugin.', 'offline-migrator-for-blogger' ),
					'zipOverPhpLimit'  => __( 'Ukuran arsip melebihi batas upload PHP server. Upload hampir pasti gagal; kecilkan file atau naikkan upload_max_filesize dan post_max_size.', 'offline-migrator-for-blogger' ),
					'noSource'         => __( 'Sumber Takeout belum dipilih, ulangi dari langkah 1.', 'offline-migrator-for-blogger' ),
					'confirmMode'      => __( 'Mode ini mengubah struktur permalink situs. URL lama Blogger dialihkan dengan redirect 301 otomatis. Mode bisa diganti nanti dengan menjalankan ulang migrasi memakai mode lain. Lanjutkan?', 'offline-migrator-for-blogger' ),
					'confirmPending'   => __( 'Job sebelumnya belum selesai. Mulai dari awal?', 'offline-migrator-for-blogger' ),
					'confirmReset'     => __( 'State job akan dihapus dan wizard kembali ke awal. Konten hasil impor tidak ikut terhapus. Lanjutkan?', 'offline-migrator-for-blogger' ),
					'jobFinished'      => __( 'Migrasi selesai.', 'offline-migrator-for-blogger' ),
					'startFailed'      => __( 'Gagal memulai job', 'offline-migrator-for-blogger' ),
					'resetFailed'      => __( 'Gagal reset job', 'offline-migrator-for-blogger' ),
					'summaryFailed'    => __( 'Gagal memuat ringkasan blog', 'offline-migrator-for-blogger' ),
					'reportEmpty'      => __( 'Migrasi selesai tanpa konten baru. Kemungkinan semua konten sudah pernah diimpor atau feed tidak berisi entry.', 'offline-migrator-for-blogger' ),
					'batchFailed'      => __( 'Batch gagal 5x berturut-turut, migrasi dihentikan. Muat ulang halaman lalu klik Lanjutkan job untuk melanjutkan dari batch terakhir.', 'offline-migrator-for-blogger' ),
					'batchRetry'       => __( 'Batch error, coba ulang', 'offline-migrator-for-blogger' ),
					'resumeJob'        => __( 'Lanjutkan job', 'offline-migrator-for-blogger' ),
					'resumedTo'        => __( 'Melanjutkan job dari fase:', 'offline-migrator-for-blogger' ),
					'extractDone'      => __( 'Ekstrak selesai, blog ditemukan:', 'offline-migrator-for-blogger' ),
					'uploadFailed'     => __( 'Upload gagal', 'offline-migrator-for-blogger' ),
					'phaseContent'     => __( 'Mengimpor konten...', 'offline-migrator-for-blogger' ),
					'phaseMedia'       => __( 'Mengimpor gambar...', 'offline-migrator-for-blogger' ),
					'phaseReplace'     => __( 'Menyiapkan konten...', 'offline-migrator-for-blogger' ),
					'phaseRedirect'    => __( 'Menyiapkan redirect...', 'offline-migrator-for-blogger' ),
					'confirmCancel'    => __( 'Batal', 'offline-migrator-for-blogger' ),
					'confirmProceed'   => __( 'Lanjutkan', 'offline-migrator-for-blogger' ),
					'confirmOk'        => __( 'OK', 'offline-migrator-for-blogger' ),
				),
			)
		);
	}

	/**
	 * Effective PHP upload ceiling in MB: the lower of upload_max_filesize and
	 * post_max_size, so the wizard can warn before an upload that would fail.
	 *
	 * @return int
	 */
	private static function php_upload_limit_mb() {
		$limits = array();
		foreach ( array( 'upload_max_filesize', 'post_max_size' ) as $key ) {
			$bytes = wp_convert_hr_to_bytes( (string) ini_get( $key ) );
			if ( $bytes > 0 ) {
				$limits[] = $bytes;
			}
		}
		if ( empty( $limits ) ) {
			return 0;
		}
		return (int) floor( min( $limits ) / MB_IN_BYTES );
	}

	/**
	 * Format a duration in seconds as "Xm Ys", mirroring the JS helper.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string
	 */
	private static function format_duration( $seconds ) {
		$seconds = (int) $seconds;
		$mins    = (int) floor( $seconds / 60 );
		$secs    = $seconds % 60;
		return $mins > 0 ? $mins . 'm ' . $secs . 's' : $secs . 's';
	}

	/**
	 * Render the three-step wizard plus the report section.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Akses ditolak.', 'offline-migrator-for-blogger' ) );
		}

		$allow_path = (bool) apply_filters( 'bmig_allow_path_input', false );
		$job        = BMIG_Ajax::job_summary();
		$done       = $job && isset( $job['phase'] ) && 'done' === $job['phase'];
		$report     = $done && ! empty( $job['report'] ) ? $job['report'] : array();
		$r_posts    = isset( $report['posts'] ) ? $report['posts'] : 0;
		$r_pages    = isset( $report['pages'] ) ? $report['pages'] : 0;
		$r_comments = isset( $report['comments'] ) ? $report['comments'] : 0;
		$r_attach   = isset( $report['attachments'] ) ? $report['attachments'] : 0;
		$r_matched  = isset( $report['images_matched'] ) ? $report['images_matched'] : 0;
		$r_album    = isset( $report['images_album'] ) ? $report['images_album'] : 0;
		$r_ext      = isset( $report['images_external'] ) ? $report['images_external'] : 0;
		$r_failed   = isset( $report['images_failed'] ) ? count( $report['images_failed'] ) : 0;
		$r_updated  = isset( $report['posts_updated'] ) ? $report['posts_updated'] : 0;
		$r_mode     = isset( $report['mode'] ) ? strtoupper( $report['mode'] ) : '-';
		$r_dur      = isset( $report['duration'] ) ? self::format_duration( $report['duration'] ) : '-';
		$r_imported = $r_posts + $r_pages + $r_comments;
		$r_warn     = isset( $report['mode'] ) && 'a' === $report['mode'];
		$r_conflicts = isset( $report['slug_conflicts'] ) ? $report['slug_conflicts'] : array();
		$r_unmatched = isset( $report['images_unmatched'] ) ? $report['images_unmatched'] : array();
		$r_failed_list = isset( $report['images_failed'] ) ? $report['images_failed'] : array();
		$limit_mb   = self::php_upload_limit_mb();
		?>
		<div class="wrap bmig-wrap">
			<h1>Offline Migrator for Blogger</h1>

			<?php if ( ! $job ) : ?>
				<div class="bmig-intro">
					<p><?php esc_html_e( 'Migrasikan blog Blogger ke WordPress hanya dari file backup Google Takeout, tanpa perlu blog online.', 'offline-migrator-for-blogger' ); ?></p>
					<ul class="bmig-intro-steps">
						<li>
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Upload arsip Takeout', 'offline-migrator-for-blogger' ); ?></strong><br>
							<?php esc_html_e( 'Ekspor Blogger dari Google Takeout, lalu upload arsipnya (zip atau tgz) di langkah 1.', 'offline-migrator-for-blogger' ); ?>
						</li>
						<li>
							<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Pilih blog & mode', 'offline-migrator-for-blogger' ); ?></strong><br>
							<?php esc_html_e( 'Pilih blog yang akan dimigrasi dan mode permalink tujuan.', 'offline-migrator-for-blogger' ); ?>
						</li>
						<li>
							<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Jalankan migrasi', 'offline-migrator-for-blogger' ); ?></strong><br>
							<?php esc_html_e( 'Konten, komentar, dan gambar diproses bertahap sampai selesai.', 'offline-migrator-for-blogger' ); ?>
						</li>
					</ul>
				</div>
			<?php endif; ?>

			<ol class="bmig-tabs"<?php echo $done ? ' hidden' : ''; ?>>
				<li class="bmig-tab bmig-active" data-step="1">1. <?php esc_html_e( 'Upload Takeout', 'offline-migrator-for-blogger' ); ?></li>
				<li class="bmig-tab" data-step="2">2. <?php esc_html_e( 'Pilih Blog & Mode', 'offline-migrator-for-blogger' ); ?></li>
				<li class="bmig-tab" data-step="3">3. <?php esc_html_e( 'Jalankan Migrasi', 'offline-migrator-for-blogger' ); ?></li>
			</ol>

			<p class="bmig-status" id="bmig-status" hidden></p>

			<div class="bmig-step" id="bmig-step-1"<?php echo $done ? ' hidden' : ''; ?>>
				<h2><?php esc_html_e( 'Langkah 1: Upload arsip Takeout (zip/tgz)', 'offline-migrator-for-blogger' ); ?></h2>
				<form id="bmig-upload-form">
					<p>
						<input type="file" id="bmig-zip" accept=".zip,.tgz,.tar.gz,application/zip,application/gzip">
					</p>
					<?php if ( $limit_mb > 0 ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %d: upload size limit in MB */
								esc_html__( 'Batas upload server saat ini: %d MB. Arsip yang lebih besar akan ditolak PHP sebelum sempat diproses.', 'offline-migrator-for-blogger' ),
								intval( $limit_mb )
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( $allow_path ) : ?>
						<p>
							<label for="bmig-takeout-path"><?php esc_html_e( 'Atau path folder Takeout (khusus dev):', 'offline-migrator-for-blogger' ); ?></label><br>
							<input type="text" id="bmig-takeout-path" class="regular-text" placeholder="/path/to/Takeout">
						</p>
					<?php endif; ?>
					<p>
						<button type="submit" class="button button-primary" id="bmig-upload-btn">
							<?php esc_html_e( 'Upload & Ekstrak', 'offline-migrator-for-blogger' ); ?>
						</button>
						<span class="spinner" id="bmig-upload-spinner"></span>
					</p>
				</form>
			</div>

			<div class="bmig-step" id="bmig-step-2" hidden>
				<h2><?php esc_html_e( 'Langkah 2: Pilih blog dan mode permalink', 'offline-migrator-for-blogger' ); ?></h2>
				<p>
					<label for="bmig-blog"><?php esc_html_e( 'Blog yang dimigrasi:', 'offline-migrator-for-blogger' ); ?></label><br>
					<select id="bmig-blog"></select>
				</p>
				<fieldset class="bmig-modes">
					<legend><?php esc_html_e( 'Mode permalink:', 'offline-migrator-for-blogger' ); ?></legend>
					<p>
						<label>
							<input type="radio" name="bmig_mode" value="a" checked>
							<strong><?php esc_html_e( 'Mode A', 'offline-migrator-for-blogger' ); ?></strong>:
							<?php esc_html_e( 'pertahankan URL asli Blogger (/2026/03/slug.html).', 'offline-migrator-for-blogger' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="radio" name="bmig_mode" value="b">
							<strong><?php esc_html_e( 'Mode B', 'offline-migrator-for-blogger' ); ?></strong>:
							<?php esc_html_e( 'URL baru di root (/slug/) dengan redirect 301 dari URL lama.', 'offline-migrator-for-blogger' ); ?>
						</label>
					</p>
				</fieldset>
				<fieldset class="bmig-modes">
					<legend><?php esc_html_e( 'Gambar postingan:', 'offline-migrator-for-blogger' ); ?></legend>
					<p>
						<label>
							<input type="checkbox" id="bmig-media-album" checked>
							<?php esc_html_e( 'Import gambar dari album Takeout (offline, dari folder Albums hasil ekstrak arsip).', 'offline-migrator-for-blogger' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" id="bmig-media-external">
							<?php esc_html_e( 'Import gambar dari URL eksternal (online: URL yang masih hidup di-download ke media library).', 'offline-migrator-for-blogger' ); ?>
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'Kosongkan keduanya untuk tidak mengimport gambar.', 'offline-migrator-for-blogger' ); ?>
					</p>
				</fieldset>
				<p>
					<label>
						<input type="checkbox" id="bmig-to-blocks">
						<?php esc_html_e( 'Konversi HTML konten ke Gutenberg blocks.', 'offline-migrator-for-blogger' ); ?>
					</label>
				</p>
				<p>
					<button type="button" class="button button-primary" id="bmig-config-btn">
						<?php esc_html_e( 'Lanjut', 'offline-migrator-for-blogger' ); ?>
					</button>
				</p>
			</div>

			<div class="bmig-step" id="bmig-step-3" hidden>
				<h2><?php esc_html_e( 'Langkah 3: Jalankan migrasi', 'offline-migrator-for-blogger' ); ?></h2>
				<div class="bmig-summary" id="bmig-summary" hidden>
					<h3><?php esc_html_e( 'Ringkasan blog', 'offline-migrator-for-blogger' ); ?></h3>
					<p class="bmig-summary-blog">
						<?php esc_html_e( 'Blog:', 'offline-migrator-for-blogger' ); ?>
						<strong id="bmig-sum-blog"></strong>
					</p>
					<div class="bmig-summary-grid">
						<div class="bmig-summary-item">
							<span class="bmig-summary-num" id="bmig-sum-posts">0</span>
							<span class="bmig-summary-label"><?php esc_html_e( 'Postingan', 'offline-migrator-for-blogger' ); ?></span>
						</div>
						<div class="bmig-summary-item">
							<span class="bmig-summary-num" id="bmig-sum-pages">0</span>
							<span class="bmig-summary-label"><?php esc_html_e( 'Halaman', 'offline-migrator-for-blogger' ); ?></span>
						</div>
						<div class="bmig-summary-item">
							<span class="bmig-summary-num" id="bmig-sum-comments">0</span>
							<span class="bmig-summary-label"><?php esc_html_e( 'Komentar', 'offline-migrator-for-blogger' ); ?></span>
						</div>
						<div class="bmig-summary-item">
							<span class="bmig-summary-num" id="bmig-sum-images">0</span>
							<span class="bmig-summary-label"><?php esc_html_e( 'Gambar', 'offline-migrator-for-blogger' ); ?></span>
						</div>
					</div>
				</div>
				<div class="bmig-progress" id="bmig-progress" hidden>
					<div class="bmig-progress-bar" id="bmig-progress-bar"></div>
				</div>
				<p id="bmig-progress-label" hidden>0%</p>
				<p>
					<button type="button" class="button button-primary" id="bmig-start-btn">
						<?php esc_html_e( 'Mulai Migrasi', 'offline-migrator-for-blogger' ); ?>
					</button>
				</p>
			</div>

			<div class="bmig-step bmig-report" id="bmig-report"<?php echo $done ? '' : ' hidden'; ?>>
				<h2><?php esc_html_e( 'Laporan Migrasi', 'offline-migrator-for-blogger' ); ?></h2>
				<div class="notice notice-info inline" id="bmig-report-empty"<?php echo $done && $r_imported > 0 ? ' hidden' : ''; ?>>
					<p><?php esc_html_e( 'Migrasi selesai tanpa konten baru. Kemungkinan semua konten sudah pernah diimpor atau feed tidak berisi entry.', 'offline-migrator-for-blogger' ); ?></p>
				</div>
				<table class="widefat striped bmig-report-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Post diimport', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-posts"><?php echo esc_html( $r_posts ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Halaman diimport', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-pages"><?php echo esc_html( $r_pages ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Komentar diimport', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-comments"><?php echo esc_html( $r_comments ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Attachment dibuat', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-attachments"><?php echo esc_html( $r_attach ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'URL gambar cocok', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-matched"><?php echo esc_html( $r_matched ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gambar dari album', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-album"><?php echo esc_html( $r_album ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gambar dari URL eksternal', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-external"><?php echo esc_html( $r_ext ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gambar gagal diimport', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-failed-count"><?php echo esc_html( $r_failed ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Konten diupdate (replace URL)', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-posts-updated"><?php echo esc_html( $r_updated ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Mode redirect aktif', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-mode"><?php echo esc_html( $r_mode ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Durasi', 'offline-migrator-for-blogger' ); ?></th>
							<td id="bmig-r-duration"><?php echo esc_html( $r_dur ); ?></td>
						</tr>
					</tbody>
				</table>
				<div class="notice notice-warning inline" id="bmig-report-warning"<?php echo $done && $r_warn ? '' : ' hidden'; ?>>
					<p>
						<?php esc_html_e( 'Mode A memakai URL asli Blogger untuk postingan (contoh: namablog.com/2026/03/judul.html). Halaman statis tetap diarahkan otomatis dari /p/judul.html ke /judul/ karena strukturnya berbeda.', 'offline-migrator-for-blogger' ); ?>
					</p>
				</div>
				<div class="notice notice-warning inline" id="bmig-report-conflicts"<?php echo $done && ! empty( $r_conflicts ) ? '' : ' hidden'; ?>>
					<p>
						<?php esc_html_e( 'Slug berikut bentrok dengan halaman statis yang sudah ada. Halaman menang di routing; redirect dari URL Blogger tetap dipasang.', 'offline-migrator-for-blogger' ); ?>
					</p>
					<ul id="bmig-r-conflicts">
						<?php foreach ( $r_conflicts as $slug ) : ?>
							<li><?php echo esc_html( '/' . $slug . '/' ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<h3><?php esc_html_e( 'URL gambar tidak ditemukan di album', 'offline-migrator-for-blogger' ); ?></h3>
				<p id="bmig-r-unmatched-empty"<?php echo $done && ! empty( $r_unmatched ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Semua URL gambar berhasil dipetakan.', 'offline-migrator-for-blogger' ); ?></p>
				<ul id="bmig-r-unmatched">
					<?php foreach ( array_keys( $r_unmatched ) as $url ) : ?>
						<li><?php echo esc_html( $url ); ?></li>
					<?php endforeach; ?>
				</ul>
				<h3><?php esc_html_e( 'URL gambar gagal diimport', 'offline-migrator-for-blogger' ); ?></h3>
				<p id="bmig-r-failed-empty"<?php echo $done && ! empty( $r_failed_list ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Tidak ada gambar yang gagal diimport.', 'offline-migrator-for-blogger' ); ?></p>
				<ul id="bmig-r-failed">
					<?php foreach ( array_keys( $r_failed_list ) as $url ) : ?>
						<li><?php echo esc_html( $url ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Lihat situs', 'offline-migrator-for-blogger' ); ?>
					</a>
					<button type="button" class="button" id="bmig-reset-btn">
						<?php esc_html_e( 'Mulai migrasi baru', 'offline-migrator-for-blogger' ); ?>
					</button>
				</p>
			</div>

			<?php self::render_redirect_manager(); ?>
		</div>
		<?php
	}

	/**
	 * Render the redirect manager section: export buttons and the summary of
	 * redirects produced by the migration.
	 */
	private static function render_redirect_manager() {
		if ( 0 === BMIG_Redirect::data_count() ) {
			return;
		}

		$map = BMIG_Redirect::export_map();

		$export_csv  = wp_nonce_url( admin_url( 'admin-post.php?action=bmig_export_redirects&format=csv' ), 'bmig_export_redirects' );
		$export_json = wp_nonce_url( admin_url( 'admin-post.php?action=bmig_export_redirects&format=json' ), 'bmig_export_redirects' );
		?>
		<div class="bmig-step bmig-redirect-manager">
			<h2><?php esc_html_e( 'Export redirect', 'offline-migrator-for-blogger' ); ?></h2>

			<p>
				<?php
				printf(
					/* translators: %d: total redirect entries */
					esc_html__( 'Total redirect: %d.', 'offline-migrator-for-blogger' ),
					count( $map )
				);
				?>
			</p>
			<div class="bmig-redirect-scroll">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL lama', 'offline-migrator-for-blogger' ); ?></th>
							<th><?php esc_html_e( 'URL baru', 'offline-migrator-for-blogger' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $map as $source => $target ) : ?>
							<tr>
								<td><code><?php echo esc_html( $source ); ?></code></td>
								<td><code><?php echo esc_html( $target ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p><?php esc_html_e( 'Unduh semua redirect untuk diimport ke plugin Redirection. Setelah itu plugin Offline Migrator for Blogger bisa dihapus dan URL redirect tetap bekerja. Atau tetap aktifkan plugin ini jika tidak ingin menggunakan plugin Redirection.', 'offline-migrator-for-blogger' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( $export_csv ); ?>"><?php esc_html_e( 'Export redirect CSV', 'offline-migrator-for-blogger' ); ?></a>
				<a class="button" href="<?php echo esc_url( $export_json ); ?>"><?php esc_html_e( 'Export redirect JSON', 'offline-migrator-for-blogger' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Shared guard for the redirect manager admin-post endpoints.
	 *
	 * @param string $nonce_action Nonce action to verify.
	 */
	private static function verify_manager_request( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Akses ditolak.', 'offline-migrator-for-blogger' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Back to the plugin page, carrying result flags for the notice area.
	 *
	 * @param array $args Extra query args.
	 */
	private static function redirect_back( array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Stream the redirect export as a CSV or JSON download.
	 */
	public static function handle_export() {
		self::verify_manager_request( 'bmig_export_redirects' );

		$format = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce verified in self::verify_manager_request() above.
		$rows   = BMIG_Redirect::export_rows();

		nocache_headers();
		if ( 'json' === $format ) {
			// Dokumen impor plugin Redirection: objek top-level berisi array "redirects".
			$data = array(
				'plugin'    => array(
					'version' => BMIG_VERSION,
					'date'    => gmdate( 'r' ),
				),
				'redirects' => array(),
			);
			foreach ( $rows as $row ) {
				$data['redirects'][] = array(
					'url'         => $row[0],
					'match_type'  => 'url',
					'action_code' => 301,
					'action_type' => 'url',
					'action_data' => array( 'url' => $row[1] ),
					'regex'       => false,
					'enabled'     => true,
				);
			}
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="bmig-redirects.json"' );
			echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bmig-redirects.csv"' );
		$out = fopen( 'php://output', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array( $row[0], $row[1], '0', '301' ), ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streamed CSV to php://output; WP_Filesystem cannot write to output streams.
		exit;
	}
}
