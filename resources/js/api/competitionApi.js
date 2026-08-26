import http from './http';
import { createMockApi, SCENARIOS } from './mockApi';

/*
| The contestant API surface, one function per backend route.
|
| Route names and payload keys mirror routes/web.php exactly. Nothing is
| renamed, reshaped or invented here: if a screen wants a different shape it
| derives it, it does not rewrite the contract.
*/
const realApi = {
    /** POST /api/login — {email, password} */
    login: (credentials) => http.post('/login', credentials).then((r) => r.data),

    /** POST /api/logout */
    logout: () => http.post('/logout').then((r) => r.data),

    /**
     * GET /api/competition/status — public, so a closed portal can say so
     * before anyone logs in.
     */
    status: () => http.get('/competition/status').then((r) => r.data),

    /** POST /api/exam/start — start or resume; the backend cannot tell them apart. */
    start: () => http.post('/exam/start').then((r) => r.data),

    /** GET /api/exam/current — {exam_status, started_at, question|null} */
    current: () => http.get('/exam/current').then((r) => r.data),

    /** POST /api/exam/answer — {question_id, selected_option} */
    answer: (questionId, selectedOption) =>
        http
            .post('/exam/answer', { question_id: questionId, selected_option: selectedOption })
            .then((r) => r.data),

    /** GET /api/exam/result */
    result: () => http.get('/exam/result').then((r) => r.data),
};

/*
| Mock switch. Two opt-in routes to the mock, and only these two:
|
|   * VITE_MADAD_MOCK_API=true — build or dev-serve the whole app on the mock.
|   * ?preview=<scenario>      — DEV BUILDS ONLY. `import.meta.env.DEV` is a
|                                literal false in a production build, so the
|                                query string is not even read there and the
|                                branch is eliminated. A deployed Madad cannot
|                                be talked into the mock by a URL.
|
| Either way it is the same contract; the mock does not get one of its own.
*/
export const activeScenario = import.meta.env?.DEV
    ? new URLSearchParams(window.location.search).get('preview')
    : null;

const api =
    activeScenario !== null || import.meta.env?.VITE_MADAD_MOCK_API === 'true'
        ? createMockApi(SCENARIOS[activeScenario]?.mock)
        : realApi;

export default api;
export { realApi };
