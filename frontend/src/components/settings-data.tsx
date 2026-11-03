"use client";
import { useEffect, useRef, useState } from "react";
import { api, type Conversation, type Page, type User } from "@/lib/api";
const actions = {
  archive_chats: "ARCHIVE ALL CHATS",
  delete_chats: "DELETE ALL CHATS",
  clear_memories: "CLEAR ALL MEMORIES",
  revoke_shares: "REVOKE ALL LINKS",
};
export default function SettingsData({
  user,
  onChanged,
  onSignedOut,
}: {
  user: User;
  onChanged: () => void;
  onSignedOut: () => void;
}) {
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [archived, setArchived] = useState<Page<Conversation> | null>(null);
  const [shares, setShares] = useState<
    { id: number; conversation_id: number | null; research_project_id?: number | null; expires_at: string }[]
  >([]);
  const [action, setAction] = useState<keyof typeof actions>("archive_chats");
  const controller = useRef<AbortController | null>(null);
  useEffect(() => {
    const c = new AbortController();
    controller.current = c;
    Promise.all([
      api<Page<Conversation>>(
        "conversations?archived=1",
        "GET",
        undefined,
        c.signal,
      ),
      api<typeof shares>("shares", "GET", undefined, c.signal),
    ])
      .then(([a, s]) => {
        setArchived(a);
        setShares(s);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => controller.current?.abort();
  }, []);
  async function run(fn: (signal: AbortSignal) => Promise<void>) {
    controller.current?.abort();
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await fn(c.signal);
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  async function reload(signal: AbortSignal) {
    const [a, s] = await Promise.all([
      api<Page<Conversation>>(
        "conversations?archived=1",
        "GET",
        undefined,
        signal,
      ),
      api<typeof shares>("shares", "GET", undefined, signal),
    ]);
    if (!signal.aborted) {
      setArchived(a);
      setShares(s);
      onChanged();
    }
  }
  return (
    <>
      <h3>Data controls</h3>
      <p className="muted">
        Chats and files are stored on this installation. No model-training or
        third-party inference pipeline is configured. Temporary chat does not
        save conversation history; separately uploaded files and media tasks
        remain stored until deleted.
      </p>
      {error && (
        <p className="feedback error" role="alert">
          {error}
        </p>
      )}
      {notice && (
        <p className="feedback success" role="status">
          {notice}
        </p>
      )}
      <button
        className="secondary-button"
        disabled={busy}
        onClick={() =>
          void run(async (signal) => {
            const data = await api("export", "GET", undefined, signal);
            if (signal.aborted) return;
            const url = URL.createObjectURL(
              new Blob([JSON.stringify(data, null, 2)], {
                type: "application/json",
              }),
            );
            const a = document.createElement("a");
            a.href = url;
            a.download = "openmindai-export.json";
            a.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
          })
        }
      >
        Export my data
      </button>
      <p className="muted">
        Includes profile, preferences, saved chats, memories, projects, text
        files and canvas documents. Download binary media separately from Media
        studio.
      </p>
      <hr />
      <h3>Archived chats</h3>
      {archived?.data.length === 0 && (
        <p className="muted">No archived chats.</p>
      )}
      {archived?.data.map((chat) => (
        <div className="device-row" key={chat.id}>
          <span>{chat.title}</span>
          <button
            disabled={busy}
            onClick={() =>
              void run(async (signal) => {
                await api(
                  `conversations/${chat.id}`,
                  "PATCH",
                  { archived: false },
                  signal,
                );
                await reload(signal);
              })
            }
          >
            Unarchive
          </button>
          <button
            className="danger-text"
            disabled={busy}
            onClick={() => {
              if (confirm("Permanently delete this chat?"))
                void run(async (signal) => {
                  await api(
                    `conversations/${chat.id}`,
                    "DELETE",
                    undefined,
                    signal,
                  );
                  await reload(signal);
                });
            }}
          >
            Delete
          </button>
        </div>
      ))}
      {archived && archived.last_page > 1 && (
        <div className="button-row">
          <button
            disabled={busy || archived.current_page <= 1}
            onClick={() =>
              void run(async (signal) =>
                setArchived(
                  await api(
                    `conversations?archived=1&page=${archived.current_page - 1}`,
                    "GET",
                    undefined,
                    signal,
                  ),
                ),
              )
            }
          >
            Previous
          </button>
          <span>
            {archived.current_page} / {archived.last_page}
          </span>
          <button
            disabled={busy || archived.current_page >= archived.last_page}
            onClick={() =>
              void run(async (signal) =>
                setArchived(
                  await api(
                    `conversations?archived=1&page=${archived.current_page + 1}`,
                    "GET",
                    undefined,
                    signal,
                  ),
                ),
              )
            }
          >
            Next
          </button>
        </div>
      )}
      <hr />
      <h3>Shared links</h3>
      <p className="muted">Revocation removes access to the public snapshot.</p>
      {shares.length === 0 && <p className="muted">No shared links.</p>}
      {shares.map((s) => (
        <div className="device-row" key={s.id}>
          <span>
            {s.research_project_id ? `Research project #${s.research_project_id}` : `Chat #${s.conversation_id}`}
            <small>Expires {new Date(s.expires_at).toLocaleDateString()}</small>
          </span>
          <button
            disabled={busy}
            onClick={() =>
              void run(async (signal) => {
                await api(`shares/${s.id}`, "DELETE", undefined, signal);
                await reload(signal);
              })
            }
          >
            Revoke
          </button>
        </div>
      ))}
      <hr />
      <h3>Bulk data actions</h3>
      <p className="muted">
        Archiving keeps chats. Deleting chats also removes their messages and
        shared snapshots, including archived/project chats. Files, media,
        canvases and memories remain separate.
      </p>
      <form
        className="form-stack"
        onSubmit={(e) => {
          e.preventDefault();
          const data = Object.fromEntries(new FormData(e.currentTarget));
          void run(async (signal) => {
            await api("settings/data", "POST", { ...data, action }, signal);
            await reload(signal);
            if (!signal.aborted) setNotice("Data action completed.");
          });
        }}
      >
        <fieldset disabled={busy} className="settings-fieldset">
          <label>
            Action
            <select
              value={action}
              onChange={(e) =>
                setAction(e.target.value as keyof typeof actions)
              }
            >
              {Object.entries(actions).map(([key, label]) => (
                <option key={key} value={key}>
                  {label.toLowerCase()}
                </option>
              ))}
            </select>
          </label>
          <label>
            Type {actions[action]} to confirm
            <input
              key={action}
              name="confirmation"
              required
              autoComplete="off"
            />
          </label>
          <IdentityFields twoFactor={!!user.two_factor_confirmed_at} />
          <button className="danger-button">Confirm action</button>
        </fieldset>
      </form>
      <hr />
      <h3>Delete account</h3>
      <p className="muted">
        Permanently removes your profile, chats, memories, projects, files,
        canvases, media and shared links from this installation. Export anything
        you need first. Infrastructure backups follow the operator’s retention
        policy.
      </p>
      {!user.is_admin ? (
        <form
          className="form-stack"
          onSubmit={(e) => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(e.currentTarget));
            if (data.confirmation !== user.email)
              return setError(
                "Enter your exact email address to confirm deletion.",
              );
            void run(async (signal) => {
              await api("me", "DELETE", data, signal);
              if (!signal.aborted) onSignedOut();
            });
          }}
        >
          <fieldset disabled={busy} className="settings-fieldset">
            <label>
              Type your email to confirm
              <input name="confirmation" required autoComplete="off" />
            </label>
            <IdentityFields twoFactor={!!user.two_factor_confirmed_at} />
            <button className="danger-button">
              Permanently delete my account
            </button>
          </fieldset>
        </form>
      ) : (
        <p className="muted">Administrator accounts cannot be self-deleted.</p>
      )}
    </>
  );
}
export function IdentityFields({ twoFactor }: { twoFactor: boolean }) {
  return (
    <>
      <label>
        Current password
        <input
          name="password"
          type="password"
          required
          autoComplete="current-password"
        />
      </label>
      {twoFactor && (
        <label>
          Authenticator or recovery code
          <input
            name="code"
            required
            autoComplete="one-time-code"
            maxLength={64}
          />
        </label>
      )}
    </>
  );
}
