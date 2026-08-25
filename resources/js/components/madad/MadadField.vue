<script setup>
import { computed, useId } from 'vue';

/*
| A labelled form control, matching the registration screenshot: green bold
| label above, white 56px input with a warm hairline border, muted placeholder.
|
| The error is announced as well as coloured — an `aria-invalid` input, an
| `aria-describedby` message, and a warning glyph — so the failed state is
| never communicated by colour alone.
*/
const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    autocomplete: { type: String, default: null },
    inputmode: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    /** A translated sentence, never a raw server message. */
    error: { type: String, default: null },
    /**
     * Mark the control invalid without repeating a message under it — for the
     * case where one banner already explains the failure for the whole form.
     */
    invalid: { type: Boolean, default: false },
    /** id of the element that explains the failure, e.g. that banner. */
    describedBy: { type: String, default: null },
    /** Rendered left-to-right (emails, numbers) while the page stays RTL. */
    ltr: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const uid = useId();
const inputId = computed(() => `madad-field-${uid}`);
const errorId = computed(() => `${inputId.value}-error`);

const isInvalid = computed(() => Boolean(props.error) || props.invalid);
const describedByIds = computed(() => {
    const ids = [props.error ? errorId.value : null, props.describedBy].filter(Boolean);

    return ids.length > 0 ? ids.join(' ') : undefined;
});
</script>

<template>
    <div class="madad-field">
        <label class="madad-field__label" :for="inputId">
            {{ label }}
            <span v-if="required" class="madad-field__required" aria-hidden="true">*</span>
        </label>

        <input
            :id="inputId"
            class="madad-field__input"
            :class="{ 'madad-field__input--invalid': isInvalid, 'madad-field__input--ltr': ltr }"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            :inputmode="inputmode"
            :disabled="disabled"
            :required="required"
            :aria-invalid="isInvalid ? 'true' : undefined"
            :aria-describedby="describedByIds"
            :dir="ltr ? 'ltr' : undefined"
            @input="$emit('update:modelValue', $event.target.value)"
        />

        <p v-if="error" :id="errorId" class="madad-field__error">
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false">
                <path
                    d="M10 2.8 18.4 17H1.6zM10 8v4M10 14.4v.2"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            <span>{{ error }}</span>
        </p>

        <slot name="hint" />
    </div>
</template>

<style scoped>
.madad-field + .madad-field {
    margin-top: var(--madad-space-5);
}

.madad-field__label {
    display: block;
    margin-bottom: var(--madad-space-2);
    color: var(--madad-green-800);
    font-size: var(--madad-text-sm);
    font-weight: 700;
}

.madad-field__required {
    color: var(--madad-gold-600);
}

.madad-field__input {
    display: block;
    width: 100%;
    height: var(--madad-control-height);
    padding-inline: var(--madad-space-4);
    background: var(--madad-surface);
    border: 1px solid var(--madad-border);
    border-radius: var(--madad-radius-md);
    color: var(--madad-ink);
    font-size: var(--madad-text-base);
    transition:
        border-color 160ms ease,
        box-shadow 160ms ease;
}

.madad-field__input::placeholder {
    color: var(--madad-ink-faint);
}

.madad-field__input:hover:not(:disabled) {
    border-color: var(--madad-border-strong);
}

.madad-field__input:focus {
    border-color: var(--madad-green-600);
    box-shadow: 0 0 0 3px var(--madad-green-050);
    outline-offset: 1px;
}

.madad-field__input--ltr {
    text-align: start;
}

.madad-field__input--invalid {
    border-color: var(--madad-danger-border);
    background: var(--madad-danger-surface);
}

.madad-field__input:disabled {
    background: var(--madad-disabled-surface);
    border-color: var(--madad-disabled-border);
    color: var(--madad-disabled-ink);
}

.madad-field__error {
    display: flex;
    align-items: center;
    gap: var(--madad-space-2);
    margin-top: var(--madad-space-2);
    color: var(--madad-danger-ink);
    font-size: var(--madad-text-sm);
    line-height: 1.6;
}
</style>
