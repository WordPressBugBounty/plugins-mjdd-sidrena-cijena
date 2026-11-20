<?php
/**
 * Settings tab markup. Included from Sidrena_Cijena_Admin.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

		$export->maybe_ensure_public_xml();

		$settings     = Sidrena_Cijena_Settings::instance();
		$format       = $settings->export_format();
		$open_label   = 'csv' === $format ? __( 'Otvori CSV', 'mjdd-sidrena-cijena' ) : __( 'Otvori XML', 'mjdd-sidrena-cijena' );
		$xml_url      = $export->get_public_url( '', '', 'robe' );
		$usluge_url   = $export->get_public_url( '', '', 'usluge' );
		$archive_url  = $export->get_archive_url();
		$xml_meta     = $export->get_public_meta();
		$next_daily   = $export->get_next_daily_timestamp();
		?>
		<section class="sidrena-cijena-card sidrena-cijena-feeds">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Javni cjenik', 'mjdd-sidrena-cijena' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						$settings->is_multi_poslovnica()
							? __( 'Webshop ima zaseban cjenik robe i, ako ima usluga s cijenom, cjenik usluga. Svaka poslovnica ima svoje datoteke. Jedna datoteka po danu: isti dan je prepisuje. Novi dan arhivira prethodnu i ona ostaje 15 dana. Podaci za naziv unose se na kartici Poslovnice.', 'mjdd-sidrena-cijena' )
							: __( 'Cjenik robe i cjenik usluga objavljuju se na fiksnim adresama (XML ili CSV). Jedna datoteka po danu: isti dan je prepisuje, novi dan arhivira prethodnu. Zamijenjena datoteka ostaje u arhivi 15 dana. OIB, razred prodajnog mjesta, adresa, oznaka i broj pohrane unose se na kartici Poslovnice.', 'mjdd-sidrena-cijena' )
					);
					?>
				</p>
				<?php if ( $settings->is_za_sva_prodajna_mjesta() ) : ?>
					<div class="notice notice-warning inline">
						<p><?php echo esc_html__( 'Uključen je isti cjenik za sva prodajna mjesta. Zasebne datoteke poslovnica su obrisane. Kopija ostaje u arhivi 15 dana. Javna je samo jedna datoteka.', 'mjdd-sidrena-cijena' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<div class="sidrena-cijena-feeds__grid">
			<?php
			if ( $settings->publishes_webshop_feed() ) {
				$this->render_url_box(
					__( 'Roba', 'mjdd-sidrena-cijena' ),
					$xml_url,
					$xml_url,
					$open_label,
					'button button-primary',
					'dashicons-external',
					'sidrena-cijena-public-url'
				);
				$this->render_url_box(
					__( 'Usluge', 'mjdd-sidrena-cijena' ),
					$usluge_url,
					$usluge_url,
					$open_label,
					'button button-secondary',
					'dashicons-external',
					'sidrena-cijena-usluge-url'
				);
			}

			$cjenik_url = $export->get_cjenik_url();
			$this->render_url_box(
				__( 'HTML cjenik', 'mjdd-sidrena-cijena' ),
				$cjenik_url,
				$cjenik_url,
				__( 'Otvori cjenik', 'mjdd-sidrena-cijena' ),
				'button button-secondary',
				'dashicons-external',
				'sidrena-cijena-cjenik-url'
			);

			$this->render_url_box(
				__( 'Arhiva', 'mjdd-sidrena-cijena' ),
				$archive_url,
				$archive_url,
				__( 'Otvori arhivu', 'mjdd-sidrena-cijena' ),
				'button button-secondary',
				'dashicons-archive',
				'sidrena-cijena-archive-url'
			);

			if ( $settings->is_multi_poslovnica() && ! $settings->is_za_sva_prodajna_mjesta() ) {
				foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) {
					$outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
					$this->render_url_box( $outlet_name . ' - ' . __( 'roba', 'mjdd-sidrena-cijena' ), $export->get_public_url( '', $outlet['oznaka'], 'robe' ), $export->get_public_url( '', $outlet['oznaka'], 'robe' ), $open_label );
					$this->render_url_box( $outlet_name . ' - ' . __( 'usluge', 'mjdd-sidrena-cijena' ), $export->get_public_url( '', $outlet['oznaka'], 'usluge' ), $export->get_public_url( '', $outlet['oznaka'], 'usluge' ), $open_label );
				}
			}

			$this->render_archive_shortcode_docs();
			$this->render_cjenik_shortcode_docs( $settings );
			?>
			</div>

			<?php if ( ! $settings->is_multi_poslovnica() && ! empty( $xml_meta['generated'] ) ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: datetime, 2: product count */
							__( 'Zadnje generiranje: %1$s (%2$d proizvoda/varijacija).', 'mjdd-sidrena-cijena' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $xml_meta['generated'] ),
							isset( $xml_meta['count'] ) ? (int) $xml_meta['count'] : 0
						)
					);
					?>
				</p>
				<?php if ( empty( $xml_meta['count'] ) && ! empty( $xml_meta['debug'] ) && is_array( $xml_meta['debug'] ) ) : ?>
					<p class="description sidrena-catalog-debug">
						<?php
						$debug = $xml_meta['debug'];
						$lines = array();
						if ( isset( $debug['source'] ) ) {
							$lines[] = sprintf(
								/* translators: %s: wc or sql_fallback */
								__( 'Izvor upita: %s', 'mjdd-sidrena-cijena' ),
								sanitize_text_field( (string) $debug['source'] )
							);
						}
						if ( isset( $debug['sql_publish_parents'] ) ) {
							$lines[] = sprintf(
								/* translators: %d: product count */
								__( 'Objavljeni roditelji u bazi (SQL): %d', 'mjdd-sidrena-cijena' ),
								(int) $debug['sql_publish_parents']
							);
						}
						if ( array_key_exists( 'wc_total_publish', $debug ) && null !== $debug['wc_total_publish'] ) {
							$lines[] = sprintf(
								/* translators: %d: product count */
								__( 'WooCommerce upit (publish): %d', 'mjdd-sidrena-cijena' ),
								(int) $debug['wc_total_publish']
							);
						}
						if ( array_key_exists( 'wc_with_suppress_only', $debug ) && null !== $debug['wc_with_suppress_only'] ) {
							$lines[] = sprintf(
								/* translators: %d: product count */
								__( 'WooCommerce upit (suppress_filters): %d', 'mjdd-sidrena-cijena' ),
								(int) $debug['wc_with_suppress_only']
							);
						}
						echo esc_html( implode( ' · ', $lines ) );
						?>
					</p>
				<?php endif; ?>
			<?php elseif ( ! $settings->is_multi_poslovnica() ) : ?>
				<p class="description"><?php echo esc_html__( 'Datoteka još nije generirana. Kliknite gumb ili otvorite javnu adresu - cjenik će se tada stvoriti.', 'mjdd-sidrena-cijena' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'javni-xml' ) ); ?>">
				<?php wp_nonce_field( 'sidrena_cijena_save_xml', 'sidrena_cijena_xml_nonce' ); ?>
				<p>
					<label><?php echo esc_html__( 'Format izvoza', 'mjdd-sidrena-cijena' ); ?></label>
				</p>
				<p class="sidrena-cijena-field">
					<fieldset class="sidrena-cijena-choices">
						<label class="sidrena-cijena-choice">
							<input type="radio" name="sidrena_cijena_public_format" value="xml" <?php checked( $format, 'xml' ); ?> />
							<span>XML</span>
						</label>
						<label class="sidrena-cijena-choice">
							<input type="radio" name="sidrena_cijena_public_format" value="csv" <?php checked( $format, 'csv' ); ?> />
							<span>CSV</span>
						</label>
					</fieldset>
				</p>
				<p class="description"><?php echo esc_html__( 'Mijenja samo trenutni javni link i nova generiranja. Datoteke u arhivi ostaju u formatu u kojem su nastale.', 'mjdd-sidrena-cijena' ); ?></p>

				<h3 class="title"><?php echo esc_html__( 'HTML stranice', 'mjdd-sidrena-cijena' ); ?></h3>
				<p class="description"><?php echo esc_html__( 'Naslovi i URL-ovi javne HTML stranice cjenika i arhive. Prazan naslov koristi zadani tekst plugina. Prazan slug koristi zadani put.', 'mjdd-sidrena-cijena' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sidrena_cijena_cjenik_page_title"><?php echo esc_html__( 'Naslov stranice cjenika', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="sidrena_cijena_cjenik_page_title" name="sidrena_cijena_cjenik_page_title" value="<?php echo esc_attr( (string) $settings->get( 'cjenik_page_title', '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->cjenik_page_title() ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sidrena_cijena_archive_page_title"><?php echo esc_html__( 'Naslov stranice arhive', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="sidrena_cijena_archive_page_title" name="sidrena_cijena_archive_page_title" value="<?php echo esc_attr( (string) $settings->get( 'archive_page_title', '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->archive_page_title() ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sidrena_cijena_cjenik_slug"><?php echo esc_html__( 'Slug cjenika', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<code>/</code>
							<input type="text" class="regular-text code" id="sidrena_cijena_cjenik_slug" name="sidrena_cijena_cjenik_slug" value="<?php echo esc_attr( (string) $settings->get( 'cjenik_slug', '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->cjenik_slug() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Primjer: /sidrena-cjenik. Nakon promjene slug-a WordPress osvježi permalink pravila pri sljedećem učitavanju admina.', 'mjdd-sidrena-cijena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sidrena_cijena_archive_slug"><?php echo esc_html__( 'Slug arhive', 'mjdd-sidrena-cijena' ); ?></label></th>
						<td>
							<code>/</code>
							<input type="text" class="regular-text code" id="sidrena_cijena_archive_slug" name="sidrena_cijena_archive_slug" value="<?php echo esc_attr( (string) $settings->get( 'archive_slug', '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->archive_slug() ); ?>" />
						</td>
					</tr>
				</table>

				<p class="description">
					<?php
					echo esc_html__( 'Oblik, adresa, oznaka i broj pohrane za webshop i dodatne poslovnice unose se na kartici Poslovnice.', 'mjdd-sidrena-cijena' );
					echo ' ';
					?>
					<a href="<?php echo esc_url( $this->get_settings_tab_url( 'poslovnice' ) ); ?>"><?php echo esc_html__( 'Otvori karticu Poslovnice.', 'mjdd-sidrena-cijena' ); ?></a>
				</p>

				<p>
					<?php
					$this->render_toggle(
						'sidrena_cijena_daily_xml',
						'sidrena_cijena_daily_xml',
						'yes' === $settings->get( 'daily_xml' ),
						__( 'Regeneriraj javni cjenik dnevno nakon 00:00', 'mjdd-sidrena-cijena' )
					);
					?>
				</p>
				<?php if ( 'yes' === $settings->get( 'daily_xml' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) : ?>
					<div class="notice notice-warning inline">
						<p><?php echo esc_html__( 'Provjera u 10:00 ovisi o WordPress cronu. On se pokreće tek pri sljedećem zahtjevu koji učita PHP. U wp-config.php postavite DISABLE_WP_CRON na true i iz system crona svakih 5 do 15 minuta pozovite wp-cron.php, da izmjena stigne prije 11:00.', 'mjdd-sidrena-cijena' ); ?></p>
					</div>
				<?php endif; ?>
				<?php
				$generated_at = ! empty( $xml_meta['generated'] ) ? (int) $xml_meta['generated'] : 0;
				$feed_stale   = 'yes' === $settings->get( 'daily_xml' ) && ( $generated_at < 1 || ( time() - $generated_at ) > ( DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS ) );
				?>
				<?php if ( $feed_stale ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							if ( $generated_at > 0 ) {
								echo esc_html(
									sprintf(
										/* translators: %s: datetime */
										__( 'Zadnje uspješno generiranje (%s) je starije od 26 sati. Provjerite cron prije roka u 11:00.', 'mjdd-sidrena-cijena' ),
										wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_at )
									)
								);
							} else {
								echo esc_html__( 'Javni cjenik još nije generiran. Dok datoteka ne postoji, HTML stranica i shortcode prikazuju da se cjenik priprema.', 'mjdd-sidrena-cijena' );
							}
							?>
						</p>
					</div>
				<?php elseif ( $generated_at > 0 ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: datetime */
								__( 'Zadnje uspješno generiranje: %s.', 'mjdd-sidrena-cijena' ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_at )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php
					echo esc_html__( 'Uključeno po defaultu. U 10:00 provjeri je li se cjenik promijenio. Ako jest i dan je nov, prepisuje fiksnu adresu i staru datoteku stavlja u arhivu na 15 dana. Isti dan samo prepisuje tu datoteku. Ako nije, ne radi ništa.', 'mjdd-sidrena-cijena' );
					echo ' ';
					if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
						echo esc_html__( 'DISABLE_WP_CRON je uključen, pa provjeru u 10:00 pokreće system cron koji zove wp-cron.php.', 'mjdd-sidrena-cijena' );
					} else {
						echo esc_html__( 'WordPress cron taj posao pokreće tek pri sljedećem PHP zahtjevu.', 'mjdd-sidrena-cijena' );
					}
					?>
				</p>
				<?php if ( $next_daily ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: datetime */
								__( 'Sljedeće automatsko generiranje: %s.', 'mjdd-sidrena-cijena' ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_daily )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p class="description"><?php echo esc_html__( 'Spremanje cjenika, uvoz i poslovnice ne mijenjaju javnu datoteku odmah. Promjena cijene ili kataloga stavlja generiranje u red za otprilike minutu. U 10:00 ide još jedna provjera.', 'mjdd-sidrena-cijena' ); ?></p>
				<p class="description"><?php echo esc_html__( 'Gumb Generiraj javni cjenik ide u kratkim koracima, s trakom napretka. Osvježenje stranice nastavlja isti posao. Trenutni javni cjenik ulazi u arhivu. Nova stavka s novim brojem pohrane nastaje samo kad se promijene cijene ili sadržaj.', 'mjdd-sidrena-cijena' ); ?></p>
				<?php
				$feed_job     = Sidrena_Cijena_Feed_Job::instance()->status();
				$feed_running = isset( $feed_job['status'] ) && 'running' === $feed_job['status'];
				$feed_total   = isset( $feed_job['total'] ) ? (int) $feed_job['total'] : 0;
				$feed_seen    = isset( $feed_job['seen'] ) ? (int) $feed_job['seen'] : 0;
				$feed_pct     = ( $feed_running && $feed_total > 0 ) ? (int) min( 100, round( ( $feed_seen / $feed_total ) * 100 ) ) : 0;
				?>
				<p class="sidrena-cijena-actions">
					<button type="submit" class="button button-secondary" name="sidrena_cijena_action" value="save_xml_settings">
						<?php echo $this->save_icon_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
						<?php echo esc_html__( 'Spremi', 'mjdd-sidrena-cijena' ); ?>
					</button>
					<button type="button" class="button button-primary" id="sidrena-cijena-run-feed">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php echo esc_html__( 'Generiraj javni cjenik', 'mjdd-sidrena-cijena' ); ?>
					</button>
				</p>
				<div class="sidrena-cijena-progress" id="sidrena-feed-progress"<?php echo $feed_running ? '' : ' hidden'; ?>>
					<div class="sidrena-cijena-progress__track">
						<div class="sidrena-cijena-progress__bar" id="sidrena-feed-bar" style="width:<?php echo esc_attr( (string) $feed_pct ); ?>%"></div>
					</div>
				</div>
				<p class="sidrena-cijena-snapshot-status sidrena-cijena-feed-status" aria-live="polite"><?php
				if ( $feed_running && $feed_total > 0 ) {
					echo esc_html(
						sprintf(
							/* translators: 1: processed parents, 2: total parents */
							__( 'Obrada… %1$s / %2$s', 'mjdd-sidrena-cijena' ),
							number_format_i18n( $feed_seen ),
							number_format_i18n( $feed_total )
						)
					);
				}
				?></p>
			</form>
		</section>

		<?php
		$archive_groups = Sidrena_Cijena_Archive::instance()->admin_groups();
		$archive_tabs   = false;
		foreach ( $archive_groups as $archive_group ) {
			if ( '' !== $archive_group['label'] ) {
				$archive_tabs = true;
				break;
			}
		}
		?>
		<?php
		$archive_has_files = false;
		foreach ( $archive_groups as $archive_group ) {
			if ( ! empty( $archive_group['files'] ) ) {
				$archive_has_files = true;
				break;
			}
		}
		?>
		<section class="sidrena-cijena-card sidrena-cijena-archive-admin">
			<div class="sidrena-cijena-card__head sidrena-cijena-card__head--split">
				<div>
					<h2><?php echo esc_html__( 'Arhiva cjenika', 'mjdd-sidrena-cijena' ); ?></h2>
					<p><?php echo esc_html__( 'Ovo je objavljena povijest. Nakon 17. 11. 2026. zadržite datoteke za dane u kojima je obveza vrijedila.', 'mjdd-sidrena-cijena' ); ?></p>
				</div>
				<?php if ( $archive_has_files ) : ?>
					<button type="button" class="button sidrena-archive-delete-all"><?php echo esc_html__( 'Obriši sve', 'mjdd-sidrena-cijena' ); ?></button>
				<?php endif; ?>
			</div>
			<?php if ( $archive_tabs ) : ?>
				<div class="sidrena-archive-tabs" role="tablist">
					<?php foreach ( $archive_groups as $index => $group ) : ?>
						<button
							type="button"
							class="sidrena-archive-tabs__item<?php echo 0 === $index ? ' is-active' : ''; ?>"
							role="tab"
							aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>"
							data-archive-tab="<?php echo esc_attr( $group['id'] ); ?>"
						><?php echo esc_html( $group['label'] ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php foreach ( $archive_groups as $index => $group ) : ?>
				<div class="sidrena-archive-panel<?php echo 0 === $index ? ' is-active' : ''; ?>" data-archive-panel="<?php echo esc_attr( $group['id'] ); ?>"<?php echo 0 === $index ? '' : ' hidden'; ?>>
					<?php if ( array() === $group['files'] ) : ?>
						<p class="description sidrena-archive-empty"><?php echo esc_html__( 'Arhiva je prazna.', 'mjdd-sidrena-cijena' ); ?></p>
					<?php else : ?>
						<ul class="sidrena-archive-list">
							<?php foreach ( $group['files'] as $file ) : ?>
								<?php
								$stamp = isset( $file['stamp'] ) ? (int) $file['stamp'] : 0;
								$when  = $stamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp ) : '';
								$fname = isset( $file['name'] ) ? (string) $file['name'] : '';
								$furl  = isset( $file['url'] ) ? (string) $file['url'] : '';
								?>
								<li>
									<a href="<?php echo esc_url( $furl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $fname ); ?></a>
									<?php if ( '' !== $when ) : ?>
										<span class="sidrena-archive-list__when"><?php echo esc_html( $when ); ?></span>
									<?php endif; ?>
									<button type="button" class="sidrena-archive-delete" data-name="<?php echo esc_attr( $fname ); ?>" aria-label="<?php echo esc_attr__( 'Obriši datoteku', 'mjdd-sidrena-cijena' ); ?>">
										<span aria-hidden="true">&times;</span>
									</button>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<dialog class="sidrena-archive-dialog" id="sidrena-archive-delete-dialog">
				<p><?php echo esc_html__( 'Obrisati ovu datoteku iz arhive?', 'mjdd-sidrena-cijena' ); ?></p>
				<p class="sidrena-archive-dialog__name"></p>
				<div class="sidrena-archive-dialog__actions">
					<button type="button" class="button sidrena-archive-delete-cancel"><?php echo esc_html__( 'Odustani', 'mjdd-sidrena-cijena' ); ?></button>
					<button type="button" class="button button-primary sidrena-archive-delete-confirm"><?php echo esc_html__( 'Obriši', 'mjdd-sidrena-cijena' ); ?></button>
				</div>
			</dialog>
		</section>
		<?php
