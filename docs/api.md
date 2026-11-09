# JSON API, MCP server and webhooks

For scripts and other tools (a reporting sheet, Zapier-style automations the agency runs itself). JSON in, JSON out. Everything a token can do, a staff member can do in the app; the API never publishes a post, approval stays with a human.

## Tokens

Staff page → "API tokens" → name it, optionally limit it to one client → the token is shown once. Send it on every call:

```
Authorization: Bearer so_…
Accept: application/json
```

120 calls a minute per token; call 121 gets `429` with `Retry-After` and `{"message": "Too many requests: 120 calls a minute per token. Try again in N seconds."}`. A token limited to one client gets 403 on any other client's data. Revoke on the Staff page.

## Endpoints

| Method | Path | What |
|---|---|---|
| GET | `/api/clients` | The clients the token may see: id, slug, code, name, type, location |
| GET | `/api/clients/{id}/posts?from=&to=&state=` | Posts (up to 500), oldest first; `from`/`to` are dates on `scheduled_at`, `state` one of idea, draft, in_review, changes_requested, approved, scheduled, published, failed, manual_needed, withdrawn |
| POST | `/api/clients/{id}/posts` | A new Draft, same fields and rules as the post form (below) |
| GET | `/api/posts/{id}` | One post |
| GET | `/api/clients/{id}/comments?open=1` | The inbox: comments and direct messages; `open=0` for everything |
| POST | `/api/comments/{id}/reply` | `{"text": "…"}` sends the reply on the platform and marks the comment answered |

### Creating a Draft

```json
{
  "platforms": ["instagram", "facebook"],
  "format": "feed",
  "pillar": "food",
  "brief": "New summer menu, photo of the sea bass",
  "scheduled_at": "2027-05-12 18:00",
  "settings": {"first_comment": "#chania"},
  "variants": {
    "instagram": {"el": {"caption": "…", "alt_text": "…", "hashtags": ["#chania", "#crete"]}, "en": {"caption": "…"}},
    "facebook": {"el": {"caption": "…"}}
  },
  "assets": [12, 15]
}
```

`platforms` and `format` are required; `pillar` must be one of the profile's pillars; `assets` are ids of the client's own assets, in order. The Draft is named by the usual convention and goes through review and approval in the app like any other.

### A post as the API returns it

```json
{"id": 42, "name": "ALMYRA_20270512_IG-FB_FEED_FOOD_EL-EN_v1", "client_id": 1, "state": "draft", "platforms": ["instagram", "facebook"], "format": "feed", "pillar": "food", "brief": "…", "scheduled_at": "2027-05-12T18:00:00+03:00", "published_at": null, "external_ids": null, "publish_error": null, "metrics": null, "settings": {"first_comment": "#chania"}, "captions": {"instagram": {"el": {"caption": "…", "alt_text": "…", "hashtags": ["#chania", "#crete"]}}}, "assets": [{"id": 12, "name": "ALMYRA_20270510_ANY_IMG_FOOD_EL-EN_v1.jpg", "kind": "image"}], "url": "https://…/posts/42/edit"}
```

Errors come as JSON: 401 without a valid token, 403 for another client's data, 422 with `errors` per field when the input is wrong, 422 with `message` when a platform refuses a reply.

## Webhooks

In the client profile:

```yaml
webhooks:
  - url: https://example.com/socialops
    secret: shared-secret          # optional
    events: [post.published]       # optional, default all three
```

Events: `post.published`, `post.failed`, `post.manual_needed`. Each is a POST with a JSON body `{"event": "post.published", "at": "2027-05-12T18:00:04+03:00", "post": {…as above…}}` and two headers: `X-SocialOps-Event` and `X-SocialOps-Signature`, the hex HMAC-SHA256 of the raw body with the secret (empty secret, empty key). Check the signature before trusting the body. A delivery that does not answer 2xx is retried after one minute and again after ten.

## MCP server

The same reads and writes as the API, as tools for Claude Code, Claude Desktop or any MCP client, so you can say "draft three posts for Almyra for next week" or "what did Blue Villa do in October" and let Claude call them. Nothing here publishes; a Draft waits for review and approval in the app.

