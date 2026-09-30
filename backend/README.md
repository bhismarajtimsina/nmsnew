# CyberSathy-NMS backend

FastAPI + PostgreSQL/TimescaleDB + Redis. The plan and status live in [docs/cybersathy-nms-migration](../docs/cybersathy-nms-migration/README.md).

## Run the tests

```bash
tools/test-backend.sh                                  # everything
tools/test-backend.sh tests/test_auth.py -k lockout    # any pytest arguments
```

It starts a throwaway PostgreSQL and Redis on a private Docker network and removes them afterwards. The test suite refuses any network connection except loopback and those two containers, so nothing here can reach a device.

## Run the stack

```bash
cp docker/cybersathy.env.example cybersathy.env        # fill in the two passwords; the file is git-ignored
docker compose --env-file cybersathy.env -f docker-compose.cybersathy.yml up -d --build
docker compose --env-file cybersathy.env -f docker-compose.cybersathy.yml logs cybersathy-init   # a generated admin password appears here once
```

`cybersathy-init` applies migrations and the idempotent seed, then exits; the API starts after it and refuses to serve a schema that is behind the code.

## Workers

`python -m app.workers run --kinds dispatcher,discovery,poller` runs in the `cybersathy-worker` container. It needs `JOB_SIGNING_KEY`. `SNMP_TRANSPORT` is `disabled` and is the only value this build accepts: no real transport exists yet (decision D-17), so the worker heartbeats and dispatches jobs but consumes none and never opens a session to a device. `python -m app.workers health` is its container health check.

## Admin commands

`docker compose ... run --rm cybersathy-init python -m app.cli <command>`

| Command | Purpose |
|---|---|
| `db upgrade` | Apply migrations |
| `db check` | Compare the live schema with `app/schema_snapshot.json` (finds manual changes) |
| `db snapshot --write` | Regenerate the snapshot after changing a migration |
| `seed [--reset-role-permissions]` | Insert missing permissions, roles, vendors; never touches credentials |
| `users set-password <name>` | Set a password and revoke that user's sessions |
| `sessions cleanup` | Delete expired sessions and old login attempts |
| `crypto generate-key` | Print a new encryption key |

## Conventions

- Credentials: `Authorization: Bearer <token>`. Browsers may also send the `cs_session` cookie, read-only.
- Every route declares `public`, `authenticated` or a permission; a test fails if one does not.
- Scope rules are written in `app/repositories/scope.py` only; routers never query scoped tables.
- After changing a migration, regenerate `app/schema_snapshot.json`; a test compares it with what the migrations produce.
- After changing the permission mapping, run `python3 tools/gen_permission_catalogue.py`.
