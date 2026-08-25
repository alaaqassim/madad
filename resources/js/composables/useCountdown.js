import { computed, onScopeDispose, ref } from 'vue';

/*
| The visible countdown.
|
| This clock is DECORATION. The server owns the deadline: it wrote expires_at
| once when it first served the question and never moves it, and it refuses a
| late answer regardless of what any browser is showing.
|
| Two rules follow, and both are load-bearing:
|
|  1. The anchor is the server's `seconds_remaining`, taken fresh from every
|     payload. Nothing is carried across questions, and a refresh, a remount or
|     a navigation simply re-anchors to whatever the server says is left — so
|     the timer cannot be reset by reloading, and cannot drift by staying open.
|
|  2. Elapsed time is measured with performance.now(), a monotonic counter, not
|     with Date.now(). A contestant who moves the device clock, or a machine
|     that syncs NTP mid-question, changes nothing here.
|
| Reaching zero is not a decision. It only disables the UI and asks the server
| what is true now; the server decides whether the question timed out.
*/

const monotonicNow = () =>
    typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now();

export function useCountdown({ warningAt = 10, onExpire = null } = {}) {
    const remaining = ref(null);

    let anchorSeconds = null;
    let anchorMonotonic = null;
    let frame = null;
    let expiredFired = false;

    const stop = () => {
        if (frame !== null) {
            clearInterval(frame);
            frame = null;
        }
    };

    const tick = () => {
        if (anchorSeconds === null) {
            return;
        }

        const elapsed = (monotonicNow() - anchorMonotonic) / 1000;
        const left = Math.max(0, anchorSeconds - elapsed);

        remaining.value = left;

        if (left > 0 || expiredFired) {
            return;
        }

        // Fire once. The handler's job is to ask the server, never to conclude.
        expiredFired = true;
        stop();
        onExpire?.();
    };

    /** Re-anchor to a server-supplied `seconds_remaining`. */
    const sync = (secondsRemaining) => {
        stop();

        if (secondsRemaining === null || secondsRemaining === undefined) {
            anchorSeconds = null;
            remaining.value = null;
            expiredFired = false;

            return;
        }

        anchorSeconds = Math.max(0, Number(secondsRemaining));
        anchorMonotonic = monotonicNow();
        expiredFired = false;
        remaining.value = anchorSeconds;

        if (anchorSeconds === 0) {
            expiredFired = true;
            onExpire?.();

            return;
        }

        // 250ms keeps the whole-second display honest without a render storm.
        frame = setInterval(tick, 250);
    };

    /*
    | A hidden tab has its intervals throttled — a locked phone, an app switch.
    | The displayed value self-corrects on the next tick, because it is computed
    | from the monotonic clock rather than accumulated, but the contestant would
    | briefly see a stale number on returning. Recomputing on the way back makes
    | the freeze immediate. No time is invented here: it is the same arithmetic
    | a tick would have done, run sooner.
    */
    const onVisibility = () => {
        if (typeof document !== 'undefined' && document.visibilityState === 'visible') {
            tick();
        }
    };

    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', onVisibility);
    }

    onScopeDispose(() => {
        stop();

        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', onVisibility);
        }
    });

    const seconds = computed(() => (remaining.value === null ? null : Math.ceil(remaining.value)));
    const isExpired = computed(() => remaining.value !== null && remaining.value <= 0);
    const isWarning = computed(() => seconds.value !== null && seconds.value > 0 && seconds.value <= warningAt);

    /** 1 → full, 0 → empty. Needs the question's total to be meaningful. */
    const fractionFor = (total) =>
        computed(() => {
            if (remaining.value === null || !total.value) {
                return 0;
            }

            return Math.min(1, Math.max(0, remaining.value / total.value));
        });

    return { remaining, seconds, isExpired, isWarning, sync, stop, fractionFor };
}
