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
import {
  activeResearchOperations,
  evidenceCount,
} from "@/lib/research-sources";
import SiteIcon, { siteHost } from "./site-icon";
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
  const active = running ? activeResearchOperations(metadata.activity) : [];
  const web = metadata.mode === "web_search";
  const checked =
    metadata.audit?.filter((a) => a.status !== "passage_match") || [];
  // One icon per website; a site counts as read if any of its pages was read.
  const sites = [...sources.reduce((all, source) => {
    const host = siteHost(source.url);
    if (host) all.set(host, { url: source.url, read: all.get(host)?.read || source.read_status === "read" });
    return all;
  }, new Map<string, { url: string; read: boolean }>())].sort((a, b) => Number(b[1].read) - Number(a[1].read));
  const pagesRead = sources.filter((source) => source.read_status === "read").length;
  // One compact line, as in ChatGPT/Claude: what is happening now, or a summary.
  const live = active.at(-1) || latest;
  const liveHost = siteHost(live?.url);
  const siteCount = `${sites.length} ${sites.length === 1 ? "site" : "sites"}`;
  const line = running
    ? liveHost
      ? `Reading ${liveHost}`
      : live?.query
        ? `Searching “${live.query}”`
        : live?.label || (web ? "Searching the web" : "Researching your question")
    : web
      ? metadata.status === "failed"
        ? "Web search interrupted"
        : metadata.status === "cancelled"
          ? "Web search stopped"
          : sites.length
            ? `Searched ${siteCount}${metadata.status === "partial" ? " · limited evidence" : ""}`
            : "Searched the web"
      : metadata.status === "complete"
        ? `Research report ready${sites.length ? ` · ${siteCount}` : ""}`
        : metadata.status === "paused"
          ? "Research paused · resume available"
          : metadata.status === "partial"
            ? "Report ready · evidence gaps remain"
            : metadata.status === "failed"
              ? "Research interrupted"
              : metadata.status === "cancelled"
                ? "Research stopped"
                : "Researching your question";
  return (
    <section className={`web-investigation${expanded ? " expanded" : ""}`} aria-label="Research activity">
      <button
        type="button"
        className="web-investigation-heading"
        aria-expanded={expanded}
        onClick={() => setExpanded((v) => !v)}
      >
        {sites.length > 0 ? (
          <span className="web-site-stack" aria-hidden="true">
            {(running && liveHost ? [[liveHost, { url: live!.url! }] as const, ...sites.filter(([host]) => host !== liveHost)] : sites)
              .slice(0, 4)
              .map(([host, site]) => (
                <span key={host}><SiteIcon url={site.url} size={14} /></span>
              ))}
          </span>
        ) : (
          <span className={running ? "web-research-mark active" : "web-research-mark"} aria-hidden="true">
            {running ? <Search size={14} /> : metadata.status === "complete" ? <Check size={14} /> : <BookOpen size={14} />}
          </span>
        )}
        <span className={running ? "web-investigation-line shimmer-text" : "web-investigation-line"} dir="auto" aria-live={running ? "polite" : undefined}>
          {line}
        </span>
        <ChevronDown size={15} className={expanded ? "rotated" : ""} aria-hidden="true" />
      </button>
      {expanded && (
        <div className="web-research-summary">
          <small>
            {web ? "Web search" : metadata.mode === "deep_research" ? "Deep research" : "Research"} ·{" "}
            {evidenceCount(sources.length, "source")} · {evidenceCount(pagesRead, "page")} read
            {checked.length ? ` · ${checked.length} checks need review` : ""}
            {running && active.length > 1 ? ` · ${active.length} requests in parallel` : ""}
          </small>
          {sites.length > 0 && (
            <ul className="web-research-sites" aria-label="Websites checked">
              {sites.map(([host, site]) => (
                <li key={host} className={site.read ? "read" : ""} title={site.read ? "Page read" : "Search result"}>
                  <SiteIcon url={site.url} size={14} />
                  {host}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
      {expanded && (
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
      )}
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
                    e.url ? <SiteIcon url={e.url} size={13} /> : <Globe size={13} />
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
