<?php

WP_CLI::add_command( 'elasticpress', 'ElasticPress_CLI_Command' );

/**
 * CLI Commands for ElasticPress
 *
 */
class ElasticPress_CLI_Command extends WP_CLI_Command {

	/**
	 * Add the document mapping
	 *
	 * @synopsis [--network-wide]
	 * @subcommand put-mapping
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function put_mapping( $args, $assoc_args ) {

		if ( ! empty( $assoc_args['network-wide'] ) ) {
			$sites = wp_get_sites();

			foreach ( $sites as $site ) {
				switch_to_blog( $site['blog_id'] );

				WP_CLI::line( sprintf( __( 'Adding mapping for site %d...', 'elasticpress' ), (int) $site['blog_id'] ) );

				// Flushes index first
				ep_flush();

				$result = ep_put_mapping();

				if ( $result ) {
					WP_CLI::success( __( 'Mapping sent', 'elasticpress' ) );
				} else {
					WP_CLI::error( __( 'Mapping failed', 'elasticpress' ) );
				}

				restore_current_blog();
			}
		} else {
			WP_CLI::line( __( 'Adding mapping...', 'elasticpress' ) );

			// Flushes index first
			$this->flush( $args, $assoc_args );

			$result = ep_put_mapping();

			if ( $result ) {
				WP_CLI::success( __( 'Mapping sent', 'elasticpress' ) );
			} else {
				WP_CLI::error( __( 'Mapping failed', 'elasticpress' ) );
			}
		}
	}

	/**
	 * Flush the current index. !!Warning!! This empties your elasticsearch index for the entire site.
	 *
	 * @todo replace this function with one that updates all rows with a --force option
	 * @synopsis [--network-wide]
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function flush( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['network-wide'] ) ) {
			$sites = wp_get_sites();

			foreach ( $sites as $site ) {
				switch_to_blog( $site['blog_id'] );

				WP_CLI::line( sprintf( __( 'Flushing index for site %d...', 'elasticpress' ), (int) $site['blog_id'] ) );

				$result = ep_flush();

				if ( $result ) {
					WP_CLI::success( __( 'Index flushed', 'elasticpress' ) );
				} else {
					WP_CLI::error( __( 'Flush failed', 'elasticpress' ) );
				}

				restore_current_blog();
			}
		} else {
			WP_CLI::line( __( 'Flushing index...', 'elasticpress' ) );

			$result = ep_flush();

			if ( $result ) {
				WP_CLI::success( __( 'Index flushed', 'elasticpress' ) );
			} else {
				WP_CLI::error( __( 'Flush failed', 'elasticpress' ) );
			}
		}
	}

	/**
	 * Map network alias to every index in the network
	 *
	 * @param array $args
	 * @subcommand map-network-alias
	 * @param array $assoc_args
	 */
	public function map_network_alias( $args, $assoc_args ) {
		WP_CLI::line( __( 'Mapping network alias...', 'elasticpress' ) );

		ep_delete_network_alias();

		$create_result = $this->_create_network_alias();

		if ( $create_result ) {
			WP_CLI::success( __( 'Done!', 'elasticpress' ) );
		} else {
			WP_CLI::error( __( 'An error occurred', 'elasticpress' ) );
		}
	}

	private function _create_network_alias() {
		$sites = wp_get_sites();
		$indexes = array();

		foreach ( $sites as $site ) {
			switch_to_blog( $site['blog_id'] );

			$indexes[] = ep_get_index_name();

			restore_current_blog();
		}

		return ep_create_network_alias( $indexes );
	}

	/**
	 * Index all posts for a site or network wide
	 *
	 * @synopsis [--network-wide]
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function index( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['network-wide'] ) ) {
			WP_CLI::line( __( 'Indexing posts network-wide...', 'elasticpress' ) );

			$sites = wp_get_sites();

			foreach ( $sites as $site ) {
				switch_to_blog( $site['blog_id'] );

				$result = $this->_index_helper();

				WP_CLI::line( sprintf( __( 'Number of posts synced on site %d: %d', 'elasticpress' ), get_current_blog_id(), $result['synced'] ) );

				if ( ! empty( $errors ) ) {
					WP_CLI::error( sprintf( __( 'Number of post sync errors on site %d: %d', 'elasticpress' ), get_current_blog_id(), count( $result['errors'] ) ) );
				}

				restore_current_blog();
			}

			WP_CLI::line( __( 'Mapping network alias...' ) );
			$this->_create_network_alias();

		} else {
			WP_CLI::line( __( 'Indexing posts...', 'elasticpress' ) );

			$result = $this->_index_helper();

			WP_CLI::line( sprintf( __( 'Number of posts synced on site %d: %d', 'elasticpress' ), get_current_blog_id(), $result['synced'] ) );

			if ( ! empty( $errors ) ) {
				WP_CLI::error( sprintf( __( 'Number of post sync errors on site %d: %d', 'elasticpress' ), get_current_blog_id(), count( $result['errors'] ) ) );
			}
		}

		WP_CLI::success( __( 'Done!', 'elasticpress' ) );
	}

	private function _index_helper() {
		$synced = 0;
		$errors = array();
		$offset = 0;

		while ( true ) {

			$args = array(
				'posts_per_page' => 500,
				'post_type'      => ep_get_indexable_post_types(),
				'post_status'    => 'publish',
				'offset'         => $offset,
			);

			$query = new WP_Query( $args );

			if ( $query->have_posts() ) {

				while ( $query->have_posts() ) {
					$query->the_post();

					$result = ep_sync_post( get_the_ID() );

					if ( ! $result ) {
						$errors[] = get_the_ID();
					} else {
						$synced++;
					}
				}
			} else {
				break;
			}

			$offset += 500;

			usleep( 500 );
		}

		wp_reset_postdata();

		return array( 'synced' => $synced, 'errors' => $errors );
	}
}