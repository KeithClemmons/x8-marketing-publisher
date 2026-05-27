<?php
/**
 * SEO meta handler — RankMath + Yoast.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Handler {

	const RANKMATH = 'rankmath';
	const YOAST    = 'yoast';
	const NONE     = 'none';

	/**
	 * RankMath meta map.
	 */
	const RANKMATH_MAP = [
		'focus_keyword'   => 'rank_math_focus_keyword',
		'meta_title'      => 'rank_math_title',
		'meta_description' => 'rank_math_description',
		'canonical_url'   => 'rank_math_canonical_url',
		'og_title'        => 'rank_math_facebook_title',
		'og_description'  => 'rank_math_facebook_description',
		'og_image_url'    => 'rank_math_facebook_image',
	];

	/**
	 * Yoast meta map.
	 */
	const YOAST_MAP = [
		'focus_keyword'   => '_yoast_wpseo_focuskw',
		'meta_title'      => '_yoast_wpseo_title',
		'meta_description' => '_yoast_wpseo_metadesc',
		'canonical_url'   => '_yoast_wpseo_canonical',
		'og_title'        => '_yoast_wpseo_opengraph-title',
		'og_description'  => '_yoast_wpseo_opengraph-description',
		'og_image_url'    => '_yoast_wpseo_opengraph-image',
	];

	/**
	 * Detect primary engine (returns first found, but we write to BOTH if both active).
	 *
	 * @return string
	 */
	public function detect_engine() : string {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return self::RANKMATH;
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return self::YOAST;
		}
		return self::NONE;
	}

	/**
	 * Whether RankMath is active.
	 */
	public function has_rankmath() : bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Whether Yoast is active.
	 */
	public function has_yoast() : bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	/**
	 * Apply SEO meta to a post.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $seo_data SEO payload.
	 * @return array{engine:string,written:array}
	 */
	public function apply( int $post_id, array $seo_data ) : array {
		$written     = [];
		$has_rm      = $this->has_rankmath();
		$has_yoast   = $this->has_yoast();

		// Foolproof fallback: if plugin detection fails or constants aren't loaded yet
		// during early REST API bootstrap, we still write both sets of meta keys to the database.
		$force_write = ! $has_rm && ! $has_yoast;

		foreach ( $seo_data as $field => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$value = is_string( $value ) ? wp_strip_all_tags( $value ) : $value;

			if ( ( $has_rm || $force_write ) && isset( self::RANKMATH_MAP[ $field ] ) ) {
				update_post_meta( $post_id, self::RANKMATH_MAP[ $field ], $value );
				$written[] = self::RANKMATH_MAP[ $field ];
			}
			if ( ( $has_yoast || $force_write ) && isset( self::YOAST_MAP[ $field ] ) ) {
				update_post_meta( $post_id, self::YOAST_MAP[ $field ], $value );
				$written[] = self::YOAST_MAP[ $field ];
			}
		}

		$engine_used = self::NONE;
		if ( $has_rm && $has_yoast ) {
			$engine_used = 'rankmath+yoast';
		} elseif ( $has_rm ) {
			$engine_used = self::RANKMATH;
		} elseif ( $has_yoast ) {
			$engine_used = self::YOAST;
		} elseif ( $force_write ) {
			$engine_used = 'fallback-write';
		}

		return [
			'engine'  => $engine_used,
			'written' => $written,
		];
	}
}