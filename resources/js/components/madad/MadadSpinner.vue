<script setup>
/*
| A restrained progress indicator: a thin arc in the current text colour, so it
| sits inside gold buttons, green buttons and cream cards without a palette of
| its own. Motion is suppressed under prefers-reduced-motion by madad-base.css.
*/
defineProps({
    size: { type: Number, default: 20 },
});
</script>

<template>
    <svg
        class="madad-spinner"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        aria-hidden="true"
        focusable="false"
    >
        <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.5" opacity="0.25" />
        <path
            d="M21 12a9 9 0 0 0-9-9"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
        />
    </svg>
</template>

<style scoped>
.madad-spinner {
    flex: 0 0 auto;
    animation: madad-spin 900ms linear infinite;
}

@keyframes madad-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
