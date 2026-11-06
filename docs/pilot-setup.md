# Pilot setup: platform apps, server, first client

What Panos has to do once so the app can log in to the platforms and publish. Everything here is our own app on each platform (decision D2), nothing paid. Keep every id and secret in `.env` on the server, never in git.

The redirect URIs below use `https://YOUR-DOMAIN`. Replace it with the real domain of the app (the `APP_URL` in `.env`). HTTPS is required by all three platforms.

## 1. Meta (Facebook Pages and Instagram) → `META_APP_ID`, `META_APP_SECRET`

1. Go to https://developers.facebook.com/apps and log in with the Facebook account that is an admin of the agency's Business Manager (Business Portfolio).
2. **Create app** → use case "Other" → type **Business** → name it `Social Ops` → attach the agency's Business Portfolio.
3. In the app dashboard add two products: **Facebook Login for Business** and **Instagram** (the "Instagram API with Facebook Login" setup).
4. Facebook Login for Business → **Settings** → *Valid OAuth Redirect URIs*: `https://YOUR-DOMAIN/oauth/meta/callback`. Save.
5. **App settings → Basic**: copy **App ID** and **App Secret** (click *Show*). Fill *Privacy Policy URL* and *App icon* now; App Review asks for them later. Add `YOUR-DOMAIN` under *App Domains*.
6. **App Roles → Roles**: every staff member who will press *Connect* in Social Ops must be an *Administrator* or *Developer* of the app. While the app is in **Development mode** only these people can log in, and only the Pages and Instagram accounts they manage can be published to. That is enough for the pilot.
7. The pilot client's Page (Fuse Pharmacy) and its Instagram professional account must be managed by the agency: in Business Manager add the Page to the portfolio (or request access from the client) and give the staff member *Full control* or *Content* on it. Instagram must be a Business or Creator account linked to that Page.
8. While the app is in Development mode a Facebook Page post it publishes is visible only to people with a role on the app (Instagram posts are normal). So before Fuse Pharmacy's Facebook posts can go public: **App Review** for `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`, `pages_read_user_content`, `pages_manage_engagement`, `instagram_basic`, `instagram_content_publish`, `instagram_manage_comments`, `instagram_manage_insights`, `business_management` (`pages_read_user_content`, `pages_manage_engagement` and `instagram_manage_comments` are for the comment inbox: reading, replying to and hiding comments; `instagram_manage_insights` plus the **Instagram Public Content Access** feature are for the weekly listening report: hashtag search and competitor profiles) (screencast of the Connect flow and one post going out), plus **Business Verification** of the agency. Start it in week one of the pilot; Instagram and the approval flow can be tested meanwhile.

`.env`:
```
META_APP_ID=…
META_APP_SECRET=…
META_GRAPH_VERSION=v26.0
```

## 2. TikTok → `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`

1. Go to https://developers.tiktok.com and log in with the agency's TikTok account (create one if needed). Complete the developer registration (organisation, website).
2. **Manage apps → Connect an app** → name `Social Ops`, category *Marketing*, platform *Web*, website `https://YOUR-DOMAIN`.
3. Add two products: **Login Kit** and **Content Posting API**. In Login Kit set *Redirect URI* `https://YOUR-DOMAIN/oauth/tiktok/callback`. In the scopes list tick `user.info.basic` and `video.publish`.
4. For Content Posting API with "pull from URL" (what the app uses) you must **verify the domain** the media is served from: the app's own domain for the pilot (Settings → URL properties → add `YOUR-DOMAIN`, place the verification file or DNS record it gives you).
5. **Submit the app for review** (basic review, needed before any real account can log in). After that it still counts as *unaudited*: posts are forced private. Apply for the **Content Posting API audit** from the same page once the pilot has a private test post going out.
6. Copy **Client key** and **Client secret** from the app's *App details* page.

`.env`:
```
TIKTOK_CLIENT_KEY=…
TIKTOK_CLIENT_SECRET=…
TIKTOK_AUDITED=false     # set true the day TikTok approves the audit
```

Until the audit passes, Social Ops publishes a TikTok post only when its privacy is *Only me* (a test); public posts go to Manual-Needed and you post them in the TikTok app.

## 3. Google Business Profile

Removed on 2026-11-04 (D17): the platforms are Instagram, Facebook, TikTok and LinkedIn. LinkedIn has no driver; the team posts there by hand.

## 3b. Google Drive folders (optional) → `GOOGLE_SERVICE_ACCOUNT_JSON`

Lets a client drop photos and videos in a shared Google Drive folder; Social Ops files them in the client's inbox every hour (assets page → Google Drive box). The Drive API is free, and a service account needs no consent screen, no Google review and no token renewal.

