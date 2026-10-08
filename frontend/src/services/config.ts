// API base. Relative on purpose: the frontend always calls the SAME origin that serves it,
// so one build works on any domain. The web server must route /api/handler.php to the PHP backend.
// In `npm run dev` the umi proxy forwards /api -> localhost:8001 (see .umirc.ts).
export const API_BASE_URL = '/api/handler.php';
