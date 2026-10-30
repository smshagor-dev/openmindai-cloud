import { readFileSync } from "node:fs";
import assert from "node:assert/strict";
import { test } from "node:test";

const read = (path) => readFileSync(new URL("../" + path, import.meta.url), "utf8");
const portal = read("src/components/portal.tsx");
const bridge = read("src/app/api/bridge/[...path]/route.ts");

test("successful registration opens developer email verification instead of leaving an informational notice", () => {
  assert.match(read("src/app/verify-email/page.tsx"), /<Portal view="verify"/);
  assert.match(portal, /if \(result\.verification_required\)\s*\{\s*router\.replace\(`\/verify-email\?email=/);
  assert.match(portal, /view === "verify" \|\| view === "forgot"/);
  assert.match(portal, /view === "verify" \? "Verify your email"/);
});

test("email verification and resend are allowed without a developer session", () => {
  assert.match(bridge, /POST: \/\^\(register\|login\|login\\\/verify-ip\|email\\\/resend\|email\\\/verify-code/);
  assert.match(bridge, /const publicRoutes = new Set\(\[[^\]]*"email\/verify-code"/);
  assert.match(portal, /api\("email\/verify-code", "POST", \{ email: email\.trim\(\), code \}\)/);
  assert.match(portal, /api\("email\/resend", "POST", \{ email: email\.trim\(\) \}\)/);
});

test("six-digit code entry and resend countdown are kept in the verification form", () => {
  assert.match(portal, /view === "verify" \|\| view === "login"/);
  assert.match(portal, /inputMode="numeric" pattern="\[0-9\]\{6\}"/);
  assert.match(portal, /setCooldownRemaining\(120\)/);
  assert.match(portal, /disabled=\{busy \|\| cooldownRemaining > 0 \|\| !email\}/);
  assert.match(portal, /router\.replace\("\/login\?verified=1"\)/);
  assert.match(portal, /verify your email first/i);
});
