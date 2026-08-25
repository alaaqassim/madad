<script setup>
/*
| One answer choice.
|
| Rendered as a real <button> with role="radio" inside a radiogroup, so arrow
| keys move between options and Space/Enter chooses one, exactly as a native
| radio would — but with the tap area and the Madad styling the design needs.
|
| There is deliberately NO correct/incorrect state in this component. During a
| live exam the client is never told whether an answer was right, and a green
| tick here would be a hole in that.
*/
defineProps({
    /** 'A' | 'B' | 'C' | 'D' */
    letter: { type: String, required: true },
    text: { type: String, required: true },
    selected: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** The chosen option while its submission is in flight. */
    submitting: { type: Boolean, default: false },
    /**
     * Roving tabindex. Exactly one option in the group carries it, so Tab
     * enters and leaves the group once rather than stopping four times.
     */
    focusable: { type: Boolean, default: false },
});

defineEmits(['choose']);
</script>

<template>
    <button
        type="button"
        class="madad-option"
        :class="{
            'madad-option--selected': selected,
            'madad-option--submitting': submitting,
        }"
        role="radio"
        :aria-checked="selected ? 'true' : 'false'"
        :disabled="disabled"
        :tabindex="focusable ? 0 : -1"
        @click="$emit('choose', letter)"
    >
        <span class="madad-option__letter" aria-hidden="true">{{ letter }}</span>
        <span class="madad-option__text">{{ text }}</span>
    </button>
</template>

<style scoped>
.madad-option {
    display: flex;
    align-items: center;
    gap: var(--madad-space-4);
    inline-size: 100%;
    min-block-size: var(--madad-control-height); /* 56px — above the 44px floor */
    padding: var(--madad-space-4);
    text-align: start;
    background: var(--madad-surface);
    border: 1px solid var(--madad-border);
    border-radius: var(--madad-radius-md);
    color: var(--madad-ink);
    font-size: var(--madad-text-lg);
    line-height: 1.7;
    cursor: pointer;
    transition:
        border-color 160ms ease,
        background-color 160ms ease,
        box-shadow 160ms ease;
}

.madad-option + .madad-option {
    margin-block-start: var(--madad-space-3);
}

/* The letter badge: cream well, gold ink — the source UI's accent treatment. */
.madad-option__letter {
    flex: 0 0 auto;
    inline-size: 2.25rem;
    block-size: 2.25rem;
    display: grid;
    place-items: center;
    border-radius: var(--madad-radius-sm);
    background: var(--madad-cream-deep);
    color: var(--madad-gold-700);
    font-family: var(--madad-font-numeric);
    font-size: var(--madad-text-base);
    font-weight: 700;
    transition:
        background-color 160ms ease,
        color 160ms ease;
}

.madad-option__text {
    flex: 1 1 auto;
    min-inline-size: 0;
}

@media (hover: hover) {
    .madad-option:hover:not(:disabled) {
        border-color: var(--madad-green-300);
        background: var(--madad-green-050);
    }
}

/*
| Selected: a green surface with a doubled border and a gold letter badge. The
| state is carried by border weight and badge fill as well as hue, so it stays
| legible without colour.
*/
.madad-option--selected {
    border-color: var(--madad-green-700);
    box-shadow: inset 0 0 0 1px var(--madad-green-700);
    background: var(--madad-green-050);
}

.madad-option--selected .madad-option__letter {
    background: var(--madad-gold-500);
    color: var(--madad-on-gold);
}

.madad-option--submitting {
    opacity: 0.85;
}

.madad-option:disabled {
    cursor: not-allowed;
    background: var(--madad-disabled-surface);
    border-color: var(--madad-disabled-border);
    color: var(--madad-disabled-ink);
    box-shadow: none;
}

.madad-option:disabled .madad-option__letter {
    background: var(--madad-cream-deep);
    color: var(--madad-disabled-ink);
}

/* A disabled-but-chosen option keeps its identity while the answer is sent. */
.madad-option--selected:disabled {
    border-color: var(--madad-green-300);
    box-shadow: inset 0 0 0 1px var(--madad-green-300);
    color: var(--madad-ink);
}

.madad-option--selected:disabled .madad-option__letter {
    background: var(--madad-gold-200);
    color: var(--madad-gold-700);
}
</style>
