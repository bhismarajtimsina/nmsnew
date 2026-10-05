"""Macro and ONU-registration templates (Plan 38): sandboxed rendering against a corpus of hostile templates, and
parameter validation that keeps a filled-in value from adding a console command. Pure; nothing reaches a device."""
import pytest

from app.actions.templates import (
    DEFAULT_TEXT_PATTERN,
    MAX_COMMANDS,
    MAX_TEMPLATE_CHARS,
    Parameter,
    TemplateAbort,
    TemplateRejected,
    check_template,
    parse_parameters,
    plain,
    render,
    validate_parameters,
)

VARS = {
    "params": {"onu": "5", "vlan": "100"},
    "iface": {"frame": 0, "slot": 1, "port": 7, "name": "GPON 0/1/7"},
    "device": {"name": "olt-1", "ip": "10.0.0.1", "community": "public-secret", "password_enc": "x"},
    "free_onts": {"GPON 0/1/7": {"first": 3, "all": [3, 4, 9]}},
    "global": {"vlan_internet": 100},
}


# --- what a real macro does ---

def test_a_real_delete_onu_macro_renders_line_by_line_with_blank_lines_dropped():
    template = (
        "interface gpon {{ iface.frame }}/{{ iface.slot }}\n\n"
        "{% if params.onu %}ont delete {{ iface.port }} {{ params.onu }}{% endif %}\n"
        "   \n"
        "quit"
    )
    assert render(template, VARS) == ["interface gpon 0/1", "ont delete 7 5", "quit"]


def test_loops_filters_and_lookups_a_registration_template_needs_still_work():
    template = "{% for n in free_onts['GPON 0/1/7']['all'] %}ont add {{ n }} vlan {{ global.vlan_internet }}\n{% endfor %}{{ iface.name|lower|replace(' ', '-') }}"
    assert render(template, VARS) == ["ont add 3 vlan 100", "ont add 4 vlan 100", "ont add 9 vlan 100", "gpon-0/1/7"]


def test_a_templates_exception_line_stops_it_with_its_own_message():
    template = "{% if not free_onts['GPON 0/1/9'] is defined %}<exception 'no free ONT on this port'>{% endif %}\nont add 1"
    with pytest.raises(TemplateAbort, match="no free ONT on this port"):
        render(template, VARS)


def test_an_undefined_variable_is_an_error_not_an_empty_string():
    with pytest.raises(TemplateRejected, match="failed"):
        render("ont delete {{ params.missing }}", VARS)


# --- hostile templates: none may reach Python internals, files, or unbounded work ---

HOSTILE = [
    "{{ ''.__class__.__mro__[1].__subclasses__() }}",
    "{{ ''.__class__ }}",
    "{{ params.__class__.__init__.__globals__ }}",
    "{{ self.__init__.__globals__ }}",
    "{{ self._TemplateReference__context }}",
    "{{ range.__globals__ }}",
    "{{ cycler.__init__.__globals__.os.popen('id').read() }}",
    "{{ lipsum.__globals__['os'].popen('id').read() }}",
    "{{ joiner }}",
    "{{ namespace() }}",
    "{{ config }}",
    "{{ request }}",
    "{% include '/etc/passwd' %}",
    "{% import 'os' as os %}{{ os }}",
    "{% extends 'base.html' %}",
    "{% from 'x' import y %}{{ y }}",
    "{{ params.update({'onu': 1}) }}",
    "{{ [1].append(2) }}",
    "{{ iface.__setitem__('port', 9) }}",
    "{{ ''.format.__globals__ }}",
    "{{ '{0.__class__}'.format(params) }}",
    "{{ range(10000000)|list|length }}",
    "{{ 'a' * 100000000 }}",
    "{{ [0] * 100000000 }}",
    "{{ 100000000 * 'a' }}",
    "{{ 9 ** 999999 }}",
    "{% for i in range(5000) %}x{% endfor %}",
    "{{ device.community }}",          # secrets never reach a template
    "{{ device.password_enc }}",
]


@pytest.mark.parametrize("template", HOSTILE)
def test_hostile_templates_are_refused(template):
    with pytest.raises(TemplateRejected):
        render(template, VARS)


def test_output_size_and_command_count_are_capped():
    with pytest.raises(TemplateRejected, match="at most"):
        render("{% for i in range(600) %}cmd {{ i }}\n{% endfor %}", VARS)
    assert len(render(f"{{% for i in range({MAX_COMMANDS}) %}}cmd {{{{ i }}}}\n{{% endfor %}}", VARS)) == MAX_COMMANDS
    with pytest.raises(TemplateRejected, match="limited"):
        render("{% for i in range(4000) %}" + "x" * 30 + "{% endfor %}", VARS)


def test_template_size_and_syntax_are_checked_when_saved():
    with pytest.raises(TemplateRejected, match="limited"):
        check_template("x" * (MAX_TEMPLATE_CHARS + 1))
    with pytest.raises(TemplateRejected, match="parse"):
        check_template("{% for x in %}")
    check_template("interface {{ iface.name }}")


