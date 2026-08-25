import axios from 'axios';

/*
| The single HTTP client.
|
| The contestant API is routed inside routes/web.php under an `api` prefix, so
| it is session-authenticated and CSRF-protected — not a token API. That means
| two things must hold on every request: cookies are sent, and the XSRF token
| the framework set on the document response is echoed back. Axios does both by
| default once `withCredentials` is on, which is why this file is short.
*/
const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/** A stable, machine-readable failure the UI can branch on. */
export class ApiError extends Error {
    constructor(reason, { status = 0, fields = null } = {}) {
        super(reason);
        this.name = 'ApiError';
        this.reason = reason;
        this.status = status;
        this.fields = fields;
    }

    /**
     * A request that may or may not have reached the server. The exam flow
     * must never guess an outcome for one of these — it re-reads state.
     */
    get isIndeterminate() {
        return this.reason === 'network_error' || this.status >= 500;
    }
}

/**
 * Every rejection becomes an ApiError carrying a `reason` from the backend's
 * documented vocabulary. The raw Laravel message is deliberately dropped here
 * so no server string — validation text, exception wording, SQL — can reach a
 * contestant's screen; the Arabic copy is chosen from the reason instead.
 */
function normalise(error) {
    if (error.response) {
        const { status, data } = error.response;
        const reason = data?.reason ?? (status === 401 ? 'unauthenticated' : 'server_error');

        return new ApiError(reason, { status, fields: data?.errors ?? null });
    }

    // No response: offline, DNS, timeout, or a connection dropped mid-flight.
    return new ApiError('network_error', { status: 0 });
}

http.interceptors.response.use(
    (response) => response,
    (error) => Promise.reject(normalise(error)),
);

export default http;
