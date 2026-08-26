import { setApi } from '../api/competitionApi';
import { createMockJourney, readFlags, MOCK_CONTESTANT } from './mockJourney';

/*
| DEVELOPMENT ONLY — the entry point for the mock journey.
|
| Imported by a dynamic import behind `if (import.meta.env.DEV)` in
| MadadApp.vue, so a production build eliminates the branch, emits no chunk for
| this module, and ships none of the mock.
|
| There is deliberately no developer UI: opening the app on the dev server
| looks exactly like the deployed contestant application. The three flags below
| are typed into the URL when a reviewer wants them, and nothing announces them
| on screen.
*/
export function installMockJourney() {
    const flags = readFlags();

    setApi(createMockJourney({ flags }));

    // eslint-disable-next-line no-console
    console.info(
        [
            '[madad] Development mock journey active — no backend is being contacted.',
            `  Sign in as ${MOCK_CONTESTANT.email} / ${MOCK_CONTESTANT.password}`,
            `  Seconds per question: ${flags.fast ? 8 : 40}${flags.fast ? '  (?fast=1)' : ''}`,
            `  Result at the end: ${flags.showResult ? 'shown (?showResult=1)' : 'hidden'}`,
            flags.closed ? '  Portal: closed after login (?closed=1)' : null,
        ]
            .filter(Boolean)
            .join('\n'),
    );
}
