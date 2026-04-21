import type { RouteRecordRaw } from 'vue-router';

const roleProtectedMeta = (role: 'admin' | 'teacher') => ({
  requiresAuth: true,
  roles: [role],
});

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'landing',
    component: () => import('../views/LandingView.vue'),
    meta: { guestOnly: true },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/auth/LoginView.vue'),
    meta: { guestOnly: true },
  },
  {
    path: '/admin',
    component: () => import('../components/layout/AdminShell.vue'),
    meta: roleProtectedMeta('admin'),
    children: [
      {
        path: '',
        name: 'admin-dashboard',
        component: () => import('../views/admin/AdminDashboardView.vue'),
      },
      {
        path: 'upload',
        name: 'admin-upload',
        component: () => import('../views/admin/AdminUploadView.vue'),
      },
      {
        path: 'review',
        name: 'admin-extraction',
        component: () => import('../views/admin/AdminExtractionView.vue'),
      },
      {
        path: 'bank',
        name: 'admin-question-bank',
        component: () => import('../views/admin/AdminQuestionBankView.vue'),
      },
      {
        path: 'subjects',
        name: 'admin-subjects',
        component: () => import('../views/admin/AdminSubjectsView.vue'),
      },
      {
        path: 'subjects/:code',
        name: 'admin-subject-detail',
        component: () => import('../views/admin/AdminSubjectDetailView.vue'),
      },
      {
        path: 'users',
        name: 'admin-users',
        component: () => import('../views/admin/AdminUsersView.vue'),
      },
    ],
  },
  {
    path: '/teacher',
    component: () => import('../components/layout/TeacherShell.vue'),
    meta: roleProtectedMeta('teacher'),
    children: [
      {
        path: '',
        name: 'teacher-dashboard',
        component: () => import('../views/teacher/TeacherDashboardView.vue'),
      },
      {
        path: 'blueprint',
        name: 'teacher-blueprint',
        component: () => import('../views/teacher/BlueprintBuilderView.vue'),
      },
      {
        path: 'blueprint/:id',
        name: 'teacher-blueprint-editor',
        component: () => import('../views/teacher/BlueprintEditorView.vue'),
      },
      {
        path: 'generate',
        name: 'teacher-generate',
        component: () => import('../views/teacher/TeacherGenerateView.vue'),
      },
      {
        path: 'paper/:id',
        name: 'teacher-paper-view',
        component: () => import('../views/teacher/PaperView.vue'),
      },
      {
        path: 'export/:id',
        name: 'teacher-export',
        component: () => import('../views/teacher/TeacherExportView.vue'),
      },
      {
        path: 'history',
        name: 'teacher-history',
        component: () => import('../views/teacher/TeacherHistoryView.vue'),
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    redirect: '/',
  },
];

export default routes;
