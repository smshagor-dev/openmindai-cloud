import type { Objective, ResearchEvent } from "./research-loop";
import type { ClaimAudit } from "./research-evidence";
export type ResearchMetadata = {
  version: 1;
  mode?: "web_search" | "deep_research";
  answer_body_length?: number;
  status:
    | "running"
    | "writing"
    | "complete"
    | "partial"
    | "paused"
    | "failed"
    | "cancelled";
  plan: Objective[];
  activity: ResearchEvent[];
  coverage?: {
    actions: number;
    sources: number;
    pagesRead: number;
    domainsRead: number;
    objectives?: number;
    objectivesSupported?: number;
  };
  audit?: ClaimAudit[];
  limitations?: string[];
  stop_reason?: string;
};
export function compactResearchMetadata(
  meta: ResearchMetadata,
): ResearchMetadata {
  const value = {
    ...meta,
    activity: meta.activity.slice(-100).map((e) => ({
      ...e,
      label: e.label.slice(0, 320),
      ...(e.query ? { query: e.query.slice(0, 500) } : {}),
      ...(e.url ? { url: e.url.slice(0, 2000) } : {}),
    })),
    audit: meta.audit?.slice(0, 100),
  };
  while (
    new TextEncoder().encode(JSON.stringify(value)).length > 180000 &&
    value.activity.length > 5
  )
    value.activity.shift();
  if (new TextEncoder().encode(JSON.stringify(value)).length > 180000)
    value.audit = value.audit?.map((a) => ({
      ...a,
      text: a.text.slice(0, 200),
    }));
  return value;
}
