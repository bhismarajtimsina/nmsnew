# AI Assistant Context

## Purpose

We discussed adding an AI-assisted feature to Support API and 1 for generating and helping users fill:

- generic command macros from `components/Macros`
- ONT registration templates from `components/OntsRegistration`
- event rules / Alertmanager rules from `components/Events`

Vendor-specific legacy registration components are excluded from the target scope:

- `components/ZteOntsRegistration`
- `components/HuaweiOntsRegistration`

## Current Project Context

Relevant backend components already exist:

- `components/Macros`
  - generic macros component
  - supports `display_for`: `DEVICE`, `PORT`, `PON`, `ONU`
  - stores `name`, `description`, `display_for`, `display_output`, `models`, `user_roles`, `parameters`, `template`
  - renders Twig templates in `MacrosGateway::buildTemplate()`
  - validates macro parameters in `MacrosGateway::validateParameters()`
  - executes rendered commands through `multi_console_command`

- `components/OntsRegistration`
  - general ONT registration component
  - stores registration macros/templates separately from generic `Macros`
  - renders Twig templates in `MacrosGateway::buildTemplate()`
  - generates variables including `params`, `user`, `device`, `interfaces_list`, `ont`, `iface`, `data`, `error`, `free`, `external`

- `components/Events`
  - manages events and Alertmanager rules
  - stores rules in `c_events_alertmanager_rules`
  - validates rules through `Alertmanager::validateRule()`
  - validates PromQL expressions through `Alertmanager::validateExpr()`
  - applies rules to Prometheus/Alertmanager config

The frontend is a Vue3 SPA.

## Important Architecture Decision

The AI assistant should be frontend-first, but not frontend-only.

The Vue3 SPA should own the chat UI, form filling, route navigation, and user-facing interaction. The backend should provide a secure AI gateway that:

- stores provider API keys
- builds prompts from allowlisted instructions/context
- calls the LLM provider
- validates the model output
- returns only structured, allowlisted actions

The model must not directly click DOM elements, execute arbitrary HTTP requests, run `curl`, or save/execute changes without explicit user confirmation.

## Production Direction

This is not an MVP-only feature. It should be designed as a production component from the start.

Suggested backend component:

```text
components/AiAssistant
```

Suggested structure:

```text
components/AiAssistant/
  config.php
  rules.yml
  Api/
    ChatAction.php
    CapabilitiesAction.php
    ValidateAction.php
    FeedbackAction.php
  Controllers/
    AssistantController.php
    PromptBuilder.php
    ContextBuilder.php
    ActionValidator.php
  Clients/
    LlmClientInterface.php
    OpenAiCompatibleClient.php
    FakeClient.php
  Actions/
    AssistantActionRegistry.php
    FillMacrosFormAction.php
    FillOntRegistrationFormAction.php
    FillEventRuleFormAction.php
  Storage/
    AssistantRequestStorage.php
    AssistantConversationStorage.php
    AssistantMessageStorage.php
    AssistantFeedbackStorage.php
  instructions/
    macros.md
    onts_registration.md
    events.md
  schemas/
    assistant_response.v1.json
    macros_draft.v1.json
    onts_registration_draft.v1.json
    event_rule_draft.v1.json
```

## Backend API Shape

Suggested endpoints:

```text
GET  /component/ai-assistant/capabilities
POST /component/ai-assistant/chat
POST /component/ai-assistant/validate
POST /component/ai-assistant/feedback
```

`chat` should accept current screen context and a user message, then return structured assistant actions.

Example frontend request:

```json
{
  "screen": "macros",
  "message": "Create a macro for checking ONU status",
  "route": "/macros/control",
  "state": {
    "display_for": ["ONU"],
    "current_form": {}
  }
}
```

Example backend response:

```json
{
  "message": "Draft macro is ready.",
  "actions": [
    {
      "type": "fill_macros_form",
      "payload": {
        "name": "Check ONU status",
        "description": "Shows ONU status and related diagnostic data.",
        "display_for": ["ONU"],
        "display_output": "last",
        "parameters": [],
        "template": "show ..."
      }
    }
  ]
}
```

## Allowed Assistant Actions

Initial safe action allowlist:

```text
navigate
show_message
fill_macros_form
fill_onts_registration_form
fill_event_rule_form
preview_macros
preview_onts_registration
validate_event_rule
```

Actions that must not be automatic:

```text
save_macros
execute_macros
update_alertmanager_rules
delete_*
arbitrary_fetch
arbitrary_curl
arbitrary_dom_click
```

