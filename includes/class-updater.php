<?php
/**
 * Updates from GitHub releases, through WordPress's own updater.
 *
 * Each published release on GitHub (tag "v1.5.0" and so on) shows up on
 * Dashboard → Updates like any other plugin update, and installs itself
 * with WordPress's automatic plugin updates. WordPress swaps the whole
 * folder at once, so a site never runs half of one version and half of
 * another, and an update never runs uninstall.php.
 *
 * wp-config.php overrides:
 *   define( 'X8_PUBLISHER_AUTO_UPDATE', false );  // offer updates, but don't install them unattended
 *   define( 'X8_PUBLISHER_UPDATE_REPO', 'owner/repo' );
 *   define( 'X8_PUBLISHER_GITHUB_TOKEN', 'github_pat_...' ); // a private repo: a fine-grained
 *       token for this one repository with read-only Contents access
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {

	const REPO  = 'KeithClemmons/x8-marketing-publisher';
	const CACHE = 'x8_publisher_release';
	// The zip the release workflow attaches; its top folder is the plugin's.
	const ZIP = 'x8-marketing-publisher.zip';

	/** @var string e.g. "x8-marketing-publisher/x8-marketing-publisher.php" */
	private $basename;

	/** @var string The plugin's folder, whatever it was installed as. */
	private $slug;

	public function __construct( string $file ) {
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
	}

	public function register() : void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'offer_update' ] );
		add_filter( 'plugins_api', [ $this, 'details' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'keep_folder_name' ], 10, 4 );
		add_filter( 'auto_update_plugin', [ $this, 'auto_update' ], 10, 2 );
		add_filter( 'plugin_row_meta', [ $this, 'row_links' ], 10, 2 );
		add_action( 'load-update-core.php', [ $this, 'forget_on_force_check' ] );
		add_filter( 'upgrader_pre_download', [ $this, 'download_private' ], 10, 4 );
	}

	private function token() : string {
		return defined( 'X8_PUBLISHER_GITHUB_TOKEN' ) ? trim( (string) X8_PUBLISHER_GITHUB_TOKEN ) : '';
	}

	private function api_headers() : array {
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'x8-marketing-publisher/' . X8_PUBLISHER_VERSION,
		];
		if ( '' !== $this->token() ) {
			$headers['Authorization'] = 'Bearer ' . $this->token();
		}
		return $headers;
	}

	private function repo() : string {
		return defined( 'X8_PUBLISHER_UPDATE_REPO' ) ? (string) X8_PUBLISHER_UPDATE_REPO : self::REPO;
	}

	/**
	 * The latest published release, asked of GitHub at most every six hours
	 * (every hour after a failed ask).
	 *
	 * @return array{version:string,package:string,url:string,notes:string,published:string}|null
	 */
	private function release() : ?array {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return '' !== ( $cached['version'] ?? '' ) ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo() . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => $this->api_headers(),
			]
		);
		$body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['tag_name'] ) ) {
			// A private repo answers 404 without a token.
			error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'X8 Marketing Publisher: couldn\'t check %s for updates (%s)%s',
				$this->repo(),
				is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ),
				'' === $this->token() ? '; if the repository is private, set X8_PUBLISHER_GITHUB_TOKEN in wp-config.php' : ''
			) );
			set_site_transient( self::CACHE, [ 'version' => '' ], HOUR_IN_SECONDS );
			return null;
		}

		$package = '';
		foreach ( $body['assets'] ?? [] as $asset ) {
			if ( self::ZIP === ( $asset['name'] ?? '' ) ) {
				// A private repo's files come through the API, with the token; see download_private().
				$package = (string) ( '' !== $this->token() ? $asset['url'] : $asset['browser_download_url'] );
			}
		}

		$release = [
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			// GitHub's own source zip works too; keep_folder_name() fixes its folder.
			'package'   => $package ?: (string) ( $body['zipball_url'] ?? '' ),
			'url'       => (string) ( $body['html_url'] ?? '' ),
			'notes'     => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
		];
		set_site_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Adds the release to WordPress's list of plugin updates when it's newer.
	 * Listed under no_update otherwise, so the auto-updates column still shows.
	 */
	public function offer_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->release();
		if ( null === $release || '' === $release['package'] ) {
			return $transient;
		}

		$item = (object) [
			'id'           => 'github.com/' . $this->repo(),
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.5',
			'requires_php' => '7.4',
			'icons'        => [],
			'banners'      => [],
		];

		if ( version_compare( $release['version'], X8_PUBLISHER_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}
		return $transient;
	}

	/**
	 * The "View details" window on the Plugins and Updates screens.
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$release = $this->release();
		if ( null === $release ) {
			return $result;
		}
		return (object) [
			'name'          => 'X8 Marketing Publisher',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => 'X8 Marketing',
			'homepage'      => $release['url'],
			'requires'      => '6.5',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => [
				'changelog' => '' !== trim( $release['notes'] )
					? wpautop( esc_html( $release['notes'] ) )
					: '<p>' . esc_html__( 'See the release on GitHub.', 'x8-marketing-publisher' ) . '</p>',
			],
		];
	}

	/**
	 * Unzips into the folder the plugin already lives in. GitHub's zips
	 * unpack as "owner-repo-sha" or "repo-main"; installing that would leave a
	 * second copy and switch this one off.
	 */
	public function keep_folder_name( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( is_wp_error( $source ) || ( $hook_extra['plugin'] ?? '' ) !== $this->basename ) {
			return $source;
		}
		global $wp_filesystem;

		$wanted = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . basename( $this->basename ) ) ) {
			return new \WP_Error( 'x8_publisher_bad_package', __( "The update package doesn't contain X8 Marketing Publisher.", 'x8-marketing-publisher' ) );
		}
		if ( ! $wp_filesystem->move( $source, $wanted, true ) ) {
			return new \WP_Error( 'x8_publisher_rename_failed', __( "Couldn't unpack the X8 Marketing Publisher update.", 'x8-marketing-publisher' ) );
		}
		return $wanted;
	}

	/**
	 * Installs releases unattended, twice a day with WordPress's own
	 * automatic updates, which roll back an update that breaks the site.
	 */
	public function auto_update( $update, $item ) {
		if ( ! isset( $item->plugin ) || $item->plugin !== $this->basename ) {
			return $update;
		}
		return defined( 'X8_PUBLISHER_AUTO_UPDATE' ) ? (bool) X8_PUBLISHER_AUTO_UPDATE : true;
	}

	/**
	 * Downloads a private repo's release. GitHub answers the API with a redirect
	 * to a signed file URL, which must be fetched without the token, so the
	 * redirect is followed here rather than by WordPress.
	 */
	public function download_private( $reply, $package, $upgrader = null, $hook_extra = [] ) {
		$api = 'https://api.github.com/repos/' . $this->repo() . '/';
		if ( false !== $reply || '' === $this->token() || 0 !== strpos( (string) $package, $api ) ) {
			return $reply;
		}

		$headers = $this->api_headers();
		if ( false !== strpos( $package, '/releases/assets/' ) ) {
			$headers['Accept'] = 'application/octet-stream';
		}
		$response = wp_remote_get( $package, [ 'timeout' => 30, 'redirection' => 0, 'headers' => $headers ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			return new \WP_Error(
				'x8_publisher_download_failed',
				sprintf( 'GitHub refused the download (HTTP %d). Check X8_PUBLISHER_GITHUB_TOKEN.', wp_remote_retrieve_response_code( $response ) )
			);
		}
		if ( $upgrader && isset( $upgrader->skin ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}
		return download_url( is_array( $location ) ? end( $location ) : $location );
	}

	/**
	 * A "Check for updates" link under the plugin on the Plugins screen.
	 */
	public function row_links( $links, $file ) {
		if ( $file === $this->basename && current_user_can( 'update_plugins' ) ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'update-core.php?force-check=1' ), 'upgrade-core' ) ),
				esc_html__( 'Check for updates', 'x8-marketing-publisher' )
			);
		}
		return $links;
	}

	/**
	 * "Check again" on Dashboard → Updates asks GitHub afresh too.
	 */
	public function forget_on_force_check() : void {
		if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- update-core.php checks the nonce.
			delete_site_transient( self::CACHE );
		}
	}
}
