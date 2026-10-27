/**
 * Translated strings stay safe to render (#450).
 *
 * @nextcloud/l10n escapes placeholder values and runs every translation
 * through its bundled DOMPurify before handing it back. A translation
 * comes from Weblate and a placeholder value can come from the user, so
 * both have to come out inert. These pin that behaviour across DOMPurify
 * bumps, and pin the bundled version above the last advisory range.
 */

import { describe, it, expect } from 'vitest';
import { translate as t } from '@nextcloud/l10n';
import DOMPurify from 'dompurify';

const render = (html) => {
    const div = document.createElement('div');
    div.innerHTML = html;
    return div;
};

describe('translate() output', () => {
    it('escapes markup in a placeholder value', () => {
        const out = t('budget', 'Paid {name}', { name: '<img src=x onerror=alert(1)>' });
        const div = render(out);
        expect(div.querySelector('img')).toBeNull();
        expect(div.textContent).toBe('Paid <img src=x onerror=alert(1)>');
    });

    it('strips script and event handlers from the translation itself', () => {
        const div = render(t('budget', 'Hello <script>alert(1)</script><b onclick="alert(1)">there</b>'));
        expect(div.querySelector('script')).toBeNull();
        expect(div.querySelector('b').getAttribute('onclick')).toBeNull();
        expect(div.textContent).toBe('Hello there');
    });

    it('drops javascript: links', () => {
        const div = render(t('budget', '<a href="javascript:alert(1)">docs</a>'));
        expect(div.querySelector('a').getAttribute('href')).toBeNull();
    });

    it('leaves plain text and ordinary punctuation alone', () => {
        expect(t('budget', 'Spent {amount} of {budget}', { amount: '£12.50', budget: '£100' }))
            .toBe('Spent £12.50 of £100');
    });
});

describe('bundled DOMPurify', () => {
    it('is at least 3.4.17, past every published advisory range', () => {
        const [major, minor, patch] = DOMPurify.version.split('.').map(Number);
        expect(major * 1e6 + minor * 1e3 + patch).toBeGreaterThanOrEqual(3004017);
    });
});
