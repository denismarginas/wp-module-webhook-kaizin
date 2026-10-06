<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * User meta used by this plugin:
 *  _plain_password_temp      Password the Kaizin API currently has (only changed after a confirmed 2xx).
 *  _kaizin_pending_password  New WordPress password that the API has NOT accepted yet (retried on next login).
 *  _kaizin_email             Email the Kaizin API knows the user by (kept when the WP email changes).
 *  _kaizin_token             Bearer token returned by the API.
 */

add_action( 'wp_login', 'dm_kaizin_store_login_password', 10, 2 );
add_action( 'woocommerce_created_customer', 'dm_kaizin_handle_registration', 10, 3 );
add_action( 'user_register', 'dm_kaizin_handle_user_register', 10, 2 );
add_action( 'woocommerce_save_account_details', 'dm_kaizin_handle_account_details' );
add_action( 'profile_update', 'dm_kaizin_handle_profile_update', 10, 2 );
add_action( 'password_reset', 'dm_kaizin_handle_password_reset', 10, 2 );
add_action( 'after_password_reset', 'dm_kaizin_handle_password_reset', 10, 2 );
add_action( 'woocommerce_subscription_status_updated', 'dm_kaizin_handle_subscription_status', 10, 3 );
// Fallback when WooCommerce Subscriptions is not active: subscribe once an order is paid.
// Works for both the classic and the block (Store API) checkout.
add_action( 'woocommerce_order_status_changed', 'dm_kaizin_handle_order_status', 10, 4 );

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Normalize a password value the same way everywhere.
 * Only removes the slashes WordPress adds to $_POST. Never sanitize
 * passwords (sanitize_text_field would alter them and break the hash).
 */
function dm_kaizin_clean_password( $value ): string {
	return is_string( $value ) ? wp_unslash( $value ) : '';
}

/**
 * Return the first non-empty password found in $_POST for the given keys.
 */
function dm_kaizin_password_from_post( array $keys ): string {
	foreach ( $keys as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$password = dm_kaizin_clean_password( $_POST[ $key ] );
			if ( '' !== $password ) {
				return $password;
			}
		}
	}

	return '';
}

function dm_kaizin_get_api_password( int $user_id ): string {
	$password = get_user_meta( $user_id, '_plain_password_temp', true );
	return is_string( $password ) ? $password : '';
}

function dm_kaizin_wcs_active(): bool {
	return function_exists( 'wcs_user_has_subscription' );
}

/**
 * Change the password on the Kaizin API and only update the stored password
 * when the live API confirms it (2xx).
 *
 * $old_candidates: possible current API passwords, tried in order until one works.
 * On failure the stored password is left untouched (it is still what the API has)
 * and the new one is kept as pending so it can be retried on the next login.
 */
function dm_kaizin_sync_password_change( int $user_id, array $old_candidates, string $new_password ): bool {
	if ( '' === $new_password ) {
		return false;
	}

	$old_candidates = array_values( array_unique( array_filter( $old_candidates, static function ( $p ) {
		return is_string( $p ) && '' !== $p;
	} ) ) );
	if ( empty( $old_candidates ) ) {
		$old_candidates = [ '' ]; // No known old password: send without it.
	}

	$options = dm_kaizin_get_options();
	foreach ( $old_candidates as $index => $old_password ) {
		$response = dm_kaizin_send_webhook( $user_id, 'change_password', [
			'password' => $old_password,
			'new_password' => $new_password,
		], '' === $old_password );

		if ( dm_kaizin_webhook_synced( $response ) ) {
			update_user_meta( $user_id, '_plain_password_temp', $new_password );
			delete_user_meta( $user_id, '_kaizin_pending_password' );
			if ( $index > 0 ) {
				dm_kaizin_append_debug_log( sprintf( 'CHANGE_PASSWORD synced for user_id %d using fallback old password #%d', $user_id, $index + 1 ) );
			}
			return true;
		}

		// Skipped (disabled / missing data) or test environment: retrying with another candidate is pointless.
		if ( false === $response || ! empty( $options['test_environment'] ) ) {
			break;
		}
	}

	update_user_meta( $user_id, '_kaizin_pending_password', $new_password );
	dm_kaizin_append_debug_log( sprintf( 'CHANGE_PASSWORD NOT SYNCED for user_id %d: API keeps the previous password, stored password not changed. Will retry on next login.', $user_id ) );
	return false;
}

