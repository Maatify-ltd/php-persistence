#!/usr/bin/env bash
set -euo pipefail

# Run Composer's solver on isolated roots with only the package's PHP requirement.
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
constraint="$(php -- "$root/composer.json" <<'PHP'
<?php
$contents = file_get_contents($argv[1]);
if ($contents === false) {
    exit(1);
}
$manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
$constraint = $manifest["require"]["php"] ?? null;
if (!is_string($constraint) || trim($constraint) === "" || strpbrk($constraint, "\r\n") !== false) {
    fwrite(STDERR, "Invalid require.php in composer.json\n");
    exit(1);
}
echo $constraint;
PHP
)"
command -v composer >/dev/null || { echo 'Composer is required for the PHP constraint check' >&2; exit 1; }

workspace="$(mktemp -d "${TMPDIR:-/tmp}/php-persistence-constraint.XXXXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT
trap 'exit 1' HUP INT TERM
mkdir -p "$workspace/home" "$workspace/cache"

failed=0
while read -r version expected; do
  directory="$workspace/$version"
  mkdir "$directory"
  php -- "$constraint" "$version" > "$directory/composer.json" <<'PHP'
<?php
echo json_encode([
    "require" => ["php" => $argv[1]],
    "config" => ["platform" => ["php" => $argv[2]]],
    "repositories" => [["packagist.org" => false]],
], JSON_THROW_ON_ERROR);
PHP

  status=0
  output="$(env -i "PATH=$PATH" LC_ALL=C \
    "COMPOSER_HOME=$workspace/home" "COMPOSER_CACHE_DIR=$workspace/cache" \
    COMPOSER_DISABLE_NETWORK=1 composer --working-dir="$directory" \
    --no-interaction --no-plugins --no-scripts --no-ansi \
    update --dry-run --no-progress 2>&1)" || status=$?

  if [[ "$expected" == accept && "$status" == 0 ]]; then
    printf 'PASS: Composer accepts simulated PHP %s\n' "$version"
  elif [[ "$expected" == reject && "$status" == 2 ]] \
    && grep -F -- "Root composer.json requires php $constraint but your php version ($version; overridden via config.platform" <<< "$output" \
      | grep -Fq ') does not satisfy that requirement.'; then
    printf 'PASS: Composer rejects simulated PHP %s for the PHP requirement\n' "$version"
  else
    printf 'FAIL: expected Composer to %s simulated PHP %s (exit %s)\n%s\n' "$expected" "$version" "$status" "$output" >&2
    failed=1
  fi
done <<'CASES'
8.3.99 reject
8.4.0 reject
8.4.26 reject
8.4.27 accept
8.5.0 reject
8.5.11 reject
8.5.12 accept
8.6.0 accept
8.7.0 accept
9.0.0 reject
CASES
exit "$failed"
