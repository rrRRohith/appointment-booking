<?php
/**
 * Plugin Name: Appointment Booking
 * Description: A simple appointment booking plugin with slot duration settings.
 * Version: 1.0.0
 * Author: Indigital Group
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once plugin_dir_path(__FILE__) . 'settings-handler.php';
require_once plugin_dir_path(__FILE__) . 'shortcode-booking-form.php';
wp_enqueue_style('tailwind-css', 'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css');

class AppointmentBooking {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    public function add_admin_menu() {
        // Main menu
        add_menu_page('Appointments', 'Appointments', 'manage_options', 'appointment_booking', [$this, 'booking_list_page'], 'dashicons-calendar-alt', 26);

        // Submenu - Appointments List
        add_submenu_page('appointment_booking', 'Appointments', 'Appointments', 'manage_options', 'appointment_booking', [$this, 'booking_list_page']);

        // Submenu - View Booking (Hidden)
        add_submenu_page('appointment_booking', 'View Appointment', null, 'manage_options', 'appointment_booking_view', [$this, 'booking_view_page']);

        // Submenu - Settings
        add_submenu_page('appointment_booking', 'Settings', 'Settings', 'manage_options', 'appointment_booking_settings', [$this, 'settings_page']);
    }

    public function booking_list_page() {
        include plugin_dir_path(__FILE__) . 'admin-booking-list.php';
    }

    public function booking_view_page() {
        include plugin_dir_path(__FILE__) . 'admin-booking-view.php';
    }

    public function settings_page() {
        include plugin_dir_path(__FILE__) . 'settings-page.php';
    }
}


new AppointmentBooking();


register_activation_hook(__FILE__, 'appointment_booking_create_tables');

function appointment_booking_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $booking_table = $wpdb->prefix . 'appointment_bookings';
    $transaction_table = $wpdb->prefix . 'appointment_transactions';

    $sql = "
    CREATE TABLE $booking_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        date DATE NOT NULL,
        time TIME NOT NULL,
        ms_event_id VARCHAR(255) NULL,  -- Fixed NULLABLE issue
        duration INT(11) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status ENUM('pending', 'confirmed', 'rejected', 'cancelled') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate ENGINE=InnoDB;

    CREATE TABLE $transaction_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        booking_id BIGINT(20) UNSIGNED NOT NULL,
        payment_id VARCHAR(255) NOT NULL,
        status ENUM('pending', 'paid', 'failed') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_booking FOREIGN KEY (booking_id) REFERENCES $booking_table(id) ON DELETE CASCADE
    ) $charset_collate ENGINE=InnoDB;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}



add_action('wp_ajax_update_booking_status', 'update_booking_status');

function update_booking_status() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized.']);
    }

    global $wpdb;
    $booking_table = $wpdb->prefix . 'appointment_bookings';

    $booking_id = intval($_POST['id']);
    $new_status = sanitize_text_field($_POST['status']);

    if (!$booking_id || !in_array($new_status, ['confirmed', 'rejected', 'cancelled'])) {
        wp_send_json_error(['message' => 'Invalid request.']);
    }

    // Fetch booking details
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $booking_table WHERE id = %d", $booking_id), ARRAY_A);

    if (!$booking) {
        wp_send_json_error(['message' => 'Booking not found.']);
    }

    // Update status in the database
    $wpdb->update(
        $booking_table,
        ['status' => $new_status],
        ['id' => $booking_id],
        ['%s'],
        ['%d']
    );

    // Handle Microsoft Calendar actions
    if ($new_status === 'confirmed') {
        $event_id = create_ms_calendar_event($booking);
        if ($event_id) {
            $wpdb->update(
                $booking_table,
                ['ms_event_id' => $event_id],
                ['id' => $booking_id],
                ['%s'],
                ['%d']
            );
        }
    } elseif ($new_status === 'cancelled' && !empty($booking['ms_event_id'])) {
        delete_ms_calendar_event($booking['ms_event_id']);
    }

    // Send email to the customer
    send_booking_status_email($booking, $new_status);

    wp_send_json_success(['message' => 'Booking status updated successfully.']);
}


function send_booking_status_email($booking, $status) {
    $to = $booking->email;
    $subject = "Your Appointment Status Update";

    // Base email message
    $message = "Hello {$booking->customer_name},\n\n";
    
    switch ($status) {
        case 'confirmed':
            $message .= "Your appointment on {$booking->date} at {$booking->time} has been confirmed.\n\n";
            break;
        case 'rejected':
            $message .= "Unfortunately, your appointment on {$booking->date} at {$booking->time} has been rejected.\n\n";
            break;
        case 'cancelled':
            $message .= "Your appointment on {$booking->date} at {$booking->time} has been cancelled.\n\n";
            break;
        default:
            return;
    }

    // Add detailed booking information
    $message .= "Here are your booking details:\n";
    $message .= "-----------------------------------\n";
    $message .= "📅 Date: {$booking->date}\n";
    $message .= "🕒 Time: {$booking->time}\n";
    $message .= "⏳ Duration: {$booking->duration} minutes\n";
    $message .= "💰 Amount: $" . number_format($booking->amount, 2) . "\n";
    $message .= "📌 Status: " . ucfirst($new_status) . "\n";
    $message .= "📨 Message: " . strip_tags($booking->message) . "\n";
    $message .= "🕗 Booking Created At: {$booking->created_at}\n";
    $message .= "💳 Payment Status: " . (($booking->payment_status === 'paid') ? '✅ Paid' : '❌ Not Paid') . "\n";

    if (!empty($booking->payment_id)) {
        $message .= "🔢 Transaction ID: {$booking->payment_id}\n";
        $message .= "💳 Payment Method: " . ucfirst($booking->payment_method) . "\n";
    } else {
        $message .= "⚠️ No transaction details available\n";
    }

    $message .= "-----------------------------------\n";
    $message .= "Thank you for using our service!\n";
    $message .= "📞 Contact us if you have any questions.\n";

    // Email headers
    $headers = ['Content-Type: text/plain; charset=UTF-8', 'From: Focz <noreply@focz.ca>'];
    
    // Send the email
    wp_mail($to, $subject, $message, $headers);
}


function create_ms_calendar_event($booking) {
    $token = get_graph_access_token();
    if (!$token) return false;

    $user_email = get_option('ms_graph_user_email', '');

    // Format start time correctly
    $start_time = "{$booking['date']}T{$booking['time']}"; // Ensure correct format
    $end_time = date('Y-m-d\TH:i:s', strtotime("+{$booking['duration']} minutes", strtotime("{$booking['date']} {$booking['time']}")));

    $event_data = [
        'subject' => "Appointment with {$booking['customer_name']}",
        'body' => [
            'contentType' => 'HTML',
            'content' => "Appointment with {$booking['customer_name']} has been confirmed."
        ],
        'start' => [
            'dateTime' => $start_time,
            'timeZone' => 'UTC'
        ],
        'end' => [
            'dateTime' => $end_time,
            'timeZone' => 'UTC'
        ],
        // 'attendees' => [
        //     [
        //         'emailAddress' => ['address' => $booking['email'], 'name' => $booking['customer_name']],
        //         'type' => 'required'
        //     ]
        // ],
        // 'responseRequested' => false, 
        // 'hideAttendees' => true 
    ];

    $response = wp_remote_post("https://graph.microsoft.com/v1.0/users/$user_email/calendar/events", [
        'body' => json_encode($event_data),
        'headers' => [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ]
    ]);


    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['id'] ?? false;
}



function delete_ms_calendar_event($event_id) {
    $token = get_graph_access_token();
    if (!$token) return false;

    $user_email = get_option('ms_graph_user_email', '');
    $url = "https://graph.microsoft.com/v1.0/users/$user_email/calendar/events/$event_id";

    $response = wp_remote_request($url, [
        'method' => 'DELETE',
        'headers' => [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ]
    ]);

    return !is_wp_error($response);
}

