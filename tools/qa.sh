#!/bin/bash
# Fast checks before a push: PHPUnit, PHPStan (level 8) and PSR-12 on the new code, php -l on all.
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
# The scripts, one analysis each (phpstan-scripts.neon), each with its own cache. Phase 6 adds them as they are clean.
analysed='01 02 03 04 05 06 07 09 12 13 14 15 16'
mkdir -p tools/bin/phpstan
scripts=$(for n in $analysed; do ls ${n}_*.php; done)
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
echo "PHPStan scripts: $(echo $scripts | wc -w) of 16 ok"
php tools/bin/phpcs.phar
