import test from "node:test";
import assert from "node:assert/strict";
import { studioRoute, studioDownload } from "../src/lib/studio-proxy.ts";
test("3D proxy admits only implemented authenticated method/path pairs", () => {
  const id = "12345678-1234-4234-8234-123456789abc";
  for (const [method, path] of [
    ["GET", "3d-studio/engines"],
    ["POST", "3d-studio/jobs/scene"],
    ["GET", `3d-studio/jobs/${id}`],
    ["POST", `3d-studio/jobs/${id}/cancel`],
    ["POST", "3d-studio/assets/4/restore"],
    ["PUT", "3d-scenes/3"],
    ["GET", "3d-scenes/3/revisions"],
  ])
    assert.ok(studioRoute(method, path), `${method} ${path}`);
  for (const [method, path] of [
    ["GET", "3d-studio/admin/install"],
    ["POST", "3d-studio/engines"],
    ["DELETE", `3d-studio/jobs/${id}`],
    ["GET", "3d-studio/assets/../private"],
    ["GET", "3d-studio/assets/4/data/extra"],
    ["POST", "3d-studio/assets/4/download"],
  ])
    assert.equal(studioRoute(method, path), false, `${method} ${path}`);
  assert.ok(studioDownload("3d-studio/assets/4/download"));
  assert.equal(studioDownload("3d-studio/assets/4/data"), false);
});
