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
    await exam.boot();

    /*
     * Development preview only. `import.meta.env.DEV` is a literal false in a
     * production build, so this block — and the chunk it would import — is
     * eliminated: the shipped app has no preview or debug surface. Without a
     * ?preview= scenario the harness no-ops.
     */
    if (import.meta.env.DEV) {
        const { runPreview } = await import('./dev/preview');

        await runPreview(exam);
    }
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