1. In a Google Cloud project of the agency: **APIs & Services → Library** → enable *Google Drive API*.
2. **IAM & Admin → Service accounts → Create service account**: name `social-ops-drive`, no roles, **Done**.
3. Open it → **Keys → Add key → Create new key → JSON**. The file downloads once. It is a credential: keep it out of the repository and out of `public/`.
4. Upload it to the server next to the app (not inside it), point `.env` at it, then **Refresh after update** at `/refresh` (linked in the side navigation for admins) (or `php artisan config:clear`).
5. Per client, on the assets page: share the folder (the client's own, or one you make in the agency's Drive and share with the client) with the address the box shows, *Viewer* is enough; paste the folder link; **Watch folder**. The folder itself is read, not its subfolders; one folder per client; a file is filed once, by its Drive id.

`.env`:
```
GOOGLE_SERVICE_ACCOUNT_JSON=/var/www/vhosts/AGENCY-DOMAIN/socialops-drive.json
```

## 3c. Dropbox folders (optional) → `DROPBOX_APP_KEY`

Same idea with Dropbox: the client shares a folder with the agency's Dropbox (or you make one and share it with the client), and every hour its new photos and videos land in the client's inbox (assets page → Dropbox box). Free; a Dropbox app in development status serves the agency's own account without Dropbox's review.

