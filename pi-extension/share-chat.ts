/**
 * pi share-chat — bridge a pi session to the pi-chat Laravel backend.
 *
 * Instead of serving a local page (share-live), this pushes the transcript to
 * Laravel (`chat_sessions` / `chat_messages`) and pulls messages you send from
 * any logged-in device back into pi. Any device with a browser becomes a chat
 * client: log in → open the session → chat, pi runs it locally.
 *
 * Setup:
 *   1. Run the Laravel app (~/Developer/Projects/PiPlugins/pi-chat):
 *        php artisan serve --host=127.0.0.1 --port=62880
 *      (expose it with your named tunnel for outside access)
 *   2. export PI_CHAT_URL="http://127.0.0.1:62880"   (or the public URL)
 *      export PI_CHAT_TOKEN="<sanctum token, e.g. the pi-bot token>"
 *      export PI_CHAT_KEY="my-project"                (stable thread per project)
 *   3. Copy this file to ~/.pi/agent/extensions/share-chat.ts, restart pi.
 *
 * Usage (inside pi):
 *   /share-chat [--key slug] [--url http://...]   start bridging
 *   /share-chat-status                             show bridge status
 *   /unshare-chat                                  stop bridging
 *
 * Zero npm dependencies (uses global fetch, node 18+).
 */

import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";

interface ChatState {
  on: boolean;
  base: string;
  token: string;
  key: string;
  sessionId: number | null;
  lastSeen: number;
  poll: NodeJS.Timeout | null;
  busy: boolean;
  assistantBuf: string;
  failures: number;
}

function cfg(name: string, fallback = ""): string {
  return process.env[name] || fallback;
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
    sessionId: null,
    lastSeen: 0,
    poll: null,
    busy: false,
    assistantBuf: "",
    failures: 0,
  };

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
    } catch (err: any) {
      S.failures += 1;
      if (S.failures <= 3) {
        try {
          console.error(`[share-chat] post failed (${S.failures}): ${err?.message ?? err}`);
        } catch {
          /* ignore */
        }
      }
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
        const text = String(m.text).slice(0, 4000);
        try {
          if (S.busy) pi.sendUserMessage(text, { deliverAs: "followUp" });
          else pi.sendUserMessage(text);
        } catch (err: any) {
          try {
            pi.sendUserMessage(text, { deliverAs: "followUp" });
          } catch {
            /* drop */
          }
        }
      }
    }
  }

  async function start(ctx: any, opts: { key?: string; url?: string }) {
    if (S.on) {
      ctx.ui.notify(`share-chat already on → session #${S.sessionId} (${S.key})`, "info");
      return;
    }
    S.base = (opts.url || cfg("PI_CHAT_URL", "http://127.0.0.1:62880")).replace(/\/+$/, "");
    S.token = cfg("PI_CHAT_TOKEN");
    S.key =
      opts.key || cfg("PI_CHAT_KEY") || String(ctx.cwd || "pi").split("/").filter(Boolean).pop() || "pi";
    if (!S.token) {
      ctx.ui.notify("share-chat needs PI_CHAT_TOKEN (Sanctum pi-bot token). export it, then retry.", "error");
      return;
    }
    try {
      const s = await api("/api/chat/sessions/claim", {
        method: "POST",
        body: JSON.stringify({ key: S.key, title: `${S.key} · ${ctx.cwd ?? ""}`.slice(0, 120) }),
      });
      // Claim runs before S.on is set, so use fetch directly via api() — S.base/token already set.
      S.sessionId = s.id;
      S.lastSeen = 0;
      // Fast-forward past existing history so old web messages aren't replayed.
      try {
        const h = await api(`/api/chat/sessions/${S.sessionId}/messages?limit=1`);
        const ids: number[] = (h?.messages ?? []).map((m: any) => m?.id ?? 0);
        S.lastSeen = Math.max(0, ...ids);
      } catch {
        /* ignore */
      }
      S.on = true;
      S.failures = 0;
      if (S.poll) clearInterval(S.poll);
      S.poll = setInterval(() => void pollInbound(), 2000);
      try {
        (ctx.ui as any).setStatus?.("share-chat", `💬 chat #${S.sessionId}`);
      } catch {
        /* ignore */
      }
      ctx.ui.notify(`💬 Chat bridged → ${S.base}/chat/${S.sessionId} (key "${S.key}")`, "info");
      await post("status", `pi connected · cwd ${ctx.cwd ?? ""}`, { via: "pi" });
    } catch (err: any) {
      S.on = false;
      S.sessionId = null;
      ctx.ui.notify(`share-chat failed: ${err?.message ?? err} — is the Laravel app running at ${S.base}?`, "error");
    }
  }

  function stop(ctx?: any, silent = false) {
    if (S.poll) clearInterval(S.poll);
    S.poll = null;
    const was = S.on;
    S.on = false;
    S.sessionId = null;
    try {
      (ctx?.ui as any)?.setStatus?.("share-chat", undefined);
    } catch {
      /* ignore */
    }
    if (ctx && !silent && was) ctx.ui.notify("Chat bridge stopped.", "info");
    if (ctx && !silent && !was) ctx.ui.notify("share-chat is not running.", "warning");
  }

  pi.registerCommand("share-chat", {
    description: "Bridge this session to pi-chat (Laravel): chat with pi from any logged-in device",
    handler: async (args, ctx) => {
      const km = args.match(/--key\s+(\S+)/);
      const um = args.match(/--url\s+(\S+)/);
      await start(ctx, { key: km?.[1], url: um?.[1] });
    },
  });

  pi.registerCommand("share-chat-status", {
    description: "Show pi-chat bridge status",
    handler: async (_args, ctx) => {
      if (!S.on) {
        ctx.ui.notify("share-chat: off — run /share-chat to bridge this session.", "info");
        return;
      }
      ctx.ui.notify(`share-chat: ON · ${S.base}/chat/${S.sessionId} · key "${S.key}" · ${S.busy ? "working" : "idle"}`, "info");
    },
  });

  pi.registerCommand("unshare-chat", {
    description: "Stop the pi-chat bridge",
    handler: async (_args, ctx) => stop(ctx),
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
}
