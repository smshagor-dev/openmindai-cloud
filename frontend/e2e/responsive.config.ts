import { defineConfig } from "@playwright/test";
import { fileURLToPath } from "node:url";
const browserName = (process.env.RESPONSIVE_BROWSER || "chromium") as
  "chromium" | "firefox" | "webkit";
const outputDir = fileURLToPath(
  new URL(`../test-results/responsive-${browserName}/`, import.meta.url),
);
export default defineConfig({
  testDir: ".",
  outputDir,
  testMatch: "responsive.spec.ts",
  timeout: 45000,
  expect: { timeout: 5000 },
  workers: 2,
  reporter: [["list"], ["json", { outputFile: outputDir + "results.json" }]],
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
