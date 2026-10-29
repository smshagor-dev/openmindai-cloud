import { test, expect } from "@playwright/test";
import { renderDocx } from "../src/lib/document-files";

for (const width of [1440, 768, 375, 320]) {
  for (const kind of ["image", "document_create"] as const) {
    test(`chat creation, reload and Library preview: ${kind} at ${width}px`, async ({page}) => {
      await page.setViewportSize({width,height:1000});
      const mime = kind === "image" ? "image/png" : "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
      const bytes = kind === "image" ? Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/ZxoAAAAASUVORK5CYII=", "base64") : renderDocx("Tree report\n\nTrees absorb carbon dioxide.");
      const conversation = {id:44,title:"Creation browser fixture",updated_at:"2026-10-27T12:00:00Z"};
      let saved=false, submits=0, polls=0;
      const content=`Your request was accepted.\n\n[View generated ${kind === "image" ? "image" : "document"}](/api/backend/media/91/output)`;
      const prompt=kind === "image" ? "A forest at sunrise" : "Create a Word document about trees";
      await page.route("**/api/backend/**", async route => {
        const path=new URL(route.request().url()).pathname.replace("/api/backend/", "");
        const json=(value:unknown)=>route.fulfill({json:value});
        if(path==="me")return json({id:1,name:"Generation tester",email:"fixture@example.test",email_verified_at:"2026-10-27"});
        if(path==="models")return json([{id:-3,name:"OpenMindAI Ultra",family:"Chat"}]);
        if(path==="preferences")return json({theme:"dark",reduced_motion:true,send_on_enter:true});
        if(path==="capabilities")return json({media:["image","document_create"],image_generation:true});
        if(path==="media/91/output")return route.fulfill({contentType:mime,body:bytes});
        if(path==="media/91")return json({id:91,kind,status:++polls===1?"queued":"complete",progress_stage:polls===1?"queued":"complete",output_mime:polls===1?null:mime,result_text:null,error:null});
        if(path==="media")return json({data:[{id:91,kind,status:"complete",output_mime:mime,prompt,result_text:null}],last_page:1,current_page:1});
        if(path==="attachments")return json({data:[],last_page:1,current_page:1});
        if(path.startsWith("conversations/44"))return json({conversation,messages:{data:[{id:90,role:"user",content:prompt},{id:92,role:"assistant",content,status:"complete"}],current_page:1,last_page:1}});
        if(path==="conversations")return json({data:saved?[conversation]:[],current_page:1,last_page:1});
        if(path==="chat/stream") {
          submits++; saved=true;
          const payload=route.request().postDataJSON();
          if(kind==="image")expect(payload.generation_kind).toBe("image");
          else expect(payload.generation_kind).toBeUndefined();
          expect(payload.model_id).toBe(-3);
          const events=[["meta",{conversation_id:44,user_message_id:90,message_id:92,sources:[]}],["artifact",{id:91,kind,status:"queued"}],["delta",{content}],["done",{status:"complete",saved:true}]];
          return route.fulfill({contentType:"text/event-stream",body:events.map(([event,data])=>`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`).join("")});
        }
        return json([]);
      });
      await page.goto("/");
      if (width === 1440) {
        const studio = page.getByRole("link", {name:"Research Studio",exact:true});
        await expect(studio).toBeVisible();
        await expect(studio).toHaveAttribute("href", "/research-studio");
        expect(await studio.evaluate(el=>getComputedStyle(el).textDecorationLine)).toBe("none");
      }
      const input=page.getByRole("textbox",{name:"Message",exact:true});
      await expect(page.getByRole("button",{name:"Send message",exact:true})).toBeDisabled();
      if(kind==="image") {
        await page.locator(".composer-tools summary").click();
        await page.getByRole("button",{name:/^Create image/}).click();
        await expect(page.getByRole("dialog")).toHaveCount(0);
        await expect(input).toBeFocused();
      }
      await input.fill(prompt);
      await page.getByRole("button",{name:"Send message",exact:true}).click();
      await expect(page.locator(".user-bubble")).toContainText(prompt);
      await expect(page.getByRole("region",{name:kind==="image"?"Creating image":"Creating document",exact:true})).toBeVisible();
      if(kind==="image")await expect(page.getByRole("img",{name:"Generated image; open full size"})).toBeVisible();
      else await expect(page.locator(".document-preview .file-text-preview")).toContainText("Trees absorb carbon dioxide.");
      await page.reload();
      if(kind==="image")await expect(page.getByRole("img",{name:"Generated image; open full size"})).toBeVisible();
      else await expect(page.locator(".document-preview .file-text-preview")).toContainText("Tree report");
      expect(submits).toBe(1);
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
      await page.screenshot({path:`test-results/chat-generation-${kind}-${width}.png`,fullPage:true});
      await page.locator(".composer-tools summary").click();
      await page.getByRole("button",{name:/^Add from library/}).click();
      const thumbnail=page.locator(".library-media-thumbnail");
      await expect(thumbnail).toBeVisible();
      if(kind==="image")await expect(thumbnail.locator("img")).toBeVisible();
      else await expect(thumbnail.locator(".file-text-preview")).toContainText("Trees absorb carbon dioxide.");
      expect(await thumbnail.evaluate(el=>el.getBoundingClientRect().height)).toBeLessThanOrEqual(160);
    });
  }
}
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
      mode: "deep_research",
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
          ? "GPS navigation reached 97.5 percent accuracy [1, p1]."
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
          ...(resumed ? [["answer", { content: messages()[1].content }]] : []),
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
      .getByRole("button", {
        name: "Inspect source 1: GPS navigation measurements",
        exact: true,
      })
      .click();
    await expect(
      page.getByRole("link", { name: "Open PDF page 24" }),
    ).toHaveAttribute("href", "https://primary.example/paper.pdf#page=24");
    await expect(
      page.getByText("The full document was not read.", { exact: false }),
    ).toBeVisible();
    await expect(
      page.getByRole("dialog", { name: "Sources", exact: true }),
    ).toBeVisible();
    const drawerRect = await page
      .getByRole("dialog", { name: "Sources", exact: true })
      .boundingBox();
    expect(drawerRect!.x + drawerRect!.width).toBeLessThanOrEqual(width + 1);
    await page.screenshot({
      path: `test-results/web-research-source-${width}.png`,
      fullPage: true,
    });
    await page
      .getByRole("button", { name: "Close dialog", exact: true })
      .click();
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
    await panel.getByRole("button").click();
    await page.screenshot({
      path: `test-results/web-research-report-${width}.png`,
      fullPage: true,
    });
    const citation = page
      .locator(".markdown button.web-inline-citation")
      .first();
    await expect(citation).toBeVisible();
    await citation.click();
    await expect(
      page.getByRole("link", { name: "Open PDF page 24" }),
    ).toBeVisible();
    await page
      .getByRole("searchbox", { name: "Search sources" })
      .fill("missing");
    await expect(
      page.getByText("No matching sources", { exact: true }),
    ).toBeVisible();
    await page.getByRole("searchbox", { name: "Search sources" }).fill("GPS");
    await page
      .getByRole("button", { name: "Limited evidence", exact: true })
      .click();
    await expect(page.locator(".web-source-record")).toHaveCount(1);
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog", { name: "Sources" })).toHaveCount(0);
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

