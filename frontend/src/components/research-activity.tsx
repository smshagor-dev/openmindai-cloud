"use client";
import { useState } from "react";
import {
  BookOpen,
  Check,
  ChevronDown,
  ExternalLink,
  Globe,
  Search,
  ShieldCheck,
} from "lucide-react";
import type { ResearchMetadata } from "@/lib/research-status";
import type { Source } from "@/lib/research-loop";
import { evidenceUrl } from "@/lib/research-evidence";
import "./research-activity.css";
const stages = [
  ["planning", "Plan"],
  ["searching", "Search"],
  ["reading", "Read"],
  ["analyzing", "Compare"],
  ["verifying", "Check"],
  ["writing", "Write"],
] as const;
export default function ResearchActivity({
  metadata,
  sources = [],
  messageStatus,
}: {
  metadata?: ResearchMetadata | null;
  sources?: Source[];
  messageStatus?: string;
}) {
  const [expanded, setExpanded] = useState(false);
  if (!metadata) return null;
  if (
    ["failed", "cancelled"].includes(messageStatus || "") &&
    ["running", "writing"].includes(metadata.status)
  )
    metadata = {
      ...metadata,
      status: messageStatus === "cancelled" ? "cancelled" : "failed",
    };
  const running = ["running", "writing"].includes(metadata.status);
  const latest = metadata.activity.at(-1);
  const checked =
    metadata.audit?.filter((a) => a.status !== "passage_match") || [];
  return (
    <section className="web-investigation" aria-label="Research activity">
      <button
        type="button"
        className="web-investigation-heading"
        aria-expanded={expanded}
        onClick={() => setExpanded((v) => !v)}
      >
        <span
          className={running ? "web-research-mark active" : "web-research-mark"}
        >
          {running ? (
            <Search size={16} />
          ) : metadata.status === "complete" ? (
            <Check size={16} />
          ) : (
            <BookOpen size={16} />
          )}
        </span>
        <span>
          <strong>
            {metadata.status === "complete"
              ? "Research report ready"
              : metadata.status === "paused"
                ? "Research paused · resume available"
                : metadata.status === "partial"
                  ? "Report ready · evidence gaps remain"
                  : metadata.status === "failed"
                    ? "Research interrupted"
                    : metadata.status === "cancelled"
                      ? "Research stopped"
                      : "Researching your question"}
          </strong>
          <small>
            {running
              ? latest?.label || "Preparing the investigation"
              : `${sources.length} sources · ${sources.filter((s) => s.read_status === "read").length} pages read${checked.length ? ` · ${checked.length} checks need review` : ""}`}
          </small>
        </span>
        <ChevronDown size={15} className={expanded ? "rotated" : ""} />
      </button>
      <div
        className="web-research-stages"
        aria-label="Observed research stages"
      >
        {stages.map(([phase, label]) => {
          const observed = metadata.activity.some((e) => e.phase === phase);
          const active = running && latest?.phase === phase;
          return (
            <span
              key={phase}
              className={active ? "active" : observed ? "observed" : ""}
              aria-current={active ? "step" : undefined}
            >
              {label}
            </span>
          );
        })}
      </div>
      {expanded && (
        <div className="web-research-details">
          {metadata.plan.length > 0 && (
            <div className="web-research-objectives">
              <h4>Research questions</h4>
              {metadata.plan.map((o) => (
                <div key={o.id}>
                  <span className={o.status === "supported" ? "checked" : ""}>
                    {o.status === "supported" ? (
                      <Check size={13} />
                    ) : (
                      <span>•</span>
                    )}
                  </span>
                  <p dir="auto">
                    {o.text}
                    <small>
                      {o.status === "supported"
                        ? "Planner linked inspected evidence; review cited passages"
                        : o.status === "unresolved"
                          ? "Evidence gap remains"
                          : "Under investigation"}
                    </small>
                  </p>
                </div>
              ))}
            </div>
          )}
          <h4>Browsing activity</h4>
          <ol className="web-research-timeline">
            {metadata.activity.slice(-60).map((e) => (
              <li key={e.id}>
                <span className={e.status === "failed" ? "failed" : ""}>
                  {e.phase === "searching" ? (
                    <Search size={13} />
                  ) : e.phase === "reading" ? (
                    <Globe size={13} />
                  ) : (
                    <ShieldCheck size={13} />
                  )}
                </span>
                <div>
                  <strong>{e.label}</strong>
                  {e.query && <p dir="auto">{e.query}</p>}
                  {e.url && evidenceUrl(e.url) && (
                    <a
                      href={evidenceUrl(e.url)!}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {e.url}
                      <ExternalLink size={10} />
                    </a>
                  )}
                  <small>
                    {new Date(e.at).toLocaleTimeString()} · {e.status}
                    {e.provider ? ` · ${e.provider}` : ""}
                  </small>
                </div>
              </li>
            ))}
          </ol>
          {!!metadata.limitations?.length && (
            <div className="web-research-limitations">
              <h4>Evidence limitations</h4>
              {metadata.limitations.map((l, i) => (
                <p key={i}>{l}</p>
              ))}
            </div>
          )}
          {!!metadata.audit?.length && (
            <details className="web-claim-audit">
              <summary>
                Passage and numeric checks · {checked.length} need review
              </summary>
              <p>
                These are automated evidence checks, not a guarantee of factual
                correctness. A matching passage does not prove semantic
                entailment.
              </p>
              {metadata.audit.slice(0, 80).map((claim, i) => (
                <article key={i}>
                  <span
                    className={
                      claim.status === "passage_match" ? "match" : "review"
                    }
                  >
                    {claim.status.replaceAll("_", " ")}
                  </span>
                  <p dir="auto">{claim.text}</p>
                  <small>
                    {claim.passages.length
                      ? "Matched passages: " + claim.passages.join(", ")
                      : "Inspect the original sources before relying on this claim."}
                  </small>
                </article>
              ))}
            </details>
          )}
        </div>
      )}
    </section>
  );
}
