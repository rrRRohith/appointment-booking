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

    $sql = "
    CREATE TABLE $booking_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        message TEXT NULL,
        date DATE NOT NULL,
        time TIME NOT NULL,
        ms_event_id VARCHAR(255) NULL,  -- Fixed NULLABLE issue
        duration INT(11) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status ENUM('pending', 'confirmed', 'rejected', 'cancelled') DEFAULT 'pending',
        type ENUM('online', 'in person') DEFAULT 'online',
        payment_id VARCHAR(255) NOT NULL,
        payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
    $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*
            FROM $booking_table b
            WHERE b.id = %d
        ", $booking_id));
        
    // Send email to the customer
    send_booking_status_email($booking);

    wp_send_json_success(['message' => 'Booking status updated successfully.']);
}


function send_booking_status_email($booking) {
    $to = $booking->email;
    $subject = "Your Appointment Status Update";

    // Base email message
    $message = "Hello {$booking->customer_name}, <br>";
    
    $formatted_date = date_i18n('F j Y', strtotime($booking->date));
    $formatted_time = date('g:ia', strtotime($booking->time));
    $duration = formatMinutes($booking->duration);
    
    switch ($booking->status) {
        case 'pending':
            $message .= "Your appointment request on {$formatted_date} at {$formatted_time} has been received, you will be notified once your appointment is aproved.";
            break;
        case 'confirmed':
            $message .= "Your appointment is all set for {$formatted_date} at {$formatted_time}. Looking forward to seeing you .";
            break;
        case 'rejected':
            $message .= "Unfortunately, your appointment on {$formatted_date} at {$formatted_time} has been rejected. Please contact us for more info.";
            break;
        case 'cancelled':
            $message .= "Your appointment on {$formatted_date} at {$formatted_time} has been cancelled. If this was a mistake, please contact us immediately.";
            break;
        default:
            return;
    }

    // Add detailed booking information
    $message .= "<p><strong>Booking Details</p>";
    $message .= "<p><strong>Date:</strong> {$formatted_date}</p>";
    $message .= "<p><strong>Time:</strong> {$formatted_time}</p>";
    $message .= "<p><strong>Duration:</strong> {$duration}</p>";
    $message .= "<p><strong>Type:</strong> {$booking->type}</p>";
    $message .= "<p><strong>Amount:</strong> $" . number_format($booking->amount, 2) . "</p>";
    $message .= "<p><strong>Payment Status: </strong>" . (($booking->payment_status === 'paid') ? 'Paid' : 'Not Paid') . "</p>";

    if (!empty($booking->payment_id)) {
        $message .= "<p><strong>Transaction ID: {$booking->payment_id}</p>";
        $message .= "<p><strong>Payment Method: Stripe payment </p>";
    } else {
        $message .= "<p><strong>No transaction details available</p>";
    }

    $template_path = __DIR__ . '/email.html';
    $template = file_exists($template_path) ? file_get_contents($template_path) : '{content}';
    
    // Replace placeholders
    $template = str_replace(['{content}'], [$message], $template);

    // Email headers
    $headers = ['Content-Type: text/html; charset=UTF-8', 'From: Focz <noreply@focz.ca>'];
    
    // Send the email
    wp_mail($to, $subject, $template, $headers);
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


function appointment_payment_success() {

    global $wpdb;
    
    if(!$_GET['payment_success']){
        return false;
    }

    $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
    $booking_table = $wpdb->prefix . 'appointment_bookings';
    $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*
            FROM $booking_table b
            WHERE b.id = %d
        ", $booking_id));
        

    if (!$booking_id || !$session_id || !$booking) {
        wp_die('Invalid payment details.');
    }

    require_once 'vendor/autoload.php';
    \Stripe\Stripe::setApiKey(get_option('stripe_secret'));

    try {
        $session = \Stripe\Checkout\Session::retrieve($session_id);
        if ($session->payment_status === 'paid') {

            // Insert transaction record
            $wpdb->update(
                $booking_table,
                [
                    'payment_status' => 'paid',
                    'payment_id' => $session->payment_intent // Ensure 'payment_id' exists in your DB schema
                ],
                ['id' => $booking_id],
                ['%s', '%s'],
                ['%d']
            );
            
            $booking = $wpdb->get_row($wpdb->prepare("
                SELECT b.*
                FROM $booking_table b
                WHERE b.id = %d
            ", $booking_id));
        
            send_admin_booking_notification($booking);
            send_booking_status_email($booking);

            wp_redirect(site_url("/appointment?status=paid&booking_id={$booking_id}"));
            exit;
        }
    } catch (Exception $e) {
        wp_die('Payment verification failed.');
    }
}

