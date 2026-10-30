"use client";

import { useEffect, useRef, useState } from "react";
import { Check, Clipboard, Eye, EyeOff, KeyRound, Plus, RefreshCw, Trash2 } from "lucide-react";
import { api, ApiKey, CreatedKey, Developer, KeyResponse } from "@/lib/api";

export default function Keys({ user }: { user: Developer }) {
  const [data, setData] = useState<KeyResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [label, setLabel] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [secret, setSecret] = useState("");
  const [visible, setVisible] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  function clearSecret() {
    if (timer.current) clearTimeout(timer.current);
    timer.current = null;
    setSecret(""); setVisible(false);
  }
  async function refresh() { setError(""); try { setData(await api<KeyResponse>("developer/keys")); } catch (err) { setError(err instanceof Error ? err.message : "Could not load API keys."); } finally { setLoading(false); } }
  useEffect(() => {
    let active = true;
    api<KeyResponse>("developer/keys").then((keys) => { if (active) setData(keys); }).catch((e) => { if (active) setError(e.message); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; if (timer.current) clearTimeout(timer.current); };
  }, []);
  async function create(e: React.FormEvent) {
    e.preventDefault(); clearSecret(); setError(""); setNotice(""); setBusy(true);
    try {
      const result = await api<CreatedKey>("developer/keys", "POST", { name: label.trim(), password, ...(user.two_factor_confirmed_at ? { code } : {}) });
      setLabel(""); setPassword(""); setCode(""); setSecret(result.api_key);
      timer.current = setTimeout(() => { setSecret(""); setVisible(false); }, 60_000);
      setNotice("Key created. Copy it now; it cannot be retrieved again. The value disappears from this page in 60 seconds.");
      await refresh();
    } catch (err) { setError(err instanceof Error ? err.message : "Unable to create API key."); }
    finally { setBusy(false); }
  }
  async function revoke(key: ApiKey) {
    if (!window.confirm("Revoke " + key.name.replace(/^developer:/, "") + "? All connected apps using this key will lose access.")) return;
    setBusy(true); setError(""); setNotice("");
    try { await api<unknown>("developer/keys/" + key.id, "DELETE"); clearSecret(); await refresh(); setNotice("API key revoked."); }
    catch (err) { setError(err instanceof Error ? err.message : "Could not revoke this key."); }
    finally { setBusy(false); }
  }
  async function copy(value: string) {
    try { await navigator.clipboard.writeText(value); setNotice("Copied to clipboard."); }
    catch { setError("Clipboard permission is unavailable. Use HTTPS or manually select the value."); }
  }
  return <main className="page">
    <div className="page-heading"><div><div className="eyebrow">CREDENTIALS</div><h1>Developer API keys</h1><p>Secure, scoped keys for OpenMindAI Studio, your coding assistants and backend integrations.</p></div><span className="label-chip"><KeyRound size={14}/> {data ? data.keys.length + " of 10 keys" : loading ? "Loading keys" : "Keys unavailable"}</span></div>
    {error && <p className="alert error" role="alert">{error}</p>}
    {notice && <p className="alert success" role="status">{notice}</p>}
    <section className="panel key-create"><div className="panel-title"><div><h2>Generate a key</h2><p>Access is limited to the OpenMindAI model gateway. Keys expire after 90 days.</p></div><Plus size={20}/></div>
      <form onSubmit={create} className="key-form"><label>Key name<input value={label} onChange={(e) => setLabel(e.target.value)} required minLength={3} maxLength={64} placeholder="OpenMind Studio desktop" autoComplete="off"/></label><label>Confirm your password<input value={password} onChange={(e) => setPassword(e.target.value)} required type="password" autoComplete="current-password" placeholder="Account password"/></label>{!!user.two_factor_confirmed_at && <label>Authenticator or recovery code<input value={code} onChange={(e) => setCode(e.target.value)} required maxLength={64} autoComplete="one-time-code" placeholder="One-time code"/></label>}<button disabled={busy || !!data && data.keys.length >= 10} className="button primary" type="submit">{busy ? "Working…" : "Generate API key"} <Plus size={16}/></button></form>
      {secret && <div className="secret-box"><strong><Check size={17}/> Your new API key (shown once)</strong><div className="secret-input"><input readOnly type={visible ? "text" : "password"} value={secret} aria-label="New developer API key" spellCheck={false} autoComplete="off"/><button aria-label={visible ? "Hide new API key" : "Show new API key"} title={visible ? "Hide key" : "Show key"} onClick={() => setVisible(!visible)} type="button">{visible ? <EyeOff size={18}/> : <Eye size={18}/>}</button><button aria-label="Copy new API key" onClick={() => void copy(secret)} type="button"><Clipboard size={18}/> Copy</button></div><p>Store it in your application's secure credential store. Never commit it to Git or embed it in frontend code.</p><button type="button" className="text-link" onClick={clearSecret}>Clear now</button></div>}
    </section>
    <section className="panel"><div className="panel-title"><div><h2>Your keys</h2><p>Original values are not stored in recoverable form.</p></div><button className="button ghost small" type="button" onClick={() => void refresh()} aria-label="Refresh developer API keys"><RefreshCw size={15}/> Refresh</button></div>
      {!data ? <p className="empty" role="status">{loading ? "Loading API keys…" : "Unable to display API keys. Use Refresh to try again."}</p> : data.keys.length === 0 ? <p className="empty">No keys yet. Generate one above to connect your first application.</p> : <div className="key-list">{data.keys.map((key) => <div className="key-item" key={key.id}><span className="key-icon"><KeyRound size={19}/></span><div className="key-details"><strong>{key.name.replace(/^developer:/, "")}</strong><small>Created {new Date(key.created_at).toLocaleDateString()} · Expires {key.expires_at ? new Date(key.expires_at).toLocaleDateString() : "Never"} · Last used {key.last_used_at ? new Date(key.last_used_at).toLocaleDateString() : "Never"}</small></div><button type="button" disabled={busy} className="button danger small" onClick={() => void revoke(key)}><Trash2 size={15}/> Revoke</button></div>)}</div>}
    </section>
    <section className="panel connection"><div className="panel-title"><h2>API connection</h2><span className="label-chip">HTTPS · Bearer</span></div><label>Base URL<div className="copy-field"><code>{data?.base_url || "Load your API URL after signing in"}</code><button type="button" aria-label="Copy API base URL" disabled={!data} onClick={() => data && void copy(data.base_url)}><Clipboard size={17}/></button></div></label><p className="subtle small-text">GET /models · GET /catalog · POST /chat/completions. See the documentation for request samples and agent setup.</p></section>
  </main>;
}
