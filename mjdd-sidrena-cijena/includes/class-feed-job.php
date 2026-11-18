<?php
/**
 * Batched public-catalog build for wp-admin.
 *
 * One HTTP request handles a short page of products and stores the cursor,
 * so a refresh continues the same run instead of starting over.
 *
 * @package Sidrena_Cijena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Progress job for Generiraj javni cjenik.
 */
class Sidrena_Cijena_Feed_Job {

	const OPTION    = 'sidrena_cijena_feed_job';
	const STEP_LOCK = 'sidrena_cijena_feed_step';
	const PAGE_SIZE = 30;

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
	 * Whether a build is in progress.
	 *
	 * @return bool
	 */
	public function is_running() {
		$state = $this->get();

		return isset( $state['status'] ) && 'running' === $state['status'];
	}

	/**
	 * Status array safe to send to the browser.
	 *
	 * @return array<string, mixed>
	 */
	public function status() {
		return $this->public_status( $this->get() );
	}

	/**
	 * Begin a run, or keep going if one is already in progress.
	 *
	 * A failed save can be retried without walking the catalog again.
	 *
	 * @param bool $force Rewrite the live files even when the body is unchanged.
	 * @return array<string, mixed>
	 */
	public function start( $force = true ) {
		$state = $this->get();
		if ( isset( $state['status'] ) && 'running' === $state['status'] ) {
			if ( $force ) {
				$state['force'] = true;
				$this->save( $state );
			}

			return $this->public_status( $state );
		}

		if ( isset( $state['status'], $state['phase'] ) && 'error' === $state['status'] && 'write' === $state['phase'] && is_file( $this->spool_path() ) ) {
			$state['status']  = 'running';
			$state['message'] = '';
			$state['force']   = $force || ! empty( $state['force'] );
			$state['updated'] = time();
			$this->save( $state );

			return $this->public_status( $state );
		}

		$this->reset_spool();
		delete_transient( Sidrena_Cijena_Export::REBUILD_LOCK );

		$total = 0;
		if ( Sidrena_Cijena_Plugin::woocommerce_active() ) {
			$total = Sidrena_Cijena_Feed_Rows::instance()->count_public_parents();
		}

		$state = array(
			'status'   => 'running',
			'phase'    => 'collect',
			'page'     => 1,
			'seen'     => 0,
			'total'    => $total,
			'rows'     => 0,
			'force'    => (bool) $force,
			'message'  => '',
			'count'    => 0,
			'updated'  => time(),
			'attempts' => 0,
		);
		$this->save( $state );

		return $this->public_status( $state );
	}

	/**
	 * Process the next short slice, or write the files when collection is done.
	 *
	 * @return array<string, mixed>
	 */
	public function step() {
		$state = $this->get();
		if ( ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
			return $this->public_status( $state );
		}

		if ( get_transient( self::STEP_LOCK ) ) {
			$public           = $this->public_status( $state );
			$public['retry']  = true;

			return $public;
		}

		set_transient( self::STEP_LOCK, 1, 90 );
		try {
			if ( isset( $state['phase'] ) && 'write' === $state['phase'] ) {
				return $this->finish( $state );
			}

			return $this->collect_step( $state );
		} finally {
			delete_transient( self::STEP_LOCK );
		}
	}

	/**
	 * Several steps, bounded by a clock, for cron.
	 *
	 * @param int $seconds Time budget.
	 * @return array<string, mixed>
	 */
	public function run_for( $seconds ) {
		$deadline = time() + max( 1, (int) $seconds );
		$status   = $this->status();
		do {
			if ( empty( $status['status'] ) || 'running' !== $status['status'] ) {
				break;
			}
			$status = $this->step();
			if ( ! empty( $status['done'] ) || ! empty( $status['retry'] ) ) {
				break;
			}
		} while ( time() < $deadline );

		return $status;
	}

