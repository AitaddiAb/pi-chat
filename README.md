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

## Run it (local)

```bash
cd ~/Developer/Projects/PiPlugins/pi-chat
php artisan serve --host=127.0.0.1 --port=62880
```

- Login: `http://127.0.0.1:62880/login` — user `pi@local`
- LAN: `http://192.168.9.167:62880/...` (same Wi-Fi; artisan must bind
  `0.0.0.0` for that: `--host=0.0.0.0`)
- API base: `http://127.0.0.1:62880/api`

## Bridge pi to it

```bash
export PI_CHAT_URL="http://127.0.0.1:62880"
export PI_CHAT_TOKEN="<pi-bot sanctum token>"
export PI_CHAT_KEY="my-project"   # stable thread per project (optional)
```

In pi: `/share-chat` (flags `--key`, `--url` override env).
Then open the printed `/chat/{id}` link in any browser and chat.

Mint a fresh bot token any time:

```bash
php artisan tinker --execute='$u=\App\Models\User::where("email","pi@local")->first(); echo $u->createToken("pi-bot")->plainTextToken.PHP_EOL;'
```

## Expose publicly (stable URL)

Serve via your Cloudflare named tunnel, e.g. ingress
`pi.abdrahim.dev → http://127.0.0.1:62880`, then set
`PI_CHAT_URL="https://pi.abdrahim.dev"` before `/share-chat`.

## Notes

- DB is SQLite (`database/database.sqlite`) — switch to MySQL in `.env` whenever.
- v1 uses 2s polling, no websockets. Upgrade path: Laravel Reverb +
  broadcast on `ChatMessage` created.
- Token in `PI_CHAT_TOKEN` is full account access — keep it in env, never in chat.
