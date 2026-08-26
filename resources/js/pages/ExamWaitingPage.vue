<script setup>
import MadadCard from '../components/madad/MadadCard.vue';
import MadadProgress from '../components/madad/MadadProgress.vue';
import MadadTimer from '../components/madad/MadadTimer.vue';
import MadadStatusMessage from '../components/madad/MadadStatusMessage.vue';
import { copy, messageForReason } from '../i18n/messages';

/*
| The transition between one fixed slot and the next.
|
| A contestant who answers in five seconds does not get the next question five
| seconds early: every position owns a fixed forty-second slot measured from
| started_at, and this screen is what the remainder of the current one looks
| like. It is the same card, the same dial and the same progress bar as the
| question screen — deliberately, so the wait reads as part of the exam rather
| than as an error state.
|
| Like every other screen here it decides nothing. The countdown it shows is the
| server's `seconds_remaining`; reaching zero only prompts a re-read.
*/
defineProps({
    /** The waiting payload: sequence, total_questions, seconds_remaining. */
    waiting: { type: Object, required: true },
    competitionName: { type: String, default: null },
    timerSeconds: { type: Number, default: null },
    timerFraction: { type: Number, default: 0 },
    recovering: { type: Boolean, default: false },
    errorReason: { type: String, default: null },
});

defineEmits(['retry']);
</script>

<template>
    <div class="waiting">
        <MadadProgress :current="waiting.sequence" :total="waiting.total_questions" />

        <MadadCard class="waiting__card">
            <p v-if="competitionName" class="waiting__eyebrow">{{ competitionName }}</p>

            <MadadTimer :seconds="timerSeconds" :fraction="timerFraction" />

            <h2 class="waiting__title">{{ copy.exam.waitingTitle }}</h2>
            <p class="waiting__body" role="status" aria-live="polite">
                {{ recovering ? copy.exam.syncing : copy.exam.waitingBody(waiting.sequence) }}
            </p>
            <p class="waiting__note">{{ copy.exam.waitingNote }}</p>
        </MadadCard>

        <MadadStatusMessage
            v-if="errorReason"
            class="waiting__banner"
            tone="error"
            :message="messageForReason(errorReason)"
            :retryable="!recovering"
            :busy="recovering"
            @retry="$emit('retry')"
        />
    </div>
</template>

<style scoped>
.waiting__card {
    margin-block-start: var(--madad-space-5);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--madad-space-4);
}

.waiting__eyebrow {
    margin: 0;
    color: var(--madad-ink-muted);
    font-size: var(--madad-text-sm);
}

.waiting__title {
    margin: 0;
    font-size: var(--madad-text-h2);
    color: var(--madad-ink);
}

.waiting__body {
    margin: 0;
    max-inline-size: 34rem;
    color: var(--madad-ink);
    font-size: var(--madad-text-base);
    line-height: var(--madad-leading-body);
}

.waiting__note {
    margin: 0;
    color: var(--madad-ink-muted);
    font-size: var(--madad-text-sm);
}

.waiting__banner {
    margin-block-start: var(--madad-space-5);
}
</style>
