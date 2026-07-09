<?php
/**
 * Core publisher: post creation, taxonomy, image orchestration.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Publisher {

	const MAX_TITLE_LEN   = 300;
	const MAX_SLUG_LEN    = 200;
	const MAX_CONTENT_LEN = 5242880; // 5 MB.

	/**
	 * Image handler.
	 *
	 * @var Image_Handler
	 */
	private $images;

	/**
	 * SEO handler.
	 *
	 * @var SEO_Handler
	 */
	private $seo;

	public function __construct() {
		$this->images = new Image_Handler();
		$this->seo    = new SEO_Handler();
	}

	/**
	 * Validate publish payload.
	 *
	 * @param array $data Payload.
	 * @return true|\WP_Error
	 */
	public function validate_payload( array $data ) {
		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			return new \WP_Error( 'validation_failed', 'title is required.', [ 'status' => 400 ] );
		}
		if ( strlen( $data['title'] ) > self::MAX_TITLE_LEN ) {
			return new \WP_Error( 'validation_failed', 'title exceeds 300 chars.', [ 'status' => 400 ] );
		}
		if ( empty( $data['content'] ) || ! is_string( $data['content'] ) ) {
			return new \WP_Error( 'validation_failed', 'content is required.', [ 'status' => 400 ] );
		}
		if ( strlen( $data['content'] ) > self::MAX_CONTENT_LEN ) {
			return new \WP_Error( 'validation_failed', 'content exceeds 5MB.', [ 'status' => 400 ] );
		}
		if ( ! empty( $data['slug'] ) && strlen( $data['slug'] ) > self::MAX_SLUG_LEN ) {
			return new \WP_Error( 'validation_failed', 'slug exceeds 200 chars.', [ 'status' => 400 ] );
		}
		if ( ! empty( $data['status'] ) && ! in_array( $data['status'], [ 'draft', 'publish', 'pending', 'future', 'private' ], true ) ) {
			return new \WP_Error( 'validation_failed', 'Invalid status.', [ 'status' => 400 ] );
		}
		return true;
	}

	/**
	 * Find or create taxonomy term.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy.
	 * @return int|null
	 */
	public function find_or_create_term( string $name, string $taxonomy ) : ?int {
		$name = trim( $name );
		if ( '' === $name ) {
			return null;
		}
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$result = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $result ) ) {
			// Race: maybe created between get and insert.
			$term = get_term_by( 'name', $name, $taxonomy );
			return $term ? (int) $term->term_id : null;
		}
		return (int) $result['term_id'];
	}

	/**
	 * Resolve term names to IDs.
	 *
	 * @param array  $names    Term names.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	public function resolve_terms( array $names, string $taxonomy ) : array {
		$ids = [];
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$id = $this->find_or_create_term( sanitize_text_field( $name ), $taxonomy );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Create a post from payload.
	 *
	 * @param array $data Sanitized payload.
	 * @return array|\WP_Error Post info on success.
	 */
	public function create_post( array $data ) {
		$default_status = get_option( 'x8_publisher_default_status', 'draft' );
		$default_author = (int) get_option( 'x8_publisher_default_author', 1 );

		// Always respect the WordPress local default status setting for new posts.
		$status = $default_status;

		$postarr = [
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_content' => wp_kses_post( $data['content'] ),
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => $default_author,
		];

		if ( ! empty( $data['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $data['slug'] );
		}

		// Insert as draft first (we'll process images, then update if needed).
		$initial_status        = 'publish' === $status ? 'draft' : $status;
		$postarr['post_status'] = $initial_status;

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				'post_creation_failed',
				$post_id->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		$inline_processed = 0;

		// Process inline images if enabled.
		if ( get_option( 'x8_publisher_sideload_images', 1 ) ) {
			$result = $this->images->process_inline_images( $postarr['post_content'], $post_id );
			$inline_processed = $result['processed'];

			if ( $result['processed'] > 0 ) {
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $result['content'],
					]
				);
			}
		}

		// Featured image.
		$feature_image_id = null;
		if ( ! empty( $data['feature_image_url'] ) ) {
			$fid = $this->images->sideload(
				$data['feature_image_url'],
				$post_id,
				sanitize_text_field( $data['title'] )
			);
			if ( is_wp_error( $fid ) ) {
				// Non-fatal: log and continue.
				error_log( '[X8 Publisher] Featured image sideload failed: ' . $fid->get_error_message() );
			} else {
				set_post_thumbnail( $post_id, $fid );
				$feature_image_id = $fid;
			}
		}

		// Categories.
		$cat_ids = [];
		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$cat_ids = $this->resolve_terms( $data['categories'], 'category' );
		}

		if ( empty( $cat_ids ) ) {
			$default_cat = (int) get_option( 'x8_publisher_default_category', 0 );
			if ( $default_cat > 0 ) {
				$cat_ids = [ $default_cat ];
			}
		}

		if ( ! empty( $cat_ids ) ) {
			wp_set_post_categories( $post_id, $cat_ids, false );
		}

		// Tags.
		if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$tag_names = array_map( 'sanitize_text_field', $data['tags'] );
			wp_set_post_tags( $post_id, $tag_names, false );
		}

		// SEO meta.
		$seo_result = [ 'engine' => 'none', 'written' => [] ];
		if ( ! empty( $data['seo'] ) && is_array( $data['seo'] ) ) {
			$seo_result = $this->seo->apply( $post_id, $data['seo'] );
		}

		// Mark plugin origin.
		update_post_meta( $post_id, '_x8_publisher_request_id', $data['request_id'] ?? '' );
		update_post_meta( $post_id, '_x8_publisher_created_at', current_time( 'mysql' ) );

		// If user requested publish on creation, transition now (after media + SEO settled).
		if ( 'publish' === $status ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => 'publish',
				]
			);
		}

		$final_post = get_post( $post_id );

		return [
			'id'        => $post_id,
			'url'       => get_permalink( $post_id ),
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'status'    => $final_post->post_status,
			'media'     => [
				'featured_image_id'        => $feature_image_id,
				'inline_images_processed' => $inline_processed,
			],
			'seo'       => $seo_result,
		];
	}

	/**
	 * Update post status (publish / draft toggle).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  New status.
	 * @return array|\WP_Error
	 */
	public function update_status( int $post_id, string $status ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		if ( ! in_array( $status, [ 'draft', 'publish', 'pending', 'private' ], true ) ) {
			return new \WP_Error( 'validation_failed', 'Invalid status.', [ 'status' => 400 ] );
		}

		$result = wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => $status,
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'post_creation_failed',
				$result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		return [
			'id'     => $post_id,
			'status' => $status,
			'url'    => get_permalink( $post_id ),
		];
	}

	/**
	 * Trash a post.
	 */
	public function trash_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		$result = wp_trash_post( $post_id );
		if ( ! $result ) {
			return new \WP_Error( 'post_creation_failed', 'Failed to trash post.', [ 'status' => 500 ] );
		}
		return [ 'id' => $post_id, 'status' => 'trash' ];
	}
}