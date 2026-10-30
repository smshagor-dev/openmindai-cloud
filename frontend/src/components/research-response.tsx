"use client";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowUpRight,
  BookOpen,
  ChevronRight,
  FileText,
  Search,
  ShieldAlert,
} from "lucide-react";
import type { Source } from "@/lib/research-loop";
import type { ResearchMetadata } from "@/lib/research-status";
import { evidenceUrl } from "@/lib/research-evidence";
import {
  filterResearchSources,
  sourceDomain,
  researchAnswerBody,
  evidenceCount,
} from "@/lib/research-sources";
import ResponseMarkdown from "./response-markdown";
import ResearchActivity from "./research-activity";
import Dialog from "./dialog";
import "./research-sources.css";
const emptySources: Source[] = [];
export default function ResearchResponse({
  content,
  sources = emptySources,
  metadata,
  status,
  onRendered,
  animate = false,
  streaming = false,
  reducedMotion = false,
  statusLabel,
}: {
  content: string;
  sources?: Source[];
  metadata?: ResearchMetadata | null;
  status?: string;
  onRendered?: () => void;
  animate?: boolean;
  streaming?: boolean;
  reducedMotion?: boolean;
  statusLabel?: string;
}) {
  const [drawer, setDrawer] = useState(false),
    [selected, setSelected] = useState<string | null>(null),
    [query, setQuery] = useState(""),
    [filter, setFilter] = useState<"all" | "read" | "limited">("all");
  const selectedRef = useRef<HTMLElement>(null);
  useEffect(() => {
    if (drawer && selected)
      selectedRef.current?.scrollIntoView({ block: "nearest" });
  }, [drawer, selected]);
  const safeSources = useMemo(
    () => sources.filter((source) => evidenceUrl(source.url)),
    [sources],
  );
  const filtered = useMemo(
    () => filterResearchSources(safeSources, query, filter),
    [safeSources, query, filter],
  );
  const openSource = useCallback((source?: Source) => {
    setSelected(source?.url || null);
    setQuery("");
    setFilter("all");
    setDrawer(true);
  }, []);
  const closeDrawer = useCallback(() => setDrawer(false), []);
  const read = safeSources.filter(
    (source) => source.read_status === "read",
  ).length;
  return (
    <div className="web-research-response">
      <ResearchActivity
        metadata={metadata}
        sources={safeSources}
        messageStatus={status}
      />
      <ResponseMarkdown
        content={researchAnswerBody(
          content,
          metadata?.answer_body_length,
          status === "complete",
        )}
        sources={safeSources}
        onSourceOpen={openSource}
        onRendered={onRendered}
        animate={animate && status !== "complete"}
        streaming={streaming}
        reducedMotion={reducedMotion}
        statusLabel={statusLabel}
      />
      {safeSources.length > 0 && (
        <div className="web-source-summary" aria-label="Answer sources">
          <div className="web-source-summary-heading">
            <span>
              <BookOpen size={14} />
              {safeSources.length}{" "}
              {safeSources.length === 1 ? "source" : "sources"}
              <small>{evidenceCount(read, "page")} read</small>
            </span>
            <button
              type="button"
              onClick={() => openSource()}
              aria-label="View all sources"
            >
              View all
              <ChevronRight size={14} />
            </button>
          </div>
          <div className="web-source-preview-grid">
            {safeSources.slice(0, 3).map((source, index) => (
              <button
                type="button"
                className="web-source-preview"
                key={source.url}
                onClick={() => openSource(source)}
                aria-label={`Inspect source ${source.citation || index + 1}: ${source.title}`}
              >
                <span className="web-source-domain">
                  <span className="web-domain-icon" aria-hidden="true">
                    {sourceDomain(source).slice(0, 1).toUpperCase()}
                  </span>
                  {sourceDomain(source)}
                  <span className="web-source-number">
                    {source.citation || index + 1}
                  </span>
                </span>
                <strong dir="auto">{source.title}</strong>
                <small>
                  {source.read_status === "read"
                    ? source.mime === "application/pdf"
                      ? "PDF passages"
                      : "Page inspected"
                    : source.read_status === "unavailable"
                      ? "Page unavailable"
                      : "Search excerpt"}
                </small>
              </button>
            ))}
          </div>
        </div>
      )}
      {drawer && (
        <Dialog
          title="Sources"
          onClose={closeDrawer}
          className="web-sources-dialog"
        >
          <div className="web-source-drawer-intro">
            <p>Inspect the evidence behind this answer.</p>
            <span>
              {evidenceCount(safeSources.length, "source")} ·{" "}
              {evidenceCount(read, "page")} read
            </span>
          </div>
          <label className="web-source-search">
            <Search size={16} />
            <input
              type="search"
              aria-label="Search sources"
              placeholder="Search title, domain or excerpt"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
            />
          </label>
          <div
            className="web-source-filters"
            role="group"
            aria-label="Source filters"
          >
            {(
              [
                ["all", "All sources"],
                ["read", "Pages read"],
                ["limited", "Limited evidence"],
              ] as const
            ).map(([value, label]) => (
              <button
                key={value}
                type="button"
                aria-pressed={filter === value}
                onClick={() => setFilter(value)}
              >
                {label}
              </button>
            ))}
          </div>
          <div className="web-source-drawer-list">
            {filtered.length === 0 ? (
              <div className="web-sources-empty">
                <Search size={22} />
                <strong>No matching sources</strong>
                <p>Try a different search or filter.</p>
              </div>
            ) : (
              filtered.map((source) => {
                const number =
                    source.citation || safeSources.indexOf(source) + 1,
                  expanded = selected === source.url;
                return (
                  <article
                    key={source.url}
                    ref={expanded ? selectedRef : undefined}
                    className={
                      expanded
                        ? "web-source-record selected"
                        : "web-source-record"
                    }
                  >
                    <button
                      type="button"
                      className="web-source-record-heading"
                      aria-expanded={expanded}
                      onClick={() => setSelected(expanded ? null : source.url)}
                    >
                      <span className="web-source-record-index">{number}</span>
                      <span>
                        <small>{sourceDomain(source)}</small>
                        <strong dir="auto">{source.title}</strong>
                        <span className="web-source-state">
                          {source.read_status === "read"
                            ? "Page read"
                            : source.read_status === "unavailable"
                              ? "Page unavailable · search snippet only"
                              : "Search snippet only"}
                        </span>
                      </span>
                      <ChevronRight size={16} />
                    </button>
                    {expanded && (
                      <div className="web-source-record-body">
                        <a
                          className="web-source-original"
                          href={evidenceUrl(source.url)!}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          Open original source
                          <ArrowUpRight size={14} />
                        </a>
                        {source.retrieved_at && (
                          <p className="web-source-retrieval">
                            Retrieved{" "}
                            {new Date(source.retrieved_at).toLocaleString()}
                            {source.provider ? ` · ${source.provider}` : ""}
                          </p>
                        )}
                        {source.snippet && (
                          <div className="web-source-excerpt">
                            <h4>Search excerpt</h4>
                            <p dir="auto">{source.snippet}</p>
                          </div>
                        )}
                        {source.duplicate_of && (
                          <p className="web-source-caution">
                            <ShieldAlert size={15} />
                            Similar extracted text appears in another source.
                            Independent corroboration is not established.
                          </p>
                        )}
                        {source.pdf && (
                          <p className="web-source-caution">
                            <FileText size={15} />
                            <span>
                              PDF: pages {source.pdf.sampled_pages.join(", ")}{" "}
                              of {source.pdf.total_pages}.{" "}
                              {source.pdf.partial
                                ? "The full document was not read."
                                : "All pages were sampled."}{" "}
                              OCR was not used.
                            </span>
                          </p>
                        )}
                        {source.passages?.map((passage) => (
                          <blockquote
                            key={passage.id}
                            className="web-source-passage"
                          >
                            <small>
                              Passage {passage.id}
                              {passage.page
                                ? ` · PDF page ${passage.page}`
                                : ""}
                            </small>
                            <p dir="auto">{passage.text}</p>
                            {passage.page && (
                              <a
                                target="_blank"
                                rel="noopener noreferrer"
                                href={
                                  evidenceUrl(source.url)! +
                                  "#page=" +
                                  passage.page
                                }
                              >
                                Open PDF page {passage.page}
                                <ArrowUpRight size={12} />
                              </a>
                            )}
                          </blockquote>
                        ))}
                        {source.read_status === "read" &&
                          !source.passages?.length && (
                            <p className="web-source-retrieval">
                              Passages were not retained in this older response.
                              Inspect the original source.
                            </p>
                          )}
                        <p className="web-source-disclosure">
                          Retrieving a page does not establish that it supports
                          every claim in the answer.
                        </p>
                      </div>
                    )}
                  </article>
                );
              })
            )}
          </div>
        </Dialog>
      )}
    </div>
  );
}
