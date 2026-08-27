import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ExamIntroPage from '../pages/ExamIntroPage.vue';
import ExamCompletedPage from '../pages/ExamCompletedPage.vue';
import CompetitionStatusPage from '../pages/CompetitionStatusPage.vue';
import { copy } from '../i18n/messages';

/*
| What the contestant is TOLD, checked against what the engine does.
|
| The rules on the ready screen are the only place the timing model is
| explained to a human, and they drifted once already: after the move to
| immediate advance they still promised that an early answer changes nothing.
| A contestant reading that would deliberately slow down for no reason. These
| assertions pin the copy to the behaviour.
*/

const intro = (props = {}) =>
    mount(ExamIntroPage, {
        props: {
            competitionName: 'مسابقة مداد',
            totalQuestions: 75,
            secondsPerQuestion: 40,
            examDurationMinutes: 60,
            secondsAvailable: 3600,
            examStatus: 'not_started',
            ...props,
        },
    });

describe('the ready screen states the CURRENT rules', () => {
    it('tells the contestant that answering advances immediately', () => {
        const text = intro().text();

        expect(text).toContain(copy.status.ruleImmediateAdvance);
        expect(text).toContain(copy.status.ruleNoPause);
    });

    it('no longer claims that an early answer changes nothing', () => {
        const text = intro().text();

        expect(text).not.toContain('ولا يتغيّر بالإجابة المبكرة');
        expect(text).not.toContain('وقته المحدّد منذ لحظة البدء');
    });

    it('warns a late starter that the window is shorter than the allowance', () => {
        const text = intro({ secondsAvailable: 1800 }).text();

        expect(text).toContain(copy.status.ruleShortWindow(30));
    });

    it('says nothing about a short window when the full hour is available', () => {
        expect(intro().text()).not.toContain(copy.status.ruleShortWindow(60));
    });

    it('offers resume rather than start for an in-progress attempt', () => {
        expect(intro({ examStatus: 'in_progress' }).text()).toContain(copy.status.resume);
    });
});

describe('the terminal and completion screens', () => {
    it('renders the closed state', () => {
        const w = mount(CompetitionStatusPage, {
            props: { reason: 'competition_closed', competitionName: 'مسابقة مداد', authenticated: true },
        });

        expect(w.text()).toContain(copy.status.closedTitle);
    });

    it('shows no score when the server withheld it', () => {
        const w = mount(ExamCompletedPage, {
            props: { result: { exam_status: 'completed', show_result: false }, competitionName: 'م' },
        });

        expect(w.text()).not.toMatch(/\d+\s*\/\s*\d+/);
        expect(w.text()).not.toContain('49');
    });

    it('shows the score the server sent, and only that', () => {
        const w = mount(ExamCompletedPage, {
            props: {
                result: {
                    exam_status: 'completed',
                    show_result: true,
                    correct_answers: 49,
                    answered_questions: 73,
                    total_questions: 75,
                },
                competitionName: 'م',
            },
        });

        expect(w.text()).toContain('49');
        expect(w.text()).toContain('75');
    });
});
