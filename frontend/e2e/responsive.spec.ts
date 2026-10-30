import { test, expect, type Page } from "@playwright/test";
const sizes = [
  [320, 568],
  [360, 640],
  [390, 844],
  [430, 932],
  [568, 320],
  [768, 1024],
  [820, 1180],
  [1024, 768],
  [1280, 800],
  [1440, 900],
  [1920, 1080],
];
const long = "বাংলা العربية " + "LongUnbrokenName".repeat(5);
async function fixtures(page: Page, guest = false) {
  page.on("pageerror", (e) => {
    throw e;
  });
  await page.route("**/api/backend/**", async (route) => {
    const path = new URL(route.request().url()).pathname.replace(
      "/api/backend/",
      "",
    );
    const json = (value: unknown) => route.fulfill({ json: value });
    if (path === "me")
      return guest
        ? route.fulfill({ status: 401, json: { message: "Please sign in." } })
        : json({
            id: 1,
            name: long,
            email: "responsive@example.test",
            email_verified_at: "2026-10-29",
            theme: "dark",
          });
    if (path === "models")
      return json([
        { id: -4, name: "OpenMindAI Auto", family: "Chat" },
        { id: -3, name: "OpenMindAI Ultra", family: "Chat" },
      ]);
    if (path === "capabilities")
      return json({
        media: ["image", "document_create"],
        image_generation: true,
        search: true,
        web_search: true,
      });
    if (path === "preferences")
      return json({ theme: "dark", reduced_motion: true, send_on_enter: true });
    if (path.startsWith("shared/"))
      return json({
        title: long,
        messages: [
          {
            role: "assistant",
            content:
              "Responsive rich content.\n\n| Column | Value |\n| --- | --- |\n| " +
              long +
              " | A value |\n\n```ts\nconst name = '" +
              long +
              "';\n```",
          },
        ],
      });
    if (path === "conversations/42")
      return json({
        conversation: {
          id: 42,
          title: long,
          updated_at: "2026-10-29T10:00:00Z",
        },
        messages: {
          data: [
            {
              id: 1,
              role: "assistant",
              content: "Responsive rich content.",
              status: "complete",
            },
          ],
          current_page: 1,
          last_page: 1,
        },
      });
    if (path === "conversations" || path === "media" || path === "attachments")
      return json({ data: [], last_page: 1, current_page: 1 });
    if (path === "teams") return json({ teams: [], invites: [] });
    if (path === "3d-studio/engines")
      return json({ data: [], worker: { ready: false, processors: [] } });
    if (
      [
        "3d-studio/assets",
        "3d-studio/jobs",
        "3d-studio/projects",
        "3d-studio/workflows",
        "3d-scenes/uploads",
      ].includes(path)
    )
      return json({ data: [] });
    if (path === "3d-scenes")
      return json({ data: [{ id: 1, title: long, mode: "object" }] });
    if (path === "3d-scenes/1")
      return json({
        id: 1,
        mode: "object",
        prompt: "A QA cube",
        scene: {
          title: long,
          description: long,
          nodes: [
            {
              id: "cube",
              label: long,
              shape: "box",
              position: [0, 0, 0],
              rotation: [0, 0, 0],
              scale: [1, 1, 1],
              color: "#4488ff",
            },
          ],
          edges: [],
        },
      });
    if (path === "connectors") return json({ servers: [], calls: [] });
    if (path === "developer/catalog")
      return json({ models: [], base_url: "https://api.example.test/v1" });
    if (path === "developer/usage")
      return json({ daily: [], models: [], total_credits: 0 });
    if (path === "research/projects") return json({ data: [] });
    if (path === "research/status")
      return json({
        infrastructure_ready: false,
        reasoning: { status: "setup_required" },
        worker: { status: "setup_required" },
      });
    if (path === "explore/assistants")
      return json({
        data: [{ slug: "abc", name: long, description: long, author: "QA" }],
        last_page: 1,
        current_page: 1,
      });
    return json([]);
  });
}
async function fits(page: Page, selector?: string) {
  await expect
    .poll(
      () =>
        page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      { message: "page horizontal overflow" },
    )
    .toBe(true);
  if (selector) {
    await expect
      .poll(
        async () => {
          const b = await page.locator(selector).boundingBox(),
            v = page.viewportSize()!;
          return (
            !!b &&
            b.x >= -1 &&
            b.y >= -1 &&
            b.x + b.width <= v.width + 1 &&
            b.y + b.height <= v.height + 1
          );
        },
        { message: `${selector} must fit the viewport` },
      )
      .toBe(true);
    expect(
      await page
        .locator(selector)
        .evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
      `${selector} horizontal overflow`,
    ).toBe(true);
  }
}
async function openSidebar(page: Page) {
  const menu = page.getByRole("button", { name: "Open sidebar", exact: true });
  if (await menu.isVisible()) await menu.click();
}
for (const [width, height] of sizes) {
  test(`chat and dialogs ${width}x${height}`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await fixtures(page);
    await page.goto("/");
    await expect(page.locator('a.sidebar-action[href="/3d-studio"]')).toHaveCSS(
      "text-decoration-line",
      "none",
    );
    await expect(
      page.getByRole("button", { name: "Open settings", exact: true }),
    ).toBeVisible();
    await fits(page);
    await fits(page, ".composer");
    await page.getByRole("button", { name: /Choose model:/ }).click();
    await fits(page, ".model-picker-dialog");
    await page.getByRole("button", { name: "Close model selection" }).click();
    await page.locator(".composer-tools summary").click();
    await fits(page, ".composer-tools-menu");
    await page.getByRole("button", { name: "Close tools" }).click();
    await page
      .getByRole("button", { name: "Open settings", exact: true })
      .click();
    await fits(page, "dialog[open]");
    for (const name of [
      "Personalization",
      "Account",
      "Security",
      "Devices",
      "Developer API",
      "General",
    ]) {
      await page
        .locator(".settings-nav")
        .getByRole("button", { name, exact: true })
        .click();
      await fits(page, "dialog[open]");
    }
    await page
      .getByRole("button", { name: "Close dialog", exact: true })
      .click();
    await openSidebar(page);
    await page
      .getByRole("navigation", { name: "Main navigation" })
      .getByRole("button", { name: "Library", exact: true })
      .click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await fits(page, "dialog[open]");
    for (const tab of [
      "teams",
      "connectors",
      "assistants",
      "explore",
      "tasks",
      "research",
      "library",
      "voice",
      "code",
    ]) {
      await page
        .locator(".workspace-tabs")
        .getByRole("button", { name: new RegExp("^" + tab + "$", "i") })
        .click();
      await fits(page, "dialog[open]");
    }
    await page
      .getByRole("button", { name: "Close dialog", exact: true })
      .click();
    await openSidebar(page);
    await page
      .getByRole("button", { name: "New project", exact: true })
      .click();
    await fits(page, "dialog[open]");
  });
  for (const route of [
    "/explore",
    "/research-studio",
    "/3d-studio",
    "/reset-password",
    "/confirm-email",
    `/share/${"a".repeat(64)}`,
  ]) {
    test(`standalone ${route.startsWith("/share/") ? "shared snapshot" : route.slice(1)} ${width}x${height}`, async ({
      page,
    }) => {
      await page.setViewportSize({ width, height });
      await fixtures(page);
      await page.goto(route);
      await expect(page.locator("h1")).toBeVisible();
      if (route === "/explore")
        await expect(
          page.getByRole("heading", { name: long, exact: true }),
        ).toBeVisible();
      if (route === "/research-studio" || route === "/3d-studio") {
        await expect(page.locator(".workspace-welcome")).toBeVisible();
        const composer = page.locator(
          route === "/3d-studio" ? ".studio-composer" : ".rs-chat-composer",
        );
        await expect(composer).toBeVisible();
        expect(
          await composer.evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
        ).toBe(true);
        await fits(page);
        if ([320, 768, 1440].includes(width))
          await page.screenshot({
            path: test
              .info()
              .outputPath(`startup-${route.slice(1)}-${width}.png`),
            fullPage: true,
          });
      }
      if (route === "/3d-studio") {
        await page.getByRole("button", { name: long, exact: true }).click();
        await expect(
          page.getByRole("heading", { name: long, exact: true }),
        ).toBeVisible();
      }
      await fits(page);
      if (route === "/3d-studio" && [320, 768, 1440].includes(width)) {
        await expect(
          page.locator(".studio-viewport").locator("canvas, svg"),
        ).toBeVisible();
        await page.screenshot({
          path: test.info().outputPath(`studio-${width}.png`),
          fullPage: true,
        });
      }

      if (route === "/research-studio" && width <= 760) {
        await page
          .getByRole("button", { name: "Open project navigation" })
          .click();
        await fits(page, ".rs-nav");
      }
    });
  }
  test(`saved conversation ${width}x${height}`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await fixtures(page);
    await page.goto("/c/42-responsive-qa");
    await expect(
      page.getByText("Responsive rich content.", { exact: true }),
    ).toBeVisible();
    await fits(page);
    await fits(page, ".composer");
  });
  test(`guest authentication ${width}x${height}`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await fixtures(page, true);
    await page.goto("/");
    await page
      .locator(".topbar")
      .getByRole("button", { name: "Log in", exact: true })
      .click();
    await fits(page, "dialog[open]");
  });
}

