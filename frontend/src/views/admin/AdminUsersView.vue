<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import {
  QFAvatar,
  QFBadge,
  QFButton,
  QFCard,
  QFInput,
  QFModal,
  QFPageHeader,
  QFSelect,
} from '../../components/qf';
import { useCatalogStore, type CatalogUser } from '../../stores/catalog';

const catalog = useCatalogStore();

const search = ref('');
const roleFilter = ref<'All' | 'Teacher' | 'Admin'>('All');

const filtered = computed(() =>
  catalog.users.filter((u) => {
    if (roleFilter.value !== 'All' && u.role !== roleFilter.value) return false;
    if (
      search.value &&
      !`${u.name} ${u.email}`.toLowerCase().includes(search.value.toLowerCase())
    )
      return false;
    return true;
  }),
);

const activeCount = computed(() => catalog.users.filter((u) => u.status === 'active').length);
const teacherCount = computed(() => catalog.users.filter((u) => u.role === 'Teacher').length);
const adminCount = computed(() => catalog.users.filter((u) => u.role === 'Admin').length);

const userColor = (u: CatalogUser) =>
  u.role === 'Admin' ? 'var(--cyan)' : 'var(--indigo)';

const inviteModal = ref(false);
const invite = reactive({
  name: '',
  email: '',
  role: 'Teacher' as 'Teacher' | 'Admin',
});

const sendInvite = () => {
  if (!invite.name.trim() || !invite.email.trim()) return;
  catalog.users.push({
    name: invite.name.trim(),
    email: invite.email.trim(),
    role: invite.role,
    subjects: [],
    status: 'active',
    lastSeen: 'just now',
  });
  invite.name = '';
  invite.email = '';
  invite.role = 'Teacher';
  inviteModal.value = false;
};
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Users & Roles"
      subtitle="Manage teacher and admin accounts, permissions, and subject assignments"
    >
      <template #actions>
        <QFButton variant="primary" @click="inviteModal = true">+ Invite User</QFButton>
      </template>
    </QFPageHeader>

    <div
      style="
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-bottom: 24px;
      "
    >
      <div class="qf-stat" style="border-top: 2px solid var(--success)">
        <div class="qf-stat-label">Active Users</div>
        <div class="qf-stat-value" style="color: var(--success)">{{ activeCount }}</div>
        <div class="qf-stat-sub">of {{ catalog.users.length }} total</div>
      </div>
      <div class="qf-stat" style="border-top: 2px solid var(--indigo)">
        <div class="qf-stat-label">Teachers</div>
        <div class="qf-stat-value" style="color: var(--indigo)">{{ teacherCount }}</div>
        <div class="qf-stat-sub">content authors</div>
      </div>
      <div class="qf-stat" style="border-top: 2px solid var(--cyan)">
        <div class="qf-stat-label">Admins</div>
        <div class="qf-stat-value" style="color: var(--cyan)">{{ adminCount }}</div>
        <div class="qf-stat-sub">system managers</div>
      </div>
    </div>

    <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: flex-end">
      <div class="qf-field" style="width: 320px; margin: 0">
        <input v-model="search" class="qf-input" placeholder="Search by name or email…" />
      </div>
      <div style="width: 180px">
        <QFSelect
          v-model="roleFilter"
          :options="['All', 'Teacher', 'Admin']"
        />
      </div>
    </div>

    <QFCard>
      <table class="qf-table">
        <thead>
          <tr>
            <th style="padding-left: 20px">User</th>
            <th>Role</th>
            <th>Subjects</th>
            <th>Status</th>
            <th>Last active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in filtered" :key="u.email">
            <td style="padding-left: 20px">
              <div style="display: flex; align-items: center; gap: 12px">
                <QFAvatar :name="u.name" :color="userColor(u)" :size="36" />
                <div>
                  <div style="font-weight: 500; font-size: 13.5px">{{ u.name }}</div>
                  <div style="font-size: 12px; color: var(--text3)">{{ u.email }}</div>
                </div>
              </div>
            </td>
            <td>
              <QFBadge :variant="u.role === 'Admin' ? 'cyan' : 'indigo'">{{ u.role }}</QFBadge>
            </td>
            <td>
              <div style="display: flex; gap: 4px; flex-wrap: wrap">
                <span
                  v-for="s in u.subjects"
                  :key="s"
                  class="qf-chip"
                  style="font-size: 11px; font-family: var(--font-mono)"
                >{{ s }}</span>
                <span
                  v-if="u.subjects.length === 0"
                  style="font-size: 12px; color: var(--text3)"
                >—</span>
              </div>
            </td>
            <td>
              <QFBadge :variant="u.status === 'active' ? 'success' : 'neutral'" dot>
                {{ u.status === 'active' ? 'Active' : 'Inactive' }}
              </QFBadge>
            </td>
            <td style="color: var(--text3); font-size: 12px">{{ u.lastSeen }}</td>
            <td>
              <QFButton variant="ghost" size="sm">Manage</QFButton>
            </td>
          </tr>
        </tbody>
      </table>
    </QFCard>

    <QFModal :open="inviteModal" title="Invite User" :width="460" @close="inviteModal = false">
      <div style="display: flex; flex-direction: column; gap: 14px">
        <QFInput v-model="invite.name" label="Full name *" placeholder="e.g. Dr. Jane Smith" />
        <QFInput v-model="invite.email" label="Email address *" placeholder="jane@inst.edu" />
        <QFSelect v-model="invite.role" label="Role" :options="['Teacher', 'Admin']" />
      </div>
      <template #footer>
        <QFButton variant="ghost" @click="inviteModal = false">Cancel</QFButton>
        <QFButton variant="primary" @click="sendInvite">Send Invite</QFButton>
      </template>
    </QFModal>
  </div>
</template>
