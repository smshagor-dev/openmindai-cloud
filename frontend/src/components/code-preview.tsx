"use client";
import { useMemo } from "react";
import hljs from "highlight.js/lib/core";
import javascript from "highlight.js/lib/languages/javascript";
import typescript from "highlight.js/lib/languages/typescript";
import python from "highlight.js/lib/languages/python";
import css from "highlight.js/lib/languages/css";
import xml from "highlight.js/lib/languages/xml";
import json from "highlight.js/lib/languages/json";
import bash from "highlight.js/lib/languages/bash";
import php from "highlight.js/lib/languages/php";
import sql from "highlight.js/lib/languages/sql";
import cpp from "highlight.js/lib/languages/cpp";
import java from "highlight.js/lib/languages/java";
import rust from "highlight.js/lib/languages/rust";
for (const [name, grammar] of Object.entries({
  javascript,
  typescript,
  python,
  css,
  xml,
  json,
  bash,
  php,
  sql,
  cpp,
  java,
  rust,
}))
  hljs.registerLanguage(name, grammar);

export default function CodePreview({
  content,
  language,
}: {
  content: string;
  language?: string;
}) {
  const html = useMemo(() => {
    // highlight.js escapes user content before adding its own span markup.
    if (content.length > 100000) return null;
    return language && hljs.getLanguage(language)
      ? hljs.highlight(content, { language, ignoreIllegals: true }).value
      : hljs.highlightAuto(content).value;
  }, [content, language]);
  return (
    <pre className="code-preview" tabIndex={0}>
      {html === null ? (
        <code>{content}</code>
      ) : (
        <code className="hljs" dangerouslySetInnerHTML={{ __html: html }} />
      )}
    </pre>
  );
}