for (const viewport of [
  { width: 390, height: 844 },
  { width: 820, height: 1180 },
]) {
  if (process.env.RESPONSIVE_BROWSER !== "firefox")
    test(`touch navigation ${viewport.width}px`, async ({ browser }) => {
      const context = await browser.newContext({
        viewport,
        isMobile: true,
        hasTouch: true,
        deviceScaleFactor: 2,
      });
      const page = await context.newPage();
      try {
        await fixtures(page);
        await page.goto("/");
        await expect(
          page.getByRole("button", { name: "Open settings", exact: true }),
        ).toBeVisible();
        if (viewport.width <= 760) {
          await expect(
            page.getByRole("complementary", { name: "Chat history" }),
          ).toBeHidden();
          await openSidebar(page);
          await expect(
            page.getByRole("complementary", { name: "Chat history" }),
          ).toBeVisible();
          await page
            .getByRole("button", { name: "Close sidebar", exact: true })
            .click({ position: { x: viewport.width - 8, y: 80 } });
          await expect(
            page.getByRole("complementary", { name: "Chat history" }),
          ).toBeHidden();
        }
        await page
          .getByRole("button", { name: "Open settings", exact: true })
          .click();
        await fits(page, "dialog[open]");
        await page
          .getByRole("button", { name: "Close dialog", exact: true })
          .click();
        await page.goto("/research-studio");
        if (viewport.width <= 760) {
          await expect(page.locator(".rs-nav")).toBeHidden();
          await page
            .getByRole("button", { name: "Open project navigation" })
            .click();
          await fits(page, ".rs-nav");
          await page
            .getByRole("button", { name: "Close navigation", exact: true })
            .click({ position: { x: viewport.width - 8, y: 80 } });
          await expect(page.locator(".rs-nav")).toBeHidden();
        }
        await fits(page);
      } finally {
        await context.close();
      }
    });
}
