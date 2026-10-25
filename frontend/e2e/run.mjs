import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, writeFileSync, openSync, closeSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import net from "node:net";
const root = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const dir = mkdtempSync(join(tmpdir(), "openmind-research-e2e-"));
const children = [];
const log = openSync(join(dir, "services.log"), "a");
async function port() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.listen(0, "127.0.0.1", () => {
      const p = server.address().port;
      server.close(() => resolve(p));
    });
    server.on("error", reject);
  });
}
const apiPort = await port(),
  webPort = await port(),
  workerPort = await port();
const url = `http://127.0.0.1:${webPort}`;
const db = join(dir, "test.sqlite");
writeFileSync(db, "");
const env = {
  ...process.env,
  APP_ENV: "testing",
  APP_DEBUG: "false",
  APP_KEY: "base64:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa=",
  DB_CONNECTION: "sqlite",
  DB_DATABASE: db,
  QUEUE_CONNECTION: "database",
  CACHE_STORE: "file",
  SESSION_DRIVER: "array",
  MAIL_MAILER: "log",
  RESEARCH_WORKER_URL: `http://127.0.0.1:${workerPort}`,
  RESEARCH_WORKER_KEY: "disposable-research-browser-worker-secret-12345",
  PYTHONPATH: root,
  RESEARCH_E2E_DIR: dir,
  RESEARCH_BROWSER_URL: url,
  LARAVEL_API_URL: `http://127.0.0.1:${apiPort}/api/v1`,
  FRONTEND_URL: url,
};
function service(command, args, cwd = root) {
  const p = spawn(command, args, { cwd, env, stdio: ["ignore", log, log] });
  children.push(p);
  return p;
}
async function wait(url) {
  const start = Date.now();
  while (Date.now() - start < 60000) {
    try {
      if ((await fetch(url)).status < 500) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 250));
  }
  throw new Error("Service did not start: " + url);
}
try {
  execFileSync("php", ["artisan", "migrate", "--force"], {
    cwd: root,
    env,
    stdio: ["ignore", log, log],
  });
  const credentials = JSON.parse(
    execFileSync("php", ["tests/fixtures/research-bootstrap.php"], {
      cwd: root,
      env,
      encoding: "utf8",
    }),
  );
  env.RESEARCH_TEST_TOKEN = credentials.token;
  const fixture = join(dir, "fixture.pdf");
  execFileSync(
    "python3",
    [
      "-c",
      "from reportlab.pdfgen import canvas;import sys;c=canvas.Canvas(sys.argv[1]);c.drawString(50,750,'Synthetic PDF fixture for research import.');c.save()",
      fixture,
    ],
    { cwd: root, env },
  );
  env.RESEARCH_PDF_FIXTURE = fixture;
  service("php", [
    "artisan",
    "serve",
    "--host=127.0.0.1",
    `--port=${apiPort}`,
    "--no-reload",
  ]);
  service("python3", [
    "-m",
    "uvicorn",
    "research_worker:app",
    "--app-dir",
    "tests/fixtures",
    "--host",
    "127.0.0.1",
    "--port",
    String(workerPort),
  ]);
  service("php", [
    "artisan",
    "queue:work",
    "database",
    "--queue=research",
    "--sleep=1",
    "--tries=1",
    "--timeout=220",
  ]);
  service(
    "node",
    [
      "node_modules/next/dist/bin/next",
      "start",
      "--hostname",
      "127.0.0.1",
      "--port",
      String(webPort),
    ],
    join(root, "frontend"),
  );
  await Promise.all([
    wait(`http://127.0.0.1:${apiPort}/up`),
    wait(url),
    wait(`http://127.0.0.1:${workerPort}/health`),
  ]);
  const test = spawn(
    "node",
    [
      "node_modules/@playwright/test/cli.js",
      "test",
      "--config=e2e/playwright.config.ts",
    ],
    { cwd: join(root, "frontend"), env, stdio: "inherit" },
  );
  const code = await new Promise((resolve, reject) => {
    test.on("exit", resolve);
    test.on("error", reject);
  });
  console.log("Research E2E artifacts:", dir);
  if (code !== 0) process.exitCode = 1;
} finally {
  for (const p of children) p.kill("SIGTERM");
  closeSync(log);
}
