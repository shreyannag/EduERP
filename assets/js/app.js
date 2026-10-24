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

// ========================================================
// GOOGLE MATERIAL DESIGN 3 - INK RIPPLE ENGINE
// ========================================================
function createMaterialRipple(trigger, event) {
    // If called with only an event (e.g. inline onclick handler)
    if (trigger instanceof Event || (trigger && trigger.type && !trigger.tagName)) {
        event = trigger;
        trigger = (event.target && typeof event.target.closest === 'function') 
            ? event.target.closest('.btn, .student-mobile-nav-item, .admin-mobile-nav-item, .drawer-link, .nav-pills .nav-link, .list-group-item-action, .ripple-effect, .card-clickable') 
            : (event.currentTarget || event.target);
    }

    if (!trigger || !trigger.classList || trigger.classList.contains('disabled') || trigger.disabled) return;

    var computedPos = window.getComputedStyle(trigger).position;
    if (computedPos === 'static') {
        trigger.style.position = 'relative';
    }

    var rect = trigger.getBoundingClientRect();
    var ripple = document.createElement('span');
    ripple.className = 'md-ripple-ink';

    var clientX = (event && event.clientX) || (event && event.touches && event.touches[0] ? event.touches[0].clientX : rect.left + rect.width / 2);
    var clientY = (event && event.clientY) || (event && event.touches && event.touches[0] ? event.touches[0].clientY : rect.top + rect.height / 2);

    var x = clientX - rect.left;
    var y = clientY - rect.top;

    var maxRadius = Math.hypot(
        Math.max(x, rect.width - x),
        Math.max(y, rect.height - y)
    );

    var diameter = maxRadius * 2;
    ripple.style.width = diameter + 'px';
    ripple.style.height = diameter + 'px';
    ripple.style.left = (x - maxRadius) + 'px';
    ripple.style.top = (y - maxRadius) + 'px';

    trigger.appendChild(ripple);

    setTimeout(function() {
        ripple.classList.add('md-ripple-fade');
        setTimeout(function() {
            if (ripple.parentNode) {
                ripple.parentNode.removeChild(ripple);
            }
        }, 300);
    }, 250);
}

document.addEventListener('pointerdown', function(e) {
    if (!e || !e.target || typeof e.target.closest !== 'function') return;
    var target = e.target.closest('.btn, .student-mobile-nav-item, .admin-mobile-nav-item, .drawer-link, .nav-pills .nav-link, .list-group-item-action, .ripple-effect, .card-clickable');
    if (target) {
        createMaterialRipple(target, e);
    }
});
