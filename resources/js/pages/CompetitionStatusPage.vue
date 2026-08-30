<script setup>
import { computed } from 'vue';
import MadadCard from '../components/madad/MadadCard.vue';
import MadadButton from '../components/madad/MadadButton.vue';
import { copy, messageForReason } from '../i18n/messages';

/*
| The gate screen: every state in which the contestant may not sit the exam.
|
| The distinction that matters is terminal vs. not. `competition_closed` means
| the competition has ENDED, so no refresh is offered — inviting a retry there
| would promise something that will never happen. Everything else may still
| change, so it gets a refresh.
|
| Internal enum vocabulary (draft / ready / not_started) is never printed.
*/
const props = defineProps({
    reason: { type: String, default: null },
    competitionName: { type: String, default: null },
    busy: { type: Boolean, default: false },
});

// Signing out lives in the shell header, once, for every screen that offers it.
defineEmits(['refresh']);

const TERMINAL = ['competition_closed'];

const isTerminal = computed(() => TERMINAL.includes(props.reason));

/** Transport failures get a plain retry; the rest get a shaped Madad state. */
const isTransport = computed(() => ['network_error', 'server_error', 'unknown', 'not_found'].includes(props.reason));

const content = computed(() => {
    switch (props.reason) {
        case 'competition_closed':
            return { title: copy.status.closedTitle, body: copy.status.closedBody, tone: 'closed' };
        case 'no_competition':
            return { title: copy.status.noneTitle, body: copy.status.noneBody, tone: 'waiting' };
        case 'competition_not_open':
            return { title: copy.status.notOpenTitle, body: copy.status.notOpenBody, tone: 'waiting' };
        default:
            // not_a_contestant, account_not_provisioned, paper_not_ready and
            // every transport failure share this shape: one Arabic sentence.
            return { title: messageForReason(props.reason), body: null, tone: 'blocked' };
    }
});
</script>

<template>
    <div>
        <div class="madad-intro">
            <p class="madad-eyebrow"><span>{{ copy.status.eyebrow }}</span></p>
            <h1 class="madad-intro__title">{{ competitionName ?? copy.competitionFallback }}</h1>
        </div>

        <MadadCard class="gate" :tone="isTerminal ? 'green' : 'surface'">
            <div class="gate__glyph" :class="`gate__glyph--${content.tone}`" aria-hidden="true">
                <svg viewBox="0 0 32 32" width="32" height="32" focusable="false">
                    <template v-if="content.tone === 'closed'">
                        <circle cx="16" cy="16" r="13" fill="none" stroke="currentColor" stroke-width="2" />
                        <path
                            d="M16 8v8l5 3"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </template>
                    <template v-else-if="content.tone === 'waiting'">
                        <path
                            d="M10 4h12M10 28h12M11 4c0 6 10 8 10 12S11 22 11 28M21 4c0 6-10 8-10 12s10 6 10 12"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </template>
                    <template v-else>
                        <path
                            d="M16 4.5 29 27H3zM16 13v7M16 23.5v.2"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </template>
                </svg>
            </div>

            <h2 class="gate__title">{{ content.title }}</h2>
            <p v-if="content.body" class="gate__body">{{ content.body }}</p>

            <!-- Announced for a contestant who reaches this state mid-session. -->
            <p class="madad-visually-hidden" role="status" aria-live="polite">
                {{ content.title }} {{ content.body }}
            </p>

            <div class="gate__actions">
                <MadadButton
                    v-if="!isTerminal"
                    variant="gold"
                    :loading="busy"
                    @click="$emit('refresh')"
                >
                    {{ isTransport ? copy.actions.retry : copy.status.refresh }}
                </MadadButton>
            </div>
        </MadadCard>
    </div>
</template>

<style scoped>
.gate {
    text-align: center;
}

.gate__glyph {
    display: grid;
    place-items: center;
    inline-size: 4.5rem;
    block-size: 4.5rem;
    margin-inline: auto;
    margin-block-end: var(--madad-space-5);
    border-radius: var(--madad-radius-pill);
    background: var(--madad-cream-deep);
    color: var(--madad-gold-600);
}

.gate__glyph--closed {
    background: rgba(255, 255, 255, 0.12);
    color: var(--madad-gold-400);
}

.gate__title {
    font-size: var(--madad-text-h2);
}

.gate__body {
    margin-block-start: var(--madad-space-4);
    color: var(--madad-ink-muted);
    font-size: var(--madad-text-lg);
}

/* On the terminal green card the muted ink would vanish; lift it. */
:global(.madad-card--green) .gate__body {
    color: var(--madad-on-green-muted);
}

.gate__actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--madad-space-3);
    margin-block-start: var(--madad-space-8);
}

@media (min-width: 30rem) {
    .gate__actions {
        flex-direction: row;
        justify-content: center;
    }
}
</style>
