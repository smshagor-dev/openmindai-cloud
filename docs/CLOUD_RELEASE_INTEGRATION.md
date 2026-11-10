# Cloud release integration — 2026-11-10

The owner authorized merging every pending Cloud PR (#98–#103) into main before the combined build/deployment.

Included work:
- #98: research export titles/layout, prompt scope and distinct-reference coverage.
- #99: Vercel lockfile reconciliation (already compatible with current main dependencies).
- #100: final answer recovery and bounded local Chromium page reading.
- #101: search execution deadline, truthful interrupted turns and optional isolated Chromium reader.
- #102: interactive agent architecture canvas.
- #103: durable hosted TRELLIS 2 image-to-mesh generation on shared hosting.

Conflicts preserve both renderer transports: an explicitly configured authenticated remote reader is tried first, followed by the bounded local reader and static extraction. One SSE heartbeat timer is used. Search tool evidence recovery, early verified answer delivery and execution-deadline failure persistence are retained together.

Integration fixes preserve the new 25-distinct-reference quality gate. Approved-export test fixtures now provide sufficient distinct supported synthetic sources; negative review tests assert both original findings and missing-reference failures. The hosted-mesh browser fixture uses a real canvas-encoded PNG. Symfony HTTP Foundation/Kernel/Routing are patched to 7.4.23, with their compatible transitive updates; Composer and production npm audits report no advisories.

Local validation: production frontend build and TypeScript passed; 48 focused backend tests/409 assertions and 29 focused frontend/reader tests passed. Broader suites and CI acceptance are being recorded before merge. A local Chromium executable cannot start in this environment; browser scenarios must run in CI. Fixtures do not establish live provider quality or production readiness.

Production boundaries: the hosted mesh engine requires server-only FAL_KEY and the usual Laravel scheduler. Isolated Chromium requires its separately protected worker and network egress restrictions; merging code alone does not provision it. Backend configuration, production release identity and a real saved GLB must be verified before claiming deployment success. No secret values are stored here.
