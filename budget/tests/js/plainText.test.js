/**
 * plainText() (#381).
 *
 * @nextcloud/l10n's translate() escapes interpolated values for HTML by
 * default. The two surfaces that render translated strings as TEXT — the
 * in-app dialogs and the toasts — have to undo that, or every name carrying
 * an ampersand or a quote reaches the user mangled.
 */

import { describe, it, expect } from 'vitest';
import { plainText } from '../../src/utils/helpers.js';

describe('plainText', () => {
    it('undoes each entity escape-html produces', () => {
        expect(plainText('Food &amp; Dining')).toBe('Food & Dining');
        expect(plainText('&lt;tag&gt;')).toBe('<tag>');
        expect(plainText('say &quot;hi&quot;')).toBe('say "hi"');
        expect(plainText('O&#39;Brien')).toBe("O'Brien");
    });

    it('decodes &amp; last so a doubly-escaped entity is not over-decoded', () => {
        expect(plainText('&amp;lt;')).toBe('&lt;');
        expect(plainText('&amp;amp;')).toBe('&amp;');
    });

    it('leaves ordinary text alone', () => {
        expect(plainText('Delete this account?')).toBe('Delete this account?');
        expect(plainText('100% of budget')).toBe('100% of budget');
    });

    it('tolerates nullish input', () => {
        expect(plainText(undefined)).toBe('');
        expect(plainText(null)).toBe('');
    });

    it('stringifies non-strings rather than throwing', () => {
        expect(plainText(42)).toBe('42');
    });
});
