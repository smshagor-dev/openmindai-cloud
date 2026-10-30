"use client";
import { useState } from "react";
import PasswordInput from "./password-input";
import Link from "next/link";
import { ArrowLeft, LoaderCircle, Sparkles } from "lucide-react";
import { api } from "@/lib/api";
export default function ResetPassword({
  token,
  email,
}: {
  token: string;
  email: string;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [done, setDone] = useState(false);
  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.currentTarget));
    setBusy(true);
    setError("");
    try {
      await api("reset-password", "POST", { ...data, token });
      setDone(true);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <main className="reset-page">
      <div className="reset-card">
        <div className="welcome-mark">
          <Sparkles size={25} />
        </div>
        <h1>{done ? "You're all set" : "A fresh start"}</h1>
        <p className="dialog-description">
          {done
            ? "Your password has been changed. Sign in with your new password."
            : "Choose a new password for your account."}
        </p>
        {!done &&
          (token ? (
            <form className="form-stack" onSubmit={submit}>
              <label>
                Email address
                <input
                  name="email"
                  type="email"
                  defaultValue={email}
                  autoComplete="email"
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
                Confirm password
                <PasswordInput
                  name="password_confirmation"
                  
                  autoComplete="new-password"
                  required
                  minLength={6}
                />
              </label>
              <small className="muted">
                At least 6 characters.
              </small>
              {error && (
                <p className="feedback error" role="alert">
                  {error}
                </p>
              )}
              <button className="primary-button" disabled={busy}>
                {busy ? (
                  <LoaderCircle className="spin" size={18} />
                ) : (
                  "Reset password"
                )}
              </button>
            </form>
          ) : (
            <p className="feedback error" role="alert">
              This reset link is incomplete. Request a new one from the sign-in
              screen.
            </p>
          ))}
        <Link className="back-link" href="/">
          <ArrowLeft size={15} />
          Back to your workspace
        </Link>
      </div>
    </main>
  );
}
