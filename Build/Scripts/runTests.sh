#!/usr/bin/env bash
#
# runTests.sh — unified entry point for local and CI test runs.
#
# Mirrors the conventions from the TYPO3 Tea reference extension. The
# script intentionally depends only on composer-installed binaries in
# vendor/bin/ so it works in any environment (DDEV, bare host, CI).
#
# Usage:
#   Build/Scripts/runTests.sh -s <suite> [-p <php>]
#
#   Suites: unit | unit-coverage | functional | functional-coverage | architecture | phpstan | cgl | composer | ci

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${PROJECT_ROOT}"

SUITE=""
PHP_VERSION=""
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-1G}"

usage() {
    cat <<'EOF'
Usage: Build/Scripts/runTests.sh -s <suite> [-p <php>]

Suites:
  unit                 Unit test suite.
  unit-coverage        Unit test suite with Clover, HTML, and text coverage reports.
  functional           Functional test suite (needs a database via env vars).
  functional-coverage  Functional test suite with Clover, HTML, and text coverage reports.
  architecture         PHPat architecture rules.
  phpstan              Static analysis at PHPStan level max.
  cgl                  PHP-CS-Fixer dry run.
  composer             composer validate + composer audit.
  ci                   Run everything except functional (which needs a DB).

Options:
  -p <php>       Informational only: PHP version the suite is expected
                 to run on (e.g. 8.3, 8.4, 8.5). The script uses whatever
                 `php` resolves to in PATH.
  -h             Show this help.

Environment:
  PHP_MEMORY_LIMIT  Memory limit for PHPUnit runs (default: 1G).
EOF
}

while getopts "s:p:h" opt; do
    case "${opt}" in
        s) SUITE="${OPTARG}" ;;
        p) PHP_VERSION="${OPTARG}" ;;
        h) usage; exit 0 ;;
        *) usage; exit 64 ;;
    esac
done

if [[ -z "${SUITE}" ]]; then
    usage
    exit 64
fi

if [[ -n "${PHP_VERSION}" ]]; then
    echo "# Target PHP version: ${PHP_VERSION} (informational)"
fi

ensure_coverage_driver() {
    if ! php -m | grep -Eiq '^(xdebug|pcov)$'; then
        echo "Coverage suites require Xdebug or PCOV. Enable a coverage driver or run the non-coverage suite." >&2
        exit 65
    fi
}

run_unit() {
    php -d memory_limit="${PHP_MEMORY_LIMIT}" vendor/bin/phpunit --testsuite Unit
}

run_unit_coverage() {
    ensure_coverage_driver
    mkdir -p var/log/coverage/unit-html
    XDEBUG_MODE=coverage php -d memory_limit="${PHP_MEMORY_LIMIT}" vendor/bin/phpunit --testsuite Unit \
        --coverage-clover var/log/unit-coverage.xml \
        --coverage-html var/log/coverage/unit-html \
        --coverage-text
}

run_functional() {
    php -d memory_limit="${PHP_MEMORY_LIMIT}" vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml
}

run_functional_coverage() {
    ensure_coverage_driver
    mkdir -p var/log/coverage/functional-html
    XDEBUG_MODE=coverage php -d memory_limit="${PHP_MEMORY_LIMIT}" vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml \
        --coverage-clover var/log/functional-coverage.xml \
        --coverage-html var/log/coverage/functional-html \
        --coverage-text
}

run_architecture() {
    # PHPat rule classes under Tests/Architecture/ are evaluated by PHPStan
    # via vendor/phpat/phpat/extension.neon. There is no separate runner.
    vendor/bin/phpstan analyse --no-progress --memory-limit=512M
}

run_phpstan() {
    vendor/bin/phpstan analyse --no-progress --memory-limit=512M
}

run_cgl() {
    vendor/bin/php-cs-fixer fix --dry-run --diff
}

run_composer() {
    composer validate --strict
    composer audit --locked --abandoned=report
}

case "${SUITE}" in
    unit)                run_unit ;;
    unit-coverage)       run_unit_coverage ;;
    functional)          run_functional ;;
    functional-coverage) run_functional_coverage ;;
    architecture)        run_architecture ;;
    phpstan)             run_phpstan ;;
    cgl)                 run_cgl ;;
    composer)            run_composer ;;
    ci)
        run_composer
        run_cgl
        # run_phpstan also evaluates the PHPat architecture rules.
        run_phpstan
        run_unit
        ;;
    *)
        echo "Unknown suite: ${SUITE}" >&2
        usage
        exit 64
        ;;
esac
