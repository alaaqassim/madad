<script setup>
import { computed } from 'vue';
import MadadCard from '../components/madad/MadadCard.vue';
import MadadButton from '../components/madad/MadadButton.vue';
import MadadStatusMessage from '../components/madad/MadadStatusMessage.vue';
import { copy, messageForReason } from '../i18n/messages';

/*
| The ready state — the hero of the second screenshot, carried into the exam.
|
| One button serves both a fresh start and a resume, because the backend
| deliberately cannot tell them apart. The wording changes; nothing else does,
| and nothing here suggests that resuming buys back any time.
*/
const props = defineProps({
    competitionName: { type: String, default: null },
    totalQuestions: { type: Number, default: null },
    secondsPerQuestion: { type: Number, default: null },
    /** 'not_started' | 'in_progress' */
    examStatus: { type: String, default: 'not_started' },
    busy: { type: Boolean, default: false },
    errorReason: { type: String, default: null },
});

defineEmits(['start', 'logout', 'retry']);

const resuming = computed(() => props.examStatus === 'in_progress');
const banner = computed(() => (props.errorReason ? messageForReason(props.errorReason) : null));
const retryable = computed(() => ['network_error', 'server_error', 'unknown'].includes(props.errorReason));
</script>

<template>
    <div>
        <MadadCard class="intro" tone="green">
            <h1 class="intro__title">{{ competitionName ?? copy.competitionFallback }}</h1>
            <p class="intro__lede">
                {{ resuming ? copy.status.readyBodyResume : copy.status.readyBodyFresh }}
            </p>

            <MadadButton
                class="intro__cta"
                variant="gold"
                arrow
                :loading="busy"
                @click="$emit('start')"
            >
                {{ busy ? copy.status.starting : resuming ? copy.status.resume : copy.status.start }}
            </MadadButton>
        </MadadCard>

        <MadadStatusMessage
            v-if="banner"
            class="intro__banner"
            tone="error"
            :message="banner"
            :retryable="retryable"
            :busy="busy"
            @retry="$emit('retry')"
        />

        <MadadCard class="intro__rules" :heading="copy.status.rules">
            <ul class="rules">
                <li v-if="totalQuestions">{{ copy.status.ruleQuestions(totalQuestions) }}</li>
                <li v-if="secondsPerQuestion">{{ copy.status.ruleSeconds(secondsPerQuestion) }}</li>
                <li>{{ copy.status.ruleNoBack }}</li>
            </ul>
        </MadadCard>

        <div class="intro__footer">
            <MadadButton variant="link" @click="$emit('logout')">{{ copy.status.signOut }}</MadadButton>
        </div>
    </div>
</template>

<style scoped>
/* The green hero band from the source UI, at reading width. */
.intro {
    text-align: start;
}

.intro__title {
    font-size: var(--madad-text-h1);
}

.intro__lede {
    margin-block-start: var(--madad-space-4);
    color: var(--madad-on-green-muted);
    font-size: var(--madad-text-lg);
}

.intro__cta {
    margin-block-start: var(--madad-space-8);
}

.intro__banner {
    margin-block-start: var(--madad-space-5);
}

.intro__rules {
    margin-block-start: var(--madad-space-5);
}

.rules {
    margin: 0;
    padding: 0;
    list-style: none;
    color: var(--madad-ink);
    font-size: var(--madad-text-base);
}

.rules li {
    position: relative;
    padding-inline-start: var(--madad-space-6);
    line-height: var(--madad-leading-body);
}

.rules li + li {
    margin-block-start: var(--madad-space-2);
}

/* A gold marker on the inline start — the right, in RTL. */
.rules li::before {
    content: '';
    position: absolute;
    inset-inline-start: 0;
    inset-block-start: 0.85em;
    inline-size: 6px;
    block-size: 6px;
    border-radius: var(--madad-radius-pill);
    background: var(--madad-gold-500);
}

.intro__footer {
    margin-block-start: var(--madad-space-6);
    text-align: center;
}

@media (min-width: 30rem) {
    .intro__cta {
        min-inline-size: 16rem;
    }
}
</style>
