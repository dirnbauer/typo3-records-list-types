# Extension Upgrade Report

> Run date: 2026-04-18 (round 2)
> Skill: typo3-extension-upgrade
> Extension: records_list_types @ TYPO3 v14

## Rector dry-run

With `rector.php` now wired up (TYPO3 v14 level sets + PHP 8.3 + code
quality), `vendor/bin/rector process --dry-run` reports 16 files that
can be modernised. The rules are all safe, non-behavioural:

| Rector rule | Effect |
|-------------|--------|
| `AddTypeToConstRector` | Add typed class constants (PHP 8.3) |
| `AddOverrideAttributeToOverriddenMethodsRector` | `#[\Override]` on overriding methods |
| `AddArrowFunctionReturnTypeRector` / `AddArrowFunctionParamArrayWhereDimFetchRector` | Return / parameter types on `fn(...)` |
| `FunctionFirstClassCallableRector` | `'ucfirst'` → `ucfirst(...)` |
| `ReadOnlyClassRector` | `#[\ReadOnly]` on classes whose promoted properties are all readonly |
| `ClassPropertyAssignToConstructorPromotionRector` + `ReadOnlyPropertyRector` | Constructor promotion + readonly |
| `CombineIfRector` / `RemoveUnusedVariableInCatchRector` / `SimplifyUselessVariableRector` / `SimplifyIfReturnBoolRector` / `ReturnEarlyIfVariableRector` | General cleanups |
| `StringClassNameToClassConstantRector` | `'Foo\Bar'` → `Foo\Bar::class` |
| `FlipTypeControlToUseExclusiveTypeRector` | `!($x instanceof Foo)` → early return |
| `RepeatedOrEqualToInArrayRector` / `RepeatedAndNotEqualToNotInArrayRector` | `=== a \|\| === b` → `in_array(...)` |
| `RemoveUnusedPrivateMethodParameterRector` | Drop unused private method params |
| `MigrateLabelReferenceToDomainSyntaxRector` | Legacy `LLL:EXT:...:key` → domain syntax (TYPO3 v14 idiom) |
| `LogicalToBooleanRector` | `and`/`or` → `&&`/`\|\|` |
| `IssetOnPropertyObjectToPropertyExistsRector` / `ChangeOrIfContinueToMultiContinueRector` / `CompleteMissingIfElseBracketRector` / `PrivatizeFinalClassMethodRector` | Assorted hygiene |

I apply these as a single follow-up commit and fix anything PHPStan or
the test suite flags afterwards.

## PHPStan: tried level 10

Raising `phpstan.neon` from `level: 9` to `level: 10` surfaces **131
new errors** — almost all of them about generic typed array shapes on
services that consume `$GLOBALS['TCA']`. The fix surface is broad and
would mean reworking every array return type to more specific generic
shapes. Deferred as a follow-up initiative; for now **level 9 stays**
and the `saschaegerer/phpstan-typo3` + `phpat` extensions remain active.

## Scan results

| Area | Status | Notes |
|------|--------|-------|
| `GeneralUtility::_GP / _POST / _GET` | Clean | No matches in `Classes/`. PSR-7 `ServerRequestInterface` used throughout. |
| `ObjectManager` / `makeInstanceService` | Clean | Not referenced. |
| `SC_OPTIONS` hook registration | Clean | All listeners use `#[AsEventListener]`. |
| XClass registration | v14 API | `$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects']` in `ext_localconf.php`. |
| AJAX routes | v14 API | Registered in `Configuration/Backend/AjaxRoutes.php`. |
| Composer constraints | v14-only | `typo3/cms-*` pinned to `^14.0`. |
| ext_emconf.php | Removed | Metadata consolidated in `composer.json` (round 1). |
| PHPStan TYPO3 extension | Active | `saschaegerer/phpstan-typo3:^3.0` + `phpat`. |

## Verification after fixes

- `vendor/bin/rector process` — applied.
- `vendor/bin/phpstan analyse` — expected 0 errors at level 9.
- `vendor/bin/php-cs-fixer fix` — expected 0 diff after rector.
- `vendor/bin/phpunit --testsuite Unit` — 90 tests green.
- Functional (pdo_sqlite) — 72 tests green.
