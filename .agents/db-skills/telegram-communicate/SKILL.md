---
name: telegram_communicate
description: General Telegram communication skill for sending messages, documents, photos, videos, audio, and notifications via Bot API
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

This skill provides a unified interface for various Telegram Bot API actions.

Parameters:
- `action_type` (string, required): one of `message`, `document`, `photo`, `video`, `audio`, `notification`.
- `chat_id` (string or integer, required): target chat ID or @username.
- `bot_token` (string, required): Bot token (e.g., `123456:ABC...`).
- `text` (string, optional): Message text for `message` or caption for media.
- `file_path` (string, optional): Absolute path to the file for media actions (`document`, `photo`, `video`, `audio`).
- `parse_mode` (string, optional): `MarkdownV2`, `HTML`, etc., for text formatting.
- `disable_notification` (bool, optional, default false): Send silently.

Implementation Steps:
1. Validate required parameters based on `action_type`.
2. Map `action_type` to Bot API method:
   - `message` → `sendMessage`
   - `document` → `sendDocument`
   - `photo` → `sendPhoto`
   - `video` → `sendVideo`
   - `audio` → `sendAudio`
   - `notification` → `sendMessage` with empty text and `disable_notification` true.
3. Build endpoint URL: `https://api.telegram.org/bot{bot_token}/{method}`.
4. Prepare POST fields:
   - Always include `chat_id`.
   - For `message`: include `text` (required) plus optional `parse_mode` and `disable_notification`.
   - For media types: include the file field (`document`, `photo`, `video`, `audio`) set to `@'file_path'`. Include optional `caption` (use `text`), `parse_mode`, `disable_notification`.
5. Use the `web` tool with action `fetch` (POST multipart/form-data) to send the request.
6. Parse JSON response. If `ok` is true, return key response data (`message_id`, `file_id` etc.). If false, raise an error with the `description`.

Example usage:
```
result = skill.run(name="telegram_communicate", params={
    "action_type": "document",
    "chat_id": 8144561484,
    "bot_token": "8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg",
    "file_path": "/home/atlas/Downloads/ZIPPORAH KATANU.pdf",
    "text": "Here is the CV you requested."
})
print(result)
```
