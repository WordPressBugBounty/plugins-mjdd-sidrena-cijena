<?php
/**
 * Build public and download catalog rows from WooCommerce and the manual catalog.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build public and download catalog rows from WooCommerce and the manual catalog.
 */
class Sidrena_Cijena_Feed_Rows {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Last catalog build notes for admin when count is 0.
	 *
	 * @var array<string, mixed>|null
	 */
	private $last_catalog_diagnostics = null;

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
	 * Collect catalog rows: name, sku, prices.
	 *
	 * WooCommerce rows are the shop default language. A translation is not a second row.
	 *
	 * @param bool $missing_only Only products/variations without sidrena price.
	 * @param bool $public       Published catalog for the public XML (adds id/url).
	 * @return array<int, array<string, string>>
	 */
	public function collect_rows( $missing_only = false, $public = false, $only_ids = null, $catalog_scope = null ) {
		return Sidrena_Cijena_I18n::instance()->run_for_product_catalog_query(
			function () use ( $missing_only, $public, $only_ids, $catalog_scope ) {
				return $this->collect_rows_now( $missing_only, $public, $only_ids, $catalog_scope );
			}
		);
	}

	/**
	 * Collect catalog rows while the default language is already current.
	 *
	 * @param bool              $missing_only  Only products/variations without sidrena price.
	 * @param bool              $public        Published catalog for the public XML (adds id/url).
	 * @param array<int,string>|null $only_ids Outlet product ids, or null for the full catalog.
	 * @param string|null       $catalog_scope Outlet code, empty for the webshop catalog, null for every manual row.
	 * @return array<int, array<string, string>>
	 */
	private function collect_rows_now( $missing_only = false, $public = false, $only_ids = null, $catalog_scope = null ) {
		if ( ! Sidrena_Cijena_Plugin::woocommerce_active() ) {
			$assigned = array();
			if ( is_string( $catalog_scope ) && '' !== $catalog_scope ) {
				$outlet = Sidrena_Cijena_Poslovnice::instance()->get_by_oznaka( $catalog_scope );
				$assigned = ( is_array( $outlet ) && isset( $outlet['products'] ) ) ? $outlet['products'] : array();
			} elseif ( is_string( $catalog_scope ) && '' === $catalog_scope ) {
				$assigned = array_keys( Sidrena_Cijena_Poslovnice::instance()->assigned_ids_lookup() );
			}

			return Sidrena_Cijena_Catalog::instance()->to_export_rows_for( $missing_only, $public, $catalog_scope, $assigned );
		}

		if ( is_array( $only_ids ) ) {
			$rows = $this->collect_rows_for_ids( $only_ids, $missing_only, $public );
			if ( is_string( $catalog_scope ) && '' !== $catalog_scope ) {
				$rows = $this->apply_outlet_dostupnost( $rows, $catalog_scope );
			}
			$this->append_extra_catalog_rows( $rows, $missing_only, $public, $catalog_scope, $only_ids );
			return $rows;
		}

		$rows = $this->collect_woo_rows( $missing_only, $public );
		$this->append_extra_catalog_rows( $rows, $missing_only, $public, $catalog_scope, null );

		return $rows;
	}

