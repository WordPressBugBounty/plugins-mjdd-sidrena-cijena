<?php
/**
 * Legal filenames and the 30-day public archive.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legal filenames and the 30-day public archive.
 */
class Sidrena_Cijena_Archive {

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
	 * Archive name from the 18 September 2026 ministry example:
	 * prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.xml
	 *
	 * Spaces, hyphens, and the typed case stay. Date is DD.MM.YYYY and time
	 * is HH:MM in the site timezone. A Windows disk cannot store the colon,
	 * so that server writes 07-45 instead.
	 *
	 * @param string              $format    xml|csv.
	 * @param int                 $timestamp Unix time of publication, 0 = now.
	 * @param int                 $pohrana   Storage sequence, 0 = next unused number (does not consume).
	 * @param array<string,mixed>|null $outlet Outlet row, or null for the single shop.
	 * @return string
	 */
	public function legal_filename( $format = '', $timestamp = 0, $pohrana = 0, $outlet = null, $kind = 'robe' ) {
		$settings = Sidrena_Cijena_Settings::instance();
		$format   = '' === $format ? Sidrena_Cijena_Feed_Files::instance()->export_format() : Sidrena_Cijena_Feed_Files::instance()->sanitize_ext( $format );
		$kind     = 'usluge' === $kind ? 'USLUGE' : 'ROBE';
		$tz       = wp_timezone();
		$dt       = $timestamp > 0 ? new DateTime( '@' . (int) $timestamp ) : new DateTime( 'now', $tz );
		$dt->setTimezone( $tz );
		$stamp    = $dt->format( 'Ymd' ) . '_' . $dt->format( 'Hi' );
		$seq      = $pohrana > 0 ? (int) $pohrana : $settings->next_pohrana( is_array( $outlet ) ? $outlet : null, 'USLUGE' === $kind ? 'usluge' : 'robe' );
		$id       = $settings->trader_token();

		if ( $settings->is_za_sva_prodajna_mjesta() ) {
			$oblik = $settings->sanitize_file_part( $settings->get( 'file_oblik', 'webshop' ), 'webshop' );
			$name  = $id . '_' . $oblik . '_ZA_SVA_PRODAJNA_MJESTA_' . $kind . '_' . (string) $seq . '_' . $stamp . '.' . $format;

			return $this->archive_name_for_disk( $name );
		}

		if ( is_array( $outlet ) ) {
			$oblik  = $settings->sanitize_file_part( isset( $outlet['oblik'] ) ? $outlet['oblik'] : '', 'webshop' );
			$adresa = $settings->sanitize_file_part( isset( $outlet['adresa'] ) ? $outlet['adresa'] : '', '' );
			$oznaka = $settings->normalize_oznaka( isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' );
		} else {
			$oblik  = $settings->sanitize_file_part( $settings->get( 'file_oblik', 'webshop' ), 'webshop' );
			$adresa = $settings->sanitize_file_part( $settings->get( 'file_adresa', '' ), '' );
			$oznaka = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );
		}

		if ( '' === $adresa ) {
			$adresa = $settings->sanitize_file_part( $settings->default_file_address(), 'webshop' );
		}
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		$name = $id . '_' . $oblik . '_' . $adresa . '_' . $oznaka . '_' . $kind . '_' . (string) $seq . '_' . $stamp . '.' . $format;

		return $this->archive_name_for_disk( $name );
	}

	/**
	 * Swap the time colon for a hyphen on Windows, where ":" cannot be a filename character.
	 *
	 * @param string $filename Legal basename.
	 * @return string
	 */
	public function archive_name_for_disk( $filename ) {
		if ( '\\' === DIRECTORY_SEPARATOR && false !== strpos( $filename, ':' ) ) {
			return str_replace( ':', '-', $filename );
		}

		return $filename;
	}

	/**
	 * Last published legal filename, or a preview of the next one.
	 *
	 * @param string $format xml|csv.
	 * @return string
	 */
	public function published_filename( $format = '', $oznaka = '', $kind = 'robe' ) {
		$state = $this->feed_state( $oznaka, $kind );
		if ( ! empty( $state['filename'] ) && is_string( $state['filename'] ) ) {
			$name = $this->sanitize_archive_name( $state['filename'] );
			if ( '' !== $name ) {
				return $name;
			}
		}

		$outlet = '';
		$oznaka = Sidrena_Cijena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' !== $oznaka ) {
			$found = Sidrena_Cijena_Poslovnice::instance()->get_by_oznaka( $oznaka );
			$outlet = is_array( $found ) ? $found : null;
		}

