<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$booking_table = $wpdb->prefix . 'appointment_bookings';
$transaction_table = $wpdb->prefix . 'appointment_transactions';

// Get booking ID from the URL
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$booking_id) {
    echo '<p class="text-red-500">Invalid Booking ID</p>';
    return;
}

// Fetch booking details
$booking = $wpdb->get_row($wpdb->prepare("
    SELECT b.*, t.payment_id, t.status as payment_status 
    FROM $booking_table b
    LEFT JOIN $transaction_table t ON b.id = t.booking_id
    WHERE b.id = %d
", $booking_id));


if (!$booking) {
    echo '<p class="text-red-500">Booking not found</p>';
    return;
}
?>

<div class="wrap">
    <h1 class="text-2xl font-bold mb-4">Appointment Details</h1>
    <div class="bg-white p-6 shadow rounded-lg">
        <p><strong>Name:</strong> <?php echo esc_html($booking->customer_name); ?></p>
        <p><strong>Email:</strong> <?php echo esc_html($booking->email); ?></p>
        <p><strong>Phone:</strong> <?php echo esc_html($booking->phone); ?></p>
        <p><strong>Date:</strong> <?php echo esc_html($booking->date); ?></p>
        <p><strong>Time:</strong> <?php echo esc_html($booking->time); ?></p>
        <p><strong>Duration:</strong> <?php echo esc_html($booking->duration); ?> minutes</p>
        <p><strong>Amount:</strong> $<?php echo esc_html(number_format($booking->amount, 2)); ?></p>
        <p><strong>Status:</strong> <?php echo ucfirst(esc_html($booking->status)); ?></p>
        <p><strong>Message:</strong> <?php echo nl2br(esc_html($booking->message)); ?></p>
        <p><strong>Booking Created At:</strong> <?php echo esc_html($booking->created_at); ?></p>
        <p><strong>Payment Status:</strong> 
            <?php echo ($booking->payment_status === 'paid') ? '✅ Paid' : '❌ Not Paid'; ?>
        </p>

        <?php if (!empty($booking->payment_id)) : ?>
            <p><strong>Transaction ID:</strong> <?php echo esc_html($booking->transaction_id); ?></p>
            <p><strong>Payment Method:</strong> <?php echo esc_html(ucfirst($booking->payment_method)); ?></p>
        <?php else : ?>
            <p class="text-gray-500"><em>No transaction details available</em></p>
        <?php endif; ?>

        <div class="mt-4">
            <?php if ($booking->status === 'pending') : ?>
                <button class="bg-green-500 text-white px-4 py-1 rounded confirm-booking" data-id="<?php echo esc_attr($booking->id); ?>">Confirm</button>
                <button class="bg-red-500 text-white px-4 py-1 rounded reject-booking" data-id="<?php echo esc_attr($booking->id); ?>">Reject</button>
            <?php elseif ($booking->status === 'confirmed') : ?>
                <button class="bg-gray-500 text-white px-4 py-1 rounded cancel-booking" data-id="<?php echo esc_attr($booking->id); ?>">Cancel</button>
            <?php endif; ?>

            <a href="admin.php?page=appointment_booking" class="bg-blue-500 text-white px-4 py-1 rounded">Back to List</a>
        </div>
    </div>
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
