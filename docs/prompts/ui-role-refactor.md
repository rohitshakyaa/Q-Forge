# Prompt — UI Restyle · Role Separation · Dead-UI Cleanup

> Paste this into a **fresh** Claude Code session at the repo root (`/var/www/Q-Forge`).
> This is a **frontend UX + auth/login + docs** pass — **not** a new algorithm milestone. The
> generation engine, migrations, and the Python service are **out of scope and must not change.**

You are doing a focused refactor of QForge's presentation layer: a **full visual restyle** of the Vue
app, **backend-derived role separation** at login, **removal of dead/non-functional UI**, **page-flow
cleanup**, and a **new `docs/ROLES.md`** documenting the admin vs teacher roles.

## Context (carry forward — decisions already locked)

- **Stack:** Laravel API (`code/`) + Vue 3 / Pinia / Vue Router / Tailwind v4 (`frontend/`). Design
  tokens are `oklch` CSS variables in `frontend/src/style.css`. There is a **custom component
  library** in `frontend/src/components/qf/` (QFButton, QFCard, QFModal, QFInput, QFSelect, QFBadge,
  QFChip, QFSteps, QFProgress, QFPageHeader, QFEmptyState, QFSpinner, QFAvatar, QFAIHint) — **keep
  this abstraction**; restyle *through* these components, don't bypass them with ad-hoc markup.
- **Milestones M1–M5 are Done** (domain, algorithm, papers/export, PDF pipeline, AI bank expansion).
  This pass changes **only** the frontend UX, the **login** endpoint, and docs.
- **Role routing already exists:** `frontend/src/routes/routes.ts` has role-guarded `/admin` and
  `/teacher` shells (`roleProtectedMeta`), `frontend/src/config/navigation.ts` drives per-role nav,
  and `AdminShell.vue` / `TeacherShell.vue` are the layouts. The router guard already enforces
  `meta.roles`. What is **missing** is backend-authoritative role at login (see Deliverable 1).
- **These decisions are final — do not re-litigate them:**
  1. **Remove signup entirely.** There is **no** backend registration route (confirmed) and accounts
     are provisioned by admins via the existing *Users & Roles* screen. Delete the signup mode/form.
  2. **Backend derives the role at login.** The client must **not** send or choose a role.
  3. **Full visual restyle** is in scope — but preserve every working behavior and the QF component API.
  4. Remove the dead UI listed in Deliverable 3 (do not leave non-functional buttons on screen).

## Read first (orientation)

- `CLAUDE.md`, `docs/CONVENTIONS.md` (Docker/sudo, per-service conventions).
- `frontend/src/routes/routes.ts`, `frontend/src/config/navigation.ts`,
  `frontend/src/components/layout/{AdminShell,TeacherShell,QFSidebar,AppShell}.vue`.
- `frontend/src/views/auth/LoginView.vue`, `frontend/src/stores/auth.ts`,
  `frontend/src/api/auth/auth.api.ts`, `frontend/src/types/auth.ts`.
- `code/app/Http/Controllers/Api/AuthController.php`, `code/routes/api.php`,
  `code/app/Http/Middleware/` (the `role:` middleware / `EnsureUserHasRole`).
- Skim every view under `frontend/src/views/admin/` and `frontend/src/views/teacher/` to inventory
  flow and dead controls before touching anything.

## Deliverables

### 1. Backend-derived role separation (login)
- **`AuthController::login`** (`code/app/Http/Controllers/Api/AuthController.php`): drop `role` from
  validation and delete the `role !== $validated['role']` 403 branch. Authenticate on **email +
  password only**; return the user's real `role` in the response (as it already does).
- **Frontend:** remove `role` from `LoginPayload` (`frontend/src/types/auth.ts`), from
  `authApi.login` (`frontend/src/api/auth/auth.api.ts`), and from the `authStore.login` call. After a
  successful login, redirect by the **returned** role: `user.role === 'admin' ? '/admin' : '/teacher'`
  (LoginView already does this — keep it, just stop sending the role in).
- Keep existing tests green and add/adjust an auth feature test: a teacher and an admin each log in
  with **only** email+password and receive their correct role; wrong password → 422.

### 2. Login page redesign
- Remove the **Teacher/Administrator toggle**, the **signup mode/form**, and the hardcoded
  right-hand **"System activity"** stats + **"Latest generation"** card (all fake data).
- Rebuild the login as a clean, single-purpose sign-in screen in the new design system (Deliverable
  4). No fabricated metrics. Theme-aware (light + dark).

### 3. Dead / non-functional UI removal
Remove these (they do nothing or have no backend). Where a control *should* exist but isn't wired,
**remove it** rather than stub it — note it as a follow-up in the final summary:
- `LoginView.vue`: role toggle, signup form, fake stats/latest-generation panels (per Deliverable 2).
- `PaperView.vue`: the **"Replace Question"** modal (`showReplace`, the modal, its buttons at
  ~L200–L251) and the **"AI can suggest alternatives"** edit-mode copy (~L79) — the replace/swap flow
  has no backend and the button only closes the modal.
- `AdminQuestionBankView.vue`: the **"AI Suggest"** button (~L63) and the **"+ Add Question"** button
  (~L65) — neither is wired. (Note: `AdminSubjectDetailView.vue`'s "+ Add Question" **does** open a
  working modal — leave that one, but verify it actually persists before keeping it.)
