<?php
if (!defined('ABSPATH')) {
    exit;
}

// Register settings with validation
function appointment_booking_register_settings() {
    register_setting('appointment_booking_group', 'appointment_amount', [
        'sanitize_callback' => function ($input) {
            return (is_numeric($input) && intval($input) >= 0) ? intval($input) : 0;
        }
    ]);

    register_setting('appointment_booking_group', 'appointment_email', [
        'sanitize_callback' => function ($input) {
            return is_email($input) ? sanitize_email($input) : '';
        }
    ]);

    register_setting('appointment_booking_group', 'appointment_slot_duration', [
        'sanitize_callback' => function ($input) {
            $valid_durations = ['15', '30', '45', '60', '90', '120', '150', '180'];
            return in_array($input, $valid_durations) ? $input : '30';
        }
    ]);

    register_setting('appointment_booking_group', 'appointment_start_time', [
        'sanitize_callback' => function ($input) {
            return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $input) ? $input : '09:00';
        }
    ]);

    register_setting('appointment_booking_group', 'appointment_end_time', [
        'sanitize_callback' => function ($input) {
            return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $input) ? $input : '18:00';
        }
    ]);

    register_setting('appointment_booking_group', 'ms_graph_user_email', [
        'sanitize_callback' => function ($input) {
            return is_email($input) ? sanitize_email($input) : '';
        }
    ]);

    register_setting('appointment_booking_group', 'ms_tenant_id', [
        'sanitize_callback' => function ($input) {
            return sanitize_text_field($input);
        }
    ]);

    register_setting('appointment_booking_group', 'ms_client_id', [
        'sanitize_callback' => function ($input) {
            return sanitize_text_field($input);
        }
    ]);

    register_setting('appointment_booking_group', 'ms_client_secret', [
        'sanitize_callback' => function ($input) {
            return sanitize_text_field($input);
        }
    ]);
}

add_action('admin_init', 'appointment_booking_register_settings');
