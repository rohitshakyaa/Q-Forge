# Roles — Admin vs Teacher

QForge has exactly two roles, stored on `users.role` (enum `admin | teacher`, default
`teacher`). This document is derived from [`code/routes/api.php`](../code/routes/api.php), the
`role:` middleware ([`EnsureUserHasRole`](../code/app/Http/Middleware/EnsureUserHasRole.php)), the
Vue router ([`frontend/src/routes/routes.ts`](../frontend/src/routes/routes.ts)), and the
seeders — not from intent. If a capability isn't backed by a route, it isn't listed.

- **Admin** — content steward. Provisions accounts, curates the catalog (subjects/units), owns
  the question bank, and runs the PDF/syllabus ingestion + review pipeline.
- **Teacher** — paper author. Builds blueprints and generates, edits, exports, and reviews
  question papers from the approved bank.

There is **no public signup.** Accounts are provisioned by an admin on the *Users & Roles*
screen (`POST /api/users`). The role is **derived server-side at login** and returned to the
client — the client never sends or chooses it.

---

## Capability matrix

### Admin

| Area | Screens (client) | API (server) | Gate |
|------|------------------|--------------|------|
| Dashboard | `/admin` | `GET /admin/dashboard` | `auth:sanctum` + `role:admin` |
| Users & Roles | `/admin/users` | `GET/POST/PUT/DELETE /users` | `role:admin` |
| Subjects & Units | `/admin/subjects`, `/admin/subjects/:code` | `POST/PUT/DELETE /subjects`, nested/shallow `.../units` | `role:admin` |
| Question Bank | `/admin/bank` | `apiResource /questions` (full CRUD) | `role:admin` |
| Review Queue | `/admin/review` | `POST /questions/{q}/approve\|reject`, `POST /questions/bulk-approve\|bulk-reject` | `role:admin` |
| Past-paper / syllabus intake | `/admin/upload`, `/admin/syllabus/:uploadId` | `apiResource /uploads` (index/store/show/destroy), `POST /uploads/{u}/import`, `POST /subjects/{s}/past-papers` | `role:admin` |
| Catalog browsing (shared) | (used within the above) | `GET /subjects`, `GET /subjects/{s}`, `GET /subjects/{s}/units` | `role:admin,teacher` |

**Admins cannot:** build blueprints, generate/edit/export papers, or view paper analytics
(all teacher-only). They also **cannot delete their own account** or **demote the last remaining
admin** — both are blocked server-side in `UserController` (422).

### Teacher

| Area | Screens (client) | API (server) | Gate |
|------|------------------|--------------|------|
| Dashboard | `/teacher` | `GET /teacher/dashboard` | `auth:sanctum` + `role:teacher` |
| Blueprints | `/teacher/blueprint`, `/teacher/blueprint/:id` | `apiResource /blueprints` (full, owner-scoped) | `role:teacher` |
| Generate paper | `/teacher/generate` | `POST /papers/generate`; `POST /blueprints/{b}/expand-bank`, `GET /jobs/{batchId}` (M5 AI bank expansion) | `role:teacher` |
| Paper view / edit | `/teacher/paper/:id` | `apiResource /papers` (index/show/update/destroy) | `role:teacher` |
| Export | `/teacher/export/:id` | `GET /papers/{p}/export` | `role:teacher` |
| History & analytics | `/teacher/history` | `GET /papers/analytics` | `role:teacher` |
| Catalog browsing (shared) | (used within blueprint building) | `GET /subjects`, `GET /subjects/{s}`, `GET /subjects/{s}/units` | `role:admin,teacher` |

**Teachers cannot:** manage users, write to the subject/unit catalog, touch the question bank
or review queue, or upload/import documents (all admin-only). Blueprints and papers are
**owner-scoped** — a teacher only sees their own.

---

## How the role is enforced

1. **Authentication** — Laravel Sanctum bearer tokens. `POST /api/auth/login` verifies
   **email + password only** and returns the user's real `role`; every protected route runs
   behind `auth:sanctum`.
2. **Server-side authorization** — the `role:` middleware alias resolves to
   `EnsureUserHasRole`, which returns **401** when unauthenticated and **403** when the
   authenticated user's role is not in the allowed list (`role:admin`, `role:teacher`, or the
   shared `role:admin,teacher`). This is the authoritative gate.
3. **Client-side routing** — the router marks `/admin` and `/teacher` shells with
   `meta.roles` (via `roleProtectedMeta`); the global `beforeEach` guard redirects a user
   who lacks the required role. This is UX only — it never replaces the server gate.
4. **Login redirect** — after a successful login the client routes by the **returned** role:
   `user.role === 'admin' ? '/admin' : '/teacher'`.

---

## Seeded demo accounts

Created by [`AdminUserSeeder`](../code/database/seeders/AdminUserSeeder.php):

| Role | Email | Password |
|------|-------|----------|
| Admin | `admin@qforge.com` | `password` |
| Teacher | `teacher@qforge.com` | `password` |
