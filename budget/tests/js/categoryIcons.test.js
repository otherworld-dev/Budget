/**
 * Category icons and the form's icon picker (#440).
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {
    DEFAULT_CATEGORY_ICON,
    categoryIconChoices,
    categoryIconColour,
    categoryIconSvg,
    resolveCategoryIcon,
} from '../../src/utils/categoryIcons.js';

const root = path.resolve(__dirname, '../..');

describe('category icons', () => {
    it('draws every icon the default categories are seeded with', () => {
        // The seeds were written for Nextcloud icon classes that mostly don't
        // exist, so the circles came out empty. Every one must now be known.
        const php = fs.readFileSync(path.join(root, 'lib/Service/CategoryService.php'), 'utf8');
        const seeded = [...new Set([...php.matchAll(/'icon' => '([a-z-]+)'/g)].map(m => m[1]))];
        expect(seeded.length).toBeGreaterThan(20);
        const known = new Set(categoryIconChoices().map(c => c.key));
        expect(seeded.filter(key => !known.has(key))).toEqual([]);
    });

    it('offers unique keys that fit the server-side length limit', () => {
        const keys = categoryIconChoices().map(c => c.key);
        expect(new Set(keys).size).toBe(keys.length);
        // ValidationService::MAX_ICON_LENGTH
        expect(keys.every(key => key.length <= 50)).toBe(true);
        expect(categoryIconChoices().every(c => c.label && c.path)).toBe(true);
    });

    it('falls back to the tag for a missing or unknown key', () => {
        expect(resolveCategoryIcon('icon-home')).toBe('icon-home');
        expect(resolveCategoryIcon(null)).toBe(DEFAULT_CATEGORY_ICON);
        expect(resolveCategoryIcon('icon-made-up')).toBe(DEFAULT_CATEGORY_ICON);
        expect(categoryIconSvg('icon-made-up')).toBe(categoryIconSvg(DEFAULT_CATEGORY_ICON));
        expect(categoryIconSvg(undefined)).toContain('<path d="M');
    });

    it('never puts the stored key into the markup', () => {
        const svg = categoryIconSvg('"><img src=x onerror=alert(1)>');
        expect(svg).not.toContain('onerror');
    });

    it('picks an icon colour that reads on the circle', () => {
        expect(categoryIconColour('#ffff00')).toBe('#171717');
        expect(categoryIconColour('#1e3a8a')).toBe('#ffffff');
        // Short hex is valid server-side and must not come out as NaN.
        expect(categoryIconColour('#ff0')).toBe('#171717');
        expect(categoryIconColour(null)).toBe('#ffffff');
        expect(categoryIconColour('red')).toBe('#ffffff');
    });
});

describe('category form', () => {
    const template = fs.readFileSync(path.join(root, 'templates/index.php'), 'utf8');
    const module = fs.readFileSync(path.join(root, 'src/modules/categories/CategoriesModule.js'), 'utf8');

    it('has an icon picker that the save sends', () => {
        expect(template).toContain('id="category-icon"');
        expect(template).toContain('id="category-icon-picker"');
        expect(module).toMatch(/const categoryData = \{ name, color, icon \}/);
    });

    it('no longer renders the icon as a Nextcloud class name', () => {
        expect(module).not.toContain('class="${dom.escapeHtml(category.icon');
        expect(module).not.toContain('large ${category.icon');
    });
});
