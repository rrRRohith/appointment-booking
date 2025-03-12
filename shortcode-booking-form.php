<?php
if (!defined('ABSPATH')) {
    exit;
}

function send_admin_booking_notification($booking, $booking_id) {
    $admin_email = get_option('appointment_email', get_option('admin_email')); // Get WordPress admin email
    $subject = "New Appointment Booking - Awaiting Approval";
    
    $booking_link = admin_url("admin.php?page=appointment_booking_view&id={$booking_id}");
    
    $message = "<p>Hello Admin,</p>";
    $message .= "<p>A new appointment booking has been submitted and is awaiting approval.</p>";
    
    // Booking Details
    $message .= "<h3>📌 Booking Details:</h3>";
    $message .= "<p><strong>👤 Name:</strong> {$booking['customer_name']}</p>";
    $message .= "<p><strong>📧 Email:</strong> {$booking['email']}</p>";
    $message .= "<p><strong>📞 Phone:</strong> {$booking['phone']}</p>";
    $message .= "<p><strong>📅 Date:</strong> {$booking['date']}</p>";
    $message .= "<p><strong>🕒 Time:</strong> {$booking['time']}</p>";
    $message .= "<p><strong>⏳ Duration:</strong> {$booking['duration']} minutes</p>";
    $message .= "<p><strong>💰 Amount:</strong> $" . number_format($booking['amount'], 2) . "</p>";
    $message .= "<p><strong>📨 Message:</strong> " . nl2br(esc_html($booking['message'])) . "</p>";
    $message .= "<p><strong>🕗 Booking Created At:</strong> " . current_time('mysql') . "</p>";

    // Buttons for Approve & Reject
    $message .= "<p></p>";
    $message .= "<a href='{$booking_link}&action=approve' style='
        display: inline-block;
        background-color: #28a745;
        color: white;
        padding: 10px 15px;
        text-decoration: none;
        border-radius: 5px;
        margin-right: 10px;
        font-weight: bold;'>✅ Approve</a>";

    $message .= "<a href='{$booking_link}&action=reject' style='
        display: inline-block;
        background-color: #dc3545;
        color: white;
        padding: 10px 15px;
        text-decoration: none;
        border-radius: 5px;
        font-weight: bold;'>❌ Reject</a>";


    // Email headers
    $headers = ['Content-Type: text/html; charset=UTF-8', 'From: Focz <noreply@focz.ca>'];

    
    // Send the email
    wp_mail($admin_email, $subject, $message, $headers);
}

