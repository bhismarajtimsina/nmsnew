"""Keeps secrets typed at a prompt out of console transcripts (Plan 38).

A terminal sends keystrokes as they are typed, often one character per message, and a device does not echo a password
back. So the transcript cannot be cleaned afterwards by pattern: the secret is just a run of keystrokes. Instead the
redactor watches the output. When the last line the device printed is a password-style prompt, every keystroke that
follows is withheld from the transcript until Enter, and one marker is written in its place. The keystrokes still go
to the device; only the record is changed.

Prompts covered: password, passwd, passphrase, secret, PIN, enable password and the Russian "пароль" seen on some
legacy devices, case-insensitive, at the end of the output with optional trailing punctuation and spaces. A device
that echoes '*' for each typed character keeps the input hidden. Over-hiding is the safe failure: a line of output that
merely ends in "password" hides input only until the device prints anything else.
"""
from __future__ import annotations

import re

MARKER = "[input hidden]"
_PROMPT = re.compile(r"(pass(word|wd|phrase|code)?|secret|\bpin|пароль)(\s+for\s+[^\r\n]*?)?\s*[:>#?]?\s*$", re.I)
_TAIL = 200  # characters of output kept to recognise a prompt split across chunks
_ENTER = ("\r", "\n")
_ECHO = re.compile(r"[*•\x08 ]+")  # masked echo of typed characters, and backspaces


class TranscriptRedactor:
    def __init__(self) -> None:
        self._tail = ""
        self._masking = False

    @property
    def masking(self) -> bool:
        return self._masking

    def output(self, text: str) -> str:
        """Device output, recorded as is. Updates whether the next input is a secret."""
        if self._masking and _ECHO.fullmatch(text):
            return text  # a device that echoes '*' per character: still the same secret
        self._tail = (self._tail + text)[-_TAIL:]
        last_line = re.split(r"[\r\n]", self._tail)[-1]
        if _PROMPT.search(last_line):
            self._masking = True
        elif last_line.strip():
            # The device printed something else on the current line (for example a new shell prompt): the secret
            # prompt is over, even if Enter was never seen.
            self._masking = False
        return text

    def input(self, text: str) -> str:
        """User input, as it should be recorded."""
        if not self._masking:
            return text
        out = []
        for char in text:
            if self._masking and char in _ENTER:
                out.append(MARKER + char)
                self._masking = False
                self._tail = ""
            elif not self._masking:
                out.append(char)
        return "".join(out)
