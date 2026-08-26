import { describe, expect, it } from 'vitest';
import { copy, messageForReason } from '../i18n/messages';

/*
| The client half of the frozen error contract.
|
| docs/API_CONTRACT.md §4 lists every `reason` the backend can emit. If one of
| them reaches the UI without Arabic copy the contestant sees the generic
| fallback, which is a silent quality regression rather than a crash — exactly
| the kind of thing that survives manual testing. This locks it.
|
| The list is transcribed from the contract deliberately: it must fail when the
| backend adds a reason and nobody translates it.
*/
const CONTRACT_REASONS = [
    'validation_error',
    'invalid_credentials',
    'too_many_attempts',
    'unauthenticated',
    'not_found',
    'competition_not_open',
    'competition_closed',
    'not_a_contestant',
    'account_not_provisioned',
    'paper_not_ready',
    'exam_completed',
    'no_current_question',
    'question_not_available',
    'question_expired',
    'server_error',
];

/** Emitted by the status endpoint when no competition row exists. */
const STATUS_REASONS = ['no_competition'];

/** Raised by the client itself, never by the server. */
const CLIENT_REASONS = ['network_error', 'answer_unconfirmed', 'unknown'];

describe('error contract coverage', () => {
    it.each(CONTRACT_REASONS)('has Arabic copy for %s', (reason) => {
        expect(copy.errors[reason]).toBeTruthy();
        expect(messageForReason(reason)).toBe(copy.errors[reason]);
        expect(messageForReason(reason)).not.toBe(copy.errors.unknown);
    });

    it.each([...STATUS_REASONS, ...CLIENT_REASONS])('has Arabic copy for %s', (reason) => {
        expect(copy.errors[reason]).toBeTruthy();
    });

    it('falls back rather than throwing on a reason nobody translated', () => {
        expect(messageForReason('a_reason_from_the_future')).toBe(copy.errors.unknown);
        expect(messageForReason(undefined)).toBe(copy.errors.unknown);
    });

    it('carries no untranslated placeholder or leftover English', () => {
        for (const [reason, text] of Object.entries(copy.errors)) {
            expect(text, reason).toMatch(/[؀-ۿ]/); // contains Arabic
            expect(text, reason).not.toMatch(/TODO|FIXME|\bnull\b|undefined/);
        }
    });

    it('never renders a backend reason code verbatim', () => {
        // The codes are machine-readable identifiers, not contestant-facing text.
        for (const reason of CONTRACT_REASONS) {
            expect(copy.errors[reason]).not.toContain(reason);
        }
    });
});
