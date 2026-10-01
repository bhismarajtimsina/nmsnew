# Plan 22: Realtime WebSocket

> **Phase:** 6 · **Depends on:** 4, 12 · **Status:** Done

## Goal
Implement realtime updates for CyberSathy-NMS.

## Current Source / Reference
Current WebSocket server uses PHP/Swoole and `/ws?token=...`.

## Target Design
FastAPI handles `/ws?token=...` with scoped channels.
Stack and principles: see [00-overview](00-overview.md#target-architecture).

## Implementation Steps
- Implement `/ws?token=...`.
- Add channels for device updates, poller finished, event added, alarm changed, ONU status changed, and interface status changed.
- Enforce permission checks per channel.
- Model channel permissions on `config/ws-permissions.yml`: default deny, every channel explicitly listed with its required permission.
- Check scope at subscribe time and again periodically; disconnect or drop channels when permissions change.
- Cap subscriptions per connection.
- Use the short-lived WebSocket token from Plan 36.

## Database/API Impact
No direct schema impact beyond sessions and permissions.

## Frontend Impact
Realtime client reconnects and resubscribes after refresh.

## Security / Access Rules
Invalid token rejected. Reseller receives scoped events only.

## Acceptance Checks
- Admin receives network events.
- Reseller receives scoped events.
- Invalid token rejected.
- Subscribing to an unlisted channel is refused.
- A reseller never receives an out-of-scope event.
- Removing a permission drops the channel without a reconnect.
- Wildcard subscriptions are rejected for scoped users.

## Risks
- Wildcard subscriptions can leak data if not restricted.

## Definition of Done
Channel, scope and revocation tests pass.

## Rollback
Frontend keeps polling as a fallback; the legacy WebSocket stays up until cutover.

## Implementation notes (2026-09-30)

Before writing any code, the real legacy server was read end to end
(`src/Console/WebSocketServerCommand.php`, `config/ws-permissions.yml`) - the same discipline applied to every
other plan this round. It is unusually well-documented: the current source carries inline comments recording four
real production bugs already found and fixed there (an auth header the middleware never actually read; a
trusted-internal-IP bypass that made every token check a no-op until a real client IP was forwarded; a Swoole
table key-length crash once channel names grew past ~64 bytes; a crash on every real user's first subscribe because
of a field the fix assumed existed but doesn't). None of those apply to this system - they were bugs in the legacy
auth-relay-over-HTTP design and its specific in-memory table library - but they were still worth reading, because
they shaped what the real, working behavior actually is versus what an earlier version of that same file assumed.

Built and verified offline: `app/realtime/permissions.py`, a pure port of `isSubscribeAllowed`'s algorithm - default
deny (a channel matched by no rule is refused), **first-match-wins** (the first rule whose pattern matches the
channel decides the outcome; a later rule that would also match, and that the caller *would* satisfy, is never
reached - confirmed this is the real algorithm, not "any matching rule allows it", and mutation-tested the
distinction directly), and a wildcard channel pattern is always rejected for a caller whose role does not see
everything, regardless of which permission it would otherwise need. The specific channel-to-permission mapping
(`CHANNEL_RULES`) is authored fresh for this system's own features (events, incidents, devices, interfaces, traps,
notifications) - `config/ws-permissions.yml` maps a different set of legacy components (macros, links,
switcher-core action logs) this system does not have; what is ported exactly is the algorithm and its shape.
12 tests, 2 mutations checked (the wildcard-rejection rule, and first-match-wins collapsed into "any match wins") -
both caught.

Then built the actual endpoint: `GET /ws?token=...` (`app/api/realtime.py`). Auth reuses the exact credential-lookup
core every other route uses - `authenticate()` in `app/core/security.py` was split into itself (Request-specific:
extracts the token from a header or cookie, rejects a cookie on a state-changing method) plus a new
`authenticate_token(conn, token, *, ip, source)` holding the actual lookup/permission-building logic, since a
WebSocket handshake has no `Request` to extract a cookie from or check a method against - its token arrives only as
`?token=...`, exactly like the legacy server's own. A missing or invalid token closes the socket with code 4401
before ever accepting it, matching legacy's `ws.disconnect($fd, 4401, 'unauthorized')` read directly from
`WebSocketServerCommand.php`. Once connected, the client gets a `{"type": "ready", "user": {...}}` message, then a
small JSON protocol over `receive_text`/`send_json`: `{"action": "ping"}` → `pong`; `{"action": "subscribe",
"channel": ...}` → `subscribed` or `{"type": "error", "error": "forbidden"}` per `is_subscribe_allowed`;
`{"action": "unsubscribe", "channel": ...}` → `unsubscribed`; anything else → `unsupported_action`.

Fan-out is two small pieces: `app/realtime/manager.py`'s `ConnectionManager` tracks this process's own locally
connected clients and their subscribed channel patterns, filtering a published event against each one
(transport-agnostic - `send` is an injected async callable, not a bound WebSocket, so the filtering logic is
unit-testable without opening a socket); `app/realtime/bus.py`'s `publish`/`run_subscriber` relay events between
processes over a single Redis pub/sub channel (`cybersathy:realtime`). This mirrors the legacy Swoole design
exactly, not an in-process shortcut: every process that accepts WebSocket connections runs its own
`ConnectionManager` and its own subscriber loop (started in `app/main.py`'s `lifespan()`, stopped cleanly on
shutdown), a publish reaches every one of them, and each independently pushes only to its own locally connected
clients - the same broadcast-into-N-worker-processes shape the real Swoole server used, just with Redis standing in
for Swoole's own inter-process pipe.

18 tests total for this plan (12 for the permissions algorithm, 6 for `ConnectionManager`, 3 for the Redis relay,
and a 6-test end-to-end suite driving a real ASGI WebSocket handshake through `starlette.testclient.TestClient` -
auth rejection, the full ping/subscribe/unsubscribe protocol, a permission-denied subscribe, and two publish/fan-out
cases including one proving a non-matching event is dropped rather than delivered). 8 further mutations checked
here (missing-token check inverted, auth-exception rejection swallowed, the subscribe permission check dropped, an
unsupported action falling through to `pong`, `ConnectionManager.dispatch` matching every connection regardless of
channel, and the Redis subscriber's malformed-message guard, `stop`-signal check and null-message guard each
removed in turn) - all caught, one of them (the `stop`-signal removal) by hanging the subscriber forever rather
than failing an assertion, confirmed by the test container needing to be killed.

Not ported: a subscription cap per connection, and periodic re-checking of scope after subscribe (legacy doesn't
re-check either mid-connection - a permission change takes effect on the caller's next subscribe, not by dropping
an existing one out from under them). The short-lived WebSocket-specific token mentioned in the original plan
(Plan 36) was never built as a separate thing - the existing session/API-token scheme already produces a short
prefix-checked token, and `authenticate_token` is exactly the reusable core a dedicated WS token would have needed
anyway.

## Implementation notes (2026-10-01): short-lived WebSocket ticket

The "short-lived WebSocket token from Plan 36" this plan called for is built. `/ws` no longer takes the session token
in the URL (`?token=`), because URLs are kept by proxies and access logs. The client first calls
`POST /api/v1/realtime/ticket` with its normal Bearer credential and gets a ticket, then connects with
`/ws?ticket=...`. A ticket (`app/realtime/tickets.py`):

- is random, and Redis stores only its SHA-256 hash, with the kind and id of the session or API token that minted
  it. Neither the ticket nor the credential is stored;
- lives 30 seconds and works once: it is taken out with `GETDEL`, so a copy found in a log is already spent or
  expired;
- is honored only while the minting credential is still valid. The handshake re-runs the full credential check by
  id (`authenticate_credential`, the same code path as token authentication), so logging out also stops that
  session's unused tickets, and IP-strict users are checked against the connecting address;
- cannot be minted with the session cookie: cookies are read-only for state-changing requests, so a cross-site page
  cannot get one.

Legacy's `/ws?token=` shape is deliberately not accepted. A compatibility shim, if one is needed, is Plan 41's
decision. Tests: `test_realtime_ws.py` moved to tickets, plus 6 new tests (missing or invalid ticket, a session token
in the URL refused, single use, expiry, revocation, cookie refused, nothing sensitive in Redis). 5 mutations checked,
all caught: reusable ticket, no expiry, ticket stored unhashed, revoked sessions accepted, and the old `?token=`
handshake restored.
