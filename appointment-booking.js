jQuery(document).ready(function ($) {
    // Fetch available time slots
    $("input[name='date']").on("change", function () {
        let selectedDate = $(this).val();
        let $timeSelect = $("select[name='time']");
        $timeSelect.prop("disabled", true).html('<option value="">Loading...</option>');

        $.ajax({
            url: appointmentBookingData.ajax_url,
            type: "POST",
            data: {
                action: "get_appointment_slots",
                date: selectedDate,
                nonce: appointmentBookingData.nonce, // Ensure nonce is sent
            },
            success: function (response) {
                if (response.success) {
                    $timeSelect.html('<option value="">Select a time slot</option>');
                    response.data.forEach((slot) => {
                        $timeSelect.append(`<option value="${slot}">${slot}</option>`);
                    });
                    $timeSelect.prop("disabled", false);
                } else {
                    $timeSelect.html('<option value="">No slots available</option>');
                }
            },
            error: function (xhr) {
                console.error("Error:", xhr.responseText);
            },
        });
    });

    // Handle form submission
    $("#appointment-booking-form").on("submit", function (e) {
        e.preventDefault();

        let formData = $(this).serialize();
        let btn = $(this).find('button');
        let btnText = btn.text();
        btn.attr('disabled', true).text('Please hold on..');
        $("#appointment-message").empty();
        $.ajax({
            url: appointmentBookingData.ajax_url,
            type: "POST",
            data: formData + "&action=appointment_booking_save",
            success: function (response) {
                let messageDiv = $("#appointment-message");
                if (response.success) {
                    messageDiv.html(`<p class="text-green-600 font-semibold">${response.data.message}</p>`);
                    $("#appointment-booking-form")[0].reset();
                } else {
                    messageDiv.html(`<p class="text-red-600 font-semibold">${response.data.message}</p>`);
                }
                btn.removeAttr('disabled').prop('disabled', false).text(btnText);
            },
            error: function (xhr) {
                btn.removeAttr('disabled').prop('disabled', false).text(btnText);
                console.error("Error:", xhr.responseText);
            },
        });
    });
});
