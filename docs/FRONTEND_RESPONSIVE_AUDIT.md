# Frontend responsive audit — 29 October 2026

Branch: `fix/frontend-responsive-audit`, based on main `4004773`.

## Scope and fixes

Chat home/composer, model picker, tool menu, Settings tabs, workspace tools (Teams, Connectors, Assistants, Explore, Tasks, Research, Library, Voice, Code), project dialog, assistant gallery, Research home/navigation, 3D Studio/history/export, reset and email-confirmation pages.

- Closed mobile Chat/Research drawers no longer remain in keyboard/accessibility navigation.
- Long component, project, assistant and account content wraps without widening the viewport; narrow control rows can wrap.
- Settings navigation scrolls horizontally and touch controls receive larger hit areas.
- 3D scenes fall back to a real SVG renderer when WebGL is unavailable. Orbit, zoom, labels, component inspection and GLB export remain available without requiring GPU access.
- An accessible component selector supports keyboard inspection.
- Added a Chromium/Firefox/WebKit CI matrix and responsive regression tests.

## Actual live observations

Signed-in tests at `https://chat.openmindai.org/` used the existing browser session. No credentials were copied into scripts or repository files.

- Temporary chat returned the requested exact response, `Live chat works.`
- Live Object generation produced and saved `Responsive QA cube` (2 parts).
- Live Architecture generation produced and saved `Responsive QA architecture` (3 parts, 2 connections).
- The live cloud browser lacked WebGL; the deployed viewer disabled GLB download despite successful scene generation. This is the compatibility defect fixed on this branch. The fix is not yet deployed.
- Live Settings and Library loaded, with real saved image and document previews listed.

QA scene records are retained; no existing user data was deleted.

## Automated evidence at checkpoint

- Clean production build and TypeScript validation passed.
- Initial Chromium responsive matrix: 33 tests passed across 11 viewport sizes (320, 360, 390, 430, 568 landscape, 768, 820, 1024, 1280, 1440 and 1920px).
- Default and forced-no-WebGL object/architecture rendering and GLB binary checks passed in the first 12 expanded Studio cases. Final full rerun pending at this checkpoint.
- Touch navigation coverage added; correcting the test to click the exposed backdrop instead of its center behind the drawer.
- Firefox could launch locally but content pages crashed in this container; WebKit runtime dependencies are missing. These local limitations are not passes. The CI matrix installs the official dependencies and will provide engine-level results.

Local browser tests use deterministic API fixtures. They do not certify every real provider, export worker, device hardware, microphone/camera permission, or all Research lifecycle operations. Viewports and touch emulation are not physical-device certification. Full live-provider checks above are reported separately from fixture-based browser checks.

## Commands

```bash
cd frontend
npm ci
npm run typecheck
npm run build
npm run test:integration
npx playwright install --with-deps chromium firefox webkit
node e2e/responsive-run.mjs
node e2e/studio-run.mjs
RESPONSIVE_BROWSER=firefox node e2e/responsive-run.mjs
RESPONSIVE_BROWSER=webkit node e2e/responsive-run.mjs
```

No merge or production deployment is included in this audit branch.
