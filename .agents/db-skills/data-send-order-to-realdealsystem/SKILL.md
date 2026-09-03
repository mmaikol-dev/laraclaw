---
name: [data] send-order-to-realdealsystem
description: Send order data to realdealsystem Laravel API endpoint
category: data
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

## Skill: Send Order to RealDealSystem API

### Purpose
Send order data to the realdealsystem Laravel API endpoint at https://realdealsystem.com/api/sheet-orders

### When to Use
- When order data needs to be transmitted from Google Sheets or other systems to the realdealsystem API
- When automating order processing workflows
- When integrating Google Apps Script with Laravel backend systems

### Input Requirements
- **orderData**: Object containing order information with these fields:
  - order_no (string, required)
  - order_date (string, required)
  - amount (number, required)
  - quantity (number, optional)
  - client_name (string, optional)
  - address (string, optional)
  - phone (string, optional)
  - alt_no (string, optional)
  - country (string, optional, defaults to "Kenya")
  - city (string, optional)
  - store_name (string, required)
  - product_name (string, required)
  - status (string, optional)
  - delivery_date (string, optional)
  - agent (string, optional)
  - instructions (string, optional)
  - cc_email (string, optional)
  - order_type (string, optional)
  - sheet_id (string, optional)
  - sheet_name (string, optional)
  - merchant (string, required)

### Execution Steps
1. Construct the API endpoint URL: https://realdealsystem.com/api/sheet-orders
2. Prepare HTTP options:
   - method: "post"
   - contentType: "application/json"
   - payload: JSON.stringify(orderData)
3. Use UrlFetchApp.fetch() to send the request
4. Parse the response and check for success (responseCode === 200 && responseBody.success === true)
5. Log the result for debugging
6. Return boolean success status

### Expected Output
- **success** (boolean): true if order was successfully sent, false otherwise
- Side effect: Order data is transmitted to external Laravel API

### Error Handling
- If API call fails, log the error and return false
- If response code is not 200, return false
- If response JSON doesn't contain success: true, return false

### Important Notes
- This function uses Google Apps Script's UrlFetchApp service
- The API endpoint is external and requires network access
- Ensure orderData object is properly formatted before calling
- Consider implementing retry logic for production use
- Monitor API rate limits when sending multiple orders
