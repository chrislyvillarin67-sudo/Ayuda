/*
 * Ayuda input guards.
 *
 * Applies live, inline validation to:
 *   - <input type="number">            -> digits only, max 11 digits
 *   - <input data-numeric>             -> same, for text fields that hold a number
 *
 * Rules (per the panel spec):
 *   - Letters / symbols cannot be typed.
 *   - If a disallowed character is attempted, or more than 11 digits are entered
 *     (digits before any decimal point), a red message appears below the field.
 *
 * The guard is idempotent and can be re-run after dynamically added fields
 * (e.g. the import preview) via window.ayudaInitInputGuards().
 */
(function () {
    'use strict';

    var DEFAULT_MAX_DIGITS = 11;

    function digitsCount(s) {
        var m = String(s == null ? '' : s).match(/[0-9]/g);
        return m ? m.length : 0;
    }

    function maxDigits(input) {
        var v = parseInt(input.getAttribute('data-maxdigits'), 10);
        return (isFinite(v) && v > 0) ? v : DEFAULT_MAX_DIGITS;
    }

    function isDecimal(input) {
        if (input.hasAttribute('data-decimal')) return true;
        var step = input.getAttribute('step');
        return !!(step && step.indexOf('.') !== -1);
    }

    function ensureErrorEl(input) {
        var el = input.__ayudaErrEl;
        if (el && el.parentNode) return el;
        var nxt = input.nextElementSibling;
        if (nxt && nxt.classList && nxt.classList.contains('field-error')) {
            input.__ayudaErrEl = nxt;
            return nxt;
        }
        el = document.createElement('div');
        el.className = 'field-error';
        el.style.display = 'none';
        input.insertAdjacentElement('afterend', el);
        input.__ayudaErrEl = el;
        return el;
    }

    function setError(input, msg) {
        var el = ensureErrorEl(input);
        if (msg) {
            el.textContent = msg;
            el.style.display = 'block';
            input.classList.add('input-invalid');
        } else {
            el.textContent = '';
            el.style.display = 'none';
            input.classList.remove('input-invalid');
        }
    }

    function guardNumber(input) {
        var decimal = isDecimal(input);
        var cap = maxDigits(input);

        function sanitize() {
            var raw = input.value;
            if (raw === '') { setError(input, ''); return; }

            var out = '';
            var dot = false;
            var sawBadChar = false;

            for (var i = 0; i < raw.length; i++) {
                var c = raw.charAt(i);
                if (c >= '0' && c <= '9') {
                    out += c;
                } else if (decimal && (c === '.' || c === ',') && !dot) {
                    dot = true;
                    out += '.';
                } else {
                    sawBadChar = true;
                }
            }

            // The cap counts digits LEFT of a decimal point only: fractional
            // digits are cents, not "numbers", and trimming them from the end
            // would leave a trailing "." that <input type="number"> rejects.
            var dotIdx = out.indexOf('.');
            var intPart = dotIdx === -1 ? out : out.slice(0, dotIdx);
            var fracPart = dotIdx === -1 ? '' : out.slice(dotIdx);
            var digits = digitsCount(intPart);
            var overMax = digits > cap;
            if (overMax) {
                intPart = intPart.slice(0, cap);
                digits = cap;
                input.__ayudaExceeded = true;
            } else if (digits < cap) {
                // Dropped back under the limit -- clear the "exceeded" state.
                input.__ayudaExceeded = false;
            }
            out = intPart + fracPart;

            if (out !== raw) input.value = out;

            // Exactly `cap` digits is valid; only an actual overflow keeps the
            // red message alive until the user deletes a digit.
            if (overMax || (input.__ayudaExceeded && digits >= cap)) {
                setError(input, 'Maximum of ' + cap + ' digits allowed.');
            } else if (sawBadChar) {
                setError(input, 'Numbers only.');
            } else {
                setError(input, '');
            }
        }

        input.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            var k = e.key;
            if (k == null || k.length !== 1) return; // Tab, Backspace, arrows, etc.
            if (k >= '0' && k <= '9') return;
            if (decimal && (k === '.' || k === ',')) return;
            e.preventDefault();
            setError(input, 'Numbers only.');
        });

        input.addEventListener('input', sanitize);
        input.addEventListener('paste', function () { setTimeout(sanitize, 0); });
        input.addEventListener('blur', sanitize);
    }

    function init() {
        var nodes = document.querySelectorAll('input[type="number"], input[data-numeric]');
        Array.prototype.forEach.call(nodes, function (input) {
            if (input.__ayudaGuarded) return;
            input.__ayudaGuarded = true;
            if (input.type !== 'number' && !input.hasAttribute('inputmode')) {
                input.setAttribute('inputmode', 'numeric');
            }
            guardNumber(input);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.ayudaInitInputGuards = init;
})();
