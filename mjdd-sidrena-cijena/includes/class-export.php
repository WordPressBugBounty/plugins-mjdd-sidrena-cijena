<?php
/**
 * XML / CSV catalog and missing-price exports.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloadable pricelist export.
 */
class Sidrena_Cijena_Export {

	const BATCH_SIZE       = 100;
	const PUBLIC_STEM      = 'sidrena-cijena';
	const PUBLIC_OPTION    = 'sidrena_cijena_public_xml';
	const DAILY_HOOK       = 'sidrena_cijena_daily_xml';
	const GENERATE_HOOK    = 'sidrena_cijena_generate_xml';
	const DAILY_HOUR       = 10;
	const DAILY_MINUTE     = 0;
	const ARCHIVE_SLUG     = 'arhiva-sidrene-cijene';
	const CJENIK_SLUG      = 'sidrena-cjenik';
	const CJENIK_DOM_TOKEN = 'sidrena-cjenik-dom-7f3a9c';
	const ARCHIVE_MAX      = 30;
	const REWRITE_OPTION   = 'sidrena_cijena_rewrite_ver';
	const REWRITE_VERSION  = '12';
	const REBUILD_LOCK     = 'sidrena_cijena_rebuild_lock';
	const REBUILD_COOLDOWN = 'sidrena_cijena_rebuild_cooldown';
	const REBUILD_LOCK_TTL = 600;
	const CHANGE_DELAY     = 60;

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * While a feed is being written, product hooks must not schedule another run.
	 *
	 * @var bool
	 */
	private $suppress_change_queue = false;

	/**
	 * Token of the rebuild lock held by this request.
	 *
	 * @var string
	 */
	private $rebuild_lock_token = '';

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook download, regenerate, rewrite, and public serve.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrites' ) );
		add_action( 'init', array( $this, 'prepare_manual_rebuild' ), 1 );
		add_action( 'init', array( $this, 'sync_daily_schedule' ), 20 );
		add_action( 'shutdown', array( $this, 'release_owned_rebuild_lock' ) );
		add_action( 'init', array( $this, 'maybe_ensure_public_xml_on_init' ), 30 );
		add_action( self::DAILY_HOOK, array( $this, 'run_daily_regenerate' ) );
		add_action( self::GENERATE_HOOK, array( $this, 'run_queued_generate' ) );

