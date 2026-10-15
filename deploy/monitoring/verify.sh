#!/usr/bin/env bash

set -eu

script_dir=$(cd "$(dirname "$0")" && pwd)
project_name="javmeta-monitoring-verify-$$"
export OBS_HOST_PORT=${OBS_HOST_PORT:-15080}
compose_base="docker compose --project-name ${project_name} --file ${script_dir}/compose.yml --file ${script_dir}/compose.local-test.yml"
search_url="http://127.0.0.1:${OBS_HOST_PORT:-5080}/api/default/_search?type=metrics"
auth_user='local-observe@example.invalid'
auth_password='local-Observe-2026!'

cleanup() {
    ${compose_base} down --volumes --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

search_metric() {
    metric=$1
    now_seconds=$(date +%s)
    start_time=$(( (now_seconds - 86400) * 1000000 ))
    end_time=$(( (now_seconds + 60) * 1000000 ))
    body=$(printf '%s' "{\"query\":{\"sql\":\"SELECT * FROM \\\"${metric}\\\" WHERE node = 'verify-node' AND role = 'control' LIMIT 1\",\"start_time\":${start_time},\"end_time\":${end_time}},\"from\":0,\"size\":1}")
    curl -fsS --user "${auth_user}:${auth_password}" \
        -H 'Content-Type: application/json' \
        -d "${body}" "${search_url}"
}

wait_for_openobserve() {
    attempt=1
    while [ "${attempt}" -le 60 ]; do
        if curl -fsS --user "${auth_user}:${auth_password}" \
            "http://127.0.0.1:${OBS_HOST_PORT:-5080}/healthz" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
        attempt=$((attempt + 1))
    done
    echo 'OpenObserve did not become ready' >&2
    return 1
}

wait_for_metric() {
    metric=$1
    attempt=1
    while [ "${attempt}" -le 60 ]; do
        if response=$(search_metric "${metric}" 2>/dev/null); then
            if printf '%s' "${response}" | grep -q 'verify-node' &&
                printf '%s' "${response}" | grep -q 'control'; then
                printf '%s\n' "${response}"
                return 0
            fi
        fi
        sleep 2
        attempt=$((attempt + 1))
    done
    echo "Metric did not arrive with node/role labels: ${metric}" >&2
    return 1
}

${compose_base} up -d --wait
wait_for_openobserve

echo 'CPU metric:'
wait_for_metric node_cpu_seconds_total
echo 'Memory available metric:'
# OpenObserve 0.92.0 lowercases this stream name while preserving __name__.
wait_for_metric node_memory_memavailable_bytes
echo 'Container metric:'
wait_for_metric container_start_time_seconds

if curl -fsS "http://127.0.0.1:${NODE_EXPORTER_HOST_PORT:-9100}/metrics" 2>/dev/null |
    grep -q '^node_pressure_cpu_waiting_seconds_total'; then
    echo 'CPU PSI metric:'
    wait_for_metric node_pressure_cpu_waiting_seconds_total
else
    echo 'PSI not available on this kernel'
fi

echo 'Monitoring verification passed.'
