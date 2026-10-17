#!/bin/sh
# Local test runner for this Windows box. Not part of the project; the repo
# runs its suite via `composer test` / `pest` on CI.
#
# Needs two things this winget PHP build does not provide by default:
#   - OPENSSL_CONF: the bundled extras/ssl/openssl.cnf sets dir=./demoCA with
#     config_diagnostics=1 and that directory does not exist, so
#     openssl_pkey_new() fails and UserObserver's RSA keygen dies.
#   - php-local.ini: enables the extensions the build ships.
set -e

PHPBIN="/c/Users/Etemadi/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe"
cd "$(dirname "$0")"

OPENSSL_CONF="Q:\\Vibe Coding\\Pixelfed\\openssl-local.cnf" \
  "$PHPBIN" -c php-local.ini vendor/bin/pest "$@"