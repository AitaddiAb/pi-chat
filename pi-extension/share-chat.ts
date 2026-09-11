/**
 * pi share-chat — bridge pi sessions to the pi-chat Laravel backend.
 *
 * 1 web chat per pi session: each pi session (ctx.sessionManager.getSessionId())
 * claims its own chat. Resuming the same pi session continues the same web
 * chat; a new pi session gets a new chat. `key` is now just project grouping.
 *
 * Setup:
 *   1. Run the Laravel app (~/Developer/Projects/PiPlugins/pi-chat):
 *        php artisan serve --host=127.0.0.1 --port=62880
 *      (expose it with your named tunnel for outside access)
 *   2. export PI_CHAT_URL="http://127.0.0.1:62880"   (or the public URL)
 *      export PI_CHAT_TOKEN="<sanctum token, e.g. the pi-bot token>"
 *      export PI_CHAT_KEY="my-project"                (project grouping label)
 *   3. Copy this file to ~/.pi/agent/extensions/share-chat.ts, restart pi.
 *
 * Usage (inside pi):
 *   /share-chat [--key slug] [--url http://...] [--new]   bridge (fresh = new chat)
 *   /share-chat-status                                    show bridge status
 *   /unshare-chat                                         stop bridging
 *
 * Zero npm dependencies (uses global fetch, node 18+).
 */

