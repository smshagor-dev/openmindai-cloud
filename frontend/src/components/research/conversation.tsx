"use client";

import { useEffect, useRef } from "react";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import {
  ArrowUp,
  ArrowUpRight,
  BookOpen,
  ChevronDown,
  FileText,
  FlaskConical,
  Library,
  Orbit,
  Paperclip,
  Settings2,
  ShieldCheck,
  Workflow,
} from "lucide-react";
import { type Row, safeUrl } from "@/lib/research";

export function ResearchComposer({
  value,
  onChange,
  onSubmit,
  disabled,
  pending,
  initial = false,
  onAttach,
  sourceTitle,
  onClearSource,
  mode = "question",
  onMode,
}: {
  value: string;
  onChange: (value: string) => void;
  onSubmit: () => void;
  disabled?: boolean;
  pending?: boolean;
  initial?: boolean;
  onAttach?: () => void;
  sourceTitle?: string;
  onClearSource?: () => void;
  mode?: string;
  onMode?: (mode: string) => void;
}) {
  return (
    <>
    <form
      className="rs-chat-composer"
      onSubmit={(e) => {
        e.preventDefault();
        if (!disabled && !pending && value.trim()) onSubmit();
      }}
    >
      {sourceTitle && (
        <div className="rs-chat-source-context">
          <BookOpen size={14} />
          <span>{sourceTitle}</span>
          <button
            type="button"
            onClick={onClearSource}
            aria-label="Clear selected source"
          >
            ×
          </button>
        </div>
      )}
      <label
        className="rs-sr-only"
        htmlFor={initial ? "research-topic-composer" : "research-chat-composer"}
      >
        {initial ? "Research topic" : "Research message"}
      </label>
      <textarea
        id={initial ? "research-topic-composer" : "research-chat-composer"}
        rows={2}
        dir="auto"
        value={value}
        maxLength={initial ? 16000 : mode === "selection" ? 2000 : 4000}
        onChange={(e) => onChange(e.target.value)}
        placeholder={
          initial
            ? "What would you like to research?"
            : "Ask about your research, sources or manuscript…"
        }
        onKeyDown={(e) => {
          if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            if (!disabled && !pending && value.trim()) onSubmit();
          }
        }}
      />
      <div className="rs-chat-composer-actions">
        <div>
          {onAttach && (
            <button
              type="button"
              onClick={onAttach}
              aria-label="Attach a research paper"
            >
              <Paperclip size={17} />
            </button>
          )}
          {onMode ? (
            <select
              aria-label="Research action"
              value={mode}
              onChange={(e) => onMode(e.target.value)}
            >
              <option value="question">Ask sources</option>
              <option value="selection">Revise manuscript</option>
            </select>
          ) : (
            <span>
              <Orbit size={15} />
              Research
            </span>
          )}
        </div>
        <button
          className="rs-chat-send"
          type="submit"
          disabled={disabled || pending || !value.trim()}
          aria-label={
            initial ? "Start research conversation" : "Send research message"
          }
        >
          <ArrowUp size={18} />
        </button>
      </div>
    </form>
    <p className="rs-composer-hint">
      {initial
        ? "Your language, sources and research decisions stay with the project."
        : "Enter to send · Shift + Enter for a new line"}
    </p>
    </>
  );
}

function Text({ children }: { children: string }) {
  return (
    <div className="rs-chat-markdown" dir="auto">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        skipHtml
        components={{
          img: ({ alt }) => <span>{alt || "Image reference"}</span>,
          a: ({ href, children }) => {
            const url = safeUrl(href);
            return url ? (
              <a href={url} target="_blank" rel="noopener noreferrer">
                {children}
              </a>
            ) : (
              <span>{children}</span>
            );
          },
        }}
      >
        {children}
      </ReactMarkdown>
    </div>
  );
}

