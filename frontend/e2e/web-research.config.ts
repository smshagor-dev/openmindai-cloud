import { defineConfig } from "@playwright/test";
import base from "./playwright.config";
export default defineConfig({
  ...base,
  maxFailures: 1,
  testMatch: "web-research.spec.ts",
});
