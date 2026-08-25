<script setup>
/*
| The white content card from the registration screenshot: large radius, soft
| green-tinted shadow, generous padding. `tone="green"` is the same card in the
| hero's deep green, used for the exam's question surface headline block.
*/
defineProps({
    tone: {
        type: String,
        default: 'surface',
        validator: (value) => ['surface', 'green'].includes(value),
    },
    /** A section label in gold, matching "المعلومات الشخصية" in the source UI. */
    heading: { type: String, default: null },
});
</script>

<template>
    <section class="madad-card" :class="`madad-card--${tone}`">
        <h2 v-if="heading" class="madad-section-heading">{{ heading }}</h2>
        <slot />
    </section>
</template>

<style scoped>
.madad-card {
    border-radius: var(--madad-radius-lg);
    padding: var(--madad-space-6);
    box-shadow: var(--madad-shadow-card);
}

.madad-card--surface {
    background: var(--madad-surface);
    border: 1px solid var(--madad-border);
}

.madad-card--green {
    background: var(--madad-green-700);
    border: 1px solid var(--madad-green-700);
    color: var(--madad-on-green);
}

.madad-card--green :deep(h1),
.madad-card--green :deep(h2),
.madad-card--green :deep(h3) {
    color: var(--madad-on-green);
}

@media (min-width: 30rem) {
    .madad-card {
        padding: var(--madad-space-8);
    }
}
</style>
