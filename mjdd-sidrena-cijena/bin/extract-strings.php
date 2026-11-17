<?php
/**
 * Extract gettext literals for mjdd-sidrena-cijena.
 *
 * @package Sidrena_Cijena
 */

$root = dirname( __DIR__ );
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$strings = array();

foreach ( $files as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$path = $file->getPathname();
	if ( ! preg_match( '/\.(php|js)$/', $path ) ) {
		continue;
	}
	if ( false !== strpos( $path, DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}
	$code = file_get_contents( $path );
	if ( ! is_string( $code ) || false === strpos( $code, 'mjdd-sidrena-cijena' ) ) {
		continue;
	}
	if ( preg_match_all( "/(?:__|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_e)\\(\\s*(['\"])(.*?)\\1\\s*,\\s*(['\"])mjdd-sidrena-cijena\\3/s", $code, $matches ) ) {
		foreach ( $matches[2] as $raw ) {
			$strings[ stripcslashes( $raw ) ] = true;
		}
	}
}

$out = array_keys( $strings );
sort( $out, SORT_STRING );
$dir = $root . '/languages';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir );
}
file_put_contents( $dir . '/strings.json', json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
echo count( $out ) . PHP_EOL;
