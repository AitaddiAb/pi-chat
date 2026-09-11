# pi-chat — chat with pi from any device

Laravel backend + chat UI + pi bridge extension. Log in from any browser,
open a session, and chat — the local pi agent runs your messages.

## Layout

- `app/Models` — `ChatSession`, `ChatMessage`
- `app/Http/Controllers/Api` — `AuthController` (device login → token),
  `ChatController` (claim session, list/post messages, all `auth:sanctum`)
- `app/Http/Controllers/ChatWebController.php` — browser UI (session auth)
- `routes/api.php`, `routes/web.php`
- `resources/views` — login, session list, live chat page (2s polling)
- `pi-extension/share-chat.ts` — the pi side (installed to
  `~/.pi/agent/extensions/`). Outbound: transcript → API. Inbound: polls API,
  injects web messages into pi.

## Run it

Production backend is the remote server behind `https://pi.abdrahim.dev`
(not this copy). For local dev only:

```bash
cd ~/Developer/Projects/PiPlugins/pi-chat
php artisan serve --host=127.0.0.1 --port=62880
```

- Login: `http://127.0.0.1:62880/login` — user `pi@local`
- LAN: `http://192.168.9.167:62880/...` (same Wi-Fi; artisan must bind
  `0.0.0.0` for that: `--host=0.0.0.0`)
- API base: `http://127.0.0.1:62880/api`

## Bridge pi to it

Config lives in global pi configuration — `~/.pi/agent/share-chat.json`:

```json
{ "url": "http://127.0.0.1:62880", "token": "<bot token from /settings>", "key": "PI" }
```

Precedence: `/share-chat` flags > env (`PI_CHAT_URL`, `PI_CHAT_TOKEN`,
`PI_CHAT_KEY`) > this file > defaults. Then in pi just run `/share-chat`
(flags `--key`, `--url` still override) and open the printed `/chat/{id}` link.
Input is multi-line: **Enter** adds a new line, **⌘/Ctrl+Enter** (or Send)
sends. Typing **`/`** shows pi command suggestions (`GET /chat/commands`)
— picking one inserts it, and sending delivers it to pi.

Mint a fresh bot token any time (rotates the old one):

```bash
php artisan db:seed --force
# or: SEED_USER_PASSWORD=secret php artisan db:seed --force  (first run sets it)
```

## Expose publicly (stable URL)

Serve via your Cloudflare named tunnel, e.g. ingress
`pi.abdrahim.dev → http://127.0.0.1:62880`, then set
`PI_CHAT_URL="https://pi.abdrahim.dev"` before `/share-chat`.

## Notes

- DB is MySQL (`pi_chat` on local Homebrew MySQL) — configured in `.env`.
- v1 uses 2s polling, no websockets. Upgrade path: Laravel Reverb +
  broadcast on `ChatMessage` created.
- Token in `PI_CHAT_TOKEN` is full account access — keep it in env, never in chat.
