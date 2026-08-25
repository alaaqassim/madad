<script setup>
import { computed, ref, watch } from 'vue';
import MadadCard from '../components/madad/MadadCard.vue';
import MadadButton from '../components/madad/MadadButton.vue';
import MadadProgress from '../components/madad/MadadProgress.vue';
import MadadTimer from '../components/madad/MadadTimer.vue';
import MadadExamOption from '../components/madad/MadadExamOption.vue';
import MadadStatusMessage from '../components/madad/MadadStatusMessage.vue';
import { copy, messageForReason } from '../i18n/messages';

/*
| The question screen.
|
| It renders exactly what the server sent and nothing it worked out for itself.
| In particular there is no correctness feedback anywhere on this screen: the
| payload does not carry it, and the design does not imply it — selection is
| gold-on-green because that is the Madad accent, not because it means "right".
*/
const props = defineProps({
    question: { type: Object, required: true },
    competitionName: { type: String, default: null },
    selected: { type: String, default: null },
    canAnswer: { type: Boolean, default: true },
    submitting: { type: Boolean, default: false },
    /** The countdown has hit zero, or a submission is unresolved. */
    awaitingServer: { type: Boolean, default: false },
    recovering: { type: Boolean, default: false },
    timerSeconds: { type: Number, default: null },
    timerFraction: { type: Number, default: 0 },
    timerWarning: { type: Boolean, default: false },
    timerExpired: { type: Boolean, default: false },
    errorReason: { type: String, default: null },
});

const emit = defineEmits(['select', 'submit', 'retry']);

const LETTERS = ['A', 'B', 'C', 'D'];

const groupRef = ref(null);
const attemptedWithoutChoice = ref(false);

const letters = computed(() => LETTERS.filter((letter) => props.question.options?.[letter] != null));

/** The one option that holds the group's tab stop. */
const focusedLetter = computed(() => props.selected ?? letters.value[0] ?? null);

const optionsDisabled = computed(() => !props.canAnswer);

const banner = computed(() => {
    if (props.timerExpired || props.awaitingServer) {
        // Waiting on the server outranks the underlying cause: whatever went
        // wrong, the honest thing to show is that we are re-reading state.
        return {
            tone: 'warning',
            title: props.timerExpired ? copy.exam.timeoutTitle : null,
            message: props.errorReason && !props.timerExpired
                ? `${messageForReason(props.errorReason)} ${copy.exam.syncing}`
                : props.recovering
                  ? copy.exam.syncing
                  : copy.exam.timeoutBody,
            // A retry is offered only once we have stopped trying ourselves.
            retryable: !props.recovering,
        };
    }

    /*
     * The submission was never confirmed and the server says this question is
     * still open. There is nothing to retry — the contestant simply chooses
     * again — so this is a warning with no retry button.
     */
    if (props.errorReason === 'answer_unconfirmed') {
        return { tone: 'warning', title: null, message: copy.errors.answer_unconfirmed, retryable: false };
    }

    if (props.errorReason) {
        return {
            tone: 'error',
            title: null,
            message: messageForReason(props.errorReason),
            retryable: true,
        };
    }

    if (attemptedWithoutChoice.value) {
        return { tone: 'info', title: null, message: copy.exam.chooseFirst, retryable: false };
    }

    return null;
});

// A new question clears the "pick something" nudge from the previous one.
watch(
    () => props.question?.question_id,
    () => {
        attemptedWithoutChoice.value = false;
    },
);

watch(
    () => props.selected,
    (value) => {
        if (value) {
            attemptedWithoutChoice.value = false;
        }
    },
);

function choose(letter) {
    emit('select', letter);
}

function submit() {
    if (!props.selected) {
        attemptedWithoutChoice.value = true;

        return;
    }

    emit('submit');
}

