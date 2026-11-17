<?php
/**
 * Fill empty sidrena cijena from regular price (eval-file fallback).
 *
 * Prefer: wp sidrena-cijena fill
 *
 * wp --allow-root --path=/var/www/pikabooshop.com eval-file \
 *   wp-content/plugins/sidrena-cijena/bin/fill.php
 *
 * Flags in eval-file args: --dry-run --safe --today batch-size=250
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! class_exists( 'Sidrena_Cijena_Activator' ) ) {
	WP_CLI::error( 'Plugin Sidrena cijena is not active.' );
}

$cli_args  = isset( $args ) && is_array( $args ) ? $args : array();
$dry_run   = in_array( '--dry-run', $cli_args, true );
$safe      = in_array( '--safe', $cli_args, true );
$today     = in_array( '--today', $cli_args, true );
$batch_size = 250;

foreach ( $cli_args as $arg ) {
	if ( 0 === strpos( (string) $arg, 'batch-size=' ) ) {
		$batch_size = max( 1, absint( substr( (string) $arg, 11 ) ) );
	}
}

$cli = new Sidrena_Cijena_CLI();
$cli->fill(
	array(),
	array(
		'dry-run'    => $dry_run,
		'safe'       => $safe,
		'today'      => $today,
		'batch-size' => $batch_size,
	)
);
