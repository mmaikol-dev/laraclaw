---
name: [communication] send-order-notification-to-telegram
description: Send order notification to Telegram user Mo L
category: communication
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

## Skill: Send Order Notification to Telegram

### Purpose
Send order confirmation or notification messages to Telegram user Mo L via the Telegram Bot API

### When to Use
- When you need to notify users about order status changes
- When order confirmations need to be sent via Telegram
- As part of order processing workflows

### Input Requirements
- **orderData**: Object containing order information
- **message**: Optional custom message string

### Execution Steps
1. Use the stored Telegram credentials from memory
2. Format the order data into a readable message
3. Send the message via Telegram Bot API to chat_id: 8144561484
4. Log success or failure

### Expected Output
- **success** (boolean): true if message was sent successfully, false otherwise

### Important Notes
- Uses Telegram Bot API with credentials stored in memory
- Recipient: Mo L (chat_id: 8144561484)
- Bot: @laraclawzz_bot
- This skill depends on stable internet connectivity
- Consider adding rate limiting if sending multiple messages
