import { SCENARIOS } from '../api/mockApi';
import { activeScenario } from '../api/competitionApi';

/*
| Development-only preview harness.
|
| Loaded by a dynamic import that sits behind `if (import.meta.env.DEV)`, so
| this module — and the switcher chrome it injects — is never fetched by a
| production build. There is no debug surface in the shipped app.
|
| It does NOT force components into a state. It configures the mock adapter and
| then drives the real state machine (login / start / select / submit), so what
| you are reviewing is the actual flow, not a posed screenshot.
|
| Usage:  npm run dev  +  ?preview=<scenario>
| Add     &nochrome=1  to hide the switcher for clean screenshots.
*/

const CONTESTANT = { email: 'contestant@madad.test', password: 'secret-password' };

/** Land the app on the scenario's screen by exercising the real transitions. */
async function drive(exam, step) {
    switch (step) {
        case 'login':
            // Fails by design in the login-error scenarios; the banner is the point.
            await exam.login(CONTESTANT);

            return;

        case 'start':
            await exam.start();

            return;

        case 'select':
            await exam.start();
            exam.select('B');

            return;

        case 'submit':
            await exam.start();
            exam.select('C');
            await exam.submitAnswer();

            return;

        default:
    }
}

/** A compact scenario switcher, pinned out of the way of the layout. */
function renderSwitcher(current) {
    const host = document.createElement('details');

    host.setAttribute('dir', 'rtl');
    host.style.cssText = [
        'position:fixed',
        'inset-block-end:12px',
        'inset-inline-start:12px',
        'z-index:9999',
        'max-inline-size:min(22rem, calc(100vw - 24px))',
        'font-family:var(--madad-font-ui)',
        'font-size:12px',
        'background:var(--madad-surface)',
        'border:1px solid var(--madad-border)',
        'border-radius:12px',
        'box-shadow:var(--madad-shadow-card)',
        'overflow:hidden',
    ].join(';');

    const summary = document.createElement('summary');

    summary.textContent = `معاينة · ${SCENARIOS[current]?.label ?? current}`;
    summary.style.cssText =
        'cursor:pointer;padding:8px 12px;font-weight:700;color:var(--madad-green-800);list-style:none';
    host.appendChild(summary);

    const list = document.createElement('div');

    list.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;padding:0 12px 12px';

    for (const [name, scenario] of Object.entries(SCENARIOS)) {
        const link = document.createElement('a');

        link.href = `?preview=${name}`;
        link.textContent = scenario.label;
        link.title = scenario.note;
        link.style.cssText = [
            'padding:5px 10px',
            'border-radius:999px',
            'text-decoration:none',
            'border:1px solid var(--madad-border)',
            name === current ? 'background:var(--madad-gold-500)' : 'background:var(--madad-cream)',
            name === current ? 'color:var(--madad-on-gold)' : 'color:var(--madad-green-800)',
            name === current ? 'font-weight:700' : '',
        ].join(';');
        list.appendChild(link);
    }

    host.appendChild(list);
    document.body.appendChild(host);
}

/** No-op unless ?preview= names a scenario. Safe to call unconditionally. */
export async function runPreview(exam) {
    if (!activeScenario || !SCENARIOS[activeScenario]) {
        return;
    }

    const scenario = SCENARIOS[activeScenario];

    if (scenario.drive) {
        await drive(exam, scenario.drive);
    }

    if (!new URLSearchParams(window.location.search).has('nochrome')) {
        renderSwitcher(activeScenario);
    }

    // eslint-disable-next-line no-console
    console.info(`[madad preview] ${activeScenario} — ${scenario.note}`);
}
