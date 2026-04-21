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

export default api;
