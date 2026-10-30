import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const view = (name) => readFileSync(
  new URL("../../resources/views/admin/" + name + ".blade.php", import.meta.url), "utf8",
);
const css = readFileSync(new URL("../../public/css/admin.css", import.meta.url), "utf8");

test("NVIDIA model search fields and actions use the same responsive toolbar", () => {
  const html = view("nvidia-models");
  assert.match(html, /action="\/admin\/nvidia-models" class="admin-card admin-filter-form"/);
  assert.match(html, /class="admin-filter-fields"/);
  assert.match(html, /name="q"/);
  assert.match(html, /name="category"/);
  assert.match(html, /class="admin-filter-actions"/);
  assert.match(html, /class="admin-reset-filter" href="\/admin\/nvidia-models"/);
});

test("Users and Hugging Face search pages share aligned filter controls", () => {
  for (const name of ["users", "search"]) {
    const html = view(name);
    assert.match(html, /class="admin-card admin-filter-form"/);
    assert.match(html, /class="admin-filter-fields"/);
    assert.match(html, /class="admin-filter-actions"/);
    assert.match(html, /class="admin-reset-filter"/);
  }
  for (const name of ["models", "model-bundles"]) {
    assert.match(view(name), /class="admin-card admin-discovery-form"/);
  }
});

test("all admin forms have bounded controls and responsive action positioning", () => {
  assert.match(css, /\.admin-shell \.admin-main \.field > input/);
  assert.match(css, /\.admin-shell \.admin-filter-form \{/);
  assert.match(css, /grid-template-columns: minmax\(0, 1fr\) auto/);
  assert.match(css, /\.admin-shell \.admin-filter-form \.admin-filter-fields/);
  assert.match(css, /\.admin-shell \.admin-filter-form \.admin-filter-actions/);
  assert.match(css, /\.admin-shell \.admin-filter-actions > \.admin-reset-filter/);
  for (const size of [1100, 670, 380]) {
    assert.ok(css.includes("@media (max-width: " + size + "px)"));
  }
  assert.match(view("layout"), /20261021-admin-forms/);
});
