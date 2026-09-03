---
name: send-telegram-message
description: Send a message via Telegram Bot API to user Mo L (chat ID 8144561484)
category: communication
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

To send a Telegram message to the user:

1. Replace `YOUR_BOT_TOKEN` with `[]`  
2. Replace `YOUR_CHAT_ID` with `8144561484`  
3. Replace `YOUR_MESSAGE_TEXT` with the actual message content (URL-encoded if needed)  
4. Use the Telegram Bot API endpoint:  
   `https://api.telegram.org/botYOUR_BOT_TOKEN/sendMessage?chat_id=YOUR_CHAT_ID&text=YOUR_MESSAGE_TEXT`

You can use the `web` tool with `action: fetch` or `action: extract` to call this endpoint.

Example message:  
`https://api.telegram.org/bot[]/sendMessage?chat_id=8144561484&text=Hello%2C%20this%20is%20a%20test%20message!`

Important:  
- Always use URL encoding for special characters (e.g., space → `%20`, comma → `,` is safe, but avoid unencoded `&`, `=`, or `?`).  
- Confirm success by checking the JSON response: `{"ok":true,"result":{...}}`.  
- Never share the token publicly in logs or outputs.
