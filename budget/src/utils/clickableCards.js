/**
 * Keyboard access for cards and rows that open something when clicked.
 *
 * Account, asset and pension cards, contact cards, category tree items and
 * rule group headers act on a click but are plain elements, so a keyboard
 * user could not reach or use them. Their markup now carries tabindex="0";
 * this makes Enter and Space on the focused card do what a click does.
 *
 * Only a key pressed on the card itself counts: the buttons and checkboxes
 * inside a card keep their own behaviour.
 */

export const CLICKABLE_CARD_SELECTOR = [
    '.account-card',
    '.asset-card',
    '.pension-card',
    '.contact-card',
    '.category-item',
    '.rules-group-header',
    'th.sortable',
].join(', ');

export function setupClickableCards() {
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const card = e.target;
        if (!(card instanceof Element) || !card.matches(CLICKABLE_CARD_SELECTOR)) return;
        e.preventDefault();
        card.click();
    });
}