/*
| Radiogroup keyboard behaviour. Up/Down move through the list; in RTL,
| ArrowLeft is forward and ArrowRight is back, because that is the direction
| the reader is travelling.
*/
function onKeydown(event) {
    if (optionsDisabled.value) {
        return;
    }

    const forward = ['ArrowDown', 'ArrowLeft'];
    const backward = ['ArrowUp', 'ArrowRight'];

    if (![...forward, ...backward].includes(event.key)) {
        return;
    }

    event.preventDefault();

    const list = letters.value;
    const index = Math.max(0, list.indexOf(focusedLetter.value));
    const step = forward.includes(event.key) ? 1 : -1;
    const next = list[(index + step + list.length) % list.length];

    emit('select', next);

    // Move focus with the selection, as a native radiogroup does.
    requestAnimationFrame(() => {
        groupRef.value?.querySelector('[tabindex="0"]')?.focus();
    });
}
</script>

<template>
    <div class="exam">
        <p class="exam__context">{{ competitionName ?? copy.competitionFallback }}</p>

        <div class="exam__meter">
            <MadadProgress
                class="exam__progress"
                :current="question.sequence"
                :total="question.total_questions"
            />

            <MadadTimer
                class="exam__timer"
                :seconds="timerSeconds"
                :fraction="timerFraction"
                :warning="timerWarning"
                :expired="timerExpired || awaitingServer"
            />
        </div>

        <MadadCard class="exam__card">
            <h1 class="exam__question">{{ question.question_text }}</h1>

            <div
                ref="groupRef"
                class="exam__options"
                role="radiogroup"
                :aria-label="copy.exam.optionsLabel"
                :aria-disabled="optionsDisabled ? 'true' : 'false'"
                @keydown="onKeydown"
            >
                <MadadExamOption
                    v-for="letter in letters"
                    :key="letter"
                    :letter="letter"
                    :text="question.options[letter]"
                    :selected="selected === letter"
                    :disabled="optionsDisabled"
                    :submitting="submitting && selected === letter"
                    :focusable="focusedLetter === letter"
                    @choose="choose"
                />
            </div>

            <MadadButton
                class="exam__submit"
                variant="gold"
                block
                arrow
                :loading="submitting"
                :disabled="!canAnswer && !submitting"
                @click="submit"
            >
                {{ submitting ? copy.exam.submitting : copy.exam.submit }}
            </MadadButton>
        </MadadCard>

        <MadadStatusMessage
            v-if="banner"
            class="exam__banner"
            :tone="banner.tone"
            :title="banner.title"
            :message="banner.message"
            :retryable="banner.retryable"
            :busy="recovering"
            @retry="$emit('retry')"
        />

        <p class="exam__note">{{ copy.exam.leaveWarning }}</p>
    </div>
</template>

<style scoped>
.exam__context {
    text-align: center;
    color: var(--madad-gold-600);
    font-size: var(--madad-text-eyebrow);
    font-weight: 600;
    letter-spacing: var(--madad-tracking-eyebrow);
    margin-block-end: var(--madad-space-5);
}

/*
| Progress and timer share one band. On a phone they stack, progress first,
| because knowing where you are matters before knowing how long you have.
*/
.exam__meter {
    display: flex;
    flex-direction: column;
    gap: var(--madad-space-4);
    padding: var(--madad-space-5);
    background: var(--madad-surface);
    border: 1px solid var(--madad-border);
    border-radius: var(--madad-radius-lg);
    box-shadow: var(--madad-shadow-card);
}

.exam__progress {
    flex: 1 1 auto;
    min-inline-size: 0;
}

.exam__card {
    margin-block-start: var(--madad-space-5);
}

.exam__question {
    font-size: var(--madad-text-h2);
    line-height: 1.75;
    color: var(--madad-green-900);
}

.exam__options {
    margin-block-start: var(--madad-space-8);
}

.exam__submit {
    margin-block-start: var(--madad-space-8);
}

.exam__banner {
    margin-block-start: var(--madad-space-5);
}

.exam__note {
    margin-block-start: var(--madad-space-5);
    text-align: center;
    color: var(--madad-ink-muted);
    font-size: var(--madad-text-sm);
}

@media (min-width: 30rem) {
    .exam__meter {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        gap: var(--madad-space-6);
    }

    .exam__timer {
        flex: 0 0 auto;
    }
}
</style>
