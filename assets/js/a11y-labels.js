// Accesibilidad: a cada casilla/selector sin nombre le pone uno (para lectores de pantalla).
// Orden: <label> cercano en el mismo grupo > placeholder > title > nombre del campo.
(function () {
    function nombre(el) {
        var g = el.closest('.form-group, .field, td, th, div');
        var l = g && g.querySelector('label');
        var t = (l && l.textContent) || el.getAttribute('placeholder') || el.getAttribute('title') || el.name || el.id || '';
        return t.replace(/\s+/g, ' ').trim();
    }
    function arreglar() {
        document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea').forEach(function (el) {
            if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) return;
            if (el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '') + '"]')) return;
            if (el.closest('label')) return;
            var n = nombre(el);
            if (n) el.setAttribute('aria-label', n);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arreglar); else arreglar();
})();