		if ( Sidrena_Cijena_Plugin::woocommerce_active() ) {
			add_action( 'woocommerce_new_product', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_update_product', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_product_set_stock', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue_feed_after_change' ) );
			add_action( 'trashed_post', array( $this, 'queue_feed_after_product_post' ) );
			add_action( 'untrashed_post', array( $this, 'queue_feed_after_product_post' ) );
			add_action( 'before_delete_post', array( $this, 'queue_feed_after_product_post' ) );
		}
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_public_xml' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_serve_public_xml' ), 0 );
		add_action( 'admin_init', array( $this, 'handle_download' ) );
	}

	/**
	 * Pretty URLs: /sidrena-cijena.xml|.csv, /arhiva-sidrene-cijene, /sidrena-cjenik
	 */
	public function register_rewrite() {
		Sidrena_Cijena_Feed_Routes::instance()->register_rewrite();
	}

	/**
	 * Rewrite option value including Woo vs standalone mode.
	 *
	 * @return string
	 */
	public static function rewrite_fingerprint() {
		return Sidrena_Cijena_Feed_Routes::rewrite_fingerprint();
	}

	/**
	 * Flush permalinks once after adding archive routes.
	 */
	public function maybe_flush_rewrites() {
		Sidrena_Cijena_Feed_Routes::instance()->maybe_flush_rewrites();
	}

	/**
	 * Expose the public XML and archive query vars.
	 *
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function register_query_var( $vars ) {
		return Sidrena_Cijena_Feed_Routes::instance()->register_query_var($vars);
	}

	/**
	 * Stream a file when the settings export form is submitted.
	 */

	public function handle_download() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_action'] ) );
		if ( ! in_array( $action, array( 'export_cjenik', 'export_missing', 'export_template' ), true ) ) {
			return;
		}

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_export', 'sidrena_cijena_export_nonce' );

		if ( 'export_template' === $action ) {
			Sidrena_Cijena_Feed_Files::instance()->send_import_template();
			return;
		}

		$format       = ( isset( $_POST['sidrena_cijena_export_format'] ) && 'xml' === $_POST['sidrena_cijena_export_format'] ) ? 'xml' : 'csv';
		$missing_only = ( 'export_missing' === $action );
		$public       = ! $missing_only;
		$rows         = $this->collect_rows( $missing_only, $public );
		$stamp        = gmdate( 'Y-m-d' );
		$basename     = $missing_only ? 'sidrena-nedostaje-' . $stamp : 'sidrena-cjenik-' . $stamp;

		if ( 'xml' === $format ) {
			Sidrena_Cijena_Feed_Files::instance()->send_xml( $basename . '.xml', $rows, $missing_only, $public );
		} else {
			Sidrena_Cijena_Feed_Files::instance()->send_csv( $basename . '.csv', $rows, $public );
		}
	}

	/**
	 * Collect catalog rows: name, sku, prices.
	 *
	 * @param bool $missing_only Only products/variations without sidrena price.
	 * @param bool $public       Published catalog for the public XML (adds id/url).
	 * @return array<int, array<string, string>>
	 */
	public function collect_rows( $missing_only = false, $public = false, $only_ids = null, $catalog_scope = null ) {
		return Sidrena_Cijena_Feed_Rows::instance()->collect_rows($missing_only, $public, $only_ids, $catalog_scope);
	}

	/**
	 * xml or csv from settings.
	 *
	 * @return string
	 */
	public function export_format() {
		return Sidrena_Cijena_Feed_Files::instance()->export_format();
	}

	/**
	 * Live public filename for a format.
	 *
	 * @param string $format xml|csv.
	 * @return string
	 */
	public function live_filename( $format = '', $oznaka = '' ) {
		return Sidrena_Cijena_Feed_Files::instance()->live_filename($format, $oznaka);
	}

	/**
	 * Archive name from the 18 September 2026 ministry example:
	 * prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.xml
	 *
	 * Spaces, hyphens, and the typed case stay. Date is DD.MM.YYYY and time
	 * is HH:MM in the site timezone. A Windows disk cannot store the colon,
	 * so that server writes 07-45 instead.
	 *
	 * @param string              $format    xml|csv.
	 * @param int                 $timestamp Unix time of publication, 0 = now.
	 * @param int                 $pohrana   Storage sequence, 0 = next unused number (does not consume).
	 * @param array<string,mixed>|null $outlet Outlet row, or null for the single shop.
	 * @return string
	 */
	public function legal_filename( $format = '', $timestamp = 0, $pohrana = 0, $outlet = null, $kind = 'robe' ) {
		return Sidrena_Cijena_Archive::instance()->legal_filename( $format, $timestamp, $pohrana, $outlet, $kind );
	}

	/**
	 * Last published legal filename, or a preview of the next one.
	 *
	 * @param string $format xml|csv.
	 * @return string
	 */
	public function published_filename( $format = '', $oznaka = '', $kind = 'robe' ) {
		return Sidrena_Cijena_Archive::instance()->published_filename( $format, $oznaka, $kind );
	}

	/**
	 * Build an XML document string.
	 *
	 * @param array<int, array<string, string>> $rows           Rows.
	 * @param string                            $root           Root element name.
	 * @param bool                              $public_fields  Include id and url.
	 * @return string
	 */
	public function build_xml_string( $rows, $root, $public_fields = false ) {
		return Sidrena_Cijena_Feed_Files::instance()->build_xml_string($rows, $root, $public_fields);
	}

	/**
	 * Absolute directory for the public XML file.
	 *
	 * @return string
	 */
	public function get_public_dir() {
		return Sidrena_Cijena_Feed_Files::instance()->get_public_dir();
	}

	/**
	 * Absolute path of the live public feed.
	 *
	 * @param string $format xml|csv, empty = current setting.
	 * @return string
	 */
	public function get_public_path( $format = '', $oznaka = '' ) {
		return Sidrena_Cijena_Feed_Files::instance()->get_public_path($format, $oznaka);
	}

	/**
	 * Absolute path of the generated HTML table for the public page and shortcode.
	 *
	 * @param string $oznaka Outlet code, empty for the full catalog.
	 * @return string
	 */
	public function get_cjenik_html_path( $oznaka = '' ) {
		return Sidrena_Cijena_Feed_Files::instance()->get_cjenik_html_path($oznaka);
	}

	/**
	 * Public URL (pretty permalink, or query fallback).
	 *
	 * @param string $format xml|csv, empty = current setting.
	 * @return string
	 */
	public function get_public_url( $format = '', $oznaka = '', $kind = 'robe' ) {
		return Sidrena_Cijena_Feed_Files::instance()->get_public_url( $format, $oznaka, $kind );
	}

	/**
	 * Public archive index URL.
	 *
	 * @return string
	 */
	public function get_archive_url() {
		return Sidrena_Cijena_Feed_Files::instance()->get_archive_url();
	}

	/**
	 * Public HTML pricelist URL (standalone mode).
	 *
	 * @return string
	 */
	public function get_cjenik_url( $oznaka = '' ) {
		return Sidrena_Cijena_Feed_Files::instance()->get_cjenik_url( $oznaka );
	}

	/**
	 * Public URL for one archived feed file.
	 *
	 * @param string $name_or_date Legal filename or legacy d-m-Y.
	 * @param string $ext          xml|csv (legacy date URLs).
	 * @return string
	 */
	public function get_archive_file_url( $name_or_date, $ext = 'xml' ) {
		return Sidrena_Cijena_Feed_Files::instance()->get_archive_file_url($name_or_date, $ext);
	}

	/**
	 * Directory for dated archive XML files.
	 *
	 * @return string
	 */
	public function get_archive_dir() {
		return Sidrena_Cijena_Archive::instance()->get_archive_dir();
	}

	/**
	 * Copy today's live feed into the rolling 30-day archive.
	 *
	 * @return string|WP_Error Destination path or error.
	 */
	public function archive_public_xml() {
		return Sidrena_Cijena_Archive::instance()->archive_public_xml();
	}

	/**
	 * Archived feed files, newest first. Older days keep their original xml/csv type.
	 *
	 * @return array<int, array{date:string,ext:string,name:string,path:string,url:string,bytes:int}>
	 */
	public function get_archive_files() {
		return Sidrena_Cijena_Archive::instance()->get_archive_files();
	}

	/**
	 * Align the daily cron with the setting and WordPress timezone (00:05).
	 */
	public function sync_daily_schedule() {
		if ( 'yes' !== Sidrena_Cijena_Settings::instance()->get( 'daily_xml' ) ) {
			wp_clear_scheduled_hook( self::DAILY_HOOK );
			return;
		}

		$next = wp_next_scheduled( self::DAILY_HOOK );
		if ( $next && $this->timestamp_is_daily_hour( $next ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_schedule_event( $this->next_daily_timestamp(), 'daily', self::DAILY_HOOK );
	}

	/**
	 * Cron callback: rewrite the public catalog and keep a 30-day archive.
	 */

	public function run_daily_regenerate() {
		$this->run_feed_job( false );
	}

	/**
	 * Queue a one-off public XML build (activation / first run).
	 */

	public function queue_public_xml() {
		if ( ! wp_next_scheduled( self::GENERATE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::GENERATE_HOOK );
		}
	}

	/**
	 * Rebuild the public file a minute after the last price, sale, or stock change.
	 *
	 * Each new change pushes the single cron event forward, so a burst of saves
	 * writes the catalog once. The daily 00:05 job is a separate hook and stays.
	 */
	public function queue_feed_after_change() {
		if ( $this->suppress_change_queue ) {
			return;
		}

		wp_clear_scheduled_hook( self::GENERATE_HOOK );
		wp_schedule_single_event( time() + self::CHANGE_DELAY, self::GENERATE_HOOK );
	}

	/**
	 * Queue a rebuild when a product or variation is trashed, restored, or deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function queue_feed_after_product_post( $post_id ) {
		$type = get_post_type( $post_id );
		if ( ! in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$this->queue_feed_after_change();
	}

	/**
	 * Cron callback for the one-off build.
	 */

	public function run_queued_generate() {
		$this->run_feed_job( false );
	}

	/**
	 * Start or continue the batched catalog build, then schedule another slice.
	 *
	 * @param bool $force Admin rebuild refreshes files even when prices are unchanged.
	 */
	public function run_feed_job( $force = false ) {
		$job = Sidrena_Cijena_Feed_Job::instance();
		if ( ! $job->is_running() ) {
			$job->start( $force );
		}
		$job->run_for( 20 );
		if ( $job->is_running() && ! wp_next_scheduled( self::GENERATE_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::GENERATE_HOOK );
		}
	}

	/**
	 * Public feed hits never build the catalog. A missing file is queued once.
	 */
	public function maybe_ensure_public_xml_on_init() {
		Sidrena_Cijena_Feed_Routes::instance()->maybe_ensure_public_xml_on_init();
	}

	/**
	 * Claim the rebuild before wp-cron on this request can start the queued job.
	 *
	 * WordPress runs wp-cron on init priority 10. The Generate button used to
	 * lose that race and return sidrena_err=busy.
	 */
	public function prepare_manual_rebuild() {
		if ( ! $this->is_manual_rebuild_request() ) {
			return;
		}

		delete_transient( self::REBUILD_LOCK );

		if ( ! $this->acquire_rebuild_lock() ) {
			return;
		}

		wp_clear_scheduled_hook( self::GENERATE_HOOK );
	}

	/**
	 * This admin POST is the Generate public pricelist button.
	 *
	 * @return bool
	 */
	private function is_manual_rebuild_request() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( ! isset( $_POST['sidrena_cijena_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_action'] ) );
		if ( 'regenerate_public_xml' !== $action ) {
			return false;
		}

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return false;
		}

		$nonce = isset( $_POST['sidrena_cijena_xml_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_xml_nonce'] ) ) : '';

		return (bool) wp_verify_nonce( $nonce, 'sidrena_cijena_save_xml' );
	}

	/**
	 * Whether a catalog rebuild is in progress.
	 *
	 * @return bool
	 */
	public function is_rebuild_locked() {
		return $this->lock_is_fresh( get_option( self::REBUILD_LOCK, '' ) );
	}

	/**
	 * One catalog rebuild at a time. A dead process cannot hold this forever:
	 * the option stores a start time and is ignored after REBUILD_LOCK_TTL.
	 *
	 * @return bool
	 */
	private function acquire_rebuild_lock() {
		$held  = get_option( self::REBUILD_LOCK, '' );
		$owned = $this->lock_token( $held );
		if ( '' !== $this->rebuild_lock_token && '' !== $owned && strlen( $owned ) === strlen( $this->rebuild_lock_token ) && hash_equals( $this->rebuild_lock_token, $owned ) ) {
			return true;
		}

		if ( $this->lock_is_fresh( $held ) ) {
			return false;
		}

		$token = wp_generate_password( 12, false );
		$value = time() . '|' . $token;

		if ( ! is_string( $held ) || '' === $held ) {
			if ( ! add_option( self::REBUILD_LOCK, $value, '', 'no' ) ) {
				return false;
			}
		} else {
			global $wpdb;

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$value,
					self::REBUILD_LOCK,
					$held
				)
			);
			if ( 1 !== (int) $updated ) {
				return false;
			}

			wp_cache_delete( self::REBUILD_LOCK, 'options' );
		}

		$this->rebuild_lock_token = $token;
		delete_transient( self::REBUILD_LOCK );

		return true;
	}

	/**
	 * Drop the lock when this request still owns it. Also runs on shutdown so a
	 * failed admin handler cannot leave the button busy for the whole TTL.
	 */
	public function release_owned_rebuild_lock() {
		$this->release_rebuild_lock();
	}

	/**
	 * Drop the lock when this request still owns it.
	 */
	private function release_rebuild_lock() {
		if ( '' === $this->rebuild_lock_token ) {
			return;
		}

		$held  = get_option( self::REBUILD_LOCK, '' );
		$owned = $this->lock_token( $held );
		if ( '' !== $owned && strlen( $owned ) === strlen( $this->rebuild_lock_token ) && hash_equals( $this->rebuild_lock_token, $owned ) ) {
			delete_option( self::REBUILD_LOCK );
		}

		$this->rebuild_lock_token = '';
		delete_transient( self::REBUILD_LOCK );
	}

	/**
	 * Lock value is "{timestamp}|{token}" and counts only inside the TTL.
	 *
	 * @param mixed $held Stored option value.
	 * @return bool
	 */
	private function lock_is_fresh( $held ) {
		if ( ! is_string( $held ) || '' === $held ) {
			return false;
		}

		$parts   = explode( '|', $held, 2 );
		$started = isset( $parts[0] ) ? (int) $parts[0] : 0;
		if ( $started <= 0 || ! isset( $parts[1] ) || '' === $parts[1] ) {
			return false;
		}

		return ( time() - $started ) < self::REBUILD_LOCK_TTL;
	}

	/**
	 * Token half of a lock value.
	 *
	 * @param mixed $held Stored option value.
	 * @return string
	 */
	private function lock_token( $held ) {
		if ( ! is_string( $held ) || false === strpos( $held, '|' ) ) {
			return '';
		}

		$parts = explode( '|', $held, 2 );

		return isset( $parts[1] ) ? $parts[1] : '';
	}

	/**
	 * Build the public XML if the file is missing. Wp-admin only; public requests queue cron.
	 *
	 * @return array<string, mixed>|WP_Error|true True when the file already exists.
	 */

	public function maybe_ensure_public_xml() {
		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			Sidrena_Cijena_Feed_Routes::instance()->schedule_public_rebuild_if_missing();
			return true;
		}

		if ( Sidrena_Cijena_Settings::instance()->is_multi_poslovnica() ) {
			$oznaka = Sidrena_Cijena_Feed_Routes::instance()->requested_oznaka();
			if ( '' === $oznaka || Sidrena_Cijena_Settings::instance()->is_za_sva_prodajna_mjesta() ) {
				return true;
			}

			if ( file_exists( $this->get_public_path( $this->export_format(), $oznaka ) ) ) {
				return true;
			}

			return $this->regenerate_one_outlet( $oznaka );
		}

		if ( file_exists( $this->get_public_path() ) ) {
			return true;
		}

		return $this->regenerate_public_xml();
	}

	public function get_next_daily_timestamp() {
		$next = wp_next_scheduled( self::DAILY_HOOK );
		return $next ? (int) $next : 0;
	}

	/**
	 * Whether a timestamp falls on 00:05 in the site timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return bool
	 */

	private function timestamp_is_daily_hour( $timestamp ) {
		$dt = new DateTime( '@' . (int) $timestamp );
		$dt->setTimezone( wp_timezone() );

		return self::DAILY_HOUR === (int) $dt->format( 'G' ) && self::DAILY_MINUTE === (int) $dt->format( 'i' );
	}

	/**
	 * Next 00:05 in the site timezone (after midnight, before the 08:00 deadline).
	 *
	 * @return int
	 */

	private function next_daily_timestamp() {
		$tz   = wp_timezone();
		$now  = new DateTime( 'now', $tz );
		$next = new DateTime( sprintf( 'today %02d:%02d:00', self::DAILY_HOUR, self::DAILY_MINUTE ), $tz );

		if ( $next <= $now ) {
			$next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Last public XML generation meta.
	 *
	 * @return array<string, mixed>
	 */

	public function get_public_meta() {
		$stored = get_option( self::PUBLIC_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write the public catalog XML to uploads.
	 *
	 * @param bool $force_rewrite Manual admin rebuild: refresh live files even when catalog body is unchanged.
	 * @return array<string, mixed>|WP_Error
	 */

	/**
	 * Write public files from rows already collected in batches.
	 *
	 * @param array<int, array<string, string>> $rows          Woo (or full catalog) rows.
	 * @param bool                              $force_rewrite Refresh live files when the body is unchanged.
	 * @return array<string, mixed>|WP_Error
	 */
	public function publish_collected_public_rows( $rows, $force_rewrite = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$this->suppress_change_queue = true;
		try {
			$settings = Sidrena_Cijena_Settings::instance();
			$rows_api = Sidrena_Cijena_Feed_Rows::instance();

			if ( ! $settings->is_multi_poslovnica() || $settings->is_za_sva_prodajna_mjesta() ) {
				if ( Sidrena_Cijena_Plugin::woocommerce_active() ) {
					$rows_api->append_extra_catalog_rows( $rows, false, true, null, null );
				}
				if ( $settings->is_za_sva_prodajna_mjesta() ) {
					Sidrena_Cijena_Feed_Files::instance()->delete_outlet_live_files();
				}
				$result = Sidrena_Cijena_Feed_Files::instance()->write_feed_file( $rows, '', $force_rewrite );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( $settings->is_za_sva_prodajna_mjesta() ) {
					$meta = array(
						'generated' => time(),
						'count'     => (int) $result['count'],
						'changed'   => ! empty( $result['changed'] ),
						'refreshed' => ! empty( $result['refreshed'] ),
						'format'    => $this->export_format(),
						'webshop'   => $result,
						'outlets'   => array(),
					);
					update_option( self::PUBLIC_OPTION, $meta );

					return $meta;
				}
				update_option( self::PUBLIC_OPTION, $result );

				return $result;
			}

			$prev   = $this->get_public_meta();
			$stored = ( isset( $prev['outlets'] ) && is_array( $prev['outlets'] ) ) ? $prev['outlets'] : array();
			$total  = 0;
			$latest = 0;
			$changed   = false;
			$refreshed = false;
			$web       = null;

			if ( $settings->publishes_webshop_feed() ) {
				$web_rows = $rows;
				$rows_api->append_extra_catalog_rows( $web_rows, false, true, '', array_keys( Sidrena_Cijena_Poslovnice::instance()->assigned_ids_lookup() ) );
				$web = Sidrena_Cijena_Feed_Files::instance()->write_feed_file( $web_rows, '', $force_rewrite );
				if ( is_wp_error( $web ) ) {
					return $web;
				}
				$total    += (int) $web['count'];
				$latest    = max( $latest, (int) $web['generated'] );
				$changed   = $changed || ! empty( $web['changed'] );
				$refreshed = $refreshed || ! empty( $web['refreshed'] );
			}

			foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
				$ids = isset( $outlet['products'] ) ? $outlet['products'] : array();
				if ( Sidrena_Cijena_Plugin::woocommerce_active() ) {
					$out_rows = $rows_api->apply_outlet_dostupnost( $rows_api->filter_woo_rows_for_assigned_ids( $rows, $ids ), $outlet['oznaka'] );
					$rows_api->append_extra_catalog_rows( $out_rows, false, true, $outlet['oznaka'], $ids );
				} else {
					$out_rows = $rows_api->collect_rows( false, true, $ids, $outlet['oznaka'] );
				}
				$result = $this->write_outlet_feed( $outlet, $stored, $out_rows, $force_rewrite );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$stored[ $outlet['oznaka'] ] = $result;
				$total    += (int) $result['count'];
				$latest    = max( $latest, (int) $result['generated'] );
				$changed   = $changed || ! empty( $result['changed'] );
				$refreshed = $refreshed || ! empty( $result['refreshed'] );
			}

			$meta = array(
				'generated' => $latest > 0 ? $latest : time(),
				'count'     => $total,
				'changed'   => $changed,
				'refreshed' => $refreshed,
				'format'    => $this->export_format(),
				'filename'  => isset( $prev['filename'] ) ? $prev['filename'] : '',
				'pohrana'   => isset( $prev['pohrana'] ) ? $prev['pohrana'] : 0,
				'outlets'   => $stored,
			);
			if ( is_array( $web ) ) {
				$meta['webshop'] = $web;
			}
			update_option( self::PUBLIC_OPTION, $meta );

			return $meta;
		} finally {
			$this->suppress_change_queue = false;
		}
	}

	public function regenerate_public_xml( $force_rewrite = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		if ( Sidrena_Cijena_Feed_Job::instance()->is_running() ) {
			return new WP_Error( 'sidrena_xml_busy', __( 'Javni cjenik se već generira.', 'mjdd-sidrena-cijena' ) );
		}

		if ( ! $this->acquire_rebuild_lock() ) {
			return new WP_Error( 'sidrena_xml_busy', __( 'Javni cjenik se već generira.', 'mjdd-sidrena-cijena' ) );
		}

		$this->suppress_change_queue = true;

		try {
			if ( Sidrena_Cijena_Settings::instance()->is_multi_poslovnica() ) {
				return $this->regenerate_poslovnice_feeds( $force_rewrite );
			}

			if ( Sidrena_Cijena_Plugin::woocommerce_active() && ! function_exists( 'wc_get_products' ) ) {
				return new WP_Error( 'sidrena_xml_wc', __( 'WooCommerce nije spreman za generiranje cjenika.', 'mjdd-sidrena-cijena' ) );
			}

			try {
				$rows   = $this->collect_rows( false, true, null, null );
				$result = Sidrena_Cijena_Feed_Files::instance()->write_feed_file( $rows, '', $force_rewrite );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				if ( isset( $result['count'] ) && (int) $result['count'] <= 0 ) {
					$result['debug'] = Sidrena_Cijena_Feed_Rows::instance()->catalog_diagnostics();
				}

				update_option( self::PUBLIC_OPTION, $result );

				return $result;
			} catch ( Throwable $e ) {
				return $this->feed_failure( $e );
			}
		} finally {
			$this->suppress_change_queue = false;
			$this->release_rebuild_lock();
		}
	}

	/**
	 * Rebuild every outlet feed that has products.
	 *
	 * @param bool $force_rewrite Manual admin rebuild.
	 * @return array<string, mixed>|WP_Error
	 */
	private function regenerate_poslovnice_feeds( $force_rewrite = false ) {
		if ( Sidrena_Cijena_Plugin::woocommerce_active() && ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'sidrena_xml_wc', __( 'WooCommerce nije spreman za generiranje cjenika.', 'mjdd-sidrena-cijena' ) );
		}

		try {
			$prev    = $this->get_public_meta();
			$stored  = ( isset( $prev['outlets'] ) && is_array( $prev['outlets'] ) ) ? $prev['outlets'] : array();
			$total     = 0;
			$latest    = 0;
			$changed   = false;
			$refreshed = false;

			if ( Sidrena_Cijena_Settings::instance()->is_za_sva_prodajna_mjesta() ) {
				$rows = $this->collect_rows( false, true, null, null );
				$web  = Sidrena_Cijena_Feed_Files::instance()->write_feed_file( $rows, '', $force_rewrite );
				if ( is_wp_error( $web ) ) {
					return $web;
				}
				Sidrena_Cijena_Feed_Files::instance()->delete_outlet_live_files();
				$meta = array(
					'generated' => time(),
					'count'     => (int) $web['count'],
					'changed'   => ! empty( $web['changed'] ),
					'refreshed' => ! empty( $web['refreshed'] ),
					'format'    => $this->export_format(),
					'webshop'   => $web,
					'outlets'   => array(),
				);
				update_option( self::PUBLIC_OPTION, $meta );

				return $meta;
			}

			$bundle = Sidrena_Cijena_Feed_Rows::instance()->collect_public_by_scope();
			$web    = null;
			if ( Sidrena_Cijena_Settings::instance()->publishes_webshop_feed() ) {
				$web_rows = isset( $bundle['webshop'] ) ? $bundle['webshop'] : array();
				$web      = Sidrena_Cijena_Feed_Files::instance()->write_feed_file( $web_rows, '', $force_rewrite );
				if ( is_wp_error( $web ) ) {
					return $web;
				}
				$total     += (int) $web['count'];
				$latest     = max( $latest, (int) $web['generated'] );
				$changed    = $changed || ! empty( $web['changed'] );
				$refreshed  = $refreshed || ! empty( $web['refreshed'] );
			}

			$scoped = isset( $bundle['outlets'] ) && is_array( $bundle['outlets'] ) ? $bundle['outlets'] : array();
			foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
				$rows   = isset( $scoped[ $outlet['oznaka'] ] ) ? $scoped[ $outlet['oznaka'] ] : null;
				$result = $this->write_outlet_feed( $outlet, $stored, $rows, $force_rewrite );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$stored[ $outlet['oznaka'] ] = $result;
				$total     += (int) $result['count'];
				$latest     = max( $latest, (int) $result['generated'] );
				$changed    = $changed || ! empty( $result['changed'] );
				$refreshed  = $refreshed || ! empty( $result['refreshed'] );
			}

			$meta = array(
				'generated' => $latest > 0 ? $latest : time(),
				'count'     => $total,
				'changed'   => $changed,
				'refreshed' => $refreshed,
				'format'    => $this->export_format(),
				'filename'  => isset( $prev['filename'] ) ? $prev['filename'] : '',
				'pohrana'   => isset( $prev['pohrana'] ) ? $prev['pohrana'] : 0,
				'outlets'   => $stored,
			);
			if ( is_array( $web ) ) {
				$meta['webshop'] = $web;
			}
			update_option( self::PUBLIC_OPTION, $meta );

			return $meta;
		} catch ( Throwable $e ) {
			return $this->feed_failure( $e );
		}
	}

	/**
	 * Rebuild one outlet when its public URL is opened and the file is missing.
	 *
	 * @param string $oznaka Object code.
	 * @return array<string, mixed>|WP_Error|true
	 */

	private function regenerate_one_outlet( $oznaka ) {
		if ( Sidrena_Cijena_Settings::instance()->is_za_sva_prodajna_mjesta() ) {
			return true;
		}

		$outlet = Sidrena_Cijena_Poslovnice::instance()->get_by_oznaka( $oznaka );
		if ( ! is_array( $outlet ) ) {
			return new WP_Error( 'sidrena_poslovnica_missing', __( 'Poslovnica nema objavljen cjenik.', 'mjdd-sidrena-cijena' ) );
		}

		try {
			$prev   = $this->get_public_meta();
			$stored = ( isset( $prev['outlets'] ) && is_array( $prev['outlets'] ) ) ? $prev['outlets'] : array();
			$result = $this->write_outlet_feed( $outlet, $stored );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$stored[ $outlet['oznaka'] ] = $result;
			$prev['generated']           = (int) $result['generated'];
			$prev['format']              = $this->export_format();
			$prev['outlets']             = $stored;
			update_option( self::PUBLIC_OPTION, $prev );

			return $result;
		} catch ( Throwable $e ) {
			return $this->feed_failure( $e );
		}
	}

	/**
	 * Log a feed failure and return a fixed message safe to show in wp-admin.
	 *
	 * @param Throwable $exception Caught error.
	 * @return WP_Error
	 */

	private function feed_failure( $exception ) {
		Sidrena_Cijena_Plugin::log_exception( $exception );

		return new WP_Error( 'sidrena_xml_fatal', __( 'Javni cjenik nije mogao biti generiran.', 'mjdd-sidrena-cijena' ) );
	}

	/**
	 * Write one outlet feed and keep its previous archive filename.
	 *
	 * @param array<string, mixed>             $outlet Outlet.
	 * @param array<string, array<string, mixed>> $stored Previous per-outlet meta.
	 * @param array<int, array<string, string>>|null $rows Prebuilt rows, or null to collect.
	 * @param bool                               $force_rewrite Manual admin rebuild.
	 * @return array<string, mixed>|WP_Error
	 */
	private function write_outlet_feed( $outlet, $stored, $rows = null, $force_rewrite = false ) {
		if ( null === $rows ) {
			$rows = $this->collect_rows( false, true, isset( $outlet['products'] ) ? $outlet['products'] : array(), $outlet['oznaka'] );
		}
		$result = Sidrena_Cijena_Feed_Files::instance()->write_feed_file( $rows, $outlet['oznaka'], $force_rewrite );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$previous            = isset( $stored[ $outlet['oznaka'] ] ) && is_array( $stored[ $outlet['oznaka'] ] ) ? $stored[ $outlet['oznaka'] ] : array();
		$result['filename']  = isset( $previous['filename'] ) ? (string) $previous['filename'] : '';
		$result['pohrana']   = isset( $previous['pohrana'] ) ? (int) $previous['pohrana'] : 0;

		return $result;
	}

	/**
	 * Last generation meta for one outlet.
	 *
	 * @param string $oznaka Object code.
	 * @return array<string, mixed>
	 */
	public function get_outlet_meta( $oznaka ) {
		return Sidrena_Cijena_Archive::instance()->get_outlet_meta($oznaka);
	}

	/**
	 * Serve public XML, archive index, or an archived file.
	 */
	public function maybe_serve_public_xml() {
		Sidrena_Cijena_Feed_Routes::instance()->maybe_serve_public_xml();
	}

	/**
	 * File list for the [sidrena-arhiva] shortcode. Name and date only.
	 *
	 * @param string $oznaka Object code, empty for every file.
	 * @return string
	 */
	public function get_archive_embed_html( $oznaka = '' ) {
		return Sidrena_Cijena_Archive::instance()->get_archive_embed_html( $oznaka );
	}

	/**
	 * Searchable pricelist table for the public page and the [sidrena-cjenik] shortcode.
	 *
	 * Reads the file written with the public feed. A missing file queues one rebuild.
	 *
	 * @param string                                  $oznaka Outlet code, empty for the full list.
	 * @param array<int, array<string, string>>|null $rows   Already built rows. Public calls leave this null.
	 * @return string
	 */
	public function get_cjenik_embed_html( $oznaka = '', $rows = null ) {
		return Sidrena_Cijena_Feed_Files::instance()->get_cjenik_embed_html($oznaka, $rows);
	}
}
