"use client";
import { useMemo, useState } from "react";
import type { Row } from "@/lib/research";
export default function TaskGraph({
  tasks,
  dependencies,
}: {
  tasks: Row[];
  dependencies: Row[];
}) {
  const [selected, setSelected] = useState<number | null>(null);
  const layout = useMemo(() => {
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
    tasks.forEach((t) => {
      const depth = levels.get(t.id)!;
      (groups[depth] ||= []).push(t);
    });
    let y = 30;
    const nodes: Row[] = [];
    for (const group of groups) {
      if (!group) continue;
      group.forEach((t, i) =>
        nodes.push({
          ...t,
          x: 20 + (i % 4) * 185,
          y: y + Math.floor(i / 4) * 90,
        }),
      );
      y += Math.ceil(group.length / 4) * 90 + 35;
    }
    return { nodes, height: Math.max(160, y) };
  }, [tasks, dependencies]);
  const chosen = tasks.find((t) => t.id === selected);
  const color = (status: string) =>
    ({
      running: "#86dbc8",
      completed: "#6caa91",
      failed: "#e4a0a0",
      cancelled: "#8a96a3",
    })[status as "running"] || "#607289";
  if (!tasks.length) return null;
  return (
    <section className="rs-card">
      <h2>Execution dependencies</h2>
      <p>
        Each node is a persisted task. Select a node to inspect its current
        state and prerequisites.
      </p>
      <div style={{ overflow: "auto", maxHeight: 480 }}>
        <svg
          role="group"
          aria-label="Research task dependency graph"
          viewBox={`0 0 770 ${layout.height}`}
          style={{ width: "100%", minWidth: 590, height: layout.height }}
        >
          {dependencies.map((d) => {
            const a = layout.nodes.find((t) => t.id === d.depends_on),
              b = layout.nodes.find((t) => t.id === d.task_id);
            return a && b ? (
              <path
                key={d.task_id + "-" + d.depends_on}
                d={`M${a.x + 80},${a.y + 55} C${a.x + 80},${a.y + 85} ${b.x + 80},${b.y - 25} ${b.x + 80},${b.y}`}
                fill="none"
                stroke="#607289"
                strokeWidth={1.2}
              />
            ) : null;
          })}
          {layout.nodes.map((t) => (
            <g
              key={t.id}
              role="button"
              tabIndex={0}
              aria-label={`${t.agent}: ${t.status}`}
              onClick={() => setSelected(t.id)}
              onKeyDown={(e) => {
                if (e.key === "Enter" || e.key === " ") {
                  e.preventDefault();
                  setSelected(t.id);
                }
              }}
              style={{ cursor: "pointer" }}
            >
              <rect
                x={t.x}
                y={t.y}
                width={165}
                height={55}
                rx={8}
                fill="var(--panel2)"
                stroke={color(t.status)}
                strokeWidth={selected === t.id ? 3 : 1}
              />
              <text x={t.x + 12} y={t.y + 21} fill="var(--text)" fontSize={12}>
                {t.agent}
              </text>
              <text
                x={t.x + 12}
                y={t.y + 41}
                fill={color(t.status)}
                fontSize={11}
              >
                {t.status}
              </text>
            </g>
          ))}
        </svg>
      </div>
      {chosen && (
        <p>
          <strong>{chosen.key}</strong> · {chosen.status} · Dependencies:{" "}
          {dependencies
            .filter((d) => d.task_id === chosen.id)
            .map(
              (d) =>
                tasks.find((t) => t.id === d.depends_on)?.key || d.depends_on,
            )
            .join(", ") || "None"}
        </p>
      )}
    </section>
  );
}
