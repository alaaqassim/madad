<script setup>
import MadadMark from './MadadMark.vue';
import { copy } from '../../i18n/messages';

/*
| The header from both screenshots: pale sage bar, hairline beneath, mark and
| wordmark at the inline start (the right, in RTL), actions at the inline end
| where the source UI puts its menu button.
*/
</script>

<template>
    <header class="madad-header">
        <div class="madad-header__inner">
            <a class="madad-header__brand" href="/">
                <MadadMark :size="38" />
                <span class="madad-header__wordmark">{{ copy.brand }}</span>
            </a>

            <div class="madad-header__actions">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>

<style scoped>
.madad-header {
    background: var(--madad-green-100);
    border-bottom: 1px solid var(--madad-border-green);
}

.madad-header__inner {
    max-width: var(--madad-container-wide);
    margin-inline: auto;
    min-height: var(--madad-header-height);
    padding-inline: var(--madad-space-4);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--madad-space-4);
}

.madad-header__brand {
    display: inline-flex;
    align-items: center;
    gap: var(--madad-space-3);
    text-decoration: none;
    /* The whole brand block stays a comfortable target on a phone. */
    min-height: var(--madad-tap-min);
}

.madad-header__wordmark {
    font-family: var(--madad-font-display);
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--madad-green-900);
    letter-spacing: 0.01em;
}

.madad-header__actions {
    display: flex;
    align-items: center;
    gap: var(--madad-space-2);
}

@media (min-width: 48rem) {
    .madad-header__inner {
        padding-inline: var(--madad-space-8);
    }

    .madad-header__wordmark {
        font-size: 1.25rem;
    }
}
</style>
