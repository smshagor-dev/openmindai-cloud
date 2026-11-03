"use client";
import { useMemo, useState } from "react";
import type { Row } from "@/lib/research";

const title = (agent: string) => agent.charAt(0).toUpperCase() + agent.slice(1).replaceAll("_", " ");

// Tasks grouped by dependency depth and laid out as responsive stages: chips
// wrap to the panel width, so the graph stays readable on phones and desktops.
export default function TaskGraph({
  tasks,
  dependencies,
}: {
  tasks: Row[];
  dependencies: Row[];
}) {
  const [selected, setSelected] = useState<number | null>(null);
  const stages = useMemo(() => {
    const byId = new Map(tasks.map((t) => [t.id, t]));
    const levels = new Map<number, number>();
    const level = (id: number, path = new Set<number>()): number => {
      if (levels.has(id)) return levels.get(id)!;
      if (path.has(id)) return 0;
      const next = new Set(path);
      next.add(id);
      const parents = dependencies.filter(
        (d) => d.task_id === id && byId.has(d.depends_on),
      );
      const n = parents.length
        ? 1 + Math.max(...parents.map((d) => level(d.depends_on, next)))
        : 0;
      levels.set(id, n);
      return n;
    };
    tasks.forEach((t) => level(t.id));
    const groups: Row[][] = [];
    tasks.forEach((t) => (groups[levels.get(t.id)!] ||= []).push(t));
    return groups.filter(Boolean);
  }, [tasks, dependencies]);
  const counts = useMemo(
    () => tasks.reduce<Record<string, number>>((all, t) => ({ ...all, [t.status]: (all[t.status] || 0) + 1 }), {}),
    [tasks],
  );
  const chosen = tasks.find((t) => t.id === selected);
  if (!tasks.length) return null;
  const stageName = (group: Row[]) => {
    const agents = [...new Set(group.map((t) => title(String(t.agent))))];
    return agents.length <= 2 ? agents.join(" & ") : `${agents.slice(0, 2).join(", ")} +${agents.length - 2}`;
  };
  return (
    <section className="rs-card rs-task-graph" aria-label="Research task dependency graph">
      <h2>Execution dependencies</h2>
      <p>Tasks run stage by stage; each stage waits for the one above. Select a task to see its prerequisites.</p>
      <div className="rs-task-summary">
        <span>{tasks.length} tasks</span>
        {Object.entries(counts).map(([status, n]) => (
          <span key={status} className={`rs-task-count rs-task-${status}`}>
            <i aria-hidden="true" />{n} {status}
          </span>
        ))}
      </div>
      <ol className="rs-task-stages">
        {stages.map((group, index) => (
          <li key={index} className="rs-task-stage">
            <div className="rs-task-stage-head">
              <span className="rs-task-stage-index">{index + 1}</span>
              <strong>{stageName(group)}</strong>
              {group.length > 1 && <small>×{group.length}</small>}
            </div>
            <div className="rs-task-chips">
              {group.map((t) => {
                // Number repeated agents within a stage so chips stay distinguishable.
                const same = group.filter((other) => other.agent === t.agent);
                const name = title(String(t.agent)) + (same.length > 1 ? ` ${same.indexOf(t) + 1}` : "");
                return (
                <button
                  key={t.id}
                  type="button"
                  className={`rs-task-chip rs-task-${t.status}${selected === t.id ? " selected" : ""}`}
                  aria-pressed={selected === t.id}
                  aria-label={`${name}: ${t.status}`}
                  onClick={() => setSelected(selected === t.id ? null : t.id)}
                >
                  <i aria-hidden="true" />
                  <span>{name}</span>
                  <small>{t.status}</small>
                </button>
                );
              })}
            </div>
          </li>
        ))}
      </ol>
      {chosen && (
        <p className="rs-task-detail" role="status">
          <strong>{chosen.key}</strong> · {chosen.status} · Depends on:{" "}
          {dependencies
            .filter((d) => d.task_id === chosen.id)
            .map((d) => tasks.find((t) => t.id === d.depends_on)?.key || d.depends_on)
            .join(", ") || "None"}
        </p>
      )}
    </section>
  );
}
