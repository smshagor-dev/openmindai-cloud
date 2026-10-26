import { test, expect } from "@playwright/test";
// Browser fixtures exercise the production React UI and SSE parser. Provider and
// database boundaries are tested separately; these are not live-provider tests.
for (const width of [1920, 1440, 1280, 1024, 768, 430, 375, 320]) {
  test(`chat research evidence, pause, reload and resume at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 1000 });
    const sources = [
      {
        url: "https://primary.example/paper.pdf",
        title: "GPS navigation measurements",
        citation: 1,
        read_status: "read",
        mime: "application/pdf",
        passages: [
          {
            id: "p1",
            page: 24,
            text: "GPS navigation reached 97.5 percent accuracy in published baseline measurements.",
          },
        ],
        pdf: {
          total_pages: 24,
          sampled_pages: [1, 4, 7, 10, 14, 17, 20, 24],
          partial: true,
          ocr: false,
        },
      },
    ];
    const activity = [
      {
        id: 1,
        at: "2026-10-26T12:00:00Z",
        phase: "searching",
        status: "completed",
        label: "Search results collected",
        query: "GPS navigation accuracy",
        provider: "fixture-provider",
      },
      {
        id: 2,
        at: "2026-10-26T12:00:01Z",
        phase: "reading",
        status: "completed",
        label: "Relevant page passages extracted",
        url: sources[0].url,
      },
    ];
    const metadata = {
      version: 1,
      status: "paused",
      plan: [
        {
          id: "o1",
          text: "বাংলায় GPS accuracy এবং limitations ব্যাখ্যা করুন",
          status: "open",
          source_ids: [],
        },
      ],
      activity,
      limitations: [
        "Execution budget reached; remaining questions can resume.",
      ],
      coverage: { actions: 2, sources: 1, pagesRead: 1, domainsRead: 1 },
    };
    let saved = false,
      resumed = false;
    const conversation = {
      id: 12,
      title: "GPS research",
      updated_at: "2026-10-26T12:00:00Z",
    };
    const messages = () => [
      { id: 30, role: "user", content: "বাংলায় GPS accuracy গবেষণা করুন।" },
      {
        id: 31,
        role: "assistant",
        content: resumed
          ? "GPS navigation reached 97.5 percent accuracy [1](https://primary.example/paper.pdf)."
          : "",
        status: resumed ? "complete" : "failed",
        resumable: !resumed,
        sources,
        research_metadata: {
          ...metadata,
          status: resumed ? "partial" : "paused",
        },
      },
    ];
    await page.route("**/api/backend/**", async (route) => {
      const path = new URL(route.request().url()).pathname.replace(
        "/api/backend/",
        "",
      );
      const json = (value: unknown) => route.fulfill({ json: value });
      if (path === "me")
        return json({
          id: 1,
          name: "Research tester",
          email: "fixture@example.test",
          email_verified_at: "2026-10-26",
        });
      if (path === "models")
        return json([{ id: -1, name: "OpenMindAI Lite", family: "Chat" }]);
      if (path === "preferences")
        return json({
          theme: "dark",
          reduced_motion: true,
          send_on_enter: true,
        });
      if (path === "capabilities") return json({ web_search: true, media: [] });
      if (
        path.startsWith("conversations/12") &&
        route.request().method() === "GET"
      )
        return json({
          conversation,
          messages: { data: messages(), current_page: 1, last_page: 1 },
        });
      if (
        path.startsWith("conversations") &&
        route.request().method() === "GET"
      )
        return json({
          data: saved ? [conversation] : [],
          current_page: 1,
          last_page: 1,
        });
      if (path === "chat/stream" || path === "chat/resume") {
        if (path.endsWith("/resume")) {
          expect(route.request().postDataJSON().resume_message_id).toBe(31);
          resumed = true;
        } else expect(route.request().postDataJSON().deep_research).toBe(true);
        saved = true;
        const events = [
          [
            "meta",
            {
              conversation_id: 12,
              user_message_id: 30,
              message_id: 31,
              sources: [],
              resumable: true,
            },
          ],
          [
            "research",
            {
              label: "Evidence inspected",
              sources,
              metadata: { ...metadata, status: resumed ? "partial" : "paused" },
            },
          ],
          ...(resumed ? [["answer", {content: messages()[1].content}]] : []),
          ["done", { status: resumed ? "complete" : "failed", saved: true }],
        ];
        return route.fulfill({
          contentType: "text/event-stream",
          body: events
            .map(
              ([event, data]) =>
                `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`,
            )
            .join(""),
        });
      }
      return json([]);
    });
    await page.goto("/");
    await expect(
      page.getByRole("textbox", { name: "Message", exact: true }),
    ).toBeVisible();
    await page.locator(".composer-tools summary").click();
    await page.getByRole("button", { name: /^Deep research/ }).click();
    await page
      .getByRole("textbox", { name: "Message", exact: true })
      .fill("বাংলায় GPS accuracy গবেষণা করুন।");
    await page
      .getByRole("button", { name: "Send message", exact: true })
      .click();
    const panel = page.getByRole("region", {
      name: "Research activity",
      exact: true,
    });
    await expect(panel.getByRole("button")).toContainText("Research paused");
    await panel.getByRole("button").click();
    await expect(
      panel.getByText("GPS navigation accuracy", { exact: true }),
    ).toBeVisible();
    await expect(
      panel.locator(".web-research-objectives p").filter({
        hasText: "বাংলায় GPS accuracy এবং limitations ব্যাখ্যা করুন",
      }),
    ).toBeVisible();
    await page
      .getByText("1. GPS navigation measurements · Page read", { exact: true })
      .click();
    await expect(
      page.getByRole("link", { name: "Open PDF page 24" }),
    ).toHaveAttribute("href", "https://primary.example/paper.pdf#page=24");
    await expect(
      page.getByText("The full document was not read.", { exact: false }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    ).toBe(true);
    const headerRects = await page.locator(".topbar").evaluate((header) => {
      const left = header
        .querySelector(".topbar-left")!
        .getBoundingClientRect();
      const right = header
        .querySelector(".topbar-right")!
        .getBoundingClientRect();
      return { leftEnd: left.right, rightStart: right.left };
    });
    expect(headerRects.leftEnd).toBeLessThanOrEqual(headerRects.rightStart + 1);
    for (const rect of await page
      .locator(".topbar button")
      .evaluateAll((buttons) =>
        buttons
          .map((button) => {
            const r = button.getBoundingClientRect();
            return { x: r.x, right: r.right, width: r.width };
          })
          .filter((r) => r.width > 0),
      )) {
      expect(rect.x).toBeGreaterThanOrEqual(0);
      expect(rect.right).toBeLessThanOrEqual(width + 1);
    }
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({
      path: `test-results/web-research-${width}.png`,
      fullPage: true,
    });
    await page.reload();
    await expect(
      page.getByRole("button", { name: "Resume response", exact: true }),
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Resume response", exact: true })
      .click();
    await expect(
      page.getByRole("button", { name: "Resume response", exact: true }),
    ).toHaveCount(0);
    await expect(
      page.getByRole("region", { name: "Research activity" }),
    ).toContainText("evidence gaps remain");
    await page.reload();
    await expect(
      page.getByRole("region", { name: "Research activity" }),
    ).toContainText("evidence gaps remain");
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    ).toBe(true);
  });
}
