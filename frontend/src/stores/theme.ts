import { computed } from 'vue';
import { defineStore } from 'pinia';
import { useStorage } from '@vueuse/core';

export type ThemePref = 'light' | 'dark';

function systemTheme(): ThemePref {
  return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

export const useThemeStore = defineStore('theme', () => {
  // null = follow the OS preference; an explicit value = a user override that persists.
  const stored = useStorage<ThemePref | null>('qforge-theme', null);

  const active = computed<ThemePref>(() => stored.value ?? systemTheme());
  const isDark = computed(() => active.value === 'dark');

  function apply() {
    document.documentElement.setAttribute('data-theme', active.value);
  }

  function toggle() {
    stored.value = active.value === 'dark' ? 'light' : 'dark';
    apply();
  }

  // Keep following the OS while the user hasn't made an explicit choice.
  function watchSystem() {
    window
      .matchMedia?.('(prefers-color-scheme: light)')
      .addEventListener('change', () => {
        if (stored.value === null) apply();
      });
  }

  return { active, isDark, apply, toggle, watchSystem };
});