/**
 * Register the user on the Kaizin API (shared by WP and WooCommerce registration).
 */
function dm_kaizin_do_register( int $user_id, string $password ): void {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return;
	}

	update_user_meta( $user_id, '_dm_kaizin_register_sent', 1 );
	update_user_meta( $user_id, '_kaizin_email', $user->user_email );
	// The password the user registered with is the one the API should have.
	update_user_meta( $user_id, '_plain_password_temp', $password );

	$response = dm_kaizin_send_webhook( $user_id, 'register', [ 'password' => $password ] );
	if ( ! dm_kaizin_webhook_synced( $response ) ) {
		dm_kaizin_append_debug_log( sprintf( 'REGISTER NOT SYNCED for user_id %d: user may not exist on the API, subscribe will fail until fixed.', $user_id ) );
	}
	// Token (if any) is saved by dm_kaizin_send_webhook().
}

/* -------------------------------------------------------------------------
 * Login
 * ---------------------------------------------------------------------- */

function dm_kaizin_store_login_password( string $user_login, WP_User $user ): void {
	// 'pwd' = wp-login.php, 'password' = WooCommerce My Account login form.
	$password = dm_kaizin_password_from_post( [ 'pwd', 'password' ] );
	if ( '' === $password ) {
		return;
	}

	$pending = get_user_meta( $user->ID, '_kaizin_pending_password', true );
	if ( is_string( $pending ) && '' !== $pending ) {
		$api_password = dm_kaizin_get_api_password( $user->ID );
		if ( $api_password === $password ) {
			// API already has this password; nothing pending any more.
			delete_user_meta( $user->ID, '_kaizin_pending_password' );
			return;
		}
		// Retry the change that failed earlier: API has $api_password, WordPress has $password.
		dm_kaizin_append_debug_log( sprintf( 'LOGIN: retrying pending password sync for user_id %d', $user->ID ) );
		dm_kaizin_sync_password_change( $user->ID, [ $api_password ], $password );
		return;
	}

	// Nothing pending: WordPress and API are assumed in sync, refresh the stored copy.
	update_user_meta( $user->ID, '_plain_password_temp', $password );
}

/* -------------------------------------------------------------------------
 * Registration
 * ---------------------------------------------------------------------- */

/**
 * WooCommerce passes $password_generated as a bool, not the password.
 * The real password is in $new_customer_data['user_pass'].
 */
function dm_kaizin_handle_registration( int $user_id, array $new_customer_data, $password_generated = false ): void {
	if ( get_user_meta( $user_id, '_dm_kaizin_register_sent', true ) ) {
		return;
	}

	$password = dm_kaizin_password_from_post( [ 'account_password', 'password' ] );
	if ( '' === $password && isset( $new_customer_data['user_pass'] ) && is_string( $new_customer_data['user_pass'] ) && '' !== $new_customer_data['user_pass'] ) {
		$password = $new_customer_data['user_pass'];
	}

	if ( '' === $password ) {
		dm_kaizin_append_debug_log( sprintf( 'REGISTER skipped (WooCommerce): missing password for user_id %d', $user_id ) );
		return;
	}

	dm_kaizin_do_register( $user_id, $password );
}

function dm_kaizin_handle_user_register( int $user_id, array $userdata = [] ): void {
	if ( get_user_meta( $user_id, '_dm_kaizin_register_sent', true ) ) {
		return;
	}

	$password = dm_kaizin_password_from_post( [ 'pass1', 'account_password', 'password', 'pwd' ] );
	if ( '' === $password && isset( $userdata['user_pass'] ) && is_string( $userdata['user_pass'] ) && '' !== $userdata['user_pass'] ) {
		$password = $userdata['user_pass'];
	}

	if ( '' === $password ) {
		dm_kaizin_append_debug_log( sprintf( 'REGISTER skipped: missing password for user_id %d', $user_id ) );
		return;
	}

	dm_kaizin_do_register( $user_id, $password );
}

/* -------------------------------------------------------------------------
 * Password / email changes
 * ---------------------------------------------------------------------- */

function dm_kaizin_handle_account_details( int $user_id ): void {
	$new_password = dm_kaizin_password_from_post( [ 'password_1' ] );
	if ( '' === $new_password ) {
		return;
	}

	// Try the password we know the API has first, then what the user typed as "current".
	dm_kaizin_sync_password_change( $user_id, [
		dm_kaizin_get_api_password( $user_id ),
		dm_kaizin_password_from_post( [ 'password_current' ] ),
	], $new_password );
}

