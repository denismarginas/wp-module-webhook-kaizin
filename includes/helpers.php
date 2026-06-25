<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dm_kaizin_get_options(): array {
	$options = get_option( 'dm_kaizin_options', [] );
	$defaults = [
		'test_environment' => 0,
		'test_webhook_url' => '',
		'enable_all_webhooks' => 1,
		'register' => 1,
		'change_password' => 1,
		'subscribe' => 1,
		'unsubscribe' => 1,
		'register_url' => 'https://services.barakos.ai/api/auth/subscribers',
		'change_password_url' => 'https://services.barakos.ai/api/auth/subscribers',
		'subscribe_url' => 'https://services.barakos.ai/api/sputnik/subscribe',
		'unsubscribe_url' => 'https://services.barakos.ai/api/sputnik/unsubscribe',
		'default_headers' => 'Content-Type: application/json\nx-doorman: ec7847b48a0426a1ca41d3582bd43f52',
	];

	return array_merge( $defaults, is_array( $options ) ? $options : [] );
}

function dm_kaizin_is_action_allowed( string $action ): bool {
	$options = dm_kaizin_get_options();
	if ( ! empty( $options['enable_all_webhooks'] ) ) {
		return true;
	}

	return ! empty( $options[ $action ] );
}
