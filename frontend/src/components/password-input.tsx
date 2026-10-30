"use client";
import { forwardRef, useState, type InputHTMLAttributes } from "react";
import { Eye, EyeOff } from "lucide-react";

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, "type">;
const PasswordInput = forwardRef<HTMLInputElement, Props>(function PasswordInput(props, ref) {
  const [visible, setVisible] = useState(false);
  return <span style={{ position: "relative", display: "block", width: "100%" }}>
    <input {...props} ref={ref} type={visible ? "text" : "password"} style={{ ...props.style, paddingRight: 48, width: "100%" }} />
    <button type="button" aria-label={visible ? "Hide password" : "Show password"} aria-pressed={visible}
      onClick={() => setVisible(value => !value)}
      style={{ position: "absolute", right: 10, top: "50%", transform: "translateY(-50%)", background: "transparent", border: 0, cursor: "pointer", display: "grid", placeItems: "center", padding: 6, color: "inherit" }}>
      {visible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
    </button>
  </span>;
});
export default PasswordInput;
