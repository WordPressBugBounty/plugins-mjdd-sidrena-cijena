<?php
/**
 * Admin: settings, standalone catalog, and WooCommerce product fields.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Sidrena_Cijena_Admin {

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
	 * Mark the settings screen so its footer can be styled apart from other admin pages.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, array( 'woocommerce_page_sidrena-cijena', 'toplevel_page_sidrena-cijena' ), true ) ) {
			$classes .= ' sidrena-cijena-screen';
		} elseif ( isset( $_GET['page'] ) && 'sidrena-cijena' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$classes .= ' sidrena-cijena-screen';
		}

		return $classes;
	}

	/**
	 * Hide the WordPress footer credit on this screen only.
	 */
	public function hide_footer_thankyou() {
		if ( ! $this->is_settings_screen() ) {
			return;
		}

		echo '<style id="sidrena-cijena-footer-thankyou">#footer-thankyou,#footer-left{display:none!important}</style>';
	}

	/**
	 * Drop the WordPress footer credit. Late, so other admin CSS cannot show it again.
	 *
	 * @param string $text Footer HTML.
	 * @return string
	 */
	public function filter_admin_footer_text( $text ) {
		if ( $this->is_settings_screen() ) {
			return '';
		}

		return $text;
	}

	/**
	 * Whether the current request is the Sidrena cijena settings screen.
	 *
	 * @return bool
	 */
	private function is_settings_screen() {
		if ( isset( $_GET['page'] ) && 'sidrena-cijena' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, array( 'woocommerce_page_sidrena-cijena', 'toplevel_page_sidrena-cijena' ), true );
	}

	/**
	 * Register admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $this, 'handle_xml_settings_save' ) );
		add_action( 'admin_init', array( $this, 'handle_poslovnice_save' ) );
		add_action( 'admin_init', array( $this, 'handle_catalog_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_head', array( $this, 'hide_footer_thankyou' ) );
		add_action( 'admin_footer', array( $this, 'hide_footer_thankyou' ), 999 );
		add_filter( 'admin_footer_text', array( $this, 'filter_admin_footer_text' ), 999 );
		add_action( 'wp_ajax_sidrena_cijena_purge', array( $this, 'ajax_purge' ) );
		add_action( 'wp_ajax_sidrena_cijena_feed_job', array( $this, 'ajax_feed_job' ) );
		add_action( 'wp_ajax_sidrena_cijena_archive_delete', array( $this, 'ajax_archive_delete' ) );
		add_action( 'wp_ajax_sidrena_cijena_archive_delete_all', array( $this, 'ajax_archive_delete_all' ) );
		add_action( 'admin_init', array( $this, 'handle_prefix_choice' ) );
		add_action( 'admin_notices', array( $this, 'prefix_notice' ) );

		if ( ! Sidrena_Cijena_Plugin::woocommerce_active() ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'maybe_dismiss_fill_prompt' ) );
		add_action( 'admin_notices', array( $this, 'snapshot_notice' ) );
		add_action( 'wp_ajax_sidrena_cijena_snapshot', array( $this, 'ajax_snapshot' ) );

		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_simple_fields' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 10, 2 );
		add_action( 'woocommerce_product_bulk_edit_start', array( $this, 'bulk_edit_vrsta_field' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_edit_save_vrsta' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'bulk_edit_save_iskljuci' ) );
	}

	/**
	 * WooCommerce submenu, or a top-level menu without Woo.
	 */
	public function register_menu() {
		$cap   = Sidrena_Cijena_Plugin::manage_capability();
		$title = __( 'Sidrena cijena', 'mjdd-sidrena-cijena' );

		if ( Sidrena_Cijena_Plugin::woocommerce_active() ) {
			add_submenu_page(
				'woocommerce',
				$title,
				$title,
				$cap,
				'sidrena-cijena',
				array( $this, 'render_settings_page' )
			);
			return;
		}

		add_menu_page(
			$title,
			$title,
			$cap,
			'sidrena-cijena',
			array( $this, 'render_settings_page' ),
			'dashicons-tag',
			56
		);
	}

	/**
	 * Settings screen.
	 */
	public function render_settings_page() {
		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		$tab      = $this->get_settings_tab();
		$tabs     = $this->get_tabs();
		$settings = Sidrena_Cijena_Settings::instance();
		$export   = Sidrena_Cijena_Export::instance();
		$saved              = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$catalog_incomplete = isset( $_GET['catalog_incomplete'] ) && '1' === $_GET['catalog_incomplete']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_ok    = isset( $_GET['sidrena_xml'] ) && '1' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_fail  = isset( $_GET['sidrena_xml'] ) && '0' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_empty = isset( $_GET['sidrena_xml'] ) && '2' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_zero       = isset( $_GET['sidrena_xml'] ) && '3' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_unchanged  = isset( $_GET['sidrena_feed'] ) && 'unchanged' === $_GET['sidrena_feed']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_busy       = isset( $_GET['sidrena_err'] ) && 'busy' === $_GET['sidrena_err']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$multi     = $settings->is_multi_poslovnica();
		$xml_url   = $export->get_public_url();
		?>
		<div class="wrap sidrena-cijena-settings">
			<div class="sidrena-cijena-hero">
				<h1><?php echo esc_html__( 'Sidrena cijena', 'mjdd-sidrena-cijena' ); ?></h1>
			</div>

			<nav class="sidrena-cijena-tabs" aria-label="<?php echo esc_attr__( 'Sidrena cijena', 'mjdd-sidrena-cijena' ); ?>">
				<?php foreach ( $tabs as $key => $item ) : ?>
					<a
						class="sidrena-cijena-tabs__item<?php echo $tab === $key ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( $this->get_settings_tab_url( $key ) ); ?>"
					>
						<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $item['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Postavke su spremljene.', 'mjdd-sidrena-cijena' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $catalog_incomplete ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Nijedan redak nije spremljen. Za svaku stavku upišite naziv, redovnu i baznu cijenu.', 'mjdd-sidrena-cijena' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $xml_ok ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					if ( $multi ) {
						echo esc_html__( 'Javni cjenici poslovnica su generirani.', 'mjdd-sidrena-cijena' );
					} else {
						echo esc_html__( 'Javni cjenik je generiran.', 'mjdd-sidrena-cijena' );
						echo ' <a href="' . esc_url( $xml_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $xml_url ) . '</a>';
					}
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $xml_empty ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Nijedna poslovnica nema dodane proizvode. Dodajte proizvode na kartici Poslovnice.', 'mjdd-sidrena-cijena' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $xml_zero ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Datoteka je spremljena, ali nije ušao nijedan proizvod (count 0). Provjerite ima li objavljenih (publish) proizvoda u WooCommerceu i regenerirajte nakon ažuriranja plugina.', 'mjdd-sidrena-cijena' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $xml_fail ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					if ( $xml_busy ) {
						echo esc_html__( 'Generiranje je već u tijeku (red čekanja ili drugi zahtjev). Pričekajte oko minute pa pokušajte ponovno.', 'mjdd-sidrena-cijena' );
					} else {
						echo esc_html__( 'Javni cjenik nije mogao biti generiran. Provjerite dozvole mape uploads.', 'mjdd-sidrena-cijena' );
					}
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $xml_ok && $xml_unchanged ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html__( 'Cjenik je osvježen (HTML i javne datoteke na fiksnoj adresi). Sadržaj cijena nije promijenjen, pa arhiva nije dopunjena — to je u skladu s pravilima o sidrenoj cijeni.', 'mjdd-sidrena-cijena' ); ?></p></div>
			<?php endif; ?>

			<?php do_action( 'sidrena_cijena_settings_notices', $tab ); ?>

			<div class="sidrena-cijena-panels">
				<?php
				if ( 'proizvodi' === $tab ) {
					$this->render_tab_proizvodi();
				} elseif ( 'uvoz-izvoz' === $tab ) {
					$this->render_tab_uvoz_izvoz();
				} elseif ( 'javni-xml' === $tab ) {
					$this->render_tab_javni_xml( $export );
				} elseif ( 'poslovnice' === $tab ) {
					$this->render_tab_poslovnice();
				} elseif ( 'postavke' === $tab ) {
					$this->render_tab_postavke( $settings );
				} else {
					do_action( 'sidrena_cijena_render_settings_tab', $tab );
				}
				?>

				<section class="sidrena-cijena-card sidrena-cijena-credit">
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: developer website URL */
								__( 'Developed by <a href="%s" target="_blank" rel="noopener noreferrer">Jure Zadravec / MJDD</a>', 'mjdd-sidrena-cijena' ),
								'https://mjdd.hr'
							),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
					</p>
					<a class="sidrena-cijena-credit__logo" href="https://mjdd.hr" target="_blank" rel="noopener noreferrer">
						<img src="<?php echo esc_url( SIDRENA_CIJENA_URL . 'assets/images/mjdd-logo-black.webp' ); ?>" alt="MJDD" width="135" height="36" />
					</a>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings tabs (slug => label/icon).
	 *
	 * @return array<string, array{label:string, icon:string}>
	 */
	private function get_tabs() {
		$tabs = array();

		$tabs['proizvodi'] = array(
			'label' => Sidrena_Cijena_Plugin::woocommerce_active()
				? __( 'Dodatne stavke', 'mjdd-sidrena-cijena' )
				: __( 'Proizvodi', 'mjdd-sidrena-cijena' ),
			'icon'  => 'dashicons-products',
		);

		$tabs['postavke'] = array(
			'label' => __( 'Postavke', 'mjdd-sidrena-cijena' ),
			'icon'  => 'dashicons-admin-generic',
		);
		$tabs['uvoz-izvoz'] = array(
			'label' => __( 'Uvoz/Izvoz', 'mjdd-sidrena-cijena' ),
			'icon'  => 'dashicons-migrate',
		);
		$tabs['javni-xml'] = array(
			'label' => __( 'Javni cjenik', 'mjdd-sidrena-cijena' ),
			'icon'  => 'dashicons-media-code',
		);
		$tabs['poslovnice'] = array(
			'label' => __( 'Poslovnice', 'mjdd-sidrena-cijena' ),
			'icon'  => 'dashicons-store',
		);

		/**
		 * Extra Sidrena cijena settings tabs.
		 *
		 * @param array<string, array{label:string, icon:string}> $tabs Tabs.
		 */
		$filtered = apply_filters( 'sidrena_cijena_settings_tabs', $tabs );

		return is_array( $filtered ) ? $filtered : $tabs;
	}

	/**
	 * Current settings tab.
	 *
	 * @return string
	 */
	private function get_settings_tab() {
		$tabs = $this->get_tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}

		if ( isset( $_GET['sidrena_xml'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'javni-xml';
		}

		$keys = array_keys( $tabs );
		return isset( $keys[0] ) ? $keys[0] : 'postavke';
	}

	/**
	 * Settings tab URL.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function get_settings_tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => 'sidrena-cijena',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Outlet codes for the catalog row select.
	 *
	 * @return array<int, array{oznaka:string,naziv:string}>
	 */
	private function catalog_outlet_choices() {
		$out = array();
		foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) {
			$label = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
			$out[] = array(
				'oznaka' => $outlet['oznaka'],
				'naziv'  => $label,
			);
		}

		return $out;
	}

	/**
	 * Postavke tab: display options, then snapshot fill.
	 *
	 * @param Sidrena_Cijena_Settings $settings Settings helper.
	 */
	private function render_tab_postavke( $settings ) {
		include SIDRENA_CIJENA_PATH . 'includes/admin/tab-postavke.php';
	}

	/**
	 * Poslovnice tab: legal filename fields and assigned products.
	 */
	private function render_tab_poslovnice() {
		include SIDRENA_CIJENA_PATH . 'includes/admin/tab-poslovnice.php';
	}

	/**
	 * Save the Poslovnice repeater.
	 */
	public function handle_poslovnice_save() {
		Sidrena_Cijena_Admin_Save::instance()->handle_poslovnice_save();
	}

	/**
	 * iOS-style on/off toggle.
	 *
	 * @param string $name    Input name.
	 * @param string $id      Input id.
	 * @param bool   $checked On/off.
	 * @param string $label   Visible label.
	 * @param string $class   Extra class on the checkbox.
	 */
	public function render_toggle( $name, $id, $checked, $label, $class = '' ) {
		?>
		<label class="sidrena-cijena-toggle" for="<?php echo esc_attr( $id ); ?>">
			<input
				type="checkbox"
				name="<?php echo esc_attr( $name ); ?>"
				id="<?php echo esc_attr( $id ); ?>"
				value="yes"
				class="<?php echo esc_attr( trim( $class ) ); ?>"
				<?php checked( $checked ); ?>
			/>
			<span class="sidrena-cijena-toggle__track" aria-hidden="true"></span>
			<span class="sidrena-cijena-toggle__label"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * Floppy-disk glyph used on primary save buttons.
	 *
	 * @return string
	 */
	public function save_icon_html() {
		return '<span class="sidrena-cijena-save-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20.5 5.8 18.2 3.5c-.4-.4-.9-.6-1.4-.6H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7.2c0-.5-.2-1-.5-1.4zM12 19c-1.7 0-3-1.3-3-3s1.3-3 3-3 3 1.3 3 3-1.3 3-3 3zm3-10H6V5h9v4z"/></svg></span>';
	}

	/**
	 * Primary submit button with the save icon.
	 *
	 * @param string $label Button label.
	 */
	public function render_save_button( $label ) {
		echo '<p class="submit"><button type="submit" class="button button-primary">';
		echo $this->save_icon_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo esc_html( $label );
		echo '</button></p>';
	}

	/**
	 * Copyable URL or shortcode row.
	 *
	 * @param string $label      Box label.
	 * @param string $value      Code / URL text.
	 * @param string $open_url   Optional open-link href.
	 * @param string $open_label Optional open-link label.
	 * @param string $open_class Open-link button class.
	 * @param string $open_icon  Dashicon class.
	 * @param string $code_id    Optional id on the code element.
	 */
	public function render_url_box( $label, $value, $open_url = '', $open_label = '', $open_class = 'button button-primary', $open_icon = 'dashicons-external', $code_id = '' ) {
		?>
		<div class="sidrena-cijena-urlbox">
			<span class="sidrena-cijena-urlbox__label"><?php echo esc_html( $label ); ?></span>
			<code<?php echo '' !== $code_id ? ' id="' . esc_attr( $code_id ) . '"' : ''; ?>><?php echo esc_html( $value ); ?></code>
			<div class="sidrena-cijena-urlbox__actions">
				<button type="button" class="button button-secondary sidrena-cijena-copy" data-copy="<?php echo esc_attr( $value ); ?>">
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<?php echo esc_html__( 'Kopiraj', 'mjdd-sidrena-cijena' ); ?>
				</button>
				<?php if ( '' !== $open_url && '' !== $open_label ) : ?>
					<a class="<?php echo esc_attr( $open_class ); ?>" href="<?php echo esc_url( $open_url ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons <?php echo esc_attr( $open_icon ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $open_label ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Catalog repeater. Without Woo this is the whole pricelist. With Woo it is extra rows appended to the shop feed.
	 */
	private function render_tab_proizvodi() {
		include SIDRENA_CIJENA_PATH . 'includes/admin/tab-proizvodi.php';
	}

	/**
	 * Uvoz / izvoz tab.
	 */
	private function render_tab_uvoz_izvoz() {
		include SIDRENA_CIJENA_PATH . 'includes/admin/tab-uvoz-izvoz.php';
	}

	/**
	 * Javni cjenik tab: shortcode that prints the archive file list.
	 */
	private function render_archive_shortcode_docs() {
		$settings = Sidrena_Cijena_Settings::instance();
		$code     = '[sidrena-arhiva]';
		$this->render_url_box( __( 'Shortcode arhive', 'mjdd-sidrena-cijena' ), $code );
		if ( ! $settings->is_multi_poslovnica() ) {
			return;
		}

		if ( $settings->publishes_webshop_feed() ) {
			$web = Sidrena_Cijena_Feed_Routes::instance()->webshop_oznaka();
			$this->render_url_box( $web, '[sidrena-arhiva oznaka="' . $web . '"]' );
		}
		foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) {
			$outlet_code = '[sidrena-arhiva oznaka="' . $outlet['oznaka'] . '"]';
			$outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
			$this->render_url_box( $outlet_name, $outlet_code );
		}
	}

	/**
	 * Javni cjenik tab: shortcode that prints the table.
	 *
	 * @param Sidrena_Cijena_Settings $settings Settings helper.
	 */
	private function render_cjenik_shortcode_docs( $settings ) {
		if ( $settings->publishes_webshop_feed() ) {
			$code = '[sidrena-cjenik]';
			$this->render_url_box( __( 'Shortcode tablice', 'mjdd-sidrena-cijena' ), $code );
		}
		if ( $settings->is_multi_poslovnica() ) {
			foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) {
				$outlet_code = '[sidrena-cjenik oznaka="' . $outlet['oznaka'] . '"]';
				$outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
				$this->render_url_box( $outlet_name, $outlet_code );
			}
		} elseif ( ! $settings->publishes_webshop_feed() ) {
			$this->render_url_box( __( 'Shortcode tablice (s oznakom poslovnice)', 'mjdd-sidrena-cijena' ), '[sidrena-cjenik oznaka="001"]' );
		}
	}

	/**
	 * Javni XML tab.
	 *
	 * @param Sidrena_Cijena_Export $export Export helper.
	 */
	private function render_tab_javni_xml( $export ) {
		include SIDRENA_CIJENA_PATH . 'includes/admin/tab-javni-xml.php';
	}

	/**
	 * Save settings form.
	 */
	public function handle_settings_save() {
		Sidrena_Cijena_Admin_Save::instance()->handle_settings_save();
	}

	/**
	 * Save public XML options from the Javni XML tab.
	 */
	public function handle_xml_settings_save() {
		Sidrena_Cijena_Admin_Save::instance()->handle_xml_settings_save();
	}

	/**
	 * Save standalone catalog repeater.
	 */
	public function handle_catalog_save() {
		Sidrena_Cijena_Admin_Save::instance()->handle_catalog_save();
	}

	/**
	 * Hide the post-activation fill prompt.
	 */
	public function maybe_dismiss_fill_prompt() {
		if ( ! isset( $_GET['sidrena_cijena_dismiss_fill'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer( 'sidrena_cijena_dismiss_fill' );

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		delete_option( Sidrena_Cijena_Activator::FILL_PROMPT_OPTION );
		wp_safe_redirect( remove_query_arg( array( 'sidrena_cijena_dismiss_fill', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * After activation, point at the two fill buttons. Shown on every admin screen until dismissed or a fill finishes.
	 */
	private function render_fill_prompt_notice() {
		if ( 'yes' !== get_option( Sidrena_Cijena_Activator::FILL_PROMPT_OPTION ) ) {
			return;
		}

		$settings_url = $this->get_settings_tab_url( 'postavke' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'sidrena_cijena_dismiss_fill', '1' ),
			'sidrena_cijena_dismiss_fill'
		);

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Sidrena cijena ne upisuje cijene pri aktivaciji. Prazna polja možete popuniti na kartici Postavke: iz redovne cijene (datum ostaje zadani) ili s današnjim datumom.', 'mjdd-sidrena-cijena' );
		echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Otvori postavke', 'mjdd-sidrena-cijena' ) . '</a>';
		echo ' · <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Sakrij', 'mjdd-sidrena-cijena' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Confirm replacing a bare "Cijena" label, or remember that the merchant kept it.
	 */
	public function handle_prefix_choice() {
		$use     = isset( $_GET['sidrena_cijena_use_bazna'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dismiss = isset( $_GET['sidrena_cijena_dismiss_prefix'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $use && ! $dismiss ) {
			return;
		}

		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		if ( $use ) {
			check_admin_referer( 'sidrena_cijena_use_bazna' );
			Sidrena_Cijena_Settings::instance()->apply_bazna_prefix();
			delete_option( Sidrena_Cijena_Settings::PREFIX_CHOICE_OPTION );
		} else {
			check_admin_referer( 'sidrena_cijena_dismiss_prefix' );
			$prefix = trim( (string) Sidrena_Cijena_Settings::instance()->get( 'prefix' ) );
			update_option( Sidrena_Cijena_Settings::PREFIX_CHOICE_OPTION, $prefix, false );
		}

		wp_safe_redirect( remove_query_arg( array( 'sidrena_cijena_use_bazna', 'sidrena_cijena_dismiss_prefix', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Offer the statutory label when the shop still shows only "Cijena".
	 */
	public function prefix_notice() {
		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		$prefix = trim( (string) Sidrena_Cijena_Settings::instance()->get( 'prefix' ) );
		if ( ! Sidrena_Cijena_Settings::is_generic_prefix( $prefix ) ) {
			return;
		}

		if ( (string) get_option( Sidrena_Cijena_Settings::PREFIX_CHOICE_OPTION, '' ) === $prefix ) {
			return;
		}

		$replacement = Sidrena_Cijena_Settings::bazna_replacement( $prefix );
		$use_url     = wp_nonce_url( add_query_arg( 'sidrena_cijena_use_bazna', '1' ), 'sidrena_cijena_use_bazna' );
		$keep_url    = wp_nonce_url( add_query_arg( 'sidrena_cijena_dismiss_prefix', '1' ), 'sidrena_cijena_dismiss_prefix' );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: current prefix, 2: suggested prefix */
				__( 'Natpis uz cijenu je „%1$s”. Kupac ga može zamijeniti s cijenom koju plaća. Zakonski naziv je bazna cijena. Predloženi natpis: %2$s.', 'mjdd-sidrena-cijena' ),
				$prefix,
				$replacement
			)
		);
		echo ' <a href="' . esc_url( $use_url ) . '">' . esc_html( sprintf( /* translators: %s: prefix */ __( 'Postavi „%s”', 'mjdd-sidrena-cijena' ), $replacement ) ) . '</a>';
		echo ' · <a href="' . esc_url( $keep_url ) . '">' . esc_html__( 'Zadrži sadašnji natpis', 'mjdd-sidrena-cijena' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Notice while a snapshot is running, or after activation until prices are filled or the prompt is dismissed.
	 */
	public function snapshot_notice() {
		if ( ! Sidrena_Cijena_Plugin::current_user_can_manage() ) {
			return;
		}

		if ( ! Sidrena_Cijena_Activator::is_pending() ) {
			$this->render_fill_prompt_notice();
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$allowed = (
			'woocommerce_page_sidrena-cijena' === $screen->id
			|| 'toplevel_page_sidrena-cijena' === $screen->id
			|| 'product' === $screen->id
			|| 'edit-product' === $screen->id
			|| ( isset( $screen->post_type ) && 'product' === $screen->post_type )
			|| ( is_string( $screen->id ) && false !== strpos( $screen->id, 'woocommerce' ) )
		);

		if ( ! $allowed ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Sidrena cijena: u tijeku je spremanje redovnih cijena proizvoda i varijacija. Već unesene sidrene cijene neće se prepisati.', 'mjdd-sidrena-cijena' );
		echo '</p></div>';
	}

	/**
	 * Admin assets on product and settings screens.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product  = $screen && 'product' === $screen->id;
		$is_settings = in_array( $hook, array( 'woocommerce_page_sidrena-cijena', 'toplevel_page_sidrena-cijena' ), true );

		if ( ! $is_product && ! $is_settings ) {
			return;
		}

		wp_enqueue_style(
			'sidrena-cijena-admin',
			SIDRENA_CIJENA_URL . 'assets/css/admin.css',
			array(),
			SIDRENA_CIJENA_VERSION
		);

		$script_deps = array( 'jquery' );
		if ( $is_settings && Sidrena_Cijena_Plugin::woocommerce_active() ) {
			if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
			}
			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				wp_enqueue_script( 'selectWoo' );
				$script_deps[] = 'selectWoo';
			}
		}

		wp_enqueue_script(
			'sidrena-cijena-admin',
			SIDRENA_CIJENA_URL . 'assets/js/admin.js',
			$script_deps,
			SIDRENA_CIJENA_VERSION,
			true
		);

		if ( $is_settings ) {
			$catalog_choices = array();
			if ( ! Sidrena_Cijena_Plugin::woocommerce_active() ) {
				foreach ( Sidrena_Cijena_Catalog::instance()->all() as $row ) {
					$label = $row['naziv'];
					if ( '' !== $row['sku'] ) {
						$label .= ' (' . $row['sku'] . ')';
					}
					$catalog_choices[] = array(
						'id'    => $row['id'],
						'label' => $label,
					);
				}
			}

			wp_localize_script(
				'sidrena-cijena-admin',
				'sidrenaCijenaAdmin',
				array(
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
					'nonce'              => wp_create_nonce( 'sidrena_cijena_snapshot' ),
					'feedNonce'          => wp_create_nonce( 'sidrena_cijena_feed_job' ),
					'feedJob'            => Sidrena_Cijena_Feed_Job::instance()->status(),
					'purgeNonce'         => wp_create_nonce( 'sidrena_cijena_purge' ),
					'archiveNonce'       => wp_create_nonce( 'sidrena_cijena_archive_delete' ),
					'importNonce'        => wp_create_nonce( 'sidrena_cijena_import' ),
					'productSearchNonce' => wp_create_nonce( 'search-products' ),
					'standalone'         => ! Sidrena_Cijena_Plugin::woocommerce_active(),
					'catalogIdPrefix'    => Sidrena_Cijena_Plugin::woocommerce_active() ? 'd' : '',
					'cjmEnabled'         => Sidrena_Cijena_Settings::instance()->is_cjm_enabled(),
					'multiPoslovnica'    => Sidrena_Cijena_Settings::instance()->is_multi_poslovnica(),
					'defaultDate'        => (string) Sidrena_Cijena_Settings::instance()->get( 'default_date' ),
					'defaultPrefix'      => __( 'Bazna cijena', 'mjdd-sidrena-cijena' ),
					'outlets'            => $this->catalog_outlet_choices(),
					'catalogChoices'     => $catalog_choices,
					'productLabels'      => Sidrena_Cijena_Poslovnice::instance()->product_labels(),
					'i18n'               => array(
						'running'      => __( 'Obrada…', 'mjdd-sidrena-cijena' ),
						'progress'     => __( 'Obrada… %1$s / %2$s', 'mjdd-sidrena-cijena' ),
						'feedWrite'    => __( 'Spremanje javnih datoteka…', 'mjdd-sidrena-cijena' ),
						'feedDone'     => __( 'Javni cjenik je generiran.', 'mjdd-sidrena-cijena' ),
						'feedDoneCount'=> __( 'Javni cjenik je generiran. Stavki: %d.', 'mjdd-sidrena-cijena' ),
						'feedStalled'  => __( 'Generiranje je stalo. Osvježite stranicu — nastavlja se od istog mjesta.', 'mjdd-sidrena-cijena' ),
						'retry'        => __( 'Veza je prekinuta. Nastavljam automatski…', 'mjdd-sidrena-cijena' ),
						'stalled'      => __( 'Obrada je stala. Pritisnite gumb ponovno za nastavak od istog mjesta. Već upisane cijene se ne prepisuju.', 'mjdd-sidrena-cijena' ),
						'done'         => __( 'Gotovo. Prazna polja su popunjena.', 'mjdd-sidrena-cijena' ),
						'doneToday'    => __( 'Gotovo. Prazna polja su popunjena današnjim datumom.', 'mjdd-sidrena-cijena' ),
						'doneCount'    => __( 'Gotovo. Upisano %d stavki.', 'mjdd-sidrena-cijena' ),
						'doneCountToday' => __( 'Gotovo. Upisano %d stavki (s današnjim datumom gdje je bilo prazno).', 'mjdd-sidrena-cijena' ),
						'fillNothing'  => __( 'Popunjavanje je završilo, ali nijedna stavka nije upisana.', 'mjdd-sidrena-cijena' ),
						'catalogStats' => __( 'U bazi: %1$d podobnih stavki, %2$d već ima sidrenu cijenu, %3$d još prazno; Woo roditeljskih proizvoda u obradi: %4$d.', 'mjdd-sidrena-cijena' ),
						'todayConfirm' => __( 'Ovo popunjava prazne sidrene cijene trenutnom redovnom cijenom i upisuje današnji datum kao referentni (samo ako datum još nije unesen). Proizvodi koji su postojali na 10.09.2026. trebaju ostati na zadanom datumu. Nastaviti?', 'mjdd-sidrena-cijena' ),
						'error'        => __( 'Došlo je do pogreške. Pokušajte ponovno.', 'mjdd-sidrena-cijena' ),
						'purgeConfirm' => __( 'Ovo će obrisati sve sidrene cijene, ručni cjenik, postavke, javni XML i arhivu, zatim deaktivirati dodatak. Radnja se ne može poništiti. Nastaviti?', 'mjdd-sidrena-cijena' ),
						'purging'      => __( 'Brisanje podataka…', 'mjdd-sidrena-cijena' ),
						'archiveEmpty' => __( 'Arhiva je prazna.', 'mjdd-sidrena-cijena' ),
						'archiveDeleteFail' => __( 'Datoteka nije obrisana.', 'mjdd-sidrena-cijena' ),
						'archiveDeleteAllConfirm' => __( 'Obrisati sve datoteke iz arhive? Važeći javni cjenik ostaje. Radnja se ne može poništiti.', 'mjdd-sidrena-cijena' ),
						'copied'       => __( 'Kopirano', 'mjdd-sidrena-cijena' ),
						'noFile'       => __( 'Odaberite CSV ili XML datoteku.', 'mjdd-sidrena-cijena' ),
						'analyzing'    => __( 'Čitanje datoteke…', 'mjdd-sidrena-cijena' ),
						'skuRequired'     => __( 'Odaberite stupac/čvor koji sadrži SKU.', 'mjdd-sidrena-cijena' ),
						'skuOrIdRequired' => __( 'Odaberite stupac za SKU ili ID.', 'mjdd-sidrena-cijena' ),
						'importPricesRequired' => __( 'Za nove stavke mapirajte naziv, redovnu cijenu i sidrena/baznu cijenu (ne „Ne uvozi“).', 'mjdd-sidrena-cijena' ),
						'pageStatus'      => __( 'Stranica %1$d od %2$d', 'mjdd-sidrena-cijena' ),
						'noImport'     => __( 'Ne uvozi', 'mjdd-sidrena-cijena' ),
						'chooseSku'    => __( 'Odaberite SKU polje', 'mjdd-sidrena-cijena' ),
						'importing'    => __( 'Uvoz u tijeku…', 'mjdd-sidrena-cijena' ),
						'importDone'   => __( 'Uvoz je gotov.', 'mjdd-sidrena-cijena' ),
						'rowsLabel'    => __( 'redova', 'mjdd-sidrena-cijena' ),
						'updated'      => __( 'ažurirano', 'mjdd-sidrena-cijena' ),
						'skipped'      => __( 'preskočeno', 'mjdd-sidrena-cijena' ),
						'missing'      => __( 'SKU nije pronađen', 'mjdd-sidrena-cijena' ),
						'created'      => __( 'dodano', 'mjdd-sidrena-cijena' ),
						'addProduct'    => __( 'Dodaj proizvod', 'mjdd-sidrena-cijena' ),
						'duplicate'     => __( 'Dupliciraj', 'mjdd-sidrena-cijena' ),
						'remove'        => __( 'Ukloni', 'mjdd-sidrena-cijena' ),
						'addRow'        => __( 'Dodaj redak', 'mjdd-sidrena-cijena' ),
						'copyShortcode' => __( 'Kopiraj shortcode', 'mjdd-sidrena-cijena' ),
						'saveForCode'   => __( 'Spremi cjenik za shortcode', 'mjdd-sidrena-cijena' ),
						'dostupno'      => __( 'Dostupno', 'mjdd-sidrena-cijena' ),
						'nedostupno'    => __( 'Nedostupno', 'mjdd-sidrena-cijena' ),
						'cjm'           => __( 'CJM', 'mjdd-sidrena-cijena' ),
						'cjmPlaceholder' => __( 'za kg/l', 'mjdd-sidrena-cijena' ),
						'oblikPlaceholder' => 'akcija',
						'jedinicaPlaceholder' => 'kg',
						'datePlaceholder'  => (string) Sidrena_Cijena_Settings::instance()->get( 'default_date' ),
						'vrstaProizvod'    => __( 'Proizvod', 'mjdd-sidrena-cijena' ),
						'vrstaUsluga'      => __( 'Usluga', 'mjdd-sidrena-cijena' ),
						'webshop'          => __( 'Webshop', 'mjdd-sidrena-cijena' ),
						'rowCount'       => Sidrena_Cijena_Plugin::woocommerce_active() ? __( '%d stavki', 'mjdd-sidrena-cijena' ) : __( '%d proizvoda', 'mjdd-sidrena-cijena' ),
						'outletName'     => __( 'Naziv', 'mjdd-sidrena-cijena' ),
						'outletNameHelp' => __( 'Samo za vas u administraciji. Ne ulazi u naziv datoteke.', 'mjdd-sidrena-cijena' ),
						'outletOblik'    => __( 'Oblik prodajnog objekta', 'mjdd-sidrena-cijena' ),
						'outletOblikHelp' => __( 'Kako je napisano, npr. prodavaonica, webshop ili servis.', 'mjdd-sidrena-cijena' ),
						'outletAddress'  => __( 'Adresa prodajnog objekta', 'mjdd-sidrena-cijena' ),
						'outletAddressHelp' => __( 'Ulica i mjesto ostaju u nazivu, npr. Ilica 150 Zagreb.', 'mjdd-sidrena-cijena' ),
						'outletCode'     => __( 'Oznaka prodajnog objekta', 'mjdd-sidrena-cijena' ),
						'outletCodeHelp' => __( 'Interna oznaka, npr. P-01 ili 001. Crtica ostaje. Ulazi u adresu cjenika: /sidrena-cijena-P-01.xml.', 'mjdd-sidrena-cijena' ),
						'outletSeq'      => __( 'Sljedeći broj pohrane robe', 'mjdd-sidrena-cijena' ),
						'outletSeqHelp'  => __( 'Raste jednom na dan, kad se cjenik robe te poslovnice promijeni.', 'mjdd-sidrena-cijena' ),
						'outletSeqUsluge' => __( 'Sljedeći broj pohrane usluga', 'mjdd-sidrena-cijena' ),
						'outletSeqUslugeHelp' => __( 'Raste jednom na dan, kad se cjenik usluga te poslovnice promijeni.', 'mjdd-sidrena-cijena' ),
						'outletProducts' => __( 'Proizvodi', 'mjdd-sidrena-cijena' ),
						'outletProductsHelp' => __( 'U cjenik ove poslovnice ulaze samo odabrani proizvodi.', 'mjdd-sidrena-cijena' ),
						'searchProducts' => __( 'Traži proizvod…', 'mjdd-sidrena-cijena' ),
						'noCatalog'      => __( 'Prvo dodajte proizvode u kartici Proizvodi.', 'mjdd-sidrena-cijena' ),
						'chooseOutlet'   => __( 'Odaberite poslovnicu u koju se uvozi.', 'mjdd-sidrena-cijena' ),
						'removeOutlet'   => __( 'Ukloni poslovnicu', 'mjdd-sidrena-cijena' ),
						'catalogRequired' => __( 'Svaki redak mora imati naziv, redovnu i baznu cijenu. Prazni retci se ne spremaju.', 'mjdd-sidrena-cijena' ),
					),
				)
			);

			wp_enqueue_script(
				'sidrena-cijena-admin-catalog',
				SIDRENA_CIJENA_URL . 'assets/js/admin-catalog.js',
				array( 'sidrena-cijena-admin' ),
				SIDRENA_CIJENA_VERSION,
				true
			);
			wp_enqueue_script(
				'sidrena-cijena-admin-import',
				SIDRENA_CIJENA_URL . 'assets/js/admin-import.js',
				array( 'sidrena-cijena-admin' ),
				SIDRENA_CIJENA_VERSION,
				true
			);
			wp_enqueue_script(
				'sidrena-cijena-admin-outlets',
				SIDRENA_CIJENA_URL . 'assets/js/admin-outlets.js',
				array( 'sidrena-cijena-admin' ),
				SIDRENA_CIJENA_VERSION,
				true
			);
		}
	}

	/**
	 * AJAX batch snapshot from the settings page.
	 */
	public function ajax_snapshot() {
		Sidrena_Cijena_Admin_Save::instance()->ajax_snapshot();
	}

	/**
	 * One step of the public-catalog progress job.
	 */
	public function ajax_feed_job() {
		Sidrena_Cijena_Admin_Save::instance()->ajax_feed_job();
	}

	/**
	 * Wipe plugin data and deactivate.
	 */
	public function ajax_purge() {
		Sidrena_Cijena_Admin_Save::instance()->ajax_purge();
	}

	/**
	 * Delete one archived cjenik file.
	 */
	public function ajax_archive_delete() {
		Sidrena_Cijena_Admin_Save::instance()->ajax_archive_delete();
	}

	/**
	 * Delete every archived file from the Javni cjenik list.
	 */
	public function ajax_archive_delete_all() {
		Sidrena_Cijena_Admin_Save::instance()->ajax_archive_delete_all();
	}

	/**
	 * Simple / external product fields under sale price.
	 */
	public function render_simple_fields() {
		Sidrena_Cijena_Product_Fields::instance()->render_simple_fields();
	}

	/**
	 * Variation fields under variation sale price.
	 *
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) {
		Sidrena_Cijena_Product_Fields::instance()->render_variation_fields( $loop, $variation_data, $variation );
	}


	/**
	 * Persist simple product fields.
	 *
	 * @param WC_Product $product Product.
	 */
	public function save_simple_fields( $product ) {
		Sidrena_Cijena_Fields::save_simple( $product );
	}

	/**
	 * Persist variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Loop index.
	 */
	public function save_variation_fields( $variation_id, $i ) {
		Sidrena_Cijena_Fields::save_variation( $variation_id, $i );
	}

	/**
	 * Product list column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_product_column( $columns ) {
		$columns['sidrena_cijena'] = __( 'Bazna cijena', 'mjdd-sidrena-cijena' );
		return $columns;
	}

	/**
	 * Render product list column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	/**
	 * Bulk edit: vrsta for the public feed.
	 */
	public function bulk_edit_vrsta_field() {
		?>
		<label>
			<span class="title"><?php echo esc_html__( 'Sidrena vrsta', 'mjdd-sidrena-cijena' ); ?></span>
			<span class="input-text-wrap">
				<select class="sidrena-vrsta-bulk" name="sidrena_vrsta_bulk">
					<option value=""><?php echo esc_html__( '- Bez promjene -', 'mjdd-sidrena-cijena' ); ?></option>
					<option value="proizvod"><?php echo esc_html__( 'Proizvod', 'mjdd-sidrena-cijena' ); ?></option>
					<option value="usluga"><?php echo esc_html__( 'Usluga', 'mjdd-sidrena-cijena' ); ?></option>
				</select>
			</span>
		</label>
		<label>
			<span class="title"><?php echo esc_html__( 'Javni cjenik', 'mjdd-sidrena-cijena' ); ?></span>
			<span class="input-text-wrap">
				<select class="sidrena-iskljuci-javni-bulk" name="sidrena_iskljuci_javni_bulk">
					<option value=""><?php echo esc_html__( '- Bez promjene -', 'mjdd-sidrena-cijena' ); ?></option>
					<option value="yes"><?php echo esc_html__( 'Isključi', 'mjdd-sidrena-cijena' ); ?></option>
					<option value="no"><?php echo esc_html__( 'Uključi', 'mjdd-sidrena-cijena' ); ?></option>
				</select>
			</span>
		</label>
		<?php
	}

	/**
	 * @param WC_Product $product Product being bulk-edited.
	 */
	public function bulk_edit_save_vrsta( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_REQUEST['sidrena_vrsta_bulk'] ) ) {
			return;
		}

		$vrsta = Sidrena_Cijena_Money::normalize_vrsta( wp_unslash( $_REQUEST['sidrena_vrsta_bulk'] ) );
		$meta  = Sidrena_Cijena_Product_Meta::instance();
		$ids   = array( $product->get_id() );

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$ids[] = (int) $child_id;
			}
		}

		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				$meta->set_vrsta( $id, $vrsta );
			}
		}
	}

	/**
	 * Bulk edit: include or exclude from the public cjenik.
	 *
	 * @param WC_Product $product Product being bulk-edited.
	 */
	public function bulk_edit_save_iskljuci( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['sidrena_iskljuci_javni_bulk'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$choice = sanitize_text_field( wp_unslash( $_REQUEST['sidrena_iskljuci_javni_bulk'] ) );
		if ( 'yes' !== $choice && 'no' !== $choice ) {
			return;
		}

		$meta = Sidrena_Cijena_Product_Meta::instance();
		$ids  = array( $product->get_id() );

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$ids[] = (int) $child_id;
			}
		}

		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				$meta->set_excluded_from_public_feed( $id, 'yes' === $choice );
			}
		}
	}

	public function render_product_column( $column, $post_id ) {
		if ( 'sidrena_cijena' !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '-';
			return;
		}

		$meta = Sidrena_Cijena_Product_Meta::instance();

		if ( $product->is_type( 'variable' ) ) {
			$prices = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}

				$display = $meta->get_price_to_display( $variation );
				if ( null !== $display ) {
					$prices[] = $display;
				}
			}

			if ( empty( $prices ) ) {
				echo '-';
				return;
			}

			$min = min( $prices );
			$max = max( $prices );
			if ( $min === $max ) {
				echo wp_kses_post( wc_price( $min ) );
				return;
			}

			echo wp_kses_post( wc_price( $min ) . ' - ' . wc_price( $max ) );
			return;
		}

		$html = $meta->format_price_html( $product );
		echo '' === $html ? '-' : wp_kses_post( $html );
	}
}
