<?php

/**
 * Plugin Name: Signalwire SMS WooCommerce Notifications
 * Description: Automatically send SMS when WooCommerce order status changes.
 * Version: 1.0.0
 * Author: Kervio
 * Author URI: https://www.kervio.com
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load Signalwire API Settings Page
include plugin_dir_path(__FILE__) . 'inc/sw-settings-page.php';

// Add plugin settings link
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'sw_plugin_settings_link' );
function sw_plugin_settings_link( $links )
{
    $url = esc_url( admin_url( 'options-general.php?page=signalwire-api' ) );
    $_link = '<a href="'.$url.'">' . __( 'API Settings', 'wordpress' ) . '</a>';
    $links[] = $_link;
    return $links;
}

/* Set Signalwire Variables */
function sw_variable($variable) {
    $options = get_option('signalwire-api');
    if ($variable == 'account_sid') {
        return $options['sw_account_sid'];
    } elseif ($variable == 'space_url') {
        return $options['sw_space_url'];
    } elseif ($variable == 'auth_token') {
        return $options['sw_auth_token'];
    } elseif ($variable == 'project_id') {
        return $options['sw_project_id'];
    } elseif ($variable == 'phone_number') {
        return $options['sw_campaign_phone_number'];
    } elseif ($variable == 'site_name') {
        return get_bloginfo( 'name' );
    } else {
        return '';
    }
}

/* Add custom endpoint for receiving SMS messages from SignalWire */
add_action('rest_api_init', 'register_sms_endpoint');
function register_sms_endpoint()
{
    register_rest_route('signalwire-sms/v1', '/receive/', array(
        'methods'  => 'POST',
        'callback' => 'receive_sms',
        'permission_callback' => '__return_true',
    ));
}


/* Check if contact exists */
function check_customer_by_phone( $phone_number ) {
    // Remove the "+1" if it exists
    $phone_number = ltrim( $phone_number, '+1' );

    // Get all customers in the store
    $customers = get_users( [
        'meta_key' => 'billing_phone',
        'meta_value' => $phone_number
    ] );

    // Return the user ID if a customer was found, false otherwise
    return count( $customers ) > 0 ? $customers[0]->ID : false;
}


/* Callback function for receiving SMS messages */
function receive_sms($request)
{
    $params = $request->get_params();

    // Check for errors in the SignalWire response
    if (isset($params['error_code'])) {
        error_log('SignalWire error: ' . $params['error_code']);
        return new WP_Error('signalwire_error', $params['error_code'], array('status' => 400));
    }

    // Verify ProjectID (AccountSid)
    if ($params['AccountSid'] !== sw_variable('account_sid')) {
        error_log('Error: Invalid AccountSid');
        return new WP_Error('invalid_account_sid', 'Invalid AccountSid', array('status' => 400));
    }

    // Verify or Create Contact
    $from = sanitize_text_field($params['From']);
    $contact_id = check_customer_by_phone( $from );
    if (empty($contact_id)) {
        error_log('Error: Invalid Contact ID');
        return new WP_Error('invalid_contact_id', 'Invalid Contact ID', array('status' => 400));
    }

    // Validate query
    $query = sanitize_text_field(strtolower($params['Body']));
    // Check if unsubscribed
    if ($query == 'stop' || $query == 'unsubscribe') {
        update_user_meta( $contact_id, 'sms_order_notifications', '0' );
        send_signalwire_sms($from, 'You have opted out from '.sw_variable('site_name').' order notifications.');
        return new WP_Error('contact_unsubscribed', 'Contact Unsubscribed', array('status' => 400));
        // Check if re-subscribed
    } elseif ($query == 'start' || $query == 'subscribe') {
        update_user_meta( $contact_id, 'sms_order_notifications', '1' );
        send_signalwire_sms($from, 'Thank you for signing up to '.sw_variable('site_name').' Order Notifications! Reply STOP to unsubscribe. Reply HELP for help.');
        return new WP_Error('contact_resubscribed', 'Contact Resubscribed', array('status' => 400));
    }
}


/* Send WooCommerce order status update SMS */
function send_sms_notification( $order_id, $checkout = null ) {
    $order = wc_get_order( $order_id );
    $order_status = $order->get_status();
    // Add opt out message to first status
    if ($order_status === 'pending' || $order_status === 'processing') {
        $opt_out_message = ' To opt out, reply STOP. For help, reply HELP.';
    } else {
        $opt_out_message = '';
    }
    // Check if the sms_order_notifications checkbox is checked
    $user_id = get_current_user_id();
    $sms_order_notifications = get_user_meta( $user_id, 'sms_order_notifications', true );
    $sms_order_notifications = empty( $sms_order_notifications ) ? '0' : $sms_order_notifications;
    if ( ! isset( $sms_order_notifications ) || $sms_order_notifications != '1' ) {
        if( ! isset( $_POST['sms_order_notifications'] ) ) {
            return;
        }
    }
    // Default messages based on order status
    $status_messages = [
        'pending' => 'Your order is being processed.',
        'processing' => 'Your order is being prepared for shipment.',
        'on-hold' => 'Your order has been placed on hold.',
        'completed' => 'Your order has been shipped.',
        'cancelled' => 'Your order has been cancelled.',
        'refunded' => 'Your order has been refunded.',
        'failed' => 'Your order has failed.',
    ];
    $message = isset( $status_messages[$order_status] ) ? $status_messages[$order_status] : 'Your order status has changed.';
   
    // Customize the message
    $site_name = get_bloginfo( 'name' );
    $message = $site_name . ' - Order #' . $order_id . ' update: ' . $message . $opt_out_message;
    // Get customers phone number
    $phone_number = $order->get_billing_phone();
    if ( !empty($phone_number) ) {
        // Remove the "+1" if it exists
        $phone_number = ltrim( $phone_number, '+1' );
        // Then re-add it back. Prevents duplicate +1
        $to = '+1'.$phone_number;
    } else {
        return;
    }
    // Send SMS message
    send_signalwire_sms($to, $message);
}

