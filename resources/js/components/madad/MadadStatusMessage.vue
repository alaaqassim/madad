<script setup>
import { computed } from 'vue';
import MadadButton from './MadadButton.vue';
import MadadSpinner from './MadadSpinner.vue';
import { copy } from '../../i18n/messages';

/*
| The one banner used for every backend refusal and every transport failure.
|
| It takes a translated Arabic sentence — the caller resolves it from the
| response's `reason` code — so no Laravel message, validation string or
| exception text can reach a contestant's screen through this component.
|
| Every tone carries its own glyph as well as its own colour: the meaning
| survives a greyscale screen or a colour-blind reader.
*/
const props = defineProps({
    tone: {
        type: String,
        default: 'error',
        validator: (value) => ['error', 'warning', 'info', 'success'].includes(value),
    },
    title: { type: String, default: null },
    message: { type: String, required: true },
    /** Renders a retry affordance; emit target for the caller's safe re-read. */
    retryable: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    /** Errors are announced assertively; progress notes politely. */
    live: {
        type: String,
        default: null,
        validator: (value) => value === null || ['polite', 'assertive'].includes(value),
    },
});

defineEmits(['retry']);

const liveness = computed(() => props.live ?? (props.tone === 'error' ? 'assertive' : 'polite'));
const role = computed(() => (props.tone === 'error' ? 'alert' : 'status'));
</script>

<template>
    <div class="madad-status" :class="`madad-status--${tone}`" :role="role" :aria-live="liveness">
        <MadadSpinner v-if="busy" class="madad-status__icon" :size="20" />

        <svg
            v-else
            class="madad-status__icon"
            viewBox="0 0 20 20"
            width="20"
            height="20"
            aria-hidden="true"
            focusable="false"
        >
            <template v-if="tone === 'success'">
                <circle cx="10" cy="10" r="8.4" fill="none" stroke="currentColor" stroke-width="1.5" />
                <path
                    d="m6.2 10.3 2.6 2.6 5-5.4"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </template>
            <template v-else-if="tone === 'info'">
                <circle cx="10" cy="10" r="8.4" fill="none" stroke="currentColor" stroke-width="1.5" />
                <path
                    d="M10 9v5M10 6.2v.2"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
            </template>
            <template v-else>
                <path
                    d="M10 2.6 18.6 17.4H1.4zM10 8.2v4M10 14.6v.2"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </template>
        </svg>

        <div class="madad-status__body">
            <p v-if="title" class="madad-status__title">{{ title }}</p>
            <p class="madad-status__message">{{ message }}</p>

            <MadadButton
                v-if="retryable"
                class="madad-status__retry"
                variant="quiet"
                :loading="busy"
                @click="$emit('retry')"
            >
                {{ copy.actions.retry }}
            </MadadButton>
        </div>
    </div>
</template>

<style scoped>
.madad-status {
    display: flex;
    align-items: flex-start;
    gap: var(--madad-space-3);
    padding: var(--madad-space-4);
    border: 1px solid;
    border-radius: var(--madad-radius-md);
    font-size: var(--madad-text-sm);
    line-height: 1.75;
}

.madad-status__icon {
    margin-top: 0.2rem;
}

.madad-status__body {
    flex: 1 1 auto;
    min-width: 0;
}

.madad-status__title {
    font-weight: 700;
    margin-bottom: var(--madad-space-1);
}

.madad-status__retry {
    margin-top: var(--madad-space-3);
}

.madad-status--error {
    background: var(--madad-danger-surface);
    border-color: var(--madad-danger-border);
    color: var(--madad-danger-ink);
}

.madad-status--warning {
    background: var(--madad-warning-surface);
    border-color: var(--madad-warning-border);
    color: var(--madad-warning);
}

.madad-status--info {
    background: var(--madad-gold-050);
    border-color: var(--madad-gold-200);
    color: var(--madad-gold-700);
}

.madad-status--success {
    background: var(--madad-success-surface);
    border-color: var(--madad-success-border);
    color: var(--madad-success);
}
</style>
