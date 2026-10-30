import { evidenceUrl } from "./research-evidence.ts";
import type { Source, ResearchEvent } from "./research-loop.ts";
export function sourceDomain(source: Pick<Source, "url">): string {
  const safe = evidenceUrl(source.url);
  return safe
    ? new URL(safe).hostname.replace(/^www\./, "")
    : "Unavailable source";
}
export function sourceForCitation(
  href: string | undefined,
  label: unknown,
  sources: Source[],
): Source | undefined {
  if (
    Array.isArray(label) &&
    label.every(
      (value) => typeof value === "string" || typeof value === "number",
    )
  )
    label = label.join("");
  if (typeof label !== "string" && typeof label !== "number") return undefined;
  const number = String(label);
  if (!/^\d+$/.test(number)) return undefined;
  const url = evidenceUrl(href);
  if (!url) return undefined;
  return sources.find(
    (source, index) =>
      (source.citation || index + 1) === Number(number) &&
      evidenceUrl(source.url) === url,
  );
}
export function filterResearchSources(
  sources: Source[],
  query: string,
  status: "all" | "read" | "limited" = "all",
): Source[] {
  const words = query.toLocaleLowerCase().trim().split(/\s+/u).filter(Boolean);
  return sources.filter(
    (source) =>
      evidenceUrl(source.url) &&
      (status === "all" ||
        (status === "read" && source.read_status === "read") ||
        (status === "limited" &&
          (source.read_status !== "read" ||
            source.duplicate_of ||
            source.pdf?.partial))) &&
      words.every((word) =>
        (
          source.title +
          " " +
          sourceDomain(source) +
          " " +
          (source.snippet || "")
        )
          .toLocaleLowerCase()
          .includes(word),
      ),
  );
}
export function activeResearchOperations(
  activity: ResearchEvent[],
): ResearchEvent[] {
  const latest = new Map<string, ResearchEvent>();
  for (const event of activity)
    if (event.operation_id) latest.set(event.operation_id, event);
  return [...latest.values()].filter((event) => event.status === "running");
}

export function researchAnswerBody(
  content: string,
  bodyLength: unknown,
  complete: boolean,
): string {
  return complete &&
    typeof bodyLength === "number" &&
    Number.isInteger(bodyLength) &&
    bodyLength >= 0 &&
    bodyLength <= content.length
    ? content.slice(0, bodyLength)
    : content;
}

export function evidenceCount(count: number, noun: "source" | "page"): string {
  return `${count} ${noun}${count === 1 ? "" : "s"}`;
}
