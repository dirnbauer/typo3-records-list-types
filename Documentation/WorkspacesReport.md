# Workspaces Report

> Run date: 2026-04-18
> Skill: typo3-workspaces
> Extension: records_list_types @ TYPO3 v14

## Summary

The extension already integrates with TYPO3's workspace API correctly in the
data layer. The remaining work is v14 API tightening and cleanup, not a
rewrite.

## What already works

- **Query-time restriction**: every `QueryBuilder` obtained from the extension
  applies `DeletedRestriction` + `WorkspaceRestriction` keyed to the current
  backend user's workspace
  (`Classes/Service/RecordGridDataProvider.php:202-205`, `:113-116`, `:674-677`).
- **Row-time overlay**: every result row is passed through
  `BackendUtility::workspaceOL()` before being enriched and returned
  (`Classes/Service/RecordGridDataProvider.php:81`, `:703`).
- **State detection**: `t3ver_state` is mapped to visual indicators (new,
  changed, move, deleted) via `getWorkspaceState()`
  (`Classes/Service/RecordGridDataProvider.php:612-630`).
- **Template rendering**: `gridview-card--ws-*` and
  `gridview-card__translation-pill--ws-*` CSS hooks render workspace status
  in Card, Compact and Teaser templates.
- **Core XClass integration**: `RecordListController` delegates DataHandler
  calls to the parent controller, so record deletion and copy operations
  automatically use the workspace-aware Core APIs.

## Issues found and addressed

| # | Issue | Resolution |
|---|-------|-----------|
| 1 | State mapping for `t3ver_state = 3` kept for legacy reasons. The value was removed in TYPO3 v11 (old "move placeholder"). On v14 only `4` represents a move pointer. | Map only `4` to `'move'`. |
| 2 | Workspace ID resolved via `$BE_USER->workspace` property access. Skill guidance recommends `Context` aspect as the canonical API. | Read via `Context::getPropertyFromAspect('workspace', 'id', 0)`. |
| 3 | Deprecated wrapper `ViewModeResolver::isGridViewAllowed()` kept alive for BC. Extension is v14-only; no external API surface depends on it. | Remove the wrapper. |

## Issues accepted

| # | Issue | Rationale |
|---|-------|-----------|
| A | Physical files under `fileadmin/` are not versioned, so thumbnails in the grid always render the live binary. | Documented limitation of TYPO3 FAL. Listed in `Documentation/KnownProblems/Index.rst`. |
| B | The extension does not expose publish/stage/swap buttons. | The module is view-only; DataHandler handles publishing elsewhere in Core. |

## Verification

- PHPStan level 9 (see `phpstan.neon`).
- Unit test `ConstantsTest` and functional tests for `TableAccessService`,
  `ViewModeResolver`, `GridConfigurationService`, `ViewTypeRegistry` remain
  green after the API tightening.
- Manual backend check: switch to a custom workspace, verify the workspace
  badge colors on the Grid view match the expected `t3ver_state` values.
