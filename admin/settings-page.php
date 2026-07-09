<?php
/**
 * Branded settings page — client-facing.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) exit;

$nonce            = wp_create_nonce( 'x8_publisher_admin' );
$last4            = get_option( 'x8_publisher_api_key_last4', '----' );
$last_request_at  = get_option( 'x8_publisher_last_request_at' );
$default_status   = get_option( 'x8_publisher_default_status', 'draft' );
$default_author   = (int) get_option( 'x8_publisher_default_author', 1 );
$default_category = (int) get_option( 'x8_publisher_default_category', 0 );
$sideload_images  = (bool) get_option( 'x8_publisher_sideload_images', 1 );
$provisioned      = (bool) get_option( 'x8_publisher_provisioned' );
$provisioned_at   = get_option( 'x8_publisher_provisioned_at' );
$provision_error  = get_option( 'x8_publisher_provision_error' );
$notified_at      = get_option( 'x8_publisher_provision_notified_at' );

$admin = new Admin();
$logs  = $admin->get_recent_logs( 20 );
$seo   = new SEO_Handler();

// ───────────────────────────────────────────────────────────────
// Connection Status — provision on-demand, show real result
// ───────────────────────────────────────────────────────────────

$has_recent_activity = ! empty( $last_request_at ) && strtotime( $last_request_at ) > strtotime( '-30 days' );

// If not provisioned yet, TRY NOW (synchronously). No waiting on cron.
if ( ! $provisioned && ! empty( get_option( 'x8_publisher_username' ) ) ) {
	( new Provisioning() )->notify();

	$provisioned     = (bool) get_option( 'x8_publisher_provisioned' );
	$provisioned_at  = get_option( 'x8_publisher_provisioned_at' );
	$provision_error = get_option( 'x8_publisher_provision_error' );

	$scheduled = wp_next_scheduled( 'x8_publisher_provision' );
	if ( $scheduled ) {
		wp_unschedule_event( $scheduled, 'x8_publisher_provision' );
	}
}

$truly_connected = $provisioned && empty( $provision_error );

if ( $truly_connected ) {
	$status_class = 'x8-status-green';
	$status_text  = 'Connected';
	$status_sub   = $has_recent_activity
		? 'Last request: ' . esc_html( $last_request_at )
		: '✓ Ready to publish from your X8 Marketing Dashboard';
} elseif ( ! empty( $provision_error ) ) {
	$status_class = 'x8-status-red';
	$status_text  = 'Connection failed';
	$status_sub   = 'Issue: ' . esc_html( $provision_error );
} else {
	$status_class = 'x8-status-amber';
	$status_text  = 'Finalizing connection…';
	$status_sub   = 'Click "Reconnect to X8" below if this persists.';
}
?>

<div class="wrap x8-wrap">

	<h1 class="x8-screen-reader-title">X8 Marketing Publisher</h1>

	<!-- Branded Header -->
	<div class="x8-header">
		<?php echo Admin::logo_svg( 38 ); // phpcs:ignore ?>
		<h1>
			Publisher Plugin
			<span>v<?php echo esc_html( X8_PUBLISHER_VERSION ); ?></span>
		</h1>
		
		<div class="x8-header-actions">
			<form method="post" style="margin:0;" class="x8-dashboard-form">
				<input type="hidden" name="_x8_nonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="x8_action" value="open_dashboard">
				<button type="submit" class="x8-header-link" style="cursor:pointer; background:none;">
					<span class="x8-btn-text">Open Dashboard ↗</span>
				</button>
			</form>
		</div>

		<?php if ( 'x8-status-amber' === $status_class ) : ?>
		<script>
		(function(){
			let tries = 0;
			const poll = setInterval(async () => {
				tries++;
				try {
					const r = await fetch('<?php echo esc_url_raw( rest_url( X8_PUBLISHER_NAMESPACE . '/ping' ) ); ?>', { cache: 'no-store' });
					const d = await r.json();
					if (d.provisioned) {
						clearInterval(poll);
						window.location.reload();
					}
				} catch(e) {}
				if (tries >= 12) clearInterval(poll);
			}, 5000);
		})();
		</script>
		<?php endif; ?>        
	</div>

	<?php if ( ! empty( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible" style="margin: 16px 0 0;"><p>Settings saved.</p></div>
	<?php endif; ?>
	<?php if ( ! empty( $_GET['reprovisioned'] ) ) : ?>
		<div class="notice notice-success is-dismissible" style="margin: 16px 0 0;">
			<p>Reconnecting with X8 Marketing Dashboard… This usually completes within 30 seconds.</p>
		</div>
	<?php endif; ?>
	<?php if ( ! empty( $_GET['magic_link_error'] ) ) : ?>
		<div class="notice notice-warning is-dismissible" style="margin: 16px 0 0;">
			<p><strong>Auto-login unavailable:</strong> We couldn't verify your secure session. Please sign in manually at the dashboard.</p>
		</div>
	<?php endif; ?>

	<div class="x8-card">
        <h2>Connection Status</h2>

        <div class="x8-status-row">
            <span class="x8-status-dot <?php echo esc_attr( $status_class ); ?>"></span>
            <span class="x8-status-text"><?php echo esc_html( $status_text ); ?></span>
        </div>
        <div class="x8-meta"><?php echo esc_html( $status_sub ); ?></div>

        <div class="x8-dashboard-cta">
            <div class="x8-dashboard-cta-text">
                <strong>Open your X8 Marketing Dashboard</strong>
                <span>Manage content, SEO, and campaigns for <?php echo esc_html( get_option( 'x8_publisher_business_name', 'your business' ) ); ?></span>
            </div>
			<form method="post" style="margin:0;" class="x8-dashboard-form">
				<input type="hidden" name="_x8_nonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="x8_action" value="open_dashboard">
				<button type="submit" class="button x8-btn x8-btn-large">
					<span class="x8-btn-text">Open Dashboard →</span>
				</button>
			</form>
        </div>

        <div style="margin-top: 16px;">
            <button type="button" class="button" id="x8-test-btn">Test Connection</button>
            <span class="x8-test-result" id="x8-test-result"></span>
        </div>
    </div>

	<div class="x8-card">
		<h2>System Information</h2>
		<div class="x8-info-grid">
			<div class="label">SEO Engine</div>
			<div><code><?php echo esc_html( ucfirst( $seo->detect_engine() ) ); ?></code></div>

			<div class="label">WordPress</div>
			<div><?php echo esc_html( get_bloginfo( 'version' ) ); ?></div>

			<div class="label">PHP</div>
			<div><?php echo esc_html( PHP_VERSION ); ?></div>

			<div class="label">Plugin Version</div>
			<div><?php echo esc_html( X8_PUBLISHER_VERSION ); ?></div>

			<?php if ( $provisioned_at ) : ?>
				<div class="label">Connected Since</div>
				<div><?php echo esc_html( $provisioned_at ); ?></div>
			<?php endif; ?>
		</div>
	</div>

	<div class="x8-card">
		<h2>Publishing Settings</h2>
		<form method="post">
			<input type="hidden" name="_x8_nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="x8_action" value="save_settings">
			<table class="form-table">
				<tr>
					<th><label for="default_status">Default post status</label></th>
					<td>
						<select name="default_status" id="default_status">
							<?php foreach ( [ 'draft', 'pending', 'publish' ] as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $default_status, $opt ); ?>>
									<?php echo esc_html( ucfirst( $opt ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="default_author">Default author</label></th>
					<td><?php wp_dropdown_users( [ 'name' => 'default_author', 'selected' => $default_author, 'who' => 'authors' ] ); ?></td>
				</tr>
				<tr>
					<th><label for="default_category">Default category</label></th>
					<td>
						<?php
						wp_dropdown_categories( [
							'show_option_none' => '— WordPress Default —',
							'option_none_value' => '0',
							'name'             => 'default_category',
							'id'               => 'default_category',
							'selected'         => $default_category,
							'hierarchical'     => 1,
							'hide_empty'       => 0,
							'class'            => 'postform',
						] );
						?>
					</td>
				</tr>
				<tr>
					<th>Image handling</th>
					<td>
						<label>
							<input type="checkbox" name="sideload_images" value="1" <?php checked( $sideload_images ); ?>>
							Automatically import remote images into your media library
						</label>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button x8-btn">Save Settings</button></p>
		</form>
	</div>

	<div class="x8-card">
		<h2>Recent Activity</h2>
		<?php if ( empty( $logs ) ) : ?>
			<p style="color:#666;"><em>No activity yet.</em></p>
		<?php else : ?>
			<table class="x8-logs">
				<thead>
					<tr>
						<th>When</th>
						<th>Action</th>
						<th>Result</th>
						<th>Post</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $logs as $log ) :
					$ok = $log->status_code >= 200 && $log->status_code < 300;
				?>
					<tr>
						<td><?php echo esc_html( human_time_diff( strtotime( $log->created_at ), current_time( 'timestamp' ) ) ); ?> ago</td>
						<td><?php echo esc_html( $log->endpoint ); ?></td>
						<td><span class="x8-badge <?php echo $ok ? 'x8-badge-ok' : 'x8-badge-err'; ?>"><?php echo $ok ? 'Success' : 'Failed'; ?></span></td>
						<td>
							<?php if ( $log->post_id ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $log->post_id ) ); ?>" target="_blank">
									<?php echo $log->post_title ? esc_html( $log->post_title ) : '#' . (int) $log->post_id; ?>
								</a>
							<?php else : ?>
								<span style="color:#999;">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<details class="x8-card" style="cursor:pointer;">
		<summary style="font-weight:600; color:#666;">Advanced</summary>
		<div style="margin-top:16px;">
			<form method="post" onsubmit="return confirm('Reconnect with X8 Marketing?');">
				<input type="hidden" name="_x8_nonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="x8_action" value="reprovision">
				<button type="submit" class="button">Reconnect to Dashboard</button>
			</form>
		</div>
	</details>

	<div class="x8-footer">
		Powered by <span class="accent">X8 Marketing</span> • <a href="https://x8webdesign.com" target="_blank" rel="noopener">x8marketing.com</a>
	</div>
</div>

<script>
(function(){
	// 1. Dashboard Magic Link Loading State
	document.querySelectorAll('.x8-dashboard-form').forEach(form => {
		form.addEventListener('submit', function() {
			const btn = this.querySelector('button');
			const text = this.querySelector('.x8-btn-text');
			
			btn.style.pointerEvents = 'none';
			btn.style.opacity = '0.7';
			
			if (text) {
				text.innerHTML = '<span class="spinner is-active" style="float:none; margin:0 8px 0 0; vertical-align:middle;"></span> Opening dashboard...';
			}
		});
	});

	// 2. Test Connection Logic
	const testBtn = document.getElementById('x8-test-btn');
	const testOut = document.getElementById('x8-test-result');
	if (testBtn) {
		testBtn.addEventListener('click', async function(){
			testOut.style.color = '#666';
			testOut.innerHTML = 'Testing…';
			testBtn.disabled = true;

			try {
				const res = await fetch('<?php echo esc_url_raw( rest_url( X8_PUBLISHER_NAMESPACE . '/ping' ) ); ?>', {
					headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
					cache: 'no-store'
				});
				const data = await res.json();

				if (data.provisioned) {
					testOut.style.color = '#22c55e';
					testOut.innerHTML = '✓ Connected.';
				} else {
					testOut.style.color = '#f59e0b';
					testOut.innerHTML = '⏳ Waiting for connection.';
				}
			} catch(e) {
				testOut.style.color = '#dc2626';
				testOut.innerHTML = '✗ Error.';
			} finally {
				testBtn.disabled = false;
			}
		});
	}
})();
</script>