import { test, expect, type Page } from "@playwright/test";
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
        await expect(
          page.getByRole("button", { name: "Auto Detect", exact: true }),
        ).toHaveAttribute("aria-pressed", "true");
        if (mode === "architecture")
          await page
            .getByRole("button", { name: "3D Architecture", exact: true })
            .click();
        await page.locator("#scene-prompt").fill("A robot with a camera");
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
        await page
          .getByRole("button", { name: "New scene", exact: true })
          .click();
        await page
          .getByRole("button", { name: "Delivery robot", exact: true })
          .click();
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
        await page
          .getByRole("button", { name: "Delete Delivery robot", exact: true })
          .click();
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
  await expect(page.getByRole("main").getByRole("alert")).toContainText(
    "Please sign in.",
  );
  await page.locator("#scene-prompt").fill("A robot with wheels");
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
  await page.locator(".studio-references input[type=file]").setInputFiles({
    name: "robot.md",
    mimeType: "text/markdown",
    buffer: Buffer.from("# Blue body"),
  });
  await expect(
    page.getByText("robot.md", { exact: true }).last(),
  ).toBeVisible();
  await page.locator("#scene-prompt").fill("Use the blue body reference");
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
  await page
    .getByRole("button", { name: "Reference cube", exact: true })
    .click();
  await expect(page.locator(".studio-viewport svg")).toBeVisible();
  await page
    .getByText("Saved references (1 selected)", { exact: true })
    .click();
  await expect(page.getByRole("checkbox", { name: "robot.md" })).toBeChecked();
});

test("Studio palette stays consistent and its theme preference survives reload", async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: "light" });
  await mockWorkbench(page, () => {});
  await page.route("**/api/backend/3d-scenes**", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  await page.goto("/3d-studio");
  const shell = page.locator(".studio-shell");
  await expect(shell).not.toHaveClass(/studio-light/);
  await expect(page.locator(".studio-workbench")).toHaveCSS(
    "background-color",
    "rgb(21, 30, 44)",
  );
  await page.screenshot({
    path: test.info().outputPath("studio-dark.png"),
    fullPage: true,
  });
  await page.getByRole("button", { name: "Use light theme" }).click();
  await expect(shell).toHaveClass(/studio-light/);
  await expect(shell).toHaveCSS("background-color", "rgb(248, 250, 252)");
  await page.screenshot({
    path: test.info().outputPath("studio-light.png"),
    fullPage: true,
  });
  await page.reload();
  await expect(shell).toHaveClass(/studio-light/);
  await page.getByRole("button", { name: "Use dark theme" }).click();
  await expect(shell).not.toHaveClass(/studio-light/);
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
    await expect(page.locator("#scene-prompt")).toBeDisabled();
    await expect(
      page.getByRole("button", { name: "Auto Detect", exact: true }),
    ).toBeDisabled();
  } finally {
    release();
  }
  await expect(page.locator("#scene-prompt")).toBeEnabled();
  await page.locator("#scene-prompt").fill("Keep this early prompt");
  await expect(page.locator("#scene-prompt")).toHaveValue(
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
    if (cpanelMode === "architecture")
      await page
        .getByRole("button", { name: "3D Architecture", exact: true })
        .click();
    await page.locator("#scene-prompt").fill("Build a robot");
    await page.getByRole("button", { name: "Generate scene" }).click();
    await expect(
      page.getByRole("heading", { name: scene.title, exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Generate scene" }),
    ).toBeEnabled();
    expect(keys).toHaveLength(1);
    expect(polls).toEqual([]);
    await page
      .getByRole("spinbutton", { name: "position X", exact: true })
      .fill("3");
    await page.getByRole("button", { name: "Save scene revision" }).click();
    await expect(page.getByLabel("Previous revisions")).toBeVisible();
    expect(scene.nodes[0].position[0]).toBe(3);
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
  await page.locator("#scene-prompt").fill("Build a robot");
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