export default function ResearchConversation({
  data,
  events,
  activityState,
  question,
  setQuestion,
  send,
  busy,
  editable,
  label,
  open,
  source,
  clearSource,
  mode,
  onMode,
}: {
  data: Row;
  events: Row[];
  activityState: string;
  question: string;
  setQuestion: (value: string) => void;
  send: () => void;
  busy: boolean;
  editable: boolean;
  label: (status: string) => string;
  open: (view: string) => void;
  source: Row | null;
  clearSource: () => void;
  mode: string;
  onMode: (mode: string) => void;
}) {
  const tasks: Row[] = data.tasks || [];
  const sources: Row[] = data.sources || [];
  const messages: Row[] = data.conversation || [];
  const done = tasks.filter((t) => t.status === "completed").length;
  const active = ["running", "paused"].includes(data.run?.status);
  const plan = data.research_plan || data.run?.plan;
  const scroll = useRef<HTMLDivElement>(null);
  const following = useRef(true);
  const last = messages.at(-1);
  useEffect(() => {
    if (following.current && scroll.current)
      scroll.current.scrollTop = scroll.current.scrollHeight;
  }, [messages.length, last?.status, last?.answer]);
  const workflow = tasks.filter(
    (t) => !["question", "selection"].includes(t.agent),
  );
  const recent = events.slice(-14).reverse();
  return (
    <section className="rs-conversation" aria-label="Research conversation">
      <div
        className="rs-chat-scroll"
        ref={scroll}
        onScroll={(e) => {
          const el = e.currentTarget;
          following.current =
            el.scrollHeight - el.scrollTop - el.clientHeight < 100;
        }}
      >
        <div className="rs-thread">
          <div className="rs-chat-context">
            <span className="rs-cloud-dot" />
            OpenMind Cloud<span>·</span>
            <span>Private research workspace</span>
            {activityState !== "connected" && (
              <span role="status">
                {activityState === "backoff"
                  ? "Activity updates delayed · retrying after cooldown"
                  : "Reconnecting activity…"}
              </span>
            )}
          </div>
          <article
            className="rs-user-bubble"
            aria-label="Original research request"
          >
            <Text>{data.project.prompt}</Text>
          </article>
          <article className="rs-assistant-message">
            <div className="rs-message-author">
              <span>
                <Orbit size={17} />
              </span>
              OpenMind <small>Research</small>
            </div>
            {!plan ? (
              <p>
                {workflow.length > 0 && data.run?.status === "running"
                  ? "Your research request is queued. Activity appears as the agents perform actual work."
                  : "Your research workspace is ready. Start research to interpret the request, discover sources and prepare an evidence-grounded plan."}
              </p>
            ) : (
              <>
                <p>
                  Your research plan is ready. Sources, findings and manuscript
                  revisions remain attached to this conversation.
                </p>
                <details className="rs-chat-plan">
                  <summary>
                    Research plan <ChevronDown size={15} />
                  </summary>
                  <div>
                    <p>{plan.discipline}</p>
                    <ul>
                      {(plan.objectives || []).map(
                        (objective: string, i: number) => (
                          <li key={i} dir="auto">
                            {objective}
                          </li>
                        ),
                      )}
                    </ul>
                    <p className="rs-secondary">
                      {plan.constraints?.date_range}
                      {plan.experimental === false &&
                        " · No experiments requested"}
                    </p>
                  </div>
                </details>
              </>
            )}
            {workflow.length > 0 && (
              <details className="rs-chat-activity">
                <summary>
                  <Workflow size={16} />
                  <span>
                    Agent activity{" "}
                    <small>
                      {done} of {tasks.length} tasks completed
                    </small>
                  </span>
                  <ChevronDown size={15} />
                </summary>
                <div className="rs-chat-progress">
                  <progress
                    aria-label="Completed research tasks"
                    value={done}
                    max={tasks.length || 1}
                  />
                </div>
                <div className="rs-chat-task-list">
                  {tasks.map((t) => (
                    <div key={t.id}>
                      <span className={`rs-task-dot ${t.status}`} />
                      <span>{t.agent.replaceAll("_", " ")}</span>
                      <small>{label(t.status)}</small>
                    </div>
                  ))}
                </div>
                <button type="button" onClick={() => open("Live agents")}>
                  Open execution details <ArrowUpRight size={14} />
                </button>
              </details>
            )}
            <div className="rs-chat-artifacts">
              <button type="button" onClick={() => open("Literature")}>
                <Library size={18} />
                <span>
                  <strong>Source library</strong>
                  <small>
                    {sources.length} sources ·{" "}
                    {
                      sources.filter((s) => s.verification === "verified")
                        .length
                    }{" "}
                    metadata verified
                  </small>
                </span>
                <ArrowUpRight size={15} />
              </button>
              <button type="button" onClick={() => open("Manuscript")}>
                <FileText size={18} />
                <span>
                  <strong>Manuscript canvas</strong>
                  <small>
                    Version {data.document?.version || 1} · Reviewable edits
                  </small>
                </span>
                <ArrowUpRight size={15} />
              </button>
            </div>
            <div className="rs-chat-shortcuts">
              <button type="button" onClick={() => open("Web activity")}>
                <BookOpen size={14} />
                Browsing activity
              </button>
              <button type="button" onClick={() => open("Experiments")}>
                <FlaskConical size={14} />
                Experiments
              </button>
              <button type="button" onClick={() => open("Quality review")}>
                <ShieldCheck size={14} />
                Review
              </button>
              <button type="button" onClick={() => open("Export")}>
                <FileText size={14} />
                Export
              </button>
            </div>
            {recent.length > 0 && (
              <details className="rs-chat-trail">
                <summary>
                  Recent activity <ChevronDown size={14} />
                </summary>
                <ol>
                  {recent.map((e) => (
                    <li key={e.id}>
                      <div>
                        <strong>{e.type.replaceAll(".", " ")}</strong>
                        <time dateTime={e.created_at}>
                          {new Date(e.created_at).toLocaleTimeString()}
                        </time>
                      </div>
                      <p dir="auto">
                        {e.data.query ||
                          e.data.title ||
                          e.data.agent ||
                          e.data.error_code ||
                          label(e.data.status || "")}
                      </p>
                    </li>
                  ))}
                </ol>
              </details>
            )}
          </article>
          {(data.findings || [])
            .filter((f: Row) =>
              ["gaps", "manuscript", "quality", "integrity"].includes(f.kind),
            )
            .slice(0, 4)
            .reverse()
            .map((f: Row) => (
              <article className="rs-chat-result" key={f.id}>
                <div className="rs-message-author">
                  <Orbit size={17} />
                  {
                    (
                      {
                        gaps: "Research gaps",
                        manuscript: "Manuscript proposal",
                        quality: "Quality review",
                        integrity: "Integrity review",
                      } as Record<string, string>
                    )[f.kind]
                  }
                </div>
                <p>
                  {f.kind === "manuscript"
                    ? "A draft proposal is available. Review it before accepting any changes to your manuscript."
                    : "This agent's recorded findings are ready to inspect with their supporting evidence."}
                </p>
                <button
                  type="button"
                  onClick={() =>
                    open(
                      (
                        {
                          gaps: "Research gaps",
                          manuscript: "Manuscript",
                          quality: "Quality review",
                          integrity: "Quality review",
                        } as Record<string, string>
                      )[f.kind],
                    )
                  }
                >
                  Open findings <ArrowUpRight size={14} />
                </button>
              </article>
            ))}
          {(data.experiments || []).slice(-3).map((experiment: Row) => (
            <article
              className="rs-chat-result"
              key={`experiment-${experiment.id}`}
            >
              <div className="rs-message-author">
                <FlaskConical size={17} />
                {experiment.title}
                <small>{label(experiment.status)}</small>
              </div>
              <p>
                {experiment.status === "completed"
                  ? "Execution results and recorded provenance are available for review."
                  : "Execution requires approval of the exact code, datasets and seed, plus an available isolated worker."}
              </p>
              <button type="button" onClick={() => open("Experiments")}>
                Inspect experiment <ArrowUpRight size={14} />
              </button>
            </article>
          ))}
          {(data.exports || [])
            .slice(0, 3)
            .reverse()
            .map((file: Row) => (
              <article className="rs-chat-result" key={`export-${file.id}`}>
                <div className="rs-message-author">
                  <FileText size={17} />
                  {String(file.format).toUpperCase()} export
                  <small>{label(file.status)}</small>
                </div>
                {file.status === "completed" ? (
                  <a
                    href={`/api/backend/research/projects/${data.project.id}/exports/${file.id}/download`}
                  >
                    Download validated {String(file.format).toUpperCase()}
                  </a>
                ) : (
                  <p>
                    {file.status === "failed"
                      ? "Export failed. Inspect its status before retrying."
                      : "The frozen document is waiting for processing."}
                  </p>
                )}
              </article>
            ))}
          {data.conversation_has_more && (
            <p className="rs-chat-caption">
              Showing the latest 50 questions. Earlier task activity remains in
              History.
            </p>
          )}
          {messages.map((m) => (
            <div className="rs-chat-turn" key={m.id}>
              <article
                className="rs-user-bubble"
                aria-label="Research question"
              >
                <Text>{m.question}</Text>
              </article>
              <article className="rs-assistant-message">
                <div className="rs-message-author">
                  <span>
                    <Orbit size={17} />
                  </span>
                  OpenMind<small>{label(m.status)}</small>
                </div>
                {m.answer ? (
                  <>
                    <Text>{m.answer}</Text>
                    {m.kind === "selection" && (
                      <button type="button" onClick={() => open("Manuscript")}>
                        Review in manuscript canvas <ArrowUpRight size={14} />
                      </button>
                    )}
                    {m.uncertainty && (
                      <p className="rs-chat-uncertainty">{m.uncertainty}</p>
                    )}
                    <div className="rs-answer-sources">
                      {(m.source_ids || []).map((sourceId: number) => {
                        const s = sources.find((x) => x.id === sourceId);
                        return s ? (
                          <button
                            type="button"
                            key={sourceId}
                            onClick={() => open("Citations")}
                          >
                            <BookOpen size={13} />
                            {s.title}
                            <small>{s.verification}</small>
                          </button>
                        ) : null;
                      })}
                    </div>
                  </>
                ) : (
                  <p role={m.status === "failed" ? "alert" : undefined}>
                    {m.status === "failed"
                      ? "This response could not be completed. Inspect the agent activity before retrying."
                      : m.status === "cancelled"
                        ? "This response was cancelled. No answer was accepted."
                        : "The evidence-based response is " +
                          label(m.status) +
                          "."}
                  </p>
                )}
              </article>
            </div>
          ))}
        </div>
      </div>
      <div className="rs-chat-composer-dock">
        <ResearchComposer
          value={question}
          onChange={setQuestion}
          onSubmit={send}
          disabled={!editable || active}
          pending={busy}
          onAttach={() => open("PDF reader")}
          sourceTitle={source?.title}
          onClearSource={clearSource}
          mode={mode}
          onMode={onMode}
        />
        {active ? (
          <p className="rs-chat-caption">
            {data.run.status === "paused"
              ? "Resume or cancel the paused run before asking a separate question."
              : "Research is running. Pause or cancel it before starting a separate question."}
          </p>
        ) : (
          <button
            className="rs-chat-availability"
            type="button"
            onClick={() => open("Settings")}
          >
            <Settings2 size={13} />
            Languages, journal style & processing availability
          </button>
        )}
      </div>
    </section>
  );
}