- Any other button/link discovered during the inventory that has no `@click` / no handler / no route.
  List each one you remove in the final summary.

### 4. Full visual restyle
- **First establish a design system, then apply it screen by screen.** Use the **`/ui-ux-pro-max`**
  skill to choose **one** cohesive direction and produce the token/spacing/type decisions before
  touching screens. Guardrails:
  - It is a **professional exam/paper-generation dashboard** — clarity and density over decoration.
  - **Theme-aware** (light + dark) and **WCAG AA** contrast on text and interactive states.
  - Keep or deliberately evolve the QForge cyan/indigo identity — don't ship an incoherent mix.
  - Restyle by updating `frontend/src/style.css` tokens and the **QF components** so screens inherit
    the new look; avoid per-view one-off styling.
- Apply across: Login, Landing, both Shells + `QFSidebar`, and every admin + teacher view.
- **21st.dev Magic MCP:** it emits **React/shadcn**, which is **not usable in this Vue codebase.**
  Use it for **visual inspiration only** — implement in Vue with the QF components. **Do not add React
  or any new UI dependency.**

### 5. Page-flow / logic cleanup
- Fix awkward flows surfaced during the inventory (redundant back-and-forth, dead ends, buttons that
  navigate nowhere, steppers that don't reflect real state). Examples to check: Generate → Paper →
  Export hand-offs, Blueprint Builder vs Editor overlap, admin Upload → Review → Bank continuity.
- **Present the specific flow issues you found and your proposed fixes for approval before large
  navigational changes** (see Stop Conditions).

### 6. `docs/ROLES.md`
- Create **one** file that clearly documents the two roles for developers. Derive it from
  `code/routes/api.php` and the `role:` middleware — do not invent capabilities. Include:
  - One-line definition of **Admin** and **Teacher**.
  - A **capability matrix**: for each role, the screens they can reach, the API endpoints/middleware
    that gate them, and what they explicitly **cannot** do.
  - How role is enforced (Sanctum + `role:` middleware server-side; router `meta.roles` client-side;
    role-based redirect at login).
  - The seeded demo accounts (`admin@qforge.com` / `teacher@qforge.com`, password `password`).

## Constraints & conventions
- Run all project commands in Docker via `sudo` (non-interactive):
  `echo 'rohitshakya' | sudo -S docker compose exec <service> <cmd>` (see `docs/CONVENTIONS.md`).
- **Do not** change: the generation algorithm (`code/app/Services/PaperGeneration/`), the Python
  service, any migration, or the DB schema. **Do not** add npm/composer dependencies without asking.
- Preserve all working behavior and the QF component public API (props/slots/events).
- Keep the suite green: `docker compose exec qforge_app php artisan test` and
  `docker compose exec qforge_frontend npx vue-tsc -b` must both pass. Run the frontend at
  `http://localhost:8040` and click through both roles before declaring done.

## Working method
1. **Plan first.** Do the full read/inventory, then present: (a) the dead-UI removal list, (b) the
   flow-fix list, (c) the chosen design direction from `/ui-ux-pro-max`. **Confirm before editing.**
2. Land in this order, verifying after each: **(1) login backend + auth → (2) `docs/ROLES.md` →
   (3) design system (tokens + QF components) → (4) restyle + dead-UI removal screen by screen →
   (5) flow fixes.** Restyle one screen fully (and confirm it still works) before moving to the next.
3. After each screen: run `vue-tsc` and eyeball it in the browser at 375px and 1440px.

## Acceptance criteria
- [ ] Login sends **only** email+password; backend returns role; admin lands on `/admin`, teacher on
      `/teacher`; no role toggle, no signup, no fake stats anywhere on the login page.
- [ ] Every dead control in Deliverable 3 is gone; no button on any screen is a no-op.
- [ ] All admin + teacher screens + login + landing share **one** cohesive, theme-aware, AA-contrast
      design; QF components carry the restyle (no per-view one-offs).
- [ ] `docs/ROLES.md` exists with an accurate, route-derived capability matrix for both roles.
- [ ] `php artisan test` green and `vue-tsc -b` clean; both roles click through end-to-end with no
      broken links or dead ends.

## Stop conditions — pause and ask before:
- Deleting any file (removing a *control* within a file is fine; deleting a whole view/route is not).
- Adding any dependency (npm or composer), or introducing React / any new UI framework.
- Changing anything outside the frontend, the login endpoint, or docs — **especially** migrations,
  the generation algorithm, or the Python service.
- Making large navigational/flow changes (renaming routes, merging/removing screens) — get the
  flow-fix list approved first.
- An error can't be resolved in 2 attempts.

## Definition of done
- All acceptance criteria met. Final summary lists: every file changed, every dead control removed,
  every flow fix applied, the chosen design direction, and any follow-ups (e.g. a real
  question-replace/swap endpoint if you judge it worth building later). **Do not commit** unless asked.

---
*This prompt targets an agentic tool with real system access (edits files, runs `docker compose`).
Review the scope locks, forbidden actions, and stop conditions before pasting. Confirm the sudo
password convention and file paths match the project.*
