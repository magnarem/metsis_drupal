#!/usr/bin/env bash
#ddev-generated
#
# Resolve a GitHub token on the HOST, where the OS keychain lives.
#
# GitHub CLI stores tokens in the OS keychain by default (macOS Keychain, GNOME
# Keyring via Secret Service, Windows Credential Manager) and only falls back to
# a plaintext `oauth_token:` in hosts.yml when no keychain is reachable. The web
# container has no access to any host keychain, so an in-container
# `gh auth token` succeeds only on hosts that happened to take the plaintext
# fallback — which is why authentication forwarding worked on headless Linux but
# not on macOS or a Linux desktop.
#
# Reading the token here, on the host, and handing it to the container over a
# read-only bind mount makes forwarding behave identically on every OS.
#
# Written for bash 3.2 so it runs on stock macOS /bin/bash.
#
# Usage:
#   host-gh-token.sh --print   Print the resolved token to stdout (non-zero if none).
#   host-gh-token.sh --seed    Write the token file, unless ephemeral mode is on.
#   host-gh-token.sh --write   Write the token file unconditionally.
#   host-gh-token.sh --clear   Remove the token file.
#   host-gh-token.sh --path    Print the host token file path.
#   host-gh-token.sh --is-ephemeral  Exit 0 when ephemeral token mode is enabled.

set -eu

APPROOT="${DDEV_APPROOT:-$PWD}"

# Read a `- KEY=value` entry from the project's DDEV config files. Commented-out
# lines start with `#` and so never match. Later files win, which lets
# config.local.yaml override the add-on default.
setting() {
  key="$1"
  value=""
  for file in "${APPROOT}"/.ddev/config.yaml "${APPROOT}"/.ddev/config.*.yaml; do
    [ -f "${file}" ] || continue
    v="$(sed -n "s/^[[:space:]]*-[[:space:]]*${key}=[[:space:]]*//p" "${file}" | tail -n1)"
    v="$(printf '%s' "${v}" | tr -d '\r' | sed -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//")"
    [ -n "${v}" ] || continue
    value="${v}"
  done
  # A real environment variable on the host always wins over the config file.
  eval "env_value=\"\${${key}:-}\""
  [ -n "${env_value}" ] && value="${env_value}"
  printf '%s' "${value}"
}

project_name() {
  name=""
  if [ -f "${APPROOT}/.ddev/config.yaml" ]; then
    name="$(sed -n 's/^name:[[:space:]]*//p' "${APPROOT}/.ddev/config.yaml" | head -n1)"
    name="$(printf '%s' "${name}" | tr -d '\r"'"'" | tr -d '[:space:]')"
  fi
  [ -n "${name}" ] || name="$(basename "${APPROOT}")"
  printf '%s' "${name}"
}

token_dir() {
  printf '%s/.ddev-assistant-copilot/%s' "${HOME}" "$(project_name)"
}

token_file() {
  printf '%s/token' "$(token_dir)"
}

ephemeral_enabled() {
  case "$(setting DDEV_ASSISTANT_COPILOT_EPHEMERAL_TOKEN)" in
    [tT][rR][uU][eE] | 1 | [yY][eE][sS]) return 0 ;;
    *) return 1 ;;
  esac
}

# Ensure the bind-mount source exists before Docker creates it as root.
ensure_dir() {
  mkdir -p "$(token_dir)"
  chmod 700 "${HOME}/.ddev-assistant-copilot" "$(token_dir)" 2>/dev/null || true
}

resolve_token() {
  if ! command -v gh >/dev/null 2>&1; then
    echo "ddev-assistant-copilot: gh not found on host; cannot forward GitHub authentication." >&2
    return 1
  fi

  gh_hostname="$(setting DDEV_ASSISTANT_COPILOT_GH_HOSTNAME)"
  gh_user="$(setting DDEV_ASSISTANT_COPILOT_GH_USER)"

  set --
  [ -n "${gh_hostname}" ] && set -- "$@" --hostname "${gh_hostname}"
  [ -n "${gh_user}" ] && set -- "$@" --user "${gh_user}"

  token="$(gh auth token "$@" 2>/dev/null || true)"
  token="$(printf '%s' "${token}" | tr -d '\r\n')"

  if [ -z "${token}" ]; then
    echo "ddev-assistant-copilot: host \`gh auth token${gh_hostname:+ --hostname ${gh_hostname}}${gh_user:+ --user ${gh_user}}\` returned no token." >&2
    echo "ddev-assistant-copilot: run \`gh auth login\` on the host, or \`gh auth status\` to check the active account." >&2
    return 1
  fi

  printf '%s' "${token}"
}

write_token() {
  ensure_dir
  if ! token="$(resolve_token)"; then
    # Leave any previously written token in place rather than breaking a working
    # container, and never fail the hook: an unauthenticated host must still be
    # able to start the project.
    return 0
  fi
  (
    umask 077
    printf '%s' "${token}" >"$(token_file)"
  )
  chmod 600 "$(token_file)" 2>/dev/null || true
}

clear_token() {
  rm -f "$(token_file)" 2>/dev/null || true
}

case "${1:---print}" in
  --print)
    resolve_token
    ;;
  --seed)
    ensure_dir
    if ephemeral_enabled; then
      # Ephemeral mode: only `ddev copilot` materialises a token, and it removes
      # it again on exit. Clear anything a previous non-ephemeral start wrote.
      clear_token
    else
      write_token
    fi
    ;;
  --write)
    write_token
    ;;
  --clear)
    clear_token
    ;;
  --path)
    token_file
    ;;
  --is-ephemeral)
    ephemeral_enabled
    ;;
  *)
    echo "usage: $(basename "$0") [--print|--seed|--write|--clear|--path|--is-ephemeral]" >&2
    exit 64
    ;;
esac
