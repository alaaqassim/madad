import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ExamPage from '../pages/ExamPage.vue';
import ExamCompletedPage from '../pages/ExamCompletedPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import CompetitionStatusPage from '../pages/CompetitionStatusPage.vue';
import { copy } from '../i18n/messages';

const QUESTION = {
    question_id: 51,
    question_text: 'ما هو الفصل الذي يتناول العدالة الاجتماعية؟',
    options: { A: 'الأول', B: 'الثاني', C: 'الثالث', D: 'الرابع' },
    sequence: 12,
    total_questions: 75,
    opened_at: '2026-08-26T10:00:00+00:00',
    expires_at: '2026-08-26T10:00:40+00:00',
    server_time: '2026-08-26T10:00:03+00:00',
    seconds_remaining: 37,
};

const mountExam = (props = {}) =>
    mount(ExamPage, {
        props: {
            question: QUESTION,
            competitionName: 'المسابقة الطلابيّة',
            timerSeconds: 37,
            timerFraction: 0.92,
            ...props,
        },
    });

describe('ExamPage', () => {
    it('renders the question and all four options', () => {
        const wrapper = mountExam();

        expect(wrapper.text()).toContain(QUESTION.question_text);

        const options = wrapper.findAll('[role="radio"]');
        expect(options).toHaveLength(4);
        expect(options.map((o) => o.text())).toEqual(
            expect.arrayContaining(['A الأول', 'B الثاني', 'C الثالث', 'D الرابع'].map((t) => expect.stringContaining(t.slice(2)))),
        );
    });

    it('shows sequential progress as "السؤال 12 من 75"', () => {
        const wrapper = mountExam();

        expect(wrapper.text()).toContain(copy.exam.progress(12, 75));
        // Not a 75-item navigator: only the four answers are interactive.
        expect(wrapper.findAll('[role="radio"]')).toHaveLength(4);
    });

    it('emits the chosen letter and marks it checked', async () => {
        const wrapper = mountExam();

        await wrapper.findAll('[role="radio"]')[2].trigger('click');
        expect(wrapper.emitted('select')[0]).toEqual(['C']);

        await wrapper.setProps({ selected: 'C' });
        expect(wrapper.findAll('[role="radio"]')[2].attributes('aria-checked')).toBe('true');
        expect(wrapper.findAll('[role="radio"]')[0].attributes('aria-checked')).toBe('false');
    });

    it('never renders correctness feedback for a chosen answer', async () => {
        const wrapper = mountExam({ selected: 'A' });
        await wrapper.vm.$nextTick();

        const html = wrapper.html();
        expect(html).not.toContain('correct');
        expect(html).not.toMatch(/صحيح|خاطئ|إجابة صحيحة/);
    });

    it('asks for a choice instead of submitting an empty answer', async () => {
        const wrapper = mountExam();

        await wrapper.find('.exam__submit').trigger('click');

        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.text()).toContain(copy.exam.chooseFirst);
    });

    it('submits the selected answer', async () => {
        const wrapper = mountExam({ selected: 'B' });

        await wrapper.find('.exam__submit').trigger('click');

        expect(wrapper.emitted('submit')).toHaveLength(1);
    });

    it('disables every option and the submit button while sending', () => {
        const wrapper = mountExam({ selected: 'B', submitting: true, canAnswer: false });

        expect(wrapper.findAll('[role="radio"]').every((o) => o.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.find('.exam__submit').attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).toContain(copy.exam.submitting);
    });

    it('freezes interaction and explains itself when the timer runs out', () => {
        const wrapper = mountExam({ canAnswer: false, timerExpired: true, timerSeconds: 0, timerFraction: 0 });

        expect(wrapper.find('[role="radiogroup"]').attributes('aria-disabled')).toBe('true');
        expect(wrapper.findAll('[role="radio"]').every((o) => o.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.text()).toContain(copy.exam.timeoutTitle);
    });

    it('offers a retry after a network failure and asks for current state', async () => {
        const wrapper = mountExam({
            canAnswer: false,
            awaitingServer: true,
            errorReason: 'network_error',
        });

        expect(wrapper.text()).toContain(copy.errors.network_error);

        await wrapper.find('.madad-status__retry').trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
    });

    it('moves selection with the arrow keys, RTL-forward on ArrowLeft', async () => {
        const wrapper = mountExam({ selected: 'A' });

        await wrapper.find('[role="radiogroup"]').trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.emitted('select').at(-1)).toEqual(['B']);

        await wrapper.find('[role="radiogroup"]').trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.emitted('select').at(-1)).toEqual(['D']); // wraps backwards from A
    });

    it('keeps exactly one tab stop in the option group', () => {
        const wrapper = mountExam({ selected: 'C' });

        const tabbable = wrapper.findAll('[role="radio"]').filter((o) => o.attributes('tabindex') === '0');
        expect(tabbable).toHaveLength(1);
        expect(tabbable[0].text()).toContain('الثالث');
    });
});

