<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import type { UserRole } from '../../types/auth';
import QFSidebar from './QFSidebar.vue';

defineProps<{
  role: UserRole;
}>();

const authStore = useAuthStore();
const router = useRouter();

const handleLogout = async () => {
  await authStore.logout();
  await router.push('/login');
};
</script>

<template>
  <div class="qf-app">
    <div class="qf-layout">
      <QFSidebar :role="role" :user-name="authStore.user?.name" @logout="handleLogout" />
      <div class="qf-main">
        <router-view />
      </div>
    </div>
  </div>
</template>
