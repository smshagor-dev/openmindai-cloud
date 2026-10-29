import { test, expect } from "@playwright/test";
for (const width of [1440, 375]) {
  for (const mode of ["object", "architecture"] as const) {
    test(`3D ${mode}: generate, export, reopen and delete at ${width}px`, async ({
      page,
    }) => {
      await page.setViewportSize({ width, height: 1000 });
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
      await page.route("**/api/backend/3d-scenes**", async (route) => {
        const req = route.request(),
          list = new URL(req.url()).pathname.endsWith("3d-scenes");
        if (new URL(req.url()).pathname.endsWith("/uploads"))
          return route.fulfill({ json: { data: [] } });
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
            : { id: 1, mode, prompt: "A robot with a camera", scene },
        });
      });
      page.on("dialog", (dialog) => dialog.accept());
      const errors: string[] = [];
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto("/3d-studio");
      if (mode === "architecture")
        await page
          .getByRole("button", { name: "Architecture", exact: true })
          .click();
      await page.getByRole("textbox").fill("A robot with a camera");
      await page.getByRole("button", { name: "Generate scene" }).click();
      await expect(
        page.getByRole("heading", { name: "Delivery robot" }),
      ).toBeVisible();
      await expect(page.locator("canvas")).toBeVisible();
      if (mode === "architecture")
        await expect(
          page.locator(".studio-label").filter({ hasText: "Telemetry" }),
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
      expect(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth,
        ),
      ).toBe(true);
      await page
        .getByRole("button", { name: "New scene", exact: true })
        .click();
      await page
        .getByRole("button", { name: "Delivery robot", exact: true })
        .click();
      await expect(page.locator("canvas")).toBeVisible();
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
  await page.goto("/3d-studio");
  await expect(page.getByRole("main").getByRole("alert")).toContainText(
    "Please sign in.",
  );
  await page.getByRole("textbox").fill("A robot with wheels");
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
  await page.goto("/3d-studio");
  await page.locator("input[type=file]").setInputFiles({
    name: "robot.md",
    mimeType: "text/markdown",
    buffer: Buffer.from("# Blue body"),
  });
  await expect(
    page.getByText("robot.md", { exact: true }).last(),
  ).toBeVisible();
  await page.getByRole("textbox").fill("Use the blue body reference");
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
