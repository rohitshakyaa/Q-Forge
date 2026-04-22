import type { UserRole } from '../types/auth';

export interface NavItem {
  title: string;
  path: string;
  icon: string;
  badge?: string;
}

export interface NavSection {
  label: string | null;
  items: NavItem[];
}

export const navigationByRole: Record<UserRole, NavSection[]> = {
  admin: [
    {
      label: null,
      items: [{ title: 'Dashboard', path: '/admin', icon: '⬡' }],
    },
    {
      label: 'Content',
      items: [
        { title: 'Past Papers', path: '/admin/upload', icon: '⬆', badge: '2' },
        { title: 'Review Queue', path: '/admin/review', icon: '◈', badge: '3' },
        { title: 'Question Bank', path: '/admin/bank', icon: '◉' },
      ],
    },
    {
      label: 'Management',
      items: [
        { title: 'Subjects & Units', path: '/admin/subjects', icon: '⊞' },
        { title: 'Users & Roles', path: '/admin/users', icon: '◎' },
      ],
    },
  ],
  teacher: [
    {
      label: null,
      items: [{ title: 'Dashboard', path: '/teacher', icon: '⬡' }],
    },
    {
      label: 'Papers',
      items: [
        { title: 'Blueprint Builder', path: '/teacher/blueprint', icon: '⬢' },
        { title: 'Generate Paper', path: '/teacher/generate', icon: '✦' },
      ],
    },
    {
      label: 'Records',
      items: [{ title: 'History & Analytics', path: '/teacher/history', icon: '◎' }],
    },
  ],
};
