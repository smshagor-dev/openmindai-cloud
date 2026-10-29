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

## Automated evidence

Implementation checkpoint `d2d4f267ffd22a1fa7094b49b68ef169227c1ecf` passed all three browser jobs in [CI run 40885118063](https://github.com/smshagor-dev/openmindai-cloud/actions/runs/40885118063):

| Engine | Responsive cases | 3D lifecycle/export cases | Result |
|---|---:|---:|---|
| Chromium | 35 | 13 | 48 passed |
| Firefox | 33 | 13 | 46 passed |
| WebKit | 35 | 13 | 48 passed |

142 browser cases passed in total. The 11 viewport sizes cover 320, 360, 390, 430, 568 landscape, 768, 820, 1024, 1280, 1440 and 1920px. Touch navigation is additionally exercised at 390/820px in Chromium/WebKit; Firefox has no Playwright mobile-context emulation. Both normal and forced-no-WebGL 3D modes validate generated/reopened scenes, accessible inspection, real GLB binary headers and deletion of fixture-only records.

Additional local verification:

- Clean production build and TypeScript validation passed.
- All 235 frontend integration tests passed, zero skipped.
- All 18 existing browser flow regressions passed: image and DOCX preview/reload/Library, research pause/reload/resume and citation drawers, at desktop/tablet/mobile sizes.
- Final test-harness updates isolate each suite/engine's output directory, retain JSON results/screenshots and fail interrupted test processes instead of treating a missing exit code as success. Fresh CI on these harness changes is separate from the verified implementation checkpoint above.

Local browser tests use deterministic API fixtures. Real production checks are listed separately above. Viewports and touch emulation are not physical-device certification, and this audit does not certify every provider, microphone/camera permission or all Research lifecycle/worker operations.

Local Firefox content-process crashes and missing WebKit system libraries were infrastructure limitations; they were not counted as passes. Official engine tests succeeded in CI after installing the dependencies there.

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
