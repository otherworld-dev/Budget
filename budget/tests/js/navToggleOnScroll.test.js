/**
 * The navigation button on narrow screens steps aside while the view
 * scrolls down, and comes back on the way up or at the top. It covered the
 * Bills by Month year picker, a table's first column and card titles as
 * they passed under it.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';

import { hideNavToggleOnScroll, SCROLLED_AWAY_CLASS } from '../../src/utils/navToggleOnScroll.js';
import { RULES } from './helpers/stylesheet.js';

let wrapper;
let content;

function scrollTo(element, top) {
    element.scrollTop = top;
    element.dispatchEvent(new Event('scroll'));
}

const away = () => wrapper.classList.contains(SCROLLED_AWAY_CLASS);

beforeEach(() => {
    document.body.innerHTML = '<div id="budget-nav-toggle-wrapper"><button id="budget-nav-toggle"></button></div>'
        + '<div id="app-content"><div class="table-container"></div></div>';
    wrapper = document.getElementById('budget-nav-toggle-wrapper');
    content = document.getElementById('app-content');
    hideNavToggleOnScroll(wrapper);
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the navigation button', () => {
    it('steps aside when the view scrolls down', () => {
        scrollTo(content, 300);
        expect(away()).toBe(true);
    });

    it('comes back when the view scrolls up', () => {
        scrollTo(content, 300);
        scrollTo(content, 200);
        expect(away()).toBe(false);
    });

    it('stays near the top of the view', () => {
        scrollTo(content, 40);
        expect(away()).toBe(false);
    });

    it('ignores a few pixels of jitter', () => {
        scrollTo(content, 300);
        scrollTo(content, 298);
        expect(away()).toBe(true);
    });

    it('stays while the navigation is open', () => {
        wrapper.classList.add('nav-open');
        scrollTo(content, 300);
        expect(away()).toBe(false);
    });

    it('takes no notice of a table or dialog scrolling on its own', () => {
        scrollTo(content.querySelector('.table-container'), 300);
        expect(away()).toBe(false);
    });

    it('is hidden by the stylesheet only while out of the way, closed and without keyboard focus', () => {
        // Narrow screens only: the rule sits in the stylesheet's width < 1024px block
        const hiding = RULES.filter(rule => rule.declarations.opacity === '0'
            && rule.selectors.some(s => s.startsWith('#budget-nav-toggle-wrapper.' + SCROLLED_AWAY_CLASS)));
        expect(hiding.length).toBeGreaterThan(0);
        expect(hiding.every(rule => rule.selectors.some(s => s.includes(':not(.nav-open)') && s.includes(':not(:has(:focus-visible))')))).toBe(true);
    });

    it('steps aside after a mouse click too, which leaves focus on it but not keyboard focus (#399)', () => {
        // :focus-within kept a clicked button over the content until focus moved
        const hiding = RULES.filter(rule => rule.declarations.opacity === '0'
            && rule.selectors.some(s => s.startsWith('#budget-nav-toggle-wrapper.' + SCROLLED_AWAY_CLASS)));
        expect(hiding.some(rule => rule.selectors.some(s => s.includes(':focus-within')))).toBe(false);
    });
});
