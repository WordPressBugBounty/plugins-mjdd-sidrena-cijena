<?php
/**
 * WooCommerce product and variation editor fields.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product editor UI for sidrena fields.
 */
class Sidrena_Cijena_Product_Fields {

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
	 * Simple / external product fields under sale price.
	 */
	public function render_simple_fields() {
		$product_id = get_the_ID();
		$meta       = Sidrena_Cijena_Product_Meta::instance();
		$cjm_on     = ( 'yes' === Sidrena_Cijena_Settings::instance()->get( 'enable_cjm' ) );

		echo '<div class="options_group sidrena-cijena-admin-fields">';

		echo '<div class="sidrena-cijena-price-row">';

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_cijena',
				'name'        => 'sidrena_cijena',
				'label'       => __( 'Bazna cijena', 'mjdd-sidrena-cijena' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'desc_tip'    => true,
				'description' => __( 'Redovna maloprodajna cijena na referentni dan (2. 5. 2025. za FMCG, 10. 9. 2026. za ostalo). Unesite je u istom obliku kao redovnu cijenu (s ili bez PDV-a, prema WooCommerce postavci poreza). Ne unosite akcijsku cijenu.', 'mjdd-sidrena-cijena' ),
				'value'       => $meta->get_price( $product_id ),
				'data_type'   => 'price',
				'wrapper_class' => 'sidrena-cijena-price-field',
			)
		);

		if ( $cjm_on ) {
			$this->render_cjm_controls(
				$product_id,
				'sidrena_cijena_cjm',
				'sidrena_cijena_cjm_suffix',
				$meta->is_cjm_enabled( $product_id ),
				$meta->get_cjm_suffix( $product_id )
			);
		}

		echo '</div>';

