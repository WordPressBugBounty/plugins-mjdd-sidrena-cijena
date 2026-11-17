<?php
/**
 * Snapshot regular prices into sidrena meta in batches.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activation snapshot runner.
 */
class Sidrena_Cijena_Activator {

	const PENDING_OPTION     = 'sidrena_cijena_snapshot_pending';
	const PAGE_OPTION        = 'sidrena_cijena_snapshot_page';
	const OFFSET_OPTION      = 'sidrena_cijena_snapshot_offset';
	const CHILD_OPTION       = 'sidrena_cijena_snapshot_child';
	const FILL_PROMPT_OPTION = 'sidrena_cijena_fill_prompt';
	const BATCH_SIZE         = 40;
	const WORK_BUDGET        = 25;

	/**
	 * Ask an admin to fill empty sidrena fields. Activation does not write prices.
	 */
	public static function queue_fill_prompt() {
		update_option( self::FILL_PROMPT_OPTION, 'yes', false );
	}

	/**
	 * Queue the first snapshot batch.
	 */
	public static function queue_snapshot() {
		update_option( self::PENDING_OPTION, 'yes', false );
		update_option( self::PAGE_OPTION, 1, false );
		update_option( self::OFFSET_OPTION, 0, false );
		update_option( self::CHILD_OPTION, 0, false );

		if ( ! wp_next_scheduled( 'sidrena_cijena_snapshot_batch' ) ) {
			wp_schedule_single_event( time() + 3, 'sidrena_cijena_snapshot_batch' );
		}
	}

	/**
	 * Process one short slice of products, then reschedule if needed.
	 *
	 * A page is 40 parents, but each HTTP request stops after WORK_BUDGET
	 * product objects so a proxy timeout cannot drop the rest of the catalog.
	 * Offset and child resume inside the same page.
	 *
	 * @param int  $page        Optional explicit page. 0 reads the stored cursor.
	 * @param bool $stamp_today Also write today's date when the date field is empty.
	 * @param int  $offset      Parent index inside the current page.
	 * @param int  $child        Variation index inside the current parent.
	 * @param int  $written_run  Rows written in earlier requests of the same run.
	 * @return array<string,mixed>
	 */
	public static function process_batch( $page = 0, $stamp_today = false, $offset = 0, $child = 0, $written_run = 0 ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
		ignore_user_abort( true );

		$page   = absint( $page );
		$offset = absint( $offset );
		$child  = absint( $child );
		if ( $page < 1 ) {
			$page   = max( 1, absint( get_option( self::PAGE_OPTION, 1 ) ) );
			$offset = absint( get_option( self::OFFSET_OPTION, 0 ) );
			$child  = absint( get_option( self::CHILD_OPTION, 0 ) );
		}

		$ids   = self::snapshot_ids( $page );
		$total = self::snapshot_total();

		if ( 1 === $page && 0 === $offset && 0 === $child && array() === $ids ) {
			$stats = self::catalog_stats();
			if ( (int) $stats['missing'] > 0 ) {
				$date           = $stamp_today ? Sidrena_Cijena_Product_Meta::today_date() : '';
				$missing_before = '' !== $date ? self::missing_product_ids() : array();
				$fast           = self::fast_fill( false );
				$written        = (int) $fast['written'];
				if ( $written > 0 && '' !== $date && array() !== $missing_before ) {
					self::stamp_empty_dates( $missing_before, $date );
				}
				$written_run = absint( $written_run ) + $written;

				delete_option( self::PENDING_OPTION );
				delete_option( self::PAGE_OPTION );
				delete_option( self::OFFSET_OPTION );
				delete_option( self::CHILD_OPTION );
				delete_option( self::FILL_PROMPT_OPTION );

				$result = array(
					'done'             => true,
					'processed'        => 0,
					'written'          => $written,
					'written_run'      => $written_run,
					'page'             => $page,
					'next_page'        => $page,
					'next_offset'      => 0,
					'next_child'       => 0,
					'total'            => $total,
					'seen'             => $total,
					'parents_in_batch' => 0,
					'used_fast_fill'   => true,
				);

				$result['diagnostics'] = self::fill_diagnostics( $written_run, true );
				return $result;
			}
		}

		$meta      = Sidrena_Cijena_Product_Meta::instance();
		$date      = $stamp_today ? Sidrena_Cijena_Product_Meta::today_date() : '';
		$processed = 0;
		$written   = 0;
		$budget    = self::WORK_BUDGET;
		$advanced  = 0;
		$next_child = 0;
		$slice     = array_slice( $ids, $offset );

		foreach ( $slice as $product_id ) {
			if ( $budget < 1 && $advanced > 0 ) {
				break;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				++$advanced;
				$child = 0;
				continue;
			}

			++$processed;
			--$budget;

			if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
				$children = array_values( $product->get_children() );
				$start    = $child;
				$child    = 0;
				$count    = count( $children );

				for ( $i = $start; $i < $count; $i++ ) {
					if ( $budget < 1 ) {
						$next_child = $i;
						break 2;
					}

					$variation = wc_get_product( $children[ $i ] );
					if ( $variation && $meta->snapshot_if_empty( $variation, $date ) ) {
						++$written;
					}
					--$budget;
				}

				++$advanced;
				continue;
			}

			if ( $meta->snapshot_if_empty( $product, $date ) ) {
				++$written;
			}
			$child = 0;
			++$advanced;
		}

