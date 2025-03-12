<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>Appointment Booking Settings</h1>

    <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) : ?>
        <div id="message" class="updated notice is-dismissible">
            <p><strong>Settings saved successfully!</strong></p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php
        settings_fields('appointment_booking_group');
        do_settings_sections('appointment_booking_group');
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Amount <br> <small>Appointment booking amount</small></th>
                <td>
                    <span style="font-weight: bold;">$</span>
                    <input required type="number" name="appointment_amount" value="<?php echo esc_attr(get_option('appointment_amount', '0')); ?>" min="0" step="1">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Slot Duration <br> <small>Appointment booking duration</small></th>
                <td>
                    <select required name="appointment_slot_duration">
                        <?php
                        $durations = [
                            '15' => '15 min',
                            '30' => '30 min',
                            '45' => '45 min',
                            '60' => '1 hr',
                            '90' => '1.5 hr',
                            '120' => '2 hr',
                            '150' => '2.5 hr',
                            '180' => '3 hr'
                        ];
                        $selected_duration = get_option('appointment_slot_duration', '30');
                        foreach ($durations as $value => $label) {
                            echo '<option value="' . esc_attr($value) . '"' . selected($selected_duration, $value, false) . '>' . esc_html($label) . '</option>';
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Start Time <br> <small>Start time for appointment</small></th>
                <td>
                    <input required type="time" name="appointment_start_time" value="<?php echo esc_attr(get_option('appointment_start_time', '09:00')); ?>">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">End Time <br> <small>End time for appointment</small></th>
                <td>
                    <input required type="time" name="appointment_end_time" value="<?php echo esc_attr(get_option('appointment_end_time', '18:00')); ?>">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Appointment Email <br> <small>Email address to receive notification</small></th>
                <td>
                    <input type="email" required name="appointment_email" value="<?php echo esc_attr(get_option('appointment_email')); ?>">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Microsoft Graph User Email <br> <small>MS user to push appointments to calendar</small></th>
                <td>
                    <input type="email" required name="ms_graph_user_email" value="<?php echo esc_attr(get_option('ms_graph_user_email', '')); ?>">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Microsoft Tenant ID</th>
                <td>
                    <input type="text" required name="ms_tenant_id" value="<?php echo esc_attr(get_option('ms_tenant_id', '')); ?>">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Microsoft Client ID</th>
                <td>
                    <input type="text" required name="ms_client_id" value="<?php echo esc_attr(get_option('ms_client_id', '')); ?>">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Microsoft Client Secret</th>
                <td>
                    <input type="password" required name="ms_client_secret" value="<?php echo esc_attr(get_option('ms_client_secret', '')); ?>">
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
