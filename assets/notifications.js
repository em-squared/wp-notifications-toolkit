(function($) {
    $(document).ready(function() {
        // Fetch notifications via AJAX
        $.post(wpNotification.ajax_url, {
            action: 'get_notifications',
            nonce: wpNotification.nonce,
        }, function(data) {
            $('body').append(data);  // Append notifications to the page

            $('.notification').each(function() {
                var $this = $(this);
                var fadeout = $this.data('fadeout');
                var notificationId = $this.data('id');

                if (fadeout === 'never') {
                    $this.on('click', function() {
                        $this.fadeOut();
                        markNotificationAsRead(notificationId);  // Mark as read or delete
                    });
                } else {
                    setTimeout(function() {
                        $this.fadeOut();
                        markNotificationAsRead(notificationId);  // Mark as read or delete
                    }, fadeout * 1000);
                }
            });
        });

        // Function to send AJAX request to mark notification as read or delete
        function markNotificationAsRead(notificationId) {
            $.post(wpNotification.ajax_url, {
                action: 'mark_notification_as_read',
                nonce: wpNotification.nonce,
                notification_id: notificationId
            }, function(response) {
                if (!response.success) {
                    console.log('Error marking notification as read: ' + response.data.message);
                }
            });
        }
    });
})(jQuery);
