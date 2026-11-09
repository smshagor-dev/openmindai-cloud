"use client";
import { useId, useMemo, useRef, useState } from "react";
import {
  BookOpen,
  Check,
  ChevronRight,
  CircleAlert,
  FlaskConical,
  GitBranch,
  GripVertical,
  Layers3,
  List,
  Minus,
  Orbit,
  Plus,
  RotateCcw,
  ShieldCheck,
  Workflow,
} from "lucide-react";
import type { Row } from "@/lib/research";
import {
  NODE_HEIGHT,
  NODE_WIDTH,
  taskGraphLayout,
  type GraphTask,
} from "@/lib/task-graph-layout";

const title = (value: string) =>
  value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
const agentIcon = (agent: string) =>
  /experiment/.test(agent)
    ? FlaskConical
    : /verif|quality|integrity/.test(agent)
      ? ShieldCheck
      : /literature|citation|discovery/.test(agent)
        ? BookOpen
        : Orbit;
const statusIcon = (status: string) =>
  status === "completed"
    ? Check
    : /failed|cancelled/.test(status)
      ? CircleAlert
      : status === "running"
        ? Orbit
        : GitBranch;
type Point = { x: number; y: number };

export default function TaskGraph({
  tasks,
  dependencies,
}: {
  tasks: Row[];
  dependencies: Row[];
}) {
  const [selected, setSelected] = useState<number | null>(null);
  const [view, setView] = useState<"map" | "list">("map");
  const [zoom, setZoom] = useState(1);
  const [offsets, setOffsets] = useState<Record<number, Point>>({});
  const drag = useRef<{
    id: number;
    x: number;
    y: number;
    start: Point;
    moved: boolean;
  } | null>(null);
  const suppressClick = useRef(false);
  const marker = useId().replaceAll(":", "");
  const graph = useMemo(
    () =>
      taskGraphLayout(
        tasks as GraphTask[],
        dependencies as { task_id: number; depends_on: number }[],
      ),
    [tasks, dependencies],
  );
  const byId = new Map(tasks.map((task) => [task.id, task]));
  const chosen = selected === null ? undefined : byId.get(selected);
  const done = tasks.filter((task) => task.status === "completed").length;
  const running = tasks.filter((task) => task.status === "running").length;
  const failed = tasks.filter((task) => task.status === "failed").length;
  const percent = tasks.length ? Math.round((done / tasks.length) * 100) : 0;
  const point = (id: number): Point => {
    const base = graph.positions.get(id) || { x: 0, y: 0 };
    return {
      x: Math.max(
        12,
        Math.min(graph.width - NODE_WIDTH - 12, base.x + (offsets[id]?.x || 0)),
      ),
      y: Math.max(
        34,
        Math.min(
          graph.height - NODE_HEIGHT - 12,
          base.y + (offsets[id]?.y || 0),
        ),
      ),
    };
  };
  const move = (id: number, x: number, y: number) => {
    const base = graph.positions.get(id)!;
    setOffsets((previous) => ({
      ...previous,
      [id]: {
        x: Math.max(12, Math.min(graph.width - NODE_WIDTH - 12, x)) - base.x,
        y: Math.max(34, Math.min(graph.height - NODE_HEIGHT - 12, y)) - base.y,
      },
    }));
  };
  const related = new Set(
    selected === null
      ? []
      : [
          selected,
          ...graph.edges
            .filter(
              (edge) =>
                edge.task_id === selected || edge.depends_on === selected,
            )
            .flatMap((edge) => [edge.task_id, edge.depends_on]),
        ],
  );
  if (!tasks.length)
    return (
      <section className="rs-card rs-workflow-empty">
        <Workflow size={28} />
        <h2>Your agent architecture starts here</h2>
        <p>
          Start research to see the actual agents, dependencies and live
          execution states.
        </p>
      </section>
    );

  const node = (task: Row) => {
    const Icon = agentIcon(String(task.agent)),
      StateIcon = statusIcon(task.status);
    const position = point(task.id);
    return (
      <button
        key={task.id}
        type="button"
        className={`rs-flow-node rs-flow-${task.status}${selected === task.id ? " selected" : ""}${chosen && !related.has(task.id) ? " dimmed" : ""}`}
        style={
          view === "map"
            ? {
                left: position.x,
                top: position.y,
                width: NODE_WIDTH,
                height: NODE_HEIGHT,
              }
            : undefined
        }
        aria-pressed={selected === task.id}
        aria-label={`${title(String(task.agent))}, ${title(task.status)}, ${task.key || `task ${task.id}`}`}
        onClick={() => {
          if (suppressClick.current) {
            suppressClick.current = false;
            return;
          }
          setSelected((previous) => (previous === task.id ? null : task.id));
        }}
        onKeyDown={(event) => {
          if (
            view !== "map" ||
            !event.altKey ||
            !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(
              event.key,
            )
          )
            return;
          event.preventDefault();
          move(
            task.id,
            position.x +
              (event.key === "ArrowLeft"
                ? -16
                : event.key === "ArrowRight"
                  ? 16
                  : 0),
            position.y +
              (event.key === "ArrowUp"
                ? -16
                : event.key === "ArrowDown"
                  ? 16
                  : 0),
          );
        }}
        onPointerDown={(event) => {
          if (view !== "map" || event.button !== 0) return;
          suppressClick.current = false;
          drag.current = {
            id: task.id,
            x: event.clientX,
            y: event.clientY,
            start: position,
            moved: false,
          };
          event.currentTarget.setPointerCapture(event.pointerId);
        }}
        onPointerMove={(event) => {
          const current = drag.current;
          if (!current || current.id !== task.id) return;
          const dx = (event.clientX - current.x) / zoom,
            dy = (event.clientY - current.y) / zoom;
          if (Math.abs(dx) + Math.abs(dy) > 4) current.moved = true;
          if (current.moved)
            move(task.id, current.start.x + dx, current.start.y + dy);
        }}
        onPointerUp={(event) => {
          suppressClick.current = Boolean(drag.current?.moved);
          drag.current = null;
          if (event.currentTarget.hasPointerCapture(event.pointerId))
            event.currentTarget.releasePointerCapture(event.pointerId);
        }}
        onPointerCancel={() => {
          drag.current = null;
          suppressClick.current = false;
        }}
      >
        <span className="rs-flow-port rs-flow-port-in" aria-hidden="true" />
        <span className="rs-flow-node-top">
          <span className="rs-flow-agent-icon">
            <Icon size={18} />
          </span>
          <strong>{title(String(task.agent))}</strong>
          <GripVertical className="rs-flow-grip" size={15} aria-hidden="true" />
        </span>
        <span className="rs-flow-node-key" title={task.key}>
          {task.key || `Task ${task.id}`}
        </span>
        <span className="rs-flow-node-bottom">
          <span className="rs-flow-state">
            <StateIcon size={12} />
            {title(task.status)}
          </span>
          <small>
            {task.checkpoint?.index > 0
              ? `${task.checkpoint.index} work units`
              : `#${task.id}`}
          </small>
        </span>
        <span className="rs-flow-port rs-flow-port-out" aria-hidden="true" />
      </button>
    );
  };
  return (
    <section
      className="rs-card rs-task-graph"
      aria-label="Research task dependency graph"
    >
      <header className="rs-flow-header">
        <div>
          <span className="rs-flow-eyebrow">
            <Workflow size={13} /> RESEARCH ORCHESTRATION
          </span>
          <h2>Agent workflow</h2>
          <p>Explore how your research moves between agents.</p>
        </div>
        <span className={`rs-flow-live${running ? " active" : ""}`}>
          <i />
          {running
            ? `${running} running`
            : failed
              ? `${failed} need attention`
              : done === tasks.length
                ? "Workflow complete"
                : "Waiting for agents"}
        </span>
      </header>
      <div className="rs-flow-metrics">
        <span>
          <strong>{tasks.length}</strong> Tasks
        </span>
        <span>
          <strong>{graph.edges.length}</strong> Connections
        </span>
        <span>
          <strong>{done}</strong> Completed
        </span>
        <div className="rs-flow-completion">
          <span>{percent}% complete</span>
          <progress
            value={done}
            max={tasks.length}
            aria-label="Completed workflow tasks"
          />
        </div>
      </div>
      <div className="rs-flow-toolbar">
        <div className="rs-flow-view" role="group" aria-label="Workflow view">
          <button
            type="button"
            aria-pressed={view === "map"}
            onClick={() => setView("map")}
          >
            <Layers3 size={14} />
            Architecture
          </button>
          <button
            type="button"
            aria-pressed={view === "list"}
            onClick={() => setView("list")}
          >
            <List size={14} />
            List
          </button>
        </div>
        <div
          className="rs-flow-controls"
          role="group"
          aria-label="Workflow layout controls"
        >
          <button
            type="button"
            aria-label="Zoom out"
            disabled={view !== "map" || zoom <= 0.6}
            onClick={() =>
              setZoom((value) =>
                Math.max(0.6, Math.round((value - 0.1) * 10) / 10),
              )
            }
          >
            <Minus size={15} />
          </button>
          <output aria-label="Workflow zoom">{Math.round(zoom * 100)}%</output>
          <button
            type="button"
            aria-label="Zoom in"
            disabled={view !== "map" || zoom >= 1.4}
            onClick={() =>
              setZoom((value) =>
                Math.min(1.4, Math.round((value + 0.1) * 10) / 10),
              )
            }
          >
            <Plus size={15} />
          </button>
          <button
            type="button"
            aria-label="Reset workflow layout"
            onClick={() => {
              setOffsets({});
              setZoom(1);
            }}
          >
            <RotateCcw size={14} />
            <span>Reset</span>
          </button>
        </div>
      </div>
      {view === "map" ? (
        <div
          className="rs-flow-viewport"
          tabIndex={0}
          role="region"
          aria-label="Agent architecture canvas. Scroll to explore. Drag nodes to arrange, or focus a node and use Alt plus arrow keys."
        >
          <div
            className="rs-flow-scroll-space"
            style={{ width: graph.width * zoom, height: graph.height * zoom }}
          >
            <div
              className="rs-flow-board"
              style={{
                width: graph.width,
                height: graph.height,
                transform: `scale(${zoom})`,
              }}
            >
              {graph.stages.map((stage, index) => (
                <span
                  className="rs-flow-stage-label"
                  key={stage.depth}
                  style={{ top: stage.y - 32 }}
                >
                  STAGE {String(index + 1).padStart(2, "0")}
                  <span>
                    {stage.tasks.length}{" "}
                    {stage.tasks.length === 1 ? "task" : "parallel tasks"}
                  </span>
                </span>
              ))}
              <svg
                className="rs-flow-wires"
                width={graph.width}
                height={graph.height}
                aria-hidden="true"
              >
                <defs>
                  <marker
                    id={marker}
                    markerWidth="7"
                    markerHeight="7"
                    refX="6"
                    refY="3.5"
                    orient="auto"
                  >
                    <path d="M0 0 L7 3.5 L0 7 Z" fill="context-stroke" />
                  </marker>
                </defs>
                {graph.edges.map((edge) => {
                  const from = point(edge.depends_on),
                    to = point(edge.task_id),
                    active = byId.get(edge.task_id)?.status === "running";
                  const x1 = from.x + NODE_WIDTH / 2,
                    y1 = from.y + NODE_HEIGHT + 3,
                    x2 = to.x + NODE_WIDTH / 2,
                    y2 = to.y - 5;
                  const bend = Math.max(28, Math.abs(y2 - y1) * 0.45);
                  return (
                    <path
                      key={`${edge.depends_on}-${edge.task_id}`}
                      className={`${active ? "active" : ""}${chosen && (edge.task_id === selected || edge.depends_on === selected) ? " selected" : chosen ? " dimmed" : ""}`}
                      d={`M${x1},${y1} C${x1},${y1 + bend} ${x2},${y2 - bend} ${x2},${y2}`}
                      markerEnd={`url(#${marker})`}
                    />
                  );
                })}
              </svg>
              {tasks.map(node)}
            </div>
          </div>
        </div>
      ) : (
        <div className="rs-flow-list">{tasks.map(node)}</div>
      )}
      <footer className="rs-flow-footer">
        <span>
          <i className="complete" />
          Completed
        </span>
        <span>
          <i className="running" />
          Running
        </span>
        <span>
          <i />
          Waiting
        </span>
        <span>
          <i className="failed" />
          Failed
        </span>
        <small>
          {view === "map"
            ? "Drag to arrange · Alt + arrows to move · Layout only"
            : "Select a task to inspect its connections"}
        </small>
      </footer>
      {chosen ? (
        <aside
          className="rs-flow-inspector"
          aria-label="Selected agent details"
          aria-live="polite"
        >
          <div>
            <span className="rs-flow-eyebrow">SELECTED AGENT</span>
            <h3>
              {title(String(chosen.agent))}
              <span>{title(chosen.status)}</span>
            </h3>
            <p>{chosen.key}</p>
          </div>
          <div>
            <strong>Prerequisites</strong>
            <p>
              {dependencies
                .filter((edge) => edge.task_id === chosen.id)
                .map(
                  (edge) =>
                    byId.get(edge.depends_on)?.key ||
                    `Task #${edge.depends_on}`,
                )
                .join(", ") || "Entry task · no prerequisites"}
            </p>
            <strong>Hands off to</strong>
            <p>
              {graph.edges
                .filter((edge) => edge.depends_on === chosen.id)
                .map(
                  (edge) =>
                    byId.get(edge.task_id)?.key || `Task #${edge.task_id}`,
                )
                .join(", ") || "No downstream tasks"}
            </p>
            {chosen.error_code ? (
              <p className="rs-error-text">{chosen.error_code}</p>
            ) : null}
          </div>
        </aside>
      ) : (
        <div className="rs-flow-inspect-hint">
          <GitBranch size={15} />
          Select an agent to trace its connections
          <ChevronRight size={14} />
        </div>
      )}
    </section>
  );
}
