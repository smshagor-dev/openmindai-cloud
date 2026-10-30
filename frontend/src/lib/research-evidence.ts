import type { Source } from "./research-loop.ts";
export type Passage = { id: string; text: string; page: number | null };
export type ClaimAudit = {
  text: string;
  citations: number[];
  status:
    | "passage_match"
    | "needs_review"
    | "snippet_only"
    | "uncited"
    | "numeric_mismatch";
  passages: string[];
};
export function evidenceUrl(value: unknown): string | null {
  if (typeof value !== "string" || value.length > 2000) return null;
  try {
    const u = new URL(value);
    if (!["https:", "http:"].includes(u.protocol) || u.username || u.password)
      return null;
    const host = u.hostname.toLowerCase();
    if (
      !host.includes(".") ||
      /^(localhost|127\.|0\.|10\.|169\.254\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(
        host,
      ) ||
      host.endsWith(".local") ||
      host.endsWith(".localhost") ||
      host.includes(":") ||
      (u.port && !["80", "443"].includes(u.port))
    )
      return null;
    u.hash = "";
    for (const k of [...u.searchParams.keys()])
      if (/^(utm_|fbclid$|gclid$)/i.test(k)) u.searchParams.delete(k);
    u.searchParams.sort();
    return u.href.replace(/%28/gi, "(").replace(/%29/gi, ")");
  } catch {
    return null;
  }
}
export function terms(text: string): string[] {
  return [
    ...new Set(
      (text.toLocaleLowerCase().match(/[\p{L}\p{M}\p{N}_]{3,}/gu) || []).filter(
        (w) =>
          ![
            "the",
            "and",
            "that",
            "this",
            "with",
            "from",
            "were",
            "was",
            "are",
            "for",
            "has",
            "have",
            "not",
            "but",
            "than",
          ].includes(w),
      ),
    ),
  ];
}
export function selectPassages(
  text: string,
  query: string,
  maxChars = 8000,
): Passage[] {
  const words = terms(query);
  const clauses = text
    .split(/(?<=[!?।\n])\s*|(?<!\d)\.\s+|(?<=\d)\.(?!\d)\s+/u)
    .filter((s) => s.trim().length > 24);
  const ranked = clauses
    .map((text, i) => ({
      text,
      i,
      score: terms(text).filter((t) => words.includes(t)).length,
    }))
    .sort((a, b) => b.score - a.score || a.i - b.i);
  let used = 0;
  const chosen = ranked
    .slice(0, 8)
    .flatMap((p) => {
      let value = p.text.trim();
      if (value.length > 1000) {
        const found =
          words
            .map((w) => value.toLocaleLowerCase().indexOf(w))
            .find((i) => i >= 0) || 0;
        value = value.slice(
          Math.max(0, found - 120),
          Math.max(0, found - 120) + 1000,
        );
      }
      if (used + value.length > maxChars) return [];
      used += value.length;
      return [{ ...p, text: value }];
    })
    .sort((a, b) => a.i - b.i);
  return chosen.map((p, i) => ({ id: `p${i + 1}`, text: p.text, page: null }));
}
export function duplicateOf(source: Source, others: Source[]): string | null {
  const rawWords = (s: string) =>
    s.toLocaleLowerCase().match(/[\p{L}\p{M}\p{N}_]+/gu) || [];
  const words = rawWords((source.passages || []).map((p) => p.text).join(" "));
  if (words.length < 25) return null;
  const shingles = (s: string[]) =>
    new Set(
      s
        .slice(0, 3000)
        .map((_, i) => s.slice(i, i + 5).join(" "))
        .filter((_, i) => i + 5 <= s.length),
    );
  const own = shingles(words);
  for (const old of others) {
    const other = shingles(
      rawWords((old.passages || []).map((p) => p.text).join(" ")),
    );
    if (other.size < 20) continue;
    const overlap =
      [...own].filter((s) => other.has(s)).length /
      Math.min(own.size, other.size);
    if (overlap >= 0.8) return old.url;
  }
  return null;
}
const numeric = (text: string) =>
  text.replace(/[০-৯٠-٩]/g, (c) =>
    String(
      "০১২৩৪৫৬৭৮৯".includes(c)
        ? "০১২৩৪৫৬৭৮৯".indexOf(c)
        : "٠١٢٣٤٥٦٧٨٩".indexOf(c),
    ),
  );
function numbers(text: string) {
  return [
    ...numeric(text).matchAll(
      /(?<![\p{L}\p{N}])\d+(?:[.,]\d+)*(?:\s*(?:%|percent\b|pct\b|শতাংশ|ms\b|milliseconds?\b|seconds?\b|minutes?\b|hours?\b))?/giu,
    ),
  ].map((m) =>
    m[0]
      .toLowerCase()
      .replace(/\s+/g, "")
      .replace(/percent|pct|শতাংশ/g, "%")
      .replace(/milliseconds?/g, "ms"),
  );
}
export function normalizePassageCitations(answer: string, sources: Source[]) {
  const known = new Map(sources.filter(source => evidenceUrl(source.url)).map((source, index) => [source.citation || index + 1, source]));
  let removed = 0;
  const text = answer.split(/(```[\s\S]*?```|`[^`\n]+`)/g).map((chunk, index) => index % 2 ? chunk : chunk.replace(
    /\[(\d+),\s*p(\d+)(?:\s*[-–]\s*p?(\d+))?\](?!\()/gi,
    (_mark, citation, first, last) => {
      const source = known.get(Number(citation)), start = Number(first), end = Number(last || first);
      if (!source || source.read_status !== "read" || start < 1 || end < start || end > 8 ||
          !Array.from({length:end-start+1}, (_, i) => `p${start+i}`).every(id => source.passages?.some(passage => passage.id === id))) {
        removed++;
        return "[unverified citation omitted]";
      }
      const passage = start === end ? `passage p${start}` : `passages p${start}–p${end}`;
      return `[${citation}](${source.url.replace(/\(/g, "%28").replace(/\)/g, "%29")}) (${passage})`;
    },
  )).join("");
  return {text, removed};
}

export function groundResearchAnswer(answer: string, sources: Source[]) {
  const valid = sources.filter((s) => evidenceUrl(s.url));
  const byUrl = new Map(valid.map((s) => [evidenceUrl(s.url), s]));
  const byNumber = new Map(valid.map((s, i) => [s.citation || i + 1, s]));
  const normalized = normalizePassageCitations(answer, sources);
  let removed = normalized.removed;
  // Code examples are not citation prose and must remain intact.
  const text = normalized.text
    .split(/(```[\s\S]*?```|`[^`\n]+`)/g)
    .map((chunk, i) =>
      i % 2
        ? chunk
        : chunk
            .replace(
              /\[([^\]\n]+)\]\((https?:\/\/(?:[^\s()]|\([^\s()]*\))+)\)/g,
              (link, label, url) => {
                const source = byUrl.get(evidenceUrl(url));
                const n = /^\d+$/.test(label) ? Number(label) : null;
                if (source && (!n || byNumber.get(n)?.url === source.url))
                  return `[${source.citation || valid.indexOf(source) + 1}](${source.url.replace(/\(/g, "%28").replace(/\)/g, "%29")})`;
                removed++;
                return "[unverified citation omitted]";
              },
            )
            .replace(/\[(\d+)\](?!\()/g, (mark, n) => {
              const s = byNumber.get(Number(n));
              if (s)
                return `[${n}](${s.url.replace(/\(/g, "%28").replace(/\)/g, "%29")})`;
              removed++;
              return "[unverified citation omitted]";
            }),
    )
    .join("");
  const audit: ClaimAudit[] = [];
  const prose = text
    .replace(/```[\s\S]*?```|`[^`\n]+`/g, "")
    .replace(/^\s*#{1,6}.*$/gm, "");
  for (const block of prose.split(/\n\s*\n/).slice(0, 120)) {
    const citations = [
      ...block.matchAll(/\[(\d+)\]\(https?:\/\/[^\s]+?\)/g),
    ].map((m) => Number(m[1]));
    const claim = block.replace(/\[[^\]]*\]\([^)]*\)/g, "").trim();
    if (claim.length < 45) continue;
    const cited = [...new Set(citations)]
      .map((n) => byNumber.get(n))
      .filter((s): s is Source => Boolean(s));
    const readable = cited.filter((s) => s.read_status === "read");
    const claimTerms = terms(claim);
    const passages = readable.flatMap((s) =>
      (s.passages || []).map((p) => ({
        key: `${s.citation}:${p.id}`,
        text: p.text,
      })),
    );
    const matched = passages.filter(
      (p) =>
        terms(p.text).filter((w) => claimTerms.includes(w)).length >=
        Math.min(3, Math.max(1, Math.floor(claimTerms.length / 4))),
    );
    const evidence = passages.map((p) => p.text).join(" ");
    const wanted = numbers(claim.replace(/\[\d+\]/g, ""));
    const available = numbers(evidence);
    const mismatch =
      readable.length > 0 && wanted.some((n) => !available.includes(n));
    audit.push({
      text: claim.slice(0, 600),
      citations: [...new Set(citations)],
      status: !cited.length
        ? "uncited"
        : !readable.length
          ? "snippet_only"
          : mismatch
            ? "numeric_mismatch"
            : matched.length
              ? "passage_match"
              : "needs_review",
      passages: matched.map((p) => p.key).slice(0, 8),
    });
  }
  const read = valid.filter((s) => s.read_status === "read").length;
  const hosts = new Set(
    valid.map((s) => new URL(s.url).hostname.replace(/^www\./, "")),
  ).size;
  const review = audit.filter((c) => c.status !== "passage_match").length;
  const footer = valid
    .map((s, i) => {
      const title = s.title.replace(/[\[\]\\\r\n]/g, " ").slice(0, 200);
      const label =
        s.read_status === "read"
          ? "page read"
          : s.read_status === "unavailable"
            ? "page unavailable; snippet only"
            : "search snippet only";
      return `${s.citation || i + 1}. [${title || "Source"}](${s.url.replace(/\(/g, "%28").replace(/\)/g, "%29")}) · ${label}${s.duplicate_of ? " · similar retrieved excerpt; independence not established" : ""}${s.pdf?.partial ? " · PDF pages sampled" : ""}`;
    })
    .join("\n");
  return {
    text,
    audit,
    footer: `\n\nSources\n\n${footer}\n\nEvidence coverage: ${valid.length} sources, ${hosts} domains, ${read} pages read. Page retrieval does not establish that every claim is supported.${removed ? " Unrecognized model-generated citation links were omitted; review unsupported claims." : ""}${review ? ` Automated passage/numeric checks flagged ${review} passages for review; these checks do not prove truth or semantic entailment.` : ""}`,
    removed,
  };
}
