#!/bin/bash
# Fast checks before a push: php -l on all, PHPUnit, PHPStan (level 8) and PSR-12 on src/, the tests, lib and the 16 scripts.
# PHPStan and PHPCS come as pinned phars in tools/bin (gitignored), downloaded on first use.
set -e
cd "$(dirname "$0")/.."
mkdir -p tools/bin
[ -f tools/bin/phpstan.phar ] || curl -sSL -o tools/bin/phpstan.phar https://github.com/phpstan/phpstan/releases/download/2.1.33/phpstan.phar
[ -f tools/bin/phpcs.phar ] || curl -sSL -o tools/bin/phpcs.phar https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/download/3.13.5/phpcs.phar
for f in [0-9]*.php lib/*.php src/*/*.php tools/*.php tools/testenv/*.php; do php -l "$f" > /dev/null; done
echo "php -l: ok"
vendor/bin/phpunit
php tools/bin/phpstan.phar analyse --no-progress --memory-limit=1G
# The 16 scripts, one analysis each (phpstan-scripts.neon), each with its own cache and its baseline if it has one.
mkdir -p tools/bin/phpstan
scripts=$(ls [0-9][0-9]_*.php)
for script in $scripts; do
  {
    echo "includes:"
    echo "    - $PWD/phpstan-scripts.neon"
    [ -f "tools/phpstan-baseline/$script.neon" ] && echo "    - $PWD/tools/phpstan-baseline/$script.neon"
    echo "parameters:"
    echo "    tmpDir: $PWD/tools/bin/phpstan/$script"
  } > "tools/bin/phpstan/$script.neon"
done
[ -z "$scripts" ] || printf '%s\n' $scripts | xargs -P 4 -I{} php tools/bin/phpstan.phar analyse --no-progress --memory-limit=1G \
  --error-format=raw -c "tools/bin/phpstan/{}.neon" {}
echo "PHPStan: the $(echo $scripts | wc -w) scripts ok"
php tools/bin/phpcs.phar
