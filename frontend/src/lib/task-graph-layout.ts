// Layout is presentation only. Runtime prerequisites always come from the API.
export type GraphTask = {
  id: number;
  agent: string;
  status: string;
  key?: string;
  checkpoint?: { index?: number };
  error_code?: string;
};
export type GraphDependency = { task_id: number; depends_on: number };
export const NODE_WIDTH = 232,
  NODE_HEIGHT = 112;
export function taskGraphLayout(
  tasks: GraphTask[],
  dependencies: GraphDependency[],
) {
  const byId = new Map(tasks.map((task) => [task.id, task]));
  const parents = new Map<number, number[]>();
  const edges = dependencies.filter(
    (edge) => byId.has(edge.task_id) && byId.has(edge.depends_on),
  );
  for (const edge of edges)
    parents.set(edge.task_id, [
      ...(parents.get(edge.task_id) || []),
      edge.depends_on,
    ]);
  const levels = new Map<number, number>();
  function level(id: number, path = new Set<number>()): number {
    if (levels.has(id)) return levels.get(id)!;
    if (path.has(id)) return 0;
    const next = new Set(path).add(id);
    const depth =
      Math.max(
        -1,
        ...(parents.get(id) || []).map((parent) => level(parent, next)),
      ) + 1;
    levels.set(id, depth);
    return depth;
  }
  const groups = new Map<number, GraphTask[]>();
  for (const task of tasks) {
    const depth = level(task.id);
    groups.set(depth, [...(groups.get(depth) || []), task]);
  }
  const width = 824;
  let top = 56;
  const positions = new Map<number, { x: number; y: number }>();
  const stages = [...groups]
    .sort(([a], [b]) => a - b)
    .map(([depth, group]) => {
      const y = top;
      group.forEach((task, index) => {
        const rowCount = Math.min(3, group.length - Math.floor(index / 3) * 3);
        positions.set(task.id, {
          x:
            (width - (rowCount * NODE_WIDTH + (rowCount - 1) * 28)) / 2 +
            (index % 3) * (NODE_WIDTH + 28),
          y: top + Math.floor(index / 3) * 140,
        });
      });
      top += Math.ceil(group.length / 3) * 140 + 52;
      return { depth, y, tasks: group };
    });
  return { positions, stages, edges, width, height: Math.max(260, top - 16) };
}
