<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$booking_table = $wpdb->prefix . 'appointment_bookings';
$transaction_table = $wpdb->prefix . 'appointment_transactions';

// Fetch bookings with payment details
$bookings = $wpdb->get_results("
    SELECT b.*, t.payment_id, t.status as payment_status 
    FROM $booking_table b
    LEFT JOIN $transaction_table t ON b.id = t.booking_id
    ORDER BY b.created_at DESC
");
?>

<div class="wrap">
    <h1 class="text-2xl font-bold mb-4">Appointments</h1>
    <table class="w-full border-collapse border border-gray-300 bg-white">
        <thead class="bg-gray-100">
            <tr>
                <th class="border p-2">ID</th>
                <th class="border p-2">Name</th>
                <th class="border p-2">Email</th>
                <th class="border p-2">Phone</th>
                <th class="border p-2">Date</th>
                <th class="border p-2">Time</th>
                <th class="border p-2">Status</th>
                <th class="border p-2">Payment</th>
                <th class="border p-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bookings as $booking) : ?>
                <tr>
                    <td class="border p-2"><?php echo esc_html($booking->id); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->customer_name); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->email); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->phone); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->date); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->time); ?></td>
                    <td class="border p-2"><?php echo ucfirst(esc_html($booking->status)); ?></td>
                    <td class="border p-2"><?php echo $booking->payment_status == 'paid' ? '✅ Paid' : '❌ Not Paid'; ?></td>
                    <td class="border p-2">
                        <a href="admin.php?page=appointment_booking_view&id=<?php echo esc_attr($booking->id); ?>" class="bg-blue-500 !text-white px-4 py-1 rounded">View</a>
                        
                        <?php if ($booking->status === 'pending') : ?>
                            <button class="bg-green-500 text-white px-4 py-1 rounded confirm-booking" data-id="<?php echo esc_attr($booking->id); ?>">Confirm</button>
                            <button class="bg-red-500 text-white px-4 py-1 rounded reject-booking" data-id="<?php echo esc_attr($booking->id); ?>">Reject</button>
                        <?php elseif ($booking->status === 'confirmed') : ?>
                            <button class="bg-gray-500 text-white px-4 py-1 rounded cancel-booking" data-id="<?php echo esc_attr($booking->id); ?>">Cancel</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(document).ready(function($) {
    function updateBookingStatus(id, status) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'update_booking_status',
                id: id,
                status: status,
                nonce: '<?php echo wp_create_nonce("update_booking_status_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            }
        });
    }

    $('.confirm-booking').click(function() {
        let id = $(this).data('id');
        updateBookingStatus(id, 'confirmed');
    });

    $('.reject-booking').click(function() {
        let id = $(this).data('id');
        updateBookingStatus(id, 'rejected');
    });

    $('.cancel-booking').click(function() {
        let id = $(this).data('id');
        updateBookingStatus(id, 'cancelled');
    });
});
</script>
