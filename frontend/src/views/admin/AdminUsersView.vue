<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
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
import { useUsersStore, type ManagedUser } from '../../stores/users';
import { useAuthStore } from '../../stores/auth';
import type { UserRole } from '../../types/auth';

const usersStore = useUsersStore();
const authStore = useAuthStore();

const search = ref('');
const roleFilter = ref<'All' | 'teacher' | 'admin'>('All');

const roleOptions = [
  { value: 'teacher', label: 'Teacher' },
  { value: 'admin', label: 'Admin' },
];
const roleFilterOptions = [
  { value: 'All', label: 'All roles' },
  ...roleOptions,
];

onMounted(() => usersStore.fetch());

const filtered = computed(() =>
  usersStore.list.filter((u) => {
    if (roleFilter.value !== 'All' && u.role !== roleFilter.value) return false;
    if (
      search.value &&
      !`${u.name} ${u.email}`.toLowerCase().includes(search.value.toLowerCase())
    )
      return false;
    return true;
  }),
);

const total = computed(() => usersStore.list.length);
const teacherCount = computed(() => usersStore.list.filter((u) => u.role === 'teacher').length);
const adminCount = computed(() => usersStore.list.filter((u) => u.role === 'admin').length);

const userColor = (u: ManagedUser) => (u.role === 'admin' ? 'var(--cyan)' : 'var(--indigo)');
const roleLabel = (r: UserRole) => (r === 'admin' ? 'Admin' : 'Teacher');
const formatDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';

// --- Create / edit modal ------------------------------------------------
const modal = reactive({
  open: false,
  mode: 'create' as 'create' | 'edit',
  id: null as number | null,
  name: '',
  email: '',
  role: 'teacher' as UserRole,
  password: '',
});
const modalError = ref('');
const saving = ref(false);

const openCreate = () => {
  Object.assign(modal, {
    open: true, mode: 'create', id: null,
    name: '', email: '', role: 'teacher', password: '',
  });
  modalError.value = '';
};

const openEdit = (u: ManagedUser) => {
  Object.assign(modal, {
    open: true, mode: 'edit', id: u.id,
    name: u.name, email: u.email, role: u.role, password: '',
  });
  modalError.value = '';
};

const saveUser = async () => {
  modalError.value = '';
  if (!modal.name.trim() || !modal.email.trim()) {
    modalError.value = 'Name and email are required.';
    return;
  }
  if (modal.mode === 'create' && modal.password.length < 8) {
    modalError.value = 'Set an initial password of at least 8 characters.';
    return;
  }
  saving.value = true;
  try {
    if (modal.mode === 'create') {
      await usersStore.create({
        name: modal.name.trim(),
        email: modal.email.trim(),
        role: modal.role,
        password: modal.password,
      });
    } else if (modal.id != null) {
      await usersStore.update(modal.id, {
        name: modal.name.trim(),
        email: modal.email.trim(),
        role: modal.role,
        ...(modal.password ? { password: modal.password } : {}),
      });
    }
    modal.open = false;
  } catch (error) {
    modalError.value = extractError(error, 'Unable to save user.');
  } finally {
    saving.value = false;
  }
};

// --- Delete confirm -----------------------------------------------------
const deleteConfirm = ref<ManagedUser | null>(null);
const deleteError = ref('');
const deleting = ref(false);

const confirmDelete = async () => {
  if (!deleteConfirm.value) return;
  deleteError.value = '';
  deleting.value = true;
  try {
    await usersStore.remove(deleteConfirm.value.id);
    deleteConfirm.value = null;
  } catch (error) {
    deleteError.value = extractError(error, 'Unable to delete user.');
  } finally {
    deleting.value = false;
  }
};

const isSelf = (u: ManagedUser) => u.id === authStore.user?.id;

