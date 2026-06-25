<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dm_kaizin_send_webhook( int $user_id, string $action, array $args = [] ) {
	$options = dm_kaizin_get_options();
	if ( ! dm_kaizin_is_action_allowed( $action ) ) {
		return false;
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		dm_kaizin_append_debug_log( sprintf( '%s skipped: user_id %d not found', strtoupper( $action ), $user_id ) );
		return false;
	}

	$email = $user->user_email;
	$salt = 'MakeThingsGoRight';
	$password = ! empty( $args['password'] ) ? hash_hmac( 'sha512', $args['password'], $salt ) : '';
	$new_password = ! empty( $args['new_password'] ) ? hash_hmac( 'sha512', $args['new_password'], $salt ) : '';
	$missing_fields = [];
	if ( in_array( $action, [ 'register', 'subscribe', 'unsubscribe' ], true ) && empty( $args['password'] ) ) {
		$missing_fields[] = 'password';
	}
	if ( 'change_password' === $action ) {
		if ( empty( $args['password'] ) ) {
			$missing_fields[] = 'password';
		}
		if ( empty( $args['new_password'] ) ) {
			$missing_fields[] = 'new_password';
		}
	}
	if ( ! empty( $missing_fields ) ) {
		dm_kaizin_append_debug_log( sprintf( '%s skipped: missing %s for user_id %d', strtoupper( $action ), implode( ', ', $missing_fields ), $user_id ) );
		return false;
	}
	$token = get_user_meta( $user_id, '_kaizin_token', true );
	$endpoint = dm_kaizin_get_webhook_config( $action );
	$endpoint['body']['email'] = $email;
	$endpoint['body']['password'] = $password;

	if ( 'change_password' === $action ) {
		$endpoint['body']['newPassword'] = $new_password;
	}
	if ( in_array( $action, [ 'subscribe', 'unsubscribe' ], true ) ) {
		$endpoint['headers'] = $token ? [ 'Authorization' => 'Bearer ' . $token ] : [];
	}
	if ( ! empty( $options['test_environment'] ) ) {
		$endpoint['url'] = ! empty( $options['test_webhook_url'] ) ? $options['test_webhook_url'] : 'https://webhook.site/df5ac162-d804-4c79-93f8-53bc0e65dbb7';
	}

	$default_headers = dm_kaizin_parse_default_headers( $options['default_headers'] ?? '' );
	$final_headers = array_merge( $default_headers, $endpoint['headers'] ?? [] );

	$response = wp_remote_request( $endpoint['url'], [
		'method' => $endpoint['method'],
		'headers' => $final_headers,
		'body' => wp_json_encode( $endpoint['body'] ),
		'timeout' => 20,
	] );

	$status = is_wp_error( $response ) ? 'FAIL' : 'OK';
	$status_code = wp_remote_retrieve_response_code( $response );
	$detail = sprintf( 'RESULT %s | STATUS %s | URL %s | DATA %s', $status, $status_code, $endpoint['url'], wp_json_encode( $endpoint['body'] ) );
	dm_kaizin_append_debug_log( sprintf( '%s | %s', strtoupper( $action ), $detail ) );
	dm_kaizin_log_webhook( $action, $endpoint['url'], $endpoint['body'], $response );

	return $response;
}

function dm_kaizin_parse_default_headers( string $header_text ): array {
	$headers = [];
	if ( '' === trim( $header_text ) ) {
		return [
			'Content-Type' => 'application/json',
			'x-doorman' => 'ec7847b48a0426a1ca41d3582bd43f52',
		];
	}

	$lines = preg_split( '/\r\n|\n|\r/', $header_text );
	if ( ! is_array( $lines ) ) {
		return [];
	}

	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line || strpos( $line, ':' ) === false ) {
			continue;
		}
		[$name, $value] = array_map( 'trim', explode( ':', $line, 2 ) );
		if ( '' !== $name ) {
			$headers[ $name ] = $value;
		}
	}

	return $headers;
}

function dm_kaizin_get_webhook_config( string $action ): array {
	$options = dm_kaizin_get_options();
	$defaults = [
		'register' => 'https://services.barakos.ai/api/auth/subscribers',
		'change_password' => 'https://services.barakos.ai/api/auth/subscribers',
		'subscribe' => 'https://services.barakos.ai/api/sputnik/subscribe',
		'unsubscribe' => 'https://services.barakos.ai/api/sputnik/unsubscribe',
	];

	$webhooks = [
		'register' => [
			'url' => ! empty( $options['register_url'] ) ? $options['register_url'] : $defaults['register'],
			'method' => 'POST',
			'body' => [ 'action-text' => 'register' ],
		],
		'change_password' => [
			'url' => ! empty( $options['change_password_url'] ) ? $options['change_password_url'] : $defaults['change_password'],
			'method' => 'PATCH',
			'body' => [ 'action-text' => 'change_password' ],
		],
		'subscribe' => [
			'url' => ! empty( $options['subscribe_url'] ) ? $options['subscribe_url'] : $defaults['subscribe'],
			'method' => 'POST',
			'body' => [ 'action-text' => 'subscribe' ],
		],
		'unsubscribe' => [
			'url' => ! empty( $options['unsubscribe_url'] ) ? $options['unsubscribe_url'] : $defaults['unsubscribe'],
			'method' => 'POST',
			'body' => [ 'action-text' => 'unsubscribe' ],
		],
	];

	return $webhooks[ $action ] ?? [];
}
