"use client";
import { useEffect, useState } from "react";
import PasswordInput from "./password-input";
import { ArrowRight, LoaderCircle, Mail, ShieldCheck } from "lucide-react";
import Dialog from "./dialog";
import OtpCodeInput from "./otp-code-input";
import { api, type User } from "@/lib/api";
type Mode = "login" | "register" | "forgot" | "verify-email" | "reset";
export default function AuthDialog({
  onClose,
  onLogin,
}: {
  onClose: () => void;
  onLogin: (user: User) => void;
}) {
  const [mode, setMode] = useState<Mode>("login");
  const [registrationEnabled, setRegistrationEnabled] = useState(true);
  const [registrationEmailVerification, setRegistrationEmailVerification] = useState(false);
  const [ipChallengeId, setIpChallengeId] = useState<string | null>(null);
  useEffect(() => {
    let active = true;
    api<{ registration_enabled: boolean; registration_email_verification_enabled: boolean }>("public-config")
      .then((result) => {
        if (!active) return;
        setRegistrationEnabled(result.registration_enabled);
        setRegistrationEmailVerification(result.registration_email_verification_enabled);
        if (!result.registration_enabled) setMode((current) => current === "register" ? "login" : current);
      })
      .catch(() => { /* The server independently enforces registration policy. */ });
    return () => { active = false; };
  }, []);
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [showCode, setShowCode] = useState(false);
  function change(next: Mode) {
    setMode(next);
    setIpChallengeId(null);
    setShowCode(false);
    setError("");
    setNotice("");
  }
  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    setBusy(true);
    const data = Object.fromEntries(new FormData(event.currentTarget));
    if ((mode === "verify-email" || mode === "reset" || Boolean(ipChallengeId)) && !/^\d{6}$/.test(String(ipChallengeId ? data.ip_code : data.code ?? ""))) {
      setError("Enter all six digits of the security code.");
      setBusy(false);
      return;
    }
    try {
      if (mode === "login" && ipChallengeId) {
        const result = await api<{ user: User }>("login/verify-ip", "POST", {
          challenge_id: ipChallengeId,
          code: data.ip_code,
        });
        onLogin(result.user);
      } else if (mode === "login") {
        const result = await api<{ user?: User; verification_required?: boolean; challenge_id?: string }>("login", "POST", {
          ...data,
          device_name: "Openmindai Web",
        });
        if (result.verification_required && result.challenge_id) {
          setIpChallengeId(result.challenge_id);
          setNotice("A one-time code was sent to your verified email. Enter it below to finish signing in.");
        } else if (result.user) {
          onLogin(result.user);
        } else {
          throw new Error("The login response was incomplete.");
        }
      } else if (mode === "register") {
        const result = await api<{ user?: User; verification_required?: boolean }>("register", "POST", data);
        if (result.verification_required) {
          setMode("verify-email");
          setShowCode(false);
          setNotice("Enter the latest six-digit verification code sent to your email.");
        } else if (result.user) {
          onLogin(result.user);
        } else {
          throw new Error("The registration response was incomplete.");
        }
      } else if (mode === "forgot") {
        await api("forgot-password", "POST", data);
        setMode("reset");
        setNotice("If your account exists, enter the six-digit reset code sent to your email.");
      } else if (mode === "verify-email") {
        await api("email/verify-code", "POST", { email, code: data.code });
        change("login");
        setNotice("Email verified. You can now sign in.");
      } else if (mode === "reset") {
        await api("reset-password", "POST", { ...data, email });
        change("login");
        setNotice("Password updated. Sign in with your new password.");
      }
    } catch (e) {
      const message = e instanceof Error ? e.message : "Unable to continue.";
      setError(message);
      if (message.toLowerCase().includes("two-factor")) setShowCode(true);
      if (mode === "login" && /verify your email first/i.test(message)) {
        setMode("verify-email");
        setNotice("Your account is not verified. Enter the code from your email, or request a new one.");
      }
    } finally {
      setBusy(false);
    }
  }
  async function resend() {
    if (!email) {
      setError("Enter your email first.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      await api("email/resend", "POST", { email });
      setNotice("If eligible, a six-digit verification code has been sent.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog
      title={
        ipChallengeId
          ? "Verify new sign-in location"
          : mode === "verify-email" ? "Verify your email" : mode === "reset" ? "Set a new password" : mode === "register"
          ? "Create your account"
          : mode === "forgot"
            ? "Reset your password"
            : "Welcome back"
      }
      onClose={() => {
        if (!busy) onClose();
      }}
    >
      <p className="dialog-description">
        {ipChallengeId
          ? "We detected a login from a new network. Enter the six-digit email code to continue."
          : mode === "verify-email" || mode === "reset" ? "Enter the six-digit code from your email." : mode === "register"
          ? (registrationEmailVerification ? "Create an account, then verify your email address before signing in." : "A space for your ideas. Your conversations, saved in one place.")
          : mode === "forgot"
            ? "We'll send a six-digit reset code to your email address."
            : "Pick up where you left off."}
      </p>
      <form onSubmit={submit} className="form-stack">
        {mode === "register" && !ipChallengeId && (
          <label>
            Full name
            <input
              name="name"
              autoComplete="name"
              required
              maxLength={120}
              autoFocus
            />
          </label>
        )}
        {!ipChallengeId && <label>
          Email address
          <input
            name="email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoFocus={mode !== "register"}
          />
        </label>}
        {!ipChallengeId && (mode === "verify-email" || mode === "reset") && <OtpCodeInput key={mode + email} name="code" disabled={busy} />}
        {!ipChallengeId && mode === "reset" && <><label>New password<PasswordInput name="password"  autoComplete="new-password" minLength={6} required /></label><label>Confirm new password<PasswordInput name="password_confirmation"  autoComplete="new-password" minLength={6} required /></label></>}
        {!ipChallengeId && (mode === "login" || mode === "register") && (
          <label>
            Password
            <PasswordInput
              name="password"
              
              autoComplete={
                mode === "register" ? "new-password" : "current-password"
              }
              required
              minLength={mode === "register" ? 6 : undefined}
            />
          </label>
        )}
        {mode === "register" && !ipChallengeId && (
          <>
            <small className="muted">
              At least 6 characters.
            </small>
            <label>
              Confirm password
              <PasswordInput
                name="password_confirmation"
                
                autoComplete="new-password"
                required
                minLength={6}
              />
            </label>
          </>
        )}
        {mode === "login" && !ipChallengeId &&
          (showCode ? (
            <label>
              Authenticator or recovery code
              <input
                name="code"
                autoComplete="one-time-code"
                placeholder="6-digit code or recovery code"
                maxLength={64}
              />
            </label>
          ) : (
            <button
              className="text-button align-start"
              type="button"
              onClick={() => setShowCode(true)}
            >
              <ShieldCheck size={15} /> Use a two-factor code
            </button>
          ))}
        {ipChallengeId && <OtpCodeInput key={ipChallengeId} name="ip_code" disabled={busy} />}
        {error && (
          <p className="feedback error" role="alert">
            {error}
          </p>
        )}
        {notice && (
          <p className="feedback success" role="status">
            <Mail size={17} />
            {notice}
          </p>
        )}
        <button className="primary-button" disabled={busy}>
          {busy ? (
            <LoaderCircle className="spin" size={18} />
          ) : (
            <>
              {ipChallengeId
                ? "Verify and sign in"
                : mode === "register"
                ? "Create account"
                : mode === "forgot"
                  ? "Send reset code"
                  : mode === "verify-email" ? "Verify email" : mode === "reset" ? "Reset password"
                  : "Continue"}
              <ArrowRight size={17} />
            </>
          )}
        </button>
      </form>
      <div className="auth-links">
        {ipChallengeId ? (
          <button className="text-button" type="button" disabled={busy} onClick={() => change("login")}>Back to sign in</button>
        ) : mode === "verify-email" ? (
          <><button className="text-button" disabled={busy} type="button" onClick={resend}>Resend verification code</button><button className="text-button" disabled={busy} type="button" onClick={() => change("login")}>Back to sign in</button></>
        ) : mode === "login" ? (
          <>
            <button
              className="text-button"
              disabled={busy}
              onClick={() => change("forgot")}
            >
              Forgot password?
            </button>
            {registrationEnabled && <button
              className="text-button"
              disabled={busy}
              onClick={() => change("register")}
            >
              Create an account
            </button>}
            <button
              className="text-button muted"
              type="button"
              disabled={busy}
              onClick={() => { setMode("verify-email"); setError(""); setNotice("Enter your email and six-digit verification code, or request a new code."); }}
            >
              Verify email / resend code
            </button>
          </>
        ) : (
          <button
            className="text-button"
            disabled={busy}
            onClick={() => change("login")}
          >
            Back to sign in
          </button>
        )}
      </div>
    </Dialog>
  );
}
