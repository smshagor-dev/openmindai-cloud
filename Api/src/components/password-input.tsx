"use client";
import { useState, type InputHTMLAttributes } from "react";
import { Eye, EyeOff } from "lucide-react";

export default function PasswordInput(props: Omit<InputHTMLAttributes<HTMLInputElement>, "type">) {
  const [visible, setVisible] = useState(false);
  return <span style={{ position: "relative", display: "block", width: "100%" }}>
    <input {...props} type={visible ? "text" : "password"} style={{ ...props.style, width: "100%", paddingRight: 48 }} />
    <button type="button" aria-label={visible ? "Hide password" : "Show password"} aria-pressed={visible}
      onClick={() => setVisible(value => !value)}
      style={{ position: "absolute", right: 10, top: "50%", transform: "translateY(-50%)", border: 0, background: "transparent", cursor: "pointer", padding: 6, display: "grid", placeItems: "center", color: "inherit" }}>
      {visible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
    </button>
  </span>;
}
