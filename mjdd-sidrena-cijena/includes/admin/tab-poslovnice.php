<?php
/**
 * Settings tab markup. Included from Sidrena_Cijena_Admin.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

		$settings = Sidrena_Cijena_Settings::instance();
		$export   = Sidrena_Cijena_Export::instance();
		$rows     = Sidrena_Cijena_Poslovnice::instance()->all();
		$json     = wp_json_encode( $rows );
		if ( ! is_string( $json ) ) {
			$json = '[]';
		}

		$adresa  = (string) $settings->get( 'file_adresa', '' );
		$webshop = $settings->publishes_webshop_feed();
		?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					$webshop
						? __( 'Webshop je zadana poslovnica. OIB ili matični broj, razred prodajnog mjesta, adresa, oznaka i broj pohrane ulaze u službeni naziv datoteke (NN 132/2026). Roba i usluge su zasebne datoteke. Jedna datoteka po danu: isti dan je prepisuje, a zamijenjena ostaje 15 dana.', 'mjdd-sidrena-cijena' )
						: __( 'Bez WooCommercea nema zasebnog webshop cjenika. Svaka poslovnica je prodajni objekt. Roba i usluge su zasebne datoteke. Jedna datoteka po danu: isti dan je prepisuje.', 'mjdd-sidrena-cijena' )
				);
				?>
			</p>
		</div>

		<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'poslovnice' ) ); ?>" id="sidrena-poslovnice-form">
			<?php wp_nonce_field( 'sidrena_cijena_save_poslovnice', 'sidrena_cijena_poslovnice_nonce' ); ?>
			<input type="hidden" name="sidrena_cijena_action" value="save_poslovnice" />
			<input type="hidden" name="sidrena_cijena_poslovnice_json" id="sidrena-poslovnice-json" value="<?php echo esc_attr( $json ); ?>" />

			<section class="sidrena-cijena-card">
				<?php if ( $webshop ) : ?>
				<div class="sidrena-cijena-card__head">
					<h2><?php echo esc_html__( 'Webshop', 'mjdd-sidrena-cijena' ); ?></h2>
					<p><?php echo esc_html__( 'Javni cjenik webshopa: /sidrena-cijena-robe.xml i /sidrena-cijena-usluge.xml. /sidrena-cijena.xml ostaje alias.', 'mjdd-sidrena-cijena' ); ?></p>
				</div>
				<?php else : ?>
				<div class="sidrena-cijena-card__head">
					<h2><?php echo esc_html__( 'Poslovnice', 'mjdd-sidrena-cijena' ); ?></h2>
					<p><?php echo esc_html__( 'Prva poslovnica je vaš objekt. Webshop adresa se ne objavljuje dok WooCommerce nije uključen.', 'mjdd-sidrena-cijena' ); ?></p>
				</div>
				<?php endif; ?>

				<article class="sidrena-poslovnica sidrena-poslovnica--webshop">
					<div class="sidrena-poslovnica__grid">
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_trader_kind"><?php echo esc_html__( 'Trgovac', 'mjdd-sidrena-cijena' ); ?></label>
							<select id="sidrena_cijena_trader_kind" name="sidrena_cijena_trader_kind">
								<option value="oib" <?php selected( $settings->get( 'trader_kind', 'oib' ), 'oib' ); ?>><?php echo esc_html__( 'Pravna osoba (OIB)', 'mjdd-sidrena-cijena' ); ?></option>
								<option value="maticni" <?php selected( $settings->get( 'trader_kind', 'oib' ), 'maticni' ); ?>><?php echo esc_html__( 'Fizička osoba (matični broj)', 'mjdd-sidrena-cijena' ); ?></option>
							</select>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_trader_id"><?php echo esc_html__( 'OIB ili matični broj', 'mjdd-sidrena-cijena' ); ?></label>
							<input type="text" class="regular-text" id="sidrena_cijena_trader_id" name="sidrena_cijena_trader_id" value="<?php echo esc_attr( (string) $settings->get( 'trader_id', '' ) ); ?>" />
						</p>
						<p class="sidrena-poslovnica__field sidrena-poslovnica__field--wide">
							<?php
							$this->render_toggle(
								'sidrena_cijena_file_za_sva',
								'sidrena_cijena_file_za_sva',
								'yes' === $settings->get( 'file_za_sva', 'no' ),
								__( 'Isti cjenik za sva prodajna mjesta', 'mjdd-sidrena-cijena' )
							);
							?>
							<span class="description"><?php echo esc_html__( 'U naziv ostaje razred prodajnog mjesta, a umjesto adrese i oznake ide ZA_SVA_PRODAJNA_MJESTA. Zasebne datoteke po poslovnici se ne objavljuju.', 'mjdd-sidrena-cijena' ); ?></span>
						</p>
						<div id="sidrena-za-sva-notice" class="notice notice-warning inline" <?php echo 'yes' === $settings->get( 'file_za_sva', 'no' ) ? '' : 'hidden'; ?>>
							<p><?php echo esc_html__( 'Spremanjem se brišu zasebne javne datoteke svake poslovnice (roba i usluge, XML i CSV). Kopija ostaje u arhivi 15 dana. Ostaje jedna datoteka za sva prodajna mjesta. Isto vrijedi za datoteke iz e-računa.', 'mjdd-sidrena-cijena' ); ?></p>
						</div>
					</div>
					<?php if ( $webshop ) : ?>
					<div class="sidrena-poslovnica__grid">
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_oblik"><?php echo esc_html__( 'Razred prodajnog mjesta', 'mjdd-sidrena-cijena' ); ?></label>
							<input type="text" class="regular-text" id="sidrena_cijena_file_oblik" name="sidrena_cijena_file_oblik" value="<?php echo esc_attr( (string) $settings->get( 'file_oblik', 'webshop' ) ); ?>" />
							<span class="description"><?php echo esc_html__( 'Kako je napisano, npr. webshop ili prodavaonica.', 'mjdd-sidrena-cijena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_adresa"><?php echo esc_html__( 'Adresa prodajnog objekta', 'mjdd-sidrena-cijena' ); ?></label>
							<input type="text" class="regular-text" id="sidrena_cijena_file_adresa" name="sidrena_cijena_file_adresa" value="<?php echo esc_attr( $adresa ); ?>" placeholder="<?php echo esc_attr( $settings->default_file_address() ); ?>" />
							<span class="description"><?php echo esc_html__( 'Ulica i mjesto ostaju u nazivu, npr. Ilica 150 Zagreb.', 'mjdd-sidrena-cijena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_oznaka"><?php echo esc_html__( 'Oznaka prodajnog objekta', 'mjdd-sidrena-cijena' ); ?></label>
							<input type="text" class="small-text" id="sidrena_cijena_file_oznaka" name="sidrena_cijena_file_oznaka" value="<?php echo esc_attr( (string) $settings->get( 'file_oznaka', '001' ) ); ?>" />
							<span class="description"><?php echo esc_html__( 'Interna oznaka, npr. WEB ili 001. Crtica ostaje.', 'mjdd-sidrena-cijena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_pohrana_robe"><?php echo esc_html__( 'Sljedeći broj pohrane robe', 'mjdd-sidrena-cijena' ); ?></label>
							<input type="number" class="sidrena-cijena-input-number" id="sidrena_cijena_file_pohrana_robe" name="sidrena_cijena_file_pohrana_robe" min="1" step="1" value="<?php echo esc_attr( (string) (int) $settings->get( 'file_pohrana_robe', 1 ) ); ?>" />
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_pohrana_usluge"><?php echo esc_html__( 'Sljedeći broj pohrane usluga', 'mjdd-sidrena-cijena' ); ?></label>
							<input type="number" class="sidrena-cijena-input-number" id="sidrena_cijena_file_pohrana_usluge" name="sidrena_cijena_file_pohrana_usluge" min="1" step="1" value="<?php echo esc_attr( (string) (int) $settings->get( 'file_pohrana_usluge', 1 ) ); ?>" />
							<span class="description"><?php echo esc_html__( 'Raste jednom na dan, kad se taj cjenik stvarno promijeni.', 'mjdd-sidrena-cijena' ); ?></span>
						</p>
					</div>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: example filename */
								__( 'Sljedeći službeni naziv robe: %s', 'mjdd-sidrena-cijena' ),
								$export->legal_filename( '', time(), 0, null, 'robe' )
							)
						);
						?>
					</p>
					<?php endif; ?>

					<div class="sidrena-poslovnice">
						<div class="sidrena-poslovnice__head">
							<h3><?php echo esc_html( $webshop ? __( 'Dodatne poslovnice', 'mjdd-sidrena-cijena' ) : __( 'Poslovnice', 'mjdd-sidrena-cijena' ) ); ?></h3>
							<button type="button" class="button button-secondary" id="sidrena-poslovnica-add">
								<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
								<?php echo esc_html__( 'Dodaj poslovnicu', 'mjdd-sidrena-cijena' ); ?>
							</button>
						</div>
						<p class="description"><?php echo esc_html__( 'Svaka poslovnica dobiva /sidrena-cijena-robe-OZNAKA i /sidrena-cijena-usluge-OZNAKA. Arhiva se čuva 15 dana od zamjene.', 'mjdd-sidrena-cijena' ); ?></p>
						<div id="sidrena-poslovnice"></div>
					</div>
				</article>
			</section>

			<?php $this->render_save_button( __( 'Spremi poslovnice', 'mjdd-sidrena-cijena' ) ); ?>
		</form>
		<?php
