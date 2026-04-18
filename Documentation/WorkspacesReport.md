# Workspaces Report

> Run date: 2026-04-18 (round 2)
> Skill: typo3-workspaces
> Extension: records_list_types @ TYPO3 v14

## Scan findings

Running the `typo3-workspaces` skill against the current tree surfaced a
few workspace-id resolution sites that still read
`$backendUser->workspace` directly instead of going through the Context
aspect that was standardised in the previous round.

| # | Location | Old code | Remediation |
|---|----------|----------|-------------|
| 1 | `Classes/Controller/RecordListController.php:1041` | `$useWorkspaceReduction = $backendUser->workspace > 0;` | Read the workspace id from the Context aspect via a local helper. |
| 2 | `Classes/Controller/RecordListController.php:1058` | `BackendUtility::workspaceOL($tableName, $row, $backendUser->workspace, true);` | Drop the explicit workspace id — the two-arg form of `workspaceOL()` reads the current workspace itself. |
| 3 | `Classes/Service/RecordGridDataProvider.php:80` | `BackendUtility::workspaceOL($table, $row, $backendUser->workspace, true);` | Same: let `workspaceOL()` pick up the workspace id, remove the orphaned `$backendUser` local. |
| 4 | `Classes/Service/RecordGridDataProvider.php:724` | Ditto, inside `getRecordsWithActions()` | Same fix. |

## Confirmed green (no change required)

- Every `QueryBuilder` obtained inside `RecordGridDataProvider` applies
  both `DeletedRestriction` and `WorkspaceRestriction` with the workspace
  id resolved via `Context::getPropertyFromAspect('workspace', 'id')`.
- `getWorkspaceState()` only maps the TYPO3 v14 values (1 = new,
  2 = deleted, 4 = move). Legacy `t3ver_state = 3` is absent.
- No TCA ships with this extension, so there is nothing to mark
  `versioningWS => true` here; `pages` and `tt_content` already carry
  the flag in Core.
- The extension does not expose publish/stage buttons. Publishing flows
  continue through Core's Workspaces module.
- `workspaceRecordIdentity()` correctly folds live + overlaid rows to a
  single effective record when the current workspace id > 0.

## File / FAL reminder

Physical files under `fileadmin/` remain unversioned. Grid and Teaser
thumbnails always render the live binary regardless of workspace — this
is a TYPO3 platform constraint, covered in `Documentation/KnownProblems`
and `Documentation/Developer/Workspaces.rst`.

## Verification after fixes

- `vendor/bin/phpstan analyse` — expected 0 errors.
- `vendor/bin/phpunit --testsuite Unit` — 90 green.
- `typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml` — 72 green.
