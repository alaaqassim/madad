<script setup>
import { onMounted } from 'vue';
import MadadShell from './components/madad/MadadShell.vue';
import MadadButton from './components/madad/MadadButton.vue';
import MadadCard from './components/madad/MadadCard.vue';
import MadadSpinner from './components/madad/MadadSpinner.vue';
import LoginPage from './pages/LoginPage.vue';
import CompetitionStatusPage from './pages/CompetitionStatusPage.vue';
import ExamIntroPage from './pages/ExamIntroPage.vue';
import ExamPage from './pages/ExamPage.vue';
import ExamCompletedPage from './pages/ExamCompletedPage.vue';
import { useCompetitionExam, SCREEN } from './composables/useCompetitionExam';
import { copy } from './i18n/messages';

/*
| The contestant application.
|
| One mount, one state machine, no router: the flow is linear and every gate is
| the server's to open. Which screen shows is a function of `screen`, and the
| screen components are presentational — none of them calls the API.
*/
const exam = useCompetitionExam();

onMounted(async () => {
    /*
     * The real backend is the default everywhere, including on the dev server.
     * The mock is reachable in exactly two ways, both of them deliberate:
     *
     *   * the static demo built by vite.demo.config.js, which is the only
     *     thing that defines VITE_MADAD_DEMO;
     *   * ?mock=1 on a dev server, for visual work without a database.
     *
     * Both operands fold to a literal false in the Laravel production build —
     * DEV is false there, and VITE_MADAD_DEMO is undefined — so this branch and
     * the chunk it would import are eliminated. The shipped app contains no
     * mock adapter, no credentials, no question text, and no way for a URL to
     * talk it into one.
     */
    const mockRequested =
        import.meta.env.VITE_MADAD_DEMO === 'true' ||
        (import.meta.env.DEV && new URLSearchParams(window.location.search).get('mock') === '1');

    if (mockRequested) {
        const { installMockJourney } = await import('./dev/installMockJourney');

        installMockJourney();
    }

    await exam.boot();
});
</script>

<template>
    <MadadShell>
        <template #header-actions>
            <MadadButton
                v-if="exam.authenticated.value && exam.screen.value !== SCREEN.EXAM"
                variant="link"
                @click="exam.logout()"
            >
                {{ copy.status.signOut }}
            </MadadButton>
        </template>

        <!-- 1. Boot: reading /competition/status before anything is claimed. -->
        <MadadCard v-if="exam.screen.value === SCREEN.BOOT" class="boot">
            <MadadSpinner :size="28" />
            <p class="boot__text" role="status" aria-live="polite">{{ copy.status.checking }}</p>
        </MadadCard>

        <!-- 2. Login -->
        <LoginPage
            v-else-if="exam.screen.value === SCREEN.LOGIN"
            :loading="exam.busy.login"
            :error-reason="exam.error.value"
            :field-errors="exam.fieldErrors.value"
            @submit="exam.login($event)"
        />

        <!-- 3. Gate: not open / closed / not a contestant / transport failure -->
        <CompetitionStatusPage
            v-else-if="exam.screen.value === SCREEN.GATE"
            :reason="exam.fatalReason.value"
            :competition-name="exam.competition.name"
            :busy="exam.busy.boot"
            :authenticated="exam.authenticated.value"
            @refresh="exam.boot()"
            @logout="exam.logout()"
        />

        <!-- 4. Ready: start or resume -->
        <ExamIntroPage
            v-else-if="exam.screen.value === SCREEN.READY"
            :competition-name="exam.competition.name"
            :total-questions="exam.competition.totalQuestions"
            :seconds-per-question="exam.competition.secondsPerQuestion"
            :exam-duration-minutes="exam.competition.examDurationMinutes"
            :seconds-available="exam.competition.secondsAvailable"
            :exam-status="exam.participation.value?.exam_status"
            :busy="exam.busy.start"
            :error-reason="exam.error.value"
            @start="exam.start()"
            @retry="exam.start()"
            @logout="exam.logout()"
        />

        <!-- 5. The exam itself -->
        <ExamPage
            v-else-if="exam.screen.value === SCREEN.EXAM && exam.question.value"
            :question="exam.question.value"
            :competition-name="exam.competition.name"
            :selected="exam.selected.value"
            :can-answer="exam.canAnswer.value"
            :submitting="exam.busy.answer"
            :awaiting-server="exam.awaitingServer.value"
            :recovering="exam.busy.recover"
            :timer-seconds="exam.timer.seconds.value"
            :timer-fraction="exam.timer.fraction.value"
            :timer-warning="exam.timer.isWarning.value"
            :timer-expired="exam.timer.isExpired.value"
            :error-reason="exam.error.value"
            @select="exam.select($event)"
            @submit="exam.submitAnswer()"
            @retry="exam.refreshCurrent()"
        />

        <!-- 6. Completed, with or without a score depending on the server -->
        <ExamCompletedPage
            v-else-if="exam.screen.value === SCREEN.COMPLETED"
            :result="exam.result.value"
            :error-reason="exam.error.value"
            @retry="exam.boot()"
            @logout="exam.logout()"
        />
    </MadadShell>
</template>

<style scoped>
.boot {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--madad-space-4);
    color: var(--madad-green-700);
    text-align: center;
}

.boot__text {
    color: var(--madad-ink-muted);
    font-size: var(--madad-text-base);
}
</style>
