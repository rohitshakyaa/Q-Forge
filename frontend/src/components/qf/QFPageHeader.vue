<script setup lang="ts">
import { RouterLink } from 'vue-router';
import type { RouteLocationRaw } from 'vue-router';

export interface Crumb {
  label: string;
  to?: RouteLocationRaw;
}

defineProps<{
  title: string;
  subtitle?: string;
  breadcrumbs?: Crumb[];
}>();
</script>

<template>
  <div class="mb-6">
    <nav
      v-if="breadcrumbs && breadcrumbs.length"
      aria-label="Breadcrumb"
      class="flex flex-wrap items-center gap-1.5 mb-2.5 text-[12.5px] text-text3"
    >
      <template v-for="(crumb, i) in breadcrumbs" :key="i">
        <RouterLink
          v-if="crumb.to && i < breadcrumbs.length - 1"
          :to="crumb.to"
          class="text-text2 no-underline transition-colors hover:text-cyan"
        >{{ crumb.label }}</RouterLink>
        <span
          v-else
          :class="i === breadcrumbs.length - 1 ? 'text-text font-medium' : 'text-text2'"
        >{{ crumb.label }}</span>
        <span
          v-if="i < breadcrumbs.length - 1"
          class="text-text3 select-none"
        >/</span>
      </template>
    </nav>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
      <div class="min-w-0">
        <h1 class="font-head text-[20px] sm:text-[22px] font-bold text-text leading-tight">
          {{ title }}
        </h1>
        <p
          v-if="subtitle"
          class="text-text3 text-[13.5px] mt-1"
        >
          {{ subtitle }}
        </p>
      </div>
      <div
        v-if="$slots.actions"
        class="flex flex-wrap gap-2 shrink-0"
      >
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>
