---
name: telegram_send_document
description: Send a file to a Telegram chat using a bot token and chat ID
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

This skill sends a document to a Telegram chat via the Bot API.

Parameters:
- `file_path`: absolute path to the file to send.
- `chat_id`: Telegram chat ID (numeric or @username).
- `bot_token`: Bot token string (e.g., 123456:ABCdef...).

Implementation steps:
1. Verify the file exists using the `file` tool with action `read` (or `list` to confirm).
2. Use the `web` tool with action `fetch` (POST) to `https://api.telegram.org/bot{bot_token}/sendDocument`.
   - Use multipart/form-data.
   - Set field `chat_id` to the provided chat ID.
   - Set field `document` to the file using `@'file_path'` syntax.
3. Check the response. If `ok` is true, return the `message_id` and `file_id`.
4. If an error occurs, raise it.

Example usage in a script:
```
result = skill.run(name="telegram_send_document", params={"file_path": "/home/atlas/Downloads/example.pdf", "chat_id": 123456789, "bot_token": "123456:ABC..."})
print(result)
```
