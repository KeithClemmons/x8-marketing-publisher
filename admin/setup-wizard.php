<?php
/**
 * Setup wizard — captures Business Name before provisioning.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) exit;

$nonce = wp_create_nonce( 'x8_publisher_admin' );
$error = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
$completed = ( 'complete' === ( $_GET['setup'] ?? '' ) );
?>
<div class="wrap x8-wrap">
	<h1 class="x8-screen-reader-title">X8 Marketing Publisher Setup</h1>
	<div class="x8-header">
		<?php echo Admin::logo_svg( 38 ); // phpcs:ignore ?>
		<h1>
			Welcome
			<span>Let's get you connected</span>
		</h1>
	</div>

	<?php if ( $completed ) : ?>
		<div class="x8-setup-card" style="text-align:center;">
			<div style="font-size:64px; margin-bottom:16px;">🎉</div>
			<h2>Connecting to your X8 Marketing Dashboard…</h2>
			<p class="x8-lead" style="max-width:520px; margin:0 auto 24px;">
				This usually takes 10–30 seconds. The page will refresh automatically when complete.
			</p>
			<div class="x8-status-row" style="justify-content:center;">
				<span class="x8-status-dot x8-status-amber"></span>
				<span class="x8-status-text">Establishing secure connection…</span>
			</div>
			<script>
				setTimeout( function(){
					window.location.href = '<?php echo esc_url_raw( admin_url( 'admin.php?page=' . Admin::MENU_SLUG ) ); ?>';
				}, 15000 );
			</script>
		</div>
	<?php else : ?>
		<div class="x8-setup-card">
			<h2>Connect to X8</h2>
			<p class="x8-lead">
				Enter the <strong>Business Name</strong> you use in your X8 Marketing Dashboard.
				This links your WordPress site to your client account so your team can publish content here.
			</p>

			<?php if ( 'name_required' === $error ) : ?>
				<div class="notice notice-error inline" style="margin:0 0 20px; max-width:500px;">
					<p>Please enter your Business Name (at least 2 characters).</p>
				</div>
			<?php elseif ( 'name_too_long' === $error ) : ?>
				<div class="notice notice-error inline" style="margin:0 0 20px; max-width:500px;">
					<p>Business Name is too long (max 100 characters).</p>
				</div>
			<?php elseif ( 'invalid_chars' === $error ) : ?>
				<div class="notice notice-error inline" style="margin:0 0 20px; max-width:500px;">
					<p>Business Name must contain at least one letter or number.</p>
				</div>
			<?php endif; ?>

			<form method="post">
				<input type="hidden" name="_x8_nonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="x8_action" value="save_business_name">

				<label for="x8-business-name" style="display:block; font-weight:600; margin-bottom:8px;">
					Your Business Name
				</label>
				<input
					type="text"
					id="x8-business-name"
					name="business_name"
					class="x8-setup-input"
					placeholder="e.g., Acme Plumbing"
					value="<?php echo esc_attr( $_POST['business_name'] ?? '' ); ?>"
					required
					autofocus
					maxlength="100"
				>

				<div class="x8-help">
					💡 <strong>Tip:</strong> Use the exact same Business Name you entered in your X8 Marketing Dashboard.
					If you haven't created an account yet, no problem — we'll set one up for you automatically.
				</div>

				<p style="margin-top: 24px;">
					<button type="submit" class="button button-primary x8-btn x8-btn-large">
						Connect WordPress to X8 →
					</button>
				</p>
			</form>
		</div>

		<div class="x8-footer">
			Powered by <span class="accent">X8 Marketing</span> •
			<a href="https://x8marketing.com" target="_blank" rel="noopener">x8marketing.com</a>
		</div>

		
	<?php endif; ?>
</div>