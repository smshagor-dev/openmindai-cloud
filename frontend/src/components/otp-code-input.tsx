"use client";

import { useRef, useState } from "react";
import type { ClipboardEvent, KeyboardEvent } from "react";

export default function OtpCodeInput({ name = "code", disabled = false }: { name?: string; disabled?: boolean }) {
  const [digits, setDigits] = useState<string[]>(Array(6).fill(""));
  const inputs = useRef<Array<HTMLInputElement | null>>([]);
  function apply(value: string, start: number) {
    const numbers = value.replace(/\D/g, "").slice(0, 6 - start).split("");
    const next = [...digits];
    numbers.forEach((digit, offset) => { next[start + offset] = digit; });
    if (!numbers.length) next[start] = "";
    setDigits(next);
    inputs.current[Math.min(5, start + Math.max(1, numbers.length))]?.focus();
  }
  function keyDown(event: KeyboardEvent<HTMLInputElement>, index: number) {
    if (event.key === "Backspace" && !digits[index] && index > 0) {
      const next = [...digits]; next[index - 1] = ""; setDigits(next);
      inputs.current[index - 1]?.focus();
    }
    if (event.key === "ArrowLeft" && index > 0) inputs.current[index - 1]?.focus();
    if (event.key === "ArrowRight" && index < 5) inputs.current[index + 1]?.focus();
  }
  function paste(event: ClipboardEvent<HTMLInputElement>, index: number) {
    event.preventDefault();
    apply(event.clipboardData.getData("text"), index);
  }
  return (
    <div>
      <label htmlFor={name + "-0"}>Six-digit email code</label>
      <div style={{ display: "flex", gap: "8px", justifyContent: "center", marginTop: "8px" }}>
        {digits.map((digit, index) => (
          <input key={index} ref={element => { inputs.current[index] = element; }}
            id={name + "-" + index} aria-label={"Digit " + (index + 1) + " of 6"}
            value={digit} disabled={disabled} inputMode="numeric" pattern="[0-9]*"
            autoComplete={index === 0 ? "one-time-code" : "off"}
            onChange={event => apply(event.target.value, index)}
            onKeyDown={event => keyDown(event, index)}
            onPaste={event => paste(event, index)}
            maxLength={6} style={{ width: "min(44px, 14%)", height: "50px", textAlign: "center", fontSize: "22px", fontWeight: 600, padding: "4px", borderRadius: "9px" }}
          />
        ))}
      </div>
      <input type="hidden" name={name} value={digits.join("")} />
    </div>
  );
}
