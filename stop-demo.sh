#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "$0")" && pwd)"
runtime_dir="$project_dir/work/runtime"

for name in agent-service-8005 queue-worker laravel; do
    pid_file="$runtime_dir/$name.pid"
    if [[ -f "$pid_file" ]] && kill -0 "$(<"$pid_file")" 2>/dev/null; then
        kill "$(<"$pid_file")"
        echo "Stopped $name."
    fi
done
