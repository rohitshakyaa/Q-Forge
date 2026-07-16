import { ref } from 'vue';
import { defineStore } from 'pinia';
import api from '../api/client/axios';
import type { UploadStatus } from './extraction';

export interface OverviewStats {
  questionsTotal: number;
  questionsThisWeek: number;
  questionsPending: number;
  documentsTotal: number;
  teachersTotal: number;
  usersTotal: number;
  papersGenerated: number;
}

export interface RecentUpload {
  id: number;
  filename: string;
  subjectCode: string | null;
  questionsCreated: number | null;
  status: UploadStatus;
  createdAt: string;
}

export type ActivityType = 'upload' | 'paper' | 'user';

export interface ActivityItem {
  type: ActivityType;
  title: string;
  detail: string;
  at: string;
}

interface OverviewResponse {
  stats: OverviewStats;
  recentUploads: RecentUpload[];
  activity: ActivityItem[];
}

const EMPTY_STATS: OverviewStats = {
  questionsTotal: 0,
  questionsThisWeek: 0,
  questionsPending: 0,
  documentsTotal: 0,
  teachersTotal: 0,
  usersTotal: 0,
  papersGenerated: 0,
};

export const useAdminOverviewStore = defineStore('adminOverview', () => {
  const stats = ref<OverviewStats>({ ...EMPTY_STATS });
  const recentUploads = ref<RecentUpload[]>([]);
  const activity = ref<ActivityItem[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function fetch() {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.get<OverviewResponse>('/admin/overview');
      stats.value = { ...EMPTY_STATS, ...data.stats };
      recentUploads.value = data.recentUploads ?? [];
      activity.value = data.activity ?? [];
    } catch (e) {
      error.value = 'Failed to load dashboard data.';
      throw e;
    } finally {
      loading.value = false;
    }
  }

  return { stats, recentUploads, activity, loading, error, fetch };
});
