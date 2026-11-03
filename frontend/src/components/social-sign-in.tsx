"use client";

import { useState } from "react";
import Link from "next/link";
import { readApiJson } from "@/lib/api";
import OtpCodeInput from "./otp-code-input";

const messages: Record<string, string> = {
  expired: "This sign-in request expired. Start again.",
  cancelled: "The provider did not complete sign-in.",
  unavailable: "Social sign-in could not be completed. Try again or sign in with email.",
  "existing-account": "Sign in to your existing OpenMindAI account, then link the provider under Settings → Security.",
  "sign-in-required": "Sign in to your OpenMindAI account before linking a provider.",
};

export default function SocialSignIn({
  provider, initialStep, initialError, linked,
}: { provider: string; initialStep: string; initialError: string; linked: boolean }) {
  const [step, setStep] = useState(initialStep);
  const [error, setError] = useState(messages[initialError] || "");
  const [busy, setBusy] = useState(false);
  const showForm = ["google", "facebook"].includes(provider) && ["email", "two-factor", "ip"].includes(step);
  return (
    <main className="shared-page">
      <header><Link href="/">OpenMindAI</Link></header>
      <h1>{linked ? "Sign-in provider linked" : "Finish signing in"}</h1>
      {showForm && (
        <form className="form-stack" key={step} onSubmit={async event => {
          event.preventDefault();
          const code = new FormData(event.currentTarget).get("code");
          setBusy(true);
          setError("");
          try {
            const response = await fetch(`/api/auth/social/${provider}/complete`, {
              method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ code }),
            });
            const result = await readApiJson<{ signedIn: boolean; step?: string }>(response, "social sign-in");
            if (result.signedIn) window.location.assign("/");
            else if (result.step) setStep(result.step);
            else setError("Could not finish signing in. Start again.");
          } catch (e) {
            setError((e as Error).message);
          } finally {
            setBusy(false);
          }
        }}>
          <p>{step === "ip" ? "Enter the email code for this new sign-in location." :
            step === "email" ? "Enter the code sent to your email address." : "Verify your two-factor authentication."}</p>
          {step === "two-factor" ? (
            <label>Authenticator or recovery code<input name="code" autoComplete="one-time-code" maxLength={64} required /></label>
          ) : <OtpCodeInput disabled={busy} />}
          <button className="primary-button" disabled={busy}>Verify and continue</button>
        </form>
      )}
      {error && <p className="feedback error" role="alert">{error}</p>}
      <Link href="/">Back to OpenMindAI</Link>
    </main>
  );
}
