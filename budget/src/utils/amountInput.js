/**
 * Amount boxes that take a decimal comma (#442).
 *
 * A browser number field only accepts a comma when the browser decides the
 * locale allows it, and Safari usually doesn't, so `12,50` came back as an
 * empty value. Amount boxes are text fields marked `data-amount` instead,
 * and one listener tidies whatever is typed into a plain `1250.50` as it is
 * typed. Every reader then sees a dot-decimal number, exactly as it did
 * from a number field, so nothing that reads `.value` has to change.
 *
 * The last `.` or `,` typed is the decimal point and any earlier one is a
 * thousands separator, which covers both `1.250,50` and `1,250.50`. A
 * minus sign is kept only first, and only when the box allows negatives
 * (no `min`, or a negative one). Anything else, a currency sign, a space or
 * a Swiss apostrophe, is dropped. `min` still works through the
 * `pattern` below; `step` and `max` are no longer enforced.
 */

/**
 * @param {string} raw what is in the box
 * @param {object} [options]
 * @param {boolean} [options.allowNegative=true]
 * @param {number} [options.caret] the cursor position in raw
 * @returns {{value: string, caret: number}} the tidied text and where the
 *   cursor belongs in it
 */
export function normaliseAmountText(raw, { allowNegative = true, caret = String(raw ?? '').length } = {}) {
    const text = String(raw ?? '');
    const lastSeparator = Math.max(text.lastIndexOf('.'), text.lastIndexOf(','));
    let value = '';
    let newCaret = 0;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (c >= '0' && c <= '9') {
            value += c;
        } else if (i === lastSeparator) {
            value += '.';
        } else if (c === '-' && allowNegative && value === '') {
            value += '-';
        }
        if (i < caret) {
            newCaret = value.length;
        }
    }
    return { value, caret: newCaret };
}

/**
 * Whether an amount box may hold a negative number: only when it has no
 * minimum, or a negative one.
 *
 * @param {HTMLInputElement} input
 * @returns {boolean}
 */
function allowsNegative(input) {
    const min = input.getAttribute('min');
    return min === null || min === '' || Number(min) < 0;
}

/**
 * The `pattern` that stands in for a number field's own checks: a complete
 * number (not a lone `-` or `.`), and above zero when the minimum is.
 *
 * @param {HTMLInputElement} input
 * @returns {string}
 */
export function amountPattern(input) {
    const min = Number(input.getAttribute('min'));
    if (min > 0) {
        return '0*[1-9][0-9]*(\\.[0-9]*)?|0*\\.?0*[1-9][0-9]*';
    }
    const number = '([0-9]+(\\.[0-9]*)?|\\.[0-9]+)';
    return allowsNegative(input) ? `-?${number}` : number;
}

/**
 * Turns an input into an amount box. For boxes built in JS; markup just
 * carries `type="text" inputmode="decimal" data-amount`.
 *
 * @param {HTMLInputElement} input
 * @returns {HTMLInputElement}
 */
export function makeAmountInput(input) {
    input.type = 'text';
    input.inputMode = 'decimal';
    input.dataset.amount = '';
    return input;
}

/**
 * Tidies the amount box an input event came from. Runs on the capture
 * phase at the document, so it is done before any of the box's own input
 * listeners read the value.
 *
 * @param {Event} event
 */
function onInput(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !('amount' in input.dataset)) {
        return;
    }
    input.pattern = amountPattern(input);
    const caret = input.selectionStart ?? input.value.length;
    const { value, caret: newCaret } = normaliseAmountText(input.value, {
        allowNegative: allowsNegative(input),
        caret,
    });
    if (value === input.value) {
        return;
    }
    input.value = value;
    if (document.activeElement === input) {
        input.setSelectionRange(newCaret, newCaret);
    }
}

let installed = false;

/**
 * Starts tidying every amount box on the page, including ones added later.
 * Safe to call more than once.
 */
export function installAmountInputs() {
    if (installed) {
        return;
    }
    installed = true;
    document.addEventListener('input', onInput, true);
}
