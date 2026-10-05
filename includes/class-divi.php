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
 *   GET  /divi/identity                  The site's identity: title, tagline, logo, header phone and email, footer credits, accent color
 *   POST /divi/identity                  Change any of those ({ site_title, tagline, logo, phone, email, footer_credits, accent_color })
 *   POST /divi/validate                  Check Divi 5 block markup before it's saved ({ content, post_id? })
 *   GET  /divi/design                    The design system: global colors, fonts, variables and presets
 *   POST /divi/design                    Add or change global colors, fonts, variables and module presets ({ colors, fonts, variables, presets })
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
		register_rest_route( $ns, '/divi/identity', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'identity' ],
				'permission_callback' => [ $this, 'can_edit_identity' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_identity' ],
				'permission_callback' => [ $this, 'can_edit_identity' ],
			],
		] );
		register_rest_route( $ns, '/divi/validate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'validate' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( $ns, '/divi/design', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'design' ],
				'permission_callback' => [ $this, 'can_edit' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_design' ],
				'permission_callback' => [ $this, 'can_edit_identity' ],
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

	/** Theme options are an administrator's: a user who can edit them, or the plugin's API key. */
	public function can_edit_identity( \WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return current_user_can( 'edit_theme_options' )
				? true
				: new \WP_Error( 'forbidden', 'This user can’t change the site’s theme options.', [ 'status' => 403 ] );
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
	// Site identity: the Theme Options Divi's standard header and footer show

	/** Divi's theme option keys for each identity field. */
	const IDENTITY_OPTIONS = [
		'logo'           => 'divi_logo',
		'phone'          => 'phone_number',
		'email'          => 'header_email',
		'footer_credits' => 'custom_footer_credits',
		'accent_color'   => 'accent_color',
	];

	private static function theme_option( string $key ) : string {
		if ( function_exists( 'et_get_option' ) ) {
			return (string) et_get_option( $key, '' );
		}
		$options = (array) get_option( 'et_divi', [] );
		return isset( $options[ $key ] ) ? (string) $options[ $key ] : '';
	}

	private static function set_theme_option( string $key, $value ) : void {
		if ( function_exists( 'et_update_option' ) ) {
			et_update_option( $key, $value );
			return;
		}
		$options         = (array) get_option( 'et_divi', [] );
		$options[ $key ] = $value;
		update_option( 'et_divi', $options );
	}

	private static function identity_values() : array {
		$values = [
			// Stored HTML-escaped; sent as people typed them.
			'site_title' => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			'tagline'    => wp_specialchars_decode( (string) get_option( 'blogdescription' ), ENT_QUOTES ),
		];
		foreach ( self::IDENTITY_OPTIONS as $field => $key ) {
			$values[ $field ] = self::theme_option( $key );
		}
		$values['footer_credits_shown'] = 'on' !== self::theme_option( 'disable_custom_footer_credits' );
		return $values;
	}

	public function identity() {
		if ( null === self::version() ) {
			return new \WP_Error( 'no_divi', 'Divi isn’t active on this site.', [ 'status' => 409 ] );
		}
		return new \WP_REST_Response( self::identity_values(), 200 );
	}

	/** Changes the fields sent; leaves the rest. Clears Divi's CSS so a new accent color shows. */
	public function update_identity( \WP_REST_Request $request ) {
		if ( null === self::version() ) {
			return new \WP_Error( 'no_divi', 'Divi isn’t active on this site.', [ 'status' => 409 ] );
		}
		$body = (array) $request->get_json_params();

		if ( isset( $body['accent_color'] ) && null === sanitize_hex_color( (string) $body['accent_color'] ) ) {
			return new \WP_Error( 'bad_color', 'The accent color must be a hex color like #1f6feb.', [ 'status' => 400 ] );
		}
		if ( isset( $body['email'] ) && '' !== (string) $body['email'] && ! is_email( (string) $body['email'] ) ) {
			return new \WP_Error( 'bad_email', 'That isn’t an email address.', [ 'status' => 400 ] );
		}
		if ( isset( $body['logo'] ) && '' !== (string) $body['logo'] && ! wp_http_validate_url( (string) $body['logo'] ) ) {
			return new \WP_Error( 'bad_logo', 'The logo must be an image address.', [ 'status' => 400 ] );
		}

		if ( isset( $body['site_title'] ) ) {
			update_option( 'blogname', sanitize_text_field( (string) $body['site_title'] ) );
		}
		if ( isset( $body['tagline'] ) ) {
			update_option( 'blogdescription', sanitize_text_field( (string) $body['tagline'] ) );
		}
		if ( isset( $body['logo'] ) ) {
			self::set_theme_option( 'divi_logo', esc_url_raw( (string) $body['logo'] ) );
		}
		if ( isset( $body['phone'] ) ) {
			self::set_theme_option( 'phone_number', sanitize_text_field( (string) $body['phone'] ) );
		}
		if ( isset( $body['email'] ) ) {
			self::set_theme_option( 'header_email', sanitize_email( (string) $body['email'] ) );
		}
		if ( isset( $body['footer_credits'] ) ) {
			self::set_theme_option( 'custom_footer_credits', wp_kses_post( (string) $body['footer_credits'] ) );
			self::set_theme_option( 'disable_custom_footer_credits', 'false' );
		}
		if ( isset( $body['accent_color'] ) ) {
			self::set_theme_option( 'accent_color', sanitize_hex_color( (string) $body['accent_color'] ) );
		}
		self::clear_css( 'all' );

		return new \WP_REST_Response( self::identity_values(), 200 );
	}

	// -------------------------------------------------------------------------
	// Checking Divi 5 markup before it's saved
	//
	// Divi stores almost any block markup without complaint, then renders some
	// of it wrong or not at all. This catches that before a person approves it:
	// broken block comments and settings, modules this site doesn't have,
	// global colors and presets that don't exist, settings in places Divi never
	// reads, and anything that fails to render. The module rules follow the
	// DiviOps Agent plugin's validator (GPL-2.0-or-later, github.com/oaris-dev/diviops).

	/** WordPress's block comment pattern, as in WP_Block_Parser. */
	const BLOCK_TOKEN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)}\s+)?(?P<void>\/)?-->/s';

	/** At most this many problems are listed; the rest are counted. */
	const MAX_PROBLEMS = 40;

	public function validate( \WP_REST_Request $request ) {
		$body    = (array) $request->get_json_params();
		$content = isset( $body['content'] ) ? (string) $body['content'] : '';
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'missing_content', 'content is required.', [ 'status' => 400 ] );
		}
		$post_id = isset( $body['post_id'] ) ? (int) $body['post_id'] : 0;
		if ( $post_id && is_user_logged_in() && ! current_user_can( 'edit_post', $post_id ) ) {
			$post_id = 0;
		}
		return new \WP_REST_Response( self::check_markup( $content, $post_id ), 200 );
	}

	private static function note( array &$report, string $level, string $code, string $message, string $block = '', int $index = 0 ) : void {
		if ( count( $report['errors'] ) + count( $report['warnings'] ) >= self::MAX_PROBLEMS ) {
			$report['more']++;
			return;
		}
		$item = [ 'code' => $code, 'message' => $message ];
		if ( '' !== $block ) {
			$item['block'] = $block;
		}
		if ( $index ) {
			$item['number'] = $index;
		}
		$report[ $level ][] = $item;
	}

	/** A value deep in a block's settings, or null. */
	private static function dig( $value, array $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/** Whether this site has Divi 5's modules (registered as divi/* blocks). */
	private static function divi5_blocks() : array {
		$names = [];
		foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
			if ( 0 === strpos( $name, 'divi/' ) ) {
				$names[ $name ] = true;
			}
		}
		return $names;
	}

	public static function check_markup( string $content, int $post_id = 0 ) : array {
		$report = [ 'errors' => [], 'warnings' => [], 'more' => 0 ];
		$known  = self::divi5_blocks();

		// 1. The block comments themselves: settings that are valid JSON, and
		//    every module closed in the order it was opened.
		preg_match_all( self::BLOCK_TOKEN, $content, $tokens, PREG_SET_ORDER );
		$stack  = [];
		$number = 0;
		foreach ( $tokens as $t ) {
			$name   = ( ( $t['namespace'] ?? '' ) ?: 'core/' ) . $t['name'];
			$closer = '' !== ( $t['closer'] ?? '' );
			if ( $closer ) {
				$open = array_pop( $stack );
				if ( null === $open ) {
					self::note( $report, 'errors', 'unopened', "{$name} is closed but was never opened.", $name );
				} elseif ( $open !== $name ) {
					self::note( $report, 'errors', 'mismatched', "{$open} is closed as {$name}: the modules are closed in the wrong order, or one is missing its closing comment.", $open );
					$stack = [];
					break;
				}
				continue;
			}
			$number++;
			$attrs = trim( (string) ( $t['attrs'] ?? '' ) );
			if ( '' !== $attrs && null === json_decode( $attrs, true ) ) {
				self::note( $report, 'errors', 'bad_settings', "The settings of {$name} (block {$number}) aren't valid JSON: " . json_last_error_msg() . '.', $name, $number );
			}
			if ( '' === ( $t['void'] ?? '' ) ) {
				$stack[] = $name;
			}
		}
		foreach ( array_reverse( $stack ) as $open ) {
			self::note( $report, 'errors', 'unclosed', "{$open} is never closed.", $open );
		}
		if ( preg_match( '/(?<!\\\\)u00(22|3c|3e|26)/i', $content ) ) {
			self::note( $report, 'errors', 'lost_escapes', 'The markup has \\u0022-style escapes that lost their backslash (e.g. "u0022"), so quotes and tags would show as text. Write them as \\u0022, or as plain characters.' );
		}

		// 2. Each module: one this site has, and settings where Divi reads them.
		$blocks  = parse_blocks( $content );
		$counts  = [];
		$design  = self::design_values( false );
		$index   = 0;
		$registry = \WP_Block_Type_Registry::get_instance();
		$walk     = function ( array $blocks, string $parent ) use ( &$walk, &$report, &$counts, &$index, $known, $design, $registry ) {
			foreach ( $blocks as $block ) {
				$name = $block['blockName'] ?? null;
				if ( null === $name ) {
					if ( '' !== trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) ) ) {
						self::note( $report, 'warnings', 'loose_content', 'There is text or HTML outside any module. Divi\'s builder won\'t show it; put it in a Text module.' );
					}
					continue;
				}
				$index++;
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
				// Modules from Divi or an add-on (e.g. Divi Supreme) that this site doesn't have render as nothing.
				if ( $known && 0 !== strpos( $name, 'core/' ) && ! $registry->is_registered( $name ) ) {
					self::note( $report, 'errors', 'unknown_module', "{$name} isn't a module on this site (is the plugin that provides it active?).", $name, $index );
				}
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
				self::check_place( $name, $parent, $index, $report );
				self::check_module( $name, $attrs, $index, $report, $design );
				if ( 'divi/group' === $name ) {
					self::check_group( (array) ( $block['innerBlocks'] ?? [] ), $index, $report );
				}
				self::check_hardcoded_colors( $name, $attrs, $index, $report, $design );
				$walk( (array) ( $block['innerBlocks'] ?? [] ), $name );
			}
		};
		$walk( $blocks, '' );
		if ( ! $known && preg_grep( '/^divi\//', array_keys( $counts ) ) ) {
			self::note( $report, 'warnings', 'no_divi5', 'Divi 5 isn\'t active on this site, so only the markup\'s shape was checked.' );
		}

		// 3. Global colors and variables it names that the site doesn't have.
		if ( $design['divi5'] ) {
			$colors    = array_flip( array_column( $design['colors'], 'id' ) );
			$variables = array_flip( array_column( $design['variables'], 'id' ) );
			preg_match_all( '/\b(gcid|gvid)-[0-9a-z_-]+/', $content, $refs );
			foreach ( array_unique( $refs[0] ) as $ref ) {
				if ( 0 === strpos( $ref, 'gcid-' ) && ! isset( $colors[ $ref ] ) ) {
					self::note( $report, 'errors', 'unknown_color', "It uses the global color {$ref}, which this site doesn't have, so it would show no color. Use one of the site's global colors, or a hex value." );
				} elseif ( 0 === strpos( $ref, 'gvid-' ) && ! isset( $variables[ $ref ] ) ) {
					self::note( $report, 'warnings', 'unknown_variable', "It uses the global variable {$ref}, which isn't in this site's Variable Manager." );
				}
			}
		}

		// 4. Render it, as the page would be, when nothing above is broken.
		$rendered = false;
		if ( ! $report['errors'] && $known ) {
			$notices  = [];
			$previous = $GLOBALS['post'] ?? null;
			$post     = $post_id ? get_post( $post_id ) : null;
			if ( $post ) {
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				setup_postdata( $post );
			}
			set_error_handler( function ( $no, $message ) use ( &$notices ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$notices[] = $message;
				return true;
			}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE );
			ob_start();
			try {
				$html     = do_blocks( $content );
				$rendered = true;
				if ( '' === trim( wp_strip_all_tags( $html ) ) && false === stripos( $html, '<img' ) ) {
					self::note( $report, 'warnings', 'renders_empty', 'It renders with no text or images.' );
				}
			} catch ( \Throwable $e ) {
				self::note( $report, 'errors', 'render_failed', 'Divi couldn\'t render it: ' . mb_substr( $e->getMessage(), 0, 400 ) );
			} finally {
				ob_end_clean();
				restore_error_handler();
				$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				if ( $post ) {
					wp_reset_postdata();
				}
			}
			foreach ( array_slice( array_unique( $notices ), 0, 5 ) as $message ) {
				self::note( $report, 'warnings', 'render_notice', 'While rendering: ' . mb_substr( (string) $message, 0, 300 ) );
			}
		}

		ksort( $counts );
		return [
			'valid'    => ! $report['errors'],
			'errors'   => $report['errors'],
			'warnings' => $report['warnings'],
			'more'     => $report['more'],
			'modules'  => $counts,
			'rendered' => $rendered,
		];
	}

	/**
	 * Where Divi 5 modules can go: the containers that hold only their own
	 * items, and the items that only work inside them. From real Divi 5
	 * exports (after the JHMG AI Editor for Divi 5's validator, GPL-2.0-or-later).
	 */
	const ONLY_CHILDREN = [
		'divi/row'                  => [ 'divi/column' ],
		'divi/row-inner'            => [ 'divi/column-inner' ],
		'divi/accordion'            => [ 'divi/accordion-item', 'divi/code' ],
		'divi/contact-form'         => [ 'divi/contact-field' ],
		'divi/counters'             => [ 'divi/counter' ],
		'divi/icon-list'            => [ 'divi/icon-list-item' ],
		'divi/pricing-tables'       => [ 'divi/pricing-table' ],
		'divi/slider'               => [ 'divi/slide' ],
		'divi/fullwidth-slider'     => [ 'divi/slide' ],
		'divi/tabs'                 => [ 'divi/tab' ],
		'divi/social-media-follow'  => [ 'divi/social-media-follow-network' ],
		'divi/timeline'             => [ 'divi/timeline-item' ],
		'divi/group-carousel'       => [ 'divi/group' ],
		'divi/video-slider'         => [ 'divi/video-slider-item' ],
		'divi/post-filter'          => [ 'divi/post-filter-item' ],
		'divi/fullwidth-map'        => [ 'divi/map-pin' ],
		'divi/placeholder'          => [ 'divi/section', 'divi/global-layout' ],
	];

	const ONLY_PARENTS = [
		'divi/section'                     => [ 'divi/placeholder', '' ],
		'divi/column'                      => [ 'divi/row', 'divi/section' ],
		'divi/column-inner'                => [ 'divi/row-inner' ],
		'divi/accordion-item'              => [ 'divi/accordion' ],
		'divi/contact-field'               => [ 'divi/contact-form' ],
		'divi/counter'                     => [ 'divi/counters' ],
		'divi/icon-list-item'              => [ 'divi/icon-list' ],
		'divi/pricing-table'               => [ 'divi/pricing-tables' ],
		'divi/slide'                       => [ 'divi/slider', 'divi/fullwidth-slider' ],
		'divi/tab'                         => [ 'divi/tabs' ],
		'divi/social-media-follow-network' => [ 'divi/social-media-follow' ],
		'divi/timeline-item'               => [ 'divi/timeline' ],
		'divi/video-slider-item'           => [ 'divi/video-slider' ],
		'divi/post-filter-item'            => [ 'divi/post-filter' ],
		'divi/map-pin'                     => [ 'divi/fullwidth-map' ],
	];

	private static function check_place( string $name, string $parent, int $i, array &$report ) : void {
		$where = '' === $parent ? 'at the top of the page' : "directly in {$parent}";
		if ( isset( self::ONLY_CHILDREN[ $parent ] ) && ! in_array( $name, self::ONLY_CHILDREN[ $parent ], true ) ) {
			self::note( $report, 'errors', 'misplaced', "{$name} can't go {$where}; {$parent} holds only " . implode( ' or ', self::ONLY_CHILDREN[ $parent ] ) . '.', $name, $i );
			return;
		}
		if ( isset( self::ONLY_PARENTS[ $name ] ) && ! in_array( $parent, self::ONLY_PARENTS[ $name ], true ) ) {
			$allowed = array_filter( self::ONLY_PARENTS[ $name ] );
			self::note( $report, 'errors', 'misplaced', "{$name} can't go {$where}; it belongs in " . implode( ' or ', $allowed ) . '.', $name, $i );
			return;
		}
		if ( 'divi/section' === $parent && 0 !== strpos( $name, 'divi/row' ) && 'divi/column' !== $name && 'divi/global-layout' !== $name && 0 !== strpos( $name, 'divi/fullwidth-' ) ) {
			self::note( $report, 'errors', 'misplaced', "{$name} can't go directly in a section; put it in a row's column.", $name, $i );
		}
	}

	/** A Group of an icon or image, a heading and text (and maybe a button) is what a Blurb is for. */
	private static function check_group( array $children, int $i, array &$report ) : void {
		$names = [];
		foreach ( $children as $child ) {
			if ( ! empty( $child['blockName'] ) ) {
				$names[] = $child['blockName'];
			}
		}
		$visual  = array_intersect( $names, [ 'divi/icon', 'divi/image' ] );
		$rest    = array_diff( $names, [ 'divi/icon', 'divi/image', 'divi/heading', 'divi/text', 'divi/button' ] );
		if ( 1 === count( $visual ) && in_array( 'divi/heading', $names, true ) && in_array( 'divi/text', $names, true ) && ! $rest && count( $names ) <= 4 ) {
			self::note( $report, 'warnings', 'group_could_be_blurb', 'This Group is an icon or image with a heading and text: use a Blurb module (with its own button or link) so it\'s one module to edit.', 'divi/group', $i );
		}
	}

	/** Hex colors typed into a module's design settings that the site already has as a global color. */
	private static function check_hardcoded_colors( string $name, array $attrs, int $i, array &$report, array $design ) : void {
		if ( ! $design['divi5'] || 0 !== strpos( $name, 'divi/' ) ) {
			return;
		}
		$globals = [];
		foreach ( $design['colors'] as $c ) {
			$hex = self::normal_hex( (string) $c['color'] );
			if ( $hex && ! isset( $globals[ $hex ] ) ) {
				$globals[ $hex ] = $c;
			}
		}
		if ( ! $globals ) {
			return;
		}
		$found = [];
		$scan  = function ( $value, string $key ) use ( &$scan, &$found, $globals ) {
			if ( 'innerContent' === $key ) {
				return; // The words, not the design.
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $k => $v ) {
					$scan( $v, (string) $k );
				}
			} elseif ( is_string( $value ) ) {
				$hex = self::normal_hex( $value );
				if ( $hex && isset( $globals[ $hex ] ) ) {
					$found[ $hex ] = $globals[ $hex ];
				}
			}
		};
		$scan( $attrs, '' );
		foreach ( $found as $hex => $c ) {
			self::note( $report, 'warnings', 'hardcoded_color', "It types {$hex}, which is the site's global color \"{$c['label']}\" ({$c['id']}): use the global color instead, so it changes with the site.", $name, $i );
		}
	}

	/** #abc, #aabbcc or #aabbccff as lowercase #aabbcc(ff); null for anything else. */
	private static function normal_hex( string $value ) : ?string {
		$value = strtolower( trim( $value ) );
		if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $m ) ) {
			return null;
		}
		$hex = $m[1];
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 8 === strlen( $hex ) && 'ff' === substr( $hex, 6 ) ) {
			$hex = substr( $hex, 0, 6 );
		}
		return '#' . $hex;
	}

	/** Settings Divi stores but never reads, or reads differently, per module. */
	private static function check_module( string $name, array $attrs, int $i, array &$report, array $design ) : void {
		if ( 0 !== strpos( $name, 'divi/' ) ) {
			return;
		}
		$e = function ( string $code, string $message ) use ( &$report, $name, $i ) {
			self::note( $report, 'errors', $code, $message, $name, $i );
		};
		$w = function ( string $code, string $message ) use ( &$report, $name, $i ) {
			self::note( $report, 'warnings', $code, $message, $name, $i );
		};

		if ( 'divi/placeholder' !== $name && ! isset( $attrs['builderVersion'] ) ) {
			$w( 'no_builder_version', 'It has no builderVersion; copy the one the page\'s other modules use.' );
		}

		switch ( $name ) {
			case 'divi/text':
				if ( ! self::dig( $attrs, [ 'content', 'innerContent', 'desktop', 'value' ] ) ) {
					$e( 'empty_text', 'The Text module has no content.innerContent.desktop.value, so it renders as nothing.' );
				}
				break;
			case 'divi/heading':
				if ( null === self::dig( $attrs, [ 'title', 'decoration', 'font', 'font', 'desktop', 'value', 'headingLevel' ] ) ) {
					$w( 'no_heading_level', 'The Heading has no headingLevel (title.decoration.font.font.desktop.value.headingLevel), so it\'s an h2. Set h1 to h6 to match the outline.' );
				}
				break;
			case 'divi/button':
				$text = self::dig( $attrs, [ 'button', 'innerContent', 'desktop', 'value' ] );
				if ( is_string( $text ) ) {
					$e( 'button_text_string', 'button.innerContent.desktop.value must be an object like {"text":"Call now","linkUrl":"..."}; a plain string renders an empty button.' );
				}
				if ( null === $text && null !== self::dig( $attrs, [ 'content', 'innerContent', 'desktop', 'value' ] ) ) {
					$e( 'button_text_wrong_place', 'The Button\'s text is in content.innerContent, but Divi reads button.innerContent, so it would say "Click Me" and link nowhere.' );
				}
				if ( null !== self::dig( $attrs, [ 'button', 'decoration', 'spacing' ] ) ) {
					$w( 'button_padding_wrong_place', 'Button padding belongs in module.decoration.spacing, not button.decoration.spacing.' );
				}
				break;
			case 'divi/blurb':
				if ( is_string( self::dig( $attrs, [ 'title', 'innerContent', 'desktop', 'value' ] ) ) ) {
					$e( 'blurb_title_string', 'The Blurb\'s title.innerContent.desktop.value must be an object like {"text":"..."}; a plain string renders an empty title.' );
				}
				$icon = self::dig( $attrs, [ 'imageIcon', 'innerContent', 'desktop', 'value' ] );
				if ( is_array( $icon ) && isset( $icon['icon'] ) && 'on' !== ( $icon['useIcon'] ?? null ) ) {
					$e( 'blurb_icon_off', 'The Blurb has an icon but useIcon isn\'t "on", so the icon won\'t show.' );
				}
				break;
			case 'divi/contact-field':
				foreach ( (array) self::dig( $attrs, [ 'fieldItem', 'innerContent' ] ) as $breakpoint => $states ) {
					foreach ( is_array( $states ) ? $states : [] as $state => $value ) {
						if ( null !== $value && ! is_string( $value ) ) {
							$e( 'field_label_not_text', "fieldItem.innerContent.{$breakpoint}.{$state} must be the label text; anything else stops the whole page rendering. Put the field's id, type and required in fieldItem.advanced.<setting>.desktop.value." );
						}
					}
				}
				break;
			case 'divi/code':
			case 'divi/fullwidth-code':
				$w( 'code_module', 'A Code module can\'t be edited in the builder. Use Divi\'s own modules where you can.' );
				break;
		}

		if ( null !== self::dig( $attrs, [ 'content', 'decoration', 'bodyFont', 'bodyFont' ] ) ) {
			$e( 'body_font_wrong_place', 'content.decoration.bodyFont.bodyFont is never read, so its fonts and colors are lost. Use content.decoration.bodyFont.body.font.' );
		}
		if ( ! in_array( $name, [ 'divi/column', 'divi/column-inner' ], true ) && null !== self::dig( $attrs, [ 'module', 'decoration', 'layout', 'desktop', 'value', 'flexType' ] ) ) {
			$w( 'flex_type_ignored', 'flexType in module.decoration.layout only sizes columns. For other modules use module.decoration.sizing.desktop.value.width.' );
		}
		foreach ( [ 'module', 'button' ] as $part ) {
			$gradient = self::dig( $attrs, [ $part, 'decoration', 'background', 'desktop', 'value', 'gradient' ] );
			if ( is_array( $gradient ) && 'on' !== ( $gradient['enabled'] ?? null ) ) {
				$w( 'gradient_off', "The gradient in {$part}.decoration.background has no enabled:\"on\", so it won't show." );
			}
		}
		foreach ( [ 'module', 'button', 'icon' ] as $part ) {
			foreach ( [ 'background', 'border', 'boxShadow' ] as $prop ) {
				$value = self::dig( $attrs, [ $part, 'decoration', $prop ] );
				if ( is_array( $value ) && isset( $value['hover'] ) && ! isset( $value['desktop']['hover'] ) ) {
					$w( 'hover_wrong_place', "The hover style in {$part}.decoration.{$prop}.hover is ignored; put it in {$part}.decoration.{$prop}.desktop.hover." );
				}
			}
		}

		// Presets it names that the site doesn't have: their styling would be missing.
		if ( $design['divi5'] ) {
			$ids = [];
			foreach ( $design['presets'] as $preset ) {
				$ids[ $preset['id'] ] = true;
			}
			$module_presets = $attrs['modulePreset'] ?? [];
			foreach ( (array) $module_presets as $id ) {
				if ( is_string( $id ) && '' !== $id && 'default' !== $id && ! isset( $ids[ $id ] ) ) {
					$e( 'unknown_preset', "It uses the preset {$id}, which this site doesn't have." );
				}
			}
			foreach ( (array) ( $attrs['groupPreset'] ?? [] ) as $group ) {
				foreach ( (array) ( is_array( $group ) ? ( $group['presetId'] ?? [] ) : [] ) as $id ) {
					if ( is_string( $id ) && '' !== $id && 'default' !== $id && ! isset( $ids[ $id ] ) ) {
						$e( 'unknown_preset', "It uses the option group preset {$id}, which this site doesn't have." );
					}
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// The design system: global colors, fonts, variables and presets
	//
	// Divi 5 keeps global colors in the et_global_data theme option, the
	// colors and fonts bound to Theme Options (primary color, heading font...)
	// in their own theme options, other global variables in
	// et_divi_global_variables, and presets in et_divi_builder_global_presets_d5.

	const PRESETS_OPTION   = 'et_divi_builder_global_presets_d5';
	const VARIABLES_OPTION = 'et_divi_global_variables';

	private static function as_array( $value ) : array {
		$value = maybe_unserialize( $value );
		if ( is_object( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : [];
	}

	private static function raw_theme_option( string $key ) {
		if ( function_exists( 'et_get_option' ) ) {
			return et_get_option( $key, '' );
		}
		$options = (array) get_option( 'et_divi', [] );
		return $options[ $key ] ?? '';
	}

	/** The global colors bound to Theme Options: id => [ label, option, default ]. */
	private static function theme_colors() : array {
		$class = '\ET\Builder\Packages\GlobalData\GlobalData';
		if ( ! class_exists( $class ) || ! property_exists( $class, 'customizer_colors' ) ) {
			return [];
		}
		$colors = [];
		foreach ( (array) $class::$customizer_colors as $id => $meta ) {
			if ( is_string( $id ) && is_array( $meta ) ) {
				$colors[ $id ] = [
					'label'   => (string) ( $meta['label'] ?? $id ),
					'option'  => (string) ( $meta['option_name'] ?? '' ),
					'default' => (string) ( $meta['default'] ?? '' ),
				];
			}
		}
		return $colors;
	}

	private static function preset_registry() : array {
		$registry = self::as_array( get_option( self::PRESETS_OPTION, [] ) );
		return $registry ?: self::as_array( self::raw_theme_option( 'builder_global_presets_d5' ) );
	}

	private static function design_values( bool $with_attrs = true ) : array {
		$colors = [];
		foreach ( self::theme_colors() as $id => $meta ) {
			$value    = '' !== $meta['option'] ? (string) self::raw_theme_option( $meta['option'] ) : '';
			$colors[] = [ 'id' => $id, 'label' => $meta['label'], 'color' => '' !== $value ? $value : $meta['default'], 'theme_option' => true ];
		}
		$global = self::as_array( self::raw_theme_option( 'et_global_data' ) );
		foreach ( self::as_array( $global['global_colors'] ?? [] ) as $id => $c ) {
			if ( is_array( $c ) && 'archived' !== ( $c['status'] ?? '' ) ) {
				$colors[] = [ 'id' => (string) $id, 'label' => (string) ( $c['label'] ?? '' ), 'color' => (string) ( $c['color'] ?? '' ) ];
			}
		}

		$variables = [];
		foreach ( self::as_array( get_option( self::VARIABLES_OPTION, [] ) ) as $type => $items ) {
			foreach ( is_array( $items ) ? $items : [] as $id => $v ) {
				if ( is_array( $v ) ) {
					$variables[] = [ 'id' => (string) $id, 'type' => (string) $type, 'label' => (string) ( $v['label'] ?? $id ), 'value' => $v['value'] ?? '' ];
				}
			}
		}

		$presets = [];
		foreach ( [ 'module', 'group' ] as $kind ) {
			foreach ( self::as_array( self::preset_registry()[ $kind ] ?? [] ) as $key => $bucket ) {
				$bucket = self::as_array( $bucket );
				foreach ( self::as_array( $bucket['items'] ?? [] ) as $id => $p ) {
					$p    = self::as_array( $p );
					$item = [
						'id'      => (string) $id,
						'kind'    => $kind,
						( 'module' === $kind ? 'module' : 'group' ) => (string) $key,
						'name'    => (string) ( $p['name'] ?? '' ),
						'default' => (string) ( $bucket['default'] ?? '' ) === (string) $id,
					];
					if ( 'group' === $kind ) {
						$item['group_id'] = (string) ( $p['groupId'] ?? '' );
					}
					if ( $with_attrs ) {
						$item['attrs'] = $p['attrs'] ?? (object) [];
					}
					$presets[] = $item;
				}
			}
		}

		return [
			'divi5'     => (bool) self::divi5_blocks(),
			'colors'    => $colors,
			'fonts'     => [
				'heading' => (string) self::raw_theme_option( 'heading_font' ),
				'body'    => (string) self::raw_theme_option( 'body_font' ),
			],
			'variables' => $variables,
			'presets'   => $presets,
		];
	}

	public function design() {
		if ( null === self::version() ) {
			return new \WP_Error( 'no_divi', 'Divi isn’t active on this site.', [ 'status' => 409 ] );
		}
		return new \WP_REST_Response( self::design_values(), 200 );
	}

	private static function valid_color( $color ) : bool {
		return is_string( $color ) && (
			1 === preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color )
			|| 1 === preg_match( '/^(rgba?|hsla?)\(\s*[0-9.,%\s\/]+\)$/i', $color )
		);
	}

	/**
	 * Adds or changes global colors ({ id?, label, color }; an id of a color
	 * bound to Theme Options, like gcid-primary-color, changes that option),
	 * the heading and body fonts ({ heading, body }), existing global
	 * variables' values ({ id, value }), and module presets
	 * ({ module, name, attrs, id?, default? }; a preset with that id, or with
	 * no id and the same name on that module, is updated; a new id is used for
	 * a new preset). Everything is checked before anything is saved.
	 */
	public function update_design( \WP_REST_Request $request ) {
		if ( null === self::version() || ! self::divi5_blocks() ) {
			return new \WP_Error( 'no_divi5', 'Divi 5 isn’t active on this site.', [ 'status' => 409 ] );
		}
		$body    = (array) $request->get_json_params();
		$bad     = function ( string $message ) {
			return new \WP_Error( 'invalid_design', $message, [ 'status' => 400 ] );
		};
		$now_iso = gmdate( 'Y-m-d\TH:i:s.000\Z' );
		$now_ms  = (int) round( microtime( true ) * 1000 );
		$made    = [];

		// Colors.
		$theme_colors  = self::theme_colors();
		$theme_changes = [];
		$global        = self::as_array( self::raw_theme_option( 'et_global_data' ) );
		$palette       = self::as_array( $global['global_colors'] ?? [] );
		$palette_dirty = false;
		foreach ( (array) ( $body['colors'] ?? [] ) as $n => $c ) {
			$c     = (array) $c;
			$color = trim( (string) ( $c['color'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $c['label'] ?? '' ) );
			$id    = sanitize_text_field( (string) ( $c['id'] ?? '' ) );
			if ( ! self::valid_color( $color ) ) {
				return $bad( "colors[{$n}]: the color must be a hex value like #1f6feb, or rgba()." );
			}
			if ( '' !== $id && isset( $theme_colors[ $id ] ) ) {
				if ( '' === $theme_colors[ $id ]['option'] ) {
					return $bad( "colors[{$n}]: {$id} can only be changed in Theme Options." );
				}
				$theme_changes[ $theme_colors[ $id ]['option'] ] = $color;
				$made[] = [ 'kind' => 'color', 'id' => $id, 'label' => $theme_colors[ $id ]['label'] ];
				continue;
			}
			if ( '' === $id ) {
				if ( '' === $label ) {
					return $bad( "colors[{$n}]: a new color needs a label." );
				}
				foreach ( $palette as $existing_id => $existing ) {
					if ( is_array( $existing ) && ( $existing['label'] ?? '' ) === $label ) {
						$id = (string) $existing_id;
					}
				}
				if ( '' === $id ) {
					$base = 'gcid-' . substr( trim( preg_replace( '/[^0-9a-z-]+/', '-', strtolower( remove_accents( $label ) ) ), '-' ) ?: 'color', 0, 60 );
					$id   = $base;
					for ( $k = 2; isset( $palette[ $id ] ) || isset( $theme_colors[ $id ] ); $k++ ) {
						$id = "{$base}-{$k}";
					}
				}
			} elseif ( ! preg_match( '/^gcid-[0-9a-z-]{1,80}$/', $id ) ) {
				return $bad( "colors[{$n}]: a global color's id looks like gcid-brand-navy." );
			}
			$old            = self::as_array( $palette[ $id ] ?? [] );
			$palette[ $id ] = [
				'color'       => $color,
				'folder'      => (string) ( $old['folder'] ?? '' ),
				'label'       => '' !== $label ? $label : (string) ( $old['label'] ?? $id ),
				'lastUpdated' => $now_iso,
				'status'      => 'active',
				'usedInPosts' => is_array( $old['usedInPosts'] ?? null ) ? $old['usedInPosts'] : [],
			];
			$palette_dirty  = true;
			$made[]         = [ 'kind' => 'color', 'id' => $id, 'label' => $palette[ $id ]['label'] ];
		}

		// Fonts.
		$fonts = [];
		foreach ( [ 'heading', 'body' ] as $which ) {
			if ( isset( $body['fonts'][ $which ] ) ) {
				$font = sanitize_text_field( (string) $body['fonts'][ $which ] );
				if ( '' === $font || strlen( $font ) > 80 ) {
					return $bad( "fonts.{$which}: give a font family's name, like Poppins." );
				}
				$fonts[ "{$which}_font" ] = $font;
			}
		}

		// Global variables: the values of ones the site has (spacing, sizes, fonts...).
		$variables       = self::as_array( get_option( self::VARIABLES_OPTION, [] ) );
		$variables_dirty = false;
		foreach ( (array) ( $body['variables'] ?? [] ) as $n => $v ) {
			$v     = (array) $v;
			$id    = sanitize_text_field( (string) ( $v['id'] ?? '' ) );
			$value = trim( (string) ( $v['value'] ?? '' ) );
			$type  = null;
			foreach ( $variables as $t => $items ) {
				if ( is_array( $items ) && is_array( $items[ $id ] ?? null ) ) {
					$type = (string) $t;
				}
			}
			if ( null === $type ) {
				return $bad( "variables[{$n}]: the site has no global variable {$id}; only existing ones can be changed here." );
			}
			if ( 'gradients' === $type ) {
				return $bad( "variables[{$n}]: {$id} is a gradient; change it in Divi's Variable Manager." );
			}
			if ( '' === $value || strlen( $value ) > 500 ) {
				return $bad( "variables[{$n}]: give {$id} a value." );
			}
			$value = in_array( $type, [ 'images', 'links' ], true ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
			$variables[ $type ][ $id ]['value']       = $value;
			$variables[ $type ][ $id ]['lastUpdated'] = $now_iso;
			$variables_dirty = true;
			$made[]          = [ 'kind' => 'variable', 'id' => $id, 'label' => (string) ( $variables[ $type ][ $id ]['label'] ?? $id ) ];
		}

		// Module presets.
		$registry      = self::preset_registry();
		$presets_dirty = false;
		$modules       = self::divi5_blocks();
		foreach ( (array) ( $body['presets'] ?? [] ) as $n => $p ) {
			$p      = (array) $p;
			$module = sanitize_text_field( (string) ( $p['module'] ?? '' ) );
			$name   = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			$attrs  = $p['attrs'] ?? null;
			if ( ! isset( $modules[ $module ] ) ) {
				return $bad( "presets[{$n}]: {$module} isn't a Divi module on this site." );
			}
			if ( '' === $name || ! is_array( $attrs ) || ! $attrs ) {
				return $bad( "presets[{$n}]: a preset needs a name and its attrs (the module's settings, as in its block)." );
			}
			$bucket          = self::as_array( $registry['module'][ $module ] ?? [] );
			$bucket['items'] = self::as_array( $bucket['items'] ?? [] );
			$id              = sanitize_text_field( (string) ( $p['id'] ?? '' ) );
			// A new preset can come with its id, so pages can use it before it's saved.
			if ( '' !== $id && ! isset( $bucket['items'][ $id ] ) && ! preg_match( '/^[0-9a-z][0-9a-z-]{7,63}$/', $id ) ) {
				return $bad( "presets[{$n}]: a preset's id is a lowercase UUID." );
			}
			if ( '' === $id ) {
				foreach ( $bucket['items'] as $existing_id => $existing ) {
					if ( ( self::as_array( $existing )['name'] ?? '' ) === $name ) {
						$id = (string) $existing_id;
					}
				}
			}
			$old    = '' !== $id ? self::as_array( $bucket['items'][ $id ] ) : [];
			$id     = '' !== $id ? $id : wp_generate_uuid4();
			$preset = array_merge( $old, [
				'id'          => $id,
				'name'        => $name,
				'moduleName'  => $module,
				'type'        => 'module',
				// Divi reads all three; keeping them equal keeps its two CSS passes in step.
				'attrs'       => $attrs,
				'styleAttrs'  => $attrs,
				'renderAttrs' => $attrs,
				'created'     => $old['created'] ?? $now_ms,
				'updated'     => $now_ms,
				'version'     => (string) ( defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : self::version() ),
			] );
			$bucket['items'][ $id ] = $preset;
			$bucket['default']      = ! empty( $p['default'] ) ? $id : (string) ( $bucket['default'] ?? '' );
			$registry['module']             = self::as_array( $registry['module'] ?? [] );
			$registry['module'][ $module ]  = $bucket;
			$registry['group']              = $registry['group'] ?? [];
			$presets_dirty                  = true;
			$made[] = [ 'kind' => 'preset', 'id' => $id, 'module' => $module, 'name' => $name, 'default' => $bucket['default'] === $id ];
		}

		if ( ! $made && ! $fonts ) {
			return $bad( 'Nothing to change: send colors, fonts, variables or presets.' );
		}

		foreach ( $theme_changes as $option => $color ) {
			self::set_theme_option( $option, $color );
		}
		if ( $palette_dirty ) {
			$global['global_colors'] = $palette;
			self::set_theme_option( 'et_global_data', $global );
		}
		foreach ( $fonts as $option => $font ) {
			self::set_theme_option( $option, $font );
		}
		if ( $variables_dirty ) {
			update_option( self::VARIABLES_OPTION, $variables );
		}
		if ( $presets_dirty ) {
			update_option( self::PRESETS_OPTION, $registry, false );
		}
		self::clear_css( 'all' );

		return new \WP_REST_Response( array_merge( [ 'changed' => $made ], self::design_values() ), 200 );
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
