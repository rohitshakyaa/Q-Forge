import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { useStorage } from '@vueuse/core';
import { authApi } from '../api/auth/auth.api';
import type { AuthUser, LoginPayload } from '../types/auth';

interface StoredAuth {
  token: string | null;
  user: AuthUser | null;
}

const DEFAULT_AUTH: StoredAuth = {
  token: null,
  user: null,
};

export const useAuthStore = defineStore('auth', () => {
  const persisted = useStorage<StoredAuth>('qforge-auth', DEFAULT_AUTH);
  const loading = ref(false);

  const token = computed(() => persisted.value.token);
  const user = computed(() => persisted.value.user);
  const isAuthenticated = computed(() => Boolean(persisted.value.token && persisted.value.user));

  const setAuth = (next: StoredAuth) => {
    persisted.value = next;
  };

  const clearAuth = () => {
    persisted.value = { ...DEFAULT_AUTH };
  };

  const login = async (payload: LoginPayload) => {
    loading.value = true;

    try {
      const response = await authApi.login(payload);
      setAuth({
        token: response.token,
        user: response.user,
      });

      return response.user;
    } finally {
      loading.value = false;
    }
  };

  const hydrateFromToken = async () => {
    if (!persisted.value.token) {
      return;
    }

    try {
      const response = await authApi.me();
      setAuth({
        token: persisted.value.token,
        user: response.user,
      });
    } catch {
      clearAuth();
    }
  };

  const logout = async () => {
    try {
      if (persisted.value.token) {
        await authApi.logout();
      }
    } finally {
      clearAuth();
    }
  };

  return {
    token,
    user,
    isAuthenticated,
    loading,
    login,
    logout,
    clearAuth,
    hydrateFromToken,
  };
});
