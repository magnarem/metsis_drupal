#!/usr/bin/env bash
set -euo pipefail
umask 077

project="${DDEV_SITENAME:-${DDEV_PROJECT:-}}"
if [[ -z "$project" || ! "$project" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]]; then
    printf 'ERROR: Missing or invalid DDEV project name.\n' >&2
    exit 1
fi

storage="${HOME}/.ddev-vscode-history/${project}"
container="ddev-${project}-web"
marker="${storage}/.migrated"
mode="${1:-}"

if [[ $# -gt 1 || ( -n "$mode" && "$mode" != "--prepare" && "$mode" != "--initialize-empty" ) ]]; then
    printf 'Usage: ddev vscode-history [--initialize-empty]\n' >&2
    exit 1
fi

if [[ "$mode" == "--prepare" ]]; then
    if [[ ! -f "$marker" || ! -d "${storage}/vscode-data" || ! -d "${storage}/copilot" ]]; then
        printf 'ERROR: History storage is not initialized. Close VS Code and run ddev vscode-history before restarting. For a new project without a web container, use --initialize-empty.\n' >&2
        exit 1
    fi
    chmod 700 "$storage" "${storage}/vscode-data" "${storage}/copilot"
    exit 0
fi

command -v docker >/dev/null
if [[ -e "$marker" ]]; then
    printf 'ERROR: Storage is already initialized at %s; refusing to overwrite it.\n' "$storage" >&2
    exit 1
fi
for directory in vscode-data copilot; do
    target="${storage}/${directory}"
    if [[ -e "$target" && ( ! -d "$target" || -n "$(find "$target" -mindepth 1 -maxdepth 1 -print -quit)" ) ]]; then
        printf 'ERROR: Destination is not an empty directory: %s\n' "$target" >&2
        exit 1
    fi
done

if [[ "$mode" == "--initialize-empty" ]]; then
    if ! docker info >/dev/null; then
        printf 'ERROR: Cannot check for existing containers; Docker must be available.\n' >&2
        exit 1
    fi
    existing="$(docker container ls --all --filter "name=^/${container}$" --format '{{.Names}}')"
    if [[ -n "$existing" ]]; then
        printf 'ERROR: A web container already exists. Migrate it with ddev vscode-history instead.\n' >&2
        exit 1
    fi
    mkdir -p "${storage}/vscode-data" "${storage}/copilot"
    touch "$marker"
    printf 'Initialized empty history storage at %s\n' "$storage"
    exit 0
fi

if [[ "$(docker container inspect --format '{{.State.Running}} {{.State.Paused}}' "$container")" != "true false" ]]; then
    printf 'ERROR: The existing web container must be running and not paused. Do not recreate it before migration.\n' >&2
    exit 1
fi

container_home="$(docker exec "$container" printenv HOME)"
if [[ ! "$container_home" =~ ^/home/[^/]+$ ]]; then
    printf 'ERROR: Unexpected web container HOME: %s\n' "$container_home" >&2
    exit 1
fi
for directory in .vscode-server/data .copilot; do
    if ! docker exec "$container" test -d "${container_home}/${directory}"; then
        printf 'ERROR: Missing migration source: %s/%s\n' "$container_home" "$directory" >&2
        exit 1
    fi
done

mkdir -p "$storage"
staging="$(mktemp -d "${storage}/.migration.XXXXXX")"
paused=false
cleanup() {
    status=$?
    trap - EXIT
    if [[ "$paused" == true ]]; then
        if ! docker unpause "$container" >/dev/null; then
            printf 'ERROR: Container could not be resumed. Run docker unpause %s from the host.\n' "$container" >&2
            status=1
        fi
    fi
    if [[ "$status" -ne 0 ]]; then
        printf 'ERROR: Migration failed; retained copied data at %s. No restart is authorized.\n' "$staging" >&2
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

printf 'Pausing %s while copying history; the site will briefly be unavailable.\n' "$container"
docker pause "$container" >/dev/null
paused=true
docker cp "${container}:${container_home}/.vscode-server/data" "${staging}/vscode-data"
docker cp "${container}:${container_home}/.copilot" "${staging}/copilot"
find "$staging" -type d -exec chmod 700 {} +
find "$staging" -type f -exec chmod go-rwx {} +
for directory in vscode-data copilot; do
    target="${storage}/${directory}"
    if [[ -d "$target" ]]; then
        rmdir "$target"
    fi
    mv "${staging}/${directory}" "$target"
done
docker unpause "$container" >/dev/null
paused=false
touch "$marker"
rmdir "$staging"
printf 'Migrated history to %s. Do not create new sessions before ddev restart; reconnect afterward with ddev code.\n' "$storage"
