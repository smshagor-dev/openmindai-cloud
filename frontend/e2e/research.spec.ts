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
    await context.addCookies([
      {
        name: "openmindai_token",
        value: process.env.RESEARCH_TEST_TOKEN!,
        url: origin,
        httpOnly: true,
        sameSite: "Lax",
      },
    ]);
    await page.goto("/research-studio");
    await expect(page.locator("[data-research-version]")).toHaveAttribute(
      "data-research-version",
      "2026-10-25-r3",
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
    const navigate = async (name: string) => {
      if (width < 760)
        await page
          .getByRole("button", { name: "Open project navigation" })
          .click();
      await page.getByRole("button", { name, exact: true }).click();
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
      await page
        .getByLabel("Reference import")
        .fill(
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
      const projectId=new URL(page.url()).searchParams.get('project');
      await expect.poll(async()=>{const response=await page.request.get(`/api/backend/research/projects/${projectId}`);const value=await response.json();return value.sources?.find((s:{title:string})=>s.title==='fixture.pdf')?.metadata?.parse_status;},{timeout:20000}).toBe('completed');
      await page.getByLabel('Selected source').selectOption({label:'fixture.pdf'});
      await expect(page.getByText('Synthetic PDF fixture for research import.',{exact:false})).toBeVisible();
      await navigate("Manuscript");
      await page
        .getByText("Outline, search and academic elements", { exact: true })
        .click();
      await page
        .getByLabel("Citation", { exact: true })
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
test("research proxy rejects unauthenticated access", async ({ request }) => {
  const r = await request.get("/api/backend/research/projects");
  expect(r.status()).toBe(401);
});
