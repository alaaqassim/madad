import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { effectScope } from 'vue';
import { useCountdown } from '../composables/useCountdown';

/**
 * Runs a composable inside a scope so onScopeDispose fires, the way a component
 * would. Returns the composable plus a stop() that simulates unmounting.
 */
function inScope(factory) {
    const scope = effectScope();
    const value = scope.run(factory);

    return { ...value, dispose: () => scope.stop() };
}

describe('useCountdown', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('anchors to the server-supplied seconds_remaining', () => {
        const timer = inScope(() => useCountdown());

        timer.sync(40);

        expect(timer.seconds.value).toBe(40);
    });

    it('counts down as time passes', () => {
        const timer = inScope(() => useCountdown());
        timer.sync(40);

        vi.advanceTimersByTime(5000);

        expect(timer.seconds.value).toBe(35);
    });

    it('re-anchoring to a smaller server value does not restart the clock', () => {
        // What a refresh looks like: the server reports what is genuinely left
        // on the SAME question, and the timer must adopt it, not reset to full.
        const timer = inScope(() => useCountdown());

        timer.sync(40);
        vi.advanceTimersByTime(25000);
        expect(timer.seconds.value).toBe(15);

        timer.sync(15); // remount / refetch of the same live question
        expect(timer.seconds.value).toBe(15);

        vi.advanceTimersByTime(5000);
        expect(timer.seconds.value).toBe(10);
    });

    it('a remount inherits the remaining time rather than the full duration', () => {
        const first = inScope(() => useCountdown());
        first.sync(40);
        vi.advanceTimersByTime(30000);
        const carried = first.seconds.value;
        first.dispose();

        const second = inScope(() => useCountdown());
        second.sync(carried);

        expect(second.seconds.value).toBe(10);
        expect(second.seconds.value).not.toBe(40);
    });

    it('fires onExpire exactly once, and only at zero', () => {
        const onExpire = vi.fn();
        const timer = inScope(() => useCountdown({ onExpire }));

        timer.sync(3);
        vi.advanceTimersByTime(2000);
        expect(onExpire).not.toHaveBeenCalled();

        vi.advanceTimersByTime(2000);
        expect(onExpire).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(5000);
        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(timer.isExpired.value).toBe(true);
    });

    it('treats a zero from the server as already expired', () => {
        const onExpire = vi.fn();
        const timer = inScope(() => useCountdown({ onExpire }));

        timer.sync(0);

        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(timer.isExpired.value).toBe(true);
    });

    it('enters the warning band near the deadline', () => {
        const timer = inScope(() => useCountdown({ warningAt: 10 }));
        timer.sync(40);

        vi.advanceTimersByTime(29000);
        expect(timer.isWarning.value).toBe(false);

        vi.advanceTimersByTime(2000);
        expect(timer.isWarning.value).toBe(true);
    });

    it('stops ticking once the scope is disposed', () => {
        const onExpire = vi.fn();
        const timer = inScope(() => useCountdown({ onExpire }));

        timer.sync(5);
        timer.dispose();
        vi.advanceTimersByTime(10000);

        expect(onExpire).not.toHaveBeenCalled();
    });

    it('reports a fraction of the question duration for the dial', () => {
        const timer = inScope(() => useCountdown());
        const total = { value: 40 };
        const fraction = timer.fractionFor(total);

        timer.sync(40);
        expect(fraction.value).toBeCloseTo(1, 5);

        vi.advanceTimersByTime(20000);
        expect(fraction.value).toBeCloseTo(0.5, 2);
    });
});
