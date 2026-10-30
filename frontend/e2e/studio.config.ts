import { defineConfig } from "@playwright/test";
import { resolve } from "node:path";
const outputDir = resolve(
  __dirname,
  "../test-results/",
  `studio-${process.env.RESPONSIVE_BROWSER || "chromium"}`,
);
export default defineConfig({
  testDir: ".",
  outputDir,
  testMatch: "studio.spec.ts",
  timeout: 60000,
  workers: 1,
  reporter: [
    ["list"],
    [
      "json",
      {
        outputFile: resolve(outputDir, "results.json"),
      },
    ],
  ],
  use: {
    browserName: (process.env.RESPONSIVE_BROWSER || "chromium") as
      "chromium" | "firefox" | "webkit",
    baseURL: process.env.RESEARCH_BROWSER_URL || "http://127.0.0.1:3101",
    headless: true,
    trace: "retain-on-failure",
    launchOptions:
      !process.env.RESPONSIVE_BROWSER ||
      process.env.RESPONSIVE_BROWSER === "chromium"
        ? {
            executablePath: process.env.STUDIO_CHROMIUM_PATH,
            args: ["--no-sandbox", "--enable-unsafe-swiftshader"],
          }
        : {},
  },
});
