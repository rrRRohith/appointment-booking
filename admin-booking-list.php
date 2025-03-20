<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$booking_table = $wpdb->prefix . 'appointment_bookings';

// Fetch filters
$search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$payment_status_filter = isset($_GET['payment_status']) ? sanitize_text_field($_GET['payment_status']) : '';
$appointment_start = isset($_GET['appointment_start']) ? sanitize_text_field($_GET['appointment_start']) : '';
$appointment_end = isset($_GET['appointment_end']) ? sanitize_text_field($_GET['appointment_end']) : '';
$booking_start = isset($_GET['booking_start']) ? sanitize_text_field($_GET['booking_start']) : '';
$booking_end = isset($_GET['booking_end']) ? sanitize_text_field($_GET['booking_end']) : '';

// SQL Conditions
$where = [];
if ($search_query) {
    $where[] = "(b.customer_name LIKE '%$search_query%' OR b.email LIKE '%$search_query%' OR b.phone LIKE '%$search_query%' OR b.id LIKE '%$search_query%' OR b.payment_id LIKE '%$search_query%')";
}
if ($status_filter) {
    $where[] = "b.status = '$status_filter'";
}
if ($type_filter) {
    $where[] = "b.type = '$type_filter'";
}
if ($payment_status_filter) {
    $where[] = "b.payment_status = '$payment_status_filter'";
}
if ($appointment_start && $appointment_end) {
    $where[] = "b.date BETWEEN '$appointment_start' AND '$appointment_end'";
}
if ($booking_start && $booking_end) {
    $where[] = "b.created_at BETWEEN '$booking_start' AND '$booking_end'";
}
$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch filtered bookings
$bookings = $wpdb->get_results("
    SELECT b.*
    FROM $booking_table b
    $where_sql
    ORDER BY b.created_at DESC
");
?>

<div class="wrap">
    <h1 class="text-2xl font-bold mb-4">Appointments</h1>
    <form method="GET" class="mb-4 grid grid-cols-4 gap-4 bg-white p-4 shadow rounded-lg">
        <input type="hidden" name="page"  value="appointment_booking">
        
        <div>
            <label class="block text-sm font-medium">Search</label>
            <input type="text" name="search" placeholder="Search by name, email, payment_id etc" value="<?php echo esc_attr($search_query); ?>" class="w-full px-3 py-2 border rounded">
        </div>
        <div>
            <label class="block text-sm font-medium">Type</label>
            <select name="type" class="w-full px-3 py-2 border rounded">
                <option value="">All</option>
                <option value="online" <?php selected($type_filter, 'online'); ?>>Online</option>
                <option value="in person" <?php selected($type_filter, 'in person'); ?>>In person</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">Status</label>
            <select name="status" class="w-full px-3 py-2 border rounded">
                <option value="">All</option>
                <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                <option value="confirmed" <?php selected($status_filter, 'confirmed'); ?>>Confirmed</option>
                <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>Cancelled</option>
                <option value="rejected" <?php selected($status_filter, 'rejected'); ?>>Rejected</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium">Payment Status</label>
            <select name="payment_status" class="w-full px-3 py-2 border rounded">
                <option value="">All</option>
                <option value="paid" <?php selected($payment_status_filter, 'paid'); ?>>Paid</option>
                <option value="pending" <?php selected($payment_status_filter, 'pending'); ?>>Unpaid</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium">Appointment Date From</label>
            <input type="date" name="appointment_start" value="<?php echo esc_attr($appointment_start); ?>" class="w-full px-3 py-2 border rounded">
        </div>
        
        <div>
            <label class="block text-sm font-medium">Appointment Date To</label>
            <input type="date" name="appointment_end" value="<?php echo esc_attr($appointment_end); ?>" class="w-full px-3 py-2 border rounded">
        </div>
        
        <div>
            <label class="block text-sm font-medium">Booking Date From</label>
            <input type="date" name="booking_start" value="<?php echo esc_attr($booking_start); ?>" class="w-full px-3 py-2 border rounded">
        </div>
        
        <div>
            <label class="block text-sm font-medium">Booking Date To</label>
            <input type="date" name="booking_end" value="<?php echo esc_attr($booking_end); ?>" class="w-full px-3 py-2 border rounded">
        </div>
        
        <div class="col-span-4">
            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Filter</button>
            <a href="admin.php?page=appointment_booking" class="ml-2 text-red-500">Reset</a>
        </div>
    </form>
    
    <table class="w-full border-collapse border border-gray-300 bg-white">
        <thead class="bg-gray-100">
            <tr>
                <th class="border p-2">ID</th>
                <th class="border p-2">Name</th>
                <th class="border p-2">Contact</th>
                <th class="border p-2">Date & Time</th>
                <th class="border p-2">Type</th>
                <th class="border p-2">Status</th>
                <th class="border p-2">Booked at</th>
                <th class="border p-2">Payment</th>
                <th class="border p-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bookings as $booking) : ?>
                <tr>
                    <td class="border p-2"><?php echo esc_html($booking->id); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->customer_name); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->email); ?> <br> <?php echo esc_html($booking->phone); ?></td>
                    <td class="border p-2"><?php echo esc_html($booking->date); ?> <?php echo esc_html($booking->time); ?> <br> <?php echo formatMinutes($booking->duration); ?></td>
                    <td class="border p-2"><?php echo ucfirst(esc_html($booking->type)); ?></td>
                    <td class="border p-2"><?php echo ucfirst(esc_html($booking->status)); ?></td>
                    <td class="border p-2"><?php echo ucfirst(esc_html($booking->created_at)); ?></td>
                    <td class="border p-2"><?php echo $booking->payment_status == 'paid' ? '✅ Paid' : '❌ Not Paid'; ?> <br> <?php echo $booking->payment_id; ?></td>
                    <td class="border p-2">
                        <a href="admin.php?page=appointment_booking_view&id=<?php echo esc_attr($booking->id); ?>" class="bg-blue-500 text-white hover:text-white px-4 py-1 rounded">View</a>
                        
                        <?php if ($booking->status === 'pending') : ?>
                            <span class="bg-green-500 text-white px-4 py-1 rounded confirm-booking cursor-pointer" data-id="<?php echo esc_attr($booking->id); ?>">Confirm</span>
                            <span class="bg-red-500 text-white px-4 py-1 rounded reject-booking cursor-pointer" data-id="<?php echo esc_attr($booking->id); ?>">Reject</span>
                        <?php elseif ($booking->status === 'confirmed') : ?>
                            <span class="bg-gray-500 text-white px-4 py-1 rounded cancel-booking cursor-pointer" data-id="<?php echo esc_attr($booking->id); ?>">Cancel</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if(!count($bookings)) : ?>
            <tr>
                    <td colspan="10" class="border p-2">No bookings found...</td>
            </tr>
            <?php endif ?>
        </tbody>
    </table>
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
