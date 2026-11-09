# Roadmap

Tick items as they finish. Effort numbers are estimates for 1–2 developers; re-check after the first two weeks.

## Phase 0: Platform access (start now, runs in parallel with code)
- [ ] Meta Business Verification for the agency Business Manager
- [ ] Meta developer app; request `instagram_content_publish` / Instagram Platform publishing, `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`; record screencast; submit App Review
- [ ] Google Business Profile API access request form (required: our own GBP driver)
- [ ] TikTok developer app; Content Posting API with `video.publish`; submit audit (required for public TikTok posts; private-only until it passes)
- [x] DPA template for clients; list sub-processors (Anthropic, storage host): `docs/dpa-template.md`, the lawyer's review and the Greek version pending

## Phase 1: MVP (~8–11 weeks; the four direct drivers add roughly 2 to 3 weeks over an aggregator)
Exit: all current clients run one full month through the system.

- [x] Resolve open decisions D1–D4 in `decisions.md` (decided 2026-11-03; D5 and D6 still open)
- [x] Project scaffold (Laravel 13, Pest, Horizon, CI) and auth for agency staff (login only, `php artisan staff:create`)
- [ ] EU-region storage bucket (depends on D3)
- [ ] Meta app in dev mode, TikTok sandbox app, GBP test listing, agency test accounts, so every driver is tested before approvals land
- [x] **Clients & brand profiles:** CRUD; fields from `config/clients/example.yaml`; per-client isolation (every client-owned table carries `client_id`; `php artisan clients:import` loads YAML profiles)
- [x] **Asset inbox:** per-client no-login upload link (signed); auto-naming `CLIENT_YYYYMMDD_PLATFORM_FORMAT_PILLAR_LANG_v#`; tags (pillar, season, people/consent, UGC permission)
- [x] **Posts & calendar:** post model with state machine (see CLAUDE.md); month/week views per client and all clients; drag-and-drop reschedule
- [x] **AI drafting:** Claude API call using `prompts/caption-draft.md` + brand profile + last approved captions as examples; outputs EL/EN, per-platform variants, alt text, hashtags; human edits before review. "Draft captions with Claude" button on the post editor (`POST /posts/{id}/draft`), reviewer notes shown on the form
- [x] **Trend-aware batch planning:** `php artisan content:plan <client> --posts=N` researches trends (Claude web search, `prompts/trend-research.md`), plans N posts by pillar quota and key dates (`prompts/content-plan.md`), drafts every caption with `CaptionDrafter` (reusable for the AI drafting item above), creates one Draft post per plan with a variant per platform and language on the calendar, and saves a review sheet under `plans/<client>/`
- [x] **Approval portal:** signed expiring magic link per batch; Instagram/Facebook/TikTok/GBP previews; inline comments; approve / request changes on mobile; lock on approve; auto-schedule on approve; reminders + SLA escalation; auto-approve on timeout for `optional` clients. Staff page `/clients/{id}/reviews` sends a batch (`Review`) and reads the answers; the client's page is `/review/{id}` behind the signed link; `reviews:remind` runs hourly from the scheduler
- [x] **Publisher adapter and drivers:** `publish(post, account)` interface; `MetaPublisher` (Instagram + Facebook via Graph API: image, carousel, Reel, Story; container flow, resumable video upload), `TikTokPublisher` (Content Posting API direct post, video and photos; private-only until audit), `GbpPublisher` (local posts: update, event, offer); OAuth connect flow per platform; live quota check; retries; status polling or webhooks; Manual-Needed notification; TikTok composer shows preview, commercial-content disclosure and the creator's consent, as TikTok requires. Built in dev mode against the official APIs (`app/Publishing`), tokens in `accounts`, `posts:publish-due` every minute queues `PublishPost` (3 tries, then Failed + mail); needs the Phase 0 app ids in `.env` and the agency's test accounts to try for real
- [x] **Token monitor:** daily job; expiry per account; alert 10 days before re-auth; re-auth link. `accounts:check-tokens` at 08:00 mails the staff member who connected the account once per token, the Accounts page colours expiring tokens
- [ ] Pilot with 1 client (Fuse Pharmacy, D4) for 2 weeks, then roll out to the rest. Setup steps: `docs/pilot-setup.md`

