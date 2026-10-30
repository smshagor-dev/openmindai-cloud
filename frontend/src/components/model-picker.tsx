"use client";
import { useEffect, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { Check, ChevronDown, Search, Sparkles, Cpu, X } from "lucide-react";
import type { Model } from "@/lib/api";

export default function ModelPicker({ models, value, onChange, disabled }: {
  models: Model[]; value: number | null; onChange: (id: number) => void; disabled: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const trigger = useRef<HTMLButtonElement>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  const search = useRef<HTMLInputElement>(null);
  const titleId = useId();
  const selected = models.find((model) => model.id === value);
  const filtered = models.filter((model) => model.name.toLowerCase().includes(query.trim().toLowerCase()));
  function close() { setOpen(false); setQuery(""); trigger.current?.focus(); }
  useEffect(() => {
    if (open) { dialog.current?.showModal(); search.current?.focus(); }
  }, [open]);
  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);
  return <>
    <button ref={trigger} type="button" className="model-picker-trigger" disabled={disabled}
      aria-label={`Choose model: ${selected?.name || "OpenMindAI Auto"}`} aria-haspopup="dialog" aria-expanded={open}
      onClick={() => setOpen(true)}>
      <span>{selected?.name || "OpenMindAI Auto"}</span><ChevronDown size={14} aria-hidden="true" />
    </button>
    {open && createPortal(<dialog ref={dialog} className="model-picker-dialog" aria-labelledby={titleId}
      onCancel={(event) => { event.preventDefault(); close(); }}
      onClick={(event) => { if (event.target === event.currentTarget) close(); }}>
      <header className="model-picker-header"><div><h2 id={titleId}>Choose your model</h2><p>One place for every OpenMindAI chat model.</p></div>
        <button type="button" className="icon-button" aria-label="Close model selection" onClick={close}><X size={18} /></button></header>
      <label className="model-picker-search"><Search size={17} aria-hidden="true" /><input ref={search} value={query}
        onChange={(event) => setQuery(event.target.value)} placeholder="Search models" aria-label="Search models" /></label>
      <div className="model-picker-options" role="group" aria-label="Chat models" onKeyDown={(event) => {
        if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
        const buttons = Array.from(event.currentTarget.querySelectorAll<HTMLButtonElement>("button"));
        if (!buttons.length) return;
        event.preventDefault();
        const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
        const next = event.key === "Home" ? 0 : event.key === "End" ? buttons.length - 1 :
          (index + (event.key === "ArrowDown" ? 1 : -1) + buttons.length) % buttons.length;
        buttons[next].focus();
      }}>
        {filtered.map((model) => <button type="button" key={model.id} className={`model-picker-option${value === model.id ? " is-selected" : ""}`}
          aria-pressed={value === model.id} onClick={() => { onChange(model.id); close(); }}>
          <span className="model-picker-icon">{model.id === -4 ? <Sparkles size={19} /> : <Cpu size={19} />}</span>
          <span className="model-picker-copy"><strong>{model.name}</strong><small>{model.id === -4 ? "Let OpenMindAI choose for your request" : model.id === -1 ? "Quick answers for everyday tasks" : model.id === -2 ? "Coding, images and detailed answers" : model.id === -3 ? "Complex questions and deeper reasoning" : "Chat with this model"}</small></span>
          {value === model.id && <Check size={18} className="model-picker-check" aria-hidden="true" />}
        </button>)}
        {!filtered.length && <p className="model-picker-empty">No models match your search.</p>}
      </div>
      <footer className="model-picker-footer">{models.length} options · Managed by OpenMindAI</footer>
    </dialog>, document.body)}
  </>;
}
