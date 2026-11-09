import { test, expect, type Page } from "@playwright/test";
async function openTools(page: Page) {
  const tools = page.locator(".composer-tools");
  if (!(await tools.evaluate(el => (el as HTMLDetailsElement).open))) await tools.locator("summary").click();
}
async function sidebarAction(page: Page, name: string) {
  if ((page.viewportSize()?.width || 1440) <= 760) await page.getByRole("button", { name: "Open sidebar", exact: true }).click();
  await page.locator(".sidebar").getByRole("button", { name, exact: true }).click();
  const close = page.getByRole("button", { name: "Close sidebar", exact: true });
  if (await close.isVisible()) await close.click({ position: { x: (page.viewportSize()?.width || 390) - 5, y: 5 } });
}
async function editGeometry(page: Page) {
  const details = page.locator("details.studio-edit");
  if (!(await details.evaluate(el => (el as HTMLDetailsElement).open))) await details.locator("summary").click();
}
async function selectArchitecture(page: Page) {
  await openTools(page);
  await page.getByRole("button", { name: /^3D architecture/ }).click();
}
async function mockWorkbench(
  page: Page,
  onSubmit: (body: { mode: string; upload_ids: number[] }) => void,
  failure = false,
) {
  const job = {
    id: "12345678-1234-4234-8234-123456789abc",
    engine: "workflow",
    operation: "workflow",
    status: "completed",
    result: { scene_id: 1 },
  };
  await page.route("**/api/backend/3d-studio/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/engines"))
      return route.fulfill({
        json: {
          data: [
            {
              id: "procedural",
              name: "Procedural 3D",
              available: true,
              input: "text",
              output: "scene",
              kind: "scene",
            },
          ],
          worker: { ready: false },
        },
      });
    if (path.endsWith("/route")) {
      if (failure)
        return route.fulfill({
          status: 503,
          json: { message: "Provider unavailable." },
        });
      const body = route.request().postDataJSON();
      return route.fulfill({
        json: {
          mode: body.mode === "architecture" ? "architecture" : "object",
          engine: "procedural",
          output: "scene",
        },
      });
    }
    if (path.endsWith("/jobs/scene")) {
      onSubmit(route.request().postDataJSON());
      return route.fulfill({ status: 202, json: { ...job, status: "queued" } });
    }
    if (path.endsWith(job.id)) return route.fulfill({ json: job });
    return route.fulfill({ json: { data: [] } });
  });
}
for (const compatibility of [false, true]) {
  for (const width of [1440, 768, 375, 320]) {
    for (const mode of ["object", "architecture"] as const) {
      test(`3D ${mode} ${compatibility ? "compatible" : "default"}: generate, export, reopen and delete at ${width}px`, async ({
        page,
      }) => {
        await page.setViewportSize({ width, height: 1000 });
        if (compatibility)
          await page.addInitScript(() => {
            const original = HTMLCanvasElement.prototype.getContext;
            HTMLCanvasElement.prototype.getContext = function (
              this: HTMLCanvasElement,
              kind: string,
              ...args: unknown[]
            ) {
              if (["webgl", "webgl2", "experimental-webgl"].includes(kind))
                return null;
              return (original as Function).apply(this, [kind, ...args]);
            } as typeof original;
          });
        const scene = {
          title: "Delivery robot",
          description: "Procedural test scene",
          nodes: [
            {
              id: "body",
              label: "Body",
              shape: "box",
              position: [0, 1, 0],
              rotation: [0, 0, 0],
              scale: [2, 1, 1],
              color: "#4488ff",
            },
            {
              id: "camera",
              label: "Camera",
              shape: "sphere",
              position: [3, 1, 0],
              rotation: [0, 0, 0],
              scale: [1, 1, 1],
              color: "#44ff88",
            },
          ],
          edges:
            mode === "architecture"
              ? [{ from: "body", to: "camera", label: "Telemetry" }]
              : [],
        };
        let exists = false;
        let savedVersion = 1;
        let savedScene = structuredClone(scene);
        await mockWorkbench(page, (body) => {
          expect(body.mode).toBe(mode);
          exists = true;
        });
        await page.route("**/api/backend/3d-scenes**", async (route) => {
          const req = route.request(),
            list = new URL(req.url()).pathname.endsWith("3d-scenes");
          if (new URL(req.url()).pathname.endsWith("/uploads"))
            return route.fulfill({ json: { data: [] } });
          if (new URL(req.url()).pathname.endsWith("/revisions"))
            return route.fulfill({
              json: { data: [{ version: 1, scene: JSON.stringify(scene) }] },
            });
          if (req.method() === "PUT") {
            const input = req.postDataJSON();
            expect(input.version).toBe(savedVersion);
            savedScene = input.scene;
            savedVersion++;
            return route.fulfill({
              json: {
                id: 1,
                mode,
                prompt: "A robot with a camera",
                scene: savedScene,
                version: savedVersion,
              },
            });
          }
          if (req.method() === "POST") {
            expect(req.postDataJSON().mode).toBe(mode);
            exists = true;
            return route.fulfill({
              status: 201,
              json: { id: 1, mode, prompt: "A robot with a camera", scene },
            });
          }
          if (req.method() === "DELETE") {
            exists = false;
            return route.fulfill({ status: 204 });
          }
          return route.fulfill({
            json: list
              ? { data: exists ? [{ id: 1, title: scene.title, mode }] : [] }
              : {
                  id: 1,
                  mode,
                  prompt: "A robot with a camera",
                  scene: savedScene,
                  version: savedVersion,
                },
          });
        });
        page.on("dialog", (dialog) => dialog.accept());
        const errors: string[] = [];
        page.on("pageerror", (e) => errors.push(e.message));
        await page.goto("/3d-studio");
        await openTools(page);
        await expect(page.getByRole("button", { name: /^Auto detect/ })).toHaveAttribute("aria-pressed", "true");
        await page.locator(".composer-tools summary").click();
        if (mode === "architecture") await selectArchitecture(page);
        await page.getByRole("textbox", { name: "Describe your scene", exact: true }).fill("A robot with a camera");
        await page.getByRole("button", { name: "Generate scene" }).click();
        await expect(
          page.getByRole("heading", { name: "Delivery robot" }),
        ).toBeVisible();
        await expect(
          page.locator(".studio-viewport").locator("canvas, svg"),
        ).toBeVisible();
        if (compatibility)
          await expect(
            page.getByRole("img", { name: "Compatibility 3D preview" }),
          ).toBeVisible();
        if (mode === "architecture")
          await expect(
            page.getByRole("heading", { name: "Delivery robot" }),
          ).toBeVisible();
        const download = page.waitForEvent("download");
        await page
          .getByRole("button", { name: "Download GLB", exact: true })
          .click();
        const file = await download;
        expect(file.suggestedFilename()).toBe("openmind-scene.glb");
        const stream = await file.createReadStream();
        const chunks: Buffer[] = [];
        for await (const chunk of stream!) chunks.push(Buffer.from(chunk));
        expect(Buffer.concat(chunks).subarray(0, 4).toString()).toBe("glTF");
        await page.getByRole("button", { name: "Reset view" }).click();
        await editGeometry(page);
        await page
          .getByRole("spinbutton", { name: "position X", exact: true })
          .fill("4");
        await page.getByRole("button", { name: "Undo", exact: true }).click();
        await expect(
          page.getByRole("spinbutton", { name: "position X", exact: true }),
        ).toHaveValue("0");
        await page.getByRole("button", { name: "Redo", exact: true }).click();
        await page.getByRole("button", { name: "Save scene revision" }).click();
        await expect(page.getByLabel("Previous revisions")).toBeVisible();
        expect(savedScene.nodes[0].position[0]).toBe(4);
        const overflowing = await page.evaluate(() =>
          Array.from(document.querySelectorAll("body *"))
            .filter(
              (element) =>
                element.getBoundingClientRect().right > innerWidth + 1,
            )
            .map((element) => ({
              tag: element.tagName,
              className: element.className,
              width: element.getBoundingClientRect().width,
            }))
            .slice(0, 20),
        );
        expect(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
          ),
          JSON.stringify(overflowing),
        ).toBe(true);
        await sidebarAction(page, "New scene");
        await sidebarAction(page, "Delivery robot");
        await editGeometry(page);
        await expect(
          page.locator(".studio-viewport").locator("canvas, svg"),
        ).toBeVisible();
        if (compatibility)
          await expect(
            page.getByRole("img", { name: "Compatibility 3D preview" }),
          ).toBeVisible();
        await expect(
          page.getByRole("spinbutton", { name: "position X", exact: true }),
        ).toHaveValue("4");
        await sidebarAction(page, "Delete Delivery robot");
        await expect(
          page.getByRole("heading", { name: "Delivery robot" }),
        ).toHaveCount(0);
        expect(errors).toEqual([]);
      });
    }
  }
}
test("3D Studio exposes authentication and provider failures", async ({
  page,
}) => {
  await page.route("**/api/backend/3d-scenes/uploads", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  await page.route("**/api/backend/3d-scenes", (route) =>
    route.fulfill({
      status: route.request().method() === "GET" ? 401 : 502,
      json: {
        message:
          route.request().method() === "GET"
            ? "Please sign in."
            : "Provider unavailable.",
      },
    }),
  );
  await mockWorkbench(page, () => {}, true);
  await page.goto("/3d-studio");
  await expect(page.locator(".sidebar").getByRole("link", { name: /Log in/ })).toBeVisible();
  await expect(page.getByRole("main").getByRole("alert")).toHaveCount(0);
  await page.getByRole("textbox", { name: "Describe your scene", exact: true }).fill("A robot with wheels");
  await page.getByRole("button", { name: "Generate scene" }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText(
    "Provider unavailable.",
  );
  await expect(
    page.getByRole("button", { name: "Generate scene" }),
  ).toBeEnabled();
});

test("uploads Markdown privately and exports GLB using compatible preview without WebGL", async ({
  page,
}) => {
  await page.addInitScript(() => {
    const original = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function (
      this: HTMLCanvasElement,
      type: string,
      ...args: unknown[]
    ) {
      if (type.includes("webgl")) return null;
      return original.apply(this, [type, ...args] as never);
    } as typeof original;
  });
  const scene = {
    title: "Reference cube",
    description: "Blue reference body",
    nodes: [
      {
        id: "body",
        label: "Blue body",
        shape: "box",
        position: [0, 1, 0],
        rotation: [0, 0, 0],
        scale: [2, 1, 1],
        color: "#4488ff",
      },
    ],
    edges: [],
  };
  let uploaded = false,
    exists = false;
  await page.route("**/api/backend/3d-scenes**", async (route) => {
    const request = route.request(),
      path = new URL(request.url()).pathname;
    if (path.endsWith("/uploads")) {
      if (request.method() === "POST") {
        const data = request.postDataJSON();
        expect(Buffer.from(data.data, "base64").toString()).toBe("# Blue body");
        expect(data.text).toBe("# Blue body");
        uploaded = true;
        return route.fulfill({
          status: 201,
          json: { id: 7, name: "robot.md", mime: "text/plain", bytes: 11 },
        });
      }
      return route.fulfill({
        json: {
          data: uploaded
            ? [{ id: 7, name: "robot.md", mime: "text/plain", bytes: 11 }]
            : [],
        },
      });
    }
    if (request.method() === "POST") {
      expect(request.postDataJSON().upload_ids).toEqual([7]);
      exists = true;
      return route.fulfill({
        status: 201,
        json: {
          id: 1,
          mode: "object",
          prompt: "Use the blue body reference",
          upload_ids: [7],
          scene,
        },
      });
    }
    return route.fulfill({
      json: path.endsWith("3d-scenes")
        ? {
            data: exists ? [{ id: 1, title: scene.title, mode: "object" }] : [],
          }
        : {
            id: 1,
            mode: "object",
            prompt: "Use the blue body reference",
            upload_ids: [7],
            scene,
          },
    });
  });
  await mockWorkbench(page, (body) => {
    expect(body.upload_ids).toEqual([7]);
    exists = true;
  });
  await page.goto("/3d-studio");
  await expect(page.getByRole("button", { name: "Add reference files", exact: true })).toBeEnabled();
  await page.locator(".composer input[type=file]").setInputFiles({
    name: "robot.md",
    mimeType: "text/markdown",
    buffer: Buffer.from("# Blue body"),
  });
  await expect(
    page.locator(".attachment-chips").getByText("robot.md", { exact: true }),
  ).toBeVisible();
  await page.getByRole("textbox", { name: "Describe your scene", exact: true }).fill("Use the blue body reference");
  await page.getByRole("button", { name: "Generate scene" }).click();
  await expect(page.locator(".studio-viewport svg")).toBeVisible();
  await expect(page.getByText(/Compatible preview mode/)).toBeVisible();
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download GLB", exact: true }).click();
  const file = await download;
  const stream = await file.createReadStream();
  const chunks: Buffer[] = [];
  for await (const chunk of stream!) chunks.push(Buffer.from(chunk));
  expect(Buffer.concat(chunks).subarray(0, 4).toString()).toBe("glTF");
  await page.reload();
  await sidebarAction(page, "Reference cube");
  await expect(page.locator(".studio-viewport svg")).toBeVisible();
  await openTools(page);
  await expect(page.getByRole("checkbox", { name: "robot.md" })).toBeChecked();
});

test("Studio palette follows the saved account theme across reload", async ({ page }) => {
  let theme = "dark";
  await page.emulateMedia({ colorScheme: "light" });
  await page.route("**/api/backend/me", route => route.fulfill({ json: { id: 1, name: "Theme fixture" } }));
  await page.route("**/api/backend/preferences", route => route.fulfill({ json: { theme, accent_color: "default", text_size: "default", reduced_motion: false } }));
  await mockWorkbench(page, () => {});
  await page.route("**/api/backend/3d-scenes**", route => route.fulfill({ json: { data: [] } }));
  await page.goto("/3d-studio");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await expect(page.locator(".workspace")).toHaveCSS("background-color", "rgb(33, 33, 33)");
  await page.screenshot({ path: test.info().outputPath("studio-dark.png"), fullPage: true });
  theme = "light";
  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await expect(page.locator(".workspace")).toHaveCSS("background-color", "rgb(255, 255, 255)");
  await page.screenshot({ path: test.info().outputPath("studio-light.png"), fullPage: true });
  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  theme = "dark";
  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
});

test("Studio preserves early input by waiting for client initialization", async ({
  page,
}) => {
  await mockWorkbench(page, () => {});
  await page.route("**/api/backend/3d-scenes**", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  let release!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route("**/_next/static/**/*.js", async (route) => {
    await gate;
    await route.continue();
  });
  try {
    await page.goto("/3d-studio", { waitUntil: "commit" });
    await expect(page.getByRole("textbox", { name: "Describe your scene", exact: true })).toBeDisabled();
    await expect(
      page.getByRole("button", { name: "Generate scene", exact: true }),
    ).toBeDisabled();
  } finally {
    release();
  }
  await expect(page.getByRole("textbox", { name: "Describe your scene", exact: true })).toBeEnabled();
  await page.getByRole("textbox", { name: "Describe your scene", exact: true }).fill("Keep this early prompt");
  await expect(page.getByRole("textbox", { name: "Describe your scene", exact: true })).toHaveValue(
    "Keep this early prompt",
  );
  await expect(
    page.getByRole("button", { name: "Generate scene" }),
  ).toBeEnabled();
});

for (const cpanelMode of ["object", "architecture"] as const) {
  test(`cPanel ${cpanelMode} generates and edits without a queue worker`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    let exists = false,
      version = 1;
    let scene = {
      title: "cPanel robot",
      description: "Fixture scene",
      nodes: [
        {
          id: "body",
          label: "Body",
          shape: "box",
          position: [0, 0, 0],
          rotation: [0, 0, 0],
          scale: [1, 1, 1],
          color: "#4488ff",
        },
      ],
      edges: [],
    };
    const keys: string[] = [],
      polls: string[] = [];
    await page.route("**/api/backend/3d-studio/**", async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith("/engines"))
        return route.fulfill({
          json: {
            data: [],
            deployment: { scene_execution: "direct", queued_jobs: false },
            worker: { ready: false, processors: [] },
          },
        });
      if (path.endsWith("/route"))
        return route.fulfill({
          json: {
            mode: cpanelMode,
            engine:
              cpanelMode === "architecture" ? "architecture" : "procedural",
            output: "scene",
          },
        });
      if (path.endsWith("/jobs/scene")) {
        const body = route.request().postDataJSON();
        expect(body.mode).toBe(cpanelMode);
        keys.push(body.request_key);
        exists = true;
        return route.fulfill({
          status: 201,
          json: {
            id: 1,
            mode: cpanelMode,
            prompt: body.prompt,
            scene,
            version,
          },
        });
      }
      if (/\/jobs\/[a-f0-9-]{36}$/.test(path)) polls.push(path);
      return route.fulfill({ json: { data: [] } });
    });
    await page.route("**/api/backend/3d-scenes**", async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith("/uploads"))
        return route.fulfill({ json: { data: [] } });
      if (path.endsWith("/revisions"))
        return route.fulfill({
          json: { data: [{ version: 1, scene: JSON.stringify(scene) }] },
        });
      if (route.request().method() === "PUT") {
        scene = route.request().postDataJSON().scene;
        version++;
      }
      return route.fulfill({
        json: path.endsWith("3d-scenes")
          ? {
              data: exists
                ? [{ id: 1, mode: cpanelMode, title: scene.title }]
                : [],
            }
          : {
              id: 1,
              mode: cpanelMode,
              prompt: "Build a robot",
              scene,
              version,
            },
      });
    });
    await page.goto("/3d-studio");
    if (cpanelMode === "architecture") await selectArchitecture(page);
    await page.getByRole("textbox", { name: "Describe your scene", exact: true }).fill("Build a robot");
    await page.getByRole("button", { name: "Generate scene" }).click();
    await expect(
      page.getByRole("heading", { name: scene.title, exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("button", { name: "Apply changes to scene" })).toBeDisabled();
    expect(keys).toHaveLength(1);
    expect(polls).toEqual([]);
    await editGeometry(page);
    await page.getByRole("spinbutton", { name: "position X", exact: true }).fill("3");
    await page.getByRole("button", { name: "Save scene revision" }).click();
    await expect(page.getByLabel("Previous revisions")).toBeVisible();
    expect(scene.nodes[0].position[0]).toBe(3);
    await sidebarAction(page, "Assets & workflows");
    await page
      .getByRole("tab", { name: "Workflow editor", exact: true })
      .click();
    await expect(
      page.getByRole("button", { name: "Run validated workflow" }),
    ).toBeDisabled();
    await expect(
      page.getByRole("button", { name: "Save workflow" }),
    ).toBeEnabled();
  });
}

