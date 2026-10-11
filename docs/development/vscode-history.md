# Local VS Code and Copilot history

[Development guide](README.md) | [Back to module README](../../README.md)

## 1. Launcher and storage

The local [VS Code launcher](../../.ddev/commands/host/code) lets Dev Containers
copy host Git configuration, restores `core.autocrlf=input`, and adds only the
attached project path to `safe.directory` when missing. Existing trust entries
are preserved.

The assistant-copilot add-on's read-only host mounts are configuration seeds,
not live history. Customized
[initialization](../../.ddev/config.assistant-copilot.yaml) synchronizes
configuration without replacing `session-state/`, `session-store.db`, or its
SQLite companion files.

[History mounts](../../.ddev/docker-compose.vscode-history.yaml) persist stores
under host `~/.ddev-vscode-history/<DDEV project name>/`:

- `vscode-data/` mounts at container `~/.vscode-server/data`, including the
  agent-host catalog and session databases. Server binaries remain disposable.
- `copilot/` mounts at container `~/.copilot`, including transcripts/history.
  The host's ordinary `~/.copilot` remains a read-only seed.

## 2. Migrate before restarting

**Before any restart or container recreation**, close the remote VS Code window
and run from a **host terminal** in the project directory:

```bash
ddev vscode-history
ddev debug compose-config
ddev restart
ddev code
```

The [migration helper](../../.ddev/vscode-history/storage.sh) pauses the web
container, copies both stores including SQLite WAL/SHM files, and resumes it.
This briefly interrupts the development site. It refuses nonempty destinations
and retains partial copies on failure. **Do not restart after failed migration**;
inspect the reported staging directory. Do not migrate from inside the container.

For a new project with **no existing web container**, run
`ddev vscode-history --initialize-empty` before the first `ddev start`.
The [startup hook](../../.ddev/config.vscode-history.yaml) requires initialized
storage; it is not a substitute for migrating existing history.

## 3. Verify and maintain

After restarting, use `findmnt` to verify both paths are bind mounts and check
that old sessions appear. Repeat a restart to verify new sessions survive.
These host/container-recreation checks cannot run through the container's
limited `ddev` shim.

Storage is private and outside Git; it may contain credentials and conversation
content. Back it up securely. The launcher and modified add-on configuration
are custom-owned (generated markers removed), so reconcile add-on updates
manually. These local files are ignored/untracked; reinstalling an add-on alone
does not reproduce the setup.
