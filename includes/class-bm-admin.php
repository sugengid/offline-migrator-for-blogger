<?php
/**
 * Admin wizard page: menu, render, and asset enqueue.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Admin {

	const MENU_SLUG = 'bloggermigrator';

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_bm_export_redirects', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Add the top-level BloggerMigrator menu.
	 */
	public static function register_menu() {
		add_menu_page(
			'BloggerMigrator',
			'BloggerMigrator',
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
			'bm-admin',
			BM_PLUGIN_URL . 'assets/bm-admin.css',
			array(),
			(string) filemtime( BM_PLUGIN_DIR . 'assets/bm-admin.css' )
		);
		wp_enqueue_script(
			'bm-admin',
			BM_PLUGIN_URL . 'assets/bm-admin.js',
			array(),
			(string) filemtime( BM_PLUGIN_DIR . 'assets/bm-admin.js' ),
			true
		);
		wp_localize_script(
			'bm-admin',
			'bmAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'bm_nonce' ),
				'homeUrl'    => home_url( '/' ),
				'maxZipMb'   => (int) apply_filters( 'bm_max_zip_mb', 512 ),
				'phpLimitMb' => self::php_upload_limit_mb(),
				'job'        => BM_Ajax::job_summary(),
				'strings'    => array(
					'requestFailed'    => __( 'Request gagal. Periksa koneksi lalu coba lagi.', 'bloggermigrator' ),
					'pickZip'          => __( 'Pilih file arsip Takeout dulu.', 'bloggermigrator' ),
					'zipTooLarge'      => __( 'Arsip melebihi batas plugin.', 'bloggermigrator' ),
					'zipOverPhpLimit'  => __( 'Ukuran arsip melebihi batas upload PHP server. Upload hampir pasti gagal; kecilkan file atau naikkan upload_max_filesize dan post_max_size.', 'bloggermigrator' ),
					'noSource'         => __( 'Sumber Takeout belum dipilih, ulangi dari langkah 1.', 'bloggermigrator' ),
					'confirmMode'      => __( 'Mode ini mengubah struktur permalink situs. URL lama Blogger dialihkan dengan redirect 301 otomatis. Mode bisa diganti nanti dengan menjalankan ulang migrasi memakai mode lain. Lanjutkan?', 'bloggermigrator' ),
					'confirmPending'   => __( 'Job sebelumnya belum selesai. Mulai dari awal?', 'bloggermigrator' ),
					'confirmReset'     => __( 'State job akan dihapus dan wizard kembali ke awal. Konten hasil impor tidak ikut terhapus. Lanjutkan?', 'bloggermigrator' ),
					'jobFinished'      => __( 'Migrasi selesai.', 'bloggermigrator' ),
					'startFailed'      => __( 'Gagal memulai job', 'bloggermigrator' ),
					'resetFailed'      => __( 'Gagal reset job', 'bloggermigrator' ),
					'summaryFailed'    => __( 'Gagal memuat ringkasan blog', 'bloggermigrator' ),
					'reportEmpty'      => __( 'Migrasi selesai tanpa konten baru. Kemungkinan semua konten sudah pernah diimpor atau feed tidak berisi entry.', 'bloggermigrator' ),
					'batchFailed'      => __( 'Batch gagal 5x berturut-turut, migrasi dihentikan. Muat ulang halaman lalu klik Lanjutkan job untuk melanjutkan dari batch terakhir.', 'bloggermigrator' ),
					'batchRetry'       => __( 'Batch error, coba ulang', 'bloggermigrator' ),
					'resumeJob'        => __( 'Lanjutkan job', 'bloggermigrator' ),
					'resumedTo'        => __( 'Melanjutkan job dari fase:', 'bloggermigrator' ),
					'extractDone'      => __( 'Ekstrak selesai, blog ditemukan:', 'bloggermigrator' ),
					'uploadFailed'     => __( 'Upload gagal', 'bloggermigrator' ),
					'phaseContent'     => __( 'Mengimpor konten...', 'bloggermigrator' ),
					'phaseMedia'       => __( 'Mengimpor gambar...', 'bloggermigrator' ),
					'phaseReplace'     => __( 'Menyiapkan konten...', 'bloggermigrator' ),
					'phaseRedirect'    => __( 'Menyiapkan redirect...', 'bloggermigrator' ),
					'confirmCancel'    => __( 'Batal', 'bloggermigrator' ),
					'confirmProceed'   => __( 'Lanjutkan', 'bloggermigrator' ),
					'confirmOk'        => __( 'OK', 'bloggermigrator' ),
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
			wp_die( esc_html__( 'Akses ditolak.', 'bloggermigrator' ) );
		}

		$allow_path = (bool) apply_filters( 'bm_allow_path_input', false );
		$job        = BM_Ajax::job_summary();
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
		<div class="wrap bm-wrap">
			<h1>BloggerMigrator</h1>

			<?php if ( ! $job ) : ?>
				<div class="bm-intro">
					<p><?php esc_html_e( 'Migrasikan blog Blogger ke WordPress hanya dari file backup Google Takeout, tanpa perlu blog online.', 'bloggermigrator' ); ?></p>
					<ul class="bm-intro-steps">
						<li>
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Upload arsip Takeout', 'bloggermigrator' ); ?></strong><br>
							<?php esc_html_e( 'Ekspor Blogger dari Google Takeout, lalu upload arsipnya (zip atau tgz) di langkah 1.', 'bloggermigrator' ); ?>
						</li>
						<li>
							<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Pilih blog & mode', 'bloggermigrator' ); ?></strong><br>
							<?php esc_html_e( 'Pilih blog yang akan dimigrasi dan mode permalink tujuan.', 'bloggermigrator' ); ?>
						</li>
						<li>
							<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Jalankan migrasi', 'bloggermigrator' ); ?></strong><br>
							<?php esc_html_e( 'Konten, komentar, dan gambar diproses bertahap sampai selesai.', 'bloggermigrator' ); ?>
						</li>
					</ul>
				</div>
			<?php endif; ?>

			<ol class="bm-tabs"<?php echo $done ? ' hidden' : ''; ?>>
				<li class="bm-tab bm-active" data-step="1">1. <?php esc_html_e( 'Upload Takeout', 'bloggermigrator' ); ?></li>
				<li class="bm-tab" data-step="2">2. <?php esc_html_e( 'Pilih Blog & Mode', 'bloggermigrator' ); ?></li>
				<li class="bm-tab" data-step="3">3. <?php esc_html_e( 'Jalankan Migrasi', 'bloggermigrator' ); ?></li>
			</ol>

			<p class="bm-status" id="bm-status" hidden></p>

			<div class="bm-step" id="bm-step-1"<?php echo $done ? ' hidden' : ''; ?>>
				<h2><?php esc_html_e( 'Langkah 1: Upload arsip Takeout (zip/tgz)', 'bloggermigrator' ); ?></h2>
				<form id="bm-upload-form">
					<p>
						<input type="file" id="bm-zip" accept=".zip,.tgz,.tar.gz,application/zip,application/gzip">
					</p>
					<?php if ( $limit_mb > 0 ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %d: upload size limit in MB */
								esc_html__( 'Batas upload server saat ini: %d MB. Arsip yang lebih besar akan ditolak PHP sebelum sempat diproses.', 'bloggermigrator' ),
								intval( $limit_mb )
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( $allow_path ) : ?>
						<p>
							<label for="bm-takeout-path"><?php esc_html_e( 'Atau path folder Takeout (khusus dev):', 'bloggermigrator' ); ?></label><br>
							<input type="text" id="bm-takeout-path" class="regular-text" placeholder="/path/to/Takeout">
						</p>
					<?php endif; ?>
					<p>
						<button type="submit" class="button button-primary" id="bm-upload-btn">
							<?php esc_html_e( 'Upload & Ekstrak', 'bloggermigrator' ); ?>
						</button>
						<span class="spinner" id="bm-upload-spinner"></span>
					</p>
				</form>
			</div>

			<div class="bm-step" id="bm-step-2" hidden>
				<h2><?php esc_html_e( 'Langkah 2: Pilih blog dan mode permalink', 'bloggermigrator' ); ?></h2>
				<p>
					<label for="bm-blog"><?php esc_html_e( 'Blog yang dimigrasi:', 'bloggermigrator' ); ?></label><br>
					<select id="bm-blog"></select>
				</p>
				<fieldset class="bm-modes">
					<legend><?php esc_html_e( 'Mode permalink:', 'bloggermigrator' ); ?></legend>
					<p>
						<label>
							<input type="radio" name="bm_mode" value="a" checked>
							<strong><?php esc_html_e( 'Mode A', 'bloggermigrator' ); ?></strong>:
							<?php esc_html_e( 'pertahankan URL asli Blogger (/2026/03/slug.html).', 'bloggermigrator' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="radio" name="bm_mode" value="b">
							<strong><?php esc_html_e( 'Mode B', 'bloggermigrator' ); ?></strong>:
							<?php esc_html_e( 'URL baru di root (/slug/) dengan redirect 301 dari URL lama.', 'bloggermigrator' ); ?>
						</label>
					</p>
				</fieldset>
				<fieldset class="bm-modes">
					<legend><?php esc_html_e( 'Gambar postingan:', 'bloggermigrator' ); ?></legend>
					<p>
						<label>
							<input type="checkbox" id="bm-media-album" checked>
							<?php esc_html_e( 'Import gambar dari album Takeout (offline, dari folder Albums hasil ekstrak arsip).', 'bloggermigrator' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" id="bm-media-external">
							<?php esc_html_e( 'Import gambar dari URL eksternal (online: URL yang masih hidup di-download ke media library).', 'bloggermigrator' ); ?>
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'Kosongkan keduanya untuk tidak mengimport gambar.', 'bloggermigrator' ); ?>
					</p>
				</fieldset>
				<p>
					<label>
						<input type="checkbox" id="bm-to-blocks">
						<?php esc_html_e( 'Konversi HTML konten ke Gutenberg blocks.', 'bloggermigrator' ); ?>
					</label>
				</p>
				<p>
					<button type="button" class="button button-primary" id="bm-config-btn">
						<?php esc_html_e( 'Lanjut', 'bloggermigrator' ); ?>
					</button>
				</p>
			</div>

			<div class="bm-step" id="bm-step-3" hidden>
				<h2><?php esc_html_e( 'Langkah 3: Jalankan migrasi', 'bloggermigrator' ); ?></h2>
				<div class="bm-summary" id="bm-summary" hidden>
					<h3><?php esc_html_e( 'Ringkasan blog', 'bloggermigrator' ); ?></h3>
					<p class="bm-summary-blog">
						<?php esc_html_e( 'Blog:', 'bloggermigrator' ); ?>
						<strong id="bm-sum-blog"></strong>
					</p>
					<div class="bm-summary-grid">
						<div class="bm-summary-item">
							<span class="bm-summary-num" id="bm-sum-posts">0</span>
							<span class="bm-summary-label"><?php esc_html_e( 'Postingan', 'bloggermigrator' ); ?></span>
						</div>
						<div class="bm-summary-item">
							<span class="bm-summary-num" id="bm-sum-pages">0</span>
							<span class="bm-summary-label"><?php esc_html_e( 'Halaman', 'bloggermigrator' ); ?></span>
						</div>
						<div class="bm-summary-item">
							<span class="bm-summary-num" id="bm-sum-comments">0</span>
							<span class="bm-summary-label"><?php esc_html_e( 'Komentar', 'bloggermigrator' ); ?></span>
						</div>
						<div class="bm-summary-item">
							<span class="bm-summary-num" id="bm-sum-images">0</span>
							<span class="bm-summary-label"><?php esc_html_e( 'Gambar', 'bloggermigrator' ); ?></span>
						</div>
					</div>
				</div>
				<div class="bm-progress" id="bm-progress" hidden>
					<div class="bm-progress-bar" id="bm-progress-bar"></div>
				</div>
				<p id="bm-progress-label" hidden>0%</p>
				<p>
					<button type="button" class="button button-primary" id="bm-start-btn">
						<?php esc_html_e( 'Mulai Migrasi', 'bloggermigrator' ); ?>
					</button>
				</p>
			</div>

			<div class="bm-step bm-report" id="bm-report"<?php echo $done ? '' : ' hidden'; ?>>
				<h2><?php esc_html_e( 'Laporan Migrasi', 'bloggermigrator' ); ?></h2>
				<div class="notice notice-info inline" id="bm-report-empty"<?php echo $done && $r_imported > 0 ? ' hidden' : ''; ?>>
					<p><?php esc_html_e( 'Migrasi selesai tanpa konten baru. Kemungkinan semua konten sudah pernah diimpor atau feed tidak berisi entry.', 'bloggermigrator' ); ?></p>
				</div>
				<table class="widefat striped bm-report-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Post diimport', 'bloggermigrator' ); ?></th>
							<td id="bm-r-posts"><?php echo esc_html( $r_posts ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Halaman diimport', 'bloggermigrator' ); ?></th>
							<td id="bm-r-pages"><?php echo esc_html( $r_pages ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Komentar diimport', 'bloggermigrator' ); ?></th>
							<td id="bm-r-comments"><?php echo esc_html( $r_comments ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Attachment dibuat', 'bloggermigrator' ); ?></th>
							<td id="bm-r-attachments"><?php echo esc_html( $r_attach ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'URL gambar cocok', 'bloggermigrator' ); ?></th>
							<td id="bm-r-matched"><?php echo esc_html( $r_matched ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gambar dari album', 'bloggermigrator' ); ?></th>
							<td id="bm-r-album"><?php echo esc_html( $r_album ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gambar dari URL eksternal', 'bloggermigrator' ); ?></th>
							<td id="bm-r-external"><?php echo esc_html( $r_ext ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gambar gagal diimport', 'bloggermigrator' ); ?></th>
							<td id="bm-r-failed-count"><?php echo esc_html( $r_failed ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Konten diupdate (replace URL)', 'bloggermigrator' ); ?></th>
							<td id="bm-r-posts-updated"><?php echo esc_html( $r_updated ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Mode redirect aktif', 'bloggermigrator' ); ?></th>
							<td id="bm-r-mode"><?php echo esc_html( $r_mode ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Durasi', 'bloggermigrator' ); ?></th>
							<td id="bm-r-duration"><?php echo esc_html( $r_dur ); ?></td>
						</tr>
					</tbody>
				</table>
				<div class="notice notice-warning inline" id="bm-report-warning"<?php echo $done && $r_warn ? '' : ' hidden'; ?>>
					<p>
						<?php esc_html_e( 'Mode A memakai URL asli Blogger untuk postingan (contoh: namablog.com/2026/03/judul.html). Halaman statis tetap diarahkan otomatis dari /p/judul.html ke /judul/ karena strukturnya berbeda.', 'bloggermigrator' ); ?>
					</p>
				</div>
				<div class="notice notice-warning inline" id="bm-report-conflicts"<?php echo $done && ! empty( $r_conflicts ) ? '' : ' hidden'; ?>>
					<p>
						<?php esc_html_e( 'Slug berikut bentrok dengan halaman statis yang sudah ada. Halaman menang di routing; redirect dari URL Blogger tetap dipasang.', 'bloggermigrator' ); ?>
					</p>
					<ul id="bm-r-conflicts">
						<?php foreach ( $r_conflicts as $slug ) : ?>
							<li><?php echo esc_html( '/' . $slug . '/' ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<h3><?php esc_html_e( 'URL gambar tidak ditemukan di album', 'bloggermigrator' ); ?></h3>
				<p id="bm-r-unmatched-empty"<?php echo $done && ! empty( $r_unmatched ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Semua URL gambar berhasil dipetakan.', 'bloggermigrator' ); ?></p>
				<ul id="bm-r-unmatched">
					<?php foreach ( array_keys( $r_unmatched ) as $url ) : ?>
						<li><?php echo esc_html( $url ); ?></li>
					<?php endforeach; ?>
				</ul>
				<h3><?php esc_html_e( 'URL gambar gagal diimport', 'bloggermigrator' ); ?></h3>
				<p id="bm-r-failed-empty"<?php echo $done && ! empty( $r_failed_list ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Tidak ada gambar yang gagal diimport.', 'bloggermigrator' ); ?></p>
				<ul id="bm-r-failed">
					<?php foreach ( array_keys( $r_failed_list ) as $url ) : ?>
						<li><?php echo esc_html( $url ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Lihat situs', 'bloggermigrator' ); ?>
					</a>
					<button type="button" class="button" id="bm-reset-btn">
						<?php esc_html_e( 'Mulai migrasi baru', 'bloggermigrator' ); ?>
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
		if ( 0 === BM_Redirect::data_count() ) {
			return;
		}

		$map = BM_Redirect::export_map();

		$export_csv  = wp_nonce_url( admin_url( 'admin-post.php?action=bm_export_redirects&format=csv' ), 'bm_export_redirects' );
		$export_json = wp_nonce_url( admin_url( 'admin-post.php?action=bm_export_redirects&format=json' ), 'bm_export_redirects' );
		?>
		<div class="bm-step bm-redirect-manager">
			<h2><?php esc_html_e( 'Export redirect', 'bloggermigrator' ); ?></h2>

			<p>
				<?php
				printf(
					/* translators: %d: total redirect entries */
					esc_html__( 'Total redirect: %d.', 'bloggermigrator' ),
					count( $map )
				);
				?>
			</p>
			<div class="bm-redirect-scroll">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL lama', 'bloggermigrator' ); ?></th>
							<th><?php esc_html_e( 'URL baru', 'bloggermigrator' ); ?></th>
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

			<p><?php esc_html_e( 'Unduh semua redirect untuk diimport ke plugin Redirection. Setelah itu plugin BloggerMigrator bisa dihapus dan URL redirect tetap bekerja. Atau tetap aktifkan plugin ini jika tidak ingin menggunakan plugin Redirection.', 'bloggermigrator' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( $export_csv ); ?>"><?php esc_html_e( 'Export redirect CSV', 'bloggermigrator' ); ?></a>
				<a class="button" href="<?php echo esc_url( $export_json ); ?>"><?php esc_html_e( 'Export redirect JSON', 'bloggermigrator' ); ?></a>
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
			wp_die( esc_html__( 'Akses ditolak.', 'bloggermigrator' ) );
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
		self::verify_manager_request( 'bm_export_redirects' );

		$format = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce verified in self::verify_manager_request() above.
		$rows   = BM_Redirect::export_rows();

		nocache_headers();
		if ( 'json' === $format ) {
			$data = array();
			foreach ( $rows as $row ) {
				$data[] = array(
					'source' => $row[0],
					'target' => $row[1],
					'code'   => 301,
					'regex'  => false,
				);
			}
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="bm-redirects.json"' );
			echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bm-redirects.csv"' );
		$out = fopen( 'php://output', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array( $row[0], $row[1], '0', '301' ), ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streamed CSV to php://output; WP_Filesystem cannot write to output streams.
		exit;
	}
}