		$product = wc_get_product( $product_id );
		if ( $product && ! $product->is_type( 'variable' ) ) {
			$this->render_vrsta_field( 'sidrena_vrsta', $meta->get_vrsta( $product_id ) );
			$this->render_exclude_field( 'sidrena_iskljuci_javni', $meta->is_excluded_from_public_feed( $product_id ) );
		}

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_cijena_datum',
				'name'        => 'sidrena_cijena_datum',
				'label'       => __( 'Referentni datum', 'mjdd-sidrena-cijena' ),
				'placeholder' => Sidrena_Cijena_Settings::instance()->get( 'default_date' ),
				'desc_tip'    => true,
				'description' => __( 'Ostavi prazno za zadani datum iz postavki. Za hranu, piće, kozmetiku i kućanstvo: 02.05.2025. Ako je taj dan bio na akciji, upišite najnižu cijenu u 30 dana prije akcije. Dodatak je ne računa.', 'mjdd-sidrena-cijena' ),
				'value'       => $meta->get_date( $product_id ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_naziv_posebnog_oblika',
				'name'        => 'sidrena_naziv_posebnog_oblika',
				'label'       => __( 'Naziv posebnog oblika prodaje', 'mjdd-sidrena-cijena' ),
				'placeholder' => 'akcija',
				'desc_tip'    => true,
				'description' => __( 'Dok je akcijska cijena aktivna, javni cjenik u stupcu poseban oblik upisuje DA. Ovdje upišite naziv za HTML tablicu. Bez aktivne akcijske cijene stupac je NE.', 'mjdd-sidrena-cijena' ),
				'value'       => $meta->get_oblik( $product_id ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_jedinica_mjere',
				'name'        => 'sidrena_jedinica_mjere',
				'label'       => __( 'Jedinica mjere', 'mjdd-sidrena-cijena' ),
				'placeholder' => 'kg',
				'desc_tip'    => true,
				'description' => __( 'Jedinica u javnom cjeniku, npr. kg, l ili kom. Prazno ako proizvod nema cijenu za jedinicu mjere.', 'mjdd-sidrena-cijena' ),
				'value'       => $meta->get_jedinica( $product_id ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_neto_kolicina',
				'name'        => 'sidrena_neto_kolicina',
				'label'       => __( 'Neto količina', 'mjdd-sidrena-cijena' ),
				'desc_tip'    => true,
				'description' => __( 'Samo kad za ovu robu morate isticati cijenu za jedinicu mjere. Inače ostavite prazno.', 'mjdd-sidrena-cijena' ),
				'value'       => $meta->get_neto( $product_id ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => 'sidrena_cijena_jedinice_mjere',
				'name'          => 'sidrena_cijena_jedinice_mjere',
				'label'         => __( 'Cijena za jedinicu mjere', 'mjdd-sidrena-cijena' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'desc_tip'      => true,
				'description'   => __( 'Isti oblik kao redovna cijena. Objavljuje se uz maloprodajnu cijenu. Prazno ako nije primjenjivo.', 'mjdd-sidrena-cijena' ),
				'value'         => $meta->get_jedinica_cijena( $product_id ),
				'data_type'     => 'price',
			)
		);

		echo '</div>';
	}

	/**
	 * CJM toggle + suffix field next to a sidrena price input.
	 *
	 * @param int    $product_id Product ID (unused, kept for callers).
	 * @param string $toggle_name Checkbox name.
	 * @param string $suffix_name Suffix input name.
	 * @param bool   $enabled     Current toggle.
	 * @param string $suffix      Current suffix.
	 */
	public function render_cjm_controls( $product_id, $toggle_name, $suffix_name, $enabled, $suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		?>
		<p class="form-field sidrena-cijena-cjm">
			<label for="<?php echo esc_attr( $toggle_name ); ?>"><?php echo esc_html__( 'CJM', 'mjdd-sidrena-cijena' ); ?></label>
			<?php
			Sidrena_Cijena_Admin::instance()->render_toggle(
				$toggle_name,
				$toggle_name,
				$enabled,
				__( 'Cijena za jedinicu mjere', 'mjdd-sidrena-cijena' ),
				'sidrena-cijena-cjm-toggle'
			);
			?>
			<span class="sidrena-cijena-cjm-suffix" <?php echo $enabled ? '' : 'hidden'; ?>>
				<input type="text" name="<?php echo esc_attr( $suffix_name ); ?>" value="<?php echo esc_attr( $suffix ); ?>" placeholder="<?php echo esc_attr__( 'za kg/l', 'mjdd-sidrena-cijena' ); ?>" />
				<span class="description"><?php echo esc_html__( 'Dodaje se u prefix: Cijena za kg/l', 'mjdd-sidrena-cijena' ); ?></span>
			</span>
		</p>
		<?php
	}

	/**
	 * Variation fields under variation sale price.
	 *
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$meta   = Sidrena_Cijena_Product_Meta::instance();
		$id     = $variation->ID;
		$cjm_on = ( 'yes' === Sidrena_Cijena_Settings::instance()->get( 'enable_cjm' ) );
		$cjm    = $meta->is_cjm_enabled( $id );
		?>
		<div class="sidrena-cijena-variation-fields">
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Bazna cijena', 'mjdd-sidrena-cijena' ) . ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
				<input type="text" class="short wc_input_price" name="sidrena_cijena[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $meta->get_price( $id ) ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Vrsta', 'mjdd-sidrena-cijena' ); ?></label>
				<select name="sidrena_vrsta[<?php echo esc_attr( (string) $loop ); ?>]">
					<?php $this->render_vrsta_options( $meta->get_vrsta( $id ) ); ?>
				</select>
			</p>
			<p class="form-row form-row-full">
				<label>
					<input type="checkbox" name="sidrena_iskljuci_javni[<?php echo esc_attr( (string) $loop ); ?>]" value="yes" <?php checked( $meta->is_excluded_from_public_feed( $id ) ); ?> />
					<?php echo esc_html__( 'Isključi iz javnog cjenika', 'mjdd-sidrena-cijena' ); ?>
				</label>
			</p>
			<?php if ( $cjm_on ) : ?>
				<p class="form-row form-row-last sidrena-cijena-cjm">
					<label><?php echo esc_html__( 'CJM', 'mjdd-sidrena-cijena' ); ?></label>
					<?php
					Sidrena_Cijena_Admin::instance()->render_toggle(
						'sidrena_cijena_cjm[' . (string) $loop . ']',
						'sidrena_cijena_cjm_' . (string) $loop,
						$cjm,
						__( 'Jedinica mjere', 'mjdd-sidrena-cijena' ),
						'sidrena-cijena-cjm-toggle'
					);
					?>
				</p>
				<p class="form-row form-row-full sidrena-cijena-cjm-suffix" <?php echo $cjm ? '' : 'hidden'; ?>>
					<label><?php echo esc_html__( 'CJM dodatak prefixu', 'mjdd-sidrena-cijena' ); ?></label>
					<input type="text" class="short" name="sidrena_cijena_cjm_suffix[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_cjm_suffix( $id ) ); ?>" placeholder="<?php echo esc_attr__( 'za kg/l', 'mjdd-sidrena-cijena' ); ?>" />
				</p>
			<?php endif; ?>
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Referentni datum', 'mjdd-sidrena-cijena' ); ?></label>
				<input type="text" class="short" name="sidrena_cijena_datum[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_date( $id ) ); ?>" placeholder="<?php echo esc_attr( Sidrena_Cijena_Settings::instance()->get( 'default_date' ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Naziv posebnog oblika prodaje', 'mjdd-sidrena-cijena' ); ?></label>
				<input type="text" class="short" name="sidrena_naziv_posebnog_oblika[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_oblik( $id ) ); ?>" placeholder="akcija" />
			</p>
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Jedinica mjere', 'mjdd-sidrena-cijena' ); ?></label>
				<input type="text" class="short" name="sidrena_jedinica_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_jedinica( $id ) ); ?>" placeholder="kg" />
			</p>
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Neto količina', 'mjdd-sidrena-cijena' ); ?></label>
				<input type="text" class="short" name="sidrena_neto_kolicina[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_neto( $id ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Cijena za jedinicu mjere', 'mjdd-sidrena-cijena' ) . ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
				<input type="text" class="short wc_input_price" name="sidrena_cijena_jedinice_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $meta->get_jedinica_cijena( $id ) ) ); ?>" />
			</p>
		</div>
		<?php
	}

	/**
	 * Vrsta select on the product pricing tab (below sidrena price).
	 *
	 * @param string $name  Input name.
	 * @param string $value Current value.
	 */
	public function render_vrsta_field( $name, $value ) {
		woocommerce_wp_select(
			array(
				'id'          => $name,
				'name'        => $name,
				'label'       => __( 'Vrsta', 'mjdd-sidrena-cijena' ),
				'value'       => Sidrena_Cijena_Money::normalize_vrsta( $value ),
				'options'     => array(
					'proizvod' => __( 'Proizvod', 'mjdd-sidrena-cijena' ),
					'usluga'   => __( 'Usluga', 'mjdd-sidrena-cijena' ),
				),
				'desc_tip'    => true,
				'description' => __( 'Roba i usluge idu u zasebne javne datoteke. Virtualni proizvod bez spremljene vrste ide u usluge.', 'mjdd-sidrena-cijena' ),
			)
		);
	}

	/**
	 * Leave this product out of the public XML, CSV, and HTML cjenik.
	 *
	 * @param string $name    Input name.
	 * @param bool   $checked Current value.
	 */
	public function render_exclude_field( $name, $checked ) {
		woocommerce_wp_checkbox(
			array(
				'id'          => $name,
				'name'        => $name,
				'label'       => __( 'Javni cjenik', 'mjdd-sidrena-cijena' ),
				'value'       => $checked ? 'yes' : 'no',
				'cbvalue'     => 'yes',
				'description' => __( 'Isključi iz javnog cjenika', 'mjdd-sidrena-cijena' ),
			)
		);
	}

	/**
	 * @param string $selected Selected slug.
	 */
	private function render_vrsta_options( $selected ) {
		$selected = Sidrena_Cijena_Money::normalize_vrsta( $selected );
		$options  = array(
			'proizvod' => __( 'Proizvod', 'mjdd-sidrena-cijena' ),
			'usluga'   => __( 'Usluga', 'mjdd-sidrena-cijena' ),
		);
		foreach ( $options as $key => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $selected, $key, false ),
				esc_html( $label )
			);
		}
	}
}
