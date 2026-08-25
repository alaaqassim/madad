<script setup>
import { computed, ref, watch } from 'vue';
import { copy } from '../../i18n/messages';

/*
| The question timer.
|
| A gold dial on a cream track in the Madad palette — noticeable, but not a
| game-show clock. The late window shifts to the warm --madad-warning amber and
| adds a written note, so "running out" is never signalled by colour alone.
|
| It renders whatever it is given and decides nothing. The value comes from the
| server-anchored countdown; expiry is the server's ruling, not this component's.
*/
const props = defineProps({
    /** Whole seconds left, or null when no question is live. */
    seconds: { type: Number, default: null },
    /** 1 → full, 0 → empty. */
    fraction: { type: Number, default: 0 },
    warning: { type: Boolean, default: false },
    expired: { type: Boolean, default: false },
});

const RADIUS = 30;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const display = computed(() => (props.seconds === null ? '—' : String(Math.max(0, props.seconds))));
const dashOffset = computed(() => CIRCUMFERENCE * (1 - Math.min(1, Math.max(0, props.fraction))));

/*
| Announcing every tick would make a screen reader unusable. Instead the live
| region speaks only when a milestone is crossed.
*/
const MILESTONES = [30, 20, 10, 5, 0];
const announcement = ref('');
let lastAnnounced = null;

watch(
    () => props.seconds,
    (value) => {
        if (value === null) {
            announcement.value = '';
            lastAnnounced = null;

            return;
        }

        const milestone = MILESTONES.find((mark) => value === mark);

        if (milestone === undefined || milestone === lastAnnounced) {
            return;
        }

        lastAnnounced = milestone;
        announcement.value = milestone === 0 ? copy.exam.timeoutTitle : copy.exam.timerRemaining(milestone);
    },
);
</script>

<template>
    <div
        class="madad-timer"
        :class="{ 'madad-timer--warning': warning && !expired, 'madad-timer--expired': expired }"
    >
        <svg class="madad-timer__dial" viewBox="0 0 68 68" width="68" height="68" aria-hidden="true" focusable="false">
            <circle class="madad-timer__track" cx="34" cy="34" :r="RADIUS" fill="none" stroke-width="5" />
            <circle
                class="madad-timer__arc"
                cx="34"
                cy="34"
                :r="RADIUS"
                fill="none"
                stroke-width="5"
                stroke-linecap="round"
                :stroke-dasharray="CIRCUMFERENCE"
                :stroke-dashoffset="dashOffset"
            />
        </svg>

        <span class="madad-timer__value" aria-hidden="true">{{ display }}</span>

        <div class="madad-timer__meta">
            <span class="madad-timer__label">{{ copy.exam.timerLabel }}</span>
            <span v-if="warning && !expired" class="madad-timer__warning">{{ copy.exam.timerWarning }}</span>
        </div>

        <!-- Milestone-only announcements; the ticking number itself is silent. -->
        <span class="madad-visually-hidden" role="status" aria-live="polite">{{ announcement }}</span>
    </div>
</template>

<style scoped>
.madad-timer {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--madad-space-3);
}

.madad-timer__dial {
    flex: 0 0 auto;
    /* Depletes anticlockwise from the top, following the reading direction. */
    transform: rotate(-90deg) scaleX(-1);
}

[dir='ltr'] .madad-timer__dial {
    transform: rotate(-90deg);
}

.madad-timer__track {
    stroke: var(--madad-cream-deep);
}

.madad-timer__arc {
    stroke: var(--madad-gold-500);
    transition: stroke-dashoffset 250ms linear, stroke 200ms ease;
}

.madad-timer__value {
    position: absolute;
    inset-inline-start: 0;
    width: 68px;
    text-align: center;
    font-family: var(--madad-font-numeric);
    font-size: var(--madad-text-timer);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--madad-green-900);
    line-height: 68px;
    pointer-events: none;
}

.madad-timer__meta {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.madad-timer__label {
    color: var(--madad-ink-muted);
    font-size: var(--madad-text-sm);
    line-height: 1.5;
}

.madad-timer__warning {
    color: var(--madad-warning);
    font-size: var(--madad-text-sm);
    font-weight: 700;
    line-height: 1.5;
}

.madad-timer--warning .madad-timer__arc {
    stroke: var(--madad-warning);
}

.madad-timer--warning .madad-timer__value {
    color: var(--madad-warning);
}

.madad-timer--expired .madad-timer__arc {
    stroke: var(--madad-border-strong);
}

.madad-timer--expired .madad-timer__value {
    color: var(--madad-ink-muted);
}
</style>