Saving, updating, deleting, or executing must require explicit user confirmation in the SPA.

## LLM Provider Strategy

Use provider abstraction rather than binding the project to one model.

Recommended first implementation:

```text
OpenAI-compatible Chat Completions API
```

This allows using providers such as:

- OpenRouter
- OpenAI
- Groq
- Together AI
- Mistral-compatible gateways
- Ollama
- LM Studio
- LocalAI
- llama.cpp server

Suggested interface:

```php
interface LlmClientInterface
{
    public function generateJson(array $messages, array $schema, array $options = []): array;
}
```

Suggested env configuration:

```env
AI_ASSISTANT_ENABLED=yes
AI_ASSISTANT_PROVIDER=openai_compatible
AI_ASSISTANT_BASE_URL=https://openrouter.ai/api/v1
AI_ASSISTANT_API_KEY=
AI_ASSISTANT_MODEL=
AI_ASSISTANT_TIMEOUT=30
AI_ASSISTANT_MAX_TOKENS=1500
AI_ASSISTANT_TEMPERATURE=0.1
AI_ASSISTANT_DAILY_USER_LIMIT=100
AI_ASSISTANT_STORE_PROMPTS=no
```

## Compatibility Standards

There is no single universal LLM API standard, but these are useful compatibility layers:

- OpenAI-compatible `/v1/chat/completions` API as the main provider contract
- JSON Schema for structured assistant responses and draft payloads
- OpenAPI for describing allowed backend APIs if tools are added later
- MCP only if the assistant later needs a full tool/resource/prompt ecosystem

The project should track model/provider capabilities explicitly:

```yaml
supports_json_schema: true
supports_tools: false
supports_streaming: false
```

## Security Rules

The assistant must not receive secrets or sensitive operational data.

Do not send:

- `.env`
- API keys
- auth tokens
- SNMP community strings
- device access credentials
- full private configs
- arbitrary local files

Allowed context should be explicit and allowlisted:

- current SPA screen
- sanitized form state
- available Twig variables
- safe examples from project instructions
- JSON schemas for expected output
- non-secret device metadata needed for template generation

## Production Requirements

Required backend features for production:

- RBAC permissions, for example `ai_assistant_use` and `ai_assistant_admin`
- per-user rate limits
- daily token/cost budgets
- provider/model registry
- schema-versioned responses
- action allowlist validation
- prompt/context redaction
- request audit log
- feedback tracking: accepted, rejected, edited
- provider latency/error metrics
- circuit breaker when the provider fails or returns invalid output
- no effect on core API when AI provider is unavailable

Suggested tables:

```text
c_ai_assistant_requests
c_ai_assistant_conversations
c_ai_assistant_messages
c_ai_assistant_feedback
```

Full prompts should not be stored by default. Store safe metadata and redacted payloads unless debug storage is explicitly enabled.

## Frontend Direction

The Vue3 SPA should have an assistant panel/chat.

Suggested frontend modules:

```text
src/features/ai-assistant/
  AiAssistantPanel.vue
  useAiAssistant.ts
  assistantApi.ts
  assistantActions.ts
  contextBuilders/
    macrosContext.ts
    ontsRegistrationContext.ts
    eventsContext.ts
```

The frontend should execute only known high-level actions returned by the backend.

The model should not return DOM selectors or raw click instructions. It should return semantic actions such as:

```json
{
  "type": "fill_macros_form",
  "payload": {
    "name": "Check ONU status",
    "display_for": ["ONU"],
    "template": "show ..."
  }
}
```

## Ready-Made Frontend Options Discussed

Potential frontend libraries:

- AI Elements Vue
  - useful if the SPA can use Tailwind/shadcn-vue style components
  - provides chat/conversation/prompt UI pieces

- `@ai-sdk/vue`
  - useful as a chat state/streaming transport layer
  - not a UI kit
  - more useful if streaming is required

- Botpress Webchat
  - ready embed widget
  - less suitable for controlled Support form actions

React-first solutions like CopilotKit and assistant-ui were considered less suitable for a Vue3 SPA unless using a separate React microfrontend.

## Initial Implementation Slice

Even for production architecture, implement domains incrementally.

Recommended order:

1. Generic `Macros`
   - first real domain
   - return `fill_macros_form`
   - validate draft shape
   - no automatic save or execute

2. `OntsRegistration`
   - return `fill_onts_registration_form`
   - use ONT registration-specific variables and constraints

3. `Events`
   - return `fill_event_rule_form`
   - validate with existing Alertmanager rule validation

This keeps the assistant architecture production-grade while limiting domain risk during rollout.