		$next_offset   = $offset + $advanced;
		$page_complete = ( $next_child < 1 ) && ( $next_offset >= count( $ids ) );
		$done          = $page_complete && count( $ids ) < self::BATCH_SIZE;
		$next_page     = $page;
		$consumed      = $page_complete ? count( $ids ) : $next_offset;

		if ( $page_complete && ! $done ) {
			$next_page   = $page + 1;
			$next_offset = 0;
		}

		$seen = $done ? $total : ( ( $page - 1 ) * self::BATCH_SIZE ) + $consumed;
		if ( $total < $seen ) {
			$total = $seen;
		}

		$written_run = absint( $written_run ) + $written;

		if ( $done ) {
			delete_option( self::PENDING_OPTION );
			delete_option( self::PAGE_OPTION );
			delete_option( self::OFFSET_OPTION );
			delete_option( self::CHILD_OPTION );
			delete_option( self::FILL_PROMPT_OPTION );
		} else {
			update_option( self::PAGE_OPTION, $next_page, false );
			update_option( self::OFFSET_OPTION, $next_offset, false );
			update_option( self::CHILD_OPTION, $next_child, false );
			if ( ! wp_next_scheduled( 'sidrena_cijena_snapshot_batch' ) ) {
				wp_schedule_single_event( time() + 2, 'sidrena_cijena_snapshot_batch' );
			}
		}

		$result = array(
			'done'        => $done,
			'processed'   => $processed,
			'written'     => $written,
			'written_run' => $written_run,
			'page'        => $page,
			'next_page'   => $done ? $page : $next_page,
			'next_offset' => $done ? 0 : $next_offset,
			'next_child'  => $done ? 0 : $next_child,
			'total'       => $total,
			'seen'        => $seen,
			'parents_in_batch' => count( $ids ),
		);

		if ( $done ) {
			$result['diagnostics'] = self::fill_diagnostics( $written_run, true );
		}