function dm_kaizin_handle_profile_update( int $user_id, WP_User $old_user_data ): void {
	// Email change: keep using the email the API knows.
	$user = get_user_by( 'id', $user_id );
	if ( $user && $old_user_data->user_email !== $user->user_email ) {
		$api_email = get_user_meta( $user_id, '_kaizin_email', true );
		if ( ! is_string( $api_email ) || '' === $api_email ) {
			update_user_meta( $user_id, '_kaizin_email', $old_user_data->user_email );
			$api_email = $old_user_data->user_email;
		}
		dm_kaizin_append_debug_log( sprintf( 'EMAIL changed for user_id %d (%s -> %s). API calls keep using %s.', $user_id, $old_user_data->user_email, $user->user_email, $api_email ) );
	}

	// Password change from wp-admin (profile.php / user-edit.php).
	$new_password = dm_kaizin_password_from_post( [ 'pass1' ] );
	if ( '' === $new_password ) {
		return;
	}

	$created_via_admin = isset( $_POST['createuser'] ) || ( isset( $_POST['action'] ) && 'createuser' === $_POST['action'] );
	if ( $created_via_admin ) {
		return;
	}

	dm_kaizin_sync_password_change( $user_id, [ dm_kaizin_get_api_password( $user_id ) ], $new_password );
}

function dm_kaizin_handle_password_reset( WP_User $user, string $new_password ): void {
	static $sent = false;
	if ( $sent ) {
		return;
	}
	$sent = true;

	// Prefer the submitted field (wp-login uses pass1, WooCommerce uses password_1)
	// so it is normalized like every other path.
	$posted = dm_kaizin_password_from_post( [ 'pass1', 'password_1' ] );
	if ( '' !== $posted ) {
		$new_password = $posted;
	}

	dm_kaizin_sync_password_change( $user->ID, [ dm_kaizin_get_api_password( $user->ID ) ], $new_password );
}

/* -------------------------------------------------------------------------
 * Subscribe / unsubscribe
 * ---------------------------------------------------------------------- */

function dm_kaizin_send_subscription_event( int $user_id, string $action ): void {
	$password = dm_kaizin_get_api_password( $user_id );
	if ( '' === $password ) {
		dm_kaizin_append_debug_log( sprintf( '%s skipped: no stored API password for user_id %d (user must log in once)', strtoupper( $action ), $user_id ) );
		return;
	}

	dm_kaizin_send_webhook( $user_id, $action, [ 'password' => $password ] );
}

/**
 * WooCommerce Subscriptions: subscribe when a subscription becomes active
 * (first payment or reactivation), unsubscribe when it stops and the user
 * has no other active subscription.
 */
function dm_kaizin_handle_subscription_status( $subscription, string $new_status, string $old_status ): void {
	$user_id = (int) $subscription->get_user_id();
	if ( ! $user_id ) {
		return;
	}

	if ( 'active' === $new_status && 'active' !== $old_status ) {
		dm_kaizin_send_subscription_event( $user_id, 'subscribe' );
		return;
	}

	if ( in_array( $new_status, [ 'cancelled', 'on-hold', 'expired' ], true ) ) {
		if ( dm_kaizin_wcs_active() && wcs_user_has_subscription( $user_id, '', 'active' ) ) {
			dm_kaizin_append_debug_log( sprintf( 'UNSUBSCRIBE skipped: user_id %d still has another active subscription', $user_id ) );
			return;
		}
		dm_kaizin_send_subscription_event( $user_id, 'unsubscribe' );
	}
}

/**
 * Without WooCommerce Subscriptions: subscribe once per order, after payment.
 */
function dm_kaizin_handle_order_status( $order_id, string $old_status, string $new_status, $order = null ): void {
	if ( dm_kaizin_wcs_active() ) {
		return; // Handled by dm_kaizin_handle_subscription_status().
	}
	if ( ! in_array( $new_status, [ 'processing', 'completed' ], true ) ) {
		return;
	}

	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$user_id = (int) $order->get_user_id();
	if ( ! $user_id || $order->get_meta( '_kaizin_subscribe_sent' ) ) {
		return;
	}

	$order->update_meta_data( '_kaizin_subscribe_sent', 1 );
	$order->save_meta_data();

	dm_kaizin_send_subscription_event( $user_id, 'subscribe' );
}
