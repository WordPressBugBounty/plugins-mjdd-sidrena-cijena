<?php
/**
 * Admin form saves and AJAX writes for Sidrena cijena.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings, catalog, outlet, snapshot, and purge writes.
 */
class Sidrena_Cijena_Admin_Save {

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
	 * Save the Poslovnice repeater.
	 */
	public function handle_poslovnice_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) || 'save_poslovnice' !== $_POST['sidrena_cijena_action'] ) {
			return;
		}

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_poslovnice', 'sidrena_cijena_poslovnice_nonce' );

		$before = array();
		foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) {
			if ( '' !== $outlet['id'] ) {
				$before[ $outlet['id'] ] = $outlet['oznaka'];
			}
		}

		$json = isset( $_POST['sidrena_cijena_poslovnice_json'] ) ? wp_unslash( $_POST['sidrena_cijena_poslovnice_json'] ) : '[]';
		$saved = Sidrena_Cijena_Poslovnice::instance()->save_from_json( is_string( $json ) ? $json : '[]' );

		$kept = array();
		foreach ( $saved as $outlet ) {
			if ( '' !== $outlet['id'] ) {
				$kept[ $outlet['id'] ] = true;
			}
		}
		foreach ( $before as $id => $oznaka ) {
			if ( ! isset( $kept[ $id ] ) && '' !== $oznaka ) {
				Sidrena_Cijena_Catalog::instance()->delete_for_poslovnica( $oznaka );
				Sidrena_Cijena_Upit::instance()->delete_for_poslovnica( $oznaka );
			}
		}

		if ( isset( $_POST['sidrena_cijena_trader_id'] ) || isset( $_POST['sidrena_cijena_file_oblik'] ) ) {
			Sidrena_Cijena_Settings::instance()->save(
				array(
					'trader_kind'         => isset( $_POST['sidrena_cijena_trader_kind'] ) ? wp_unslash( $_POST['sidrena_cijena_trader_kind'] ) : 'oib',
					'trader_id'           => isset( $_POST['sidrena_cijena_trader_id'] ) ? wp_unslash( $_POST['sidrena_cijena_trader_id'] ) : '',
					'file_za_sva'         => isset( $_POST['sidrena_cijena_file_za_sva'] ) ? 'yes' : 'no',
					'file_oblik'          => isset( $_POST['sidrena_cijena_file_oblik'] ) ? wp_unslash( $_POST['sidrena_cijena_file_oblik'] ) : 'webshop',
					'file_adresa'         => isset( $_POST['sidrena_cijena_file_adresa'] ) ? wp_unslash( $_POST['sidrena_cijena_file_adresa'] ) : '',
					'file_oznaka'         => isset( $_POST['sidrena_cijena_file_oznaka'] ) ? wp_unslash( $_POST['sidrena_cijena_file_oznaka'] ) : '001',
					'file_pohrana_robe'   => isset( $_POST['sidrena_cijena_file_pohrana_robe'] ) ? wp_unslash( $_POST['sidrena_cijena_file_pohrana_robe'] ) : 1,
					'file_pohrana_usluge' => isset( $_POST['sidrena_cijena_file_pohrana_usluge'] ) ? wp_unslash( $_POST['sidrena_cijena_file_pohrana_usluge'] ) : 1,
				)
			);
		}

		if ( Sidrena_Cijena_Settings::instance()->is_za_sva_prodajna_mjesta() ) {
			Sidrena_Cijena_Feed_Files::instance()->delete_outlet_live_files();
			if ( class_exists( 'Sidrena_Eracuni_Export' ) ) {
				Sidrena_Eracuni_Export::instance()->regenerate_all();
			}
		}
		Sidrena_Cijena_Export::instance()->queue_feed_after_change();

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'poslovnice', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save settings form.
	 */
	public function handle_settings_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) || 'save_settings' !== $_POST['sidrena_cijena_action'] ) {
			return;
		}

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_settings', 'sidrena_cijena_nonce' );

		$payload = array(
			'prefix'         => isset( $_POST['sidrena_cijena_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_prefix'] ) ) : '',
			'tooltip'        => isset( $_POST['sidrena_cijena_tooltip'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sidrena_cijena_tooltip'] ) ) : '',
			'enable_tooltip' => isset( $_POST['sidrena_cijena_enable_tooltip'] ) ? 'yes' : 'no',
			'default_date'   => isset( $_POST['sidrena_cijena_default_date'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_default_date'] ) ) : '',
			'date_style'     => isset( $_POST['sidrena_cijena_date_style'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_date_style'] ) ) : 'parens',
			'enable_cjm'     => isset( $_POST['sidrena_cijena_enable_cjm'] ) ? 'yes' : 'no',
			'brand_manual'   => isset( $_POST['sidrena_cijena_brand_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_brand_manual'] ) ) : '',
		);

		if ( Sidrena_Cijena_Plugin::woocommerce_active() ) {
			$payload['brand_taxonomy'] = isset( $_POST['sidrena_cijena_brand_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_brand_taxonomy'] ) ) : '';
			$payload['auto_display'] = isset( $_POST['sidrena_cijena_auto_display'] ) ? 'yes' : 'no';
			$payload['auto_fill']    = isset( $_POST['sidrena_cijena_auto_fill'] ) ? 'yes' : 'no';
		}

		Sidrena_Cijena_Settings::instance()->save( $payload );

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'postavke', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save public XML options from the Javni XML tab.
	 */
	public function handle_xml_settings_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_action'] ) );
		if ( ! in_array( $action, array( 'save_xml_settings', 'regenerate_public_xml' ), true ) ) {
			return;
		}

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_xml', 'sidrena_cijena_xml_nonce' );

		$payload = array(
			'daily_xml'          => isset( $_POST['sidrena_cijena_daily_xml'] ) ? 'yes' : 'no',
			'export_format'      => isset( $_POST['sidrena_cijena_public_format'] ) ? wp_unslash( $_POST['sidrena_cijena_public_format'] ) : 'xml',
			'cjenik_page_title'  => isset( $_POST['sidrena_cijena_cjenik_page_title'] ) ? wp_unslash( $_POST['sidrena_cijena_cjenik_page_title'] ) : '',
			'archive_page_title' => isset( $_POST['sidrena_cijena_archive_page_title'] ) ? wp_unslash( $_POST['sidrena_cijena_archive_page_title'] ) : '',
			'cjenik_slug'        => isset( $_POST['sidrena_cijena_cjenik_slug'] ) ? wp_unslash( $_POST['sidrena_cijena_cjenik_slug'] ) : '',
			'archive_slug'       => isset( $_POST['sidrena_cijena_archive_slug'] ) ? wp_unslash( $_POST['sidrena_cijena_archive_slug'] ) : '',
		);

		Sidrena_Cijena_Settings::instance()->save( $payload );

		Sidrena_Cijena_Export::instance()->sync_daily_schedule();

		if ( 'regenerate_public_xml' === $action ) {
			$result = Sidrena_Cijena_Export::instance()->regenerate_public_xml( true );
			$flag   = '1';
			$extra  = array();
			if ( is_wp_error( $result ) ) {
				if ( 'sidrena_poslovnice_empty' === $result->get_error_code() ) {
					$flag = '2';
				} elseif ( 'sidrena_xml_busy' === $result->get_error_code() ) {
					$flag          = '0';
					$extra['sidrena_err'] = 'busy';
				} else {
					$flag = '0';
				}
			} elseif ( is_array( $result ) && isset( $result['count'] ) && (int) $result['count'] <= 0 ) {
				$flag = '3';
			} elseif ( is_array( $result ) && empty( $result['changed'] ) && (int) $result['count'] > 0 ) {
				$extra['sidrena_feed'] = 'unchanged';
			}
			wp_safe_redirect(
				add_query_arg(
					array_merge(
						array(
							'page'        => 'sidrena-cijena',
							'tab'         => 'javni-xml',
							'sidrena_xml' => $flag,
						),
						$extra
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'javni-xml', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save standalone catalog repeater.
	 */
	public function handle_catalog_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) || 'save_catalog' !== $_POST['sidrena_cijena_action'] ) {
			return;
		}

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_catalog', 'sidrena_cijena_catalog_nonce' );

		$json = isset( $_POST['sidrena_cijena_catalog_json'] ) ? wp_unslash( $_POST['sidrena_cijena_catalog_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		Sidrena_Cijena_Catalog::instance()->save_from_json( is_string( $json ) ? $json : '[]' );

		$upit = isset( $_POST['sidrena_cijena_upit_json'] ) ? wp_unslash( $_POST['sidrena_cijena_upit_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		Sidrena_Cijena_Upit::instance()->save_from_json( is_string( $upit ) ? $upit : '[]' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'proizvodi', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Start or continue the batched public cjenik build.
	 */
	public function ajax_feed_job() {
		check_ajax_referer( 'sidrena_cijena_feed_job', 'nonce' );

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'step';

		try {
			$job = Sidrena_Cijena_Feed_Job::instance();
			if ( 'start' === $op ) {
				$job->start( true );
			}
			wp_send_json_success( $job->step() );
		} catch ( Throwable $e ) {
			Sidrena_Cijena_Plugin::log_exception( $e );
			wp_send_json_error( array( 'message' => __( 'Javni cjenik nije mogao biti generiran.', 'mjdd-sidrena-cijena' ) ) );
		}
	}

	/**
	 * AJAX batch snapshot from the settings page.
	 */
	public function ajax_snapshot() {
		check_ajax_referer( 'sidrena_cijena_snapshot', 'nonce' );

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		if ( ! Sidrena_Cijena_Plugin::woocommerce_active() ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce nije aktivan - popunjavanje radi samo na Woo trgovini.', 'mjdd-sidrena-cijena' ),
				)
			);
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce nije učitan (nedostaje wc_get_products).', 'mjdd-sidrena-cijena' ),
				)
			);
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$page        = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
		$offset      = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$child       = isset( $_POST['child'] ) ? absint( wp_unslash( $_POST['child'] ) ) : 0;
		$written_run = isset( $_POST['written_run'] ) ? absint( wp_unslash( $_POST['written_run'] ) ) : 0;
		$stamp_today = isset( $_POST['stamp_today'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['stamp_today'] ) );

		try {
			$result = Sidrena_Cijena_Activator::process_batch( $page, $stamp_today, $offset, $child, $written_run );
			wp_send_json_success( $result );
		} catch ( Throwable $e ) {
			Sidrena_Cijena_Plugin::log_exception( $e );
			wp_send_json_error( array( 'message' => __( 'Snimka cijena nije uspjela. Pojedinosti su u zapisniku poslužitelja.', 'mjdd-sidrena-cijena' ) ) );
		}
	}

	/**
	 * Delete one archived XML/CSV from the Javni cjenik list.
	 */
	public function ajax_archive_delete() {
		check_ajax_referer( 'sidrena_cijena_archive_delete', 'nonce' );

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$name   = isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '';
		$result = Sidrena_Cijena_Archive::instance()->delete_archive_file( is_string( $name ) ? $name : '' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Delete every archived XML/CSV. The live public feed stays.
	 */
	public function ajax_archive_delete_all() {
		check_ajax_referer( 'sidrena_cijena_archive_delete', 'nonce' );

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$result = Sidrena_Cijena_Archive::instance()->delete_all_archive_files();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'removed' => (int) $result ) );
	}

	/**
	 * Wipe plugin data and deactivate.
	 */
	public function ajax_purge() {
		check_ajax_referer( 'sidrena_cijena_purge', 'nonce' );

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		try {
			Sidrena_Cijena_Plugin::purge_all_data();

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			deactivate_plugins( plugin_basename( SIDRENA_CIJENA_FILE ) );

			wp_send_json_success(
				array(
					'redirect' => admin_url( 'plugins.php' ),
				)
			);
		} catch ( Throwable $e ) {
			Sidrena_Cijena_Plugin::log_exception( $e );
			wp_send_json_error( array( 'message' => __( 'Brisanje podataka nije uspjelo. Pojedinosti su u zapisniku poslužitelja.', 'mjdd-sidrena-cijena' ) ) );
		}
	}
}
