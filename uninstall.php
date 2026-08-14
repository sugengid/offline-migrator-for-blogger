<?php
/**
 * Bersihkan opsi milik plugin saat uninstall. Konten hasil impor (post,
 * halaman, komentar, attachment) sengaja dipertahankan karena sudah menjadi
 * bagian situs; hanya state plugin yang dihapus.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bm_options = array(
	'bm_job',              // BM_Ajax::OPTION_JOB
	'bm_img_map',          // BM_Media::OPTION_MAP
	'bm_permalink_mode',   // BM_Redirect::OPTION_MODE
	'bm_rewrite_flushed',  // BM_Redirect::OPTION_FLUSHED
	'bm_mode_a_rules',     // BM_Redirect::OPTION_MODE_A_RULES
);

foreach ( $bm_options as $bm_option ) {
	delete_option( $bm_option );
}

flush_rewrite_rules();