for (const width of [1440, 375]) {
  test(`web search citation drawer and honest limited evidence at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 950 });
    const sources = [
      {
        url: "https://primary.example/evidence",
        title: "বাংলা GPS evidence",
        citation: 1,
        read_status: "read",
        passages: [
          {
            id: "p1",
            text: "GPS navigation reached 97.5 percent accuracy in published baseline measurements.",
            page: null,
          },
        ],
      },
      {
        url: "https://secondary.example/snippet",
        title: "Independent search excerpt",
        citation: 2,
        read_status: "snippet_only",
        snippet:
          "Only a search excerpt was retrieved. The original page was not inspected.",
      },
    ] as const;
    const body =
      "GPS navigation reached 97.5 percent accuracy [1](https://primary.example/evidence).";
    const metadata = {
      version: 1,
      mode: "web_search",
      status: "complete",
      plan: [],
      activity: [
        {
          id: 1,
          at: "2026-10-26T12:00:00Z",
          phase: "searching",
          status: "completed",
          label: "Search results collected",
        },
      ],
      answer_body_length: body.length,
    };
    await page.route("**/api/backend/**", async (route) => {
      const path = new URL(route.request().url()).pathname.replace(
        "/api/backend/",
        "",
      );
      const json = (value: unknown) => route.fulfill({ json: value });
      if (path === "me")
        return json({
          id: 1,
          name: "Search tester",
          email: "fixture@example.test",
          email_verified_at: "2026-10-26",
        });
      if (path === "models")
        return json([{ id: -1, name: "OpenMindAI Lite", family: "Chat" }]);
      if (path === "preferences")
        return json({
          theme: width === 375 ? "light" : "dark",
          reduced_motion: true,
          send_on_enter: true,
        });
      if (path === "capabilities") return json({ web_search: true, media: [] });
      if (path.startsWith("conversations"))
        return json({ data: [], current_page: 1, last_page: 1 });
      if (path === "chat/stream") {
        expect(route.request().postDataJSON().web_search).toBe(true);
        expect(route.request().postDataJSON().deep_research).toBe(false);
        const events = [
          [
            "meta",
            {
              conversation_id: 12,
              user_message_id: 30,
              message_id: 31,
              sources: [],
              resumable: false,
            },
          ],
          ["research", { label: "Searched the web", sources, metadata }],
          ["answer", { content: body + "\n\nSources\nHOST FOOTER INDEX" }],
          ["done", { status: "complete", saved: true }],
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
    await page.getByRole("button", { name: /^Web search/ }).click();
    await page
      .getByRole("textbox", { name: "Message", exact: true })
      .fill("Find GPS navigation evidence");
    await page
      .getByRole("button", { name: "Send message", exact: true })
      .click();
    await expect(
      page.getByRole("region", { name: "Research activity" }),
    ).toContainText("Searched the web");
    await expect(
      page.getByText("HOST FOOTER INDEX", { exact: true }),
    ).toHaveCount(0);
    await expect(
      page.getByRole("button", { name: "Resume response", exact: true }),
    ).toHaveCount(0);
    await page.locator(".markdown .web-inline-citation").click();
    const drawer = page.getByRole("dialog", { name: "Sources", exact: true });
    await expect(drawer).toBeVisible();
    await expect(
      drawer.getByText(sources[0].passages[0].text, { exact: true }),
    ).toBeVisible();
    await drawer
      .getByRole("button", { name: "Limited evidence", exact: true })
      .click();
    await expect(drawer.locator(".web-source-record")).toHaveCount(1);
    await drawer.locator(".web-source-record-heading").click();
    await expect(
      drawer.getByText("Search snippet only", { exact: true }),
    ).toBeVisible();
    await expect(
      drawer.getByText(sources[1].snippet, { exact: true }),
    ).toBeVisible();
    await page.screenshot({
      path: `test-results/web-search-limited-${width}.png`,
      fullPage: true,
    });
    await page.keyboard.press("Escape");
    await expect(drawer).toHaveCount(0);
    await page.screenshot({
      path: `test-results/web-search-report-${width}.png`,
      fullPage: true,
    });
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    ).toBe(true);
  });
}
