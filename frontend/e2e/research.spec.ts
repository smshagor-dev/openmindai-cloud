import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { writeFileSync } from "node:fs";
for (const width of [1440, 390])
  test(`research editing and persistence at ${width}px`, async ({
    page,
    context,
  }) => {
    await page.setViewportSize({ width, height: 950 });
    const origin = process.env.RESEARCH_BROWSER_URL!;
    // Each viewport represents an independent account; keep the production rate limits intact.
    const fixture = JSON.parse(
      execFileSync("php", ["tests/fixtures/research-bootstrap.php"], {
        cwd: "..",
        env: process.env,
        encoding: "utf8",
      }),
    );
    await context.addCookies([
      {
        name: "openmindai_token",
        value: fixture.token,
        url: origin,
        httpOnly: true,
        sameSite: "Lax",
      },
    ]);
    await page.goto("/research-studio");
    await expect(page.locator("[data-research-version]")).toHaveAttribute(
      "data-research-version",
      "2026-10-25-chat",
    );
    if (width < 760)
      await page
        .getByRole("button", { name: "Open project navigation" })
        .click();
    await page
      .getByRole("button", { name: "New research", exact: true })
      .click();
    await page.getByLabel("Project title").fill("Browser research " + width);
    await page
      .getByLabel("Research prompt")
      .fill("বাংলা গবেষণা বুঝে English manuscript তৈরি করুন।");
    await page.getByRole("button", { name: "Create research project" }).click();
    await expect(
      page.getByRole("heading", { name: "Browser research " + width }),
    ).toBeVisible();
    await expect(
      page.getByRole("region", { name: "Research conversation", exact: true }),
    ).toBeVisible();
    // Deterministic frontend fixture; real cross-run persistence is covered by Laravel tests.
    const chatProject = new URL(page.url()).searchParams.get("project");
    const messages: Record<string, unknown>[] = [];
    await page.route(
      `**/api/backend/research/projects/${chatProject}`,
      async (route) => {
        if (route.request().method() !== "GET") return route.continue();
        const response = await route.fetch();
        const value = await response.json();
        await route.fulfill({
          response,
          json: { ...value, conversation: messages },
        });
      },
    );
    await page.route(
      `**/api/backend/research/projects/${chatProject}/components`,
      async (route) => {
        const body = route.request().postDataJSON();
        messages.push({
          id: messages.length + 500,
          kind: body.agent,
          question: body.input.question || body.input.instruction,
          status: "completed",
          answer:
            body.agent === "selection"
              ? "Fixture reviewable revision."
              : "Fixture answer grounded in the project.",
          uncertainty: "Deterministic browser fixture",
          source_ids: [],
        });
        if (body.agent === "selection")
          expect(body.input.selection).toBeTruthy();
        await route.fulfill({
          status: 200,
          json: { id: 500, status: "completed" },
        });
      },
    );
    await page
      .getByRole("textbox", { name: "Research message", exact: true })
      .fill("বাংলা follow-up question");
    await page.getByRole("button", { name: "Send research message" }).click();
    await expect(
      page.getByText("Fixture answer grounded in the project.", {
        exact: true,
      }),
    ).toBeVisible();
    await page.reload();
    await expect(
      page.getByText("বাংলা follow-up question", { exact: true }),
    ).toBeVisible();
    await page
      .getByRole("combobox", { name: "Research action" })
      .selectOption("selection");
    await page
      .getByRole("textbox", { name: "Research message", exact: true })
      .fill("Improve clarity without inventing results");
    await page.getByRole("button", { name: "Send research message" }).click();
    await expect(
      page.getByText("Fixture reviewable revision.", { exact: true }),
    ).toBeVisible();
    const navigate = async (name: string) => {
      if (width < 760)
        await page
          .getByRole("button", { name: "Open project navigation" })
          .click();
      const tools = page.locator(".rs-tools-menu");
      if (!(await tools.evaluate((el) => (el as HTMLDetailsElement).open)))
        await tools.getByText("Research tools", { exact: true }).click();
      await tools.getByRole("button", { name, exact: true }).click();
    };
    await navigate("Manuscript");
    const editor = page.getByRole("textbox", { name: "Academic manuscript" });
    await editor.fill("A traceable manuscript for integration testing.");
    await expect(
      page.getByRole("status").filter({ hasText: "Saved · Version 2" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Table", exact: true }).click();
    await expect(editor.locator("table")).toBeVisible();
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(
      page.getByRole("status").filter({ hasText: /Saved · Version [3-9]/ }),
    ).toBeVisible();
    await page.reload();
    await expect(editor).toContainText("A traceable manuscript");
    await expect(editor.locator("table")).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBeTruthy();
    await page.screenshot({
      path: process.env.RESEARCH_E2E_DIR + `/editor-${width}.png`,
      fullPage: true,
    });
    if (width === 1440) {
      await navigate("Citations");
      await page.getByLabel("Reference import").fill(
        JSON.stringify([
          {
            id: "fixture",
            type: "article-journal",
            title: "Browser synthetic source",
            author: [{ given: "Test", family: "Fixture" }],
            issued: { "date-parts": [[2025]] },
            DOI: "10.1234/browser-fixture",
          },
        ]),
      );
      await page.getByRole("button", { name: "Import references" }).click();
      await expect(
        page.getByRole("heading", { name: "Browser synthetic source" }),
      ).toBeVisible();
      await navigate("PDF reader");
      await page
        .getByText("I have permission to use and process this file.")
        .locator("input")
        .check();
      await expect(page.getByLabel("Upload pdf")).toBeEnabled();
      await page
        .getByLabel("Upload pdf")
        .setInputFiles(process.env.RESEARCH_PDF_FIXTURE!);
      await expect(
        page.getByRole("status").filter({ hasText: "Uploaded privately" }),
      ).toBeVisible();
      const projectId = new URL(page.url()).searchParams.get("project");
      await expect
        .poll(
          async () => {
            const response = await page.request.get(
              `/api/backend/research/projects/${projectId}`,
            );
            const value = await response.json();
            return value.sources?.find(
              (s: { title: string }) => s.title === "fixture.pdf",
            )?.metadata?.parse_status;
          },
          { timeout: 20000 },
        )
        .toBe("completed");
      await page
        .getByRole("combobox", { name: "Selected source", exact: true })
        .selectOption({ label: "fixture.pdf" });
      await expect(
        page.getByText("Synthetic PDF fixture for research import.", {
          exact: false,
        }),
      ).toBeVisible();
      await navigate("Manuscript");
      await page
        .getByText("Outline, search and academic elements", { exact: true })
        .click();
      await page
        .getByRole("combobox", { name: "Citation", exact: true })
        .selectOption({ label: "Browser synthetic source" });
      await editor.click();
      await page
        .getByRole("button", { name: "Insert citation", exact: true })
        .click();
      await expect(editor.locator("[data-citation]")).toContainText("Fixture");
      await page.getByRole("button", { name: "Save", exact: true }).click();
      await expect(
        page.getByRole("status").filter({ hasText: "Saved · Version" }),
      ).toBeVisible();
      await navigate("Export");
      await page.getByRole("button", { name: "Generate export" }).click();
      const download = page
        .getByRole("link", { name: /Download validated file/ })
        .first();
      await expect(download).toBeVisible({ timeout: 30000 });
      const href = await download.getAttribute("href");
      const response = await page.request.get(href!);
      expect(response.ok()).toBeTruthy();
      const file = process.env.RESEARCH_E2E_DIR + "/browser-export.docx";
      writeFileSync(file, await response.body());
      execFileSync("python3", [
        "-c",
        "from docx import Document;import sys;d=Document(sys.argv[1]);assert d.tables;assert any('traceable manuscript' in p.text for p in d.paragraphs)",
        file,
      ]);
    }
  });
test("new research starts directly from the chat composer", async ({
  page,
  context,
}) => {
  const fixture = JSON.parse(
    execFileSync("php", ["tests/fixtures/research-bootstrap.php"], {
      cwd: "..",
      env: process.env,
      encoding: "utf8",
    }),
  );
  await context.addCookies([
    {
      name: "openmindai_token",
      value: fixture.token,
      url: process.env.RESEARCH_BROWSER_URL!,
      httpOnly: true,
      sameSite: "Lax",
    },
  ]);
  await page.setViewportSize({ width: 390, height: 950 });
  // Planning execution is mocked here; this test covers real project creation from the composer.
  await page.route("**/api/backend/research/projects/*/runs", (route) =>
    route.fulfill({ status: 200, json: { id: 700, status: "queued" } }),
  );
  await page.goto("/research-studio");
  await expect(
    page.getByRole("heading", { name: "Where does your curiosity lead?" }),
  ).toBeVisible();
  const topic = "مراجعة الأدبيات حول الملاحة البصرية للروبوتات";
  await page
    .getByRole("textbox", { name: "Research topic", exact: true })
    .fill(topic);
  await page
    .getByRole("button", { name: "Start research conversation" })
    .click();
  await expect(
    page.getByRole("heading", { name: topic, exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("article", { name: "Original research request" }),
  ).toContainText(topic);
  await page.reload();
  await expect(
    page.getByRole("article", { name: "Original research request" }),
  ).toContainText(topic);
  await page.getByRole("button", { name: /Manuscript canvas/ }).click();
  await expect(
    page.getByRole("textbox", { name: "Academic manuscript" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Close research canvas" }).click();
  await expect(
    page.getByRole("textbox", { name: "Research message", exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
  await page.screenshot({
    path: process.env.RESEARCH_E2E_DIR + "/chat-390.png",
    fullPage: true,
  });
});
test("research proxy rejects unauthenticated access", async ({ request }) => {
  const r = await request.get("/api/backend/research/projects");
  expect(r.status()).toBe(401);
});
