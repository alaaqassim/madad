<script setup>
/*
| The brand mark.
|
| A reconstruction of the gold arched medallion in the header of the source
| screenshots, drawn as an inline SVG so it inherits the gold token and stays
| crisp at any density. It is deliberately geometric rather than a traced
| imitation of the calligraphy: when the real brand asset is dropped into
| public/, replace the <svg> below and nothing else changes.
*/
defineProps({
    size: { type: Number, default: 40 },
});
</script>

<template>
    <svg
        class="madad-mark"
        :width="size"
        :height="size"
        viewBox="0 0 48 48"
        role="img"
        aria-label="شعار مداد الغدير"
        focusable="false"
    >
        <!-- Arched frame -->
        <path
            d="M7 44V20a17 17 0 0 1 34 0v24z"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linejoin="round"
        />
        <!-- Inner arch, the ornamental double-line of the original -->
        <path
            d="M12 43V20.5a12 12 0 0 1 24 0V43z"
            fill="none"
            stroke="currentColor"
            stroke-width="1"
            opacity="0.5"
            stroke-linejoin="round"
        />
        <!--
            An interlaced ribbon filling the arch: a compressed stand-in for the
            calligraphy in the real emblem. Abstract on purpose — an imitation
            of the script at 38px would only read as noise.
        -->
        <path
            d="M17 34c4-2 5-7 3-10m11 10c-4-2-5-7-3-10"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
        />
        <path
            d="M17.5 27.5h13"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
        />
        <path
            d="M20 37.5h8"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            opacity="0.7"
        />
    </svg>
</template>

<style scoped>
.madad-mark {
    color: var(--madad-gold-500);
    flex: 0 0 auto;
    display: block;
}
</style>