function get_available_slots($user_email, $date) {
    $token = get_graph_access_token();
    if (!$token) return [];

    // Load settings from WordPress options
    $slot_duration = get_option('appointment_slot_duration', 30); // Default: 30 mins
    $start_time = get_option('appointment_start_time', '09:00'); // Default: 09:00 AM
    $end_time = get_option('appointment_end_time', '18:00'); // Default: 06:00 PM

    // Convert start & end time to UTC format
    $start_time_utc = "{$date}T" . $start_time . ":00";
    $end_time_utc = "{$date}T" . $end_time . ":00";

    $url = "https://graph.microsoft.com/v1.0/users/$user_email/calendar/getSchedule";

    $body = json_encode([
        'schedules' => [$user_email],
        'startTime' => ['dateTime' => $start_time_utc, 'timeZone' => 'UTC'],
        'endTime' => ['dateTime' => $end_time_utc, 'timeZone' => 'UTC'],
        'availabilityViewInterval' => $slot_duration
    ]);

    $response = wp_remote_post($url, [
        'body' => $body,
        'headers' => [
            'Authorization' => "Bearer $token",
            'Content-Type'  => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!isset($data['value'][0]['availabilityView'])) {
        return [];
    }

    return generate_slots_from_availability($data['value'][0]['availabilityView'], $slot_duration, $start_time);
}

function get_graph_access_token() {
    $current_token = get_option('ms_graph_access_token', '');
    $token_expires = get_option('ms_graph_token_expires', 0);

    // Check if token is valid
    if ($current_token && $token_expires > time()) {
        return $current_token;
    }

    // Fetch credentials from settings
    $tenant_id = get_option('ms_tenant_id', '');
    $client_id = get_option('ms_client_id', '');
    $client_secret = get_option('ms_client_secret', '');

    $url = "https://login.microsoftonline.com/$tenant_id/oauth2/v2.0/token";
    $body = [
        'grant_type' => 'client_credentials',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'scope' => 'https://graph.microsoft.com/.default'
    ];

    $response = wp_remote_post($url, [
        'body' => $body,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['access_token'], $data['expires_in'])) {
        $expires_in = intval($data['expires_in']); // Typically 3600 seconds (1 hour)
        $expiration_time = time() + $expires_in - 300; // Store 5 mins earlier to be safe

        // Store token and expiry time
        update_option('ms_graph_access_token', $data['access_token']);
        update_option('ms_graph_token_expires', $expiration_time);

        return $data['access_token'];
    }

    return false;
}


function generate_slots_from_availability($availabilityView, $interval, $start_time) {
    $slots = [];

    // Convert start time (HH:MM) into minutes
    list($start_hour, $start_min) = explode(':', $start_time);
    $start_time_minutes = ($start_hour * 60) + $start_min;

    for ($i = 0; $i < strlen($availabilityView); $i++) {
        if ($availabilityView[$i] === '0') { // '0' means available
            $slot_minutes = $start_time_minutes + ($i * $interval);
            $hour = floor($slot_minutes / 60);
            $minute = $slot_minutes % 60;
            $slots[] = sprintf('%02d:%02d', $hour, $minute);
        }
    }

    return $slots;
}


function appointment_booking_get_slots() {
    //check_ajax_referer('appointment_booking_nonce', 'nonce');

    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $slot_duration = get_option('appointment_slot_duration', '30');
    $user_email = get_option('ms_graph_user_email', '');
    // Generate available slots dynamically
    $slots = get_available_slots($user_email, $date, $slot_duration);
    

    wp_send_json_success($slots);
}
add_action('wp_ajax_get_appointment_slots', 'appointment_booking_get_slots');
add_action('wp_ajax_nopriv_get_appointment_slots', 'appointment_booking_get_slots');

function appointment_booking_save() {
    global $wpdb;

    //check_ajax_referer('appointment_booking_nonce', 'nonce');

    // Sanitize and validate input data
    $customer_name = sanitize_text_field($_POST['customer_name']);
    $email = sanitize_email($_POST['email']);
    $phone = sanitize_text_field($_POST['phone']);
    $date = sanitize_text_field($_POST['date']);
    $time = sanitize_text_field($_POST['time']);
    $message = sanitize_textarea_field($_POST['message']);
    $amount = get_option('appointment_amount', '0');
    $duration = get_option('appointment_slot_duration', '30');
    
    if (empty($customer_name) || empty($email) || empty($phone) || empty($date) || empty($time)) {
        wp_send_json_error(['message' => 'Please fill in all required fields.']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        wp_send_json_error(['message' => 'Invalid email address.']);
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        wp_send_json_error(['message' => 'Invalid phone number.']);
    }

    // Insert into database
    $table_name = $wpdb->prefix . 'appointment_bookings';
    $wpdb->insert($table_name, [
        'customer_name' => $customer_name,
        'email' => $email,
        'phone' => $phone,
        'date' => $date,
        'time' => $time,
        'amount' => $amount,
        'duration' => $duration,
        'status' => 'pending',
        'created_at' => current_time('mysql'),
    ]);
    
    $booking_id = $wpdb->insert_id; // Get the newly created booking ID

    // Send admin email with action buttons
    send_admin_booking_notification([
        'customer_name' => $customer_name,
        'email' => $email,
        'phone' => $phone,
        'date' => $date,
        'time' => $time,
        'duration' => $duration,
        'amount' => $amount,
        'message' => $message,
    ], $booking_id);

    wp_send_json_success(['message' => 'Appointment booked successfully!']);
}
add_action('wp_ajax_appointment_booking_save', 'appointment_booking_save');
add_action('wp_ajax_nopriv_appointment_booking_save', 'appointment_booking_save');


// Enqueue necessary scripts
function appointment_booking_enqueue_scripts() {
    wp_enqueue_script('jquery'); // Ensure jQuery is loaded
    wp_enqueue_script('appointment-booking-js', plugin_dir_url(__FILE__) . 'appointment-booking.js', ['jquery'], null, true);
    
    wp_localize_script('appointment-booking-js', 'appointmentBookingData', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'slot_duration' => get_option('appointment_slot_duration', '30'),
        'amount' => get_option('appointment_amount', '0'),
    ]);
}
add_action('wp_enqueue_scripts', 'appointment_booking_enqueue_scripts');

// Shortcode function
function appointment_booking_form() {
    ob_start();
    $amount = get_option('appointment_amount', '0');
    $slot_duration = get_option('appointment_slot_duration', '30');
    ?>
    
    <div class="max-w-lg mx-auto bg-white shadow-lg rounded-lg p-6">
        <h2 class="text-xl font-semibold text-center mb-4">Book Your Appointment</h2>
        <form id="appointment-booking-form" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                <label class="block font-medium">Name</label>
                <input type="text" name="customer_name" class="w-full border p-2" placeholder="Enter your name" required>
            </div>
            <div>
                <label class="block font-medium">Email</label>
                <input type="email" name="email" class="w-full border p-2" placeholder="Enter your email" required>
            </div>
            <div>
                <label class="block font-medium">Phone</label>
                <input type="text" name="phone" class="w-full border p-2" placeholder="Enter your phone number" required>
            </div>
            </div>
            <div>
                <label class="block font-medium">Message</label>
                <textarea name="message" class="w-full border p-2" placeholder="Enter any additional notes"></textarea>
            </div>
            <div>
                <label class="block font-medium">Booking Amount</label>
                <p class="text-lg font-bold">$<?php echo esc_html($amount); ?></p>
            </div>
            <div>
                <label class="block font-medium">Slot Duration</label>
                <p class="text-lg font-bold"><?php echo esc_html($slot_duration); ?> minutes</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block font-medium">Select Date</label>
                <input type="date" id="appointment-date" name="date" class="w-full border p-2" required>
            </div>
            <div>
                <label class="block font-medium">Select Time Slot</label>
                <select id="appointment-time" name="time" class="w-full border p-2" disabled>
                    <option value="">Select a date first</option>
                </select>
            </div>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 hover:bg-blue-700 transition">
                Book Appointment
            </button>
        </form>
        
        <div id="appointment-message" class="mt-4"></div>
    </div>

    <?php
    return ob_get_clean();
}
add_shortcode('appointment_booking', 'appointment_booking_form');

