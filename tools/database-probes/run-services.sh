#!/usr/bin/env bash

set -euo pipefail

probe_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(cd "${probe_dir}/../.." && pwd)"
compose_file="${probe_dir}/compose.yaml"
result_dir="${probe_dir}/results"

mkdir -p "${result_dir}"
find "${result_dir}" -mindepth 1 -maxdepth 1 -type f -name '*.json' -delete

cleanup() {
    docker compose -f "${compose_file}" down --volumes --remove-orphans
}

trap cleanup EXIT
docker compose -f "${compose_file}" up --detach --wait --wait-timeout 180

cd "${project_dir}"

for target in mariadb mysql proxysql maxscale; do
    php "${probe_dir}/wait.php" "${target}"
done

php "${probe_dir}/run.php" sqlite --output="${result_dir}/sqlite.json"
php "${probe_dir}/execution-smoke.php" sqlite --output="${result_dir}/sqlite-execution.json"
php "${probe_dir}/transaction-smoke.php" sqlite --output="${result_dir}/sqlite-transaction.json"

for prepare_mode in native emulated; do
    if [[ "${prepare_mode}" == "native" ]]; then
        emulate=false
    else
        emulate=true
    fi

    for buffering_mode in buffered unbuffered; do
        if [[ "${buffering_mode}" == "buffered" ]]; then
            buffered=true
        else
            buffered=false
        fi

        for target in mariadb mysql proxysql maxscale; do
            PROBE_EMULATE_PREPARES="${emulate}" \
            PROBE_BUFFERED="${buffered}" \
                php "${probe_dir}/run.php" "${target}" \
                --output="${result_dir}/${target}-${prepare_mode}-${buffering_mode}.json"
        done
    done
done

for target in mariadb mysql proxysql maxscale; do
    php "${probe_dir}/execution-smoke.php" "${target}" \
        --output="${result_dir}/${target}-execution-native-buffered.json"
    php "${probe_dir}/transaction-smoke.php" "${target}" \
        --output="${result_dir}/${target}-transaction-native-buffered.json"
done

php "${probe_dir}/summarize.php" "${result_dir}"
