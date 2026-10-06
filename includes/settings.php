<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'dm_kaizin_add_admin_menu' );
add_action( 'admin_init', 'dm_kaizin_register_settings' );

function dm_kaizin_add_admin_menu(): void {
	add_options_page(
		'Webhooks Kaizin',
		'Webhooks',
		'manage_options',
		'dm-kaizin-webhooks',
		'dm_kaizin_render_settings_page'
	);
}

function dm_kaizin_register_settings(): void {
	register_setting( 'dm_kaizin_settings_group', 'dm_kaizin_options', 'dm_kaizin_sanitize_options' );
	add_settings_section( 'dm_kaizin_main_section', 'Webhook Settings', '__return_empty_string', 'dm_kaizin_webhooks' );

	$fields = [
		[ 'test_environment', 'Use test environment', 'checkbox' ],
		[ 'test_webhook_url', 'Test webhook URL', 'text' ],
		[ 'enable_all_webhooks', 'Enable all webhooks', 'checkbox' ],
		[ 'default_headers', 'Default headers', 'textarea' ],
		[ 'register', 'Register', 'checkbox' ],
		[ 'register_url', 'Register URL', 'text' ],
		[ 'change_password', 'Change password', 'checkbox' ],
		[ 'change_password_url', 'Change password URL', 'text' ],
		[ 'subscribe', 'Subscribe', 'checkbox' ],
		[ 'subscribe_url', 'Subscribe URL', 'text' ],
		[ 'unsubscribe', 'Unsubscribe', 'checkbox' ],
		[ 'unsubscribe_url', 'Unsubscribe URL', 'text' ],
	];

	foreach ( $fields as $field ) {
		add_settings_field( 'dm_kaizin_' . $field[0], $field[1], 'dm_kaizin_render_field', 'dm_kaizin_webhooks', 'dm_kaizin_main_section', [ 'key' => $field[0], 'type' => $field[2] ] );
	}
}

function dm_kaizin_render_field( array $args ): void {
	$options = dm_kaizin_get_options();
	$key = $args['key'];
	$value = $options[ $key ] ?? '';
	if ( 'checkbox' === $args['type'] ) {
		printf( '<input type="checkbox" name="dm_kaizin_options[%s]" value="1" %s />', esc_attr( $key ), checked( 1, (int) $value, false ) );
		return;
	}

	if ( 'textarea' === $args['type'] ) {
		printf( '<textarea name="dm_kaizin_options[%s]" rows="4" cols="60" class="large-text code">%s</textarea>', esc_attr( $key ), esc_textarea( $value ) );
		return;
	}

	printf( '<input type="text" name="dm_kaizin_options[%s]" value="%s" class="regular-text" />', esc_attr( $key ), esc_attr( $value ) );
}

function dm_kaizin_sanitize_options( array $input ): array {
	$sanitized = [];
	$sanitized['test_environment'] = ! empty( $input['test_environment'] ) ? 1 : 0;
	$sanitized['test_webhook_url'] = ! empty( $input['test_webhook_url'] ) ? esc_url_raw( trim( $input['test_webhook_url'] ) ) : '';
	$sanitized['enable_all_webhooks'] = ! empty( $input['enable_all_webhooks'] ) ? 1 : 0;
	$sanitized['default_headers'] = ! empty( $input['default_headers'] ) ? sanitize_textarea_field( wp_unslash( $input['default_headers'] ) ) : '';
	$sanitized['register'] = ! empty( $input['register'] ) ? 1 : 0;
	$sanitized['register_url'] = ! empty( $input['register_url'] ) ? esc_url_raw( trim( $input['register_url'] ) ) : '';
	$sanitized['change_password'] = ! empty( $input['change_password'] ) ? 1 : 0;
	$sanitized['change_password_url'] = ! empty( $input['change_password_url'] ) ? esc_url_raw( trim( $input['change_password_url'] ) ) : '';
	$sanitized['subscribe'] = ! empty( $input['subscribe'] ) ? 1 : 0;
	$sanitized['subscribe_url'] = ! empty( $input['subscribe_url'] ) ? esc_url_raw( trim( $input['subscribe_url'] ) ) : '';
	$sanitized['unsubscribe'] = ! empty( $input['unsubscribe'] ) ? 1 : 0;
	$sanitized['unsubscribe_url'] = ! empty( $input['unsubscribe_url'] ) ? esc_url_raw( trim( $input['unsubscribe_url'] ) ) : '';

	return $sanitized;
}

function dm_kaizin_render_settings_page(): void {
	?>
	<div class="wrap">
		<h1>Webhooks Kaizin</h1>
		<p>Control how WooCommerce user events are sent to the Kaizin endpoints.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dm_kaizin_settings_group' ); ?>
			<?php do_settings_sections( 'dm_kaizin_webhooks' ); ?>
			<?php submit_button(); ?>
		</form>

		<h2>Debug Log</h2>
		<p>The last 30 plugin events are kept here (newest at the bottom).</p>
		<textarea readonly rows="25" cols="120" style="font-family:monospace; width:100%; max-width:1100px;"><?php echo esc_textarea( dm_kaizin_get_debug_log() ); ?></textarea>
	</div>
	<?php
}
