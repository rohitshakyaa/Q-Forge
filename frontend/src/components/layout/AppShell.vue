<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useThemeStore } from '../../stores/theme';
import type { UserRole } from '../../types/auth';
import QFSidebar from './QFSidebar.vue';

defineProps<{
  role: UserRole;
}>();

const authStore = useAuthStore();
const theme = useThemeStore();
const router = useRouter();
const sidebarOpen = ref(false);

const handleLogout = async () => {
  await authStore.logout();
  await router.push('/login');
};

const closeSidebar = () => {
  sidebarOpen.value = false;
};
</script>

<template>
  <div class="qf-app">
    <div class="qf-mobile-topbar">
      <button
        type="button"
        class="qf-hamburger"
        aria-label="Toggle menu"
        @click="sidebarOpen = !sidebarOpen"
      >
        <span class="text-xl leading-none">☰</span>
      </button>
      <div class="flex items-center gap-2">
        <div
          class="w-7 h-7 rounded-lg flex items-center justify-center text-sm font-bold font-head text-bg"
          style="background: linear-gradient(135deg, var(--cyan), var(--indigo))"
        >
          Q
        </div>
        <span class="font-head font-bold text-base tracking-tight">QForge</span>
      </div>
      <button
        type="button"
        class="qf-hamburger"
        :aria-label="`Switch to ${theme.isDark ? 'light' : 'dark'} mode`"
        @click="theme.toggle()"
      >
        <span class="text-lg leading-none">{{ theme.isDark ? '☾' : '☀' }}</span>
      </button>
    </div>
    <div class="qf-layout relative">
      <div
        :class="['qf-sidebar-backdrop', sidebarOpen && 'open']"
        @click="closeSidebar"
      />
      <QFSidebar
        :role="role"
        :user-name="authStore.user?.name"
        :class="{ open: sidebarOpen }"
        @logout="handleLogout"
        @navigate="closeSidebar"
      />
      <div class="qf-main">
        <router-view />
      </div>
    </div>
  </div>
</template>
