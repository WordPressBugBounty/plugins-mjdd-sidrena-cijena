<?php
/**
 * Settings tab markup. Included from Sidrena_Cijena_Admin.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

		$woo = Sidrena_Cijena_Plugin::woocommerce_active();
		?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					$woo
						? __( 'Uz aktualnu cijenu ističe se bazna cijena (u odlukama Vlade: dodatna ili sidrena cijena), jasno i odvojeno od cijene koju kupac plaća. Zadani natpis je Bazna cijena. Dodatak je može prikazati automatski ispod cijene ili samo preko shortcodea [sidrena-cijena].', 'mjdd-sidrena-cijena' )
						: __( 'Uz aktualnu cijenu ističe se bazna cijena (u odlukama Vlade: dodatna ili sidrena cijena). Zadani natpis je Bazna cijena. Unesite stavke u kartici Proizvodi; javna HTML stranica je /sidrena-cjenik, a XML/CSV feed ostaje na /sidrena-cijena.xml ili .csv.', 'mjdd-sidrena-cijena' )
				);
				?>
			</p>
		</div>
		<?php if ( $woo && class_exists( 'Sidrena_Cijena_Compat' ) && Sidrena_Cijena_Compat::lowest_price_plugin_active() ) : ?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s detected 30-day lowest price plugin name */
						__( 'Otkriven je %s. Sidrena cijena radi s njim: jedan redak sidrene cijene, između aktualne cijene i retka „Najniža cijena u zadnjih 30 dana“.', 'mjdd-sidrena-cijena' ),
						Sidrena_Cijena_Compat::lowest_price_plugin_label()
					)
				);
				?>
			</p>
		</div>
		<?php endif; ?>

		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Prikaz na trgovini', 'mjdd-sidrena-cijena' ); ?></h2>
				<p><?php echo esc_html__( 'Tekst, datum i način isticanja uz cijenu proizvoda.', 'mjdd-sidrena-cijena' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'postavke' ) ); ?>">
				<?php wp_nonce_field( 'sidrena_cijena_save_settings', 'sidrena_cijena_nonce' ); ?>
				<input type="hidden" name="sidrena_cijena_action" value="save_settings" />

				<table class="form-table" role="presentation">
					<?php if ( $woo ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automatski prikaz sidrenih cijena', 'mjdd-sidrena-cijena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_auto_display',
								'sidrena_cijena_auto_display',
								'yes' === $settings->get( 'auto_display' ),
								__( 'Automatski prikaz sidrenih cijena', 'mjdd-sidrena-cijena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'Dodatak automatski prikazuje sidrene cijene ispod aktualnih cijena. Ako je isključeno, koristite shortcode [sidrena-cijena] ili [sidrena-cijena id="x"] za prikaz van loopova. Za samo jedan iznos dodajte prikaz="redovna", prikaz="akcijska" ili prikaz="sidrena".', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automatsko popunjavanje novih proizvoda', 'mjdd-sidrena-cijena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_auto_fill',
								'sidrena_cijena_auto_fill',
								'yes' === $settings->get( 'auto_fill' ),
								__( 'Popuni praznu sidrenu cijenu i datum pri spremanju', 'mjdd-sidrena-cijena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'Za proizvode i varijacije dodane nakon aktivacije dodatka. Ako su polja prazna, upisuje se trenutna redovna cijena i današnji datum (vremenska zona WordPressa). Već unesena sidrena cijena i datum se ne prepisuju.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="sidrena_cijena_prefix"><?php echo esc_html__( 'Prefix', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<input type="text" class="regular-text sidrena-cijena-prefix-input" id="sidrena_cijena_prefix" name="sidrena_cijena_prefix" value="<?php echo esc_attr( $settings->get( 'prefix' ) ); ?>" />
							<p class="description">
								<?php echo esc_html__( 'Samo riječi, bez datuma. Pregled:', 'mjdd-sidrena-cijena' ); ?>
								<code id="sidrena-cijena-preview"><?php echo esc_html( $settings->get_preview_label() . $settings->preview_separator() . '19,99 €' ); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Format datuma', 'mjdd-sidrena-cijena' ); ?></th>
						<td>
							<fieldset class="sidrena-cijena-choices">
								<label class="sidrena-cijena-choice">
									<input type="radio" name="sidrena_cijena_date_style" value="parens" <?php checked( $settings->get( 'date_style' ), 'parens' ); ?> />
									<span><?php echo esc_html__( 'Prefix (datum)', 'mjdd-sidrena-cijena' ); ?></span>
								</label>
								<label class="sidrena-cijena-choice">
									<input type="radio" name="sidrena_cijena_date_style" value="plain" <?php checked( $settings->get( 'date_style' ), 'plain' ); ?> />
									<span><?php echo esc_html__( 'Prefix datum', 'mjdd-sidrena-cijena' ); ?></span>
								</label>
							</fieldset>
							<p class="description"><?php echo esc_html__( 'Primjeri: Bazna cijena (10.09.2026.) ili Bazna cijena 10.09.2026. Ako prefix završava na „na“, dobivate Bazna cijena na 10.09.2026. Možete upisati i Sidrena cijena. Prazan prefix uz „Prefix datum“ ispisuje samo datum i iznos.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sidrena_cijena_default_date"><?php echo esc_html__( 'Zadani referentni datum', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="sidrena_cijena_default_date" name="sidrena_cijena_default_date" value="<?php echo esc_attr( $settings->get( 'default_date' ) ); ?>" />
							<p class="description"><?php echo esc_html__( 'Koristi se u odabranom formatu na izlogu. Za FMCG (hrana, piće, kozmetika…) na proizvodu upišite 02.05.2025.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tooltip', 'mjdd-sidrena-cijena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_enable_tooltip',
								'sidrena_cijena_enable_tooltip',
								'yes' === $settings->get( 'enable_tooltip' ),
								__( 'Prikaži tooltip na izlogu', 'mjdd-sidrena-cijena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'Na prelazak miša preko sidrene cijene.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<tr class="sidrena-cijena-tooltip-fields">
						<th scope="row"><label for="sidrena_cijena_tooltip"><?php echo esc_html__( 'Tekst tooltipa', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="5" id="sidrena_cijena_tooltip" name="sidrena_cijena_tooltip"><?php echo esc_textarea( $settings->get( 'tooltip' ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Prikazuje se na prelazak miša preko sidrene cijene.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Cijena za jedinicu mjere (CJM)', 'mjdd-sidrena-cijena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_enable_cjm',
								'sidrena_cijena_enable_cjm',
								$settings->is_cjm_enabled(),
								__( 'Omogući mjernu jedinicu uz sidrenu cijenu', 'mjdd-sidrena-cijena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'Kad je uključeno, uz sidrenu cijenu pojavljuje se CJM: na Woo proizvodu i varijaciji, i na svakom retku kartice Proizvodi ili Dodatne stavke. Uključite ga na retku i upišite nastavak (npr. "za kg/l") pa se prikazuje "Cijena za kg/l (10.09.2026.)". Ista mjerna jedinica ide u javni XML/CSV i u HTML tablicu.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo $woo ? 'sidrena_cijena_brand_taxonomy' : 'sidrena_cijena_brand_manual'; ?>"><?php echo esc_html__( 'Marka', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<?php if ( $woo ) : ?>
								<select id="sidrena_cijena_brand_taxonomy" name="sidrena_cijena_brand_taxonomy">
									<option value=""><?php echo esc_html__( 'Bez taksonomije', 'mjdd-sidrena-cijena' ); ?></option>
									<?php foreach ( $settings->brand_taxonomy_choices() as $tax_slug => $tax_label ) : ?>
										<option value="<?php echo esc_attr( $tax_slug ); ?>" <?php selected( (string) $settings->get( 'brand_taxonomy', '' ), $tax_slug ); ?>><?php echo esc_html( $tax_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'Za WooCommerce proizvode marka u cjeniku je naziv pojma iz odabrane taksonomije (npr. product_brand ili pa_marka). Varijacija bez pojma uzima marku roditelja.', 'mjdd-sidrena-cijena' ); ?></p>
							<?php endif; ?>
							<p>
								<label for="sidrena_cijena_brand_manual"><?php echo esc_html__( 'Ručna marka', 'mjdd-sidrena-cijena' ); ?></label>
							</p>
							<input type="text" class="regular-text" id="sidrena_cijena_brand_manual" name="sidrena_cijena_brand_manual" value="<?php echo esc_attr( (string) $settings->get( 'brand_manual', '' ) ); ?>" />
							<p class="description"><?php echo esc_html__( 'Koristi se kad proizvod nema pojam u taksonomiji, i kad je polje Marka na ručnom retku prazno. Ručni retci (Proizvodi ili Dodatne stavke) imaju vlastito polje.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<?php if ( $woo ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Javni cjenik (XML/CSV)', 'mjdd-sidrena-cijena' ); ?></th>
						<td>
							<p><?php echo esc_html__( 'Dostupno/nedostupno i barkod se uvijek izvoze. Na webshopu dostupnost prati WooCommerce stanje zalihe. Na drugim poslovnicama dostupnost je ona uvezena u tu poslovnicu, ne zaliha webshopa. Barkod se čita iz polja GTIN, UPC, EAN ili ISBN. Ako proizvod nema barkod, polje ostaje prazno.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<?php $this->render_save_button( __( 'Spremi postavke', 'mjdd-sidrena-cijena' ) ); ?>
			</form>
		</section>

		<?php if ( $woo ) : ?>
		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Popunjavanje praznih polja', 'mjdd-sidrena-cijena' ); ?></h2>
				<p><?php echo esc_html__( 'Samo prazna polja se pune trenutnom redovnom cijenom. Već unesena sidrena cijena se nikad ne prepisuje. Novi proizvodi se mogu puniti automatski pri spremanju ako je to uključeno u postavkama.', 'mjdd-sidrena-cijena' ); ?></p>
			</div>
			<?php
			$fill_preflight = Sidrena_Cijena_Activator::fill_diagnostics( 0 );
			$fill_catalog   = $fill_preflight['catalog'];
			?>
			<p class="description sidrena-cijena-fill-preflight">
				<?php
				printf(
					esc_html__(
						'Pregled prije pokretanja: %1$d podobnih stavki (s redovnom cijenom), %2$d već ima sidrenu cijenu, %3$d još prazno; Woo roditeljskih proizvoda u obradi gumba: %4$d.',
						'mjdd-sidrena-cijena'
					),
					(int) $fill_catalog['eligible'],
					(int) $fill_catalog['filled'],
					(int) $fill_catalog['missing'],
					(int) $fill_preflight['parents_total']
				);
				?>
			</p>
			<?php if ( ! empty( $fill_preflight['messages'] ) ) : ?>
			<div class="sidrena-cijena-snapshot-detail <?php echo 'error' === $fill_preflight['status'] ? 'is-error' : ''; ?>">
				<ul>
					<?php foreach ( $fill_preflight['messages'] as $fill_message ) : ?>
						<li><?php echo esc_html( $fill_message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<p class="sidrena-cijena-actions">
				<button type="button" class="button button-secondary sidrena-cijena-snapshot-btn" id="sidrena-cijena-run-snapshot">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php echo esc_html__( 'Popuni prazna polja iz redovne cijene', 'mjdd-sidrena-cijena' ); ?>
				</button>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Za proizvode koji su bili u asortimanu na zadani referentni dan. Datum ostaje zadani iz postavki (npr. 10.09.2026.).', 'mjdd-sidrena-cijena' ); ?>
			</p>
			<p class="sidrena-cijena-actions">
				<button type="button" class="button button-secondary sidrena-cijena-snapshot-btn" id="sidrena-cijena-run-snapshot-today">
					<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					<?php echo esc_html__( 'Popuni prazne sidrene cijene sa današnjim datumom', 'mjdd-sidrena-cijena' ); ?>
				</button>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Za proizvode stavljene u prodaju nakon referentnog dana. Upisuje redovnu cijenu i današnji datum (vremenska zona WordPressa). Postojeći datum na proizvodu se ne prepisuje.', 'mjdd-sidrena-cijena' ); ?>
			</p>
			<div class="sidrena-cijena-progress" id="sidrena-snapshot-progress" hidden>
				<div class="sidrena-cijena-progress__track">
					<div class="sidrena-cijena-progress__bar" id="sidrena-snapshot-bar"></div>
				</div>
			</div>
			<p class="sidrena-cijena-snapshot-status" aria-live="polite"></p>
			<div id="sidrena-snapshot-detail" class="sidrena-cijena-snapshot-detail" hidden></div>
			<p class="description">
				<?php echo esc_html__( 'Popunjavanje ide u kratkim koracima, s trakom napretka. Ako poslužitelj prekine zahtjev, dodatak nastavlja sam. Već unesena sidrena cijena se ne prepisuje, pa gumb možete ponovno pritisnuti za preostale artikle. Pojedinačnu cijenu i datum možete i ručno upisati na proizvodu, ispod akcijske cijene.', 'mjdd-sidrena-cijena' ); ?>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Za velike kataloge koristite WP-CLI (puno brže od ovog gumba): wp sidrena-cijena fill --dry-run  pa  wp sidrena-cijena fill. Novi proizvodi: wp sidrena-cijena fill --today', 'mjdd-sidrena-cijena' ); ?>
			</p>
			<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
				<div class="sidrena-cijena-purge-box">
					<p><?php echo esc_html__( 'Briše sidrene cijene sa svih proizvoda i varijacija, postavke, javni XML i arhivu, zatim deaktivira dodatak. Ovo se ne može poništiti.', 'mjdd-sidrena-cijena' ); ?></p>
					<p class="sidrena-cijena-actions">
						<button type="button" class="button sidrena-cijena-purge" id="sidrena-cijena-purge">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<?php echo esc_html__( 'Obriši sve podatke i deaktiviraj', 'mjdd-sidrena-cijena' ); ?>
						</button>
						<span class="sidrena-cijena-purge-status" aria-live="polite"></span>
					</p>
				</div>
			<?php endif; ?>
		</section>
		<?php elseif ( current_user_can( 'activate_plugins' ) ) : ?>
		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Podaci dodatka', 'mjdd-sidrena-cijena' ); ?></h2>
			</div>
			<div class="sidrena-cijena-purge-box">
				<p><?php echo esc_html__( 'Briše ručni cjenik, postavke, javni XML i arhivu, zatim deaktivira dodatak. Ovo se ne može poništiti.', 'mjdd-sidrena-cijena' ); ?></p>
				<p class="sidrena-cijena-actions">
					<button type="button" class="button sidrena-cijena-purge" id="sidrena-cijena-purge">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php echo esc_html__( 'Obriši sve podatke i deaktiviraj', 'mjdd-sidrena-cijena' ); ?>
					</button>
					<span class="sidrena-cijena-purge-status" aria-live="polite"></span>
				</p>
			</div>
		</section>
		<?php endif; ?>
		<?php
