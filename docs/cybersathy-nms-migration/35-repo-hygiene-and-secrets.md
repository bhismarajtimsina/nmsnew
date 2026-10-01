# Plan 35: Repository Hygiene and Secrets

> **Phase:** 0 · **Depends on:** none · **Status:** Not started · **Owns:** R-01, R-02, R-03, R-04, R-05

## Goal
Make the repository safe and small enough to build the migration on: no committed secrets, no generated or vendored trees, one place for configuration.

## Current Source / Reference
- `.env` and `frontend/.env` are tracked. The root file holds a real `DATABASE_PASSWD` and integration keys, first committed in `6fe2dcaad`.
- There is no root `.gitignore`.
- 11,234 files across 77 `public/frontend.backup-*` folders are tracked, along with 1,668 `node_modules` files and 6,269 `vendor/` files.
- `Cybersathyisp` is a gitlink (mode 160000) with no `.gitmodules` entry.

## Found on 2026-09-28: the repository is rooted at the home directory (R-05)

`git rev-parse --show-toplevel` is `/root`, not `/root/nms`. The repository therefore tracks about 9,000 files from tool folders (`.codex`, `.cline`, `.config`, `.gemini`, `.copilot`, `.cache`, `.dotnet`) and every project under the home directory. Among them are credential files (`.codex/auth.json`, `.cline/data/secrets.json`) and several `.env` files (`nms/.env`, `nms/frontend/.env`, `backupnms/.env`, `backupnms/frontend/.env`, `.env`).

They are in **local commits only**. `origin/main` (github.com/bhismarajtimsina/ispmanagement) has one commit and the local branch is 23 commits ahead, so nothing from them is on GitHub yet. **A `git push` would publish all of it, and would keep it in GitHub's history even if deleted afterwards.**

Do first, in this order, before any push:
1. Do not push. If a push was attempted earlier, check GitHub for what arrived.
2. Decide the repository root: a repository rooted at `nms/` (recommended), created fresh from the current files, so none of the home-directory history comes with it.
3. Treat every credential that was ever committed as exposed even though it was not pushed, because the local history could be shared by accident: rotate the tool credentials (Codex, Cline, any others in those folders) and the `.env` secrets listed below.
4. Only then add `.gitignore` and untrack, as in the steps below.

## Target Design
Secrets live outside git. Build output and dependency trees are ignored and rebuilt. Configuration is described by an example file that lists names only.

## Implementation Steps
1. **Inventory secrets first.** List every tracked file matching `.env*`, `*.pem`, `*.key`, `*_secret*`, and every compose or config file with an inline password. Record names only in the plan tracker.
2. **Rotate before untracking.** Untracking does not remove history. Treat every value that was ever committed as exposed:
   - Rotate the MySQL password (`DATABASE_PASSWD`) and update the running stack.
   - Rotate Userside, MikBill and NoDeny credentials with each provider.
   - Regenerate the device-access encryption key only through the documented re-encrypt procedure (Plan 36). Do not discard the old key until re-encryption is verified.
3. Add `.env.example` files containing every variable name with safe placeholder values. Keep the real files out of git.
4. Add a root `.gitignore` covering: `.env`, `.env.*` (except `.env.example`), `node_modules/`, `vendor/`, `var/`, `public/frontend*/`, `frontend/dist/`, `__pycache__/`, `*.pem`, `*.key`, `*.bak*`, `*.log`.
5. Remove from the index (without deleting local files): `.env`, `frontend/.env`, `node_modules`, `vendor` (if Composer restores it in the image build), `public/frontend.backup-*`, and generated `public/frontend` assets.
6. Decide on history rewrite (D-14 style decision for the owner): rewriting history with `git filter-repo` removes secrets from old commits but forces every clone to re-clone. If not rewritten, the rotation in step 2 is the only protection, so it is mandatory either way.
7. Add a pre-commit and CI secret scanner (Plan 33) that fails on tracked env files, private keys and credential-shaped strings.
8. Resolve the gitlink: either add a proper `.gitmodules` entry, or remove the pointer. This is **deferred** for the ISP-solution application by request (D-14); this plan only records the state.
9. Move `docker-compose.cybersathy.yml` defaults out of code: remove the `cybersathy` fallback password from `backend/app/core/config.py` and compose, and fail fast when required variables are unset.

## Database/API Impact
None.

## Frontend Impact
Build must work from a clean checkout: `npm ci && npm run build` produces the assets the compose file serves.

## Security / Access Rules
- No secret value is copied into any document, ticket, log or commit message.
- The scanner allow-list is short and reviewed. Adding an entry needs a reviewer.

## Acceptance Checks
- `git ls-files` contains no `.env` (other than `.env.example`), no key files, no `node_modules`, no backup folders.
- A fresh clone builds the frontend and starts the new stack using only documented variables.
- The old MySQL password no longer works.
- The secret scanner fails on a deliberately planted test secret and passes on the clean tree.
- Repository size after cleanup is recorded.

## Risks
- Untracking without rotating leaves the secret valid and exposed in history.
- Removing `vendor/` from git breaks the legacy image build if the Dockerfile relies on it. Check `docker/php-fpm` and `docker/roadrunner` Dockerfiles first.

## Definition of Done
Rotation completed and verified, `.gitignore` in place, tracked files removed, scanner running in CI, fresh-clone build passes, `STATUS.md` updated.

## Rollback
Restoring the tracked files is a `git revert`, but rotated secrets are not restored. Keep the previous values in the secret store during the rotation window, not in git.

## Implementation notes (2026-10-01): tracked-secret scanner

`tools/secret_scan.py`, run in CI on every push and pull request (`.github/workflows/secrets.yml`) and by
`tests/test_secret_scan.py`. File rules refuse names that must never be committed (`.env` files, `.encrypt_passwd`,
private keys, database data directories and dumps, credential stores). Content rules find private-key blocks,
well-known token formats, and non-placeholder `NAME=value` secrets in config-shaped files. It never prints a secret.
Exceptions go in `.secret-scan-allow`, each with a reason, optionally narrowed to one variable.

Running it with `--rev origin/main` reports 255 findings in 242 files: the public leak recorded as **R-06** in
STATUS.md. It also found that the production database password has been hard-coded in `.env-example` and in
Grafana's datasource provisioning since the first commit. On this branch those are replaced: a placeholder in
`.env-example`, and `$DATABASE_PASSWD` in the datasource, with `docker-compose.yml` passing that variable to Grafana.
**Still open for this plan:** rotation, history purge, the full `.gitignore`, and the backup folders (R-03).
