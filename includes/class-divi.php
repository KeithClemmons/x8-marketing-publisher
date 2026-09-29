<?php
/**
 * Divi support for the X8 control center's agents.
 *
 * Divi keeps a page's layout in its content, which the control center edits
 * through the standard WordPress REST API. What that API can't reach lives
 * here: turning the Divi builder on for a page, clearing Divi's generated CSS
 * after a change, and the Divi Library and Theme Builder layouts.
 *
 * Routes (under X8_PUBLISHER_NAMESPACE):
 *   GET  /divi/status                    Divi version and what's available
 *   POST /divi/pages/{id}/builder        Turn the Divi builder on for a page or post
 *   POST /divi/cache/clear               Clear Divi's generated CSS (one post, or all)
 *   GET  /divi/library                   Divi Library items (?search=, ?type=section|row|module|layout)
 *   GET  /divi/theme-builder             Theme Builder templates and their header, body and footer layouts
 *   GET  /divi/layouts/{id}              One Library item or Theme Builder layout, with its content
 *   POST /divi/layouts/{id}              Replace its content ({ content, expected_modified })
 *
 * Callers sign in as a WordPress user who can edit pages (an application
 * password), or with the plugin's API key.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Divi {

	/** Post types that hold Divi Library items and Theme Builder layouts. */
	const LAYOUT_TYPES = [ 'et_pb_layout', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];

	/** @var Auth */
	private $auth;

	public function __construct( Auth $auth ) {
		$this->auth = $auth;
	}

	public function register_routes() : void {
		$ns = X8_PUBLISHER_NAMESPACE;
		$id = [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ];

		register_rest_route( $ns, '/divi/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'status' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( $ns, '/divi/pages/(?P<id>\d+)/builder', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'enable_builder' ],
			'permission_callback' => [ $this, 'can_edit_post' ],
			'args'                => $id,
		] );
		register_rest_route( $ns, '/divi/cache/clear', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'clear_cache_route' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( $ns, '/divi/library', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'library' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( $ns, '/divi/theme-builder', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'theme_builder' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( $ns, '/divi/layouts/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_layout' ],
				'permission_callback' => [ $this, 'can_edit_post' ],
				'args'                => $id,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_layout' ],
				'permission_callback' => [ $this, 'can_edit_post' ],
				'args'                => $id,
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Permissions

	/** A user who can edit pages, or a request with the plugin's API key. */
	public function can_edit( \WP_REST_Request $request ) {
		if ( current_user_can( 'edit_pages' ) ) {
			return true;
		}
		return $this->key_or_error( $request );
	}

	/** Like can_edit, for one post: a user must be able to edit that post. */
	public function can_edit_post( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( is_user_logged_in() ) {
			return current_user_can( 'edit_post', $id )
				? true
				: new \WP_Error( 'forbidden', 'This user can’t edit that item.', [ 'status' => 403 ] );
		}
		return $this->key_or_error( $request );
	}

	private function key_or_error( \WP_REST_Request $request ) {
		if ( $request->get_header( 'authorization' ) && 0 === stripos( (string) $request->get_header( 'authorization' ), 'bearer ' ) ) {
			return $this->auth->validate( $request );
		}
		return new \WP_Error( 'unauthorized', 'Sign in as a user who can edit pages, or use the plugin’s API key.', [ 'status' => 401 ] );
	}

	// -------------------------------------------------------------------------
	// Divi itself

	/** Divi's version, from the theme or the Divi Builder plugin. Null when Divi isn't active. */
	public static function version() : ?string {
		$theme = wp_get_theme();
		$parent = $theme->parent() ?: $theme;
		if ( in_array( strtolower( (string) $parent->get_template() ), [ 'divi', 'extra' ], true ) ) {
			return (string) $parent->get( 'Version' );
		}
		if ( defined( 'ET_BUILDER_PLUGIN_VERSION' ) ) {
			return (string) ET_BUILDER_PLUGIN_VERSION;
		}
		return null;
	}

	public function status() {
		$version = self::version();
		return new \WP_REST_Response( [
			'divi'          => null !== $version,
			'version'       => $version,
			'major'         => $version ? (int) $version : null,
			'theme_builder' => post_type_exists( 'et_template' ),
			'library'       => post_type_exists( 'et_pb_layout' ),
			'can_clear_css' => $this->can_clear_css(),
		], 200 );
	}

	private function can_clear_css() : bool {
		return ( class_exists( '\ET_Core_PageResource' ) && method_exists( '\ET_Core_PageResource', 'remove_static_resources' ) )
			|| function_exists( 'et_core_clear_wp_cache' );
	}

	/**
	 * Clears Divi's generated CSS so a change shows right away. Returns whether
	 * Divi offered a way to do it.
	 *
	 * @param int|string $post_id A post id, or 'all'.
	 */
	public static function clear_css( $post_id = 'all' ) : bool {
		$cleared = false;
		if ( class_exists( '\ET_Core_PageResource' ) && method_exists( '\ET_Core_PageResource', 'remove_static_resources' ) ) {
			\ET_Core_PageResource::remove_static_resources( $post_id, 'all' );
			$cleared = true;
		}
		if ( function_exists( 'et_core_clear_wp_cache' ) ) {
			et_core_clear_wp_cache( 'all' === $post_id ? '' : $post_id );
			$cleared = true;
		}
		if ( 'all' !== $post_id ) {
			clean_post_cache( (int) $post_id );
		}
		return $cleared;
	}

	public function clear_cache_route( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$post = isset( $body['post_id'] ) ? (int) $body['post_id'] : 0;
		if ( $post && ! current_user_can( 'edit_post', $post ) && is_user_logged_in() ) {
			return new \WP_Error( 'forbidden', 'This user can’t edit that item.', [ 'status' => 403 ] );
		}
		return new \WP_REST_Response( [ 'cleared' => self::clear_css( $post ?: 'all' ) ], 200 );
	}

	/**
	 * Turns the Divi builder on for a page or post whose content is already a
	 * Divi layout (e.g. a draft the control center made), so it opens in the
	 * builder and renders in Divi's full-width wrapper.
	 */
	public function enable_builder( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'not_found', 'No page or post with that id.', [ 'status' => 404 ] );
		}
		if ( null === self::version() ) {
			return new \WP_Error( 'no_divi', 'Divi isn’t active on this site.', [ 'status' => 409 ] );
		}
		$content = (string) $post->post_content;
		if ( false === strpos( $content, '[et_pb_section' ) && false === strpos( $content, '<!-- wp:divi/' ) ) {
			return new \WP_Error( 'not_divi_content', 'This item’s content isn’t a Divi layout, so the builder would start it empty.', [ 'status' => 409 ] );
		}

		update_post_meta( $id, '_et_pb_use_builder', 'on' );
		if ( '' === (string) get_post_meta( $id, '_et_pb_page_layout', true ) ) {
			update_post_meta( $id, '_et_pb_page_layout', 'et_no_sidebar' );
		}
		if ( '' === (string) get_post_meta( $id, '_et_pb_side_nav', true ) ) {
			update_post_meta( $id, '_et_pb_side_nav', 'off' );
		}
		$cleared = self::clear_css( $id );

		return new \WP_REST_Response( [
			'id'           => $id,
			'builder'      => 'on',
			'css_cleared'  => $cleared,
			'edit_link'    => get_edit_post_link( $id, 'raw' ),
			'builder_link' => add_query_arg( 'et_fb', '1', get_permalink( $id ) ),
		], 200 );
	}

	// -------------------------------------------------------------------------
	// Library and Theme Builder

	private static function terms( int $id, string $taxonomy ) : array {
		$terms = get_the_terms( $id, $taxonomy );
		return is_array( $terms ) ? array_values( wp_list_pluck( $terms, 'slug' ) ) : [];
	}

	private static function summary( \WP_Post $p ) : array {
		$types = self::terms( $p->ID, 'layout_type' );
		return [
			'id'        => $p->ID,
			'title'     => $p->post_title,
			'post_type' => $p->post_type,
			'kind'      => 'et_pb_layout' === $p->post_type ? ( $types[0] ?? 'layout' ) : str_replace( [ 'et_', '_layout' ], '', $p->post_type ),
			'global'    => in_array( 'global', self::terms( $p->ID, 'scope' ), true ),
			'status'    => $p->post_status,
			'modified'  => mysql_to_rfc3339( $p->post_modified_gmt ),
		];
	}

	public function library( \WP_REST_Request $request ) {
		if ( ! post_type_exists( 'et_pb_layout' ) ) {
			return new \WP_Error( 'no_divi', 'The Divi Library isn’t available on this site.', [ 'status' => 409 ] );
		}
		$args = [
			'post_type'      => 'et_pb_layout',
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		];
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( $type ) {
			$args['tax_query'] = [ [ 'taxonomy' => 'layout_type', 'field' => 'slug', 'terms' => $type ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$items = array_map( [ self::class, 'summary' ], get_posts( $args ) );
		return new \WP_REST_Response( [ 'items' => $items ], 200 );
	}

	public function theme_builder() {
		if ( ! post_type_exists( 'et_template' ) ) {
			return new \WP_Error( 'no_theme_builder', 'The Divi Theme Builder isn’t available on this site.', [ 'status' => 409 ] );
		}
		$layout = function ( int $template_id, string $area ) {
			$id = (int) get_post_meta( $template_id, "_et_{$area}_layout_id", true );
			if ( ! $id ) {
				return null;
			}
			$post = get_post( $id );
			return $post ? array_merge( self::summary( $post ), [ 'enabled' => '0' !== (string) get_post_meta( $template_id, "_et_{$area}_layout_enabled", true ) ] ) : null;
		};
		$templates = [];
		foreach ( get_posts( [ 'post_type' => 'et_template', 'post_status' => 'publish', 'posts_per_page' => 100 ] ) as $t ) {
			$templates[] = [
				'id'           => $t->ID,
				'title'        => $t->post_title,
				'default'      => '1' === (string) get_post_meta( $t->ID, '_et_default', true ),
				'enabled'      => '0' !== (string) get_post_meta( $t->ID, '_et_enabled', true ),
				'use_on'       => array_values( (array) get_post_meta( $t->ID, '_et_use_on' ) ),
				'exclude_from' => array_values( (array) get_post_meta( $t->ID, '_et_exclude_from' ) ),
				'header'       => $layout( $t->ID, 'header' ),
				'body'         => $layout( $t->ID, 'body' ),
				'footer'       => $layout( $t->ID, 'footer' ),
			];
		}
		return new \WP_REST_Response( [ 'templates' => $templates ], 200 );
	}

	private static function layout_or_error( int $id ) {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, self::LAYOUT_TYPES, true ) ) {
			return new \WP_Error( 'not_a_layout', 'No Divi Library item or Theme Builder layout with that id.', [ 'status' => 404 ] );
		}
		return $post;
	}

	public function get_layout( \WP_REST_Request $request ) {
		$post = self::layout_or_error( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return new \WP_REST_Response( array_merge( self::summary( $post ), [ 'content' => $post->post_content ] ), 200 );
	}

	/**
	 * Replaces a Library item's or Theme Builder layout's content. Refused if it
	 * changed since the caller read it. A global module or a header shows on
	 * many pages, so all of Divi's generated CSS is cleared.
	 */
	public function update_layout( \WP_REST_Request $request ) {
		$post = self::layout_or_error( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$body     = (array) $request->get_json_params();
		$content  = isset( $body['content'] ) ? (string) $body['content'] : '';
		$expected = isset( $body['expected_modified'] ) ? (string) $body['expected_modified'] : '';
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'missing_content', 'content is required.', [ 'status' => 400 ] );
		}
		if ( $expected && mysql_to_rfc3339( $post->post_modified_gmt ) !== $expected ) {
			return new \WP_Error( 'changed', 'This layout was edited in WordPress after the change was drafted.', [ 'status' => 409 ] );
		}
		$result = wp_update_post( [ 'ID' => $post->ID, 'post_content' => wp_slash( $content ) ], true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cleared = self::clear_css( 'all' );
		$updated = get_post( $post->ID );
		return new \WP_REST_Response( array_merge( self::summary( $updated ), [ 'css_cleared' => $cleared ] ), 200 );
	}
}
