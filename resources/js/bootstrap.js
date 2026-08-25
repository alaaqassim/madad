/*
| The contestant API is session-authenticated (routes/web.php, `api` prefix),
| so requests must carry cookies and echo the XSRF token. That is configured on
| the shared client in api/http.js; this file only exposes axios for anything
| outside the Vue app that still expects window.axios.
*/
import axios from 'axios';

window.axios = axios;

window.axios.defaults.withCredentials = true;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
