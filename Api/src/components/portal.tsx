"use client";

import Link from "next/link";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { ArrowRight, BookOpen, Box, ChartColumnIncreasing, ChevronRight, Cpu, KeyRound, LogOut, Menu, ShieldCheck, Sparkles, X } from "lucide-react";
import { api, Developer } from "@/lib/api";
import Dashboard from "./dashboard";
import PasswordInput from "./password-input";
import Keys from "./keys";
import Catalog from "./catalog";
import Docs from "./docs";

export type View = "home" | "login" | "register" | "verify" | "forgot" | "dashboard" | "keys" | "models" | "docs";
const secureViews: View[] = ["dashboard", "keys", "models"];
const nav = [
  { href: "/dashboard", label: "Overview", icon: ChartColumnIncreasing },
  { href: "/keys", label: "API keys", icon: KeyRound },
  { href: "/models", label: "Model catalog", icon: Cpu },
  { href: "/docs", label: "Documentation", icon: BookOpen }
];

export default function Portal({ view }: { view: View }) {
  const router = useRouter();
  const [user, setUser] = useState<Developer | null>(null);
  const [ready, setReady] = useState(false);
  const [menu, setMenu] = useState(false);
  useEffect(() => { setMenu(false); }, [view]);
  useEffect(() => {
    let alive = true;
    api<Developer>("me").then((data) => {
      if (!alive) return;
      setUser(data);
      setReady(true);
      if (view === "login" || view === "register") router.replace("/dashboard");
    }).catch(() => {
      if (!alive) return;
      setUser(null);
      setReady(true);
      if (secureViews.includes(view)) router.replace("/login");
    });
    return () => { alive = false; };
  }, [view, router]);
  async function logout() {
    try { await api<unknown>("logout", "POST"); } finally {
      setUser(null);
      router.push("/login");
    }
  }
  return (
    <div className="site">
      <header className="topbar">
        <Link href="/" className="brand" aria-label="OpenMindAI Developer home">
          <span className="brand-mark"><Image className="brand-image" src="/openmindai-logo.png" width={38} height={38} alt="" priority /></span>
          <span>OpenMindAI <span className="brand-soft">Developers</span></span>
        </Link>
        <nav className="top-links" aria-label="Site navigation">
          <Link href="/docs">Documentation</Link>
          <Link href="/models">Models</Link>
          {user ? <Link href="/dashboard">Dashboard</Link> : <Link href="/login">Sign in</Link>}
          {!user && <Link className="button small primary" href="/register">Get API access <ArrowRight size={14}/></Link>}
          {user && <button type="button" className="button small ghost" onClick={logout}><LogOut size={15}/> Sign out</button>}
        </nav>
        <button type="button" className="menu-toggle" aria-label={menu ? "Close navigation" : "Open navigation"} aria-controls="developer-mobile-nav" aria-expanded={menu} onClick={() => setMenu(!menu)}>{menu ? <X/> : <Menu/>}</button>
      </header>
      {menu && <nav id="developer-mobile-nav" className="mobile-links" aria-label="Mobile navigation">{nav.filter((item) => !!user || item.href === "/docs").map((item) => <Link key={item.href} href={item.href} onClick={() => setMenu(false)}>{item.label}</Link>)}{user ? <button type="button" onClick={() => { setMenu(false); void logout(); }}>Sign out</button> : <><Link href="/login" onClick={() => setMenu(false)}>Sign in</Link><Link href="/register" onClick={() => setMenu(false)}>Create developer account</Link></>}</nav>}
      {(view === "home") && <Landing loggedIn={!!user}/>}
      {(view === "login" || view === "register" || view === "verify" || view === "forgot") && <Auth view={view}/>}
      {view === "docs" && <Docs/>}
      {secureViews.includes(view) && (!ready || !user ? <main className="loading" role="status">Checking your developer session…</main> :
        <div className="workspace">
          <aside className="sidebar" aria-label="Developer navigation">
            <p className="aside-caption">WORKSPACE</p>
            {nav.map((item) => <Link key={item.href} className={"side-link" + ("/" + view === item.href ? " selected" : "")} href={item.href}><item.icon size={17}/>{item.label}<ChevronRight className="side-arrow" size={14}/></Link>)}
            <div className="sidebar-user"><span className="avatar">{user.name.substring(0, 1).toUpperCase()}</span><span><b>{user.name}</b><small>Developer account</small></span></div>
          </aside>
          <div className="workspace-content">
            {view === "dashboard" && <Dashboard user={user}/>}
            {view === "keys" && <Keys user={user}/>}
            {view === "models" && <Catalog/>}
          </div>
        </div>)}
      <footer className="footer"><span>© {new Date().getFullYear()} OpenMindAI · Developer Platform</span><span>Server-managed credentials · Scoped API access</span></footer>
    </div>
  );
}

