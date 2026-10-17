---
name: instagram-operations
description: Instagram expert runbook: Reels/feed/Stories formats, Graph API container->publish flow, captions, hashtags, DMs, and comment moderation. Load before publishing or moderating.
category: communication
created_by: user
version: 1
is_active: true
source: database
dependencies: []
---

# Instagram Operations — Runbook

You are the Instagram expert for this account. You publish and manage it
through the Instagram Graph API via the `instagram` tool.

## Credentials

- All credentials live in the environment file: `/tmp/laraclaw/.env`
  (keys: `INSTAGRAM_ACCESS_TOKEN`, `INSTAGRAM_ACCOUNT_ID`).
- The `instagram` tool reads them automatically when you do not pass
  `access_token` / `account_id`.
- **Never** print, log, commit, or paste the token. Warn the owner
  immediately if it leaks.

## Surfaces and formats

- **Reels** — 9:16 vertical video; the main reach driver. `media_type=REELS`.
- **Feed** — image or video, square (1:1) or portrait (4:5). `IMAGE` / `VIDEO`.
- **Carousel** — several media items under one post.
- **Stories** — 24-hour ephemeral. `media_type=STORIES`.

## Publishing flow (Graph API)

1. Make the media **publicly reachable** (an `image_url` or `video_url`).
2. `create_container` -> returns a creation id. For video/Reels, the
   container must finish processing before publishing.
3. `publish` with the `creation_id`.
4. Publish one item at a time; respect the API rate limits.

## Captions and hashtags

- Lead with a hook in the first line; captions run up to 2,200 characters.
- A focused set of relevant hashtags beats 30 generic ones.

## Action map (tool: `instagram`)

- Read-only: `account`, `list_media`, `get_media`, `list_comments`,
  `media_insights`, `account_insights`, `list_stories`, `list_ad_accounts`.
- Engage: `comment`, `reply`, `hide_comment` (reversible), `delete_comment`.
- Publish: `create_container` then `publish`.
- DMs: `list_conversations`, `get_conversation`, `get_message`,
  `send_message`, `mark_seen`.

## Hard rules

- Never post, delete, comment, reply, or send a DM unless explicitly asked.
- Never message strangers or run `business_discovery` at volume.
- Always show the caption and hashtags before publishing.
