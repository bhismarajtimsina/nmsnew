"""One console session's relay (Plan 38): browser terminal <-> device shell, recorded and time-limited.

Pure asyncio over two small interfaces, so the whole relay is tested without a socket or a device:

- `Client`: the user's side (the gateway wraps its WebSocket in one). `receive()` returns None when the user leaves.
- `Shell`: the device's side, opened by a `ShellFactory`. `read()` returns None when the device closes.

Every chunk in either direction goes to the transcript first, through the redactor, so a secret typed at a password
prompt is never written to the database. The session ends on the first of: the user leaving, the device closing, no
input for `idle_seconds`, or `max_seconds` in total (legacy kills a console after 1800 s).
"""
from __future__ import annotations

import asyncio
import time
from typing import Awaitable, Callable, Protocol

from app.console.redact import TranscriptRedactor

CHUNK_LIMIT = 65536  # console_history.data's limit; longer output is split


class Client(Protocol):
    async def receive(self) -> str | None: ...
    async def send(self, data: str) -> None: ...


class Shell(Protocol):
    async def read(self) -> str | None: ...
    async def write(self, data: str) -> None: ...
    async def close(self) -> None: ...


class ShellUnavailable(Exception):
    """The shell could not be opened: no transport in this build, or the device refused."""


class ShellFactory(Protocol):
    async def connect(self, address: str, *, username: str | None, password: str | None) -> Shell: ...


class DisabledShellFactory:
    """The default: no interactive transport is part of this build yet, so nothing leaves the gateway."""

    async def connect(self, address: str, *, username: str | None, password: str | None) -> Shell:
        raise ShellUnavailable("interactive console transport is not available in this build")


Record = Callable[[str, str], Awaitable[None]]  # (direction, data) -> stored

CLOSED_BY_USER = "closed by user"
CLOSED_BY_DEVICE = "device closed the connection"
IDLE = "idle timeout"
TIME_LIMIT = "time limit reached"


async def run_session(client: Client, shell: Shell, record: Record, *, banner: str, idle_seconds: float, max_seconds: float,
                      clock: Callable[[], float] = time.monotonic, tick: float = 1.0) -> str:
    """Relay until the session ends; returns why it ended. The shell is always closed. The caller records the end and then
    tells the user (`ended_message`), so the record is written before the user's side goes away."""
    redactor = TranscriptRedactor()
    started = last_input = clock()

    async def store(direction: str, data: str) -> None:
        for start in range(0, len(data), CHUNK_LIMIT):
            await record(direction, data[start:start + CHUNK_LIMIT])

    async def pump_in() -> str:
        nonlocal last_input
        while (data := await client.receive()) is not None:
            last_input = clock()
            if data:
                await store("in", redactor.input(data))
                await shell.write(data)
        return CLOSED_BY_USER

    async def pump_out() -> str:
        while (data := await shell.read()) is not None:
            if data:
                await store("out", redactor.output(data))
                await client.send(data)
        return CLOSED_BY_DEVICE

    async def watchdog() -> str:
        while True:
            await asyncio.sleep(tick)
            now = clock()
            if now - started >= max_seconds:
                return TIME_LIMIT
            if now - last_input >= idle_seconds:
                return IDLE

    await store("out", banner)
    await client.send(banner)
    tasks = [asyncio.create_task(pump_in()), asyncio.create_task(pump_out()), asyncio.create_task(watchdog())]
    try:
        done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        for task in pending:
            task.cancel()
        await asyncio.gather(*pending, return_exceptions=True)
        reason = next(iter(done)).result()
    finally:
        for task in tasks:
            task.cancel()
        await shell.close()
    return reason


def ended_message(reason: str) -> str:
    return f"\r\n[session ended: {reason}]\r\n"
