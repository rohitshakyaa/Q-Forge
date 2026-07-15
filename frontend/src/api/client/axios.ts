import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  headers: {
    Accept: 'application/json',
  },
});

api.interceptors.request.use((config) => {
  const raw = localStorage.getItem('qforge-auth');

  if (raw) {
    try {
      const parsed = JSON.parse(raw) as { token?: string | null };
      if (parsed.token) {
        config.headers.Authorization = `Bearer ${parsed.token}`;
      }
    } catch {
      // Ignore malformed local storage payload.
    }
  }

  return config;
});

// The auth endpoints handle their own 401s: `hydrateFromToken` clears the session
// and the route guard then redirects. Redirecting from here as well would push a
// route from inside `beforeEach`, aborting the navigation already in flight.
const SELF_HANDLED = ['/auth/me', '/auth/logout'];

/**
 * A 401 means the token is gone or was revoked server-side — logging in anywhere
 * calls `$user->tokens()->delete()`, and the app is reachable on two origins
 * (:5173 and :8040) whose localStorage do not share. Without this, a dead token
 * leaves the page mounted and every request rejects unhandled.
 *
 * Bad credentials return 422 and a wrong role returns 403, so a 401 never
 * originates from the login form and this cannot loop.
 */
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const url = error.config?.url ?? '';

    if (error.response?.status === 401 && !SELF_HANDLED.includes(url)) {
      // Imported lazily: this module is a dependency of both.
      const [{ useAuthStore }, { default: router }] = await Promise.all([
        import('../../stores/auth'),
        import('../../routes/index'),
      ]);

      useAuthStore().clearAuth();

      if (router.currentRoute.value.path !== '/login') {
        await router.push('/login');
      }
    }

    return Promise.reject(error);
  },
);

export default api;
