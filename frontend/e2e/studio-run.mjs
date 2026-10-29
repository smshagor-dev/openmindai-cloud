import { spawn } from "node:child_process";
import net from "node:net";
import { once } from "node:events";
import { fileURLToPath } from "node:url";
const cwd = fileURLToPath(new URL("..", import.meta.url));
const server = net.createServer();
server.listen(0, "127.0.0.1");
await once(server, "listening");
const port = server.address().port;
await new Promise((r) => server.close(r));
const url = `http://127.0.0.1:${port}`;
const child = spawn(
  process.execPath,
  [
    "node_modules/next/dist/bin/next",
    "start",
    "--hostname",
    "127.0.0.1",
    "--port",
    String(port),
  ],
  {
    cwd,
    env: { ...process.env, FRONTEND_URL: url },
    stdio: ["ignore", "ignore", "inherit"],
  },
);
try {
  let ready = false;
  for (let i = 0; i < 120; i++) {
    try {
      if ((await fetch(url)).ok) {
        ready = true;
        break;
      }
    } catch {}
    await new Promise((r) => setTimeout(r, 250));
  }
  if (!ready) throw new Error("Next.js did not start");
  const tests = spawn(
    process.execPath,
    [
      "node_modules/@playwright/test/cli.js",
      "test",
      "--config=e2e/studio.config.ts",
    ],
    {
      cwd,
      env: { ...process.env, RESEARCH_BROWSER_URL: url },
      stdio: "inherit",
    },
  );
  const [code, signal] = await once(tests, "exit");
  if (signal) console.error(`Browser tests were interrupted by ${signal}.`);
  process.exitCode = code ?? 1;
} finally {
  child.kill("SIGTERM");
}
