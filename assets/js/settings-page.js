/* Status API - API Instellingen pagina: kopiëren, tonen/verbergen en bevestigingen */
(function() {
    function copyTextFromEl(el) {
        if (!el) return;
        var text = (el.value !== undefined) ? el.value : (el.textContent || '');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
            return;
        }
        el.focus();
        if (el.select) {
            el.select();
        }
        try { document.execCommand('copy'); } catch (e) {}
        if (window.getSelection) {
            window.getSelection().removeAllRanges();
        }
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.status-api-copy');
        if (!btn) return;
        e.preventDefault();
        var id = btn.getAttribute('data-copy-target');
        copyTextFromEl(document.getElementById(id));
        btn.classList.add('is-copied');
        btn.textContent = 'Gekopieerd';
        window.setTimeout(function() {
            btn.classList.remove('is-copied');
            btn.textContent = 'Kopieer';
        }, 1200);
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.status-api-toggle');
        if (!btn) return;
        e.preventDefault();
        var id = btn.getAttribute('data-toggle-target');
        var el = document.getElementById(id);
        if (!el) return;
        var isHidden = el.classList.contains('status-api-hidden');
        if (isHidden) {
            el.classList.remove('status-api-hidden');
            btn.innerHTML = '<span class="dashicons dashicons-hidden"></span> Verberg';
        } else {
            el.classList.add('status-api-hidden');
            btn.innerHTML = '<span class="dashicons dashicons-visibility"></span> Toon';
        }
    });
    // Bevestiging vóór ingrijpende acties (regenereren, intrekken, verwijderen)
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || !form.getAttribute) return;
        var message = form.getAttribute('data-status-api-confirm');
        if (message && !window.confirm(message)) {
            e.preventDefault();
        }
    });
})();
