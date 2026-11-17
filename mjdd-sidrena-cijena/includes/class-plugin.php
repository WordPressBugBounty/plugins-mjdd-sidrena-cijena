<?php
/**
 * Bootstrap the Sidrena cijena plugin.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

require_once SIDRENA_CIJENA_PATH . 'includes/class-settings.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-i18n.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-money.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-fields.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-catalog.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-upit.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-poslovnice.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-feed-rows.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-feed-files.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-archive.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-feed-routes.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-product-fields.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-admin-save.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-admin.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-export.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-import-parse.php';
require_once SIDRENA_CIJENA_PATH . 'includes/class-bulk-import.php';

/**
 * Main plugin controller.
 */
final class Sidrena_Cijena_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin after plugins_loaded.
	 */
	public static function init() {
		load_plugin_textdomain(
			'mjdd-sidrena-cijena',
			false,
			dirname( plugin_basename( SIDRENA_CIJENA_FILE ) ) . '/languages'
		);

		require_once SIDRENA_CIJENA_PATH . 'includes/class-frontend.php';

		if ( self::woocommerce_active() ) {
			require_once SIDRENA_CIJENA_PATH . 'includes/class-product-meta.php';
			require_once SIDRENA_CIJENA_PATH . 'includes/class-activator.php';
			require_once SIDRENA_CIJENA_PATH . 'includes/class-compat.php';
			require_once SIDRENA_CIJENA_PATH . 'includes/class-import-export.php';
		}

		self::instance();
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Capability that can manage plugin settings.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		return self::woocommerce_active() ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Current user may manage the plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::manage_capability() );
	}

	/**
	 * Write an exception to the WooCommerce log, or to the PHP error log.
	 *
	 * @param Throwable $exception Caught error. The message is not shown to visitors.
	 */
	public static function log_exception( $exception ) {
		if ( ! $exception instanceof Throwable ) {
			return;
		}

		$message = 'Sidrena cijena: ' . $exception->getMessage();
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'sidrena-cijena' ) );
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	private function __construct() {
		Sidrena_Cijena_Settings::instance();
		Sidrena_Cijena_I18n::instance();
		Sidrena_Cijena_Catalog::instance();
		Sidrena_Cijena_Admin::instance();
		Sidrena_Cijena_Export::instance();
		Sidrena_Cijena_Bulk_Import::instance();
		Sidrena_Cijena_Frontend::instance();

		if ( ! self::woocommerce_active() ) {
			return;
		}

		add_action( 'sidrena_cijena_snapshot_batch', array( 'Sidrena_Cijena_Activator', 'process_batch' ) );

		Sidrena_Cijena_Product_Meta::instance();
		Sidrena_Cijena_Compat::instance();
		Sidrena_Cijena_Import_Export::instance();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SIDRENA_CIJENA_PATH . 'includes/class-cli.php';
			WP_CLI::add_command( 'sidrena-cijena', 'Sidrena_Cijena_CLI' );
		}
	}

	/**
	 * Activation: defaults. Sidrena prices stay empty until someone fills them.
	 */
	public static function activate() {
		Sidrena_Cijena_Settings::add_defaults();

		if ( self::woocommerce_active() ) {
			require_once SIDRENA_CIJENA_PATH . 'includes/class-activator.php';
			Sidrena_Cijena_Activator::queue_fill_prompt();
		}

		Sidrena_Cijena_Export::instance()->register_rewrite();
		Sidrena_Cijena_Export::instance()->sync_daily_schedule();
		Sidrena_Cijena_Export::instance()->queue_public_xml();
		flush_rewrite_rules();
		update_option( Sidrena_Cijena_Export::REWRITE_OPTION, Sidrena_Cijena_Export::rewrite_fingerprint(), false );
	}

	/**
	 * Deactivation: clear scheduled jobs only. Product meta stays.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'sidrena_cijena_snapshot_batch' );
		wp_clear_scheduled_hook( Sidrena_Cijena_Export::DAILY_HOOK );
		wp_clear_scheduled_hook( Sidrena_Cijena_Export::GENERATE_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Delete all plugin data: product meta, options, uploads, transients, cron.
	 */
	public static function purge_all_data() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
				SIDRENA_CIJENA_META_PRICE,
				SIDRENA_CIJENA_META_DATE,
				SIDRENA_CIJENA_META_CJM,
				SIDRENA_CIJENA_META_CJM_SUFFIX,
				SIDRENA_CIJENA_META_OBLIK,
				SIDRENA_CIJENA_META_JEDINICA,
				SIDRENA_CIJENA_META_JEDINICA_CIJENA,
				SIDRENA_CIJENA_META_NETO,
				SIDRENA_CIJENA_META_VRSTA,
				SIDRENA_CIJENA_META_ISKLJUCI
			)
		);

		delete_option( 'sidrena_cijena_settings' );
		delete_option( 'sidrena_cijena_snapshot_pending' );
		delete_option( 'sidrena_cijena_snapshot_page' );
		delete_option( 'sidrena_cijena_snapshot_offset' );
		delete_option( 'sidrena_cijena_snapshot_child' );
		delete_option( 'sidrena_cijena_fill_prompt' );
		delete_option( Sidrena_Cijena_Settings::PREFIX_CHOICE_OPTION );
		delete_option( 'sidrena_cijena_followup_13_11' );
		delete_option( 'sidrena_cijena_public_xml' );
		delete_option( 'sidrena_cijena_rewrite_ver' );
		delete_option( Sidrena_Cijena_Catalog::OPTION_KEY );
		delete_option( Sidrena_Cijena_Upit::OPTION_KEY );
		delete_option( Sidrena_Cijena_Poslovnice::OPTION_KEY );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_sidrena_cijena_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_sidrena_cijena_' ) . '%'
			)
		);

		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			self::delete_directory( trailingslashit( $uploads['basedir'] ) . 'sidrena-cijena' );
		}

		wp_clear_scheduled_hook( 'sidrena_cijena_snapshot_batch' );
		wp_clear_scheduled_hook( Sidrena_Cijena_Export::DAILY_HOOK );
		wp_clear_scheduled_hook( Sidrena_Cijena_Export::GENERATE_HOOK );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::delete_directory( $path );
			} elseif ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}
}