function Landing({ loggedIn }: { loggedIn: boolean }) {
  return <main className="landing">
    <div className="hero">
      <div className="eyebrow"><Sparkles size={14}/> OPENMINDAI DEVELOPER PLATFORM</div>
      <h1>Build intelligent software.<br/><span>One API, your next idea.</span></h1>
      <p className="hero-lead">Connect your editor or coding assistant to OpenMindAI. Generate scoped keys, explore coding capabilities and review your API usage.</p>
      <div className="button-row"><Link href={loggedIn ? "/dashboard" : "/register"} className="button primary">{loggedIn ? "Open developer dashboard" : "Start building"} <ArrowRight size={17}/></Link><Link href="/docs" className="button outline">Read API documentation <BookOpen size={17}/></Link></div>
      <div className="hero-note"><ShieldCheck size={17}/> Your keys stay private. Connect through this developer portal, not the backend.</div>
    </div>
    <div className="terminal-card">
      <div className="terminal-header"><span className="terminal-dots" aria-hidden="true"><i/><i/><i/></span> Your first API request <span className="language-tag">cURL</span></div>
      <pre>{'curl "$OPENMINDAI_BASE_URL/chat/completions" \\\n  -H "Authorization: Bearer $OPENMINDAI_API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d \'{"model":"openmindai-lite","messages":[{"role":"user","content":"Hello, OpenMindAI!"}]}\' '}</pre>
      <div className="terminal-bottom"><span className="dot"/> Private developer keys · OpenAI-compatible chat payload</div>
    </div>
    <section className="benefits"><article><div className="benefit-icon"><KeyRound/></div><h3>Credentials that stay yours</h3><p>Personal access keys have limited permissions, expire automatically and can be revoked instantly.</p></article><article><div className="benefit-icon"><Cpu/></div><h3>OpenMindAI coding agents</h3><p>Use OpenMindAI coding assistants for development workflows. Connection readiness is shown alongside each option.</p></article><article><div className="benefit-icon"><ChartColumnIncreasing/></div><h3>Usage you can understand</h3><p>Review coding agent activity and your monthly allocation without exposing underlying inference models.</p></article></section>
  </main>;
}

