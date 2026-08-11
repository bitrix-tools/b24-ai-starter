# Server Background Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing synchronization checkbox and interval control a server-side schedule that continues after the Bitrix24 application closes.

**Architecture:** A one-minute cron dispatcher invokes the Django management command under `flock`. The command reads each portal’s enabled flag and interval, uses `TochkaCredentials.updated_at` as the persisted last-attempt timestamp, and synchronizes only due portals. The existing browser timer remains for immediate UI refresh while the dashboard is open.

**Tech Stack:** Django management command, PostgreSQL-backed models, Python `unittest`, Linux cron and `flock`, Nuxt/Vue.

---

### Task 1: Add testable interval scheduling

**Files:**
- Create: `backends/python/api/main/background_sync_schedule.py`
- Create: `backends/python/api/main/tests/test_background_sync_schedule.py`

- [ ] **Step 1: Write failing schedule tests**

Cover disabled portals, forced runs, not-yet-due portals, due portals, and the 3–180 minute bounds.

- [ ] **Step 2: Run the focused test and verify RED**

```bash
cd backends/python/api
python3 -m unittest main.tests.test_background_sync_schedule -v
```

Expected: import failure because the scheduling module does not exist.

- [ ] **Step 3: Implement the pure scheduling decision**

Create a focused function accepting `enabled`, configured interval, last attempt,
current time, and `force`. Clamp intervals to 3–180 minutes and return whether
the portal is due.

- [ ] **Step 4: Run the focused test and verify GREEN**

Expected: all scheduling cases pass.

### Task 2: Make the management command respect portal settings

**Files:**
- Modify: `backends/python/api/main/management/commands/sync_tochka_background.py`
- Modify: `backends/python/api/main/tests/test_background_sync_schedule.py`

- [ ] **Step 1: Add a failing source integration test**

Assert that the command imports the scheduling decision, reads
`TochkaPortalPreferences`, supports `--force`, and updates
`TochkaCredentials.updated_at` before a bank request.

- [ ] **Step 2: Run the test and verify RED**

Expected: failure because the command currently synchronizes every connected
credential unconditionally.

- [ ] **Step 3: Implement settings-aware dispatch**

The default run must:

- load enabled preferences by `member_id`;
- skip disabled/missing preferences;
- skip portals whose interval has not elapsed;
- update `updated_at` before each attempted sync;
- continue processing other portals after an error.

`--force` must bypass the preference and interval checks for administrator
diagnostics.

- [ ] **Step 4: Run tests and compile the command**

```bash
python3 -m unittest main.tests.test_background_sync_schedule -v
python3 -m compileall -q \
  main/background_sync_schedule.py \
  main/management/commands/sync_tochka_background.py
```

Expected: exit code 0.

### Task 3: Update synchronization UI wording

**Files:**
- Modify: `frontend/app/pages/dashboard.client.vue`
- Create: `frontend/tests/background-sync-copy.test.mjs`

- [ ] **Step 1: Add a failing copy regression test**

Assert that the settings label says `Включить фоновую синхронизацию`, the help
text states that the server continues after the application closes, and the old
claim that background requests stop after closing is absent.

- [ ] **Step 2: Run the test and verify RED**

```bash
cd frontend
node --test tests/background-sync-copy.test.mjs
```

- [ ] **Step 3: Replace the browser-only wording**

Keep the same checkbox and interval fields. Explain that the browser refreshes
the open dashboard while the server follows the saved interval independently.

- [ ] **Step 4: Verify frontend**

```bash
node --test tests/background-sync-copy.test.mjs
pnpm postinstall
pnpm exec eslint app/pages/dashboard.client.vue tests/background-sync-copy.test.mjs
pnpm build
```

Expected: copy test and build pass. Record unrelated pre-existing lint failures
separately if the whole-file dashboard lint remains blocked.

### Task 4: Install production scheduler and enable the portal

**Files:**
- Deploy:
  - `backends/python/api/main/background_sync_schedule.py`
  - `backends/python/api/main/management/commands/sync_tochka_background.py`
  - `frontend/app/pages/dashboard.client.vue`
- Server: root crontab on `31.129.97.40`

- [ ] **Step 1: Upload changed application files**

Upload only scoped files to `/opt/b24-tochka-app` to avoid synchronizing
unrelated working-tree changes.

- [ ] **Step 2: Rebuild API and frontend services**

```bash
docker-compose -f docker-compose.production.yml up -d --build api frontend
```

- [ ] **Step 3: Install idempotent cron entry**

Install exactly one entry:

```cron
* * * * * /usr/bin/flock -n /var/lock/tochka-sync.lock /usr/bin/docker exec tochka-api python manage.py sync_tochka_background --days 2 >> /var/log/tochka-sync.log 2>&1
```

- [ ] **Step 4: Enable the current portal**

Set `client_auto_sync_enabled=true` and
`client_auto_sync_interval_minutes=15` for the connected production portal.

- [ ] **Step 5: Run forced verification**

```bash
docker exec tochka-api python manage.py sync_tochka_background --days 1 --force
```

Expected: the connected portal synchronizes successfully.

- [ ] **Step 6: Verify normal schedule decisions**

Immediately run the command without `--force`.

Expected: the portal is skipped because 15 minutes have not elapsed.

- [ ] **Step 7: Verify scheduler health**

Confirm:

- cron contains one scheduler entry;
- API/frontend containers are running;
- `/var/log/tochka-sync.log` is created by cron;
- production preferences show enabled/15;
- no parallel command remains after the previous run.

No Git commit is included unless the user explicitly requests one.
