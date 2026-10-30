import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";

const proxy = readFileSync(new URL("../src/app/api/backend/[...path]/route.ts", import.meta.url), "utf8");
const dialog = readFileSync(new URL("../src/components/auth-dialog.tsx", import.meta.url), "utf8");

test("verification code endpoint is allowed for unauthenticated users", () => {
  assert.match(proxy, /"email\/verify-code"/);
  const post = proxy.match(/POST:\s*\/\^([\s\S]*?)\$\//)?.[1];
  assert.ok(post, "POST endpoint allowlist must exist");
  assert.ok(post.includes("email\\/verify-code"), "POST must permit email/verify-code");
});

test("unverified user can reopen the verification form", () => {
  assert.match(dialog, /Verify email \/ resend code/);
  assert.match(dialog, /verify your email first/i);
  assert.match(dialog, /"email\/verify-code", "POST"/);
});
