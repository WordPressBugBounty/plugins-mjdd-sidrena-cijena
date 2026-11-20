<?php
/**
 * Pretty URLs and public HTTP serving for feeds, archive, and HTML cjenik.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pretty URLs and public HTTP serving for feeds, archive, and HTML cjenik.
 */
class Sidrena_Cijena_Feed_Routes {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
	 * Pretty URLs: /sidrena-cijena.xml|.csv, /arhiva-sidrene-cijene, /sidrena-cjenik
	 */
	public function register_rewrite() {
		$settings     = Sidrena_Cijena_Settings::instance();
		$archive_slug = $settings->archive_slug();
		$cjenik_slug  = $settings->cjenik_slug();

		add_rewrite_rule( '^' . Sidrena_Cijena_Export::PUBLIC_STEM . '\\.(xml|csv)$', 'index.php?sidrena_cijena_xml=1&sidrena_cijena_ext=$matches[1]', 'top' );
		add_rewrite_rule(
			'^' . Sidrena_Cijena_Export::PUBLIC_STEM . '-([A-Za-z0-9-]+)\\.(xml|csv)$',
			'index.php?sidrena_cijena_xml=1&sidrena_cijena_oznaka=$matches[1]&sidrena_cijena_ext=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^' . Sidrena_Cijena_Export::PUBLIC_STEM . '-(robe|usluge)\\.(xml|csv)$',
			'index.php?sidrena_cijena_xml=1&sidrena_cijena_kind=$matches[1]&sidrena_cijena_ext=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^' . Sidrena_Cijena_Export::PUBLIC_STEM . '-(robe|usluge)-([A-Za-z0-9-]+)\\.(xml|csv)$',
			'index.php?sidrena_cijena_xml=1&sidrena_cijena_kind=$matches[1]&sidrena_cijena_oznaka=$matches[2]&sidrena_cijena_ext=$matches[3]',
			'top'
		);
		$archive_slug = preg_quote( $archive_slug, '#' );
		$cjenik_slug  = preg_quote( $cjenik_slug, '#' );

		add_rewrite_rule( '^' . $archive_slug . '/?$', 'index.php?sidrena_cijena_arhiva=1', 'top' );
		add_rewrite_rule(
			'^' . $archive_slug . '/(.+\\.(xml|csv))$',
			'index.php?sidrena_cijena_arhiva_name=$matches[1]&sidrena_cijena_ext=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^' . $cjenik_slug . '/([A-Za-z0-9-]+)/?$',
			'index.php?sidrena_cijena_cjenik=1&sidrena_cijena_oznaka=$matches[1]',
			'top'
		);
		add_rewrite_rule( '^' . $cjenik_slug . '/?$', 'index.php?sidrena_cijena_cjenik=1', 'top' );
	}

	/**
	 * Rewrite option value including Woo vs standalone mode.
	 *
	 * @return string
	 */
	public static function rewrite_fingerprint() {
		$settings = Sidrena_Cijena_Settings::instance();
		$mode     = Sidrena_Cijena_Plugin::woocommerce_active() ? 'woo' : 'solo';
		$page     = $settings->is_public_cjenik_enabled() ? 'page' : 'nopage';
		return Sidrena_Cijena_Export::REWRITE_VERSION . '-' . $mode . '-' . $page . '-' . $settings->cjenik_slug() . '-' . $settings->archive_slug();
	}

	/**
	 * Flush permalinks once after adding archive routes.
	 */
	public function maybe_flush_rewrites() {
		if ( (string) get_option( Sidrena_Cijena_Export::REWRITE_OPTION, '' ) === self::rewrite_fingerprint() ) {
			return;
		}

		if ( wp_doing_ajax() || $this->is_api_request() ) {
			return;
		}

		$this->register_rewrite();
		flush_rewrite_rules( false );
		update_option( Sidrena_Cijena_Export::REWRITE_OPTION, self::rewrite_fingerprint(), false );
	}

	/**
	 * Expose the public XML and archive query vars.
	 *
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function register_query_var( $vars ) {
		$vars[] = 'sidrena_cijena_xml';
		$vars[] = 'sidrena_cijena_oznaka';
		$vars[] = 'sidrena_cijena_kind';
		$vars[] = 'sidrena_cijena_ext';
		$vars[] = 'sidrena_cijena_arhiva';
		$vars[] = 'sidrena_cijena_arhiva_file';
		$vars[] = 'sidrena_cijena_arhiva_name';
		$vars[] = 'sidrena_cijena_cjenik';
		return $vars;
	}

	/**
	 * Public feed hits never build the catalog. A missing file is queued once.
	 */
	public function maybe_ensure_public_xml_on_init() {
		if ( is_admin() || $this->is_api_request() ) {
			return;
		}

		if ( ! $this->is_public_xml_request() ) {
			return;
		}

		$this->schedule_public_rebuild_if_missing();
	}

