---
name: send-image-or-file-to-telegram
description: Send an image or any file (e.g., PDF, DOCX, ZIP, JPG, PNG, etc.) from local system to Telegram user Mo L using Telegram Bot API.
category: communication
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

To send an image or file to user Mo L (chat ID 8144561484) via Telegram Bot API:

1. **For images (JPG, PNG, etc.)**:
   - Use `curl` with `-F "photo=@<file_path>"` and the `sendPhoto` endpoint:
     ```
     curl -F "chat_id=8144561484" -F "photo=@<file_path>" https://api.telegram.org/bot8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg/sendPhoto
     ```
   - This automatically generates thumbnails and optimizes for viewing.

2. **For other files (PDF, DOCX, ZIP, etc.)**:
   - Use `curl` with `-F "document=@<file_path>"` and the `sendDocument` endpoint:
     ```
     curl -F "chat_id=8144561484" -F "document=@<file_path>" https://api.telegram.org/bot8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg/sendDocument
     ```

3. **Optional caption**:
   - Add `-F "caption=<your_message>"` to include a descriptive caption (URL-encode special characters).

4. **Verify success**:
   - Successful response: `{"ok":true,"result":{...}}`
   - Check `message_id`, `file_id`, and `file_unique_id` in the response for tracking.

✅ **Always use the full Telegram Bot API URL and correct credentials**:
- Bot token: `8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg`
- Chat ID: `8144561484`

⚠️ **Important**:
- Ensure the file path is absolute (e.g., `/home/atlas/Downloads/image.jpg`)
- File must exist and be readable.
- For large files (>10 MB), consider splitting or compressing first.
- No need to URL-encode file paths — `curl -F` handles it.

📌 **Example usage**:
- Image:  
  `curl -F "chat_id=8144561484" -F "photo=@/home/atlas/Downloads/Happy Labor Day.jpeg" https://api.telegram.org/bot8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg/sendPhoto`
- PDF:  
  `curl -F "chat_id=8144561484" -F "document=@/home/atlas/Downloads/INVOICE.pdf" https://api.telegram.org/bot8579905866:AAG-CunYA_7m3dXNqo4lydFFdByOBJro0Mg/sendDocument`
