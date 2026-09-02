/**
 * The import API has always returned a reason per failed row, and the UI threw
 * them away: the toast said "check the server log for details" and the reasons
 * went to console.warn. On the instance in #333 that was a dead end - logging
 * was broken there, so 14 of 27 rows vanished with no way to find out why.
 */

import { describe, it, expect } from 'vitest';

import { groupImportErrors } from '../../src/utils/helpers.js';

describe('groupImportErrors', () => {
    it('collapses one repeated reason into a single entry carrying its rows', () => {
        const grouped = groupImportErrors([
            { row: 2, error: 'This row has no account, and no account was chosen for the import' },
            { row: 5, error: 'This row has no account, and no account was chosen for the import' },
            { row: 9, error: 'This row has no account, and no account was chosen for the import' },
        ]);

        expect(grouped).toHaveLength(1);
        expect(grouped[0].count).toBe(3);
        expect(grouped[0].rows).toEqual([2, 5, 9]);
    });

    it('keeps different reasons apart, most frequent first', () => {
        const grouped = groupImportErrors([
            { row: 1, error: 'Invalid date format: Date:' },
            { row: 3, error: 'Could not resolve account: Sparkonto' },
            { row: 4, error: 'Could not resolve account: Sparkonto' },
        ]);

        expect(grouped.map(g => g.message)).toEqual([
            'Could not resolve account: Sparkonto',
            'Invalid date format: Date:',
        ]);
        expect(grouped[0].count).toBe(2);
        expect(grouped[1].rows).toEqual([1]);
    });

    it('survives an entry with no message or row', () => {
        const grouped = groupImportErrors([{ row: 7 }, { error: '   ' }]);

        expect(grouped).toHaveLength(1);
        expect(grouped[0].message).toBe('Unknown error');
        expect(grouped[0].count).toBe(2);
        expect(grouped[0].rows).toEqual([7]);
    });

    it('is empty for no errors at all', () => {
        expect(groupImportErrors([])).toEqual([]);
        expect(groupImportErrors(undefined)).toEqual([]);
    });
});
