# wp-audit-agent

A small tool-using AI agent written in PHP. Give it the URL of a WordPress site and it decides which checks to run, runs them, and writes a short audit report.

It uses the Anthropic Messages API with tool use. The model chooses which tool to call next, and the PHP code runs the tool and sends the result back, in a loop, until the model has enough information to write its report.

## What it checks

| Tool | What it does |
|------|--------------|
| `fetch_page` | Requests a URL and returns the HTTP status code and response time |
| `check_wp_version` | Reads the WordPress version from the generator meta tag or `readme.html` (returns `null` if hidden) |
| `get_latest_wp_version` | Gets the latest stable WordPress version from the WordPress.org API |
| `check_rest_api` | Requests `/wp-json` and reports the status code and whether the response is valid JSON |

Each tool does one small job and reports facts. The model does the reasoning, such as comparing the site's version with the latest one.

## How the agent loop works

1. The script sends the task, the tool descriptions and the message history to the API.
2. If the model asks for a tool, the script runs the matching PHP function and logs the call.
3. The tool result is added to the history and sent back to the model.
4. This repeats until the model replies without asking for a tool. That reply is the final report.

## Guardrails

- All tools are read-only. Nothing is changed on the target site.
- URLs are validated, and only `http` and `https` are allowed.
- The loop stops after 8 steps, so a confused model cannot run forever.
- Every tool call is written to `agent.log` (excluded from Git).
- The API key is read from an environment variable and is never stored in the code.

## Requirements

- PHP 8.0 or newer with the cURL extension enabled
- An Anthropic API key

## Usage

```bash
export ANTHROPIC_API_KEY="your-key"
php agent.php https://example.com
```

Only run it against sites you own or have permission to test.


## Ideas for next steps

- Add tests that check the agent's report against sites with known results
- Add checks for plugins and security headers
- Ask for approval before any tool that could change something
- Connect tools through MCP (Model Context Protocol)
