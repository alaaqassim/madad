import http from './http';

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
| The live implementation, swappable exactly once and only in development.
|
| The default export is a stable forwarder rather than the chosen object, so a
| composable can capture it during setup while the development mock is still
| being loaded asynchronously. In a production build nothing ever calls
| setApi(), so `impl` is realApi from first byte to last — and because the mock
| now lives entirely under resources/js/dev/, none of it is in the graph at all.
*/
let impl = realApi;

/** DEV ONLY. Install an alternative implementation of the same contract. */
export function setApi(next) {
    impl = next ?? realApi;
}

const api = {
    login: (credentials) => impl.login(credentials),
    logout: () => impl.logout(),
    status: () => impl.status(),
    start: () => impl.start(),
    current: () => impl.current(),
    answer: (questionId, selectedOption) => impl.answer(questionId, selectedOption),
    result: () => impl.result(),
};

export default api;
export { realApi };
