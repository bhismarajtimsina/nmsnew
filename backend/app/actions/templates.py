"""Macro and ONU-registration templates (Plan 38): validated parameters and sandboxed rendering.

A template renders into console commands, one per line, that a later step sends to a device. So two things must
hold: an editor of a template can never run code on this server, and a person filling in a parameter can never add a
command of their own.

Legacy got both wrong (found 2026-10-05, read from origin/main's MacrosGateway.php):
- Templates were rendered by a plain Twig 3.10.3 Environment with no sandbox extension. Whoever may edit a macro was
  writing unsandboxed template code on the server. How far that reaches was not tried (that would mean exploiting the
  production system); recorded as likely, not verified.
- Text parameters were checked with `preg_match("/{$regex}/")`: unanchored, and an empty pattern accepted anything.
  `10 ; reboot` passes `[0-9]+`, and a value with a newline passes most patterns, which turns into an extra console
  command once the template is rendered line by line.

Here:
- Rendering uses Jinja2's ImmutableSandboxedEnvironment with strict undefined variables, no loader (so no include,
  import or extends can read anything), a capped `range`, capped string and list repetition, a template size cap and
  an output cap. Variables are copied down to plain dicts, lists, strings and numbers first, with anything that looks
  like a secret dropped, so no Python object is reachable from a template.
- Every parameter must be declared; text parameters must match their pattern in full (re.fullmatch), every pattern
  is compiled when the template is saved, and no value may contain a control character (a newline included). A text
  parameter with no pattern gets a conservative default rather than "anything".
- Legacy's `<exception "message">` line, which lets a template refuse to run (for example when a lookup found
  nothing), is kept.
"""
from __future__ import annotations

import operator
import re
from dataclasses import dataclass
from typing import Any

from jinja2 import StrictUndefined, TemplateError, nodes
from jinja2.sandbox import ImmutableSandboxedEnvironment, SecurityError

from app.core.audit import is_sensitive_key

MAX_TEMPLATE_CHARS = 20_000
MAX_OUTPUT_CHARS = 64_000
MAX_COMMANDS = 500
MAX_RANGE = 4096
MAX_REPEAT = 10_000          # longest string or list a `*` may build
MAX_PARAM_CHARS = 120
DEFAULT_TEXT_PATTERN = r"[A-Za-z0-9 ._:/@-]{1,64}"
PARAM_KEY = re.compile(r"^[a-z][a-z0-9_]{0,39}$")
PARAM_TYPES = ("select_predefined", "select_from_variable", "input_string")
_CONTROL = re.compile(r"[\x00-\x1f\x7f]")
_EXCEPTION_LINE = re.compile(r"""<\s*exception\s*['"](.*?)['"].*?>""")


class TemplateRejected(Exception):
    """The template, a parameter, or the rendered result is not acceptable. The message is safe to show."""


class TemplateAbort(Exception):
    """The template asked not to run (`<exception "...">`)."""


@dataclass(frozen=True)
class Parameter:
    key: str
    type: str
    variants: tuple[str, ...] = ()     # select_predefined
    source: str | None = None          # select_from_variable: dotted path to a list in the variables
    pattern: str | None = None         # input_string


def parse_parameters(raw: list[dict[str, Any]]) -> list[Parameter]:
    """A template's declared parameters, validated as they are saved: known types, valid keys, non-empty variant
    lists, and patterns that compile. A bad declaration is refused at save time, not discovered at run time."""
    out, seen = [], set()
    for item in raw:
        key, kind = str(item.get("key", "")), str(item.get("type", ""))
        if not PARAM_KEY.match(key) or key in seen:
            raise TemplateRejected(f"parameter key {key!r} is invalid or repeated")
        if kind not in PARAM_TYPES:
            raise TemplateRejected(f"parameter {key} has unknown type {kind!r}")
        seen.add(key)
        if kind == "select_predefined":
            variants = tuple(str(v) for v in item.get("variants") or ())
            if not variants or any(_CONTROL.search(v) or len(v) > MAX_PARAM_CHARS for v in variants):
                raise TemplateRejected(f"parameter {key} needs a list of plain variants")
            out.append(Parameter(key, kind, variants=variants))
        elif kind == "select_from_variable":
            source = str(item.get("source", ""))
            if not re.fullmatch(r"[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)*", source):
                raise TemplateRejected(f"parameter {key} needs a dotted source path")
            out.append(Parameter(key, kind, source=source))
        else:
            pattern = item.get("pattern") or None
            if pattern is not None:
                try:
                    re.compile(pattern)
                except re.error as exc:
                    raise TemplateRejected(f"parameter {key} has an invalid pattern: {exc}") from exc
            out.append(Parameter(key, kind, pattern=pattern))
    return out


def _lookup(variables: dict[str, Any], path: str) -> Any:
    value: Any = variables
    for part in path.split("."):
        if not isinstance(value, dict) or part not in value:
            return None
        value = value[part]
    return value