test("cPanel reconnect retries reuse the request key instead of charging a second generation", async ({
  page,
}) => {
  const keys: string[] = [];
  const scene = {
    title: "Recovered robot",
    description: "Fixture scene",
    nodes: [
      {
        id: "body",
        label: "Body",
        shape: "box",
        position: [0, 0, 0],
        rotation: [0, 0, 0],
        scale: [1, 1, 1],
        color: "#4488ff",
      },
    ],
    edges: [],
  };
  await page.route("**/api/backend/3d-scenes**", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  await page.route("**/api/backend/3d-studio/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/engines"))
      return route.fulfill({
        json: {
          data: [],
          deployment: { scene_execution: "direct", queued_jobs: false },
          worker: { ready: false },
        },
      });
    if (path.endsWith("/route"))
      return route.fulfill({
        json: { mode: "object", engine: "procedural", output: "scene" },
      });
    if (path.endsWith("/jobs/scene")) {
      keys.push(route.request().postDataJSON().request_key);
      if (keys.length === 1) return route.abort("connectionreset");
      return route.fulfill({
        json: {
          id: 1,
          mode: "object",
          prompt: "Build a robot",
          scene,
          version: 1,
        },
      });
    }
    return route.fulfill({ json: { data: [] } });
  });
  await page.goto("/3d-studio");
  await page.getByRole("textbox", { name: "Describe your scene", exact: true }).fill("Build a robot");
  await page.getByRole("button", { name: "Generate scene" }).click();
  await expect(
    page.getByRole("alert").filter({ hasText: /.+/ }).first(),
  ).toBeVisible();
  await page.getByRole("button", { name: "Generate scene" }).click();
  await expect(
    page.getByRole("heading", { name: "Recovered robot" }),
  ).toBeVisible();
  expect(keys).toHaveLength(2);
  expect(keys[0]).toBe(keys[1]);
});

