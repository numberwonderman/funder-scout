#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "$0")" && pwd)"
runtime_dir="$project_dir/work/runtime"
mkdir -p "$runtime_dir"

start_service() {
    local name="$1"
    local working_dir="$2"
    shift 2
    local pid_file="$runtime_dir/$name.pid"

    if [[ -f "$pid_file" ]] && kill -0 "$(<"$pid_file")" 2>/dev/null; then
        echo "$name is already running (PID $(<"$pid_file"))."
        return
    fi

    (
        cd "$working_dir"
        nohup "$@" >"$runtime_dir/$name.log" 2>&1 </dev/null &
        echo $! >"$pid_file"
    )
    echo "Started $name (PID $(<"$pid_file"))."
}

start_service agent-service-8008 "$project_dir/agent-service" .venv/bin/uvicorn app.main:app --env-file .env --host 127.0.0.1 --port 8008
start_service queue-worker "$project_dir/laravel-app" php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=90
start_service laravel "$project_dir/laravel-app" php -d max_execution_time=0 artisan serve --host=127.0.0.1 --port=8000

for attempt in {1..30}; do
    if curl --silent --fail http://127.0.0.1:8008/health >/dev/null \
        && curl --silent --fail http://127.0.0.1:8000/ >/dev/null; then
        echo "Funder Scout is ready at http://127.0.0.1:8000"
        exit 0
    fi
    sleep 0.2
done

echo "A service did not become ready. Check work/runtime/*.log." >&2
exit 1