	/**
	 * Queue one cron rebuild when the live file for this request is missing.
	 */
	public function schedule_public_rebuild_if_missing() {
		$ext = $this->requested_live_ext();
		if ( $ext !== Sidrena_Cijena_Feed_Files::instance()->export_format() ) {
			return;
		}

		$oznaka = '';
		if ( Sidrena_Cijena_Settings::instance()->is_multi_poslovnica() ) {
			$oznaka = $this->requested_oznaka();
		}
		if ( '' !== $oznaka && Sidrena_Cijena_Settings::instance()->is_za_sva_prodajna_mjesta() ) {
			return;
		}

		$kind  = $this->requested_kind();
		$files = Sidrena_Cijena_Feed_Files::instance();
		if ( '' === $kind ) {
			$robe   = $files->get_public_path( $ext, $oznaka, 'robe' );
			$usluge = $files->get_public_path( $ext, $oznaka, 'usluge' );
			if ( ( '' !== $robe && file_exists( $robe ) ) || ( '' !== $usluge && file_exists( $usluge ) ) ) {
				return;
			}
		} elseif ( file_exists( $files->get_public_path( $ext, $oznaka, $kind ) ) ) {
			return;
		}

		$this->schedule_public_rebuild();
	}

	/**
	 * Schedule a single background rebuild. Repeat visits wait out the cooldown.
	 */
	public function schedule_public_rebuild() {
		if ( get_transient( Sidrena_Cijena_Export::REBUILD_COOLDOWN ) || Sidrena_Cijena_Export::instance()->is_rebuild_locked() ) {
			return;
		}

		Sidrena_Cijena_Export::instance()->queue_public_xml();
		set_transient( Sidrena_Cijena_Export::REBUILD_COOLDOWN, 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * REST/AJAX/JSON requests. REST_REQUEST is not set yet during init.
	 *
	 * @return bool
	 */
	public function is_api_request() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return true;
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return is_string( $uri ) && false !== strpos( $uri, '/wp-json/' );
	}

	/**
	 * Serve public XML, archive index, or an archived file.
	 */
	public function maybe_serve_public_xml() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( $this->is_cjenik_request() ) {
			$this->serve_cjenik_page();
			return;
		}

		$archive = $this->get_requested_archive_file();
		if ( null !== $archive ) {
			$this->serve_archive_file( $archive['name'] );
			return;
		}

		if ( $this->is_archive_request() ) {
			$this->serve_archive_page();
			return;
		}

		if ( ! $this->is_public_xml_request() ) {
			return;
		}

		$ext    = $this->requested_live_ext();
		$oznaka = $this->requested_oznaka();
		$kind   = $this->requested_kind();
		$multi  = Sidrena_Cijena_Settings::instance()->is_multi_poslovnica();
		$za_sva = Sidrena_Cijena_Settings::instance()->is_za_sva_prodajna_mjesta();

		if ( $za_sva && '' !== $oznaka ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Isti cjenik vrijedi za sva prodajna mjesta. Zasebna datoteka poslovnice nije objavljena.', 'mjdd-sidrena-cijena' );
			exit;
		}

		if ( $multi && '' !== $oznaka ) {
			$this->serve_outlet_feed( $oznaka, $ext, $kind );
			return;
		}

		if ( ! $multi && '' !== $oznaka ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Cjenik poslovnice nije uključen.', 'mjdd-sidrena-cijena' );
			exit;
		}

		$this->serve_live_file( '', $ext, $kind );
	}

