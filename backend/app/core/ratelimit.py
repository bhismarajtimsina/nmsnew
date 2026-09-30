"""Login throttling backed by Redis.

Failures are counted per account name and per client address inside a sliding window. Once a threshold is reached the
key is locked for an exponentially growing time. The account lock applies even to a correct password, so a guessing
attack cannot win by continuing. Unknown user names are counted exactly like real ones, so locking reveals nothing.
"""
from __future__ import annotations

import hashlib

from redis.asyncio import Redis

from app.core.config import Settings


def _name_key(username: str) -> str:
    return hashlib.sha256(username.strip().lower().encode("utf-8")).hexdigest()[:32]


class LoginGuard:
    def __init__(self, redis: Redis, cfg: Settings) -> None:
        self.redis = redis
        self.cfg = cfg

    def _keys(self, username: str, ip: str | None) -> dict[str, tuple[str, str, int]]:
        keys = {"user": (f"cs:login:fail:user:{_name_key(username)}", f"cs:login:lock:user:{_name_key(username)}", self.cfg.login_max_attempts)}
        if ip:
            keys["ip"] = (f"cs:login:fail:ip:{ip}", f"cs:login:lock:ip:{ip}", self.cfg.login_ip_max_attempts)
        return keys

    async def locked_for(self, username: str, ip: str | None) -> int:
        """Seconds until the caller may try again, 0 when not locked."""
        remaining = 0
        for _, lock_key, _ in self._keys(username, ip).values():
            ttl = await self.redis.ttl(lock_key)
            if ttl and ttl > 0:
                remaining = max(remaining, int(ttl))
        return remaining

    async def record_failure(self, username: str, ip: str | None) -> None:
        for fail_key, lock_key, threshold in self._keys(username, ip).values():
            count = await self.redis.incr(fail_key)
            await self.redis.expire(fail_key, self.cfg.login_failure_window_seconds)
            if count >= threshold:
                seconds = min(
                    self.cfg.login_lockout_base_seconds * (2 ** (count - threshold)),
                    self.cfg.login_lockout_max_seconds,
                )
                await self.redis.set(lock_key, "1", ex=int(seconds))

    async def record_success(self, username: str, ip: str | None) -> None:
        # Only the account counter is cleared. The address counter keeps counting, so one valid login cannot
        # be used to reset a spraying attack that comes from the same address.
        fail_key, lock_key, _ = self._keys(username, None)["user"]
        await self.redis.delete(fail_key, lock_key)
