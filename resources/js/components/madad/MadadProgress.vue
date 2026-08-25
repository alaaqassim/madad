<script setup>
import { computed } from 'vue';
import { copy } from '../../i18n/messages';

/*
| Sequential progress: "السؤال 12 من 75" plus a hairline bar in the accent gold.
|
| Deliberately not a 75-item navigator — the paper is strictly sequential and a
| grid of numbers would imply a freedom of movement the backend does not grant.
*/
const props = defineProps({
    current: { type: Number, required: true },
    total: { type: Number, required: true },
});

const percent = computed(() => {
    if (!props.total) {
        return 0;
    }

    return Math.min(100, Math.max(0, ((props.current - 1) / props.total) * 100));
});
</script>

<template>
    <div class="madad-progress">
        <p class="madad-progress__label">{{ copy.exam.progress(current, total) }}</p>

        <div
            class="madad-progress__track"
            role="progressbar"
            :aria-valuenow="current"
            aria-valuemin="1"
            :aria-valuemax="total"
            :aria-label="copy.exam.progressLabel"
            :aria-valuetext="copy.exam.progress(current, total)"
        >
            <div class="madad-progress__fill" :style="{ inlineSize: `${percent}%` }"></div>
        </div>
    </div>
</template>

<style scoped>
.madad-progress__label {
    color: var(--madad-green-800);
    font-size: var(--madad-text-sm);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    margin-bottom: var(--madad-space-2);
}

.madad-progress__track {
    block-size: 6px;
    background: var(--madad-cream-deep);
    border-radius: var(--madad-radius-pill);
    overflow: hidden;
}

/* Logical sizing: the fill grows from the inline start, i.e. the right in RTL. */
.madad-progress__fill {
    block-size: 100%;
    background: var(--madad-gold-500);
    border-radius: var(--madad-radius-pill);
    transition: inline-size 300ms ease;
}
</style>
