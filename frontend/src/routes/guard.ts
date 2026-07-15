import type { Router } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import type { UserRole } from '../types/auth';

const dashboardByRole: Record<UserRole, string> = {
  admin: '/admin',
  teacher: '/teacher',
};

export const applyRouteGuards = (router: Router) => {
  router.beforeEach(async (to) => {
    const authStore = useAuthStore();

    // Validate on every navigation, not only when the user is missing. The user is
    // persisted alongside the token, so a token revoked server-side — by a logout
    // in another tab, or by a login elsewhere, which deletes the user's tokens —
    // would otherwise satisfy the checks below and 401 on the first API call.
    // hydrateFromToken() clears the session on failure, so we fall through to /login.
    if (authStore.token) {
      await authStore.hydrateFromToken();
    }

    const requiresAuth = Boolean(to.meta.requiresAuth);
    const guestOnly = Boolean(to.meta.guestOnly);
    const roles = (to.meta.roles as UserRole[] | undefined) ?? [];

    if (requiresAuth && !authStore.isAuthenticated) {
      return { path: '/login' };
    }

    if (guestOnly && authStore.isAuthenticated && authStore.user) {
      return { path: dashboardByRole[authStore.user.role] };
    }

    if (requiresAuth && roles.length > 0 && authStore.user && !roles.includes(authStore.user.role)) {
      return { path: dashboardByRole[authStore.user.role] };
    }

    return true;
  });
};
