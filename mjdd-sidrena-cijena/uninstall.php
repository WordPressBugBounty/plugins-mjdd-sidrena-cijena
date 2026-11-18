<?php
/**
 * Uninstall Sidrena cijena.
 *
 * Product meta is kept on purpose: it is the legal snapshot of the
 * reference-date price and must survive a mistaken uninstall.
 *
 * @package Sidrena_Cijena
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sidrena_cijena_settings' );
delete_option( 'sidrena_cijena_snapshot_pending' );
delete_option( 'sidrena_cijena_snapshot_page' );
delete_option( 'sidrena_cijena_snapshot_offset' );
delete_option( 'sidrena_cijena_snapshot_child' );
delete_option( 'sidrena_cijena_fill_prompt' );
delete_option( 'sidrena_cijena_prefix_choice' );
delete_option( 'sidrena_cijena_followup_13_11' );
delete_option( 'sidrena_cijena_public_xml' );
delete_option( 'sidrena_cijena_rewrite_ver' );
delete_option( 'sidrena_cijena_poslovnice' );
delete_option( 'sidrena_cijena_catalog' );
delete_option( 'sidrena_cijena_upit' );
delete_option( 'sidrena_cijena_rebuild_lock' );
delete_option( 'sidrena_cijena_feed_job' );

$sidrena_cijena_uploads = wp_upload_dir( null, false );
if ( empty( $sidrena_cijena_uploads['error'] ) && ! empty( $sidrena_cijena_uploads['basedir'] ) ) {
	$sidrena_cijena_base = trailingslashit( $sidrena_cijena_uploads['basedir'] ) . 'sidrena-cijena';
	$sidrena_cijena_live = glob( $sidrena_cijena_base . '/sidrena-cijena*' );
	if ( is_array( $sidrena_cijena_live ) ) {
		foreach ( $sidrena_cijena_live as $sidrena_cijena_file ) {
			if ( is_file( $sidrena_cijena_file ) ) {
				wp_delete_file( $sidrena_cijena_file );
			}
		}
	}

	$sidrena_cijena_dirs = array( $sidrena_cijena_base . '/arhiva', $sidrena_cijena_base . '/imports' );
	foreach ( $sidrena_cijena_dirs as $sidrena_cijena_dir ) {
		if ( ! is_dir( $sidrena_cijena_dir ) ) {
			continue;
		}

		$sidrena_cijena_staged = glob( $sidrena_cijena_dir . '/*' );
		if ( is_array( $sidrena_cijena_staged ) ) {
			foreach ( $sidrena_cijena_staged as $sidrena_cijena_staged_file ) {
				if ( is_file( $sidrena_cijena_staged_file ) ) {
					wp_delete_file( $sidrena_cijena_staged_file );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $sidrena_cijena_dir );
	}

	if ( is_dir( $sidrena_cijena_base ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $sidrena_cijena_base );
	}
}

wp_clear_scheduled_hook( 'sidrena_cijena_snapshot_batch' );
wp_clear_scheduled_hook( 'sidrena_cijena_daily_xml' );
wp_clear_scheduled_hook( 'sidrena_cijena_generate_xml' );
