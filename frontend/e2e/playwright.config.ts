import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: ".",
  testMatch: "research.spec.ts",
  workers: 1,
  timeout: 60000,
  use: {
    baseURL: process.env.RESEARCH_BROWSER_URL,
    headless: true,
    launchOptions: {
      executablePath: process.env.RESEARCH_CHROMIUM_PATH || undefined,
      args: ["--no-sandbox"],
    },
    trace: "retain-on-failure",
  },
  reporter: "list",
});
