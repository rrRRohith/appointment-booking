<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$booking_table = $wpdb->prefix . 'appointment_bookings';

// Get booking ID from the URL
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$booking_id) {
    echo '<p class="text-red-500">Invalid Booking ID</p>';
    return;
}

// Fetch booking details
$booking = $wpdb->get_row($wpdb->prepare("
    SELECT b.*
    FROM $booking_table b
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
        <p><strong>Duration:</strong> <?php echo formatMinutes($booking->duration); ?></p>
        <p><strong>Type:</strong> <?php echo ucfirst(esc_html($booking->type)); ?></p>
        <p><strong>Amount:</strong> $<?php echo esc_html(number_format($booking->amount, 2)); ?></p>
        <p><strong>Status:</strong> <?php echo ucfirst(esc_html($booking->status)); ?></p>
        <p><strong>Message:</strong> <?php echo nl2br(esc_html($booking->message)); ?></p>
        <p><strong>Booking Created At:</strong> <?php echo esc_html($booking->created_at); ?></p>
        <p><strong>Payment Status:</strong> 
            <?php echo ($booking->payment_status === 'paid') ? '✅ Paid' : '❌ Not Paid'; ?>
        </p>

        <?php if (!empty($booking->payment_id)) : ?>
            <p><strong>Transaction ID:</strong> <?php echo esc_html($booking->payment_id); ?></p>
            <p><strong>Payment Method:</strong> Stripe payment</p>
        <?php else : ?>
            <p class="text-gray-500"><em>No transaction details available</em></p>
        <?php endif; ?>

        <div class="mt-4">
            <?php if ($booking->status === 'pending') : ?>
                <span class="bg-green-500 text-white px-4 py-1 rounded confirm-booking cursor-pointer" data-id="<?php echo esc_attr($booking->id); ?>">Confirm</span>
                <span class="bg-red-500 text-white px-4 py-1 rounded reject-booking cursor-pointer" data-id="<?php echo esc_attr($booking->id); ?>">Reject</span>
            <?php elseif ($booking->status === 'confirmed') : ?>
                <span class="bg-gray-500 text-white px-4 py-1 rounded cancel-booking cursor-pointer" data-id="<?php echo esc_attr($booking->id); ?>">Cancel</span>
            <?php endif; ?>

            <a href="admin.php?page=appointment_booking" class="bg-blue-500 text-white px-4 py-1 rounded hover:text-white">Back to List</a>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    async function updateBookingStatus(id, status) {
        await $.ajax({
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
        return true;
    }

    $('.confirm-booking').click(async function() {
        let id = $(this).data('id');
        $(this).text('Processing').addClass('disabled cursor-not-allowed');
        await updateBookingStatus(id, 'confirmed');
        $(this).text('Confirm').removeClass('disabled cursor-not-allowed');
    });

    $('.reject-booking').click(async function() {
        let id = $(this).data('id');
        $(this).text('Processing').addClass('disabled cursor-not-allowed');
        await updateBookingStatus(id, 'rejected');
        $(this).text('Reject').removeClass('disabled cursor-not-allowed');
    });

    $('.cancel-booking').click(async function() {
        let id = $(this).data('id');
        $(this).text('Processing').addClass('disabled cursor-not-allowed');
        await updateBookingStatus(id, 'cancelled');
        await $(this).text('Cancel').removeClass('disabled cursor-not-allowed');
    });
});
</script>
