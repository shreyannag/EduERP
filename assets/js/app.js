// assets/js/app.js

function escapeHtml(str) {
    if (typeof str !== 'string') return str;
    return str.replace(/[&<>"']/g, function(m) {
        switch (m) {
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '"': return '&quot;';
            case "'": return '&#039;';
            default: return m;
        }
    });
}

// Bootstrap Global Toasts wrapper
function showToast(message, type = 'success') {
    let icon = type === 'success' ? 'fa-check-circle text-success' : (type === 'danger' ? 'fa-exclamation-circle text-danger' : 'fa-info-circle text-info');
    let title = type.charAt(0).toUpperCase() + type.slice(1);
    
    let $toast = $(
        '<div class="toast border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-delay="4000" style="position: fixed; bottom: 30px; right: 30px; z-index: 1060; min-width: 300px; border-radius: 8px;">' +
          '<div class="toast-header border-0 bg-light">' +
            '<i class="fas mr-2 bg-white rounded-circle"></i>' +
            '<strong class="mr-auto text-dark"></strong>' +
            '<button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close">' +
              '<span aria-hidden="true">&times;</span>' +
            '</button>' +
          '</div>' +
          '<div class="toast-body bg-white rounded-bottom font-weight-500 text-dark"></div>' +
        '</div>'
    );
    
    $toast.find('.toast-header i').addClass(icon);
    $toast.find('.toast-header strong').text(title);
    $toast.find('.toast-body').text(message);
    
    document.body.appendChild($toast[0]);
    $toast.toast('show').on('hidden.bs.toast', function () {
        $(this).remove();
    });
}
