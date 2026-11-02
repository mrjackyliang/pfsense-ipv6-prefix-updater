#!/bin/sh

set -eu

workspace_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

if command -v php >/dev/null 2>&1; then
	php "${workspace_directory}/tests/domain.php"
	php "${workspace_directory}/tests/runtime.php"
	php "${workspace_directory}/tests/pfsense-adapter.php"
	php "${workspace_directory}/tests/translations.php"
	php "${workspace_directory}/tests/webgui-localization.php"

	exit 0
fi

if command -v docker >/dev/null 2>&1; then
	php_test_image="${PHP_TEST_IMAGE:-php:8.3-cli-alpine}"

	exec docker run --rm \
		--volume "${workspace_directory}:/workspace:ro" \
		--workdir /workspace \
		"${php_test_image}" \
		sh -c 'php ./tests/domain.php && php ./tests/runtime.php && php ./tests/pfsense-adapter.php && php ./tests/translations.php && php ./tests/webgui-localization.php'
fi

echo "PHP domain tests require either a local php executable or Docker." >&2

exit 1
