(function() {
    document.querySelectorAll('.webhook-toggle-action').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var id = this.dataset.webhookId;
            var row = document.querySelector('tr[data-webhook-id="' + id + '"]');
            Booked.Webhook.toggleWebhook(id, row);
        });
    });

    document.querySelectorAll('.webhook-delete-action').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            Booked.Webhook.deleteWebhook(this.dataset.webhookId);
        });
    });
})();
