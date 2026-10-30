"use client";
import { isValidElement, useEffect, useMemo } from "react";
import { useAnimatedText } from "@/hooks/use-animated-text";
import TypingIndicator from "./typing-indicator";
import CodeBlock from "./code-block";
import Markdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";
import type { Source } from "@/lib/research-loop";
import { sourceForCitation, sourceDomain } from "@/lib/research-sources";
import { normalizePassageCitations } from "@/lib/research-evidence";
// Stable renderers preserve code blocks while streamed text grows.
const markdownComponents: Components = {
  a: ({ children, ...props }) => (
    <a {...props} target="_blank" rel="noopener noreferrer">
      {children}
    </a>
  ),
  pre: ({ children }) => {
    if (isValidElement<{ children?: string; className?: string }>(children)) {
      return (
        <CodeBlock
          content={String(children.props.children ?? "").replace(/\n$/, "")}
          language={
            children.props.className?.replace("language-", "") || "code"
          }
        />
      );
    }
    return <pre tabIndex={0}>{children}</pre>;
  },
};

export default function ResponseMarkdown({
  content,
  onRendered,
  animate = false,
  streaming = false,
  reducedMotion = false,
  sources,
  onSourceOpen,
  statusLabel,
}: {
  content: string;
  onRendered?: () => void;
  animate?: boolean;
  streaming?: boolean;
  reducedMotion?: boolean;
  sources?: Source[];
  onSourceOpen?: (source: Source) => void;
  // Live work status shown inside the response turn before text arrives.
  statusLabel?: string;
}) {
  const components = useMemo<Components>(
    () =>
      !sources?.length || !onSourceOpen
        ? markdownComponents
        : {
            ...markdownComponents,
            a: ({ children, href, ...props }) => {
              const source = sourceForCitation(href, children, sources);
              return source ? (
                <button
                  type="button"
                  className="web-inline-citation"
                  aria-label={`Inspect source ${source.citation || sources.indexOf(source) + 1}: ${source.title}`}
                  title={sourceDomain(source) + " · " + source.title}
                  onClick={() => onSourceOpen(source)}
                >
                  {source.citation || sources.indexOf(source) + 1}
                </button>
              ) : (
                <a
                  {...props}
                  href={href}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {children}
                </a>
              );
            },
          },
    [sources, onSourceOpen],
  );
  const displayContent = useMemo(() => sources?.length ? normalizePassageCitations(content, sources).text : content, [content, sources]);
  const { text, pending } = useAnimatedText(displayContent, animate, reducedMotion);
  const typing = streaming || pending;
  useEffect(() => {
    onRendered?.();
  }, [text, onRendered]);
  return (
    <div
      className={`markdown${typing ? " response-streaming" : ""}`}
      aria-busy={typing}
    >
      <Markdown remarkPlugins={[remarkGfm]} components={components}>
        {text}
      </Markdown>
      {typing && <TypingIndicator writing={Boolean(text)} label={statusLabel} />}
    </div>
  );
}