		return $this->legal_filename( $format, time(), 0, $outlet, $kind );
	}

	/**
	 * Last publication of one live file.
	 *
	 * @param string $oznaka Outlet code, empty for the webshop.
	 * @param string $kind   robe|usluge.
	 * @return array<string, mixed>
	 */
	public function feed_state( $oznaka, $kind ) {
		$all  = get_option( 'sidrena_cijena_feed_state', array() );
		$key  = $this->feed_state_key( $oznaka, $kind );
		$row  = ( is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) ? $all[ $key ] : array();

		return $row;
	}

	/**
	 * @param string               $oznaka Outlet code.
	 * @param string               $kind   robe|usluge.
	 * @param array<string, mixed> $state  Hash, filename, count.
	 */
	public function save_feed_state( $oznaka, $kind, $state ) {
		$all = get_option( 'sidrena_cijena_feed_state', array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $this->feed_state_key( $oznaka, $kind ) ] = $state;
		update_option( 'sidrena_cijena_feed_state', $all, false );
	}

	/**
	 * @param string $oznaka Outlet code.
	 * @param string $kind   robe|usluge.
	 */
	public function clear_feed_state( $oznaka, $kind ) {
		$all = get_option( 'sidrena_cijena_feed_state', array() );
		if ( ! is_array( $all ) ) {
			return;
		}
		unset( $all[ $this->feed_state_key( $oznaka, $kind ) ] );
		update_option( 'sidrena_cijena_feed_state', $all, false );
	}

	/**
	 * @param string $oznaka Outlet code.
	 * @param string $kind   robe|usluge.
	 * @return string
	 */
	private function feed_state_key( $oznaka, $kind ) {
		$oznaka = Sidrena_Cijena_Settings::instance()->normalize_oznaka( $oznaka );
		$kind   = 'usluge' === $kind ? 'usluge' : 'robe';

		return ( '' === $oznaka ? 'web' : $oznaka ) . ':' . $kind;
	}

	/**
	 * Directory for dated archive XML files.
	 *
	 * @return string
	 */
	public function get_archive_dir() {
		$dir = Sidrena_Cijena_Feed_Files::instance()->get_public_dir();
		if ( '' === $dir ) {
			return '';
		}

		return trailingslashit( $dir ) . 'arhiva';
	}

	/**
	 * Copy today's live feed into the rolling 30-day archive.
	 *
	 * A calendar day keeps one file per object code. Regenerating that day
	 * replaces the file and does not advance the storage number.
	 *
	 * @return string|WP_Error Destination path or error.
	 */
	public function archive_public_xml() {
		$this->ensure_live_copies();
		$this->prune_archive();

		return true;
	}

	/**
	 * Copy each current live feed into the archive when that copy is missing.
	 *
	 * The live cjenik is the file shoppers see. The archive used to receive a
	 * file only after the next content change replaced it, so a filled cjenik
	 * left the archive empty.
	 */
	public function ensure_live_copies() {
		static $ran = false;
		if ( $ran ) {
			return;
		}
		$ran = true;

		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\ndefined( 'ABSPATH' ) || exit;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$files  = Sidrena_Cijena_Feed_Files::instance();
		$scopes = array(
			array(
				'oznaka' => '',
				'outlet' => null,
			),
		);
		$settings = Sidrena_Cijena_Settings::instance();
		if ( $settings->is_multi_poslovnica() && ! $settings->is_za_sva_prodajna_mjesta() ) {
			foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
				$scopes[] = array(
					'oznaka' => isset( $outlet['oznaka'] ) ? (string) $outlet['oznaka'] : '',
					'outlet' => $outlet,
				);
			}
		}

		$preferred = $files->export_format();
		foreach ( $scopes as $scope ) {
			$oznaka = isset( $scope['oznaka'] ) ? (string) $scope['oznaka'] : '';
			$outlet = isset( $scope['outlet'] ) && is_array( $scope['outlet'] ) ? $scope['outlet'] : null;
			foreach ( array( 'robe', 'usluge' ) as $kind ) {
				$this->ensure_one_live_copy( $files, $dir, $preferred, $oznaka, $kind, $outlet );
			}
		}
	}

	/**
	 * Copy one live robe/usluge file into the archive under its legal name.
	 *
	 * @param Sidrena_Cijena_Feed_Files    $files    Feed files.
	 * @param string                       $dir      Archive directory.
	 * @param string                       $format   Preferred xml|csv.
	 * @param string                       $oznaka   Outlet code.
	 * @param string                       $kind     robe|usluge.
	 * @param array<string, mixed>|null    $outlet   Outlet row.
	 */
	private function ensure_one_live_copy( $files, $dir, $format, $oznaka, $kind, $outlet ) {
		$path = $files->get_public_path( $format, $oznaka, $kind );
		$ext  = $format;
		if ( '' === $path || ! is_file( $path ) ) {
			$ext  = 'csv' === $format ? 'xml' : 'csv';
			$path = $files->get_public_path( $ext, $oznaka, $kind );
		}
		if ( '' === $path || ! is_file( $path ) ) {
			return;
		}

		$state = $this->feed_state( $oznaka, $kind );
		$name  = ( ! empty( $state['filename'] ) && is_string( $state['filename'] ) ) ? $this->sanitize_archive_name( $state['filename'] ) : '';
		if ( '' !== $name && substr( strtolower( $name ), -1 - strlen( $ext ) ) !== '.' . $ext ) {
			$name = '';
		}

		if ( '' === $name ) {
			$pohrana = Sidrena_Cijena_Settings::instance()->next_pohrana( $outlet, $kind );
			$stamp   = (int) filemtime( $path );
			$name    = $this->sanitize_archive_name( $this->legal_filename( $ext, $stamp > 0 ? $stamp : time(), $pohrana, $outlet, $kind ) );
			if ( '' === $name ) {
				return;
			}
			$this->save_feed_state(
				$oznaka,
				$kind,
				array(
					'hash'     => isset( $state['hash'] ) ? $state['hash'] : '',
					'filename' => $name,
					'pohrana'  => $pohrana,
					'count'    => isset( $state['count'] ) ? (int) $state['count'] : 0,
				)
			);
			$this->bump_published_pohrana( $outlet, $kind, $pohrana + 1 );
		}

		$dest = trailingslashit( $dir ) . $name;
		if ( is_file( $dest ) ) {
			return;
		}

		copy( $path, $dest );
	}

	/**
	 * Advance the storage sequence after the current live file is recorded.
	 *
	 * @param array<string, mixed>|null $outlet Outlet row, or null for the shop file.
	 * @param string                    $kind   robe|usluge.
	 * @param int                       $next   Next sequence.
	 */
	private function bump_published_pohrana( $outlet, $kind, $next ) {
		if ( is_array( $outlet ) && ! empty( $outlet['id'] ) ) {
			Sidrena_Cijena_Poslovnice::instance()->set_pohrana( $outlet['id'], $next, $kind );
			return;
		}

		$key = 'usluge' === $kind ? 'file_pohrana_usluge' : 'file_pohrana_robe';
		Sidrena_Cijena_Settings::instance()->save(
			array(
				$key            => $next,
				'file_pohrana'  => $next,
			)
		);
	}

	/**
	 * Archived feed files, newest first. Older days keep their original xml/csv type.
	 *
	 * @return array<int, array{date:string,ext:string,name:string,path:string,url:string,bytes:int}>
	 */
	public function get_archive_files() {
		$this->ensure_live_copies();

		return $this->scan_archive_files();
	}

	/**
	 * Archive files on disk, newest first. Does not copy the live feed in.
	 *
	 * @return array<int, array{date:string,ext:string,name:string,path:string,url:string,bytes:int,stamp:int}>
	 */
	private function scan_archive_files() {
		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$found = glob(
			trailingslashit( $dir ) . '*.{xml,csv}',
			defined( 'GLOB_BRACE' ) ? GLOB_BRACE : 0
		);
		if ( ! is_array( $found ) || array() === $found ) {
			$found = array_merge(
				glob( trailingslashit( $dir ) . '*.xml' ) ?: array(),
				glob( trailingslashit( $dir ) . '*.csv' ) ?: array()
			);
		}

		$files = array();
		foreach ( $found as $path ) {
			$base = basename( $path );
			$ext  = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'xml', 'csv' ), true ) ) {
				continue;
			}

			$files[] = array(
				'date'  => $this->archive_file_date( $base ),
				'ext'   => $ext,
				'name'  => $base,
				'path'  => $path,
				'url'   => Sidrena_Cijena_Feed_Files::instance()->get_archive_file_url( $base, $ext ),
				'bytes' => (int) filesize( $path ),
				'stamp' => $this->archive_file_timestamp( $base, $path ),
			);
		}

		usort(
			$files,
			function ( $a, $b ) {
				$cmp = $b['stamp'] <=> $a['stamp'];
				if ( 0 !== $cmp ) {
					return $cmp;
				}

				return strcmp( $b['name'], $a['name'] );
			}
		);

		return $files;
	}

	/**
	 * Admin archive panels. One list when there is a single shop, otherwise one tab per object.
	 *
	 * @return array<int, array{id:string,label:string,files:array<int, array<string, mixed>>}>
	 */
	public function admin_groups() {
		$files = $this->get_archive_files();
		if ( ! Sidrena_Cijena_Settings::instance()->is_multi_poslovnica() ) {
			return array(
				array(
					'id'    => 'all',
					'label' => '',
					'files' => $files,
				),
			);
		}

		$buckets = array();
		$legacy  = array();
		foreach ( $files as $file ) {
			$name   = isset( $file['name'] ) ? (string) $file['name'] : '';
			$oznaka = $this->archive_file_oznaka( $name );
			if ( '' === $oznaka ) {
				$legacy[] = $file;
				continue;
			}
			$buckets[ $oznaka ][] = $file;
		}

		$groups  = array();
		$settings = Sidrena_Cijena_Settings::instance();
		if ( $settings->publishes_webshop_feed() ) {
			$web = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );
			if ( '' === $web ) {
				$web = '001';
			}
			$groups[] = array(
				'id'    => 'webshop',
				'label' => __( 'Webshop', 'mjdd-sidrena-cijena' ),
				'files' => isset( $buckets[ $web ] ) ? $buckets[ $web ] : array(),
			);
			unset( $buckets[ $web ] );
		}

		foreach ( Sidrena_Cijena_Poslovnice::instance()->all() as $outlet ) {
			$oznaka   = isset( $outlet['oznaka'] ) ? (string) $outlet['oznaka'] : '';
			$groups[] = array(
				'id'    => $oznaka,
				'label' => ( isset( $outlet['naziv'] ) && '' !== $outlet['naziv'] ) ? (string) $outlet['naziv'] : $oznaka,
				'files' => isset( $buckets[ $oznaka ] ) ? $buckets[ $oznaka ] : array(),
			);
			unset( $buckets[ $oznaka ] );
		}

		foreach ( $buckets as $oznaka => $group_files ) {
			$groups[] = array(
				'id'    => (string) $oznaka,
				'label' => (string) $oznaka,
				'files' => $group_files,
			);
		}

		if ( array() !== $legacy ) {
			$groups[] = array(
				'id'    => 'legacy',
				'label' => __( 'Ostalo', 'mjdd-sidrena-cijena' ),
				'files' => $legacy,
			);
		}

		if ( array() === $groups ) {
			$groups[] = array(
				'id'    => 'all',
				'label' => '',
				'files' => array(),
			);
		}

		return $groups;
	}

	/**
	 * Delete one file inside the archive directory. Live feeds are not touched.
	 *
	 * @param string $name Basename from the admin list.
	 * @return true|WP_Error
	 */
	public function delete_archive_file( $name ) {
		$name = $this->sanitize_archive_name( $name );
		if ( '' === $name ) {
			return new WP_Error( 'sidrena_archive_name', __( 'Datoteka nije u arhivi.', 'mjdd-sidrena-cijena' ) );
		}

		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return new WP_Error( 'sidrena_archive_dir', __( 'Arhiva ne postoji.', 'mjdd-sidrena-cijena' ) );
		}

		$path = trailingslashit( $dir ) . $name;
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'sidrena_archive_missing', __( 'Datoteka nije u arhivi.', 'mjdd-sidrena-cijena' ) );
		}

		$real_dir  = realpath( $dir );
		$real_file = realpath( $path );
		if ( false === $real_dir || false === $real_file ) {
			return new WP_Error( 'sidrena_archive_path', __( 'Datoteka nije u arhivi.', 'mjdd-sidrena-cijena' ) );
		}

		$real_dir  = trailingslashit( wp_normalize_path( $real_dir ) );
		$real_file = wp_normalize_path( $real_file );
		if ( 0 !== strpos( $real_file, $real_dir ) ) {
			return new WP_Error( 'sidrena_archive_path', __( 'Datoteka nije u arhivi.', 'mjdd-sidrena-cijena' ) );
		}

		$ext = strtolower( (string) pathinfo( $real_file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'xml', 'csv' ), true ) ) {
			return new WP_Error( 'sidrena_archive_type', __( 'Datoteka nije u arhivi.', 'mjdd-sidrena-cijena' ) );
		}

		wp_delete_file( $real_file );

		return true;
	}

	/**
	 * Delete every archived XML/CSV. The live public feed stays.
	 *
	 * @return int|WP_Error Number of files removed.
	 */
	public function delete_all_archive_files() {
		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return new WP_Error( 'sidrena_archive_dir', __( 'Arhiva ne postoji.', 'mjdd-sidrena-cijena' ) );
		}

		$real_dir = trailingslashit( wp_normalize_path( $real_dir ) );
		$removed  = 0;
		$paths    = array();
		foreach ( array( '*.xml', '*.XML', '*.csv', '*.CSV' ) as $pattern ) {
			$found = glob( $real_dir . $pattern );
			if ( is_array( $found ) ) {
				$paths = array_merge( $paths, $found );
			}
		}

		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || ! is_file( $path ) ) {
				continue;
			}
			$real_file = realpath( $path );
			if ( false === $real_file ) {
				continue;
			}
			$real_file = wp_normalize_path( $real_file );
			if ( 0 !== strpos( $real_file, $real_dir ) ) {
				continue;
			}
			wp_delete_file( $real_file );
			++$removed;
		}

		return $removed;
	}

	/**
	 * True when the published legal name carries today's calendar day.
	 *
	 * @param string $filename Basename.
	 * @return bool
	 */
	public function filename_is_today( $filename ) {
		$date = $this->archive_file_date( basename( (string) $filename ) );

		return '' !== $date && $date === wp_date( 'd-m-Y' );
	}

	/**
	 * True when today's published name still describes this feed.
	 *
	 * A same-day price change reuses the name. A change of trader, place,
	 * address, or goods/services starts a new name.
	 *
	 * @param string                    $stored   Published basename.
	 * @param string                    $format   xml|csv.
	 * @param array<string, mixed>|null $outlet   Outlet row.
	 * @param string                    $kind     robe|usluge.
	 * @return bool
	 */
	public function reuses_today_filename( $stored, $format, $outlet, $kind ) {
		if ( ! $this->filename_is_today( $stored ) ) {
			return false;
		}

		$probe = $this->legal_filename( $format, time(), 1, $outlet, $kind );
		$left  = $this->archive_day_key( $stored );
		$right = $this->archive_day_key( $probe );

		return '' !== $left && $left === $right;
	}

	/**
	 * Replace the archive copy of one published name with the live bytes.
	 *
	 * @param string $source   Live path.
	 * @param string $filename Legal basename.
	 */
	public function overwrite_archive_copy( $source, $filename ) {
		$filename = $this->sanitize_archive_name( basename( (string) $filename ) );
		if ( '' === $filename || ! is_file( $source ) ) {
			return;
		}

		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return;
		}

		copy( $source, trailingslashit( $dir ) . $filename );
	}

	/**
	 * One archive file per feed per calendar day.
	 *
	 * The published name is kept. Other copies from that day are removed.
	 * A past day with no current name keeps its newest file.
	 */
	public function collapse_archive_to_one_per_day() {
		$keep = array();
		$all  = get_option( 'sidrena_cijena_feed_state', array() );
		if ( is_array( $all ) ) {
			foreach ( $all as $row ) {
				if ( ! is_array( $row ) || empty( $row['filename'] ) || ! is_string( $row['filename'] ) ) {
					continue;
				}
				$name = basename( $row['filename'] );
				$key  = $this->archive_day_key( $name );
				if ( '' !== $key ) {
					$keep[ $key ] = $name;
				}
			}
		}

		$groups = array();
		foreach ( $this->scan_archive_files() as $file ) {
			$name = isset( $file['name'] ) ? (string) $file['name'] : '';
			$key  = $this->archive_day_key( $name );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}
			$groups[ $key ][] = $file;
		}

		foreach ( $groups as $key => $files ) {
			$keep_name = isset( $keep[ $key ] ) ? $keep[ $key ] : '';
			$present   = false;
			if ( '' !== $keep_name ) {
				foreach ( $files as $file ) {
					if ( isset( $file['name'] ) && $file['name'] === $keep_name ) {
						$present = true;
						break;
					}
				}
			}
			$kept = false;
			foreach ( $files as $file ) {
				$name = isset( $file['name'] ) ? (string) $file['name'] : '';
				if ( $present ) {
					$drop = $name !== $keep_name;
				} else {
					$drop = $kept;
					$kept = true;
				}
				if ( ! $drop ) {
					continue;
				}
				$path = isset( $file['path'] ) ? (string) $file['path'] : '';
				if ( '' !== $path && file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	/**
	 * Stem plus calendar day, ignoring storage number, clock, and extension.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	private function archive_day_key( $name ) {
		$base = (string) pathinfo( basename( $name ), PATHINFO_FILENAME );
		if ( preg_match( '/^(.*)_(\d+)_(\d{8})_(\d{4})$/', $base, $match ) ) {
			$parsed = DateTime::createFromFormat( '!Ymd', $match[3], wp_timezone() );
			if ( $parsed instanceof DateTime ) {
				return $match[1] . '|' . $parsed->format( 'd-m-Y' );
			}
		}

		if ( preg_match( '/^(.*)_(\d{2}\.\d{2}\.\d{4})_(\d{2}[:\-]\d{2})$/', $base, $match ) ) {
			$parsed = DateTime::createFromFormat( '!d.m.Y', $match[2], wp_timezone() );
			if ( $parsed instanceof DateTime ) {
				return $match[1] . '|' . $parsed->format( 'd-m-Y' );
			}
		}

		return '';
	}

	/**
	 * Keep at most 30 archive files; drop the oldest.
	 */
	public function prune_archive() {
		$cutoff = time() - ( 15 * DAY_IN_SECONDS );
		foreach ( $this->get_archive_files() as $file ) {
			$path = isset( $file['path'] ) ? (string) $file['path'] : '';
			if ( '' === $path || ! file_exists( $path ) ) {
				continue;
			}
			$mtime = (int) filemtime( $path );
			if ( $mtime > 0 && $mtime < $cutoff ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Archive bucket: object code from the legal filename, or legacy for older names.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_group_key( $name ) {
		$oznaka = $this->archive_oznaka_from_name( $name );
		return '' !== $oznaka ? 'oznaka:' . $oznaka : 'legacy';
	}

	/**
	 * Object code from oblik_adresa_oznaka_pohrana_datum_vrijeme.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_oznaka_from_name( $name ) {
		$base = (string) pathinfo( (string) $name, PATHINFO_FILENAME );
		if ( preg_match( '/_ZA_SVA_PRODAJNA_MJESTA_(?:ROBE|USLUGE)_\d+_\d{8}_\d{4}$/i', $base ) ) {
			return '';
		}
		if ( preg_match( '/_([^_]+)_(?:ROBE|USLUGE)_\d+_\d{8}_\d{4}$/i', $base, $match ) ) {
			return (string) $match[1];
		}

		$parts = explode( '_', $base );
		if ( 6 !== count( $parts ) ) {
			return '';
		}

		$time = $parts[5];
		$date = $parts[4];
		$old  = (bool) preg_match( '/^\d{8}$/', $date ) && (bool) preg_match( '/^\d{4}$/', $time );
		$new  = (bool) preg_match( '/^\d{2}\.\d{2}\.\d{4}$/', $date ) && (bool) preg_match( '/^\d{2}[:\-]\d{2}$/', $time );
		if ( ! $old && ! $new ) {
			return '';
		}

		return (string) $parts[2];
	}

	/**
	 * Validate an archive date (d-m-Y).
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	public function sanitize_archive_date( $date ) {
		$date = is_string( $date ) ? $date : '';
		if ( ! preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $date, $match ) ) {
			return '';
		}

		if ( ! checkdate( (int) $match[2], (int) $match[1], (int) $match[3] ) ) {
			return '';
		}

		return $match[1] . '-' . $match[2] . '-' . $match[3];
	}

	/**
	 * Legacy sidrena-cijena_DD-MM-YYYY names can sit in a pretty URL path.
	 * Ministry names have spaces and a time colon, which Apache/nginx/WordPress
	 * often fail to route, so those files are opened with a query argument.
	 *
	 * @param string $name Basename.
	 * @return bool
	 */
	public function archive_name_allows_pretty_path( $name ) {
		return (bool) preg_match( '/^[A-Za-z0-9._-]+\.(xml|csv)$/', $name );
	}

	/**
	 * Safe archive basename.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	public function sanitize_archive_name( $name ) {
		$name = is_string( $name ) ? $name : '';
		$name = rawurldecode( $name );
		$name = str_replace( '\\', '/', $name );
		$name = basename( $name );

		if ( '' === $name || false !== strpos( $name, '..' ) ) {
			return '';
		}

		if ( $this->archive_name_allows_pretty_path( $name ) ) {
			return $name;
		}

		if ( ! preg_match( '/^[\p{L}\p{N} .,\'_\-:]+\.(xml|csv)$/u', $name ) ) {
			return '';
		}

		if ( strlen( $name ) > 240 ) {
			return '';
		}

		return $name;
	}

	/**
	 * Display date from an archive filename.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_file_date( $name ) {
		if ( preg_match( '/_(\d{2})\.(\d{2})\.(\d{4})_\d{2}[:\-]\d{2}\.(xml|csv)$/', $name, $match ) ) {
			return $match[1] . '-' . $match[2] . '-' . $match[3];
		}

		if ( preg_match( '/_(\d{8})_\d{4}\.(xml|csv)$/', $name, $match ) ) {
			$parsed = DateTime::createFromFormat( '!Ymd', $match[1], wp_timezone() );
			$errors = DateTime::getLastErrors();
			$clean  = $parsed instanceof DateTime && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) );
			return $clean ? $parsed->format( 'd-m-Y' ) : '';
		}

		if ( preg_match( '/_(\d{2}-\d{2}-\d{4})\.(xml|csv)$/', $name, $match ) ) {
			return $match[1];
		}

		return '';
	}

	/**
	 * Unix timestamp for sorting an archive file.
	 *
	 * @param string $name Basename.
	 * @param string $path Absolute path.
	 * @return int
	 */
	public function archive_file_timestamp( $name, $path ) {
		if ( preg_match( '/_(\d{2})\.(\d{2})\.(\d{4})_(\d{2})[:\-](\d{2})\.(xml|csv)$/', $name, $match ) ) {
			$parsed = DateTime::createFromFormat( '!d.m.Y H:i', $match[1] . '.' . $match[2] . '.' . $match[3] . ' ' . $match[4] . ':' . $match[5], wp_timezone() );
			if ( $parsed instanceof DateTime ) {
				return $parsed->getTimestamp();
			}
		}

		if ( preg_match( '/_(\d{8})_(\d{4})\.(xml|csv)$/', $name, $match ) ) {
			$parsed = DateTime::createFromFormat( '!YmdHi', $match[1] . $match[2], wp_timezone() );
			$errors = DateTime::getLastErrors();
			$clean  = $parsed instanceof DateTime && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) );
			if ( $clean ) {
				return $parsed->getTimestamp();
			}
		}

		$legacy = $this->archive_file_date( $name );
		if ( '' !== $legacy ) {
			$stamp = $this->archive_date_timestamp( $legacy );
			if ( $stamp ) {
				return $stamp;
			}
		}

		return file_exists( $path ) ? (int) filemtime( $path ) : 0;
	}

	/**
	 * Unix timestamp for an archive date (d-m-Y).
	 *
	 * @param string $date Archive date.
	 * @return int
	 */
	public function archive_date_timestamp( $date ) {
		$parsed = DateTime::createFromFormat( '!d-m-Y', $date, wp_timezone() );
		return $parsed instanceof DateTime ? $parsed->getTimestamp() : 0;
	}

	/**
	 * Today's archive files for one object code, newest first.
	 *
	 * @param string $oznaka Object code.
	 * @return array<int, array{date:string,ext:string,name:string,path:string,url:string,bytes:int,stamp:int}>
	 */
	public function same_day_archive_files( $oznaka ) {
		$today  = wp_date( 'd-m-Y' );
		$oznaka = Sidrena_Cijena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		$matches = array();
		foreach ( $this->get_archive_files() as $file ) {
			if ( ! isset( $file['date'] ) || $file['date'] !== $today ) {
				continue;
			}
			if ( $this->archive_oznaka_from_name( $file['name'] ) !== $oznaka ) {
				continue;
			}
			$matches[] = $file;
		}

		return $matches;
	}

	/**
	 * Storage number from a legal archive basename, or 0 when the name has none.
	 *
	 * @param string $name Basename.
	 * @return int
	 */
	public function archive_pohrana_from_name( $name ) {
		$base = (string) pathinfo( (string) $name, PATHINFO_FILENAME );
		if ( preg_match( '/_(\d+)_\d{8}_\d{4}$/', $base, $match ) ) {
			return absint( $match[1] );
		}

		if ( '' === $this->archive_oznaka_from_name( $name ) ) {
			return 0;
		}

		$parts = explode( '_', $base );

		return isset( $parts[3] ) ? absint( $parts[3] ) : 0;
	}

	/**
	 * Copy a live feed into the one archive file for this calendar day and object code.
	 *
	 * An existing file is replaced and keeps its name, time, and storage number.
	 * A format change keeps that stem and swaps xml/csv. Extra files from the
	 * same day are removed. A new day uses the next legal name.
	 *
	 * @param string                   $dir     Archive directory.
	 * @param string                   $source  Live file.
	 * @param string                   $format  xml|csv.
	 * @param int                      $pohrana Next storage number when this is a new day.
	 * @param array<string, mixed>|null $outlet Outlet row, or null for the webshop.
	 * @return array{path:string,filename:string,pohrana:int,replaced:bool}|WP_Error
	 */
	private function place_daily_archive( $dir, $source, $format, $pohrana, $outlet = null ) {
		$settings = Sidrena_Cijena_Settings::instance();
		if ( is_array( $outlet ) ) {
			$oznaka = $settings->normalize_oznaka( isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' );
		} else {
			$oznaka = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );
		}
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		$format   = Sidrena_Cijena_Feed_Files::instance()->sanitize_ext( $format );
		$today    = $this->same_day_archive_files( $oznaka );
		$replaced = array() !== $today;

		if ( $replaced ) {
			$stem     = (string) pathinfo( $today[0]['name'], PATHINFO_FILENAME );
			$filename = $stem . '.' . $format;
			$used     = $this->archive_pohrana_from_name( $today[0]['name'] );
			if ( $used < 1 ) {
				$used = (int) $pohrana;
			}
		} else {
			$filename = $this->legal_filename( $format, time(), $pohrana, $outlet );
			$used     = (int) $pohrana;
		}

		$dest = trailingslashit( $dir ) . $filename;
		if ( ! copy( $source, $dest ) ) {
			return new WP_Error( 'sidrena_xml_archive_copy', __( 'Arhivski cjenik nije mogao biti spremljen.', 'mjdd-sidrena-cijena' ) );
		}

		foreach ( $today as $old ) {
			if ( ! isset( $old['name'] ) || $old['name'] === $filename ) {
				continue;
			}
			if ( ! empty( $old['path'] ) && file_exists( $old['path'] ) ) {
				wp_delete_file( $old['path'] );
			}
		}

		return array(
			'path'     => $dest,
			'filename' => $filename,
			'pohrana'  => $used,
			'replaced' => $replaced,
		);
	}

	/**
	 * Copy each outlet's live feed into the archive.
	 *
	 * A new calendar day advances that outlet's storage sequence. The same day
	 * replaces its file and leaves the sequence where it is.
	 *
	 * @return true|WP_Error
	 */
	public function archive_poslovnice() {
		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sidrena_xml_archive_mkdir', __( 'Mapa arhive nije mogla biti stvorena.', 'mjdd-sidrena-cijena' ) );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\ndefined( 'ABSPATH' ) || exit;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$format  = Sidrena_Cijena_Feed_Files::instance()->export_format();
		$meta    = Sidrena_Cijena_Export::instance()->get_public_meta();
		$stored  = ( isset( $meta['outlets'] ) && is_array( $meta['outlets'] ) ) ? $meta['outlets'] : array();
		$changed = false;

		$web_source = Sidrena_Cijena_Feed_Files::instance()->get_public_path( $format, '' );
		if ( Sidrena_Cijena_Settings::instance()->publishes_webshop_feed() && file_exists( $web_source ) ) {
			$settings = Sidrena_Cijena_Settings::instance();
			$pohrana  = $settings->sanitize_pohrana( $settings->get( 'file_pohrana', 1 ) );
			$placed   = $this->place_daily_archive( $dir, $web_source, $format, $pohrana, null );
			if ( is_wp_error( $placed ) ) {
				return $placed;
			}
			if ( empty( $placed['replaced'] ) ) {
				$settings->save( array( 'file_pohrana' => $pohrana + 1 ) );
			}
			$meta['filename'] = $placed['filename'];
			$meta['pohrana']  = $placed['pohrana'];
			$changed          = true;
		}

		foreach ( Sidrena_Cijena_Poslovnice::instance()->with_products() as $outlet ) {
			$source = Sidrena_Cijena_Feed_Files::instance()->get_public_path( $format, $outlet['oznaka'] );
			if ( ! file_exists( $source ) ) {
				continue;
			}

			$pohrana = Sidrena_Cijena_Settings::instance()->sanitize_pohrana( $outlet['pohrana'] );
			$placed  = $this->place_daily_archive( $dir, $source, $format, $pohrana, $outlet );
			if ( is_wp_error( $placed ) ) {
				return $placed;
			}

			if ( empty( $placed['replaced'] ) ) {
				Sidrena_Cijena_Poslovnice::instance()->set_pohrana( $outlet['id'], $pohrana + 1 );
			}

			$current                     = isset( $stored[ $outlet['oznaka'] ] ) && is_array( $stored[ $outlet['oznaka'] ] ) ? $stored[ $outlet['oznaka'] ] : array();
			$current['filename']         = $placed['filename'];
			$current['pohrana']          = $placed['pohrana'];
			$stored[ $outlet['oznaka'] ] = $current;
			$changed                     = true;
		}

		if ( $changed ) {
			$meta['outlets'] = $stored;
			update_option( Sidrena_Cijena_Export::PUBLIC_OPTION, $meta );
			$this->prune_archive();
		}

		return true;
	}

	/**
	 * Normalized object code from an archive basename, or empty when the name has none.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_file_oznaka( $name ) {
		$base = (string) pathinfo( (string) $name, PATHINFO_FILENAME );
		if ( preg_match( '/_ZA_SVA_PRODAJNA_MJESTA_(?:ROBE|USLUGE)_\d+_\d{8}_\d{4}$/i', $base ) ) {
			$settings = Sidrena_Cijena_Settings::instance();
			$oznaka   = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );

			return '' !== $oznaka ? $oznaka : '001';
		}

		return Sidrena_Cijena_Settings::instance()->normalize_oznaka( $this->archive_oznaka_from_name( $name ) );
	}

	/**
	 * File list for the [sidrena-arhiva] shortcode. Name and date only.
	 *
	 * An oznaka keeps files for that object code. Names the parser does not
	 * recognize stay in the unfiltered list only.
	 *
	 * @param string $oznaka Object code, empty for every file.
	 * @return string
	 */
	public function get_archive_embed_html( $oznaka = '' ) {
		$files  = $this->get_archive_files();
		$oznaka = Sidrena_Cijena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' !== $oznaka ) {
			$matched = array();
			foreach ( $files as $file ) {
				$name = isset( $file['name'] ) ? (string) $file['name'] : '';
				if ( $this->archive_file_oznaka( $name ) === $oznaka ) {
					$matched[] = $file;
				}
			}
			$files = $matched;
		}

		if ( array() === $files ) {
			return '<p class="sidrena-arhiva-empty">' . esc_html__( 'Arhiva je prazna.', 'mjdd-sidrena-cijena' ) . '</p>';
		}

		$html = '<ol class="sidrena-arhiva">';
		foreach ( $files as $file ) {
			$stamp = isset( $file['stamp'] ) ? (int) $file['stamp'] : 0;
			$label = $stamp ? wp_date( 'd.m.Y. H:i', $stamp ) : ( isset( $file['date'] ) ? (string) $file['date'] : '' );
			$name  = isset( $file['name'] ) ? (string) $file['name'] : '';
			$url   = isset( $file['url'] ) ? (string) $file['url'] : '';
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			if ( '' !== $label ) {
				$html .= '<span>' . esc_html( $label ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';

		return $html;
	}

	/**
	 * Last generation meta for one outlet.
	 *
	 * @param string $oznaka Object code.
	 * @return array<string, mixed>
	 */
	public function get_outlet_meta( $oznaka ) {
		$oznaka = Sidrena_Cijena_Settings::instance()->normalize_oznaka( $oznaka );
		$stored = Sidrena_Cijena_Export::instance()->get_public_meta();
		if ( '' === $oznaka || empty( $stored['outlets'] ) || ! is_array( $stored['outlets'] ) ) {
			return array();
		}

		if ( isset( $stored['outlets'][ $oznaka ] ) && is_array( $stored['outlets'][ $oznaka ] ) ) {
			return $stored['outlets'][ $oznaka ];
		}

		return array();
	}
}
