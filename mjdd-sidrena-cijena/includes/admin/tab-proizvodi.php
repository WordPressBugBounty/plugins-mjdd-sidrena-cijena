<?php
/**
 * Settings tab markup. Included from Sidrena_Cijena_Admin.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

		$rows = Sidrena_Cijena_Catalog::instance()->all();
		$json = wp_json_encode( $rows );
		if ( ! is_string( $json ) ) {
			$json = '[]';
		}

		$upit_rows = Sidrena_Cijena_Upit::instance()->all();
		$upit_json = wp_json_encode( $upit_rows );
		if ( ! is_string( $upit_json ) ) {
			$upit_json = '[]';
		}

		$woo        = Sidrena_Cijena_Plugin::woocommerce_active();
		$cjenik_url = Sidrena_Cijena_Export::instance()->get_cjenik_url();
		?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					$woo
						? __( 'Usluge i proizvodi koji nisu u WooCommerceu (npr. po narudžbi ili razred dostave). Naziv, redovna cijena i sidrena cijena su obavezni. Vrsta određuje stupce javnog cjenika. Datum ostavite prazan za zadani referentni dan, ili upišite dan prvog uvrštenja. Ako imate više poslovnica, iznad tablice odaberite webshop ili poslovnicu - retci idu samo u taj cjenik. Dostupnost je padajući izbornik: dostupno ili nedostupno. Shortcode [sidrena-cijena id="dX"] prikazuje cijene tog retka na stranici. Za samo jedan iznos dodajte prikaz="redovna", prikaz="akcijska" ili prikaz="sidrena".', 'mjdd-sidrena-cijena' )
						: __( 'Svaki redak postaje stavka javnog cjenika (XML/CSV i HTML). Naziv, redovna cijena i sidrena cijena su obavezni. Vrsta određuje stupce: proizvod ili usluga. Datum ostavite prazan za zadani referentni dan, ili upišite dan prvog uvrštenja. Ako imate više poslovnica, iznad tablice odaberite webshop ili poslovnicu. Dostupnost je padajući izbornik: dostupno ili nedostupno. Prazni retci se ne spremaju. Shortcode [sidrena-cijena id="x"] na bilo kojoj stranici prikazuje cijene iz ovog retka. Za samo jedan iznos dodajte prikaz="redovna", prikaz="akcijska" ili prikaz="sidrena". Cijelu tablicu ubacite shortcodeom [sidrena-cjenik].', 'mjdd-sidrena-cijena' )
				);
				?>
			</p>
		</div>

		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html( $woo ? __( 'Dodatne stavke', 'mjdd-sidrena-cijena' ) : __( 'Proizvodi', 'mjdd-sidrena-cijena' ) ); ?></h2>
				<p>
					<?php
					if ( $woo ) {
						echo esc_html(
							sprintf(
								/* translators: %d row count */
								__( 'Trenutno %d stavki. Dodaju se na kraj javnog cjenika.', 'mjdd-sidrena-cijena' ),
								count( $rows )
							)
						);
					} else {
						echo esc_html(
							sprintf(
								/* translators: %d product count */
								__( 'Trenutno %d proizvoda. Javna stranica:', 'mjdd-sidrena-cijena' ),
								count( $rows )
							)
						);
					}
					echo ' ';
					if ( $woo ) {
						echo esc_html__( 'HTML:', 'mjdd-sidrena-cijena' );
						echo ' ';
					}
					?>
					<a href="<?php echo esc_url( $cjenik_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cjenik_url ); ?></a>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'proizvodi' ) ); ?>" id="sidrena-cijena-catalog-form">
				<?php wp_nonce_field( 'sidrena_cijena_save_catalog', 'sidrena_cijena_catalog_nonce' ); ?>
				<input type="hidden" name="sidrena_cijena_action" value="save_catalog" />
				<input type="hidden" name="sidrena_cijena_catalog_json" id="sidrena-cijena-catalog-json" value="<?php echo esc_attr( $json ); ?>" />
				<input type="hidden" name="sidrena_cijena_upit_json" id="sidrena-cijena-upit-json" value="<?php echo esc_attr( $upit_json ); ?>" />

				<div class="sidrena-cijena-catalog-toolbar">
					<div class="sidrena-cijena-catalog-toolbar__start">
						<?php if ( Sidrena_Cijena_Settings::instance()->is_multi_poslovnica() ) : ?>
						<label class="sidrena-cijena-catalog-scope" for="sidrena-cijena-catalog-scope">
							<span><?php echo esc_html__( 'Poslovnica', 'mjdd-sidrena-cijena' ); ?></span>
							<select id="sidrena-cijena-catalog-scope">
								<option value=""><?php echo esc_html__( 'Webshop', 'mjdd-sidrena-cijena' ); ?></option>
								<?php foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) : ?>
									<?php $outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka']; ?>
									<option value="<?php echo esc_attr( $outlet['oznaka'] ); ?>"><?php echo esc_html( $outlet_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php endif; ?>
						<button type="button" class="button button-secondary" id="sidrena-cijena-catalog-add">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<?php echo esc_html( $woo ? __( 'Dodaj stavku', 'mjdd-sidrena-cijena' ) : __( 'Dodaj proizvod', 'mjdd-sidrena-cijena' ) ); ?>
						</button>
					</div>
					<p class="description sidrena-cijena-catalog-count" id="sidrena-cijena-catalog-count"></p>
				</div>

				<div class="sidrena-cijena-catalog-wrap">
					<table class="sidrena-cijena-catalog" id="sidrena-cijena-catalog">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Naziv', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Vrsta', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'SKU', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Marka', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'URL', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Redovna cijena', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Akcijska cijena', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Poseban oblik', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Jedinica mjere', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Neto količina', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Cijena za jedinicu', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Bazna cijena', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Datum bazne cijene', 'mjdd-sidrena-cijena' ); ?></th>
								<?php if ( Sidrena_Cijena_Settings::instance()->is_cjm_enabled() ) : ?>
								<th><?php echo esc_html__( 'Mjerna jedinica', 'mjdd-sidrena-cijena' ); ?></th>
								<?php endif; ?>
								<th><?php echo esc_html__( 'Dostupnost', 'mjdd-sidrena-cijena' ); ?></th>
								<th><?php echo esc_html__( 'Shortcode', 'mjdd-sidrena-cijena' ); ?></th>
								<th><span class="screen-reader-text"><?php echo esc_html__( 'Radnje', 'mjdd-sidrena-cijena' ); ?></span></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>

				<nav class="sidrena-cijena-catalog-pager" id="sidrena-cijena-catalog-pager" hidden>
					<button type="button" class="button" id="sidrena-cijena-catalog-prev"><?php echo esc_html__( 'Prethodna', 'mjdd-sidrena-cijena' ); ?></button>
					<span id="sidrena-cijena-catalog-page"></span>
					<button type="button" class="button" id="sidrena-cijena-catalog-next"><?php echo esc_html__( 'Sljedeća', 'mjdd-sidrena-cijena' ); ?></button>
				</nav>

				<div class="sidrena-cijena-upit-block">
					<h3 class="sidrena-cijena-upit-head"><?php echo esc_html__( 'Cijena na upit', 'mjdd-sidrena-cijena' ); ?></h3>
					<p class="description"><?php echo esc_html__( 'Ovi retci ispisuju se na dnu HTML cjenika.', 'mjdd-sidrena-cijena' ); ?></p>
					<p class="sidrena-cijena-actions">
						<button type="button" class="button button-secondary" id="sidrena-cijena-upit-add">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<?php echo esc_html__( 'Dodaj stavku', 'mjdd-sidrena-cijena' ); ?>
						</button>
					</p>
					<div class="sidrena-cijena-catalog-wrap">
						<table class="sidrena-cijena-catalog sidrena-cijena-upit" id="sidrena-cijena-upit">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Naziv', 'mjdd-sidrena-cijena' ); ?></th>
									<th><?php echo esc_html__( 'Kriteriji', 'mjdd-sidrena-cijena' ); ?></th>
									<th><span class="screen-reader-text"><?php echo esc_html__( 'Radnje', 'mjdd-sidrena-cijena' ); ?></span></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>

				<?php $this->render_save_button( $woo ? __( 'Spremi stavke', 'mjdd-sidrena-cijena' ) : __( 'Spremi cjenik', 'mjdd-sidrena-cijena' ) ); ?>
				<p class="description"><?php echo esc_html__( 'Spremanje cjenika ne mijenja javnu datoteku odmah. Promjena ide u red i javna datoteka se prepisuje za otprilike minutu, samo ako se sadržaj promijenio.', 'mjdd-sidrena-cijena' ); ?></p>
			</form>
		</section>
		<?php
