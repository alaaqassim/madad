<script setup>
import { computed } from 'vue';
import MadadSpinner from './MadadSpinner.vue';

/*
| The Madad button.
|
| `gold` is the hero CTA from the second screenshot: a fully-rounded pill in
| the accent gold with dark ink on it and a leading arrow. In RTL the arrow
| points to the inline end — left — which is why it is a rotated glyph rather
| than a hard-coded "←" that a future LTR locale would render backwards.
*/
const props = defineProps({
    variant: {
        type: String,
        default: 'gold',
        validator: (value) => ['gold', 'green', 'quiet', 'link'].includes(value),
    },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
    /** Shows the CTA's forward arrow. */
    arrow: { type: Boolean, default: false },
});

const isInert = computed(() => props.disabled || props.loading);
</script>

<template>
    <button
        :type="type"
        class="madad-btn"
        :class="[`madad-btn--${variant}`, { 'madad-btn--block': block, 'madad-btn--loading': loading }]"
        :disabled="isInert"
        :aria-busy="loading ? 'true' : undefined"
    >
        <MadadSpinner v-if="loading" class="madad-btn__spinner" :size="18" />
        <span class="madad-btn__label"><slot /></span>
        <svg
            v-if="arrow && !loading"
            class="madad-btn__arrow"
            viewBox="0 0 24 24"
            width="20"
            height="20"
            aria-hidden="true"
            focusable="false"
        >
            <path
                d="M5 12h14M13 6l6 6-6 6"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </button>
</template>

<style scoped>
.madad-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--madad-space-3);
    min-height: var(--madad-tap-min);
    padding-inline: var(--madad-space-8);
    border: 1px solid transparent;
    border-radius: var(--madad-radius-pill);
    font-family: var(--madad-font-ui);
    font-size: var(--madad-text-lg);
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    transition:
        background-color 160ms ease,
        border-color 160ms ease,
        color 160ms ease,
        box-shadow 160ms ease,
        transform 160ms ease;
}

.madad-btn--block {
    display: flex;
    width: 100%;
}

/* The arrow points along the reading direction: end-ward, so left in RTL. */
.madad-btn__arrow {
    transform: scaleX(-1);
    flex: 0 0 auto;
}

[dir='ltr'] .madad-btn__arrow {
    transform: none;
}

/* ── gold: the primary call to action ───────────────────────────────────── */
.madad-btn--gold {
    min-height: var(--madad-cta-height);
    background: var(--madad-gold-500);
    color: var(--madad-on-gold);
    box-shadow: var(--madad-shadow-cta);
}

.madad-btn--gold:hover:not(:disabled) {
    background: var(--madad-gold-400);
}

.madad-btn--gold:active:not(:disabled) {
    transform: translateY(1px);
}

/* ── green: a secondary commitment, e.g. confirming an answer ───────────── */
.madad-btn--green {
    min-height: var(--madad-cta-height);
    background: var(--madad-green-700);
    color: var(--madad-on-green);
}

.madad-btn--green:hover:not(:disabled) {
    background: var(--madad-green-600);
}

/* ── quiet: outlined, same metrics as an input ──────────────────────────── */
.madad-btn--quiet {
    min-height: var(--madad-control-height);
    background: var(--madad-surface);
    border-color: var(--madad-border);
    color: var(--madad-green-800);
    font-size: var(--madad-text-base);
}

.madad-btn--quiet:hover:not(:disabled) {
    border-color: var(--madad-green-300);
    background: var(--madad-green-050);
}

/* ── link: a low-weight escape hatch (log out, retry) ───────────────────── */
.madad-btn--link {
    padding-inline: var(--madad-space-3);
    background: none;
    color: var(--madad-green-800);
    font-size: var(--madad-text-sm);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 4px;
}

.madad-btn--link:hover:not(:disabled) {
    color: var(--madad-gold-700);
}

.madad-btn:disabled {
    cursor: not-allowed;
    background: var(--madad-disabled-surface);
    border-color: var(--madad-disabled-border);
    color: var(--madad-disabled-ink);
    box-shadow: none;
}

/* Loading is still recognisably the same button, just held. */
.madad-btn--loading:disabled {
    background: var(--madad-gold-100);
    border-color: var(--madad-gold-200);
    color: var(--madad-gold-700);
}

.madad-btn--green.madad-btn--loading:disabled {
    background: var(--madad-green-050);
    border-color: var(--madad-green-300);
    color: var(--madad-green-800);
}
</style>