describe('ExamCompletedPage', () => {
    it('shows completion without any score when the server hides results', () => {
        const wrapper = mount(ExamCompletedPage, {
            props: { result: { exam_status: 'completed', completed_at: 'x', show_result: false } },
        });

        expect(wrapper.text()).toContain(copy.completed.title);
        expect(wrapper.text()).toContain(copy.completed.bodyHidden);
        expect(wrapper.find('.done__score').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(/\d+\s*من\s*\d+/);
    });

    it('prints the score exactly as the server sent it', () => {
        const wrapper = mount(ExamCompletedPage, {
            props: {
                result: {
                    exam_status: 'completed',
                    completed_at: 'x',
                    show_result: true,
                    correct_answers: 62,
                    answered_questions: 70,
                    total_questions: 75,
                },
            },
        });

        expect(wrapper.text()).toContain('62 من 75');
        expect(wrapper.text()).toContain('70 من 75');
    });

    it('still renders the completion state if the result read failed', () => {
        const wrapper = mount(ExamCompletedPage, {
            props: { result: null, errorReason: 'network_error' },
        });

        expect(wrapper.text()).toContain(copy.completed.title);
        expect(wrapper.text()).toContain(copy.errors.network_error);
    });
});

describe('LoginPage', () => {
    it('submits the email and password the backend contract expects', async () => {
        const wrapper = mount(LoginPage);

        await wrapper.find('input[type="email"]').setValue(' contestant@madad.test ');
        await wrapper.find('input[type="password"]').setValue('secret-password');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('submit')[0]).toEqual([
            { email: 'contestant@madad.test', password: 'secret-password' },
        ]);
    });

    it('does not submit an incomplete form and marks the empty field invalid', async () => {
        const wrapper = mount(LoginPage);

        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.find('input[type="email"]').attributes('aria-invalid')).toBe('true');
    });

    it('shows the Arabic invalid-credentials copy, never a server string', () => {
        const wrapper = mount(LoginPage, {
            props: { errorReason: 'invalid_credentials', fieldErrors: { email: ['These credentials do not match our records.'] } },
        });

        expect(wrapper.text()).toContain(copy.errors.invalid_credentials);
        expect(wrapper.text()).not.toContain('These credentials');
    });

    it('marks both controls invalid and points them at the one banner', () => {
        const wrapper = mount(LoginPage, { props: { errorReason: 'invalid_credentials' } });

        const email = wrapper.find('input[type="email"]');
        const password = wrapper.find('input[type="password"]');

        expect(email.attributes('aria-invalid')).toBe('true');
        expect(password.attributes('aria-invalid')).toBe('true');
        expect(email.attributes('aria-describedby')).toContain('madad-login-banner');

        // The sentence appears once, not once per field.
        const occurrences = wrapper.text().split(copy.errors.invalid_credentials).length - 1;
        expect(occurrences).toBe(1);
    });

    it('shows the rate-limit copy', () => {
        const wrapper = mount(LoginPage, { props: { errorReason: 'too_many_attempts' } });

        expect(wrapper.text()).toContain(copy.errors.too_many_attempts);
    });

    it('locks the form while authenticating', () => {
        const wrapper = mount(LoginPage, { props: { loading: true } });

        expect(wrapper.find('input[type="email"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain(copy.login.submitting);
    });
});

describe('CompetitionStatusPage', () => {
    it('offers a refresh while the competition has not opened yet', async () => {
        const wrapper = mount(CompetitionStatusPage, { props: { reason: 'competition_not_open' } });

        expect(wrapper.text()).toContain(copy.status.notOpenTitle);

        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('refresh')).toHaveLength(1);
    });

    it('offers no retry once the competition has closed', () => {
        const wrapper = mount(CompetitionStatusPage, { props: { reason: 'competition_closed' } });

        expect(wrapper.text()).toContain(copy.status.closedTitle);
        expect(wrapper.text()).not.toContain(copy.status.refresh);
        expect(wrapper.text()).not.toContain(copy.actions.retry);
    });

    it('explains a roster problem without exposing internal vocabulary', () => {
        const wrapper = mount(CompetitionStatusPage, { props: { reason: 'not_a_contestant' } });

        expect(wrapper.text()).toContain(copy.errors.not_a_contestant);
        expect(wrapper.text()).not.toContain('not_a_contestant');
    });
});
