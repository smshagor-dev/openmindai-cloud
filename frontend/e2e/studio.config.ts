import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: ".",
  testMatch: "studio.spec.ts",
  timeout: 60000,
  workers: 1,
  use: {
    baseURL: process.env.RESEARCH_BROWSER_URL || "http://127.0.0.1:3101",
    headless: true,
    launchOptions: {
      executablePath: process.env.STUDIO_CHROMIUM_PATH,
      args: ["--no-sandbox", "--enable-unsafe-swiftshader"],
    },
  },
});
