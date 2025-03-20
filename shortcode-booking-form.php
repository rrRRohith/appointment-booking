<?php
if (!defined('ABSPATH')) {
    exit;
}

function send_admin_booking_notification($booking) {
    $admin_email = get_option('appointment_email', get_option('admin_email')); // Get WordPress admin email
    $subject = "New Appointment Booking - Awaiting Approval";
    
    $booking_link = admin_url("admin.php?page=appointment_booking_view&id={$booking->id}");
    
    $formatted_date = date_i18n('F j Y', strtotime($booking->date));
    $formatted_time = date('g:ia', strtotime($booking->time));
    $duration = formatMinutes($booking->duration);
    
    $message = "<p>Hello Admin,</p>";
    $message .= "<p>A new appointment booking has been submitted and is awaiting approval.</p>";
    
    
    // Booking Details
    $message .= "<p><strong>Booking Details</p>";
    $message .= "<p><strong>Name:</strong> {$booking->customer_name}</p>";
    $message .= "<p><strong>Email:</strong> {$booking->email}</p>";
    $message .= "<p><strong>Phone:</strong> {$booking->phone}</p>";
    $message .= "<p><strong>Date:</strong> {$formatted_date}</p>";
    $message .= "<p><strong>Time:</strong> {$formatted_time}</p>";
    $message .= "<p><strong>Duration:</strong> {$duration}</p>";
    $message .= "<p><strong>Type:</strong> {$booking->type}</p>";
    $message .= "<p><strong>Amount:</strong> $" . number_format($booking->amount, 2) . "</p>";
    $message .= "<p><strong>Message:</strong> " . nl2br(esc_html($booking->message)) . "</p>";
    $message .= "<p><strong>Booking Created At:</strong> " . current_time('mysql') . "</p>";
    $message .= "<p><strong>Payment Status: </strong>" . (($booking->payment_status === 'paid') ? 'Paid' : 'Not Paid') . "</p>";

    if (!empty($booking->payment_id)) {
        $message .= "<p><strong>Transaction ID: {$booking->payment_id}</p>";
        $message .= "<p><strong>Payment Method: Stripe payment </p>";
    } else {
        $message .= "<p><strong>No transaction details available</p>";
    }

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
        font-weight: bold;'>Approve</a>";

    $message .= "<a href='{$booking_link}&action=reject' style='
        display: inline-block;
        background-color: #dc3545;
        color: white;
        padding: 10px 15px;
        text-decoration: none;
        border-radius: 5px;
        font-weight: bold;'>Reject</a>";


    // Email headers
    $template_path = __DIR__ . '/email.html';
    $template = file_exists($template_path) ? file_get_contents($template_path) : '{content}';
    
    // Replace placeholders
    $template = str_replace(['{content}'], [$message], $template);

    // Email headers
    $headers = ['Content-Type: text/html; charset=UTF-8', 'From: Focz <noreply@focz.ca>'];
    
    // Send the email
    
    // Send the email
    wp_mail($admin_email, $subject, $template, $headers);
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


// function generate_slots_from_availability($availabilityView, $interval, $start_time) {
//     $slots = [];

//     // Convert start time (HH:MM) into minutes
//     list($start_hour, $start_min) = explode(':', $start_time);
//     $start_time_minutes = ($start_hour * 60) + $start_min;

//     for ($i = 0; $i < strlen($availabilityView); $i++) {
//         if ($availabilityView[$i] === '0') { // '0' means available
//             $slot_minutes = $start_time_minutes + ($i * $interval);
//             $hour = floor($slot_minutes / 60);
//             $minute = $slot_minutes % 60;
//             $slots[] = sprintf('%02d:%02d', $hour, $minute);
//         }
//     }

//     return $slots;
// }

function generate_slots_from_availability($availabilityView, $interval, $start_time) {
    $slots = [];

    // Convert start time (HH:MM) into minutes
    list($start_hour, $start_min) = explode(':', $start_time);
    $start_time_minutes = ($start_hour * 60) + $start_min;

    for ($i = 0; $i < strlen($availabilityView); $i++) {
        if ($availabilityView[$i] === '0') { // '0' means available
            $slot_start_minutes = $start_time_minutes + ($i * $interval);
            $slot_end_minutes = $slot_start_minutes + $interval;

            $start_hour = floor($slot_start_minutes / 60);
            $start_minute = $slot_start_minutes % 60;

            $end_hour = floor($slot_end_minutes / 60);
            $end_minute = $slot_end_minutes % 60;

            // Convert to 12-hour format
            $start_time_formatted = date("g:i A", strtotime(sprintf('%02d:%02d', $start_hour, $start_minute)));
            $end_time_formatted = date("g:i A", strtotime(sprintf('%02d:%02d', $end_hour, $end_minute)));

            // Store as key-value pair: "start_time" => "start - end"
            $slots[$start_time_formatted] = "$start_time_formatted - $end_time_formatted";
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
    $type = sanitize_textarea_field($_POST['type'] ?? 'online');
    //$amount = get_option('appointment_amount', '0');
    $amount = isset($_POST['amount']) ? intval(sanitize_text_field($_POST['amount'])) : 0;
    $duration = get_option('appointment_slot_duration', '30');
    
    if (empty($customer_name) || empty($email) || empty($phone) || empty($date) || empty($time) || empty($amount)) {
        wp_send_json_error(['message' => 'Please fill in all required fields.']);
    }
    
    if ($type != 'online' && $type != 'in person') {
        wp_send_json_error(['message' => 'Invalid appointment type.']);
    }
    
    if ($amount < 1) {
        wp_send_json_error(['message' => 'Invalid booking amount.']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        wp_send_json_error(['message' => 'Invalid email address.']);
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        wp_send_json_error(['message' => 'Invalid phone number.']);
    }
    
    $recaptcha_secret = get_option('recaptcha_secret_key');
    $recaptcha_response = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
    
    if (empty($recaptcha_response)) {
        wp_send_json_error(['message' => 'reCAPTCHA verification failed. Please try again.']);
    }
    
    $verify_response = wp_remote_get("https://www.google.com/recaptcha/api/siteverify?secret={$recaptcha_secret}&response={$recaptcha_response}");
    $verify_data = json_decode(wp_remote_retrieve_body($verify_response), true);
    
    if (!$verify_data['success']) {
        wp_send_json_error(['message' => 'reCAPTCHA verification failed. Please try again.']);
    }

    // Insert into database
    $booking_table = $wpdb->prefix . 'appointment_bookings';
    
    $wpdb->insert($booking_table, [
        'customer_name' => $customer_name,
        'email' => $email,
        'phone' => $phone,
        'message' => $message,
        'date' => $date,
        'time' => $time,
        'amount' => $amount,
        'type' => $type,
        'duration' => $duration,
        'status' => 'pending',
        'created_at' => current_time('mysql'),
    ]);
    
    $booking_id = $wpdb->insert_id; // Get the newly created booking ID
    
    if ($amount > 0) {
        require_once 'vendor/autoload.php'; // Load Stripe SDK

        \Stripe\Stripe::setApiKey(get_option('stripe_secret'));

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'cad',
                    'product_data' => [
                        'name' => "Appointment Booking for $customer_name",
                    ],
                    'unit_amount' => $amount * 100, // Stripe accepts amount in cents
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => site_url("/appointment?booking_id=$booking_id&payment_success=true&session_id={CHECKOUT_SESSION_ID}"),
            'cancel_url' => site_url("/appointment?status=cancelled&booking_id={$booking_id}"),
            'metadata' => [
                'booking_id' => $booking_id,
                'email' => $email,
            ]
        ]);

        wp_send_json_success(['redirect_url' => $session->url]);
    }
    
    $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*
            FROM $booking_table b
            WHERE b.id = %d
        ", $booking_id));

    // Send admin email with action buttons
    send_admin_booking_notification($booking);
    wp_send_json_success(['message' => 'Appointment booked successfully!, you will be notified once your appointment is confirmed.']);
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
    if($_GET['payment_success'] ?? false){
        return appointment_payment_success();
    }
    if($_GET['booking_id'] ?? false){
        global $wpdb;
        $booking_table = $wpdb->prefix . 'appointment_bookings';
        $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
        // Fetch booking details
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*
            FROM $booking_table b
            WHERE b.id = %d
        ", $booking_id)); 
        
        if($booking){
             if($_GET['status'] == 'cancelled'){
                ?>
                 <div class="max-w-2xl mx-auto">
                     <div class="p-4 mb-4 text-sm text-yellow-800 rounded-lg bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300" role="alert">
                      <span class="font-medium">Oh no!</span> It seems like your payment was not completed, but don’t worry, you can book a new appointment.
                    </div>
                 </div>
                <?php
            }else if($_GET['status'] == 'paid'){
                ?>
                <div class="max-w-3xl mx-auto">
                     <div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-300" role="alert">
                      <span class="font-medium">Hurray!</span> Your appointment has been created. We have sent you an email with your booking details. <br> 
                      You will receive another email once your appointment is confirmed. <br> 
                      Thank you.
                    </div>

                 </div>
                <?php
                return;
            }
        }
    }
   
    $amount = get_option('appointment_amount', '0');
    $slot_duration = get_option('appointment_slot_duration', '30');
    ?>
    
    <div class="max-w-lg mx-auto bg-white shadow-lg rounded-lg p-6">
        <h2 class="text-xl font-semibold text-center mb-4">Book Your Appointment</h2>
        <form id="appointment-booking-form" class="space-y-0">
            <div class="grid grid-cols-1">
                <div>
                <label class="block font-medium">Full name</label>
                <input type="text" name="customer_name" class="w-full border p-2 !mb-2" placeholder="Enter your full name" required>
            </div>
            <div>
                <label class="block font-medium">Email</label>
                <input type="email" name="email" class="w-full border p-2 !mb-2" placeholder="Enter your email address" required>
            </div>
            <div>
                <label class="block font-medium">Phone</label>
                <input type="text" name="phone" class="w-full border p-2 mb-2" placeholder="Enter your phone number" required>
            </div>
            </div>
            <div>
                <label class="block font-medium">Message</label>
                <textarea name="message" class="w-full border p-2 !mb-2" placeholder="Enter any additional notes"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block font-medium">Select Date</label>
                <input type="date" id="appointment-date" name="date" class="w-full border p-2 !mb-2" required>
            </div>
            <div>
                <label class="block font-medium">Select Time Slot</label>
                <select id="appointment-time" name="time" class="w-full border p-2 !mb-2" disabled>
                    <option value="">Select a date first</option>
                </select>
            </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block font-medium">Booking Amount</label>
                <input min="0" type="number" id="amount" name="amount" placeholder="Enter booking amount" class="w-full border p-2 !mb-2" required>
            </div>
            <div>
                <label class="block font-medium">Duration</label>
                <input placeholder="Enter booking amount" value="<?= formatMinutes($slot_duration);?> appointment" readonly class="w-full border p-2 !mb-2">
            </div>
            
            </div>
            <div>
                <label class="block font-medium">Appointment type</label>
                <select id="type" name="type" class="w-full border p-2 !mb-2">
                    <option value="online">Online</option>
                    <option value="in person">In Person</option>
                </select>
            </div>
            <div class="mb-4" style="margin-bottom:15px">
                <!--<label class="block font-medium">reCAPTCHA</label>-->
                <div class="g-recaptcha" data-sitekey="<?php echo esc_attr(get_option('recaptcha_site_key')); ?>"></div>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 hover:bg-blue-700 transition mt-4">
                Book Appointment
            </button>
        </form>
        
        <div id="appointment-message" class="mt-4"></div>
    </div>
    <style>
        body:not(.template-slider) #Header {
        min-height: auto ! IMPORTANT;
    }
    input,select{
        width:100% !important;
    }
    </style>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php
    return ob_get_clean();
}
add_shortcode('appointment_booking', 'appointment_booking_form');

function formatMinutes($minutes) {
    if ($minutes < 60) {
        return "$minutes minutes";
    }

    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;

    if ($remainingMinutes == 0) {
        return ($hours == 1) ? "1 hour" : "$hours hours";
    }

    return ($hours == 1 ? "1 hour" : "$hours hours") . " $remainingMinutes minutes";
}
