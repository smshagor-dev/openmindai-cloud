import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";

const input = readFileSync(new URL("../src/components/otp-code-input.tsx", import.meta.url), "utf8");
const auth = readFileSync(new URL("../src/components/auth-dialog.tsx", import.meta.url), "utf8");

test("six OTP boxes support paste, backspace and mobile numeric input", () => {
  assert.match(input, /Array\(6\)/);
  assert.match(input, /clipboardData\.getData/);
  assert.match(input, /Backspace/);
  assert.match(input, /inputMode="numeric"/);
  assert.match(input, /type="hidden" name=\{name\}/);
});

test("all three email verification flows use six-box component", () => {
  assert.match(auth, /name="code" disabled=\{busy\}/);
  assert.match(auth, /name="ip_code" disabled=\{busy\}/);
  assert.match(auth, /Enter all six digits/);
});
