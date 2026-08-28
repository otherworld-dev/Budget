/**
 * ApiErrorHandlerTrait attaches a sanitised driver error as `detail` on failed
 * responses, specifically so a database problem is diagnosable on instances
 * where the admin cannot read nextcloud.log. Thirty controllers produce it and
 * nothing in the UI ever read it, so a missing column surfaced to the user as
 * "Failed to create bill" and nothing else (#362).
 */

import { describe, it, expect } from 'vitest';

import { serverErrorMessage } from '../../src/utils/helpers.js';

describe('serverErrorMessage', () => {
    it('adds the hint that says how to fix a database left behind by an update', () => {
        const msg = serverErrorMessage({
            error: 'Failed to create bill',
            detail: "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'amount_type'",
            hint: 'An administrator can complete it by running: occ app:disable budget && occ app:enable budget',
        }, 'Failed');

        expect(msg).toContain('Failed to create bill');
        expect(msg).toContain("Unknown column 'amount_type'");
        expect(msg).toContain('occ app:disable budget');
    });

    it('is unchanged when the server sent no hint', () => {
        expect(serverErrorMessage({ error: 'Nope', detail: 'SQLSTATE[23000]: constraint' }, 'Failed'))
            .toBe('Nope (SQLSTATE[23000]: constraint)');
    });

    it('shows a hint even when there is no detail', () => {
        expect(serverErrorMessage({ error: 'Nope', hint: 'do the thing' }, 'Failed'))
            .toContain('do the thing');
    });

    it('uses the server message when there is one', () => {
        expect(serverErrorMessage({ error: 'Auto-pay requires an account' }, 'Failed'))
            .toBe('Auto-pay requires an account');
    });

    it('falls back when the server sent no message', () => {
        expect(serverErrorMessage({}, 'Failed to create bill')).toBe('Failed to create bill');
    });

    it('appends the database detail so the cause is visible', () => {
        const msg = serverErrorMessage(
            { error: 'Failed to create bill', detail: 'SQLSTATE[HY000]: no such column: amount_type' },
            'Failed',
        );

        expect(msg).toContain('Failed to create bill');
        expect(msg).toContain('no such column: amount_type');
    });

    it('appends the detail even when the server message is missing', () => {
        const msg = serverErrorMessage({ detail: 'SQLSTATE[HY000]: disk I/O error' }, 'Failed to save');

        expect(msg).toContain('Failed to save');
        expect(msg).toContain('disk I/O error');
    });

    it('survives a body that is not an object', () => {
        expect(serverErrorMessage(null, 'Failed to save')).toBe('Failed to save');
        expect(serverErrorMessage(undefined, 'Failed to save')).toBe('Failed to save');
    });

    it('ignores a blank detail rather than trailing empty brackets', () => {
        expect(serverErrorMessage({ error: 'Nope', detail: '   ' }, 'Failed')).toBe('Nope');
    });
});
