<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import type { UserRole } from '../../types/auth';
import { navigationByRole } from '../../config/navigation';
import QFBadge from '../qf/QFBadge.vue';
import QFAvatar from '../qf/QFAvatar.vue';

const props = defineProps<{
  role: UserRole;
  userName?: string;
}>();

const emit = defineEmits<{
  logout: [];
  navigate: [];
}>();

const route = useRoute();
const sections = navigationByRole[props.role];

const isActive = (path: string) => route.path === path || route.path.startsWith(`${path}/`);

const menuOpen = ref(false);
const menuRef = ref<HTMLElement | null>(null);

const toggleMenu = () => {
  menuOpen.value = !menuOpen.value;
};

const closeMenu = () => {
  menuOpen.value = false;
};

const handleSignOut = () => {
  closeMenu();
  emit('logout');
};

const handleNavigate = () => {
  emit('navigate');
};

const onDocumentClick = (event: MouseEvent) => {
  if (!menuRef.value) return;
  if (!menuRef.value.contains(event.target as Node)) {
    closeMenu();
  }
};

document.addEventListener('click', onDocumentClick);

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
  <aside class="qf-sidebar">
    <div class="px-5 pt-2 pb-4 border-b border-border mb-2">
      <div class="flex items-center gap-2">
        <div
          class="w-7 h-7 rounded-lg flex items-center justify-center text-sm font-bold font-head text-bg"
          style="background: linear-gradient(135deg, var(--cyan), var(--indigo))"
        >
          Q
        </div>
        <span class="font-head text-base font-bold tracking-tight">QForge</span>
      </div>
      <div class="mt-2">
        <QFBadge :variant="role === 'admin' ? 'cyan' : 'indigo'">
          {{ role === 'admin' ? 'Administrator' : 'Teacher' }}
        </QFBadge>
      </div>
    </div>

    <div v-for="section in sections" :key="section.label ?? 'root'">
      <div v-if="section.label" class="qf-nav-section">{{ section.label }}</div>
      <RouterLink
        v-for="item in section.items"
        :key="item.path"
        :to="item.path"
        :class="['qf-nav-item', isActive(item.path) && 'active']"
        @click="handleNavigate"
      >
        <span class="text-base">{{ item.icon }}</span>
        <span>{{ item.title }}</span>
        <span v-if="item.badge" class="qf-nav-badge">{{ item.badge }}</span>
      </RouterLink>
    </div>

    <div class="grow" />
    <div
      ref="menuRef"
      class="relative px-2 py-3 border-t border-border mt-2"
    >
      <button
        type="button"
        class="qf-nav-item w-full bg-transparent border-none text-left cursor-pointer"
        style="font: inherit; color: inherit;"
        @click="toggleMenu"
      >
        <QFAvatar :name="userName ?? 'User'" :size="24" />
        <div class="min-w-0 flex-1">
          <div class="text-[13px] font-medium text-text overflow-hidden text-ellipsis whitespace-nowrap">
            {{ userName ?? 'Profile & Settings' }}
          </div>
        </div>
        <span class="text-text3 text-xs">{{ menuOpen ? '▾' : '▴' }}</span>
      </button>

      <div
        v-if="menuOpen"
        class="absolute left-2 right-2 bg-bg2 border border-border rounded-lg p-1 z-20"
        style="bottom: calc(100% - 4px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.24);"
      >
        <button
          type="button"
          class="flex items-center gap-2 w-full px-2.5 py-2 bg-transparent border-none rounded-md text-text text-[13px] cursor-pointer text-left hover:bg-bg3 transition-colors"
          style="font: inherit;"
          @click="handleSignOut"
        >
          <span class="text-sm">⎋</span>
          <span>Sign out</span>
        </button>
      </div>
    </div>
  </aside>
</template>