Endpoint: `POST https://<host>/mcp` (Streamable HTTP). Two ways in: **log in** (OAuth: Claude registers itself, sends you to the Social Ops login and an Allow page, and the tools then see the clients your login sees), or a **bearer token** from the Staff page (the same client scope as the API). Same rate limit either way (120 calls a minute per token or login; over it, HTTP 429 with a JSON-RPC error, code -32000). The connector's sign-up is limited per address: client registration 10 a minute and 100 a day, the Allow page 30 a minute.

```
# claude.ai and the Claude desktop app: Settings → Connectors → Add custom connector
#   Name: Social Ops   URL: https://<host>/mcp   → Add → Connect → log in → Allow.
#   Then tick the connector in a chat and ask ("plan 8 posts for Fuse for December").

# Claude Code: with the login (it opens the browser), or with a token
claude mcp add --transport http socialops https://<host>/mcp
claude mcp add --transport http socialops https://<host>/mcp --header "Authorization: Bearer so_…"

# On the server itself, over stdio, every client in reach
php artisan mcp:start socialops
```

The login is Laravel Passport behind `Mcp::oauthRoutes()` (`routes/ai.php`): discovery under `/.well-known/`, client registration at `/oauth/register` (only the Claude domains and localhost may be sent back to, `config/mcp.php`), the Allow page `resources/views/auth/oauth-authorize.blade.php`. The signing keys live in `storage/oauth-*.key`; the Refresh after update button makes them once.

Tools: `list-clients`, `list-posts` (client, from, to, state), `get-post` (post), `create-draft` (client, platforms, format, pillar, brief, scheduled_at, variants, settings, assets: the fields of the post form above), `update-post` (post, plus any of those fields: only what is sent changes, a caption per platform and language replaces that one, an approved post goes back to In Review, a published one is refused), `get-caption-brief` (post: the caption prompt filled for that post, with the voice, the limits, recent approved captions and the current ones; Claude writes the captions and files them with `update-post`, also to shorten or change them), `get-planning-brief` and `save-plan` (below), `list-claude-jobs` (no fields: the posts ticked "Ask Claude for the captions" and the plan requests queued with "Queue for Claude" on the client page; `update-post` with `settings.ask_claude` false and `save-plan` with `job` clear them (`save-plan` also clears the identical requests of that client waiting next to it); an hourly scheduled task on the agency's Claude plan runs them, so the app needs no API key). While `FEATURE_PUBLISHING` is on, also `list-comments` (client, open), `reply-to-comment` (comment, text), `month-numbers` (client, month YYYY-MM). Validation errors come back as tool errors with the field messages.

### Planning a batch through the connector (D18)

Since organize mode Claude plans from the chat, not from the server: the "Plan with Claude" box on the client page writes the request to paste. The recipe:

1. `get-planning-brief` (client, posts, from, days, platforms, instruction): the brand profile, the pillar quota for the batch (or the team's instruction instead), the window with its key dates, holidays and posting slots, the recent posts not to repeat, the formats, the caption rules and the exact prompt the planner used to follow. Claude researches the current trends itself (the last30days skill, or web search) with the profile's research sources and exclusions.
2. Claude plans the posts and writes the captions in the profile languages.
3. `save-plan` (client, trend_brief, posts: date, platforms, format, pillar, title, angle, trend_signal, why_now, asset_brief, caption_brief, captions per platform and language): files every post as a Draft on the calendar at the client's next free slot of that day, the trend brief and a plan review sheet under Reports, and answers with the post links, the sheet and its Word file (`word`: the θεματολογία for the client). Nothing is published; the Drafts go through review and approval like any other.

The server-side buttons ("Plan with Claude", "Post from a prompt", "Draft captions", "Shorten", "Change with Claude") and the research inside `content:plan` call the Claude API with `ANTHROPIC_API_KEY`; they are off while `FEATURE_CLAUDE_API` is off (`config/features.php`).