function extractError(error: unknown, fallback: string): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return firstFieldError ?? data?.message ?? fallback;
  }
  return fallback;
}
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Users & Roles"
      subtitle="Provision and manage teacher and admin accounts"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/admin' },
        { label: 'Users & Roles' },
      ]"
    >
      <template #actions>
        <QFButton variant="primary" @click="openCreate">+ Add User</QFButton>
      </template>
    </QFPageHeader>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5 mb-6">
      <div class="qf-stat" style="border-top: 2px solid var(--text2)">
        <div class="qf-stat-label">Total Users</div>
        <div class="qf-stat-value">{{ total }}</div>
        <div class="qf-stat-sub">all accounts</div>
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

    <div class="flex flex-wrap gap-3 mb-5 items-end">
      <div class="qf-field flex-1 min-w-[220px] sm:flex-none sm:w-80 m-0">
        <input v-model="search" class="qf-input" placeholder="Search by name or email…" />
      </div>
      <div class="w-full sm:w-44">
        <QFSelect v-model="roleFilter" :options="roleFilterOptions" />
      </div>
    </div>

    <QFCard>
      <div class="qf-table-wrap">
      <table class="qf-table">
        <thead>
          <tr>
            <th style="padding-left: 20px">User</th>
            <th>Role</th>
            <th>Added</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in filtered" :key="u.id">
            <td style="padding-left: 20px">
              <div style="display: flex; align-items: center; gap: 12px">
                <QFAvatar :name="u.name" :color="userColor(u)" :size="36" />
                <div>
                  <div style="font-weight: 500; font-size: 13.5px">
                    {{ u.name }}
                    <span v-if="isSelf(u)" style="font-size: 11px; color: var(--text3)">(you)</span>
                  </div>
                  <div style="font-size: 12px; color: var(--text3)">{{ u.email }}</div>
                </div>
              </div>
            </td>
            <td>
              <QFBadge :variant="u.role === 'admin' ? 'cyan' : 'indigo'">{{ roleLabel(u.role) }}</QFBadge>
            </td>
            <td style="color: var(--text3); font-size: 12px">{{ formatDate(u.created_at) }}</td>
            <td>
              <div style="display: flex; gap: 6px; justify-content: flex-end">
                <QFButton variant="ghost" size="sm" @click="openEdit(u)">Edit</QFButton>
                <QFButton
                  v-if="!isSelf(u)"
                  variant="ghost"
                  size="sm"
                  @click="deleteConfirm = u; deleteError = ''"
                >✕</QFButton>
              </div>
            </td>
          </tr>
          <tr v-if="filtered.length === 0">
            <td colspan="4" style="padding: 24px 20px; color: var(--text3); text-align: center">
              No users match your filters.
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </QFCard>

    <QFModal
      :open="modal.open"
      :title="modal.mode === 'create' ? 'Add User' : 'Edit User'"
      :width="460"
      @close="modal.open = false"
    >
      <div class="flex flex-col gap-3.5">
        <QFInput v-model="modal.name" label="Full name *" placeholder="e.g. Dr. Jane Smith" />
        <QFInput v-model="modal.email" type="email" label="Email address *" placeholder="jane@inst.edu" />
        <QFSelect v-model="modal.role" label="Role" :options="roleOptions" />
        <QFInput
          v-model="modal.password"
          type="password"
          autocomplete="new-password"
          :label="modal.mode === 'create' ? 'Initial password *' : 'Reset password'"
          :placeholder="modal.mode === 'create' ? 'At least 8 characters' : 'Leave blank to keep current'"
        />
        <div
          v-if="modalError"
          class="bg-danger-dim border border-danger rounded-md px-3.5 py-2.5 text-danger text-[13px]"
        >
          {{ modalError }}
        </div>
      </div>
      <template #footer>
        <QFButton variant="ghost" @click="modal.open = false">Cancel</QFButton>
        <QFButton variant="primary" :disabled="saving" @click="saveUser">
          {{ saving ? 'Saving…' : modal.mode === 'create' ? 'Create User' : 'Save Changes' }}
        </QFButton>
      </template>
    </QFModal>

    <QFModal
      :open="!!deleteConfirm"
      title="Delete User"
      :width="420"
      @close="deleteConfirm = null"
    >
      <p style="font-size: 13.5px; color: var(--text2); line-height: 1.6">
        Delete <strong style="color: var(--text)">{{ deleteConfirm?.name }}</strong>
        ({{ deleteConfirm?.email }})? This cannot be undone.
      </p>
      <div
        v-if="deleteError"
        class="bg-danger-dim border border-danger rounded-md px-3.5 py-2.5 text-danger text-[13px] mt-3"
      >
        {{ deleteError }}
      </div>
      <template #footer>
        <QFButton variant="ghost" @click="deleteConfirm = null">Cancel</QFButton>
        <QFButton variant="danger" :disabled="deleting" @click="confirmDelete">
          {{ deleting ? 'Deleting…' : 'Delete User' }}
        </QFButton>
      </template>
    </QFModal>
  </div>
</template>
