(function() {
    const container = document.getElementById('waitlist-tableview');
    if (!container) return;

    Craft.initUiElements(container);

    const form = document.getElementById('waitlist-filter-form');

    container.querySelectorAll('select.autosubmit').forEach(el => el.addEventListener('change', () => form.submit()));

    document.getElementById('per-page-select')?.addEventListener('change', function() {
        const url = new URL(location.href);
        url.searchParams.set('limit', this.value);
        url.searchParams.delete('page');
        location.href = url;
    });

    document.getElementById('search-clear-btn')?.addEventListener('click', () => {
        document.getElementById('search-input').value = '';
        form.submit();
    });
})();