	/**
	 * robe, usluge, or empty when the stable alias does not name a kind.
	 *
	 * @return string
	 */
	public function requested_kind() {
		$path = $this->get_request_path();
		if ( preg_match( '#^/' . preg_quote( Sidrena_Cijena_Export::PUBLIC_STEM, '#' ) . '-(robe|usluge)(?:-[A-Za-z0-9-]+)?\\.(xml|csv)$#', $path, $match ) ) {
			return $match[1];
		}

		$raw = '';
		if ( isset( $_GET['sidrena_cijena_kind'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_GET['sidrena_cijena_kind'] );
		} elseif ( function_exists( 'get_query_var' ) ) {
			$raw = get_query_var( 'sidrena_cijena_kind' );
		}

		return in_array( $raw, array( 'robe', 'usluge' ), true ) ? $raw : '';
	}

	/**
	 * Send one live file. An alias without a kind serves robe, then usluge.
	 *
	 * @param string $oznaka Outlet code.
	 * @param string $ext    xml|csv.
	 * @param string $kind   robe|usluge or empty.
	 */
	private function serve_live_file( $oznaka, $ext, $kind ) {
		$files = Sidrena_Cijena_Feed_Files::instance();
		if ( '' === $kind ) {
			$robe = $files->get_public_path( $ext, $oznaka, 'robe' );
			$kind = ( '' !== $robe && file_exists( $robe ) ) ? 'robe' : 'usluge';
		}

		$path = $files->get_public_path( $ext, $oznaka, $kind );
		if ( '' === $path || ! file_exists( $path ) ) {
			$this->serve_missing_public_feed( $ext );
		}

		$files->send_public_file( $path, Sidrena_Cijena_Archive::instance()->published_filename( $ext, $oznaka, $kind ), $ext );
	}

	/**
	 * 503 a missing live feed. Rebuild is queued, never run inside this request.
	 *
	 * @param string $ext xml|csv.
	 */
	public function serve_missing_public_feed( $ext ) {
		if ( $ext === Sidrena_Cijena_Feed_Files::instance()->export_format() ) {
			$this->schedule_public_rebuild();
		}

		status_header( 503 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Retry-After: 120' );
		echo esc_html__( 'Javni cjenik još nije generiran.', 'mjdd-sidrena-cijena' );
		exit;
	}

	/**
	 * Whether the current request is the public XML feed.
	 *
	 * @return bool
	 */
	public function is_public_xml_request() {
		if ( isset( $_GET['sidrena_cijena_xml'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( function_exists( 'get_query_var' ) && get_query_var( 'sidrena_cijena_xml' ) ) {
			return true;
		}

		$path = $this->get_request_path();

		return (bool) preg_match( '#^/' . preg_quote( Sidrena_Cijena_Export::PUBLIC_STEM, '#' ) . '(?:-[A-Za-z0-9-]+)?\\.(xml|csv)$#', $path );
	}

	/**
	 * Extension for the live feed request.
	 *
	 * @return string
	 */
	public function requested_live_ext() {
		$from_query = $this->ext_from_request();
		if ( '' !== $from_query ) {
			return $from_query;
		}

		$path = $this->get_request_path();
		if ( preg_match( '#^/' . preg_quote( Sidrena_Cijena_Export::PUBLIC_STEM, '#' ) . '(?:-[A-Za-z0-9-]+)?\\.(xml|csv)$#', $path, $match ) ) {
			return $match[1];
		}

		return Sidrena_Cijena_Feed_Files::instance()->export_format();
	}

	/**
	 * Object code from /sidrena-cijena-{oznaka}.xml or the query argument.
	 *
	 * @return string
	 */
	public function requested_oznaka() {
		$path = $this->get_request_path();
		if ( preg_match( '#^/' . preg_quote( Sidrena_Cijena_Export::PUBLIC_STEM, '#' ) . '-(robe|usluge)-([A-Za-z0-9-]+)\\.(xml|csv)$#', $path, $match ) ) {
			return Sidrena_Cijena_Settings::instance()->normalize_oznaka( $match[2] );
		}

		if ( preg_match( '#^/' . preg_quote( Sidrena_Cijena_Export::PUBLIC_STEM, '#' ) . '-(robe|usluge)\\.(xml|csv)$#', $path ) ) {
			return '';
		}

		if ( preg_match( '#^/' . preg_quote( Sidrena_Cijena_Export::PUBLIC_STEM, '#' ) . '-([A-Za-z0-9-]+)\\.(xml|csv)$#', $path, $match ) ) {
			return Sidrena_Cijena_Settings::instance()->normalize_oznaka( $match[1] );
		}

		$raw = '';
		if ( isset( $_GET['sidrena_cijena_oznaka'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_GET['sidrena_cijena_oznaka'] );
		} elseif ( function_exists( 'get_query_var' ) ) {
			$raw = get_query_var( 'sidrena_cijena_oznaka' );
		}

		return Sidrena_Cijena_Settings::instance()->normalize_oznaka( is_string( $raw ) ? $raw : '' );
	}

	/**
	 * Plain-text list when the single-shop URL is opened in multi-outlet mode.
	 */
	public function serve_poslovnice_directory() {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'Cjenik je objavljen po poslovnici.', 'mjdd-sidrena-cijena' ) . "\n";
		foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
			$name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
			echo esc_html( $name . ' ' . Sidrena_Cijena_Feed_Files::instance()->get_public_url( '', $outlet['oznaka'] ) ) . "\n";
		}
		exit;
	}

	/**
	 * Stream one outlet's live feed.
	 *
	 * @param string $oznaka Object code.
	 * @param string $ext    xml|csv.
	 */
	public function serve_outlet_feed( $oznaka, $ext, $kind = '' ) {
		$outlet = Sidrena_Cijena_Poslovnice::instance()->get_by_oznaka( $oznaka );
		if ( ! is_array( $outlet ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Poslovnica nema objavljen cjenik.', 'mjdd-sidrena-cijena' );
			exit;
		}

		$this->serve_live_file( $oznaka, $ext, $kind );
	}

	/**
	 * Download name for an outlet feed: last archive name, or the next legal name.
	 *
	 * @param array<string, mixed> $outlet Outlet.
	 * @param string               $ext    xml|csv.
	 * @return string
	 */
	public function outlet_download_name( $outlet, $ext ) {
		$meta = Sidrena_Cijena_Archive::instance()->get_outlet_meta( $outlet['oznaka'] );
		if ( ! empty( $meta['filename'] ) && is_string( $meta['filename'] ) ) {
			$name = Sidrena_Cijena_Archive::instance()->sanitize_archive_name( $meta['filename'] );
			if ( '' !== $name ) {
				return $name;
			}
		}

		$stamp = ! empty( $meta['generated'] ) ? (int) $meta['generated'] : 0;
		return Sidrena_Cijena_Archive::instance()->legal_filename( $ext, $stamp, 0, $outlet );
	}

	/**
	 * xml|csv from query string or rewrite var.
	 *
	 * @return string
	 */
	public function ext_from_request() {
		if ( isset( $_GET['sidrena_cijena_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = sanitize_key( wp_unslash( $_GET['sidrena_cijena_ext'] ) );
			if ( in_array( $raw, array( 'xml', 'csv' ), true ) ) {
				return $raw;
			}
		}

		if ( function_exists( 'get_query_var' ) ) {
			$raw = sanitize_key( (string) get_query_var( 'sidrena_cijena_ext' ) );
			if ( in_array( $raw, array( 'xml', 'csv' ), true ) ) {
				return $raw;
			}
		}

		return '';
	}

	/**
	 * Whether the current request is the archive HTML index.
	 *
	 * @return bool
	 */
	public function is_archive_request() {
		if ( isset( $_GET['sidrena_cijena_arhiva'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( function_exists( 'get_query_var' ) && get_query_var( 'sidrena_cijena_arhiva' ) ) {
			return true;
		}

		return '/' . Sidrena_Cijena_Settings::instance()->archive_slug() === $this->get_request_path();
	}

	/**
	 * Whether the current request is the standalone HTML pricelist.
	 *
	 * @return bool
	 */
	public function is_cjenik_request() {
		if ( isset( $_GET['sidrena_cijena_cjenik'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( function_exists( 'get_query_var' ) && get_query_var( 'sidrena_cijena_cjenik' ) ) {
			return true;
		}

		$path = $this->get_request_path();
		$slug = '/' . Sidrena_Cijena_Settings::instance()->cjenik_slug();
		if ( $path === $slug ) {
			return true;
		}

		return (bool) preg_match( '#^' . preg_quote( $slug, '#' ) . '/[A-Za-z0-9-]+$#', $path );
	}

	/**
	 * Requested archive file basename.
	 *
	 * @return array{name:string}|null
	 */
	public function get_requested_archive_file() {
		$name = '';
		$path = $this->get_raw_request_path();
		if ( preg_match( '#^/' . preg_quote( Sidrena_Cijena_Settings::instance()->archive_slug(), '#' ) . '/(.+\\.(xml|csv))$#', $path, $match ) ) {
			$name = Sidrena_Cijena_Archive::instance()->sanitize_archive_name( $match[1] );
		}

		if ( '' === $name && isset( $_GET['sidrena_cijena_arhiva_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$name = Sidrena_Cijena_Archive::instance()->sanitize_archive_name( wp_unslash( $_GET['sidrena_cijena_arhiva_name'] ) );
		} elseif ( '' === $name && function_exists( 'get_query_var' ) ) {
			$name = Sidrena_Cijena_Archive::instance()->sanitize_archive_name( (string) get_query_var( 'sidrena_cijena_arhiva_name' ) );
		}

		if ( '' === $name ) {
			$date = '';
			if ( isset( $_GET['sidrena_cijena_arhiva_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$date = Sidrena_Cijena_Archive::instance()->sanitize_archive_date( sanitize_text_field( wp_unslash( $_GET['sidrena_cijena_arhiva_file'] ) ) );
			} elseif ( function_exists( 'get_query_var' ) ) {
				$date = Sidrena_Cijena_Archive::instance()->sanitize_archive_date( (string) get_query_var( 'sidrena_cijena_arhiva_file' ) );
			}

			$ext = $this->ext_from_request();
			if ( '' !== $date ) {
				$name = Sidrena_Cijena_Archive::instance()->sanitize_archive_name( Sidrena_Cijena_Export::PUBLIC_STEM . '_' . $date . '.' . ( '' !== $ext ? $ext : 'xml' ) );
			}
		}

		if ( '' === $name ) {
			return null;
		}

		return array(
			'name' => $name,
		);
	}

	/**
	 * Request path relative to the site home, without a trailing slash.
	 *
	 * @return string
	 */
	public function get_raw_request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( ! is_string( $uri ) ) {
			return '/';
		}

		$uri  = str_replace( "\0", '', $uri );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$path = untrailingslashit( $path );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = untrailingslashit( $home );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Request path without the query string, relative to the site.
	 *
	 * @return string
	 */
	public function get_request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = untrailingslashit( $path );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = untrailingslashit( $home );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Stream one archived feed file.
	 *
	 * @param string $name Basename.
	 */
	public function serve_archive_file( $name ) {
		$name = Sidrena_Cijena_Archive::instance()->sanitize_archive_name( $name );
		$path = '' !== $name ? trailingslashit( Sidrena_Cijena_Archive::instance()->get_archive_dir() ) . $name : '';
		$ext  = '' !== $name ? strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) : 'xml';

		if ( '' === $name || ! file_exists( $path ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Arhivski cjenik nije pronađen.', 'mjdd-sidrena-cijena' );
			exit;
		}

		Sidrena_Cijena_Feed_Files::instance()->send_public_file( $path, $name, $ext );
	}

	/**
	 * Simple public HTML listing of archived XML files.
	 */
	public function serve_archive_page() {
		$files = Sidrena_Cijena_Archive::instance()->get_archive_files();
		$shop  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = Sidrena_Cijena_Settings::instance()->archive_page_title();

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: follow' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $title ) . '</title>';
		wp_register_style(
			'mjdd-sidrena-cijena-archive',
			SIDRENA_CIJENA_URL . 'assets/css/archive.css',
			array(),
			SIDRENA_CIJENA_VERSION
		);
		wp_print_styles( 'mjdd-sidrena-cijena-archive' );
		echo '</head><body><main>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html( $shop ) . ' - ' . esc_html(
			Sidrena_Cijena_Settings::instance()->is_multi_poslovnica()
				? __( 'Zamijenjeni cjenici, jedna datoteka po danu. Svaka ostaje 15 dana od zamjene.', 'mjdd-sidrena-cijena' )
				: __( 'Dnevni cjenici (XML ili CSV), jedna datoteka po danu.', 'mjdd-sidrena-cijena' )
		) . '</p>';

		if ( empty( $files ) ) {
			echo '<p class="empty">' . esc_html__( 'Arhiva je prazna. Objavljeni cjenik se ovdje pojavi čim postoji javna datoteka.', 'mjdd-sidrena-cijena' ) . '</p>';
		} elseif ( Sidrena_Cijena_Settings::instance()->is_multi_poslovnica() ) {
			$this->render_archive_groups( $files );
		} else {
			echo '<ol>';
			$this->render_archive_file_items( $files );
			echo '</ol>';
		}

		echo '<p class="back"><a href="' . esc_url( home_url( '/' ) ) . '">';
		echo esc_html(
			sprintf(
				/* translators: %s: site title */
				__( 'Natrag na %s', 'mjdd-sidrena-cijena' ),
				$shop
			)
		);
		echo '</a></p>';

		echo '</main></body></html>';
		exit;
	}

	/**
	 * Object code for this HTML cjenik request. Empty is the webshop page.
	 *
	 * The XML path parser stays in requested_oznaka(). This route reads the
	 * cjenik path or the query var set by the rewrite.
	 *
	 * @return string
	 */
	public function cjenik_requested_oznaka() {
		$path = $this->get_request_path();
		$slug = '/' . Sidrena_Cijena_Settings::instance()->cjenik_slug();
		if ( preg_match( '#^' . preg_quote( $slug, '#' ) . '/([A-Za-z0-9-]+)$#', $path, $match ) ) {
			return Sidrena_Cijena_Settings::instance()->normalize_oznaka( $match[1] );
		}

		return $this->requested_oznaka();
	}

	/**
	 * Webshop object code used on buttons and archive headings.
	 *
	 * @return string
	 */
	public function webshop_oznaka() {
		$settings = Sidrena_Cijena_Settings::instance();
		$oznaka   = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );

		return '' !== $oznaka ? $oznaka : '001';
	}

	/**
	 * One list item per archive file.
	 *
	 * @param array<int, array<string, mixed>> $files Archive rows.
	 */
	private function render_archive_file_items( $files ) {
		foreach ( $files as $file ) {
			$stamp = isset( $file['stamp'] ) ? (int) $file['stamp'] : Sidrena_Cijena_Archive::instance()->archive_date_timestamp( isset( $file['date'] ) ? $file['date'] : '' );
			$label = $stamp ? wp_date( 'd.m.Y. H:i', $stamp ) : ( isset( $file['date'] ) ? (string) $file['date'] : '' );
			$name  = isset( $file['name'] ) ? (string) $file['name'] : '';
			$url   = isset( $file['url'] ) ? (string) $file['url'] : '';
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			echo '<span>' . esc_html( $label ) . '</span></li>';
		}
	}

	/**
	 * Archive index grouped by object code: webshop, outlets, then older names.
	 *
	 * @param array<int, array<string, mixed>> $files Archive rows, newest first.
	 */
	private function render_archive_groups( $files ) {
		$archive = Sidrena_Cijena_Archive::instance();
		$buckets = array();
		foreach ( $files as $file ) {
			$name = isset( $file['name'] ) ? (string) $file['name'] : '';
			$code = $archive->archive_file_oznaka( $name );
			$key  = '' !== $code ? $code : 'legacy';
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array();
			}
			$buckets[ $key ][] = $file;
		}

		$order = array( $this->webshop_oznaka() );
		foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) {
			if ( ! in_array( $outlet['oznaka'], $order, true ) ) {
				$order[] = $outlet['oznaka'];
			}
		}
		foreach ( array_keys( $buckets ) as $code ) {
			if ( 'legacy' !== $code && ! in_array( $code, $order, true ) ) {
				$order[] = $code;
			}
		}
		$order[] = 'legacy';

		foreach ( $order as $code ) {
			if ( empty( $buckets[ $code ] ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $this->archive_group_heading( $code ) ) . '</h2>';
			echo '<ol>';
			$this->render_archive_file_items( $buckets[ $code ] );
			echo '</ol>';
		}
	}

	/**
	 * Heading for one archive group.
	 *
	 * @param string $code Object code, or legacy.
	 * @return string
	 */
	private function archive_group_heading( $code ) {
		if ( 'legacy' === $code ) {
			return __( 'Starije datoteke', 'mjdd-sidrena-cijena' );
		}

		if ( $code === $this->webshop_oznaka() ) {
			return $code;
		}

		$outlet = Sidrena_Cijena_Poslovnice::instance()->get_by_oznaka( $code );
		if ( is_array( $outlet ) ) {
			return $this->outlet_label( $outlet );
		}

		return $code;
	}

	/**
	 * Public HTML pricelist. Standalone catalogs always have it; Woo shops only when the setting is on.
	 */
	public function serve_cjenik_page() {
		$settings = Sidrena_Cijena_Settings::instance();
		$oznaka   = $this->cjenik_requested_oznaka();
		$outlet   = null;

		if ( '' !== $oznaka ) {
			$outlet = Sidrena_Cijena_Poslovnice::instance()->get_by_oznaka( $oznaka );
			if ( ! is_array( $outlet ) ) {
				$this->serve_cjenik_empty_page();
				return;
			}
		}

		$snapshot = Sidrena_Cijena_Feed_Files::instance()->read_cjenik_snapshot( $oznaka );
		if ( null === $snapshot ) {
			$this->schedule_public_rebuild();
			$this->serve_cjenik_pending_page();
			return;
		}

		$count     = (int) $snapshot['count'];
		$shop      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title     = $settings->cjenik_page_title();
		$label     = $settings->get_preview_label();
		$scope     = is_array( $outlet ) ? $this->outlet_label( $outlet ) : ( $settings->is_multi_poslovnica() ? $this->webshop_oznaka() : '' );
		$format    = Sidrena_Cijena_Feed_Files::instance()->export_format();
		$feed_lbl  = 'csv' === $format ? __( 'Preuzmi CSV', 'mjdd-sidrena-cijena' ) : __( 'Preuzmi XML', 'mjdd-sidrena-cijena' );
		$generated = Sidrena_Cijena_Export::instance()->get_public_meta();
		$stamp     = ! empty( $generated['generated'] )
			? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $generated['generated'] )
			: wp_date( get_option( 'date_format' ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $shop . ' - ' . $title ) . '</title>';
		echo '<meta name="description" content="' . esc_attr( sprintf( /* translators: %s shop name */ __( 'Važeći cjenik s baznom cijenom: %s', 'mjdd-sidrena-cijena' ), $shop ) ) . '" />';
		wp_register_style(
			'mjdd-sidrena-cijena-cjenik',
			SIDRENA_CIJENA_URL . 'assets/css/cjenik.css',
			array(),
			SIDRENA_CIJENA_VERSION
		);
		wp_print_styles( 'mjdd-sidrena-cijena-cjenik' );
		echo '</head><body><main>';
		echo '<header class="cjenik-hero">';
		echo '<p class="cjenik-kicker">' . esc_html( $shop ) . '</p>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $scope ) {
			echo '<p>' . esc_html( $scope ) . '</p>';
		}
		echo '<p>' . esc_html( $label ) . '</p>';
		echo '<p class="cjenik-meta">' . esc_html(
			sprintf(
				/* translators: 1: datetime, 2: count */
				__( 'Ažurirano %1$s · %2$d proizvoda', 'mjdd-sidrena-cijena' ),
				$stamp,
				$count
			)
		) . '</p>';
		echo '<div class="cjenik-actions">';
		$this->render_cjenik_downloads( $oznaka, $feed_lbl );
		echo '<a class="cjenik-btn cjenik-btn--ghost" href="' . esc_url( Sidrena_Cijena_Feed_Files::instance()->get_archive_url() ) . '">' . esc_html__( 'Arhiva', 'mjdd-sidrena-cijena' ) . '</a>';
		$this->render_cjenik_scope_links( $oznaka );
		echo '</div>';
		echo '</header>';
		echo str_replace( Sidrena_Cijena_Export::CJENIK_DOM_TOKEN, 'sidrena-cjenik-1', $snapshot['html'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- snapshot is escaped when the feed is generated.

		echo '<p class="back"><a href="' . esc_url( home_url( '/' ) ) . '">';
		echo esc_html(
			sprintf(
				/* translators: %s: site title */
				__( 'Natrag na %s', 'mjdd-sidrena-cijena' ),
				$shop
			)
		);
		echo '</a></p>';
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Visible name for one outlet row.
	 *
	 * @param array<string, mixed> $outlet Outlet row.
	 * @return string
	 */
	private function outlet_label( $outlet ) {
		$name = isset( $outlet['naziv'] ) ? trim( (string) $outlet['naziv'] ) : '';
		if ( '' !== $name ) {
			return $name;
		}

		return isset( $outlet['oznaka'] ) ? (string) $outlet['oznaka'] : '';
	}

	/**
	 * Download buttons for the table on this page.
	 *
	 * The webshop page lists the webshop feed first, then each outlet feed.
	 * An outlet page lists only that outlet's feed.
	 *
	 * @param string $oznaka   Empty for the webshop page.
	 * @param string $feed_lbl Preuzmi XML or Preuzmi CSV.
	 */
	private function render_cjenik_downloads( $oznaka, $feed_lbl ) {
		$settings = Sidrena_Cijena_Settings::instance();
		$scopes   = array();

		if ( ! $settings->is_multi_poslovnica() ) {
			$scopes[] = array(
				'oznaka' => '',
				'name'   => '',
			);
		} elseif ( '' === $oznaka ) {
			$scopes[] = array(
				'oznaka' => '',
				'name'   => $this->webshop_oznaka(),
			);
			foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
				$scopes[] = array(
					'oznaka' => $outlet['oznaka'],
					'name'   => $this->outlet_label( $outlet ),
				);
			}
		} else {
			$outlet   = Sidrena_Cijena_Poslovnice::instance()->get_by_oznaka( $oznaka );
			$scopes[] = array(
				'oznaka' => $oznaka,
				'name'   => is_array( $outlet ) ? $this->outlet_label( $outlet ) : $oznaka,
			);
		}

		foreach ( $scopes as $scope ) {
			$this->render_kind_download_buttons( $scope['oznaka'], $scope['name'], $feed_lbl );
		}
	}

	/**
	 * One download button per statutory file that exists (ROBE and USLUGE).
	 *
	 * @param string $oznaka   Outlet code, empty for the webshop feed.
	 * @param string $scope    Visible outlet name, empty on a single-shop page.
	 * @param string $feed_lbl Preuzmi XML or Preuzmi CSV.
	 */
	private function render_kind_download_buttons( $oznaka, $scope, $feed_lbl ) {
		$files  = Sidrena_Cijena_Feed_Files::instance();
		$format = $files->export_format();
		$kinds  = array();

		foreach ( array( 'robe', 'usluge' ) as $kind ) {
			$path = $files->get_public_path( '', $oznaka, $kind );
			if ( '' !== $path && file_exists( $path ) ) {
				$kinds[] = $kind;
			}
		}

		if ( array() === $kinds ) {
			$kinds[] = 'robe';
		}

		foreach ( $kinds as $kind ) {
			if ( 'usluge' === $kind ) {
				$label = 'csv' === $format
					? __( 'Preuzmi CSV cjenik usluga', 'mjdd-sidrena-cijena' )
					: __( 'Preuzmi cjenik usluga', 'mjdd-sidrena-cijena' );
			} else {
				$label = 'csv' === $format
					? __( 'Preuzmi CSV cjenik robe', 'mjdd-sidrena-cijena' )
					: __( 'Preuzmi cjenik robe', 'mjdd-sidrena-cijena' );
			}

			if ( '' !== $scope ) {
				$label .= ' - ' . $scope;
			}

			echo '<a class="cjenik-btn" href="' . esc_url( $files->get_public_url( '', $oznaka, $kind ) ) . '">' . esc_html( $label ) . '</a>';
		}
	}

	/**
	 * Links to the other HTML cjenik pages when more than one scope exists.
	 *
	 * @param string $current Empty for the webshop page.
	 */
	private function render_cjenik_scope_links( $current ) {
		$settings = Sidrena_Cijena_Settings::instance();
		if ( ! $settings->is_multi_poslovnica() ) {
			return;
		}

		$files = Sidrena_Cijena_Feed_Files::instance();
		if ( '' !== $current ) {
			echo '<a class="cjenik-btn cjenik-btn--ghost" href="' . esc_url( $files->get_cjenik_url() ) . '">' . esc_html( $this->webshop_oznaka() ) . '</a>';
		}

		foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
			if ( $outlet['oznaka'] === $current ) {
				continue;
			}
			echo '<a class="cjenik-btn cjenik-btn--ghost" href="' . esc_url( $files->get_cjenik_url( $outlet['oznaka'] ) ) . '">' . esc_html( $this->outlet_label( $outlet ) ) . '</a>';
		}
	}

	/**
	 * Unknown outlet code. Does not fall back to the webshop table.
	 */
	public function serve_cjenik_empty_page() {
		$shop  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = Sidrena_Cijena_Settings::instance()->cjenik_page_title();

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $shop . ' - ' . $title ) . '</title>';
		wp_register_style(
			'mjdd-sidrena-cijena-cjenik',
			SIDRENA_CIJENA_URL . 'assets/css/cjenik.css',
			array(),
			SIDRENA_CIJENA_VERSION
		);
		wp_print_styles( 'mjdd-sidrena-cijena-cjenik' );
		echo '</head><body><main>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p class="empty">' . esc_html__( 'Cjenik je prazan.', 'mjdd-sidrena-cijena' ) . '</p>';
		echo '<p class="back"><a href="' . esc_url( Sidrena_Cijena_Feed_Files::instance()->get_cjenik_url() ) . '">' . esc_html__( 'Cjenik', 'mjdd-sidrena-cijena' ) . '</a></p>';
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Short page while the HTML snapshot is still being generated.
	 */
	public function serve_cjenik_pending_page() {
		$shop  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = Sidrena_Cijena_Settings::instance()->cjenik_page_title();

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $shop . ' - ' . $title ) . '</title>';
		echo '</head><body><main>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html__( 'Cjenik se priprema. Osvježite stranicu za nekoliko minuta.', 'mjdd-sidrena-cijena' ) . '</p>';
		echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html(
			sprintf(
				/* translators: %s: site title */
				__( 'Natrag na %s', 'mjdd-sidrena-cijena' ),
				$shop
			)
		) . '</a></p>';
		echo '</main></body></html>';
		exit;
	}
}