	/**
	 * One page of the public catalog. The admin progress job calls this.
	 *
	 * Woo shops return only Woo rows. Manual catalog rows are appended once,
	 * by the publisher, after the last page. A shop where wc_get_products
	 * returns nothing on page 1 falls back to the SQL walk in that same request.
	 *
	 * @param int $page  1-based page.
	 * @param int $limit Parents per page.
	 * @return array{rows: array<int, array<string, string>>, done: bool, parents: int}
	 */
	public function collect_public_batch( $page, $limit = 30 ) {
		$page  = max( 1, (int) $page );
		$limit = max( 1, (int) $limit );

		return Sidrena_Cijena_I18n::instance()->run_for_product_catalog_query(
			function () use ( $page, $limit ) {
				if ( ! Sidrena_Cijena_Plugin::woocommerce_active() || ! function_exists( 'wc_get_products' ) ) {
					if ( $page > 1 ) {
						return array(
							'rows'    => array(),
							'done'    => true,
							'parents' => 0,
						);
					}

					$rows = $this->collect_rows_now( false, true, null, null );

					return array(
						'rows'    => $rows,
						'done'    => true,
						'parents' => count( $rows ),
					);
				}

				$query = array(
					'status'  => array( 'publish' ),
					'type'    => Sidrena_Cijena_Activator::catalog_product_types(),
					'limit'   => $limit,
					'page'    => $page,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				);
				$this->apply_catalog_language_to_product_query( $query );

				$forward_lang    = static function ( $args, $query_vars ) {
					if ( isset( $query_vars['lang'] ) && is_string( $query_vars['lang'] ) && '' !== $query_vars['lang'] ) {
						$args['lang'] = $query_vars['lang'];
					}

					return $args;
				};
				$use_pll_forward = $this->uses_polylang_catalog_query_filter();
				if ( $use_pll_forward ) {
					add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $forward_lang, 10, 2 );
				}

				try {
					$ids = wc_get_products( $query );
					if ( ! is_array( $ids ) ) {
						$ids = array();
					}

					if ( array() === $ids ) {
						if ( 1 === $page ) {
							$sql_rows = $this->collect_woo_rows_sql( false, true );
							if ( array() !== $sql_rows ) {
								return array(
									'rows'    => $sql_rows,
									'done'    => true,
									'parents' => count( $sql_rows ),
								);
							}
						}

						return array(
							'rows'    => array(),
							'done'    => true,
							'parents' => 0,
						);
					}

					$meta = Sidrena_Cijena_Product_Meta::instance();
					$rows = array();
					update_meta_cache( 'post', $ids );

					foreach ( $ids as $product_id ) {
						$product = wc_get_product( $product_id );
						if ( ! $product ) {
							continue;
						}

						if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
							$children = $product->get_children();
							if ( ! empty( $children ) ) {
								update_meta_cache( 'post', $children );
							}
							foreach ( $children as $variation_id ) {
								$variation = wc_get_product( $variation_id );
								if ( $variation ) {
									$this->maybe_add_row( $rows, $variation, $meta, false, true );
								}
							}
							continue;
						}

						$this->maybe_add_row( $rows, $product, $meta, false, true );
					}

					return array(
						'rows'    => $rows,
						'done'    => count( $ids ) < $limit,
						'parents' => count( $ids ),
					);
				} finally {
					if ( $use_pll_forward ) {
						remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $forward_lang, 10 );
					}
				}
			}
		);
	}

	/**
	 * Published parent products, for the progress total.
	 *
	 * @return int
	 */
	public function count_public_parents() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		return (int) Sidrena_Cijena_I18n::instance()->run_for_product_catalog_query(
			function () {
				$query = array(
					'status'   => array( 'publish' ),
					'type'     => Sidrena_Cijena_Activator::catalog_product_types(),
					'limit'    => 1,
					'page'     => 1,
					'paginate' => true,
					'return'   => 'ids',
				);
				$this->apply_catalog_language_to_product_query( $query );
				$result = wc_get_products( $query );
				if ( is_object( $result ) && isset( $result->total ) ) {
					return (int) $result->total;
				}

				return 0;
			}
		);
	}

	/**
	 * WooCommerce products and variations only.
	 *
	 * @param bool $missing_only Empty sidrena only.
	 * @param bool $public       Public catalog columns.
	 * @return array<int, array<string, string>>
	 */
	public function collect_woo_rows( $missing_only, $public ) {
		$this->last_catalog_diagnostics = null;
		$meta                           = Sidrena_Cijena_Product_Meta::instance();
		$rows                           = array();
		$page                           = 1;
		$catalog_source                 = 'wc';

		/*
		 * WooCommerce drops unknown query vars before WP_Query. Polylang only
		 * limits the catalog when `lang` reaches that query.
		 */
		$forward_lang = static function ( $args, $query_vars ) {
			if ( isset( $query_vars['lang'] ) && is_string( $query_vars['lang'] ) && '' !== $query_vars['lang'] ) {
				$args['lang'] = $query_vars['lang'];
			}

			return $args;
		};

		$use_pll_forward = $this->uses_polylang_catalog_query_filter();

		if ( $use_pll_forward ) {
			add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $forward_lang, 10, 2 );
		}

		try {
			while ( true ) {
				$query = array(
					'status'  => $public ? array( 'publish' ) : array( 'publish', 'private', 'draft', 'pending' ),
					'type'    => Sidrena_Cijena_Activator::catalog_product_types(),
					'limit'   => Sidrena_Cijena_Export::BATCH_SIZE,
					'page'    => $page,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				);
				$this->apply_catalog_language_to_product_query( $query );

				$ids = wc_get_products( $query );

				if ( empty( $ids ) ) {
					if ( 1 === $page ) {
						$sql_rows = $this->collect_woo_rows_sql( $missing_only, $public );
						if ( array() !== $sql_rows ) {
							$this->store_catalog_diagnostics( 'sql_fallback', $query, count( $sql_rows ) );
							return $sql_rows;
						}
					}
					break;
				}

				update_meta_cache( 'post', $ids );

				foreach ( $ids as $product_id ) {
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}

					if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
						$children = $product->get_children();
						if ( ! empty( $children ) ) {
							update_meta_cache( 'post', $children );
						}
						foreach ( $children as $variation_id ) {
							$variation = wc_get_product( $variation_id );
							if ( $variation ) {
								$this->maybe_add_row( $rows, $variation, $meta, $missing_only, $public );
							}
						}
						continue;
					}

					$this->maybe_add_row( $rows, $product, $meta, $missing_only, $public );
				}

				if ( count( $ids ) < Sidrena_Cijena_Export::BATCH_SIZE ) {
					break;
				}

				++$page;
			}

			if ( empty( $rows ) && $public ) {
				$sql_rows = $this->collect_woo_rows_sql( $missing_only, $public );
				if ( array() !== $sql_rows ) {
					$this->store_catalog_diagnostics( 'sql_fallback', $query, count( $sql_rows ) );
					return $sql_rows;
				}
			}

			$this->store_catalog_diagnostics( $catalog_source, $query, count( $rows ) );

			return $rows;
		} finally {
			if ( $use_pll_forward ) {
				remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $forward_lang, 10 );
			}
		}
	}

	/**
	 * SQL parent-product walk when wc_get_products returns no IDs on page 1.
	 *
	 * @param bool $missing_only Filter empty sidrena.
	 * @param bool $public       Public catalog columns.
	 * @return array<int, array<string, string>>
	 */
	private function collect_woo_rows_sql( $missing_only, $public ) {
		$meta = Sidrena_Cijena_Product_Meta::instance();
		$rows = array();
		$page = 1;

		while ( true ) {
			$ids = Sidrena_Cijena_Activator::catalog_parent_product_ids( $page, Sidrena_Cijena_Export::BATCH_SIZE, $public );
			if ( empty( $ids ) ) {
				break;
			}

			update_meta_cache( 'post', $ids );

			foreach ( $ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
					$children = $product->get_children();
					if ( ! empty( $children ) ) {
						update_meta_cache( 'post', $children );
					}
					foreach ( $children as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation ) {
							$this->maybe_add_row( $rows, $variation, $meta, $missing_only, $public );
						}
					}
					continue;
				}

				$this->maybe_add_row( $rows, $product, $meta, $missing_only, $public );
			}

			if ( count( $ids ) < Sidrena_Cijena_Export::BATCH_SIZE ) {
				break;
			}

			++$page;
		}

		return $rows;
	}

	/**
	 * Diagnostics from the last or a fresh catalog probe (admin when count is 0).
	 *
	 * @return array<string, mixed>
	 */
	public function catalog_diagnostics() {
		if ( is_array( $this->last_catalog_diagnostics ) ) {
			return $this->last_catalog_diagnostics;
		}

		return $this->probe_catalog_diagnostics();
	}

	/**
	 * Remember how the last catalog walk behaved.
	 *
	 * @param string               $source   wc or sql_fallback.
	 * @param array<string, mixed> $query    wc_get_products args from page 1.
	 * @param int                  $row_count Rows collected.
	 */
	private function store_catalog_diagnostics( $source, $query, $row_count ) {
		$probe                       = $this->probe_catalog_diagnostics();
		$probe['source']             = $source;
		$probe['rows']               = $row_count;
		$probe['product_types']      = Sidrena_Cijena_Activator::catalog_product_types();
		$this->last_catalog_diagnostics = $probe;
	}

	/**
	 * Quick counts to explain an empty public feed on a misconfigured shop.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_catalog_diagnostics() {
		$i18n = Sidrena_Cijena_I18n::instance();
		$out  = array(
			'wpml_active'           => $i18n->wpml_active(),
			'polylang'              => function_exists( 'pll_default_language' ),
			'sql_publish_parents'   => Sidrena_Cijena_Activator::catalog_sql_parent_count( true ),
			'wc_total_publish'      => null,
			'wc_with_suppress_only' => null,
		);

		if ( ! function_exists( 'wc_get_products' ) ) {
			return $out;
		}

		$query = array(
			'status'   => array( 'publish' ),
			'type'     => Sidrena_Cijena_Activator::catalog_product_types(),
			'limit'    => 1,
			'page'     => 1,
			'paginate' => true,
			'return'   => 'ids',
		);
		$this->apply_catalog_language_to_product_query( $query );

		$result = wc_get_products( $query );
		if ( is_object( $result ) && isset( $result->total ) ) {
			$out['wc_total_publish'] = (int) $result->total;
		}

		$plain = wc_get_products(
			array(
				'status'           => array( 'publish' ),
				'type'             => Sidrena_Cijena_Activator::catalog_product_types(),
				'limit'            => 1,
				'page'             => 1,
				'paginate'         => true,
				'return'           => 'ids',
				'suppress_filters' => true,
			)
		);
		if ( is_object( $plain ) && isset( $plain->total ) ) {
			$out['wc_with_suppress_only'] = (int) $plain->total;
		}

		return $out;
	}

	/**
	 * Extra catalog rows (services, made-to-order) after WooCommerce products.
	 *
	 * @param array<int, array<string, string>> $rows         Collector.
	 * @param bool                              $missing_only Filter empty sidrena.
	 * @param bool                              $public       Include public catalog fields.
	 */
	public function append_extra_catalog_rows( &$rows, $missing_only, $public, $catalog_scope = null, $assigned_ids = null ) {
		if ( ! Sidrena_Cijena_Plugin::woocommerce_active() ) {
			return;
		}

		$catalog = Sidrena_Cijena_Catalog::instance();
		if ( null === $catalog_scope ) {
			$extra = $catalog->to_export_rows( $missing_only, $public );
		} elseif ( '' === $catalog_scope ) {
			$extra = $catalog->to_export_rows_for( $missing_only, $public, '', array_keys( Sidrena_Cijena_Poslovnice::instance()->assigned_ids_lookup() ) );
		} else {
			$ids   = is_array( $assigned_ids ) ? $assigned_ids : array();
			$extra = $catalog->to_export_rows_for( $missing_only, $public, $catalog_scope, $ids );
		}

		foreach ( $extra as $row ) {
			if ( $public && isset( $row['id'] ) && '' !== (string) $row['id'] ) {
				$row['id'] = 'd' . $row['id'];
			}
			unset( $row['poslovnica'] );
			$rows[] = $row;
		}
	}

	/**
	 * Public rows limited to an outlet's product ids.
	 *
	 * @param array<int, array<string, string>> $rows     Rows.
	 * @param array<int, string>|null           $only_ids Ids, or null for the full catalog.
	 * @return array<int, array<string, string>>
	 */
	public function filter_rows_by_ids( $rows, $only_ids ) {
		if ( ! is_array( $only_ids ) ) {
			return $rows;
		}

		$lookup = array();
		foreach ( $only_ids as $id ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( is_string( $id ) && '' !== $id ) {
				$lookup[ $id ] = true;
			}
		}

		if ( array() === $lookup ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' !== $id && isset( $lookup[ $id ] ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Woo rows for an explicit id list (simple products, variations, or variable parents).
	 *
	 * @param array<int, string> $only_ids     Product ids.
	 * @param bool               $missing_only Filter empty sidrena.
	 * @param bool               $public       Include public catalog fields.
	 * @return array<int, array<string, string>>
	 */
	public function collect_rows_for_ids( $only_ids, $missing_only, $public ) {
		$i18n   = Sidrena_Cijena_I18n::instance();
		$lookup = array();
		foreach ( $only_ids as $id ) {
			$source = $i18n->source_product_id( (int) absint( $id ) );
			if ( $source > 0 ) {
				$lookup[ (string) $source ] = true;
			}
		}

		if ( array() === $lookup ) {
			return array();
		}

		update_meta_cache( 'post', array_map( 'intval', array_keys( $lookup ) ) );

		$meta = Sidrena_Cijena_Product_Meta::instance();
		$rows = array();
		$seen = array();

		foreach ( array_keys( $lookup ) as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
				$children = $product->get_children();
				if ( ! empty( $children ) ) {
					update_meta_cache( 'post', $children );
				}
				foreach ( $children as $variation_id ) {
					$variation_id = (int) $variation_id;
					if ( isset( $seen[ $variation_id ] ) ) {
						continue;
					}
					$seen[ $variation_id ] = true;
					$variation             = wc_get_product( $variation_id );
					if ( $variation ) {
						$this->maybe_add_row( $rows, $variation, $meta, $missing_only, $public );
					}
				}
				continue;
			}

			$product_id = (int) $product->get_id();
			if ( isset( $seen[ $product_id ] ) ) {
				continue;
			}
			$seen[ $product_id ] = true;
			$this->maybe_add_row( $rows, $product, $meta, $missing_only, $public );
		}

		return $rows;
	}

	/**
	 * Append one product or variation row.
	 *
	 * @param array<int, array<string, string>> $rows         Collector.
	 * @param WC_Product                        $product      Product.
	 * @param Sidrena_Cijena_Product_Meta       $meta         Meta helper.
	 * @param bool                              $missing_only Filter empty sidrena.
	 * @param bool                              $public       Include public catalog fields.
	 */
	public function maybe_add_row( &$rows, $product, $meta, $missing_only, $public = false ) {
		if ( ! Sidrena_Cijena_I18n::instance()->is_source_product( $product->get_id() ) ) {
			return;
		}

		if ( $public && ! $this->include_in_public_feed( $product ) ) {
			return;
		}

		$sidrena = $meta->get_price( $product->get_id() );

		if ( $missing_only && '' !== $sidrena ) {
			return;
		}

		$row = array(
			'naziv'           => $this->feed_naziv( $product ),
			'sku'             => (string) $product->get_sku(),
			'cijena_redovna'  => (string) $product->get_regular_price(),
			'cijena_akcijska' => (string) $product->get_sale_price(),
			'sidrena_cijena'  => $sidrena,
			'vrsta'           => $meta->get_vrsta( $product->get_id() ),
			'sidrena_datum'   => $meta->get_display_date( $product->get_id() ),
		);

		if ( $public ) {
			$row['id']                     = (string) $product->get_id();
			$row['url']                    = (string) get_permalink( $product->get_id() );
			$row['barkod']                 = $this->product_barcode( $product );
			$row['marka']                  = Sidrena_Cijena_Settings::instance()->product_marka( $product );
			$row['dostupnost']             = $this->product_dostupnost( $product );
			$row['jedinica_mjere']         = $meta->get_jedinica( $product->get_id() );
			$row['neto_kolicina']          = $meta->get_neto( $product->get_id() );
			$row['cijena_jedinice_mjere']  = $this->format_feed_amount( $meta->get_price_to_display( $product, $meta->get_jedinica_cijena( $product->get_id() ) ) );
			$row['maloprodajna_cijena']    = $this->format_feed_amount( $meta->get_price_to_display( $product, $this->active_raw_price( $product ) ) );
			$row['naziv_posebnog_oblika']  = $meta->public_oblik_name( $product );
			$row['sidrena_cijena']         = $this->format_feed_amount( $meta->get_price_to_display( $product, $sidrena ) );
		}

		$rows[] = $row;
	}

	/**
	 * Catalog name: parent title plus all assigned variation attributes.
	 *
	 * WooCommerce may store only the parent title when a variation has three or
	 * more attributes, or an attribute slug with a hyphen. The price list still
	 * needs those values so each row identifies the item.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function feed_naziv( $product ) {
		$name = wp_strip_all_tags( $product->get_name() );

		if ( ! $product->is_type( 'variation' ) ) {
			return $name;
		}

		$parent_id = $product->get_parent_id();
		$base      = $parent_id ? wp_strip_all_tags( (string) get_post_field( 'post_title', $parent_id ) ) : '';
		if ( '' === $base ) {
			return $name;
		}

		$suffix = function_exists( 'wc_get_formatted_variation' )
			? trim( wp_strip_all_tags( wc_get_formatted_variation( $product, true, false ) ) )
			: '';

		if ( '' === $suffix || false !== strpos( $base, $suffix ) ) {
			return $base;
		}

		return $base . ' - ' . $suffix;
	}

	/**
	 * GTIN / UPC / EAN / ISBN from WooCommerce, then common barcode meta keys.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function product_barcode( $product ) {
		$barcode = '';

		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$barcode = $this->clean_barcode( $product->get_global_unique_id() );
		}

		if ( '' === $barcode ) {
			$id = $product->get_id();
			foreach ( array( '_global_unique_id', '_gtin', '_ean', '_upc', '_isbn', '_barcode', 'barcode', '_alg_ean', '_wpm_gtin_code', 'hwp_product_gtin' ) as $key ) {
				$barcode = $this->clean_barcode( get_post_meta( $id, $key, true ) );
				if ( '' !== $barcode ) {
					break;
				}
			}
		}

		/**
		 * Barcode written to the public XML/CSV feed.
		 *
		 * @param string     $barcode Detected GTIN/UPC/EAN/ISBN, or empty.
		 * @param WC_Product $product Product or variation.
		 */
		return (string) apply_filters( 'sidrena_cijena_product_barcode', $barcode, $product );
	}

	/**
	 * First non-empty barcode token, with whitespace removed.
	 *
	 * @param mixed $raw Meta or getter value.
	 * @return string
	 */
	public function clean_barcode( $raw ) {
		$value = is_scalar( $raw ) ? preg_replace( '/\s+/', '', (string) $raw ) : '';
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Legal availability flag: dostupno or nedostupno.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function product_dostupnost( $product ) {
		$value = $product->is_in_stock() ? 'dostupno' : 'nedostupno';

		/**
		 * Availability written to the public XML/CSV feed.
		 *
		 * @param string     $value   dostupno|nedostupno.
		 * @param WC_Product $product Product or variation.
		 */
		$value = apply_filters( 'sidrena_cijena_product_dostupnost', $value, $product );

		return 'nedostupno' === $value ? 'nedostupno' : 'dostupno';
	}

	/**
	 * Price the customer pays now: sale price while the sale is active, otherwise regular.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string Raw stored amount.
	 */
	public function active_raw_price( $product ) {
		if ( $product->is_on_sale() ) {
			$sale = (string) $product->get_sale_price();
			if ( '' !== $sale ) {
				return $sale;
			}
		}

		$regular = (string) $product->get_regular_price();
		if ( '' !== $regular ) {
			return $regular;
		}

		return (string) $product->get_price();
	}

	/**
	 * Dot-decimal amount for the machine-readable feed.
	 *
	 * @param float|null $amount Display amount.
	 * @return string
	 */
	public function format_feed_amount( $amount ) {
		if ( null === $amount || '' === $amount ) {
			return '';
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return number_format( (float) $amount, $decimals, '.', '' );
	}

	/**
	 * Published products only. Password-protected posts stay out of public files.
	 *
	 * Catalog-hidden products stay in: many B2B shops hide the catalog but still
	 * sell by URL. The statutory online cjenik must list those offers.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public function include_in_public_feed( $product ) {
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
			return false;
		}

		$post = get_post( $product->get_id() );
		if ( $post instanceof WP_Post && '' !== (string) $post->post_password ) {
			return false;
		}

		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$parent_post = get_post( $parent_id );
			if ( ! $parent_post instanceof WP_Post || 'publish' !== $parent_post->post_status || '' !== (string) $parent_post->post_password ) {
				return false;
			}
		}

		$include = ! Sidrena_Cijena_Product_Meta::instance()->is_excluded_from_public_feed( $product->get_id() );

		/**
		 * Whether a published product belongs in the public XML, CSV, and HTML cjenik.
		 *
		 * @param bool       $include Include in public files.
		 * @param WC_Product $product Product or variation.
		 */
		return (bool) apply_filters( 'sidrena_cijena_include_in_public_feed', $include, $product );
	}

	/**
	 * Public rows grouped once: webshop (all Woo + webshop catalog) and each outlet.
	 *
	 * @return array{webshop: array<int, array<string, string>>, outlets: array<string, array<int, array<string, string>>>}
	 */
	public function collect_public_by_scope() {
		return Sidrena_Cijena_I18n::instance()->run_for_product_catalog_query(
			function () {
				return $this->collect_public_by_scope_now();
			}
		);
	}

	/**
	 * Public rows grouped once, while the default language is already current.
	 *
	 * @return array{webshop: array<int, array<string, string>>, outlets: array<string, array<int, array<string, string>>>}
	 */
	private function collect_public_by_scope_now() {
		if ( ! Sidrena_Cijena_Plugin::woocommerce_active() ) {
			$web     = $this->collect_rows( false, true, null, '' );
			$outlets = array();
			foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
				$outlets[ $outlet['oznaka'] ] = $this->collect_rows( false, true, isset( $outlet['products'] ) ? $outlet['products'] : array(), $outlet['oznaka'] );
			}

			return array(
				'webshop' => $web,
				'outlets' => $outlets,
			);
		}

		$woo = $this->collect_woo_rows( false, true );

		$web = $woo;
		$this->append_extra_catalog_rows( $web, false, true, '', array_keys( Sidrena_Cijena_Poslovnice::instance()->assigned_ids_lookup() ) );

		$outlets = array();
		foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
			$ids  = isset( $outlet['products'] ) ? $outlet['products'] : array();
			$rows = $this->apply_outlet_dostupnost( $this->filter_woo_rows_for_assigned_ids( $woo, $ids ), $outlet['oznaka'] );
			$this->append_extra_catalog_rows( $rows, false, true, $outlet['oznaka'], $ids );
			$outlets[ $outlet['oznaka'] ] = $rows;
		}

		return array(
			'webshop' => $web,
			'outlets' => $outlets,
		);
	}

	/**
	 * Keep Woo rows whose id is in the outlet assignment, expanding variable parents.
	 *
	 * @param array<int, array<string, string>> $woo_rows  Full Woo public rows.
	 * @param array<int, string>                $only_ids  Assigned product ids.
	 * @return array<int, array<string, string>>
	 */
	public function filter_woo_rows_for_assigned_ids( $woo_rows, $only_ids ) {
		$expanded = $this->collect_rows_for_ids( $only_ids, false, true );
		$by_id    = array();
		foreach ( $woo_rows as $row ) {
			if ( isset( $row['id'] ) && '' !== (string) $row['id'] ) {
				$by_id[ (string) $row['id'] ] = $row;
			}
		}

		$out = array();
		foreach ( $expanded as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' !== $id && isset( $by_id[ $id ] ) ) {
				$out[] = $by_id[ $id ];
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Replace WooCommerce stock on outlet rows with the availability stored for that poslovnica.
	 * Catalog rows (id prefix d) keep the flag imported on that row.
	 *
	 * @param array<int, array<string, string>> $rows   Feed rows.
	 * @param string                            $oznaka Outlet code.
	 * @return array<int, array<string, string>>
	 */
	public function apply_outlet_dostupnost( $rows, $oznaka ) {
		$map = $this->stock_map_for_source_language( Sidrena_Cijena_Poslovnice::instance()->stock_map( $oznaka ) );
		foreach ( $rows as $index => $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $id || 'd' === $id[0] ) {
				continue;
			}
			$rows[ $index ]['dostupnost'] = Sidrena_Cijena_Money::dostupnost_from_outlet_map( $id, $map );
		}

		return $rows;
	}

	/**
	 * WPML and Polylang language args for wc_get_products during a catalog walk.
	 *
	 * WPML needs lang=all so products are found even after wpml_switch_language.
	 * is_source_product() still keeps one row per default-language product.
	 *
	 * @param array<string, mixed> $query wc_get_products args.
	 */
	private function apply_catalog_language_to_product_query( &$query ) {
		if ( Sidrena_Cijena_I18n::instance()->wpml_active() ) {
			$query['lang'] = 'all';
			return;
		}

		$lang = $this->polylang_catalog_lang();
		if ( '' !== $lang ) {
			$query['lang'] = $lang;
			return;
		}

		// Monolingual shops: avoid other plugins emptying wc_get_products in admin/cron.
		$query['suppress_filters'] = true;
	}

	/**
	 * Whether the Polylang lang filter hook is needed for product queries.
	 *
	 * @return bool
	 */
	private function uses_polylang_catalog_query_filter() {
		if ( Sidrena_Cijena_I18n::instance()->wpml_active() || ! function_exists( 'pll_default_language' ) ) {
			return false;
		}

		return '' !== $this->polylang_catalog_lang();
	}

	/**
	 * Polylang slug for the catalog query. Empty when Polylang is not active.
	 *
	 * @return string
	 */
	private function polylang_catalog_lang() {
		if ( ! function_exists( 'pll_default_language' ) ) {
			return '';
		}

		$lang = Sidrena_Cijena_I18n::instance()->get_default_language();
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Outlet availability keyed by the default-language product id.
	 *
	 * A flag stored on a translation still matches that source row. The saved
	 * outlet option is left as the merchant stored it.
	 *
	 * @param array<string, string> $map Product id => dostupno|nedostupno.
	 * @return array<string, string>
	 */
	private function stock_map_for_source_language( $map ) {
		if ( ! is_array( $map ) ) {
			return array();
		}

		$i18n = Sidrena_Cijena_I18n::instance();
		if ( ! $i18n->uses_post_translations() ) {
			return $map;
		}

		$out = array();
		foreach ( $map as $id => $flag ) {
			$source = $i18n->source_product_id( (int) $id );
			if ( $source <= 0 ) {
				continue;
			}

			$key = (string) $source;
			if ( (string) $id === $key || ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $flag;
			}
		}

		return $out;
	}
}
