<?php
/**
 * Plugin Name: Notifications Toolkit
 * Description: A plugin that adds hooks for frontend notifications.
 * Version: 1.0
 * Author: Maxime Moraine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Create the notifications table on plugin activation.
 */
function wp_notifications_create_table() {
    global $wpdb;
    
    // Table name with dynamic prefix
    $table_name = $wpdb->prefix . 'notifications';

    // Charset and collation
    $charset_collate = $wpdb->get_charset_collate();

    // SQL query to create the table
    $sql = "CREATE TABLE $table_name (
      id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id bigint(20) UNSIGNED DEFAULT NULL,
      session_id varchar(255) DEFAULT NULL,
      message text NOT NULL,
      fadeout varchar(20) DEFAULT '5',
      delete_after_read tinyint(1) DEFAULT 0,
      created_at datetime DEFAULT CURRENT_TIMESTAMP,
      is_read tinyint(1) DEFAULT 0,
      PRIMARY KEY  (id),
      KEY user_id (user_id),
      KEY session_id (session_id)
    ) $charset_collate;";

    // Include the upgrade.php file to access dbDelta
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    // Execute the SQL query to create the table
    dbDelta( $sql );
}

// Register the activation hook to create the table
register_activation_hook( __FILE__, 'wp_notifications_create_table' );

// Enqueue assets
function wp_notification_enqueue_assets() {
    // Enqueue the JS
    wp_enqueue_script( 'notifications-toolkit-js', plugin_dir_url( __FILE__ ) . 'assets/notifications.js', array( 'jquery' ), '1.0', true );

    // Enqueue the compiled CSS
    wp_enqueue_style( 'notifications-toolkit-css', plugin_dir_url( __FILE__ ) . 'dist/notifications.css' );

    // Localize script with PHP data
    wp_localize_script( 'notifications-toolkit-js', 'wpNotification', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'wp_notification_nonce' ),
    ));
}
add_action( 'wp_enqueue_scripts', 'wp_notification_enqueue_assets' );

// Hook to add notification for a user
function wp_add_notification( $message, $options = array() ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'notifications';
    
    $defaults = array(
        'fadeout'          => 5,  // Default fadeout in seconds
        'user_id'          => get_current_user_id(),  // Current user
        'delete_after_read' => false,  // Default: don't delete, just mark as read
    );
    
    $options = wp_parse_args( $options, $defaults );

    // Insert into the notifications table
    $wpdb->insert(
        $table_name,
        array(
            'user_id'           => $options['user_id'],
            'message'           => $message,
            'fadeout'           => $options['fadeout'],
            'delete_after_read' => $options['delete_after_read'] ? 1 : 0,  // Save as integer (1 or 0)
        ),
        array( '%d', '%s', '%s', '%d' )
    );
}


// Hook to add notification for anonymous user
function wp_add_guest_notification( $message, $options = array() ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'notifications';

    // Generate or retrieve session ID (can use PHP session or a cookie)
    if ( ! isset( $_COOKIE['wp_notification_session'] ) ) {
        $session_id = uniqid( 'session_', true );
        setcookie( 'wp_notification_session', $session_id, time() + ( 3600 ), "/" );  // 1-hour session
    } else {
        $session_id = sanitize_text_field( $_COOKIE['wp_notification_session'] );
    }

    $defaults = array(
        'fadeout'           => 5,  // Default fadeout in seconds
        'delete_after_read' => true,  // Default: delete guest notifications when read
    );
    
    $options = wp_parse_args( $options, $defaults );

    // Insert into the notifications table for guest users
    $wpdb->insert(
        $table_name,
        array(
            'session_id'        => $session_id,
            'message'           => $message,
            'fadeout'           => $options['fadeout'],
            'delete_after_read' => $options['delete_after_read'] ? 1 : 0,  // Save as integer (1 or 0)
        ),
        array( '%s', '%s', '%s' )
    );
}

function wp_get_user_notifications() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'notifications';

    $user_id = get_current_user_id();
    
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND is_read = 0",
        $user_id
    ));

    return $results;
}

function wp_get_guest_notifications() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'notifications';

    if ( isset( $_COOKIE['wp_notification_session'] ) ) {
        $session_id = sanitize_text_field( $_COOKIE['wp_notification_session'] );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s AND is_read = 0",
            $session_id
        ));

        return $results;
    }

    return array();  // No notifications for guests without session ID
}

// Display notifications via AJAX
function wp_get_notifications() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wp_notification_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
        wp_die();
    }

    // Fetch notifications for the current user or guest
    if ( is_user_logged_in() ) {
        $notifications = wp_get_user_notifications();
    } else {
        $notifications = wp_get_guest_notifications();
    }

    if ( ! empty( $notifications ) ) {
        // Begin the notification tray HTML
        $output = '<div class="notifications-tray">';

        foreach ( $notifications as $notification ) {
            $output .= '<div class="notification" data-id="' . esc_attr( $notification->id ) . '" data-fadeout="' . esc_attr( $notification->fadeout ) . '">';
            $output .= esc_html( $notification->message );
            $output .= '</div>';
        }

        // Close the notification tray
        $output .= '</div>';

        echo $output;
    } else {
        echo ''; // No notifications
    }

    wp_die(); // Terminate the AJAX request
}
add_action( 'wp_ajax_get_notifications', 'wp_get_notifications' );
add_action( 'wp_ajax_nopriv_get_notifications', 'wp_get_notifications' );

// Mark notification as read or delete
function wp_mark_notification_as_read() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wp_notification_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
        wp_die();
    }

    if ( ! isset( $_POST['notification_id'] ) ) {
        wp_send_json_error( array( 'message' => 'Notification ID is required.' ) );
        wp_die();
    }

    $notification_id = intval( $_POST['notification_id'] );

    global $wpdb;
    $table_name = $wpdb->prefix . 'notifications';

    // Get the notification data
    $notification = $wpdb->get_row( $wpdb->prepare(
        "SELECT delete_after_read FROM $table_name WHERE id = %d",
        $notification_id
    ));

    if ( ! $notification ) {
        wp_send_json_error( array( 'message' => 'Notification not found.' ) );
        wp_die();
    }

    if ( $notification->delete_after_read ) {
        // Delete the notification if 'delete_after_read' is true
        $wpdb->delete(
            $table_name,
            array( 'id' => $notification_id ),
            array( '%d' )
        );
    } else {
        // Otherwise, just mark it as read
        $wpdb->update(
            $table_name,
            array( 'is_read' => 1 ),
            array( 'id' => $notification_id ),
            array( '%d' ),
            array( '%d' )
        );
    }

    wp_send_json_success( array( 'message' => 'Notification marked as read or deleted.' ) );
    wp_die(); // Always terminate the AJAX request
}
add_action( 'wp_ajax_mark_notification_as_read', 'wp_mark_notification_as_read' );
add_action( 'wp_ajax_nopriv_mark_notification_as_read', 'wp_mark_notification_as_read' );