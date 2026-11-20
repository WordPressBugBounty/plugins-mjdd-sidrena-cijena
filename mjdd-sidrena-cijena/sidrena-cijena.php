<?php
/**
 * Plugin Name: MJDD Sidrena i bazna cijena
 * Plugin URI: https://mjdd.hr/sidrena-cijena/
 * Description: MJDD plugin for the Croatian bazna cijena (sidrena cijena) - WooCommerce products or a standalone public pricelist.
 * Version: 1.29.11
 * Author: Jure Zadravec / MJDD
 * Author URI: https://mjdd.hr
 * Text Domain: mjdd-sidrena-cijena
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.3
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'SIDRENA_CIJENA_VERSION', '1.29.11' );
define( 'SIDRENA_CIJENA_FILE', __FILE__ );
define( 'SIDRENA_CIJENA_PATH', plugin_dir_path( __FILE__ ) );
define( 'SIDRENA_CIJENA_URL', plugin_dir_url( __FILE__ ) );
define( 'SIDRENA_CIJENA_META_PRICE', '_sidrena_cijena' );
define( 'SIDRENA_CIJENA_META_DATE', '_sidrena_cijena_datum' );
define( 'SIDRENA_CIJENA_META_CJM', '_sidrena_cijena_cjm' );
define( 'SIDRENA_CIJENA_META_CJM_SUFFIX', '_sidrena_cijena_cjm_suffix' );
define( 'SIDRENA_CIJENA_META_OBLIK', '_sidrena_naziv_posebnog_oblika' );
define( 'SIDRENA_CIJENA_META_JEDINICA', '_sidrena_jedinica_mjere' );
define( 'SIDRENA_CIJENA_META_JEDINICA_CIJENA', '_sidrena_cijena_jedinice_mjere' );
define( 'SIDRENA_CIJENA_META_NETO', '_sidrena_neto_kolicina' );
define( 'SIDRENA_CIJENA_META_VRSTA', '_sidrena_vrsta' );
define( 'SIDRENA_CIJENA_META_ISKLJUCI', '_sidrena_iskljuci_javni' );

require_once SIDRENA_CIJENA_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Sidrena_Cijena_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sidrena_Cijena_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SIDRENA_CIJENA_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Sidrena_Cijena_Plugin', 'init' ) );

/**
 * Universal output helper for themes: sidrena_cijena(); or sidrena_cijena( $product );
 *
 * @param WC_Product|int|null $product Product, ID, or current product.
 */
if ( ! function_exists( 'sidrena_cijena' ) ) {
	function sidrena_cijena( $product = null ) {
		if ( ! class_exists( 'Sidrena_Cijena_Frontend' ) ) {
			return;
		}

		echo wp_kses_post( Sidrena_Cijena_Frontend::instance()->get_html_for_context( $product ) );
	}
}