def validate_parameters(parameters: list[Parameter], values: dict[str, Any], variables: dict[str, Any]) -> dict[str, str]:
    """The values, each checked against its declaration. Every declared parameter must be given, nothing undeclared
    may be, and no value may carry a control character: a newline would become a command of its own."""
    declared = {p.key for p in parameters}
    if set(values) != declared:
        raise TemplateRejected(f"expected exactly the parameters {sorted(declared)}")
    clean = {}
    for p in parameters:
        value = values[p.key]
        if not isinstance(value, (str, int)) or isinstance(value, bool):
            raise TemplateRejected(f"{p.key} must be text")
        value = str(value)
        if _CONTROL.search(value) or len(value) > MAX_PARAM_CHARS:
            raise TemplateRejected(f"{p.key} contains a control character or is too long")
        if p.type == "select_predefined" and value not in p.variants:
            raise TemplateRejected(f"{p.key} must be one of the listed values")
        if p.type == "select_from_variable":
            options = _lookup(variables, p.source or "")
            if not isinstance(options, list) or value not in [str(o) for o in options]:
                raise TemplateRejected(f"{p.key} must be one of the values in {p.source}")
        if p.type == "input_string" and not re.fullmatch(p.pattern or DEFAULT_TEXT_PATTERN, value):
            raise TemplateRejected(f"{p.key} does not match its pattern")
        clean[p.key] = value
    return clean


def plain(value: Any, depth: int = 0) -> Any:
    """A copy made only of dicts, lists, strings, numbers, booleans and None. Keys that look like secrets are dropped,
    and anything else (objects, functions, bytes) becomes its string form, so nothing callable reaches a template."""
    if depth > 12:
        raise TemplateRejected("variables are nested too deeply")
    if value is None or isinstance(value, (bool, int, float, str)):
        return value
    if isinstance(value, dict):
        return {str(k): plain(v, depth + 1) for k, v in value.items() if not is_sensitive_key(k)}
    if isinstance(value, (list, tuple)):
        return [plain(v, depth + 1) for v in value]
    return str(value)


def _bounded_range(*args: int) -> range:
    r = range(*args)
    if len(r) > MAX_RANGE:
        raise SecurityError(f"range is limited to {MAX_RANGE} items")
    return r


class _Sandbox(ImmutableSandboxedEnvironment):
    intercepted_binops = frozenset({"*", "**"})

    def call_binop(self, context, operator_name, left, right):  # type: ignore[override]
        if operator_name == "**":
            if abs(left) > 1_000_000 or abs(right) > 64:
                raise SecurityError("exponent too large")
            return operator.pow(left, right)
        if isinstance(left, (str, list, tuple)) or isinstance(right, (str, list, tuple)):
            sequence, count = (left, right) if isinstance(left, (str, list, tuple)) else (right, left)
            if not isinstance(count, int) or len(sequence) * max(count, 0) > MAX_REPEAT:
                raise SecurityError("repetition too large")
        return operator.mul(left, right)


def _environment() -> _Sandbox:
    env = _Sandbox(undefined=StrictUndefined, autoescape=False, keep_trailing_newline=False)
    env.globals = {"range": _bounded_range}  # no lipsum, cycler, joiner, namespace or dict helpers
    return env


def check_template(template: str) -> None:
    """Refuse a template that is too big or does not parse. Run when a template is saved."""
    if len(template) > MAX_TEMPLATE_CHARS:
        raise TemplateRejected(f"a template is limited to {MAX_TEMPLATE_CHARS} characters")
    try:
        tree = _environment().parse(template)
    except TemplateError as exc:
        raise TemplateRejected(f"the template does not parse: {exc}") from exc
    # A template stands alone: no other template, file or module may be pulled in. Refused here, by name, rather
    # than left to fail later for want of a loader.
    if any(True for _ in tree.find_all((nodes.Include, nodes.Import, nodes.FromImport, nodes.Extends))):
        raise TemplateRejected("include, import, from and extends are not allowed in a template")


def render(template: str, variables: dict[str, Any]) -> list[str]:
    """The commands a template produces for these variables, one per line, blank output lines dropped (as legacy did). Raises
    TemplateRejected for anything unsafe or broken, TemplateAbort when the template's own `<exception>` line fires."""
    check_template(template)
    try:
        output = _environment().from_string(template).render(plain(variables))
    except SecurityError as exc:
        raise TemplateRejected(f"not allowed in a template: {exc}") from exc
    except TemplateError as exc:
        raise TemplateRejected(f"the template failed: {exc}") from exc
    if len(output) > MAX_OUTPUT_CHARS:
        raise TemplateRejected(f"the rendered output is limited to {MAX_OUTPUT_CHARS} characters")
    commands = [line.rstrip() for line in output.splitlines() if line.strip()]
    for line in commands:
        match = _EXCEPTION_LINE.search(line)
        if match:
            raise TemplateAbort(match.group(1))
        if _CONTROL.search(line):
            raise TemplateRejected("the rendered output contains a control character")
    if len(commands) > MAX_COMMANDS:
        raise TemplateRejected(f"a template may produce at most {MAX_COMMANDS} commands")
    return commands
