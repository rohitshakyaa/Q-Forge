import { createRouter, createWebHistory } from 'vue-router';
import routes from './routes';
import { applyRouteGuards } from './guard';

const router = createRouter({
  history: createWebHistory(),
  routes,
});

applyRouteGuards(router);

export default router;
