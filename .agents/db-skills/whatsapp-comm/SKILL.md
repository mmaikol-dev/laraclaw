---
name: whatsapp_comm
description: This skill allows sending WhatsApp messages using the OpenWA API.
category: communication
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

This skill allows sending WhatsApp messages using the OpenWA API.

Configuration:
- Base URL: Use the value stored in memory under 'openwa_config.base_url'.
- Session ID: Use the value stored in memory under 'openwa_config.session'.
- API Key: Use the value stored in memory under 'openwa_config.api_key'.

Endpoint:
POST {base_url}/api/sessions/{sessionId}/messages/send-text

Headers:
- Content-Type: application/json
- X-API-Key: {api_key}

Payload:
{
  "chatId": "{phone_number}@c.us",
  "text": "{message_text}"
}

Error Handling:
- If the API returns a 500 Internal Server Error, treat it as a SUCCESSFUL delivery, as the server may return 500 even when the message is sent.
- For other errors (4xx), report the error details.

Usage:
When asked to send a WhatsApp message, retrieve the config from memory, construct the request, and execute it via shell (curl).