	/**
	 * Append one page of rows to the spool.
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return array<string, mixed>
	 */
	private function collect_step( $state ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 60 );
		}

		$page  = max( 1, (int) $state['page'] );
		$batch = Sidrena_Cijena_Feed_Rows::instance()->collect_public_batch( $page, self::PAGE_SIZE );
		$this->append_rows( isset( $batch['rows'] ) && is_array( $batch['rows'] ) ? $batch['rows'] : array() );

		$parents        = isset( $batch['parents'] ) ? (int) $batch['parents'] : 0;
		$state['seen']  = (int) $state['seen'] + $parents;
		$state['rows']  = (int) $state['rows'] + ( isset( $batch['rows'] ) && is_array( $batch['rows'] ) ? count( $batch['rows'] ) : 0 );
		$state['updated'] = time();

		if ( ! empty( $batch['done'] ) ) {
			$state['phase'] = 'write';
			if ( (int) $state['total'] < (int) $state['seen'] ) {
				$state['total'] = (int) $state['seen'];
			}
		} else {
			$state['page'] = $page + 1;
			if ( (int) $state['total'] < (int) $state['seen'] ) {
				$state['total'] = (int) $state['seen'];
			}
		}

		$this->save( $state );

		return $this->public_status( $state );
	}

	/**
	 * Write the public files from the spool.
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return array<string, mixed>
	 */
	private function finish( $state ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		$rows   = $this->read_spool();
		$result = Sidrena_Cijena_Export::instance()->publish_collected_public_rows( $rows, ! empty( $state['force'] ) );
		if ( is_wp_error( $result ) ) {
			$state['attempts'] = isset( $state['attempts'] ) ? (int) $state['attempts'] + 1 : 1;
			$state['message']  = $result->get_error_message();
			$state['updated']  = time();
			if ( $state['attempts'] >= 3 ) {
				$state['status'] = 'error';
			}
			$this->save( $state );

			return $this->public_status( $state );
		}

		$count = isset( $result['count'] ) ? (int) $result['count'] : (int) $state['rows'];
		$this->reset_spool();
		$state = array(
			'status'  => 'done',
			'phase'   => 'done',
			'page'    => 0,
			'seen'    => isset( $state['total'] ) ? (int) $state['total'] : (int) $state['seen'],
			'total'   => isset( $state['total'] ) ? (int) $state['total'] : (int) $state['seen'],
			'rows'    => $count,
			'force'   => false,
			'message' => '',
			'count'   => $count,
			'updated' => time(),
			'attempts'=> 0,
		);
		$this->save( $state );

		return $this->public_status( $state );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private function save( $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function public_status( $state ) {
		$status = isset( $state['status'] ) ? (string) $state['status'] : 'idle';
		$seen   = isset( $state['seen'] ) ? (int) $state['seen'] : 0;
		$total  = isset( $state['total'] ) ? (int) $state['total'] : 0;
		$phase  = isset( $state['phase'] ) ? (string) $state['phase'] : '';
		$count  = isset( $state['count'] ) ? (int) $state['count'] : 0;
		$done   = 'done' === $status;

		return array(
			'status'  => $status,
			'phase'   => $phase,
			'seen'    => $seen,
			'total'   => $total,
			'rows'    => isset( $state['rows'] ) ? (int) $state['rows'] : 0,
			'count'   => $count,
			'done'    => $done,
			'message' => isset( $state['message'] ) ? (string) $state['message'] : '',
		);
	}

	/**
	 * @param array<int, array<string, string>> $rows Rows.
	 */
	private function append_rows( $rows ) {
		if ( array() === $rows ) {
			return;
		}

		$path = $this->spool_path();
		$dir  = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$lines = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$encoded = wp_json_encode( $row );
			if ( is_string( $encoded ) ) {
				$lines .= $encoded . "\n";
			}
		}
		if ( '' === $lines ) {
			return;
		}

		file_put_contents( $path, $lines, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function read_spool() {
		$path = $this->spool_path();
		if ( ! is_file( $path ) ) {
			return array();
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array();
		}

		$rows = array();
		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( ! is_string( $line ) || '' === trim( $line ) ) {
				continue;
			}
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$rows[] = $decoded;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Drop the spool from a previous run.
	 */
	private function reset_spool() {
		$path = $this->spool_path();
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * @return string
	 */
	private function spool_path() {
		$dir = Sidrena_Cijena_Feed_Files::instance()->get_public_dir();
		if ( '' === $dir ) {
			$dir = get_temp_dir();
		}

		return trailingslashit( $dir ) . '.job/rows.jsonl';
	}
}