test("hosted full 3D submits once, tracks the saved job and opens its private GLB", async ({
  page,
}) => {
  const id = "55555555-1111-4111-8111-555555555555";
  const document = {
    asset: { version: "2.0" },
    scene: 0,
    scenes: [{ nodes: [0] }],
    nodes: [{ mesh: 0 }],
    meshes: [{ primitives: [{ attributes: { POSITION: 0 } }] }],
    buffers: [{ byteLength: 36 }],
    bufferViews: [{ buffer: 0, byteOffset: 0, byteLength: 36 }],
    accessors: [
      {
        bufferView: 0,
        componentType: 5126,
        count: 3,
        type: "VEC3",
        min: [0, 0, 0],
        max: [1, 1, 0],
      },
    ],
  };
  let json = JSON.stringify(document);
  json = json.padEnd(Math.ceil(json.length / 4) * 4, " ");
  const binary = Buffer.alloc(36);
  [0, 0, 0, 1, 0, 0, 0, 1, 0].forEach((value, index) =>
    binary.writeFloatLE(value, index * 4),
  );
  const glb = Buffer.alloc(28 + Buffer.byteLength(json) + binary.length);
  glb.write("glTF");
  glb.writeUInt32LE(2, 4);
  glb.writeUInt32LE(glb.length, 8);
  glb.writeUInt32LE(Buffer.byteLength(json), 12);
  glb.write("JSON", 16);
  glb.write(json, 20);
  glb.writeUInt32LE(binary.length, 20 + Buffer.byteLength(json));
  glb.write("BIN\0", 24 + Buffer.byteLength(json));
  binary.copy(glb, 28 + Buffer.byteLength(json));
  const asset = {
    id: 44,
    name: "hosted-model.glb",
    kind: "mesh",
    format: "glb",
    bytes: glb.length,
    parent_id: null,
    project_id: null,
    metadata: { source: "fal-trellis-2", geometry: "generated_mesh" },
  };
  let submitted = false,
    polls = 0,
    submissions = 0;
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.route("**/api/backend/**", async (route) => {
    const request = route.request(),
      path = new URL(request.url()).pathname;
    const reply = (json: unknown, status = 200) =>
      route.fulfill({ status, json });
    if (path.endsWith("/me")) return reply({ id: 1, name: "Mesh fixture" });
    if (path.endsWith("/preferences")) return reply({ theme: "dark" });
    if (path.endsWith("/engines"))
      return reply({
        data: [
          {
            id: "fal-trellis-2",
            name: "Full 3D mesh (TRELLIS 2)",
            kind: "model",
            input: "image",
            output: "hosted_mesh",
            available: true,
          },
        ],
        deployment: { scene_execution: "direct", queued_jobs: false },
        worker: { ready: false },
      });
    if (path.endsWith("/uploads") && request.method() === "POST")
      return reply(
        { id: 7, name: "cube.png", mime: "image/png", bytes: 70 },
        201,
      );
    if (path.endsWith("/route"))
      return reply({
        engine: "fal-trellis-2",
        mode: "object",
        output: "hosted_mesh",
      });
    if (path.endsWith("/jobs/image-model")) {
      submissions++;
      submitted = true;
      expect(request.postDataJSON()).toMatchObject({
        engine: "fal-trellis-2",
        upload_id: 7,
      });
      return reply(
        {
          id,
          engine: "fal-trellis-2",
          operation: "hosted_mesh",
          status: "queued",
        },
        202,
      );
    }
    if (path.endsWith("/jobs/" + id)) {
      polls++;
      return reply({
        id,
        engine: "fal-trellis-2",
        operation: "hosted_mesh",
        status: polls > 1 ? "completed" : "running",
        ...(polls > 1 ? { result: { asset_id: 44, format: "glb" } } : {}),
      });
    }
    if (path.endsWith("/assets/44/data"))
      return reply({ format: "glb", data: glb.toString("base64") });
    if (path.endsWith("/assets"))
      return reply({ data: submitted && polls > 1 ? [asset] : [] });
    if (path.endsWith("/jobs")) return reply({ data: [] });
    return reply({ data: [] });
  });
  await page.goto("/3d-studio");
  await expect(
    page.getByRole("button", { name: "Add reference files", exact: true }),
  ).toBeEnabled();
  await page
    .locator(".composer input[type=file]")
    .setInputFiles({
      name: "cube.png",
      mimeType: "image/png",
      buffer: Buffer.from(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7WQAAAAASUVORK5CYII=",
        "base64",
      ),
    });
  await expect(page.locator(".attachment-chips")).toContainText("cube.png");
  await page
    .getByRole("textbox", { name: "Describe your scene", exact: true })
    .fill("Generate a full 3D mesh from this reference");
  await page.getByRole("button", { name: "Generate scene" }).click();
  await expect(
    page.getByRole("link", { name: "Download GLB", exact: true }),
  ).toBeVisible({ timeout: 20000 });
  expect(submissions).toBe(1);
  expect(polls).toBeGreaterThan(1);
  await expect(page.getByText(/Front-facing \(2.5D\)/)).toHaveCount(0);
  expect(errors).toEqual([]);
});
