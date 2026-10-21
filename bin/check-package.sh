#!/usr/bin/env bash
# Builds the WordPress.org package the way the deploy action does (rsync with
# .distignore) and checks what it would ship. Run it on a `composer install
# --no-dev` tree, as the release workflow builds one.
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
out=$(mktemp -d)
trap 'rm -rf "$out"' EXIT

rsync -rc --exclude-from="$root/.distignore" "$root/" "$out/"

fail=0

for path in owa-plugin.php README.txt src/module.php vendor/autoload.php \
            vendor/open-web-analytics/owa-php-sdk/src/sdk.php; do
	[ -e "$out/$path" ] || { echo "missing from package: $path"; fail=1; }
done

shipped=$(cd "$out" && find . \( -type d \( -name tests -o -name .github -o -name phpunit -o -name node_modules \) -prune -print \) \
	-o \( -name 'phpunit.xml*' -o -name 'phpstan.neon*' -o -name composer.lock -o -name .distignore \) -print | sed 's#^\./##')

if [ -n "$shipped" ]; then
	echo "development files in package:"
	echo "$shipped"
	fail=1
fi

if ! php -r 'require $argv[1] . "/vendor/autoload.php"; exit( class_exists( "OwaSdk\\sdk" ) ? 0 : 1 );' "$out"; then
	echo "the packaged autoloader does not load the SDK"
	fail=1
fi

echo "package: $(cd "$out" && find . -type f | wc -l) files"
exit $fail
