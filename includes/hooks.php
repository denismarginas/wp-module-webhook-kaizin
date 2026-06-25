<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_login', 'dm_kaizin_store_login_password', 10, 2 );
add_action( 'woocommerce_checkout_order_processed', 'dm_kaizin_handle_checkout', 10, 3 );
add_action( 'woocommerce_created_customer', 'dm_kaizin_handle_registration', 10, 3 );
add_action( 'user_register', 'dm_kaizin_handle_user_register', 10, 2 );
add_action( 'woocommerce_save_account_details', 'dm_kaizin_handle_account_details' );
add_action( 'profile_update', 'dm_kaizin_handle_profile_update', 10, 2 );
add_action( 'after_password_reset', 'dm_kaizin_handle_password_reset', 10, 2 );
add_action( 'woocommerce_subscription_status_updated', 'dm_kaizin_handle_subscription_status', 10, 3 );

function dm_kaizin_store_login_password( string $user_login, WP_User $user ): void {
	if ( isset( $_POST['pwd'] ) ) {
		update_user_meta( $user->ID, '_plain_password_temp', sanitize_text_field( wp_unslash( $_POST['pwd'] ) ) );
	}
}

function dm_kaizin_handle_checkout( int $order_id, array $posted_data, WC_Order $order ): void {
	$user_id = $order->get_user_id();
	if ( ! $user_id ) {
		return;
	}

	$password = $_POST['account_password'] ?? get_user_meta( $user_id, '_plain_password_temp', true );
	if ( $password ) {
		dm_kaizin_send_webhook( $user_id, 'subscribe', [ 'password' => $password ] );
	}
}

function dm_kaizin_handle_registration( int $user_id, array $new_customer_data, string $password_generated ): void {
	if ( get_user_meta( $user_id, '_dm_kaizin_register_sent', true ) ) {
		return;
	}

	$password = $_POST['account_password'] ?? $_POST['password'] ?? $password_generated;
	if ( $password ) {
		update_user_meta( $user_id, '_plain_password_temp', $password );
		update_user_meta( $user_id, '_dm_kaizin_register_sent', 1 );
		$response = dm_kaizin_send_webhook( $user_id, 'register', [ 'password' => $password ] );
		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $data['token'] ) ) {
				update_user_meta( $user_id, '_kaizin_token', $data['token'] );
			}
		}
	}
}

function dm_kaizin_handle_account_details( int $user_id ): void {
	if ( ! empty( $_POST['password_1'] ) ) {
		update_user_meta( $user_id, '_plain_password_temp', sanitize_text_field( wp_unslash( $_POST['password_1'] ) ) );
		dm_kaizin_send_webhook( $user_id, 'change_password', [
			'password' => $_POST['password_current'] ?? '',
			'new_password' => $_POST['password_1'],
		] );
	}
}

function dm_kaizin_handle_user_register( int $user_id, array $userdata = [] ): void {
	if ( get_user_meta( $user_id, '_dm_kaizin_register_sent', true ) ) {
		return;
	}

	$password = '';
	if ( ! empty( $_POST['pass1'] ) ) {
		$password = sanitize_text_field( wp_unslash( $_POST['pass1'] ) );
	} elseif ( ! empty( $_POST['password'] ) ) {
		$password = sanitize_text_field( wp_unslash( $_POST['password'] ) );
	} elseif ( ! empty( $_POST['pwd'] ) ) {
		$password = sanitize_text_field( wp_unslash( $_POST['pwd'] ) );
	} elseif ( ! empty( $userdata['user_pass'] ) ) {
		$password = $userdata['user_pass'];
	}

	if ( ! $password ) {
		dm_kaizin_append_debug_log( sprintf( 'REGISTER skipped: missing password for user_id %d', $user_id ) );
		return;
	}

	update_user_meta( $user_id, '_plain_password_temp', $password );
	update_user_meta( $user_id, '_dm_kaizin_register_sent', 1 );
	$response = dm_kaizin_send_webhook( $user_id, 'register', [ 'password' => $password ] );
	if ( ! is_wp_error( $response ) ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['token'] ) ) {
			update_user_meta( $user_id, '_kaizin_token', $data['token'] );
		}
	}
}

function dm_kaizin_handle_profile_update( int $user_id, WP_User $old_user_data ): void {
	if ( ! empty( $_POST['pass1'] ) ) {
		$created_via_admin = isset( $_POST['createuser'] ) || isset( $_POST['action'] ) && 'createuser' === $_POST['action'];
		if ( $created_via_admin ) {
			return;
		}

		$old_password = get_user_meta( $user_id, '_plain_password_temp', true );
		$new_password = sanitize_text_field( wp_unslash( $_POST['pass1'] ) );
		dm_kaizin_send_webhook( $user_id, 'change_password', [
			'password' => $old_password,
			'new_password' => $new_password,
		] );
		update_user_meta( $user_id, '_plain_password_temp', $new_password );
	}
}

function dm_kaizin_handle_password_reset( WP_User $user, string $new_password ): void {
	$old_password = get_user_meta( $user->ID, '_plain_password_temp', true );
	dm_kaizin_send_webhook( $user->ID, 'change_password', [
		'password' => $old_password,
		'new_password' => $new_password,
	] );
	update_user_meta( $user->ID, '_plain_password_temp', $new_password );
}

function dm_kaizin_handle_subscription_status( $subscription, string $new_status, string $old_status ): void {
	$user_id = $subscription->get_user_id();
	$password = get_user_meta( $user_id, '_plain_password_temp', true );

	if ( ! $password ) {
		return;
	}

	if ( in_array( $new_status, [ 'cancelled', 'on-hold', 'expired' ], true ) ) {
		dm_kaizin_send_webhook( $user_id, 'unsubscribe', [ 'password' => $password ] );
	}
}
