<script setup>
import { computed } from 'vue';
import MadadCard from '../components/madad/MadadCard.vue';
import MadadStatusMessage from '../components/madad/MadadStatusMessage.vue';
import { copy, messageForReason } from '../i18n/messages';

/*
| The end of the paper.
|
| Two shapes, chosen by the SERVER's show_result flag. When it is false the
| score is not in the payload at all, so there is nothing here to leak; when it
| is true the numbers are printed exactly as received. Nothing on this screen
| is computed from answers the client remembers — the client never knew whether
| any of them were right.
*/
const props = defineProps({
    /** The /exam/result body, or null if it could not be read. */
    result: { type: Object, default: null },
    errorReason: { type: String, default: null },
});

// Signing out lives in the shell header, once, for every screen that offers it.
defineEmits(['retry']);

const showScore = computed(
    () => Boolean(props.result?.show_result) && typeof props.result?.correct_answers === 'number',
);

const banner = computed(() => (props.errorReason ? messageForReason(props.errorReason) : null));
</script>

<template>
    <div>
        <div class="madad-intro">
            <p class="madad-eyebrow"><span>{{ copy.completed.eyebrow }}</span></p>
        </div>

        <MadadCard class="done" tone="green">
            <div class="done__seal" aria-hidden="true">
                <svg viewBox="0 0 44 44" width="44" height="44" focusable="false">
                    <circle cx="22" cy="22" r="19" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.5" />
                    <circle cx="22" cy="22" r="14.5" fill="none" stroke="currentColor" stroke-width="1.5" />
                    <path
                        d="m15.5 22.5 4.5 4.5 9-10"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </div>

            <h1 class="done__title">{{ copy.completed.title }}</h1>
            <p class="done__body">
                {{ showScore ? copy.completed.bodyVisible : copy.completed.bodyHidden }}
            </p>

            <!-- Result-visible: printed from the response, never derived here. -->
            <dl v-if="showScore" class="done__score">
                <div class="done__score-row done__score-row--primary">
                    <dt>{{ copy.completed.scoreLabel }}</dt>
                    <dd>{{ copy.completed.score(result.correct_answers, result.total_questions) }}</dd>
                </div>
                <div v-if="typeof result.answered_questions === 'number'" class="done__score-row">
                    <dt>{{ copy.completed.answeredLabel }}</dt>
                    <dd>{{ copy.completed.answered(result.answered_questions, result.total_questions) }}</dd>
                </div>
            </dl>

            <p class="madad-visually-hidden" role="status" aria-live="polite">{{ copy.completed.title }}</p>
        </MadadCard>

        <MadadStatusMessage
            v-if="banner"
            class="done__banner"
            tone="error"
            :message="banner"
            retryable
            @retry="$emit('retry')"
        />
    </div>
</template>

<style scoped>
.done {
    text-align: center;
}

.done__seal {
    display: grid;
    place-items: center;
    inline-size: 5rem;
    block-size: 5rem;
    margin-inline: auto;
    margin-block-end: var(--madad-space-5);
    border-radius: var(--madad-radius-pill);
    background: rgba(255, 255, 255, 0.1);
    color: var(--madad-gold-400);
}

.done__title {
    font-size: var(--madad-text-h1);
}

.done__body {
    margin-block-start: var(--madad-space-4);
    color: var(--madad-on-green-muted);
    font-size: var(--madad-text-lg);
}

.done__score {
    margin: var(--madad-space-8) 0 0;
    padding: var(--madad-space-5);
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: var(--madad-radius-md);
}

.done__score-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--madad-space-4);
}

.done__score-row + .done__score-row {
    margin-block-start: var(--madad-space-4);
    padding-block-start: var(--madad-space-4);
    border-block-start: 1px solid rgba(255, 255, 255, 0.14);
}

.done__score dt {
    color: var(--madad-on-green-muted);
    font-size: var(--madad-text-sm);
}

.done__score dd {
    margin: 0;
    font-family: var(--madad-font-numeric);
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    color: var(--madad-on-green);
}

.done__score-row--primary dd {
    font-size: var(--madad-text-timer);
    color: var(--madad-gold-400);
}

.done__banner {
    margin-block-start: var(--madad-space-5);
}
</style>