1. https://www.dropbox.com/developers/apps → **Create app**: *Scoped access*, *Full Dropbox* (so folders shared with the agency are visible wherever they sit), name `Social Ops`.
2. **Permissions** tab: tick `account_info.read`, `files.metadata.read`, `files.content.read`, **Submit**. Nothing is written to Dropbox.
3. **Settings** tab: *Redirect URIs* add `https://YOUR-DOMAIN/dropbox/callback`; copy the **App key** (no secret is needed, the login uses PKCE). The app can stay in *Development* status: it serves the agency's own account.
4. Per client, on the assets page: **Connect Dropbox** (log in with the agency's Dropbox), then type the folder's path, for example `/Clients/Almyra` (a folder the client shared sits at the top level, `/Almyra photos`), **Watch folder**. The folder itself is read, not its subfolders; a file is filed once, by its Dropbox id.

`.env`:
```
DROPBOX_APP_KEY=…
```

## 4. Claude → `ANTHROPIC_API_KEY`

Already in hand. Only in `.env`. Rotate at https://console.anthropic.com/settings/keys if it was ever pasted anywhere.

## 5. Server (decision D3: Panos's Plesk server)

A subdomain of the agency domain is enough, for example `social.AGENCY.gr`; the app is internal and the links clients receive carry a signature, not a brand. Below, `SUB` is that subdomain and `ROOT` is its folder, `/var/www/vhosts/AGENCY.gr/SUB`.

1. **Websites & Domains → Add Subdomain** `SUB`. Then **SSL/TLS Certificates → Install** a free Let's Encrypt certificate and, in **Hosting Settings**, tick *Permanent SEO-safe redirect from HTTP to HTTPS*.
2. **Hosting Settings**: PHP version **8.3**, run as *FPM application served by Apache* (Apache reads the app's `.htaccess`; with *served by nginx* you must add `location / { try_files $uri $uri/ /index.php?$query_string; }` under Apache & nginx Settings → Additional nginx directives, or every page is a 404); *Document root* `SUB/public` (set this after step 4 when the folder exists; until then Plesk shows its own 404 page). If 8.3 is not offered, install it under Tools & Settings → Updates (admin).
3. **Web Hosting Access**: *Access to the server over SSH* = `/bin/bash`. Everything below runs over SSH as that user, with Plesk's PHP (its CLI prints harmless `memcached.so` warnings; `nano` may be missing, so edit `.env` in Plesk's File Manager): `alias php=/opt/plesk/php/8.3/bin/php` and `alias composer="php /usr/lib/plesk-9.0/composer.phar"` (`/usr/lib64/...` on AlmaLinux; if neither exists, download composer.phar from getcomposer.org).
4. **Websites & Domains → Git → Add repository**: remote `git@github.com:Kokouliss/SocialOps.git`, branch `main`, deploy to `SUB`. Plesk shows a public key: add it on GitHub under the repo's Settings → Deploy keys (read-only). Click *Pull now*. In *Additional deployment actions* paste the update commands from step 10 so every pull redeploys.
5. **Databases → Add Database**: MariaDB (Plesk's default) is fine, Postgres is not needed. Note the name, user and password.
6. **Mail → Create mailbox** `social@AGENCY.gr` for the reminders and alerts.
7. `.env` (in `ROOT`, copy from `.env.example`):
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://SUB
   DB_CONNECTION=mariadb
   DB_HOST=localhost
   DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=…
   QUEUE_CONNECTION=database
   CACHE_STORE=database
   FILESYSTEM_DISK=local
   MAIL_MAILER=smtp MAIL_HOST=localhost MAIL_PORT=587 MAIL_USERNAME=social@AGENCY.gr MAIL_PASSWORD=… MAIL_FROM_ADDRESS=social@AGENCY.gr
   MAIL_URL=smtp://localhost?verify_peer=0   (Plesk's mail certificate carries the server's name, not `localhost`: without this line every mail fails with "Peer certificate subjectAltName did not match expected name `localhost`". Settings → Send a test e-mail checks it.)
   ANTHROPIC_API_KEY=… plus the platform ids from sections 1 to 3
   SLACK_WEBHOOK_URL=… optional: staff alerts (post needs a human, approval answered or overdue, token expiring, plan finished) also go to a Slack channel; create it under the Slack app's Incoming Webhooks
   ```
   Plesk has no Redis, so the queue and cache use the database (Horizon is simply unused). Media stays on the server's disk behind signed links for the pilot; an EU S3-compatible bucket comes before more clients.
8. First install, in `ROOT`:
   ```
   composer install --no-dev --optimize-autoloader
   php artisan key:generate && php artisan migrate --force && php artisan optimize
   ```
   Then open `https://SUB/setup` in the browser and create the first staff login (the page disappears once a user exists); add colleagues on the Staff page.
   Front-end assets: if `node -v` works over SSH (Plesk's Node.js extension), run `npm ci && npm run build` there; otherwise run those two commands on your PC and upload the `public/build` folder with File Manager.
9. **Scheduled Tasks → Add Task**, type *Run a command*, cron style `* * * * *` (every minute), two tasks. Write the app folder's full path, the one that holds `artisan` (for example `/var/www/vhosts/AGENCY.gr/SUB.AGENCY.gr`), in place of ROOT:
   ```
   /opt/plesk/php/8.3/bin/php ROOT/artisan schedule:run
   /opt/plesk/php/8.3/bin/php ROOT/artisan queue:work --stop-when-empty --max-time=55
   ```
   The first runs the scheduled commands (publishing due posts, reminders, token checks, the hourly folder pulls); the second works the queue: planning, Reels, imports, mails. Plesk's PHP prints two harmless `memcached.so` warnings before the real output; "Could not open input file" means the path is wrong. The Jobs page in the app shows waiting jobs with their attempts: a job that stays at 0 attempts means this task is not running.
10. Updates: Git → *Pull now* (or push to `main` with auto-deploy on), then open `/refresh` in the app and press **Refresh after update** (linked in the side navigation for admins; the page itself needs no migration, so it works even when the dashboard fails after an update). When the pull changed `composer.lock` (the pull request says so), run `composer install --no-dev --optimize-autoloader` in `ROOT` over SSH first: the button installs no packages. Or everything over SSH:
   ```
   composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize
   ```
   plus `npm run build` or a new `public/build` upload when the front-end changed.

Any other Linux server works the same with nginx + PHP-FPM, a database, cron for `schedule:run` and either the `queue:work` task above or Redis + `php artisan horizon` under systemd.

## 5a. ffmpeg for Reels (optional)

The "Make a Reel" box on the assets page cuts ticked clips into one vertical Reel with ffmpeg, which runs on the server. Install it once: `apt install ffmpeg` on Ubuntu or Debian, RPM Fusion's `ffmpeg` on AlmaLinux; check with `ffmpeg -version`. The queue task runs from cron with a short PATH, so put the full path in `.env` (`which ffmpeg` prints it):

```
FFMPEG_PATH=/usr/bin/ffmpeg
```

Without it the box says so and nothing else changes.

## 6. First client: Fuse Pharmacy (decision D4)

1. Clients → New client. Code `FUSE`, type `brand`. Fill in the form: accounts, voice, pillars, hashtags, approver name and e-mail, SLA (a starter profile is in the project Library, `clients/fuse-pharmacy.yaml`; `php artisan clients:import` loads such a file, or copy its values into the form).
2. Accounts → Connect Facebook / Instagram (TikTok when the app review is through) and tick the Fuse Pharmacy Page and Instagram account.
3. Assets → copy the upload link and send it to the client.
4. Calendar → create one test post for tomorrow, *Draft captions with Claude*, send it for approval to yourself first (put your own e-mail as approver for the first run), approve from your phone, watch it publish. Then put the client's approver in and start the two weeks.

What to watch during the pilot: Horizon's *Failed jobs* page, the post's red *Publishing* box, and the Manual-Needed e-mails.
