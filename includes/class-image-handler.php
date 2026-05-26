<?php
/**
 * Image sideloading handler.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Handler {

	/**
	 * Ensure WP media functions are loaded.
	 */
	private function load_media_deps() : void {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Sideload a single image URL and return attachment ID.
	 *
	 * @param string $url     Source URL.
	 * @param int    $post_id Parent post ID (0 if unattached).
	 * @param string $desc    Description.
	 * @return int|\WP_Error
	 */
	public function sideload( string $url, int $post_id = 0, string $desc = '' ) {
		$this->load_media_deps();

		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new \WP_Error( 'image_sideload_failed', 'Empty image URL.' );
		}

		$id = media_sideload_image( $url, $post_id, $desc, 'id' );
		if ( is_wp_error( $id ) ) {
			return new \WP_Error(
				'image_sideload_failed',
				'Failed to sideload image: ' . $id->get_error_message(),
				[ 'url' => $url ]
			);
		}
		return (int) $id;
	}

	/**
	 * Process inline <img> tags inside content: sideload remote images, rewrite src.
	 *
	 * @param string $content HTML content.
	 * @param int    $post_id Parent post.
	 * @return array{content:string,processed:int,errors:array}
	 */
	public function process_inline_images( string $content, int $post_id ) : array {
		if ( false === strpos( $content, '<img' ) ) {
			return [
				'content'   => $content,
				'processed' => 0,
				'errors'    => [],
			];
		}

		$this->load_media_deps();

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		// UTF-8 BOM hack so DOMDocument respects encoding.
		$dom->loadHTML(
			'<?xml encoding="UTF-8"?><html><body>' . $content . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$processed = 0;
		$errors    = [];
		$home      = home_url();

		$imgs = $dom->getElementsByTagName( 'img' );
		// Iterate over a static array because the live NodeList changes as we mutate.
		$nodes = [];
		foreach ( $imgs as $img ) {
			$nodes[] = $img;
		}

		foreach ( $nodes as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( empty( $src ) ) {
				continue;
			}

			// Skip already-local images.
			if ( 0 === strpos( $src, $home ) ) {
				continue;
			}

			// Skip data: URIs.
			if ( 0 === stripos( $src, 'data:' ) ) {
				continue;
			}

			$alt    = $img->getAttribute( 'alt' );
			$new_id = $this->sideload( $src, $post_id, $alt );
			if ( is_wp_error( $new_id ) ) {
				$errors[] = $new_id->get_error_message();
				continue;
			}

			$new_url = wp_get_attachment_url( $new_id );
			if ( $new_url ) {
				$img->setAttribute( 'src', $new_url );
				$img->setAttribute( 'data-x8-attachment-id', (string) $new_id );
				// Remove srcset so browsers don't fetch the original.
				if ( $img->hasAttribute( 'srcset' ) ) {
					$img->removeAttribute( 'srcset' );
				}
				$processed++;
			}
		}

		// Extract inner body HTML.
		$body     = $dom->getElementsByTagName( 'body' )->item( 0 );
		$new_html = '';
		if ( $body ) {
			foreach ( $body->childNodes as $child ) {
				$new_html .= $dom->saveHTML( $child );
			}
		} else {
			$new_html = $content;
		}

		return [
			'content'   => $new_html,
			'processed' => $processed,
			'errors'    => $errors,
		];
	}
}