## Phase 2: Scale (~6–8 weeks)
Exit: monthly reports fully automatic; onboarding a new client takes under 1 hour.

- [x] Bulk CSV / Google Sheet import into the calendar ("Import posts from a spreadsheet" on the client page: the calendar's CSV columns, a Google Sheet exported as CSV; every row becomes a Draft, or nothing is imported)
- [x] Evergreen queues with time slots (`slots` in the profile: "Next free slot" on the post form, the planner dates posts on slots, `slots:check` warns on Slack every Thursday when next week's slots are not filled)
- [ ] Canva Brand Template autofill for repeat graphics (menus, events, offers)
- [x] Monthly auto-report: insights pull → Claude summary (`posts:insights` daily, `reports:monthly` on the 1st; the report lives in the client's Reports page, .md download; branded PDF still open)
- [x] Google Drive folder → asset inbox (our own service account, hourly, no n8n)
- [x] Dropbox folder → asset inbox (our own Dropbox app, the agency's account, hourly)
- [ ] WhatsApp → asset inbox (needs a dedicated WhatsApp Business number on the Cloud API; incoming service messages are free, decide after Meta verification)
- [x] MCP endpoint so Claude can query the calendar and draft posts (`routes/ai.php`, `App\Mcp\SocialOpsServer`: `/mcp` over HTTP with the API tokens, stdio for local use; never publishes)
- [x] Content-pillar mix tracking per client, with drift warning (`App\Planning\PillarMix`: shares 30 days back and ahead on the client form and in the monthly report; "Pillar mix off target" on the dashboard and in the Thursday `slots:check` Slack line)

## Phase 3: Advanced AI (~6–8 weeks)
Exit: Reels assembly and best-time suggestions in use by every client.

- [x] Auto-assembled Reels from venue clips (human final cut): ffmpeg on the server, "Make a Reel" on the assets page
- [x] Best-time-to-post suggestions from our own performance data
- [x] ~~Link-in-bio pages generated on WordPress per client~~ (removed 2026-11-05, D19)

## Phase 4: Organizer (from 2026-11-04, D16)
Exit: the team plans, briefs, approves and tracks every client's month in Social Ops and posts in the apps by hand.

- [x] Organize mode: posting through the drivers and reading back from the platform APIs off behind `FEATURE_PUBLISHING`, code kept
- [x] Google Business Profile removed; LinkedIn added as a platform (Instagram, Facebook, TikTok, LinkedIn)
- [x] Claude through the MCP connector: planning brief and save-plan tools, the server-side API buttons behind `FEATURE_CLAUDE_API`
- [x] Word export of the θεματολογία (topic plan) from a content plan
- [x] Profile form in tabs (Basics, Voice and content, Approval and timing, Research, Replies and webhooks)
- [x] Clients board: status, person, priority, plan due, posts per week, pending notes, links (the Agency tab of the profile form)
- [x] Deadlines per client per month: client side and agency side, due date, done (Deadlines tab, due this week on the dashboard)
- [x] Recycle bin per client: a deleted post waits there whole and can be restored or deleted for good (Recycle bin tab, D20)
- [x] The Claude queue in sight: waiting requests on the client page and the Jobs page with Cancel and Stop asking, the same plan request queued once, a dashboard box and one Slack line when a request waits over 2 hours
- [x] Post today: the posts due today across the clients, media to download, Copy buttons for the exact texts to paste, Mark published (works on a phone)
- [x] Assets page rework: the team uploads many photos and videos at once (progress per file, batch pillar and tags), the other intake ways and the Reel tool folded, filters (search, kind, pillar, used or not), preview, delete that names the posts using the file
- [x] Reference folder per client on Google Drive (Files tab, D23): the previous posts and the reference files read as text, hourly, and handed to the planner in `get-planning-brief` and `content:plan`
- [x] Posts tied to a day keep their day: Claude's `fixed_date` flag in the plan, the holiday-name backup, the client's usual slot time (D24)
