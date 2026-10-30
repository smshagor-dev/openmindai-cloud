import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = file => readFileSync(new URL("../src/" + file, import.meta.url), "utf8");

test("image creation renders a large server-supplied percentage in the card, without a line", () => {
  const progress = read("components/creation-progress.tsx");
  assert.match(progress, /progress\?: number \| null/);
  assert.match(progress, /stage\?: string \| null/);
  assert.match(progress, /kind === "image"/);
  assert.match(progress, /className="creation-canvas-percent"/);
  assert.match(progress, /role=\{image \? "status" : undefined\}/);
  assert.match(progress, /creation-progress-announcement/);
  assert.doesNotMatch(progress, /role="progressbar"|className="creation-meter"/);
  assert.match(progress, /imageStages\[stage\]/);
  assert.match(progress, /taskId\?: number \| null/);
  assert.match(progress, /accepted && typeof progress === "number"/);
  assert.match(progress, /Submitting your image request/);
  assert.match(progress, /Task #/);
  assert.doesNotMatch(progress, /setInterval|Date\.now\(|Math\.random\(/);
});

test("chat preview polls the server's persisted generation progress", () => {
  const inline = read("components/inline-media.tsx");
  assert.match(inline, /progress_percent\?: number \| null/);
  assert.match(inline, /progress_stage\?: string \| null/);
  assert.match(inline, /progress=\{task\?\.progress_percent\}/);
  assert.match(inline, /stage=\{task\?\.progress_stage\}/);
  assert.match(inline, /setTimeout\(load, 3000\)/);
  assert.match(inline, /taskId=\{task\?\.id \?\? id\}/);
  assert.match(inline, /setTimeout\(load, 6000\)/);
  assert.match(inline, /queuedTooLong/);
  assert.match(inline, /Check status again/);
  assert.match(inline, /setRefreshCount/);
});

test("media studio renders progress for active images", () => {
  const studio = read("components/media-dialog.tsx");
  assert.match(studio, /import CreationProgress from "\.\/creation-progress"/);
  assert.match(studio, /\["queued", "running"\]\.includes\(task.status\)/);
  assert.match(studio, /progress=\{task.progress_percent\}/);
  assert.match(studio, /stage=\{task.progress_stage\}/);
});

test("large centered percentage replaces the horizontal progress meter", () => {
  const css = read("app/motion.css");
  assert.doesNotMatch(css, /\.creation-meter/);
  assert.match(css, /\.creation-canvas-percent/);
  assert.match(css, /\.creation-progress-announcement/);
  assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});