		return $result;
	}

	/**
	 * Human-readable reasons when a fill run wrote nothing or could not start.
	 *
	 * @param int  $written_in_run Total rows written in this admin run.
	 * @param bool $after_run      When true, include messages about a finished run that wrote nothing.
	 * @return array{status:string,messages:array<int,string>,catalog:array{eligible:int,filled:int,missing:int},parents_total:int,written_in_run:int}
	 */
	public static function fill_diagnostics( $written_in_run = 0, $after_run = false ) {
		$stats         = self::catalog_stats();
		$parents_total = self::snapshot_total();
		$messages      = array();
		$written_in_run = absint( $written_in_run );

		if ( ! Sidrena_Cijena_Plugin::woocommerce_active() ) {
			$messages[] = __( 'WooCommerce nije aktivan - popunjavanje radi samo na Woo trgovini.', 'mjdd-sidrena-cijena' );
		} elseif ( ! function_exists( 'wc_get_products' ) ) {
			$messages[] = __( 'WooCommerce nije učitan (nedostaje wc_get_products).', 'mjdd-sidrena-cijena' );
		}

		if ( $parents_total < 1 ) {
			$messages[] = __(
				'Upit proizvoda nije vratio nijedan proizvod (simple, variable, external, subscription u statusu objavljeno, skica, na čekanju ili privatno). Gumb ne prolazi kroz druge tipove niti samo varijacije bez roditelja.',
				'mjdd-sidrena-cijena'
			);
		}

		if ( (int) $stats['eligible'] < 1 ) {
			$messages[] = __(
				'U bazi nema stavki s unesenom redovnom cijenom (_regular_price). Bez redovne cijene sidrena se ne može popuniti.',
				'mjdd-sidrena-cijena'
			);
		} elseif ( (int) $stats['missing'] < 1 && $written_in_run < 1 ) {
			$messages[] = __( 'Sve podobne stavke već imaju sidrenu cijenu - nema praznih polja za popuniti.', 'mjdd-sidrena-cijena' );
		} elseif ( $after_run && $written_in_run < 1 && (int) $stats['missing'] > 0 ) {
			if ( $parents_total < 1 ) {
				$messages[] = __(
					'Woo upit nije vratio nijednog roditeljskog proizvoda (tip, jezik ili filter). Ako gumb i dalje ne upiše ništa, provjerite je li WooCommerce aktivan i pokrenite na poslužitelju: wp sidrena-cijena fill',
					'mjdd-sidrena-cijena'
				);
			}
			$messages[] = sprintf(
				/* translators: 1: number of catalog rows still missing sidrena, 2: WP-CLI command */
				__( 'Nijedna stavka nije upisana, a u bazi još nedostaje sidrena cijena na %1$d stavki. Brži SQL put: %2$s. Ako i dalje piše 0, provjerite ima li proizvoda redovnu cijenu u WooCommerceu.', 'mjdd-sidrena-cijena' ),
				(int) $stats['missing'],
				'wp sidrena-cijena fill --dry-run'
			);
		}

		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			$messages[] = sprintf(
				/* translators: %s database error */
				__( 'Greška baze podataka: %s', 'mjdd-sidrena-cijena' ),
				$wpdb->last_error
			);
		}

		if ( $after_run && $written_in_run > 0 && $parents_total < 1 ) {
			$messages[] = sprintf(
				/* translators: %d: rows written */
				__( 'Woo nije vratio roditeljske proizvode u listu; sidrene cijene upisane su SQL putem (%d stavki, isto kao wp sidrena-cijena fill).', 'mjdd-sidrena-cijena' ),
				$written_in_run
			);
		}

		$status = 'ok';
		if ( ! empty( $messages ) ) {
			if ( ! empty( $wpdb->last_error ) ) {
				$status = 'error';
			} elseif ( $after_run && $written_in_run > 0 ) {
				$status = 'ok';
			} elseif ( $after_run && $written_in_run < 1 && (int) $stats['missing'] > 0 ) {
				$status = 'error';
			} else {
				$status = 'warning';
			}
		}

		return array(
			'status'         => $status,
			'messages'       => $messages,
			'catalog'        => $stats,
			'catalog_line'   => sprintf(
				/* translators: 1: eligible, 2: filled, 3: missing, 4: Woo parent count for the button walk */
				__( 'U bazi: %1$d podobnih stavki, %2$d već ima sidrenu cijenu, %3$d još prazno; Woo roditeljskih proizvoda u obradi: %4$d.', 'mjdd-sidrena-cijena' ),
				(int) $stats['eligible'],
				(int) $stats['filled'],
				(int) $stats['missing'],
				$parents_total
			),
			'parents_total'  => $parents_total,
			'written_in_run' => $written_in_run,
		);
	}

	/**
	 * Parent IDs for one snapshot page, ordered by ID.
	 *
	 * @param int $page 1-based page.
	 * @return array<int,int>
	 */
	private static function snapshot_ids( $page ) {
		$ids = wc_get_products( self::snapshot_query_args( $page, self::BATCH_SIZE ) );

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * How many parent products the snapshot walks.
	 *
	 * @return int
	 */
	private static function snapshot_total() {
		$query = wc_get_products( self::snapshot_query_args( 1, 1, true ) );
		if ( is_object( $query ) && isset( $query->total ) ) {
			return (int) $query->total;
		}

		return 0;
	}

	/**
	 * Shared product query for the admin/cron snapshot.
	 *
	 * @param int  $page     1-based page.
	 * @param int  $limit    Page size.
	 * @param bool $paginate Return a result object with a total.
	 * @return array<string,mixed>
	 */
	private static function snapshot_query_args( $page, $limit, $paginate = false ) {
		$args = array(
			'status'   => array( 'publish', 'private', 'draft', 'pending' ),
			'type'     => self::snapshot_product_types(),
			'limit'    => $limit,
			'page'     => max( 1, absint( $page ) ),
			'paginate' => (bool) $paginate,
			'return'   => 'ids',
			'orderby'  => 'ID',
			'order'    => 'ASC',
		);

		if ( Sidrena_Cijena_I18n::instance()->wpml_active() ) {
			$args['lang'] = 'all';
		} elseif ( function_exists( 'pll_current_language' ) ) {
			$args['suppress_filters'] = true;
		}

		return $args;
	}

	/**
	 * Product types included in the public catalog walk (registered in Woo only).
	 *
	 * @return array<int,string>
	 */
	public static function catalog_product_types() {
		return self::snapshot_product_types();
	}

	/**
	 * Product types the admin snapshot walk includes (registered in Woo only).
	 *
	 * @return array<int,string>
	 */
	private static function snapshot_product_types() {
		$core = array( 'simple', 'variable', 'external', 'subscription', 'variable-subscription' );
		if ( ! function_exists( 'wc_get_product_types' ) ) {
			return array( 'simple', 'variable', 'external' );
		}

		$registered = array_keys( wc_get_product_types() );
		$types      = array_values( array_intersect( $core, $registered ) );
		$extras     = array_diff( $registered, array_merge( self::skip_parent_types(), array( 'variation', 'grouped', 'virtual' ) ) );
		$types      = array_values( array_unique( array_merge( $types, $extras ) ) );

		return array() !== $types ? $types : array( 'simple', 'variable', 'external' );
	}

	/**
	 * Whether a snapshot is still running.
	 *
	 * @return bool
	 */
	public static function is_pending() {
		return 'yes' === get_option( self::PENDING_OPTION );
	}

	/**
	 * Product types whose parent row must not receive a sidrena price.
	 *
	 * @return array<int,string>
	 */
	public static function skip_parent_types() {
		return array( 'variable', 'grouped', 'variable-subscription' );
	}

	/**
	 * Catalog counts for CLI/status: eligible rows, already filled, still empty.
	 *
	 * Eligible = product or variation with a non-empty regular price, excluding
	 * variable / grouped / variable-subscription parents.
	 *
	 * @return array{eligible:int,filled:int,missing:int}
	 */
	public static function catalog_stats() {
		global $wpdb;

		$sql = self::eligible_from_sql( 'stats' );
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'eligible' => isset( $row['eligible'] ) ? (int) $row['eligible'] : 0,
			'filled'   => isset( $row['filled'] ) ? (int) $row['filled'] : 0,
			'missing'  => isset( $row['missing'] ) ? (int) $row['missing'] : 0,
		);
	}

	/**
	 * Fill every empty sidrena field from regular price, in-process (no AJAX).
	 *
	 * @param array<string,mixed> $args {
	 *     @type bool     $dry_run    Count only.
	 *     @type bool     $fast       SQL bulk (default true).
	 *     @type int      $batch_size PHP path page size.
	 *     @type string   $stamp_date Optional reference date for newly filled rows.
	 *     @type callable $on_page    Optional progress callback( array $page_result ).
	 * }
	 * @return array{written:int,processed:int,updated:int,inserted:int,missing_before:int,dry_run:bool,fast:bool}
	 */
	public static function fill_catalog( $args = array() ) {
		$dry_run    = ! empty( $args['dry_run'] );
		$fast       = ! array_key_exists( 'fast', $args ) || ! empty( $args['fast'] );
		$batch_size = isset( $args['batch_size'] ) ? max( 1, absint( $args['batch_size'] ) ) : 250;
		$stamp_date = isset( $args['stamp_date'] ) ? sanitize_text_field( (string) $args['stamp_date'] ) : '';
		$on_page    = isset( $args['on_page'] ) && is_callable( $args['on_page'] ) ? $args['on_page'] : null;

		$stats_before = self::catalog_stats();

		if ( $fast ) {
			$ids    = '' !== $stamp_date ? self::missing_product_ids() : array();
			$result = self::fast_fill( $dry_run );
			if ( ! $dry_run && '' !== $stamp_date ) {
				self::stamp_empty_dates( $ids, $stamp_date );
			}
			self::clear_pending_state();

			return array(
				'written'        => (int) $result['written'],
				'processed'      => (int) $stats_before['eligible'],
				'updated'        => (int) $result['updated'],
				'inserted'       => (int) $result['inserted'],
				'missing_before' => (int) $stats_before['missing'],
				'dry_run'        => $dry_run,
				'fast'           => true,
			);
		}

		$page      = 1;
		$processed = 0;
		$written   = 0;

		do {
			$batch = self::fill_page( $page, $batch_size, $dry_run, $stamp_date );
			$processed += (int) $batch['processed'];
			$written   += (int) $batch['written'];

			if ( $on_page ) {
				call_user_func( $on_page, $batch );
			}

			++$page;
		} while ( empty( $batch['done'] ) );

		self::clear_pending_state();

		return array(
			'written'        => $written,
			'processed'      => $processed,
			'updated'        => 0,
			'inserted'       => $written,
			'missing_before' => (int) $stats_before['missing'],
			'dry_run'        => $dry_run,
			'fast'           => false,
		);
	}

	/**
	 * One page of the PHP object-based fill (same rules as the admin button).
	 *
	 * @param int    $page       1-based page.
	 * @param int    $batch_size Products per page.
	 * @param bool   $dry_run    Do not write.
	 * @param string $stamp_date Optional reference date for newly filled rows.
	 * @return array{done:bool,processed:int,written:int,page:int}
	 */
	public static function fill_page( $page, $batch_size = 250, $dry_run = false, $stamp_date = '' ) {
		$page       = max( 1, absint( $page ) );
		$batch_size = max( 1, absint( $batch_size ) );

		$ids = wc_get_products(
			self::snapshot_query_args( $page, $batch_size, false )
		);

		$meta       = Sidrena_Cijena_Product_Meta::instance();
		$stamp_date = is_string( $stamp_date ) ? trim( $stamp_date ) : '';
		$processed  = 0;
		$written    = 0;

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			++$processed;

			if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation ) {
						continue;
					}
					if ( $dry_run ) {
						if ( $meta->can_snapshot( $variation ) ) {
							++$written;
						}
						continue;
					}
					if ( $meta->snapshot_if_empty( $variation, $stamp_date ) ) {
						++$written;
					}
				}
				continue;
			}

			if ( $dry_run ) {
				if ( $meta->can_snapshot( $product ) ) {
					++$written;
				}
				continue;
			}

			if ( $meta->snapshot_if_empty( $product, $stamp_date ) ) {
				++$written;
			}
		}

		return array(
			'done'      => count( $ids ) < $batch_size,
			'processed' => $processed,
			'written'   => $written,
			'page'      => $page,
		);
	}

	/**
	 * SQL bulk copy of `_regular_price` into empty `_sidrena_cijena`.
	 *
	 * @param bool $dry_run Count only.
	 * @return array{written:int,updated:int,inserted:int}
	 */
	public static function fast_fill( $dry_run = false ) {
		global $wpdb;

		$ids = self::missing_product_ids();

		if ( $dry_run ) {
			return array(
				'written'  => count( $ids ),
				'updated'  => 0,
				'inserted' => 0,
			);
		}

		if ( array() === $ids ) {
			return array(
				'written'  => 0,
				'updated'  => 0,
				'inserted' => 0,
			);
		}

		$price_key = SIDRENA_CIJENA_META_PRICE;
		$skip_in   = self::skip_types_sql();
		$type_join = self::product_type_join_sql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} sc
				INNER JOIN {$wpdb->posts} p ON p.ID = sc.post_id
				INNER JOIN {$wpdb->postmeta} rp
					ON rp.post_id = p.ID AND rp.meta_key = '_regular_price' AND rp.meta_value <> ''
				{$type_join}
				SET sc.meta_value = rp.meta_value
				WHERE sc.meta_key = %s
				AND (sc.meta_value IS NULL OR sc.meta_value = '')
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'inherit')
				AND (pt.slug IS NULL OR pt.slug NOT IN ({$skip_in}))",
				$price_key
			)
		);

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				SELECT p.ID, %s, MAX(rp.meta_value)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} rp
					ON rp.post_id = p.ID AND rp.meta_key = '_regular_price' AND rp.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} sc
					ON sc.post_id = p.ID AND sc.meta_key = %s
				{$type_join}
				WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'inherit')
				AND (pt.slug IS NULL OR pt.slug NOT IN ({$skip_in}))
				AND sc.meta_id IS NULL
				GROUP BY p.ID",
				$price_key,
				$price_key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$updated  = false === $updated ? 0 : (int) $updated;
		$inserted = false === $inserted ? 0 : (int) $inserted;

		self::invalidate_product_cache( $ids );

		return array(
			'written'  => $updated + $inserted,
			'updated'  => $updated,
			'inserted' => $inserted,
		);
	}

	/**
	 * Product IDs (and variation IDs) that still need a sidrena price.
	 *
	 * @return array<int,int>
	 */
	public static function missing_product_ids() {
		global $wpdb;

		$price_key = SIDRENA_CIJENA_META_PRICE;
		$skip_in   = self::skip_types_sql();
		$type_join = self::product_type_join_sql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} rp
					ON rp.post_id = p.ID AND rp.meta_key = '_regular_price' AND rp.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} sc
					ON sc.post_id = p.ID AND sc.meta_key = %s
				{$type_join}
				WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'inherit')
				AND (pt.slug IS NULL OR pt.slug NOT IN ({$skip_in}))
				GROUP BY p.ID
				HAVING MAX(CASE WHEN sc.meta_value IS NOT NULL AND sc.meta_value <> '' THEN 1 ELSE 0 END) = 0",
				$price_key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Write a reference date only where sidrena date is still empty.
	 *
	 * @param array<int,int> $ids  Product or variation IDs.
	 * @param string         $date Date string (d.m.Y.).
	 */
	private static function stamp_empty_dates( $ids, $date ) {
		$date = sanitize_text_field( $date );
		if ( '' === $date || ! is_array( $ids ) || array() === $ids ) {
			return;
		}

		$meta = Sidrena_Cijena_Product_Meta::instance();

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id < 1 ) {
				continue;
			}
			if ( '' !== $meta->get_date( $id ) ) {
				continue;
			}
			$meta->set_date( $id, $date );
		}

		self::invalidate_product_cache( $ids );
	}

	/**
	 * Drop activation/admin snapshot cursor so the admin notice does not linger.
	 */
	public static function clear_pending_state() {
		delete_option( self::PENDING_OPTION );
		delete_option( self::PAGE_OPTION );
		delete_option( self::OFFSET_OPTION );
		delete_option( self::CHILD_OPTION );
		delete_option( self::FILL_PROMPT_OPTION );
		wp_clear_scheduled_hook( 'sidrena_cijena_snapshot_batch' );
	}

	/**
	 * Drop stale post-meta cache for IDs written via SQL.
	 *
	 * @param array<int,int> $ids Product or variation IDs.
	 */
	private static function invalidate_product_cache( $ids ) {
		if ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'get_transient_version' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id < 1 ) {
				continue;
			}
			wp_cache_delete( $id, 'post_meta' );
			clean_post_cache( $id );
		}
	}

	/**
	 * SQL fragment: join product_type to posts as `pt`.
	 *
	 * @return string
	 */
	private static function product_type_join_sql() {
		global $wpdb;

		return "LEFT JOIN (
			SELECT tr.object_id, t.slug
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt
				ON tt.term_taxonomy_id = tr.term_taxonomy_id
				AND tt.taxonomy = 'product_type'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		) pt ON pt.object_id = p.ID";
	}

	/**
	 * Quoted IN() list of parent types that must not be filled.
	 *
	 * @return string
	 */
	/**
	 * Count published parent products in the database (catalog SQL path).
	 *
	 * @param bool $public Published only.
	 * @return int
	 */
	public static function catalog_sql_parent_count( $public = true ) {
		global $wpdb;

		$type_join = self::product_type_join_sql();
		$types_in  = self::types_in_sql( self::catalog_product_types() );
		$statuses  = $public ? array( 'publish' ) : array( 'publish', 'private', 'draft', 'pending' );
		$status_in = self::types_in_sql( $statuses );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			{$type_join}
			WHERE p.post_type = 'product'
			AND p.post_status IN ({$status_in})
			AND (p.post_password IS NULL OR p.post_password = '')
			AND (pt.slug IS NULL OR pt.slug IN ({$types_in}))"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count ? (int) $count : 0;
	}

	/**
	 * Parent product IDs when wc_get_products returns nothing (SQL catalog walk).
	 *
	 * @param int  $page   1-based page.
	 * @param int  $limit  Page size.
	 * @param bool $public Published parents only.
	 * @return array<int,int>
	 */
	public static function catalog_parent_product_ids( $page, $limit, $public = true ) {
		global $wpdb;

		$page   = max( 1, absint( $page ) );
		$limit  = max( 1, absint( $limit ) );
		$offset = ( $page - 1 ) * $limit;

		$type_join = self::product_type_join_sql();
		$types_in  = self::types_in_sql( self::catalog_product_types() );
		$statuses  = $public ? array( 'publish' ) : array( 'publish', 'private', 'draft', 'pending' );
		$status_in = self::types_in_sql( $statuses );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				{$type_join}
				WHERE p.post_type = 'product'
				AND p.post_status IN ({$status_in})
				AND (p.post_password IS NULL OR p.post_password = '')
				AND (pt.slug IS NULL OR pt.slug IN ({$types_in}))
				ORDER BY p.ID ASC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Quoted SQL IN list for slugs or status strings.
	 *
	 * @param array<int, string> $values Values.
	 * @return string
	 */
	private static function types_in_sql( $values ) {
		global $wpdb;

		$quoted = array();
		foreach ( $values as $value ) {
			$quoted[] = $wpdb->prepare( '%s', (string) $value );
		}

		if ( array() === $quoted ) {
			return "''";
		}

		return implode( ',', $quoted );
	}

	private static function skip_types_sql() {
		$quoted = array();
		foreach ( self::skip_parent_types() as $slug ) {
			$quoted[] = "'" . esc_sql( $slug ) . "'";
		}

		return implode( ',', $quoted );
	}

	/**
	 * Shared FROM/WHERE for catalog stats.
	 *
	 * @param string $mode stats.
	 * @return string
	 */
	private static function eligible_from_sql( $mode = 'stats' ) {
		global $wpdb;

		unset( $mode );

		$price_key = SIDRENA_CIJENA_META_PRICE;
		$skip_in   = self::skip_types_sql();
		$type_join = self::product_type_join_sql();

		return $wpdb->prepare(
			"SELECT
				COUNT(*) AS eligible,
				SUM(has_sidrena) AS filled,
				SUM(1 - has_sidrena) AS missing
			FROM (
				SELECT p.ID,
					CASE WHEN MAX(CASE WHEN sc.meta_value IS NOT NULL AND sc.meta_value <> '' THEN 1 ELSE 0 END) = 1 THEN 1 ELSE 0 END AS has_sidrena
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} rp
					ON rp.post_id = p.ID AND rp.meta_key = '_regular_price' AND rp.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} sc
					ON sc.post_id = p.ID AND sc.meta_key = %s
				{$type_join}
				WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'inherit')
				AND (pt.slug IS NULL OR pt.slug NOT IN ({$skip_in}))
				GROUP BY p.ID
			) sidrena_rows",
			$price_key
		);
	}
}
