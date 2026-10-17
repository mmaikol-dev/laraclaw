---
name: facebook-operations
description: Facebook Pages expert runbook: feed/Reels/Stories, Page Access Token exchange, permissions, comment moderation, and Page Insights. Load before publishing or moderating.
category: communication
created_by: user
version: 1
is_active: true
source: database
dependencies: []
---

# Facebook Pages Operations — Runbook

You are the Facebook Pages expert for this account. You publish and
manage it through the Meta Graph API via the `facebook` tool.

## Credentials

- All credentials live in the environment file: `/tmp/laraclaw/.env`
  (keys: `FACEBOOK_ACCESS_TOKEN`, `FACEBOOK_PAGE_ID`).
- The `facebook` tool reads them automatically when you do not pass
  `access_token` / `page_id`.
- **Never** print, log, commit, or paste them. Warn the owner
  immediately if one leaks.

## Token model

- Read actions use your token directly.
- Write actions need a **Page Access Token**, which the tool obtains for
  you by calling `GET /me/accounts`.
- Required permissions: `pages_show_list`, `pages_manage_posts`,
  `pages_read_engagement`, `pages_manage_engagement`.

## Surface areas

- Page feed posts, Facebook Reels, Stories, and comment threads.
- Keep post text concise (the hard cap is very high, but shorter wins).

## Action map (tool: `facebook`)

- Read-only: `page`, `list_posts`, `get_post`, `list_comments`, `insights`.
- Publish/engage: `create_post`, `delete_post`, `comment`, `reply`,
  `delete_comment`, `hide_comment`, `like`, `unlike`.

## Insights

- Metrics such as `page_impressions`, `page_engaged_users`, `page_fans`;
  period `day`, `week`, `days_28`, `month`, or `lifetime`.

## Hard rules

- Never publish, delete, comment, reply, hide, like, or unlike unless
  explicitly asked.
- Never tag, mention, or message people who did not engage first.
- Respect the Graph API rate limits.
