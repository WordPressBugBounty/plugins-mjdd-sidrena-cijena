<?php
/**
 * Settings tab markup. Included from Sidrena_Cijena_Admin.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

		?>
		<section class="sidrena-cijena-card sidrena-cijena-import" id="sidrena-cijena-bulk-import">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Bulk uvoz', 'mjdd-sidrena-cijena' ); ?></h2>
				<p><?php echo esc_html( Sidrena_Cijena_Plugin::woocommerce_active() ? __( 'Učitajte CSV ili XML. Retci se spajaju prvo po SKU-u, a ako SKU ne nađe proizvod onda po postojećem stupcu ID. Uvoz u webshop ažurira Woo proizvod, uključujući zalihu. Uvoz u drugu poslovnicu upisuje dostupnost samo na tu poslovnicu. Ako ni SKU ni ID ne pogode postojeći proizvod, redak postaje ručna stavka te poslovnice.', 'mjdd-sidrena-cijena' ) : __( 'Učitajte CSV ili XML. Retci se spajaju prvo po SKU-u u odabranoj poslovnici, zatim po ID-u. Ostali se dodaju u taj cjenik. Naziv, redovna i sidrena cijena su obavezni za nove retke. Prazna polja ne brišu postojeće vrijednosti.', 'mjdd-sidrena-cijena' ) ); ?></p>
			</div>

			<div class="sidrena-cijena-drop" id="sidrena-import-drop">
				<input type="file" id="sidrena-import-file" accept=".csv,.xml,.txt,text/csv,text/xml,application/xml" />
				<label for="sidrena-import-file" class="sidrena-cijena-drop__label">
					<span class="dashicons dashicons-upload" aria-hidden="true"></span>
					<strong><?php echo esc_html__( 'Odaberite CSV ili XML', 'mjdd-sidrena-cijena' ); ?></strong>
					<em><?php echo esc_html__( 'ili ispustite datoteku ovdje', 'mjdd-sidrena-cijena' ); ?></em>
				</label>
				<p class="sidrena-cijena-import-filemeta" id="sidrena-import-filemeta" hidden></p>
				<p class="sidrena-cijena-actions">
					<button type="button" class="button button-primary" id="sidrena-import-analyze">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php echo esc_html__( 'Učitaj i mapiraj polja', 'mjdd-sidrena-cijena' ); ?>
					</button>
				</p>
			</div>

			<div class="sidrena-cijena-import-map" id="sidrena-import-map" hidden>
				<div class="sidrena-cijena-mapgrid">
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'SKU', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-sku"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'ID', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-id"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Naziv', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-name"></select>
					</label>
					<label class="sidrena-cijena-mapfield sidrena-import-standalone-only" hidden>
						<span><?php echo esc_html__( 'URL', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-url"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Redovna cijena', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-regular"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Akcijska cijena', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-sale"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Bazna cijena', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-sidrena"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Datum bazne cijene', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-datum"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Vrsta', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-vrsta"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Poslovnica', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-poslovnica"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Jedinica mjere', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-jedinica"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Neto količina', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-neto"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Cijena za jedinicu', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-jedinica-cijena"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Marka', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-marka"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Poseban oblik', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-oblik"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'CJM', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-cjm"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'CJM dodatak', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-cjm-suffix"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Dostupnost', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-map-dostupnost"></select>
					</label>
				</div>

				<div class="sidrena-cijena-preview-wrap">
					<p class="sidrena-cijena-urlbox__label"><?php echo esc_html__( 'Pregled', 'mjdd-sidrena-cijena' ); ?></p>
					<div class="sidrena-cijena-preview" id="sidrena-import-preview"></div>
				</div>

				<p class="sidrena-cijena-actions">
					<label class="sidrena-cijena-catalog-scope" for="sidrena-import-poslovnica">
						<span><?php echo esc_html__( 'Uvezi u poslovnicu', 'mjdd-sidrena-cijena' ); ?></span>
						<select id="sidrena-import-poslovnica" required>
							<option value=""><?php echo esc_html__( 'Odaberite poslovnicu', 'mjdd-sidrena-cijena' ); ?></option>
							<option value="webshop"><?php echo esc_html__( 'Webshop', 'mjdd-sidrena-cijena' ); ?></option>
							<?php foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) : ?>
								<?php $outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka']; ?>
								<option value="<?php echo esc_attr( $outlet['oznaka'] ); ?>"><?php echo esc_html( $outlet_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="button button-primary" id="sidrena-import-run">
						<span class="dashicons dashicons-database-import" aria-hidden="true"></span>
						<?php echo esc_html__( 'Pokreni uvoz', 'mjdd-sidrena-cijena' ); ?>
					</button>
				</p>
			</div>

			<div class="sidrena-cijena-progress" id="sidrena-import-progress" hidden>
				<div class="sidrena-cijena-progress__track">
					<div class="sidrena-cijena-progress__bar" id="sidrena-import-bar"></div>
				</div>
				<p class="sidrena-cijena-progress__text" id="sidrena-import-status"></p>
			</div>

			<div class="sidrena-cijena-import-result" id="sidrena-import-result" hidden></div>
		</section>

		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Izvoz cjenika', 'mjdd-sidrena-cijena' ); ?></h2>
				<p><?php echo esc_html__( 'Izvoz cjenika je isti javni CSV ili XML: vrsta, datum, maloprodajna cijena, marka, naziv posebnog oblika, jedinica mjere, cijena jedinice mjere, sidrena cijena, barkod i dostupnost. Uključeni su samo objavljeni proizvodi. Varijacije su zasebni redovi, a dodatne stavke idu na kraj. Izvoz bez sidrene cijene ostaje interni popis (naziv, SKU, redovna, akcijska i sidrena cijena).', 'mjdd-sidrena-cijena' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'uvoz-izvoz' ) ); ?>">
				<?php wp_nonce_field( 'sidrena_cijena_export', 'sidrena_cijena_export_nonce' ); ?>
				<p class="sidrena-cijena-field">
					<label for="sidrena_cijena_export_format"><?php echo esc_html__( 'Format', 'mjdd-sidrena-cijena' ); ?></label>
					<select id="sidrena_cijena_export_format" name="sidrena_cijena_export_format">
						<option value="csv">CSV</option>
						<option value="xml">XML</option>
					</select>
				</p>
				<p class="sidrena-cijena-actions">
					<button type="submit" class="button button-primary" name="sidrena_cijena_action" value="export_cjenik">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php echo esc_html__( 'Izvezi cjenik', 'mjdd-sidrena-cijena' ); ?>
					</button>
					<button type="submit" class="button button-secondary" name="sidrena_cijena_action" value="export_missing">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php echo esc_html__( 'Izvezi proizvode bez sidrene cijene', 'mjdd-sidrena-cijena' ); ?>
					</button>
					<button type="submit" class="button button-secondary" name="sidrena_cijena_action" value="export_template">
						<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
						<?php echo esc_html__( 'Preuzmi CSV predložak', 'mjdd-sidrena-cijena' ); ?>
					</button>
				</p>
				<p class="description"><?php echo esc_html__( 'Predložak ima zaglavlje, jedan primjer proizvoda i jedan primjer usluge (vrsta, datum, poslovnica).', 'mjdd-sidrena-cijena' ); ?></p>
			</form>
		</section>
		<?php
