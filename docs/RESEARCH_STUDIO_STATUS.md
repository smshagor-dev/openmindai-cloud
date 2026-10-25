# Research Studio — implementation checkpoint

Updated: 2026-10-25 (Europe/Moscow).

## Current remote state

- Working branch: `feature/research-studio`.
- Base: `main` at `6a42d5b55e35f93e792f5abad629c9aa66e3dee3`.
- This checkpoint establishes remote-backed work instructions and a truthful continuation record.
- Research Studio application code from the earlier local-only attempt is not present in this branch. Its old test counts do not verify this checkout.
- No Research Studio PR, merge or deployment has occurred.

## Verified repository baseline

- Laravel 12 backend with Sanctum authentication and interactive-session middleware.
- Next.js 16 / React frontend with an authenticated backend proxy.
- Existing hosted/NVIDIA gateway, model catalog and queue/storage configuration.
- Existing chat/web research capabilities are present; they do not constitute the requested Research Studio.
- Git working tree was clean before this checkpoint.

## Implementation sequence

1. Inspect existing gateway, authentication, UI/proxy, migrations and tests; document integration architecture.
2. Add project-scoped research persistence, authorization, APIs and revision-safe document storage.
3. Add independently executable agents, orchestrator, durable task dependencies, concurrency, retries, cancellation and recovery.
4. Add scholarly discovery, protected retrieval, PDF processing, evidence/reference verification and citations.
5. Add autonomous investigation, gap analysis and private isolated experiment workers.
6. Add academic editor, manuscript proposals, quality/integrity gates and human collaboration.
7. Connect all responsive workspace views to actual task and browsing events.
8. Add validated exports, multilingual support and deployment documentation.
9. Run deterministic unit/integration/browser tests and separate real integration checks. Report actual results.
10. Only after acceptance, open a completion PR and follow repository merge policy.

## Persistence policy

The user authorized ongoing GitHub checkpoint pushes. Save each coherent increment to the working branch and verify its remote head. Checkpoint pushes do not claim feature completion or authorize deployment. Update this record with implemented paths, actual checks and the next unfinished step.

## Verification

No new application test suite was run for this documentation-only checkpoint. Live reasoning/embeddings, Docker worker isolation/recovery and target deployment acceptance remain unverified.