add_action( 'woocommerce_order_status_changed', 'send_sms_notification', 10, 2 );


/* Function for sending SMS messages using the SignalWire API */
function send_signalwire_sms($to, $message)
{
    // Set up HTTP request to send SMS message
    $args = array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode(sw_variable('project_id') . ':' . sw_variable('auth_token')),
        ),
        'body' => array(
            'From' => sw_variable('phone_number'),
            'To' => $to,
            'Body' => $message,
        ),
    );

    // Send the message
    $response = wp_remote_post(sw_variable('space_url') . '/api/laml/2010-04-01/Accounts/' . sw_variable('project_id') . '/Messages', $args);

    // Check for errors in the HTTP response
    if (is_wp_error($response)) {
        error_log('Error sending SMS: ' . $response->get_error_message());
        return new WP_Error('sms_send_error', $response->get_error_message(), array('status' => 500));
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 201) {
            error_log('Error sending SMS: HTTP response code ' . $response_code);
            return new WP_Error('sms_send_error', 'HTTP response code: ' . $response_code, array('status' => 500));
        }
    }

    return true;
}


// Modify WooCommerce checkout field
function custom_woocommerce_checkout_fields( $fields ) {
    // Change Phone NUmber field label
    $fields['billing']['billing_phone']['label'] = 'Cell Phone Number';
    return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'custom_woocommerce_checkout_fields' );


/**
 * Add custom checkbox field to user profile page (backend)
 */
function add_custom_user_profile_fields( $user ) {
    // Get the current value of the user meta field
    $sms_order_notifications = get_user_meta( $user->ID, 'sms_order_notifications', true );
    ?>
    <h3><?php _e('SMS Order Notifications', 'your_textdomain'); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="sms_order_notifications"><?php _e('Receive SMS Order Notifications', 'your_textdomain'); ?></label></th>
            <td>
                <input type="checkbox" name="sms_order_notifications" id="sms_order_notifications" value="1" <?php checked( $sms_order_notifications, '1' ); ?> />
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'add_custom_user_profile_fields' );
add_action( 'edit_user_profile', 'add_custom_user_profile_fields' );

// Save custom user profile field
function save_custom_user_profile_fields( $user_id ) {
    if ( current_user_can( 'edit_user', $user_id ) ) {
        // Save the value of the checkbox
        update_user_meta( $user_id, 'sms_order_notifications', isset( $_POST['sms_order_notifications'] ) ? '1' : '0' );
    }
}
add_action( 'personal_options_update', 'save_custom_user_profile_fields' );
add_action( 'edit_user_profile_update', 'save_custom_user_profile_fields' );


/**
 * Add custom checkbox field to user account settings in WooCommerce
 */
function add_custom_account_field() {
    $user_id = get_current_user_id();
    $sms_order_notifications = get_user_meta( $user_id, 'sms_order_notifications', true );
    ?>
    <p class="form-row">
    <label class="checkbox" aria-describedby="sms_order_notifications-description">
        <input type="checkbox" name="sms_order_notifications" id="sms_order_notifications" value="1" <?php checked( $sms_order_notifications, '1' ); ?>>
        Please send me order notifications by SMS text message.
        </label>
    </p>
    <?php
}
add_action( 'woocommerce_edit_account_form', 'add_custom_account_field' );

// Save custom account field in WooCommerce
function save_custom_account_field( $user_id ) {
    if ( current_user_can( 'edit_user', $user_id ) ) {
        update_user_meta( $user_id, 'sms_order_notifications', isset( $_POST['sms_order_notifications'] ) ? '1' : '0' );
    }
}
add_action( 'woocommerce_save_account_details', 'save_custom_account_field' );


/**
 * Add custom checkbox field to WooCommerce checkout page
 */
function add_custom_checkout_field() {
    $user_id = get_current_user_id();
    $sms_order_notifications = get_user_meta( $user_id, 'sms_order_notifications', true );
    $sms_order_notifications = empty( $sms_order_notifications ) ? '0' : $sms_order_notifications;
    ?>
    <div class="sms-order-notifications">
        <strong><?php _e('SMS Order Notifications', 'your_textdomain'); ?></strong>
        <p class="form-row form-row-wide">
        <label class="checkbox" aria-describedby="sms_order_notifications-description">
            <input type="checkbox" name="sms_order_notifications" id="sms_order_notifications" value="1" <?php checked( $sms_order_notifications, '1' ); ?>>
            Please send me order notifications by SMS text message.
        </label>
        </p>
    </div>
    <?php
}
add_action( 'woocommerce_review_order_before_submit', 'add_custom_checkout_field' );

// Update the custom checkout field
function update_custom_checkout_field( $order, $data ) {
    $user_id = get_current_user_id();
    update_user_meta( $user_id, 'sms_order_notifications', isset( $_POST['sms_order_notifications'] ) ? '1' : '0' );
}
add_action( 'woocommerce_checkout_create_order', 'update_custom_checkout_field', 10, 2 );