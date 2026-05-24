# Extension Upgrade Report

> Run date: 2026-05-16 (round 3)
> Skill: typo3-extension-upgrade
> Extension: records_list_types @ TYPO3 v14

## Breaking changes

### Lit action host for custom templates

Custom Fluid templates that rely on the shared action JavaScript must wrap
their rendered content in:

```html
<records-list-types-actions>
    <!-- custom view markup -->
</records-list-types-actions>
```

The built-in Grid, Compact, Teaser, and Generic templates already include
this wrapper. Custom templates copied before the Lit migration need the
wrapper so drag-and-drop, record actions, sorting, pagination inputs,
compact-view scroll shadows, and client-side search are initialized.

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

## PHPStan: level max

`phpstan.neon` now uses `level: max`. The previous generic-array issues
were fixed by normalizing TYPO3 TSconfig, request, and TCA arrays at
their boundaries. No baseline or inline ignores are used.

## Scan results

| Area | Status | Notes |
|------|--------|-------|
| `GeneralUtility::_GP / _POST / _GET` | Clean | No matches in `Classes/`. PSR-7 `ServerRequestInterface` used throughout. |
| `ObjectManager` / `makeInstanceService` | Clean | Not referenced. |
| `SC_OPTIONS` hook registration | Clean | All listeners use `#[AsEventListener]`. |
| XClass registration | v14 API | `$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects']` in `ext_localconf.php`. |
| AJAX routes | v14 API | Registered in `Configuration/Backend/AjaxRoutes.php`. |
| Composer constraints | v14-only | `typo3/cms-*` pinned to `^14.3`. |
| ext_emconf.php | Removed | TYPO3 14.2+ deprecates `ext_emconf.php`; Composer carries `extra.typo3/cms.version` and empty `Package.providesPackages` per TYPO3 14.3 metadata rules. |
| PHPStan TYPO3 extension | Active | `saschaegerer/phpstan-typo3:^3.0` + `phpat`. |

## Verification after fixes

- `vendor/bin/rector process` — applied.
- `vendor/bin/phpstan analyse` — 0 errors at level max.
- `vendor/bin/php-cs-fixer fix` — expected 0 diff after rector.
- `vendor/bin/phpunit --testsuite Unit` — 120 tests green.
- Functional (pdo_sqlite) — 72 tests green.
