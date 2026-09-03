# 06 — ToolRegistry & BaseTool

## What to do
Create `app/Services/Tools/BaseTool.php` (abstract) and `app/Services/Agent/ToolRegistry.php`.

---

## BaseTool (abstract)
File: `app/Services/Tools/BaseTool.php`

All four tools extend this class. Define these abstract methods that each tool must implement:

- `getName(): string` — unique snake_case identifier (e.g. `file`, `shell`, `web`, `document`)
- `getDescription(): string` — one paragraph description sent to the model explaining what the tool does
- `getParameters(): array` — JSON Schema object with `type`, `properties`, and `required` — this is what Ollama uses to know what arguments to pass
- `execute(array $arguments): string` — runs the tool and returns a plain string result

Also define these non-abstract methods:

- `isEnabled(): bool` — returns `true` by default; tools can override this to check `AgentSetting`
- `truncate(string $output, int $maxLines = 500): string` — splits output on newlines, keeps the first `$maxLines`, appends a truncation notice if cut. Prevents huge outputs from overflowing the context window.

---

## ToolRegistry
File: `app/Services/Agent/ToolRegistry.php`

Holds all registered tool instances. Used by `AgentService` and the settings API.

### Method: `register(BaseTool $tool): void`
Store the tool in a private array keyed by `$tool->getName()`.

### Method: `get(string $name): ?BaseTool`
Return the tool by name, or null if not found.

### Method: `all(): array`
Return the full array of registered tools.

### Method: `toOllamaTools(): array`
Return the tools formatted for Ollama's tool-calling API:
```
[
  {
    "type": "function",
    "function": {
      "name": tool name,
      "description": tool description,
      "parameters": tool parameters schema
    }
  },
  ...
]
```
Only include tools where `isEnabled()` returns true.

### Method: `execute(string $toolName, array $arguments): array`
- Look up the tool by name — return an error array if not found or disabled
- Wrap `$tool->execute()` in a try/catch
- Time the execution with `microtime(true)`
- Return: `['output' => string, 'error' => string|null, 'duration_ms' => int]`
