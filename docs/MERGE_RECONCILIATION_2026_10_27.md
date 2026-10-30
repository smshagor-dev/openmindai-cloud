# Branch reconciliation — 2026-10-27

Requested scope: resolve PR #65, integrate outstanding compatible work, then deploy the verified main revision without overwriting server customizations.

## Audit and conflict resolution

- Base: `a5d8accf6540cd2b3a466f498757491b9be5a3f8` (main, after PR #64).
- PR #65 repeats the branch already squash-merged in PR #61. Its outage, cancellation, language and no-fabrication regression tests already exist on main. Preserve main's newer supplied-link recovery, citation provenance and search implementation rather than restore the obsolete version.
- Integrate `feature/research-studio` at `0a130534dc111b9a060512ecf7cf8d696e61ff1c`: literal quote selection, bounded evidence repair, checkpoint preservation, consecutive-failure accounting and eight regression tests. Main's newer mobile vision changes remain intact.
- Integrate the two post-PR #34 commits from `feature/mobile-user-cloud-access` at `55b9767`: one additional regression test for mobile session/developer-token isolation. The previously merged application implementation remains unchanged.
- `feature/automatic-request-routing` was deliberately closed in PR #36 as superseded by PR #37's native conversation tools. Do not restore its obsolete classifier alongside the replacement.
- Other non-ancestor branch heads correspond to already merged PRs (#1, #3–5, #8, #10–13, #15–28, #29, #31–33, #42, #47, #53–54 and #57). Squash merges explain their surviving divergent history; they are not missing features. Retain branches and existing main code.
- The reconciliation commit records main, PR #65, research recovery and mobile test heads as parents; no force push or branch deletion is required.

## Verification and release status

Fresh combined-tree CI and deployment are pending when this checkpoint is created. Consult PR #65 checks for the exact tested revision. Prior branch checks are not a substitute for combined-tree verification.

This is a maintenance integration, not full Research Studio acceptance. The live run reached all 36 literature chunks but final comparison failed; that separate failure remains under investigation. Preserve its checkpoint, sources, evidence and completed tasks. The private experiment/export worker and reliable search-provider configuration remain external requirements.

Before deployment inspect server Git status, back up configuration/custom files, reject overlapping local changes, use a fast-forward pull only, install locked production dependencies, apply pending migrations only, clear/rebuild Laravel caches and verify service health. Never reset the database or overwrite secrets/uploads. Do not run unrelated model seeders or enable unverified models.
