<?php
/**
 * Bersihkan opsi milik plugin saat uninstall. Konten hasil impor (post,
 * halaman, komentar, attachment) sengaja dipertahankan karena sudah menjadi
 * bagian situs; hanya state plugin yang dihapus.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bmig_options = array(
	'bmig_job',              // BMIG_Ajax::OPTION_JOB
	'bmig_upload',           // BMIG_Ajax::OPTION_UPLOAD
	'bmig_img_map',          // BMIG_Media::OPTION_MAP
	'bmig_media_inventory',  // BMIG_Media::OPTION_INVENTORY
	'bmig_permalink_mode',   // BMIG_Redirect::OPTION_MODE
	'bmig_rewrite_flushed',  // BMIG_Redirect::OPTION_FLUSHED
	'bmig_mode_a_rules',     // BMIG_Redirect::OPTION_MODE_A_RULES
);

foreach ( $bmig_options as $bmig_option ) {
	delete_option( $bmig_option );
}

flush_rewrite_rules();
