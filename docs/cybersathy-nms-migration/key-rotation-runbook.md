# Encryption Key Rotation Runbook

> Plan [36](36-credentials-2fa-service-tokens.md) · Applies to the CyberSathy-NMS backend only (not the legacy system)

## What is encrypted

Every secret stored at rest uses AES-256-GCM through `app/core/crypto.py`. Each value records the id of the key that
encrypted it (`v1:<key_id>:...`) and is bound to its own row by additional authenticated data, so a value copied to
another row or column does not decrypt.

| Column | What it holds |
|---|---|
| `device_access_profiles.snmp_community_enc` | SNMP v1/v2c community |
| `device_access_profiles.snmp_v3_auth_secret_enc` | SNMPv3 authentication secret |
| `device_access_profiles.snmp_v3_priv_secret_enc` | SNMPv3 privacy secret |
| `users.totp_secret_enc` | Users' TOTP (2FA) secrets |

The authoritative list is `ENCRYPTED_COLUMNS` in `app/core/rotation.py`. A test fails if a new `*_enc` column is
added to the schema without being added there.

Keys come from two settings:

- `ENCRYPTION_KEYS`: comma-separated `key_id:base64key` entries. **Every** listed key can decrypt.
- `ENCRYPTION_ACTIVE_KEY_ID`: the one key that encrypts new values.

The key is stored apart from database backups (D-09). A backup restored without the key in force when it was taken
cannot decrypt its secrets.

## When to rotate

- **At once** if a key may have been exposed (it was committed, pasted, logged, or held by someone who has left).
- On a regular schedule otherwise (suggested: yearly).

A rotation changes no device configuration and contacts no device. It only rewrites ciphertexts in the database.

## Procedure

Run every command from the API container (`docker exec -it cybersathy-api ...`) or anywhere with the same
environment. None of them prints a secret.

### 1. Check the starting point

```
python -m app.cli crypto status
```

It shows the active key and, per column, how many values each key id protects. Note the current key id (below,
`k2025`).

### 2. Add a new key and make it active

```
python -m app.cli crypto generate-key
```

Append the new key to `ENCRYPTION_KEYS`, **keeping the old one**, and set `ENCRYPTION_ACTIVE_KEY_ID` to the new id:

```
ENCRYPTION_KEYS=k2025:<old>,k2026:<new>
ENCRYPTION_ACTIVE_KEY_ID=k2026
```

Restart every service that reads them: `cybersathy-api`, `cybersathy-worker`, `cybersathy-trap-receiver`. From now on
new and edited secrets use `k2026`, and old ones still decrypt with `k2025`.

### 3. Re-encrypt what is stored

```
python -m app.cli crypto reencrypt            # dry run: shows what would move, writes nothing
python -m app.cli crypto reencrypt --apply
```

- Safe to run while the system is in use. Each update only lands if the stored value has not changed since it was
  read, and a value edited during the run is reported as "changed during the run". Run the command again for those.
- Safe to run more than once. Values already on the active key are skipped.
- Exit code 1 means some values could not be decrypted with any configured key. Each one is listed by row id and
  left exactly as it was. See "If a value cannot be decrypted" below. **Do not continue to step 4** until this is
  resolved.

### 4. Confirm, then remove the old key

```
python -m app.cli crypto status
```

Exit code 0 and "every stored value is on the active key" means nothing uses `k2025` any more. Remove it from
`ENCRYPTION_KEYS` and restart the same services. If the status still lists `k2025` anywhere, stop and find out why.
Removing a key that is still in use makes those secrets unreadable.

Keep a copy of the retired key, stored as securely as the live one, for as long as backups taken before the rotation
are kept. Restoring one of those needs it.

### 5. Record it

Add the date, operator, old and new key ids and the final `status` output to the operations log (Plan 40). Never
record the keys themselves.

## If a value cannot be decrypted

The row id is printed. Causes, most likely first:

1. **Its key is missing from `ENCRYPTION_KEYS`.** It was encrypted with a key retired too early, or this
   environment was configured from the wrong place. Put that key back and run step 3 again.
2. **The value was copied from another row.** The data is bound to its row, so a value pasted from another row is
   refused on purpose. Re-enter the secret through the API (`PATCH /device-access-profiles/{id}`), or for a TOTP
   secret reset the user's 2FA (`POST /users/{id}/2fa/reset`) so they enroll again.
3. **The value is damaged.** Same fix as 2.

## Rolling back

Before step 4, rolling back is just setting `ENCRYPTION_ACTIVE_KEY_ID` back to the old id and restarting. Both keys
still decrypt everything. Running `crypto reencrypt --apply` then moves values back onto the old key, if wanted.
After step 4, the retired key must be restored to `ENCRYPTION_KEYS` first.
