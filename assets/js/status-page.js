/**
 * Status Melding pagina: toon de vervaldatum/-tijd velden alleen bij een groene status.
 *
 * Verborgen velden worden uitgeschakeld, zodat browservalidatie (min/pattern) op een
 * verborgen veld het opslaan nooit kan blokkeren. De server negeert de vervaldatum
 * bij een niet-groene status toch al.
 */
(function () {
    'use strict';

    function init() {
        var status = document.getElementById('status');
        var date = document.getElementById('expiry_date');
        if (!status) {
            return;
        }

        var rows = document.querySelectorAll('.expiry-date-field');

        function toggleExpiryFields() {
            var isGreen = status.value === 'green';
            for (var i = 0; i < rows.length; i++) {
                rows[i].style.display = isGreen ? '' : 'none';
                var inputs = rows[i].querySelectorAll('input');
                for (var j = 0; j < inputs.length; j++) {
                    inputs[j].disabled = !isGreen;
                }
            }
        }

        // Geen datum in het verleden kiezen (alleen bij een nieuw gekozen datum)
        if (date && window.statusApiStatusPage && window.statusApiStatusPage.today) {
            var today = window.statusApiStatusPage.today;
            if (!date.value || date.value >= today) {
                date.min = today;
            }
        }

        toggleExpiryFields();
        status.addEventListener('change', toggleExpiryFields);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
