'use strict';
document.addEventListener('change', function (event) {
    if (event.target.matches('[data-auto-submit]') && event.target.form) {
        event.target.form.requestSubmit();
    }
});
document.addEventListener('submit', function (event) {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
});
document.addEventListener('click', function (event) {
    const row = event.target.closest('[data-row-link]');
    if (row && !event.target.closest('a,button,input,select,form,label')) {
        window.location.assign(row.dataset.rowLink);
    }
});