def test_variables_are_reduced_to_plain_data_without_secrets():
    class Obj:
        def __repr__(self):
            return "obj"

    cleaned = plain({"device": {"name": "a", "auth_key": "k", "nested": [{"token": "t", "v": Obj()}]}, "fn": len})
    assert cleaned == {"device": {"name": "a", "nested": [{"v": "obj"}]}, "fn": str(len)}


def test_a_rendered_control_character_is_refused():
    with pytest.raises(TemplateRejected, match="control"):
        render("cmd {{ x }}", {"x": "a\x07b"})


# --- parameters ---

DECLARED = parse_parameters([
    {"key": "onu", "type": "input_string", "pattern": "[0-9]{1,3}"},
    {"key": "speed", "type": "select_predefined", "variants": ["100M", "1G"]},
    {"key": "port", "type": "select_from_variable", "source": "free_onts.first_port.all"},
])


def test_valid_parameters_pass():
    params = [Parameter("onu", "input_string", pattern="[0-9]{1,3}"), Parameter("speed", "select_predefined", variants=("100M", "1G")),
              Parameter("port", "select_from_variable", source="lists.free")]
    assert validate_parameters(params, {"onu": "12", "speed": "1G", "port": "4"}, {"lists": {"free": [3, 4]}}) == {"onu": "12", "speed": "1G", "port": "4"}


@pytest.mark.parametrize("value", [
    "10 ; reboot",          # passes legacy's unanchored "[0-9]+"
    "10\nreboot",           # a newline would be a second console command
    "10\rreboot", "10\x00", "",
    "1234",                 # too long for {1,3}
])
def test_a_value_that_could_add_a_command_is_refused(value):
    params = [Parameter("onu", "input_string", pattern="[0-9]{1,3}")]
    with pytest.raises(TemplateRejected):
        validate_parameters(params, {"onu": value}, {})


def test_a_text_parameter_without_a_pattern_gets_a_conservative_default_not_anything():
    params = [Parameter("desc", "input_string")]
    assert validate_parameters(params, {"desc": "Customer 42 / flat 3"}, {})["desc"] == "Customer 42 / flat 3"
    for bad in ("a;b", "a|b", "$(id)", "a`b`", "x" * 65):
        with pytest.raises(TemplateRejected):
            validate_parameters(params, {"desc": bad}, {})
    assert DEFAULT_TEXT_PATTERN


def test_selections_must_come_from_their_lists_and_parameters_must_match_the_declaration():
    params = [Parameter("speed", "select_predefined", variants=("100M", "1G")), Parameter("port", "select_from_variable", source="lists.free")]
    with pytest.raises(TemplateRejected):
        validate_parameters(params, {"speed": "10G", "port": "3"}, {"lists": {"free": [3]}})
    with pytest.raises(TemplateRejected):
        validate_parameters(params, {"speed": "1G", "port": "5"}, {"lists": {"free": [3]}})
    with pytest.raises(TemplateRejected):
        validate_parameters(params, {"speed": "1G", "port": "3"}, {"lists": {}})          # source missing
    with pytest.raises(TemplateRejected):
        validate_parameters(params, {"speed": "1G"}, {"lists": {"free": [3]}})             # one missing
    with pytest.raises(TemplateRejected):
        validate_parameters(params, {"speed": "1G", "port": "3", "extra": "x"}, {"lists": {"free": [3]}})
    with pytest.raises(TemplateRejected):
        validate_parameters([Parameter("speed", "select_predefined", variants=("1",))], {"speed": True}, {})


@pytest.mark.parametrize("declaration", [
    [{"key": "Bad-Key", "type": "input_string"}],
    [{"key": "a", "type": "input_string"}, {"key": "a", "type": "input_string"}],
    [{"key": "a", "type": "free_text"}],
    [{"key": "a", "type": "input_string", "pattern": "([unclosed"}],
    [{"key": "a", "type": "select_predefined", "variants": []}],
    [{"key": "a", "type": "select_predefined", "variants": ["ok", "bad\nvalue"]}],
    [{"key": "a", "type": "select_from_variable", "source": "../etc"}],
])
def test_bad_declarations_are_refused_when_saved(declaration):
    with pytest.raises(TemplateRejected):
        parse_parameters(declaration)


def test_good_declarations_parse():
    assert [p.type for p in DECLARED] == ["input_string", "select_predefined", "select_from_variable"]


def test_a_newline_from_device_data_or_a_permissive_pattern_still_cannot_add_a_command():
    from_device = [Parameter("port", "select_from_variable", source="lists.free")]
    with pytest.raises(TemplateRejected, match="control"):
        validate_parameters(from_device, {"port": "3\nreboot"}, {"lists": {"free": ["3\nreboot", "4"]}})
    permissive = [Parameter("desc", "input_string", pattern=r"[\s\S]{1,40}")]
    with pytest.raises(TemplateRejected, match="control"):
        validate_parameters(permissive, {"desc": "ok\nreboot"}, {})


@pytest.mark.parametrize("template", ["{{ 'a' * 100000000 }}", "{{ [0] * 100000000 }}", "{{ 100000000 * 'a' }}"])
def test_repetition_is_refused_before_anything_large_is_built(template):
    with pytest.raises(TemplateRejected, match="repetition"):
        render(template, VARS)