import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import { readFileSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

interface ChatState {
  on: boolean;
  base: string;
  token: string;
  key: string;
  piSessionId: string | null;
  sessionId: number | null;
  lastSeen: number;
  poll: NodeJS.Timeout | null;
  busy: boolean;
  assistantBuf: string;
  failures: number;
}

/** pi-side session identity: stable across resume, new id per new session. */
function getPiSessionId(ctx: any | undefined): string | null {
  try {
    const id = ctx?.sessionManager?.getSessionId?.();
    if (typeof id === "string" && id.trim()) return id.trim().slice(0, 64);
  } catch {
    /* ignore */
  }
  try {
    const f = ctx?.sessionManager?.getSessionFile?.();
    if (typeof f === "string" && f.trim()) {
      const base = f.split("/").pop()!.replace(/\.jsonl?$/, "");
      if (base) return `file:${base}`.slice(0, 64);
    }
  } catch {
    /* ignore */
  }
  return null;
}

function shortPiId(id: string | null): string {
  if (!id) return "local";
  return id.startsWith("file:") ? id.slice(5, 13) : id.slice(0, 8);
}

function cfg(name: string, fallback = ""): string {
  if (process.env[name]) return process.env[name] as string;
  const f = loadFileCfg();
  if (name === "PI_CHAT_URL" && f.url) return f.url;
  if (name === "PI_CHAT_TOKEN" && f.token) return f.token;
  if (name === "PI_CHAT_KEY" && f.key) return f.key;
  return fallback;
}

// Global pi configuration: ~/.pi/agent/share-chat.json
//   { "url": "http://127.0.0.1:62880", "token": "...", "key": "PI" }
// Precedence: /share-chat flags > env vars > this file > defaults.
interface FileCfg {
  url?: string;
  token?: string;
  key?: string;
  autostart?: boolean;
}
let fileCfg: FileCfg | null = null;
function loadFileCfg(): FileCfg {
  if (fileCfg) return fileCfg;
  fileCfg = {};
  try {
    const raw = readFileSync(join(homedir(), ".pi", "agent", "share-chat.json"), "utf8");
    fileCfg = { ...(JSON.parse(raw) as FileCfg) };
  } catch {
    /* missing or invalid → ignore, fall back to env/defaults */
  }
  return fileCfg;
}

/** Extract readable text from pi message content (string | array parts). */
function textOf(content: unknown): string {
  if (content == null) return "";
  if (typeof content === "string") return content;
  if (Array.isArray(content)) {
    return (content as any[])
      .map((p) => {
        if (p == null) return "";
        if (typeof p === "string") return p;
        if (typeof p.text === "string") return p.text;
        if (p.type === "image") return "[image]";
        if (typeof p.type === "string") return `[${p.type}]`;
        return "";
      })
      .filter(Boolean)
      .join("\n");
  }
  if (typeof (content as any).text === "string") return (content as any).text;
  try {
    return JSON.stringify(content).slice(0, 4000);
  } catch {
    return String(content).slice(0, 4000);
  }
}

function toolResultText(result: unknown): string {
  if (result == null) return "";
  const r = result as any;
  if (typeof r === "string") return r;
  if (Array.isArray(r)) return textOf(r);
  if (r.content) return textOf(r.content);
  if (typeof r.text === "string") return r.text;
  try {
    return JSON.stringify(r).slice(0, 4000);
  } catch {
    return String(r).slice(0, 4000);
  }
}

export default function (pi: ExtensionAPI) {
  const S: ChatState = {
    on: false,
    base: "",
    token: "",
    key: "",
    piSessionId: null,
    sessionId: null,
    lastSeen: 0,
    poll: null,
    busy: false,
    assistantBuf: "",
    failures: 0,
  };
  // Set when the user explicitly runs /unshare-chat — suppresses auto-bridge
  // for later pi sessions in the same process. /share-chat clears it.
  let userDisabled = false;

  async function api(path: string, init?: RequestInit): Promise<any> {
    const res = await fetch(`${S.base}${path}`, {
      ...init,
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        Authorization: `Bearer ${S.token}`,
        ...(init?.headers ?? {}),
      },
    });
    if (!res.ok) throw new Error(`pi-chat ${path} → HTTP ${res.status}`);
    return res.json();
  }

  async function post(role: "user" | "assistant" | "tool" | "status", text: string, extra: Record<string, unknown> = {}) {
    if (!S.on || S.sessionId == null || !text.trim()) return;
    try {
      const r = await api(`/api/chat/sessions/${S.sessionId}/messages`, {
        method: "POST",
        body: JSON.stringify({ role, text: text.slice(0, 20000), ...extra }),
      });
      if (typeof r?.id === "number" && r.id > S.lastSeen) S.lastSeen = r.id;
      S.failures = 0;
    } catch {
      S.failures += 1;
      // Stay silent: never console.error here — stderr lands in the input
      // prompt in pi's TUI. Failures are visible via /share-chat-status.
    }
  }

  async function pollInbound() {
    if (!S.on || S.sessionId == null) return;
    let data: any;
    try {
      data = await api(`/api/chat/sessions/${S.sessionId}/messages?after_id=${S.lastSeen}&limit=50`);
    } catch {
      return; // stay quiet; next tick retries
    }
    const rows: any[] = data?.messages ?? [];
    for (const m of rows) {
      if (typeof m?.id === "number" && m.id > S.lastSeen) S.lastSeen = m.id;
      // Only human messages from devices come back into pi. Everything pi
      // posted itself (assistant/tool/status, local users) is skipped.
      if (m?.role === "user" && m?.via === "web" && typeof m?.text === "string" && m.text.trim()) {
        let text = String(m.text).slice(0, 4000);
        // Remote alias: /compact is a built-in TUI command pi can't receive
        // as a message — rewrite to our extension command (runs ctx.compact()).
        if (/^\/compact(\s|$)/.test(text)) text = text.replace(/^\/compact/, "/rcompact");
        // Commands must arrive leading-"/": prompt() only executes those as
        // extension commands (needs expandPromptTemplates below).
        try {
          if (S.busy && !text.startsWith("/")) pi.sendUserMessage(text, { deliverAs: "followUp" });
          else pi.sendUserMessage(text, { expandPromptTemplates: true } as any);
        } catch (err: any) {
          try {
            await post("status", `send failed (${err?.message ?? err})${text.startsWith("/") ? " — commands only run while pi is idle, resend when it settles" : ""}`, { via: "pi" });
          } catch {
            /* drop */
          }
        }
      }
    }
  }

  async function start(ctx: any | undefined, opts: { key?: string; url?: string; fresh?: boolean }) {
    const piId = getPiSessionId(ctx);
    if (S.on) {
      // Same pi session still bridged → nothing to do. Different pi session
      // (resume/new/fork) → fall through and switch to its own web chat.
      if (piId && piId === S.piSessionId) {
        if (ctx) ctx.ui.notify(`share-chat already on → session #${S.sessionId} (${S.key})`, "info");
        return;
      }
      if (!piId && S.piSessionId === null) {
        if (ctx) ctx.ui.notify(`share-chat already on → session #${S.sessionId} (${S.key})`, "info");
        return;
      }
      await switchBridge(ctx, piId, opts);
      return;
    }
    S.base = (opts.url || cfg("PI_CHAT_URL", "http://127.0.0.1:62880")).replace(/\/+$/, "");
    S.token = cfg("PI_CHAT_TOKEN");
    S.key =
      opts.key || cfg("PI_CHAT_KEY") || String(ctx?.cwd || process.cwd()).split("/").filter(Boolean).pop() || "pi";
    S.piSessionId = piId;
    if (!S.token) {
      const msg = "share-chat needs a token: set \"token\" in ~/.pi/agent/share-chat.json (or PI_CHAT_TOKEN), then /share-chat.";
      // When ctx is missing (background autostart) stay silent — never
      // console.error, it renders at the input prompt. The next
      // /share-chat attempt with UI will surface the message.
      if (ctx) ctx.ui.notify(msg, "error");
      return;
    }
    try {
      let piName = "";
      try {
        piName = String(ctx?.sessionManager?.getSessionName?.() ?? "").trim();
      } catch {
        /* ignore */
      }
      const cwdBase = String(ctx?.cwd ?? process.cwd()).split("/").filter(Boolean).pop() || "pi";
      const title = `${S.key} · ${piName || cwdBase} · ${shortPiId(S.piSessionId)}`.slice(0, 120);
      const s = await api("/api/chat/sessions/claim", {
        method: "POST",
        body: JSON.stringify({
          key: S.key,
          title,
          pi_session_id: S.piSessionId,
          ...(opts.fresh ? { fresh: true } : {}),
        }),
      });
      // Claim runs before S.on is set, so use fetch directly via api() — S.base/token already set.
      S.sessionId = s.id;
      if (typeof s?.pi_session_id === "string" && s.pi_session_id) S.piSessionId = s.pi_session_id;
      S.lastSeen = 0;
      // Resume (same pi session → same web chat): fast-forward past history
      // so old web messages aren't replayed. Fresh chats start empty anyway.
      if (!opts.fresh) {
        try {
          const h = await api(`/api/chat/sessions/${S.sessionId}/messages?limit=1`);
          const ids: number[] = (h?.messages ?? []).map((m: any) => m?.id ?? 0);
          S.lastSeen = Math.max(0, ...ids);
        } catch {
          /* ignore */
        }
      }
      S.on = true;
      userDisabled = false;
      S.failures = 0;
      if (S.poll) clearInterval(S.poll);
      S.poll = setInterval(() => void pollInbound(), 2000);
      try {
        (ctx?.ui as any)?.setStatus?.("share-chat", `💬 chat #${S.sessionId}`);
      } catch {
        /* ignore */
      }
      // Notify goes to pi's toast UI ("somewhere else"), never to stderr —
      // console.error here would render at the input prompt.
      if (ctx)
        ctx.ui.notify(`💬 Chat bridged → ${S.base}/chat/${S.sessionId} (key "${S.key}" · pi ${shortPiId(S.piSessionId)})`, "info");
      await post("status", `pi connected · ${title} · cwd ${ctx?.cwd ?? process.cwd()}`, { via: "pi" });
    } catch (err: any) {
      S.on = false;
      S.sessionId = null;
      // Same rule: only surface via UI toast when we have a ctx.
      if (ctx) ctx.ui.notify(`share-chat failed: ${err?.message ?? err} — is the Laravel app reachable at ${S.base}?`, "error");
    }
  }

  /** Switch the live bridge to a different pi session's web chat. */
  async function switchBridge(ctx: any | undefined, piId: string | null, opts: { key?: string; url?: string; fresh?: boolean }) {
    const oldId = S.sessionId;
    if (S.poll) clearInterval(S.poll);
    S.poll = null;
    S.on = false;
    S.sessionId = null;
    // Keep base/token; key follows explicit flag > config > previous.
    if (opts.key) S.key = opts.key;
    if (opts.url) S.base = opts.url.replace(/\/+$/, "");
    S.piSessionId = piId;
    // Best-effort goodbye on the old chat is skipped — the old pi session is
    // gone and its history stays intact. Just claim/continue the new one.
    try {
      (ctx?.ui as any)?.setStatus?.("share-chat", undefined);
    } catch {
      /* ignore */
    }
    if (ctx && oldId != null)
      ctx.ui.notify(`share-chat: pi session changed → switching from chat #${oldId}…`, "info");
    // Temporarily mark off so start() takes the create path.
    S.on = false;
    const keepKey = S.key;
    const keepBase = S.base;
    // Reuse start() for claim + poll + status. It recomputes piId from ctx
    // (same value) and preserves key/base unless overridden.
    await start(ctx, { key: keepKey, url: keepBase, fresh: opts.fresh });
  }

  function stop(ctx?: any, silent = false, explicit = false) {
    if (S.poll) clearInterval(S.poll);
    S.poll = null;
    const was = S.on;
    S.on = false;
    S.sessionId = null;
    S.piSessionId = null;
    if (explicit) userDisabled = true;
    try {
      (ctx?.ui as any)?.setStatus?.("share-chat", undefined);
    } catch {
      /* ignore */
    }
    if (ctx && !silent && was) ctx.ui.notify("Chat bridge stopped.", "info");
    if (ctx && !silent && !was) ctx.ui.notify("share-chat is not running.", "warning");
  }

  // Autostart + pi-session tracking. We defer to `session_start` so we have a
  // real `ctx.ui` — success/failure surfaces via `notify` toast + footer
  // `setStatus`, never via `console.error` (stderr renders at the input
  // prompt in pi's TUI). Guarded to TUI mode so one-shot / non-TTY pi runs
  // don't claim sessions unless forced.
  //
  // 1 web chat per pi session: resume (same pi id) → same chat, new pi
  // session (new/resume/fork) → claim its own chat automatically.
  pi.on("session_start", async (_event, ctx) => {
    const cur = getPiSessionId(ctx as any);
    if (S.on) {
      if (cur && cur === S.piSessionId) {
        // Same pi session (e.g. reload): UI context is new, re-set footer.
        try {
          (ctx?.ui as any)?.setStatus?.("share-chat", `💬 chat #${S.sessionId}`);
        } catch {
          /* ignore */
        }
        return;
      }
      if (!cur && S.piSessionId === null) return;
      // Different pi session while bridged → switch to its own web chat.
      if (userDisabled) return;
      try {
        await switchBridge(ctx as any, cur, {});
      } catch {
        /* start() already notified via ctx.ui on failure */
      }
      return;
    }
    if (userDisabled) return;
    const f = loadFileCfg();
    const forced = process.env.PI_CHAT_AUTOSTART === "1";
    if (!(f.autostart === true || forced)) return;
    if (!forced && ctx.mode !== "tui") return;
    if (!(f.token || process.env.PI_CHAT_TOKEN)) return;
    try {
      await start(ctx as any, {});
    } catch {
      /* start() already notified via ctx.ui on failure */
    }
  });

  pi.registerCommand("share-chat", {
    description: "Bridge this pi session to pi-chat (Laravel): 1 web chat per pi session (--new for a fresh chat)",
    handler: async (args, ctx) => {
      const km = args.match(/--key\s+(\S+)/);
      const um = args.match(/--url\s+(\S+)/);
      const fresh = /--(new|fresh)\b/.test(args);
      userDisabled = false;
      await start(ctx, { key: km?.[1], url: um?.[1], fresh });
    },
  });

  pi.registerCommand("share-chat-status", {
    description: "Show pi-chat bridge status",
    handler: async (_args, ctx) => {
      if (!S.on) {
        ctx.ui.notify("share-chat: off — run /share-chat to bridge this session.", "info");
        return;
      }
      ctx.ui.notify(
        `share-chat: ON · ${S.base}/chat/${S.sessionId} · key "${S.key}" · pi ${shortPiId(S.piSessionId)} · ${S.busy ? "working" : "idle"}`,
        "info"
      );
    },
  });

  pi.registerCommand("unshare-chat", {
    description: "Stop the pi-chat bridge",
    handler: async (_args, ctx) => stop(ctx, false, true),
  });

  pi.registerCommand("rcompact", {
    description: "Compact context remotely (web /compact alias)",
    handler: async (_args, ctx) => {
      ctx.ui.notify("🗜 Compacting context (requested from web)…", "info");
      (ctx as any).compact?.();
    },
  });

  pi.on("agent_start", async () => {
    S.busy = true;
  });
  pi.on("agent_settled", async () => {
    S.busy = false;
  });

  pi.on("message_end", async (event: any) => {
    if (!S.on) return;
    const m = event?.message;
    if (!m) return;
    if (m.role === "assistant") {
      const text = textOf((m as any).content).trim().slice(0, 8000);
      if (text) await post("assistant", text, { via: "pi" });
    } else if (m.role === "user") {
      const text = textOf((m as any).content).trim().slice(0, 8000);
      if (text) await post("user", text, { via: "local" });
    }
  });

  pi.on("tool_execution_end", async (event: any) => {
    if (!S.on) return;
    const out = toolResultText((event as any).result).slice(0, 2000);
    if (!out.trim()) return;
    await post("tool", out, { via: "pi", tool_name: String(event?.toolName ?? "tool"), is_error: !!(event as any)?.isError });
  });

  pi.on("session_shutdown", async () => {
    if (S.on) {
      try {
        await post("status", "pi disconnected", { via: "pi" });
      } catch {
        /* ignore */
      }
      stop(undefined, true);
    }
  });

  pi.on("session_compact", async () => {
    if (!S.on) return;
    try {
      await post("status", "context compacted 🗜", { via: "pi" });
    } catch {
      /* best effort */
    }
  });
}
