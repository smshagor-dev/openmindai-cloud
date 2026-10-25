import { Cite, plugins } from "@citation-js/core";
import "@citation-js/plugin-csl";
import "@citation-js/plugin-bibtex";
export type Row = Record<string, any>;
export const views = [
  "Overview",
  "Live agents",
  "Web activity",
  "Literature",
  "PDF reader",
  "Research gaps",
  "Experiments",
  "Manuscript",
  "Citations",
  "Quality review",
  "Collaboration",
  "Export",
  "History",
  "Settings",
] as const;
export const languages = [
  "auto",
  "English",
  "বাংলা",
  "Русский",
  "العربية",
  "हिन्दी",
  "中文",
  "Español",
  "Français",
  "Deutsch",
];
export async function api(path: string, method = "GET", body?: unknown) {
  const response = await fetch("/api/backend/research/" + path, {
    method,
    headers: body ? { "Content-Type": "application/json" } : undefined,
    body: body ? JSON.stringify(body) : undefined,
    cache: "no-store",
  });
  const data = await response.json();
  if (!response.ok)
    throw new Error(
      data.message ||
        data.error?.message ||
        `Request failed (${response.status})`,
    );
  return data;
}
export const sourceCsl = (s: Row) => ({
  id: String(s.id),
  type: "article-journal",
  title: s.title,
  author: s.authors,
  DOI: s.doi,
  URL: s.url,
  "container-title": s.venue,
  ...(s.year ? { issued: { "date-parts": [[s.year]] } } : {}),
});
const loaded = new Set<string>();
export async function loadStyle(style: string) {
  if (loaded.has(style)) return;
  const safe = [
    "apa",
    "ieee",
    "acm",
    "vancouver",
    "chicago",
    "harvard",
  ].includes(style)
    ? style
    : "apa";
  const r = await fetch("/research-csl/" + safe + ".csl");
  if (!r.ok) throw new Error("Citation style could not be loaded.");
  const xml = await r.text();
  plugins.config.get("@csl").styles.add(safe, xml);
  loaded.add(safe);
}
export function bibliography(sources: Row[], style = "apa"): string {
  return new Cite(sources.map(sourceCsl)).format("bibliography", {
    format: "text",
    template: style,
    lang: "en-US",
  });
}
export function citation(
  source: Row,
  style = "apa",
  ordered: Row[] = [source],
): string {
  const index = ordered.findIndex((s) => s.id === source.id);
  return new Cite(ordered.map(sourceCsl)).format("citation", {
    format: "text",
    template: style,
    lang: "en-US",
    entry: String(source.id),
    citationsPre: ordered
      .slice(0, Math.max(0, index))
      .map((s) => [String(s.id)]),
  });
}
export function parseReferences(text: string): Row[] {
  return new Cite(text).data;
}
export const pretty = (data: unknown) => JSON.stringify(data, null, 2);
export const safeUrl = (value: unknown) => {
  try {
    const u = new URL(String(value));
    return ["https:", "http:"].includes(u.protocol) &&
      !u.username &&
      !u.password
      ? u.href
      : null;
  } catch {
    return null;
  }
};
