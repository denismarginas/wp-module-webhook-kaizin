<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dm_kaizin_log_webhook( string $action, string $url, array $payload, $response ): void {
	$upload_dir = wp_upload_dir();
	$log_file = trailingslashit( $upload_dir['basedir'] ) . 'kaizin-webhooks.log';
	$payload_for_log = $payload;

	if ( isset( $payload_for_log['password'] ) ) {
		$payload_for_log['password'] = '********';
	}
	if ( isset( $payload_for_log['newPassword'] ) ) {
		$payload_for_log['newPassword'] = '********';
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$error_message = is_wp_error( $response ) ? $response->get_error_message() : 'None';
	$response_body = ! is_wp_error( $response ) ? wp_remote_retrieve_body( $response ) : 'N/A';
	$log_entry = sprintf(
		"[%s] ACTION: %s | STATUS: %s | URL: %s\nPAYLOAD: %s\nRESPONSE: %s\nERROR: %s\n%s\n",
		date( 'Y-m-d H:i:s' ),
		strtoupper( $action ),
		$status_code,
		$url,
		wp_json_encode( $payload_for_log ),
		dm_kaizin_truncate_log_response( $response_body ),
		$error_message,
		str_repeat( '-', 60 )
	);

	file_put_contents( $log_file, $log_entry, FILE_APPEND );
}

function dm_kaizin_append_debug_log( string $message ): void {
	$upload_dir = wp_upload_dir();
	$debug_log_file = trailingslashit( $upload_dir['basedir'] ) . 'kaizin-debug.log';
	$entry = sprintf( '[%s] %s', date( 'Y-m-d H:i:s' ), $message );
	$existing = file_exists( $debug_log_file ) ? trim( file_get_contents( $debug_log_file ) ) : '';
	$lines = $existing !== '' ? explode( "\n", $existing ) : [];
	$lines[] = $entry;

	while ( strlen( implode( "\n", $lines ) ) > 800 ) {
		array_shift( $lines );
	}

	file_put_contents( $debug_log_file, implode( "\n", $lines ) );
}

function dm_kaizin_get_debug_log(): string {
	$upload_dir = wp_upload_dir();
	$debug_log_file = trailingslashit( $upload_dir['basedir'] ) . 'kaizin-debug.log';

	if ( ! file_exists( $debug_log_file ) ) {
		return 'No debug activity yet.';
	}

	return trim( file_get_contents( $debug_log_file ) );
}

function dm_kaizin_truncate_log_response( string $string ): string {
	return strlen( $string ) > 200 ? substr( $string, 0, 200 ) . '...' : $string;
}
