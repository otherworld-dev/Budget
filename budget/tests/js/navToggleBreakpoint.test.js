/**
 * The navigation button at exactly 1024 px (#399, browser round 6).
 *
 * Nextcloud docks the app navigation from 1024 px up and hides it off
 * screen only below that (core's `@media (width < 1024px)`), but the
 * button that opens it floated in from `max-width: 1024px`, so at 1024 px
 * it sat over the navigation's "Search here" box. The button, the room the
 * headings leave for it, and the open navigation's overlay now start where
 * Nextcloud's own narrow layout does.
 */

import { describe, it, expect } from 'vitest';
import { RULES } from './helpers/stylesheet.js';

const NEXTCLOUD_NARROW = '@media (width < 1024px)';

const media = (rule) => (rule.media ?? '').replace(/\s+/g, ' ');
const naming = (part) => RULES.filter(rule => rule.selectors.some(s => s.includes(part)));

describe('the navigation button', () => {
    it('shows only where Nextcloud hides the navigation', () => {
        const shown = naming('#budget-nav-toggle-wrapper').filter(rule => rule.declarations.display === 'block');

        expect(shown.length).toBeGreaterThan(0);
        expect(shown.map(media)).toEqual(shown.map(() => NEXTCLOUD_NARROW));
        // Hidden by default, wider than that
        expect(naming('#budget-nav-toggle-wrapper').some(rule => rule.media === null && rule.declarations.display === 'none')).toBe(true);
    });

    it('is placed, moved aside and faded only there', () => {
        for (const rule of naming('#budget-nav-toggle')) {
            if (rule.media === null) continue;
            expect(media(rule).startsWith(NEXTCLOUD_NARROW)).toBe(true);
        }
    });

    it('has the headings make room for it only there', () => {
        const room = RULES.filter(rule => rule.declarations['padding-left'] === '48px'
            && rule.selectors.includes('.view-header h2'));

        expect(room.length).toBe(1);
        expect(media(room[0])).toBe(NEXTCLOUD_NARROW);
    });
});

describe('the open navigation\'s overlay', () => {
    it('starts where Nextcloud\'s own narrow layout does', () => {
        const rules = [...naming('#app-navigation:not(.vue)'), ...naming('.nav-open-backdrop')];

        expect(rules.length).toBeGreaterThan(0);
        expect(rules.every(rule => media(rule) === NEXTCLOUD_NARROW)).toBe(true);
    });
});
