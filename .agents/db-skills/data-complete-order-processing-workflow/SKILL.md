---
name: [data] complete-order-processing-workflow
description: Complete order processing workflow with API sending and notifications
category: data
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

## Skill: Complete Order Processing Workflow

### Purpose
Complete end-to-end order processing workflow including data preparation, sending to realdealsystem API, and Telegram notifications

### When to Use
- When processing new orders from Google Sheets
- When automating order confirmation workflows
- When you need both API integration and user notifications

### Input Requirements
- **orderData**: Object with complete order information
- **sheetName**: Name of the source sheet (for context in notifications)
- **notificationEnabled**: Boolean to control Telegram notifications (default: true)

### Execution Steps
1. Validate order data contains required fields (order_no, order_date, amount, merchant, store_name, product_name)
2. Send order data to realdealsystem API using [data] send-order-to-realdealsystem skill
3. If API call succeeds, send Telegram notification using [communication] send-order-notification-to-telegram skill
4. Log the complete workflow result
5. Return success status and any error messages

### Expected Output
- **success** (boolean): true if both API and notifications succeeded
- **apiSuccess** (boolean): result of API call
- **notificationSuccess** (boolean): result of Telegram notification
- **errorMessages**: Array of any errors encountered

### Integration Dependencies
- Requires [data] send-order-to-realdealsystem skill
- Requires [communication] send-order-notification-to-telegram skill
- Requires Telegram credentials in memory
- Requires valid order data structure

### Error Handling
- Handle missing required fields gracefully
- Continue processing even if one step fails
- Log detailed error messages for debugging
- Return partial success status when applicable

### Important Notes
- This is a high-level workflow skill that coordinates multiple sub-skills
- Consider implementing retry logic for failed operations
- Monitor API rate limits and Telegram bot rate limits
- Ensure proper error handling for production use
- This skill is designed to be used by LaraClaw agent for order processing automation
