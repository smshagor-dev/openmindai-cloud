"use client";
import { useEffect, useRef, useState } from "react";
import PasswordInput from "./password-input";
import {
  Check,
  Copy,
  Download,
  KeyRound,
  LoaderCircle,
  Monitor,
  ShieldCheck,
  UserRound,
} from "lucide-react";
import Dialog from "./dialog";
import SettingsPreferences from "./settings-preferences";
import DeveloperApiSettings from "./developer-api-settings";
import SettingsData, { IdentityFields } from "./settings-data";
import ProfilePhoto from "./profile-photo";
import { api as requestApi, type User } from "@/lib/api";
type Device = {
  current: boolean;
  id: number;
  name: string;
  last_used_at: string | null;
};
export default function AccountDialog({
  user,
  onClose,
  onUpdate,
  onSignedOut,
  onChanged,
  onDataChanged,
}: {
  user: User;
  onClose: () => void;
  onUpdate: (user: User) => void;
  onSignedOut: () => void;
  onChanged: () => void;
  onDataChanged: () => void;
}) {
  const requests = useRef(new AbortController());
  useEffect(() => {
    requests.current = new AbortController();
    return () => requests.current.abort();
  }, []);
  function api<T>(
    path: string,
    method = "GET",
    body?: unknown,
    signal?: AbortSignal,
  ) {
    return requestApi<T>(path, method, body, signal || requests.current.signal);
  }
  const [tab, setTab] = useState("general");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [devices, setDevices] = useState<Device[]>([]);
  const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(
    null,
  );
  const [codes, setCodes] = useState<string[]>([]);
  const [copied, setCopied] = useState(false);
  useEffect(() => {
    if (tab === "devices") {
      const controller = new AbortController();
      api<Device[]>("tokens", "GET", undefined, controller.signal)
        .then(setDevices)
        .catch((e) => {
          if (e.name !== "AbortError") setError(e.message);
        });
      return () => controller.abort();
    }
  }, [tab]);
  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await action();
    } catch (e) {
      if (!requests.current.signal.aborted) setError((e as Error).message);
    } finally {
      if (!requests.current.signal.aborted) setBusy(false);
    }
  }
  function values(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    return Object.fromEntries(new FormData(event.currentTarget));
  }
  async function refresh() {
    onUpdate(await api<User>("me"));
  }
  return (
    <Dialog
      title="Settings"
      onClose={() => {
        if (!busy) onClose();
      }}
      wide
    >
      <div className="settings-layout">
        <nav className="settings-nav" aria-label="Account settings">
          {[
            { id: "general", title: "General", icon: UserRound },
            {
              id: "personalization",
              title: "Personalization",
              icon: UserRound,
            },
            { id: "data", title: "Data controls", icon: Download },
            { id: "profile", title: "Account", icon: UserRound },
            { id: "security", title: "Security", icon: ShieldCheck },
            { id: "devices", title: "Devices", icon: Monitor },
            { id: "developer", title: "Developer API", icon: KeyRound },
          ].map((item) => (
            <button
              key={item.id}
              className={tab === item.id ? "selected" : ""}
              aria-current={tab === item.id ? "page" : undefined}
              disabled={busy}
              onClick={() => {
                setTab(item.id);
                setError("");
                setNotice("");
              }}
            >
              <item.icon size={17} />
              {item.title}
            </button>
          ))}
        </nav>
        <div className="settings-content">
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
          {(tab === "general" || tab === "personalization") && (
            <SettingsPreferences
              key={tab}
              section={tab}
              onChanged={onChanged}
            />
          )}
          {tab === "developer" && (
            process.env.NEXT_PUBLIC_DEVELOPER_PORTAL_URL ? (
              <section className="form-stack">
                <h3>OpenMindAI Developer Portal</h3>
                <p className="muted">Developer registration, API keys, usage reports, model catalog and integration documentation now live in the dedicated Developer Portal.</p>
                <a className="secondary-button" href={process.env.NEXT_PUBLIC_DEVELOPER_PORTAL_URL} target="_blank" rel="noopener noreferrer">
                  Open Developer Portal
                </a>
              </section>
            ) : (
              <DeveloperApiSettings requiresTwoFactor={Boolean(user.two_factor_confirmed_at)} />
            )
          )}
          {tab === "data" && (
            <SettingsData
              user={user}
              onChanged={() => {
                onChanged();
                onDataChanged();
              }}
              onSignedOut={onSignedOut}
            />
          )}
          {tab === "profile" && (
            <>
              <h3>Your profile</h3>
              <ProfilePhoto user={user} onUpdate={onUpdate} />
              <p className="muted">{user.email}</p>
              <form
                className="form-stack"
                onSubmit={(e) => {
                  const data = values(e);
                  void run(async () => {
                    onUpdate(await api<User>("me", "PATCH", data));
                    setNotice("Profile updated.");
                  });
                }}
              >
                <label>
                  Name
                  <input
                    name="name"
                    defaultValue={user.name}
                    required
                    maxLength={120}
                  />
                </label>
                <button className="primary-button" disabled={busy}>
                  Save changes
                </button>
              </form>
              <hr />
              <h3>Email address</h3>
              <p className="muted">
                {user.email_verified_at
                  ? "Verified email"
                  : "Email verification pending"}
                : {user.email}
              </p>
              {user.pending_email && (
                <div className="manage-card">
                  <p>Pending confirmation: {user.pending_email}</p>
                  <button
                    disabled={busy}
                    onClick={() =>
                      void run(async () => {
                        await api("me/email", "DELETE");
                        await refresh();
                        setNotice("Email change cancelled.");
                      })
                    }
                  >
                    Cancel email change
                  </button>
                </div>
              )}
              <form
                className="form-stack"
                onSubmit={(e) => {
                  const data = values(e);
                  void run(async () => {
                    const result = await api<{ message: string }>(
                      "me/email",
                      "POST",
                      data,
                    );
                    await refresh();
                    setNotice(result.message);
                  });
                }}
              >
                <label>
                  New email
                  <input
                    name="email"
                    type="email"
                    required
                    maxLength={254}
                    autoComplete="email"
                  />
                </label>
                <IdentityFields twoFactor={!!user.two_factor_confirmed_at} />
                <small className="muted">
                  Your current address stays active until you confirm the link
                  sent to the new one. Confirmation signs out every device.
                </small>
                <button className="secondary-button" disabled={busy}>
                  Send confirmation link
                </button>
              </form>
              <hr />
              <h3>Change password</h3>
              <form
                className="form-stack"
                onSubmit={(e) => {
                  const data = values(e);
                  void run(async () => {
                    await api("me/password", "PUT", data);
                    onSignedOut();
                  });
                }}
              >
                <label>
                  Current password
                  <PasswordInput
                    name="current_password"
                    
                    autoComplete="current-password"
                    required
                  />
                </label>
                <label>
                  New password
                  <PasswordInput
                    name="password"
                    
                    autoComplete="new-password"
                    required
                    minLength={6}
                  />
                </label>
                <label>
                  Confirm new password
                  <PasswordInput
                    name="password_confirmation"
                    
                    autoComplete="new-password"
                    required
                    minLength={6}
                  />
                </label>
                <small className="muted">
                  At least 6 characters. You'll
                  be signed out on all devices.
                </small>
                <button className="secondary-button" disabled={busy}>
                  Update password
                </button>
              </form>
            </>
          )}
          {tab === "security" && (
            <>
              <h3>
                <ShieldCheck size={19} /> Two-factor authentication
              </h3>
              <p className="muted">
                {user.two_factor_confirmed_at
                  ? "Your account has an extra layer of protection."
                  : "Protect your account with an authenticator app."}
              </p>
              {!user.two_factor_confirmed_at && !setup && (
                <form
                  className="form-stack"
                  onSubmit={(e) => {
                    const data = values(e);
                    void run(async () => {
                      const result = await api<{
                        secret: string;
                        otpauth_url: string;
                      }>("2fa/setup", "POST", data);
                      setSetup({
                        secret: result.secret,
                        qr: await (
                          await import("qrcode")
                        ).default.toDataURL(result.otpauth_url, {
                          width: 200,
                          margin: 2,
                        }),
                      });
                    });
                  }}
                >
                  <label>
                    Confirm your password
                    <PasswordInput
                      name="password"
                      
                      required
                      autoComplete="current-password"
                    />
                  </label>
                  <button className="primary-button" disabled={busy}>
                    <KeyRound size={16} /> Set up authenticator
                  </button>
                </form>
              )}
              {setup && !user.two_factor_confirmed_at && (
                <div className="setup-box">
                  <p>Scan this QR code in your authenticator app.</p>
                  {/* Local QR generation keeps the secret on this device. */}
                  <img
                    src={setup.qr}
                    alt="Authenticator setup QR code"
                    width={180}
                    height={180}
                  />
                  <label>
                    Or enter this setup key
                    <code className="secret">{setup.secret}</code>
                  </label>
                  <form
                    className="form-stack"
                    onSubmit={(e) => {
                      const data = values(e);
                      void run(async () => {
                        const result = await api<{ recovery_codes: string[] }>(
                          "2fa/confirm",
                          "POST",
                          data,
                        );
                        setCodes(result.recovery_codes);
                        setSetup(null);
                        await refresh();
                      });
                    }}
                  >
                    <label>
                      Authenticator code
                      <input
                        name="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        required
                        pattern="[0-9]{6}"
                      />
                    </label>
                    <button className="primary-button" disabled={busy}>
                      Enable two-factor authentication
                    </button>
                  </form>
                </div>
              )}
              {codes.length > 0 && (
                <div className="recovery-box">
                  <h4>Save your recovery codes</h4>
                  <p className="muted">
                    Shown once. Keep them somewhere safe. Each code works once.
                  </p>
                  <div className="recovery-grid">
                    {codes.map((code) => (
                      <code key={code}>{code}</code>
                    ))}
                  </div>
                  <div className="button-row">
                    <button
                      className="secondary-button"
                      onClick={async () => {
                        try {
                          await navigator.clipboard.writeText(codes.join("\n"));
                          setCopied(true);
                        } catch {
                          setError(
                            "Clipboard unavailable. Download your codes instead.",
                          );
                        }
                      }}
                    >
                      {copied ? <Check size={16} /> : <Copy size={16} />}Copy
                    </button>
                    <button
                      className="secondary-button"
                      onClick={() => {
                        const url = URL.createObjectURL(
                          new Blob([codes.join("\n")], { type: "text/plain" }),
                        );
                        const link = document.createElement("a");
                        link.href = url;
                        link.download = "openmindai-recovery-codes.txt";
                        link.click();
                        setTimeout(() => URL.revokeObjectURL(url), 1000);
                      }}
                    >
                      <Download size={16} />
                      Download
                    </button>
                  </div>
                </div>
              )}
              {user.two_factor_confirmed_at && (
                <>
                  <form
                    className="form-stack"
                    onSubmit={(e) => {
                      const data = values(e);
                      void run(async () => {
                        const result = await api<{ recovery_codes: string[] }>(
                          "2fa/recovery-codes",
                          "POST",
                          data,
                        );
                        setCodes(result.recovery_codes);
                      });
                    }}
                  >
                    <label>
                      Password
                      <PasswordInput
                        name="password"
                        
                        required
                        autoComplete="current-password"
                      />
                    </label>
                    <label>
                      Authenticator or recovery code
                      <input
                        name="code"
                        autoComplete="one-time-code"
                        required
                        maxLength={64}
                      />
                    </label>
                    <button className="secondary-button" disabled={busy}>
                      Replace recovery codes
                    </button>
                  </form>
                  <hr />
                  <form
                    className="form-stack"
                    onSubmit={(e) => {
                      const data = values(e);
                      void run(async () => {
                        await api("2fa", "DELETE", data);
                        setCodes([]);
                        await refresh();
                        setNotice("Two-factor authentication disabled.");
                      });
                    }}
                  >
                    <label>
                      Password
                      <PasswordInput
                        name="password"
                        
                        required
                        autoComplete="current-password"
                      />
                    </label>
                    <label>
                      Authenticator or recovery code
                      <input
                        name="code"
                        autoComplete="one-time-code"
                        required
                      />
                    </label>
                    <button className="danger-button" disabled={busy}>
                      Disable two-factor authentication
                    </button>
                  </form>
                </>
              )}
            </>
          )}
          {tab === "devices" && (
            <>
              <h3>Signed-in devices</h3>
              <p className="muted">
                Revoke a device to sign it out. Revoking this browser will
                require signing in again.
              </p>
              {devices.length === 0 && (
                <p className="muted">No devices to display.</p>
              )}
              {devices.map((device) => (
                <div className="device-row" key={device.id}>
                  <Monitor size={19} />
                  <div>
                    <strong>
                      {device.name}
                      {device.current ? " (this device)" : ""}
                    </strong>
                    <small>
                      {device.last_used_at
                        ? new Date(device.last_used_at).toLocaleDateString()
                        : "Not used yet"}
                    </small>
                  </div>
                  <button
                    className="text-button danger-text"
                    disabled={busy}
                    onClick={() =>
                      void run(async () => {
                        await api(`tokens/${device.id}`, "DELETE");
                        setDevices(
                          devices.filter((item) => item.id !== device.id),
                        );
                        if (device.current) onSignedOut();
                      })
                    }
                  >
                    Revoke
                  </button>
                </div>
              ))}
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() =>
                  void run(async () => {
                    await api("logout-all", "POST");
                    onSignedOut();
                  })
                }
              >
                Sign out all devices
              </button>
            </>
          )}
          {busy && (
            <p className="muted loading-inline" role="status">
              <LoaderCircle size={16} className="spin" />
              Saving…
            </p>
          )}
        </div>
      </div>
    </Dialog>
  );
}
