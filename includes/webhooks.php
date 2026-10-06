<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send a webhook to the Kaizin API.
 *
 * Returns the wp_remote_request() response (array or WP_Error), or false when
 * the call was skipped (action disabled, user missing, required field missing).
 * Use dm_kaizin_webhook_synced() to know whether the live API really accepted it.
 */
function dm_kaizin_send_webhook( int $user_id, string $action, array $args = [], bool $allow_missing_password = false ) {
	$options = dm_kaizin_get_options();
	if ( ! dm_kaizin_is_action_allowed( $action ) ) {
		dm_kaizin_append_debug_log( sprintf( '%s skipped: action disabled in settings (user_id %d)', strtoupper( $action ), $user_id ) );
		return false;
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		dm_kaizin_append_debug_log( sprintf( '%s skipped: user_id %d not found', strtoupper( $action ), $user_id ) );
		return false;
	}

	$has_password = isset( $args['password'] ) && is_string( $args['password'] ) && '' !== $args['password'];
	$has_new_password = isset( $args['new_password'] ) && is_string( $args['new_password'] ) && '' !== $args['new_password'];

	$email = dm_kaizin_get_api_email( $user );
	$salt = 'MakeThingsGoRight';
	$password = $has_password ? hash_hmac( 'sha512', $args['password'], $salt ) : '';
	$new_password = $has_new_password ? hash_hmac( 'sha512', $args['new_password'], $salt ) : '';

	$missing_fields = [];
	if ( in_array( $action, [ 'register', 'subscribe', 'unsubscribe' ], true ) && ! $has_password ) {
		$missing_fields[] = 'password';
	}
	if ( 'change_password' === $action ) {
		if ( ! $has_password && ! $allow_missing_password ) {
			$missing_fields[] = 'password';
		}
		if ( ! $has_new_password ) {
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
	if ( '' !== $password ) {
		$endpoint['body']['password'] = $password;
	}
	if ( 'change_password' === $action && '' !== $new_password ) {
		$endpoint['body']['newPassword'] = $new_password;
	}
	if ( in_array( $action, [ 'subscribe', 'unsubscribe' ], true ) ) {
		$endpoint['headers'] = $token ? [ 'Authorization' => 'Bearer ' . $token ] : [];
		if ( ! $token ) {
			dm_kaizin_append_debug_log( sprintf( '%s warning: no _kaizin_token for user_id %d, sending without Authorization', strtoupper( $action ), $user_id ) );
		}
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

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( is_wp_error( $response ) ) {
		$status = 'FAIL (' . $response->get_error_message() . ')';
	} elseif ( $status_code >= 200 && $status_code < 300 ) {
		$status = ! empty( $options['test_environment'] ) ? 'OK (TEST ENV - not sent to live API)' : 'OK';
	} else {
		$status = 'FAIL';
	}

	$body_for_log = $endpoint['body'];
	$detail = sprintf( 'RESULT %s | STATUS %s | URL %s | DATA %s', $status, $status_code, $endpoint['url'], wp_json_encode( $body_for_log ) );
	if ( 0 === strpos( $status, 'FAIL' ) && ! is_wp_error( $response ) ) {
		$detail .= ' | RESPONSE ' . dm_kaizin_truncate_log_response( (string) wp_remote_retrieve_body( $response ) );
		$detail .= ' | HEADERS SENT ' . dm_kaizin_headers_for_log( $final_headers );
	}
	dm_kaizin_append_debug_log( sprintf( '%s | %s', strtoupper( $action ), $detail ) );
	dm_kaizin_log_webhook( $action, $endpoint['url'], $endpoint['body'], $response );

	// Any successful live response may carry a fresh token: keep it.
	if ( dm_kaizin_webhook_synced( $response ) ) {
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && ! empty( $data['token'] ) && is_string( $data['token'] ) ) {
			update_user_meta( $user_id, '_kaizin_token', $data['token'] );
		}
	}

	return $response;
}

/**
 * True only when the request reached the LIVE API and it answered 2xx.
 * Skipped calls, transport errors, non-2xx answers and test-environment sends are false.
 */
function dm_kaizin_webhook_synced( $response ): bool {
	if ( ! $response || is_wp_error( $response ) ) {
		return false;
	}

	$options = dm_kaizin_get_options();
	if ( ! empty( $options['test_environment'] ) ) {
		return false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	return $code >= 200 && $code < 300;
}

/**
 * The email the Kaizin API knows this user by. Stays the same if the user
 * later changes their WordPress email, so API calls keep matching.
 */
function dm_kaizin_get_api_email( WP_User $user ): string {
	$api_email = get_user_meta( $user->ID, '_kaizin_email', true );
	return is_string( $api_email ) && '' !== $api_email ? $api_email : $user->user_email;
}

/**
 * Header list for the debug log. Secrets are masked except their length and last 4 chars,
 * enough to see whether the right key is being sent.
 */
function dm_kaizin_headers_for_log( array $headers ): string {
	$parts = [];
	foreach ( $headers as $name => $value ) {
		$value = (string) $value;
		if ( in_array( strtolower( (string) $name ), [ 'x-doorman', 'authorization' ], true ) ) {
			$value = sprintf( '***%s (len %d)', substr( $value, -4 ), strlen( $value ) );
		}
		$parts[] = $name . '=' . $value;
	}

	return '[' . implode( '; ', $parts ) . ']';
}

/**
 * Parse the "Default headers" setting. Accepts any of these formats:
 *   Content-Type: application/json            (one header per line)
 *   x-doorman: <key>
 *   ['Content-Type' => 'application/json', 'x-doorman' => '<key>']   (PHP array)
 *   {"Content-Type": "application/json", "x-doorman": "<key>"}       (JSON)
 * Content-Type and x-doorman are always present: if the setting does not provide
 * them, the built-in defaults are used.
 */
function dm_kaizin_parse_default_headers( string $header_text ): array {
	$required = [
		'Content-Type' => 'application/json',
		'x-doorman' => 'ec7847b48a0426a1ca41d3582bd43f52',
	];

	$parsed = [];
	// Accept literal "\n" sequences too (older saved defaults used them).
	$header_text = trim( str_replace( '\n', "\n", $header_text ) );

	if ( '' !== $header_text ) {
		// 1) PHP array / JSON style: 'Name' => 'value'  or  "Name": "value"
		if ( preg_match_all( '/[\'"]([A-Za-z0-9\-_]+)[\'"]\s*(?:=>|:)\s*[\'"]([^\'"]*)[\'"]/', $header_text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$parsed[ trim( $match[1] ) ] = trim( $match[2] );
			}
		} else {
			// 2) One "Name: value" per line.
			$lines = preg_split( '/\r\n|\n|\r/', $header_text );
			foreach ( is_array( $lines ) ? $lines : [] as $line ) {
				$line = trim( $line );
				if ( '' === $line || false === strpos( $line, ':' ) ) {
					continue;
				}
				[ $name, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
				if ( '' !== $name ) {
					$parsed[ $name ] = $value;
				}
			}
		}

		if ( empty( $parsed ) ) {
			dm_kaizin_append_debug_log( 'HEADERS warning: could not read the "Default headers" setting, using built-in defaults' );
		}
	}

	// Add any required header the setting did not provide (case-insensitive).
	$present = array_map( 'strtolower', array_keys( $parsed ) );
	foreach ( $required as $name => $value ) {
		if ( ! in_array( strtolower( $name ), $present, true ) ) {
			$parsed[ $name ] = $value;
		}
	}

	return $parsed;
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
