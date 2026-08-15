#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'HELP'
Usage: ./clean4prod.sh [--hosting | --docker] [--dry-run] [--target DIRECTORY]

Cleans an Account+ deployment copy before deploying to production.
Without --target, cleanup applies to the directory containing this script.

  --hosting       Removes Docker files for a hosting deployment (default).
  --docker        Keeps Docker build and deployment files.
  --dry-run       Lists files to remove without changing them.
  --target DIR    Cleans another copy of the project.
  -h, --help      Shows this help message.

Removes Git metadata, CI tools, tests, project documentation and editor files.
Preserves application files, configuration, secrets, data and licenses.
Keeps .env.example for Docker; removes this template in hosting mode.
HELP
}

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

project_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
dry_run=false
deployment=hosting
explicit_deployment=''

while (($#)); do
    case "$1" in
        --hosting|--docker)
            selected=${1#--}
            [[ -z "$explicit_deployment" || "$explicit_deployment" == "$selected" ]] || fail 'Choose either --hosting or --docker.'
            deployment=$selected
            explicit_deployment=$selected
            shift
            ;;
        --dry-run) dry_run=true; shift ;;
        --target)
            (($# >= 2)) || fail 'Missing directory after --target.'
            [[ -n "$2" ]] || fail 'The target directory is empty.'
            project_dir=$2
            shift 2
            ;;
        -h|--help) usage; exit 0 ;;
        *) fail "Unknown option: $1. Use --help." ;;
    esac
done

project_dir=$(cd -- "$project_dir" && pwd -P) || fail 'The target directory is inaccessible.'
[[ "$project_dir" != / ]] || fail 'The filesystem root cannot be cleaned.'
for required in index.php .htaccess app/src/Application/AppKernel.php assets/css/app.css; do
    [[ -f "$project_dir/$required" ]] || fail "This directory does not look like Account+: $required is missing."
done

# Only known development files are removed. Unknown files are left untouched.
relative_targets=(
    tests docs tools .github .git .gitignore .gitattributes
    .idea .vscode .agents .codex AGENTS.md README.md SECURITY.md .editorconfig .DS_Store
    chart-check.html
)
if [[ "$deployment" == hosting ]]; then
    relative_targets+=(Dockerfile docker-compose.yml .dockerignore docker .env.example app/bin/prepare-apache.php)
fi

targets=()
for relative_path in "${relative_targets[@]}"; do
    path="$project_dir/$relative_path"
    if [[ -e "$path" || -L "$path" ]]; then
        targets+=("$path")
    fi
done

# Include editor swap files at the project root.
shopt -s nullglob
for path in "$project_dir"/.*.swp "$project_dir"/.*.swo; do
    targets+=("$path")
done

printf 'Directory: %s\n' "$project_dir"
printf 'Deployment: %s\n' "$deployment"
if "$dry_run"; then
    printf 'Dry run: no files will be deleted.\n'
    action='[would remove]'
else
    action='[removing]'
fi
for path in "${targets[@]}"; do
    printf '%s %s\n' "$action" "${path#"$project_dir"/}"
    if ! "$dry_run"; then
        rm -rf -- "$path"
    fi
done

if "$dry_run"; then
    printf '%d item(s) would be removed.\n' "${#targets[@]}"
else
    printf 'Cleanup complete: %d item(s) removed. Configuration and data preserved.\n' "${#targets[@]}"
fi
