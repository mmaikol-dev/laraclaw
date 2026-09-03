---
name: [data] send-order-to-realdealsystem-curl
description: Send order data to realdealsystem Laravel API using curl command
category: data
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

## Skill: Send Order to RealDealSystem API (Curl-based)

### Purpose
Send order data to the realdealsystem Laravel API using curl command execution

### When to Use
- When you need to send orders to the Laravel API from shell commands
- For testing API endpoints directly
- For automation scripts that need to send order data
- When you want to use curl instead of Google Apps Script

### Input Requirements
- **orderData**: Object containing order information with these fields:
  - order_no (string, required)
  - order_date (string, required)
  - amount (number, required)
  - quantity (number, optional)
  - client_name (string, required)
  - address (string, optional)
  - phone (string, required)
  - country (string, optional, defaults to "Kenya")
  - store_name (string, required)
  - product_name (string, required)
  - merchant (string, required)
  - Optional: alt_no, city, status, delivery_date, agent, instructions, cc_email, order_type, sheet_id, sheet_name

### Execution Steps
1. Construct the curl command with order data as JSON payload
2. Execute the curl command using shell with timeout
3. Parse the response and check for success
4. Log the result for debugging
5. Return boolean success status

### Curl Command Structure
```bash
curl -s -X POST https://realdealsystem.com/api/sheet-orders \
  -H "Content-Type: application/json" \
  -d '{
    "order_no": "ORDER_NUMBER",
    "order_date": "2026-04-21",
    "amount": 4500,
    "quantity": 2,
    "client_name": "CLIENT_NAME",
    "address": "ADDRESS",
    "phone": "PHONE_NUMBER",
    "country": "Kenya",
    "store_name": "RDL2",
    "product_name": "PRODUCT_NAME",
    "merchant": "TROVELA"
  }'
```

### Expected Output
- **success** (boolean): true if order was successfully sent, false otherwise
- **responseCode** (integer): HTTP response code from API
- **responseBody** (object): Parsed JSON response from API

### Error Handling
- If curl command fails, return false with error message
- If response code is not 200, return false
- If response JSON doesn't contain success: true, return false
- Log detailed error messages for debugging

### Test Results
✅ Successfully tested with order TROVB370:
- Order Number: TROVB370
- Client: Monicah Mwangi
- Amount: 4500
- Product: Breast cream
- Status: Already existed, was updated
- API Response: Success (HTTP 200)

### Important Notes
- This skill uses shell curl command execution
- The API endpoint is external and requires network access
- Ensure orderData object is properly formatted before calling
- The API automatically updates existing orders
- Monitor API rate limits when sending multiple orders
- This skill is designed to be used by LaraClaw agent for order processing automation

### Example Usage
```bash
# Send a new order
curl -s -X POST https://realdealsystem.com/api/sheet-orders \
  -H "Content-Type: application/json" \
  -d '{"order_no":"TROVB371","order_date":"2026-05-13","amount":5000,"quantity":1,"client_name":"John Doe","address":"Nairobi","phone":"712345678","country":"Kenya","store_name":"RDL2","product_name":"Maxman","merchant":"TROVELA"}'
```
