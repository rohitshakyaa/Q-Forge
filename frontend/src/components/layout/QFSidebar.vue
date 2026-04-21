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

const emit = defineEmits<{ logout: [] }>();

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
  <div class="qf-sidebar">
    <div
      style="
        padding: 8px 20px 16px;
        border-bottom: 1px solid var(--border);
        margin-bottom: 8px;
      "
    >
      <div style="display: flex; align-items: center; gap: 8px">
        <div
          style="
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--cyan), var(--indigo));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            color: #070a10;
            font-family: var(--font-head);
          "
        >
          Q
        </div>
        <span
          style="
            font-family: var(--font-head);
            font-size: 16px;
            font-weight: 700;
            letter-spacing: -0.02em;
          "
        >
          QForge
        </span>
      </div>
      <div style="margin-top: 8px">
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
      >
        <span style="font-size: 16px">{{ item.icon }}</span>
        <span>{{ item.title }}</span>
        <span v-if="item.badge" class="qf-nav-badge">{{ item.badge }}</span>
      </RouterLink>
    </div>

    <div style="flex-grow: 1" />
    <div
      ref="menuRef"
      style="
        position: relative;
        padding: 12px 8px;
        border-top: 1px solid var(--border);
        margin-top: 8px;
      "
    >
      <button
        type="button"
        class="qf-nav-item"
        style="
          gap: 10px;
          width: 100%;
          background: transparent;
          border: none;
          text-align: left;
          cursor: pointer;
          font: inherit;
          color: inherit;
        "
        @click="toggleMenu"
      >
        <QFAvatar :name="userName ?? 'User'" :size="24" />
        <div style="min-width: 0; flex: 1">
          <div
            style="
              font-size: 13px;
              font-weight: 500;
              color: var(--text);
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
            "
          >
            {{ userName ?? 'Profile & Settings' }}
          </div>
        </div>
        <span style="color: var(--text3); font-size: 12px">{{ menuOpen ? '▾' : '▴' }}</span>
      </button>

      <div
        v-if="menuOpen"
        style="
          position: absolute;
          left: 8px;
          right: 8px;
          bottom: calc(100% - 4px);
          background: var(--surface);
          border: 1px solid var(--border);
          border-radius: 8px;
          box-shadow: 0 8px 24px rgba(0, 0, 0, 0.24);
          padding: 4px;
          z-index: 20;
        "
      >
        <button
          type="button"
          style="
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 8px 10px;
            background: transparent;
            border: none;
            border-radius: 6px;
            color: var(--text);
            font: inherit;
            font-size: 13px;
            cursor: pointer;
            text-align: left;
          "
          @mouseenter="(e) => ((e.currentTarget as HTMLElement).style.background = 'var(--surface-2, rgba(255,255,255,0.04))')"
          @mouseleave="(e) => ((e.currentTarget as HTMLElement).style.background = 'transparent')"
          @click="handleSignOut"
        >
          <span style="font-size: 14px">⎋</span>
          <span>Sign out</span>
        </button>
      </div>
    </div>
  </div>
</template>