type AuthResult = { token?: string; user?: Developer; verification_required?: boolean; challenge_id?: string; message?: string };
function Auth({ view }: { view: "login" | "register" | "verify" | "forgot" }) {
  const router = useRouter();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [code, setCode] = useState("");
  const [challenge, setChallenge] = useState<string | null>(null);
  const [needs2FA, setNeeds2FA] = useState(false);
  const [canRegister, setCanRegister] = useState(true);
  const [emailVerification, setEmailVerification] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [cooldownRemaining, setCooldownRemaining] = useState(0);
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (view === "verify") {
      setEmail(params.get("email") || "");
      if (params.get("sent") === "1") {
        setNotice("Enter the six-digit code sent to your email.");
        setCooldownRemaining(120);
      }
    } else if (view === "login" && params.get("verified") === "1") {
      setNotice("Email verified. Sign in to continue.");
    }
  }, [view]);
  useEffect(() => {
    if (cooldownRemaining <= 0) return;
    const timer = window.setTimeout(() => setCooldownRemaining((remaining) => Math.max(0, remaining - 1)), 1000);
    return () => window.clearTimeout(timer);
  }, [cooldownRemaining]);
  useEffect(() => {
    api<{ registration_enabled: boolean; registration_email_verification_enabled: boolean }>("public-config")
      .then((x) => { setCanRegister(x.registration_enabled); setEmailVerification(x.registration_email_verification_enabled); }).catch(() => {});
  }, []);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(""); setNotice("");
    try {
      if (view === "forgot") {
        await api("forgot-password", "POST", { email });
        setNotice("If this address has an account, a password reset email has been sent.");
      } else if (view === "verify") {
        await api("email/verify-code", "POST", { email: email.trim(), code });
        router.replace("/login?verified=1");
      } else if (view === "register") {
        const result = await api<AuthResult>("register", "POST", { name, email, password, password_confirmation: confirm });
        if (result.verification_required) {
          router.replace(`/verify-email?email=${encodeURIComponent(email.trim())}&sent=1`);
        } else if (result.user) {
          router.replace("/dashboard"); router.refresh();
        } else throw new Error("Registration returned an incomplete response.");
      } else {
        const result = challenge
          ? await api<AuthResult>("login/verify-ip", "POST", { challenge_id: challenge, code })
          : await api<AuthResult>("login", "POST", { email, password, ...(code ? { code } : {}), device_name: "OpenMindAI Developer Portal" });
        if (result.verification_required && result.challenge_id) {
          setChallenge(result.challenge_id);
          setCode("");
          setNotice("Enter the security code emailed to you to finish signing in.");
        } else if (result.user) {
          router.replace("/dashboard"); router.refresh();
        } else throw new Error("Sign-in returned an incomplete response.");
      }
    } catch (e) {
      const text = e instanceof Error ? e.message : "Something went wrong.";
      if (text.toLowerCase().includes("two-factor")) setNeeds2FA(true);
      if (view === "login" && /verify your email first/i.test(text)) {
        router.push(`/verify-email?email=${encodeURIComponent(email.trim())}`);
      } else {
        setError(text);
      }
    } finally { setBusy(false); }
  }
  async function resendCode() {
    setBusy(true); setError(""); setNotice("");
    try {
      await api("email/resend", "POST", { email: email.trim() });
      setCode("");
      setCooldownRemaining(120);
      setNotice("A new six-digit verification code has been sent if your account is eligible.");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not resend the code.");
    } finally { setBusy(false); }
  }
  return <main className="auth-layout">
    <div className="auth-context"><div className="eyebrow"><ShieldCheck size={14}/> SECURE DEVELOPER ACCESS</div><h1>Your workspace for<br/><span>building with AI.</span></h1><p>Sign in with your OpenMindAI account to create private API keys, explore models and monitor usage.</p><div className="auth-points"><span><KeyRound size={17}/> One-time API key disclosure</span><span><Box size={17}/> OpenMindAI coding agents</span><span><ChartColumnIncreasing size={17}/> Coding agent usage</span></div></div>
    <section className="auth-card">
      <h2>{view === "login" ? (challenge ? "Verify your sign-in" : "Welcome back") : view === "register" ? "Create your developer account" : view === "verify" ? "Verify your email" : "Reset your password"}</h2>
      <p className="subtle">{view === "login" ? "Sign in to manage your integrations." : view === "register" ? "Start issuing keys for your projects." : view === "verify" ? "Enter the six-digit code from your inbox to activate your account." : "We'll send a password reset link if your account exists."}</p>
      {view === "register" && !canRegister && <p className="alert warning">Account registration is currently disabled by the site administrator.</p>}
      {notice && <p className="alert success" role="status">{notice}</p>}
      {error && <p className="alert error" role="alert">{error}</p>}
      <form onSubmit={submit} className="form">
        {view === "register" && <label>Full name<input value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} autoComplete="name" placeholder="Your full name"/></label>}
        {!challenge && <label>Email address<input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required maxLength={254} autoComplete="email" placeholder="you@company.com"/></label>}
        {(view === "login" || view === "register") && !challenge && <label>Password<PasswordInput value={password} onChange={(e) => setPassword(e.target.value)} required minLength={view === "register" ? 6 : undefined} autoComplete={view === "login" ? "current-password" : "new-password"} placeholder="Enter your password"/></label>}
        {view === "register" && <label>Confirm password<PasswordInput value={confirm} onChange={(e) => setConfirm(e.target.value)} required minLength={6} autoComplete="new-password" placeholder="Repeat your password"/></label>}
        {(view === "verify" || view === "login" && (challenge || needs2FA)) && <label>{view === "verify" ? "Email verification code" : challenge ? "Email security code" : "Authenticator or recovery code"}{(view === "verify" || challenge) ? <div className="otp-control"><input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))} required inputMode="numeric" pattern="[0-9]{6}" maxLength={6} autoComplete="one-time-code" aria-label="Six-digit email verification code"/><span className="otp-digits" aria-hidden="true">{Array.from({ length: 6 }, (_, i) => <span key={i}>{code[i] || ""}</span>)}</span></div> : <input value={code} onChange={(e) => setCode(e.target.value)} maxLength={64} autoComplete="one-time-code" placeholder="Authenticator or recovery code"/>}</label>}
        {view === "register" && emailVerification && <small>You'll need to verify your email before signing in.</small>}
        <button className="button primary full" disabled={busy || view === "register" && !canRegister} type="submit">{busy ? "Please wait…" : view === "login" ? (challenge ? "Verify and sign in" : "Sign in") : view === "register" ? "Create account" : view === "verify" ? "Verify email" : "Send reset email"} <ArrowRight size={16}/></button>
      </form>
      <div className="auth-footer">{view === "verify" ? <><button type="button" className="button ghost small" disabled={busy || cooldownRemaining > 0 || !email} onClick={resendCode}>Resend code{cooldownRemaining > 0 ? ` (${Math.floor(cooldownRemaining / 60)}:${String(cooldownRemaining % 60).padStart(2, "0")})` : ""}</button><span><Link href="/login">Back to sign in</Link></span></> : view === "login" ? <><Link href="/forgot-password">Forgot password?</Link><span>New here? <Link href="/register">Create an account</Link></span></> : <span>Already registered? <Link href="/login">Sign in</Link></span>}</div>
    </section>
  </main>;
}
