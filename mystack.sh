#!/bin/sh
# MyStack portable launcher for Linux/macOS.
# Works even when the executable/shebang bit of ./mystack is unavailable
# (SMB/NFS mounts, copied trees). `php mystack` remains the universal fallback.
exec php "$(dirname "$0")/mystack" "$@"
