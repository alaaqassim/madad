import http from './http';
import { createMockApi } from './mockApi';

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
| Mock switch.
|
| `VITE_MADAD_MOCK_API=true` swaps in an adapter that speaks the identical
| contract, for working on screens while the backend session is mid-flight.
| There is one contract; the mock does not get its own.
*/
const api = import.meta.env?.VITE_MADAD_MOCK_API === 'true' ? createMockApi() : realApi;

export default api;
export { realApi };
