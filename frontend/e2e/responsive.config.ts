import { defineConfig } from "@playwright/test";
const browserName = (process.env.RESPONSIVE_BROWSER || "chromium") as
  "chromium" | "firefox" | "webkit";
export default defineConfig({
  testDir: ".",
  testMatch: "responsive.spec.ts",
  timeout: 45000,
  expect: { timeout: 5000 },
  workers: 2,
  reporter: "list",
  use: {
    browserName,
    baseURL: process.env.RESEARCH_BROWSER_URL,
    headless: true,
    launchOptions:
      browserName === "chromium"
        ? {
            executablePath: process.env.STUDIO_CHROMIUM_PATH,
            args: ["--no-sandbox", "--enable-unsafe-swiftshader"],
          }
        : {},
    trace: "retain-on-failure",
  },
});
