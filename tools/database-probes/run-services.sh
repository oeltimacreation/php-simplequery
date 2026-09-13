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

mkdir -p "${result_dir}/environment"
for service in mariadb mysql proxysql maxscale; do
    image_id=$(docker compose -f "${compose_file}" images -q "${service}")
    docker image inspect --format '{{json .RepoDigests}}' "${image_id}" \
        > "${result_dir}/environment/${service}-image.json"
done
docker compose -f "${compose_file}" exec -T proxysql proxysql --version \
    > "${result_dir}/environment/proxysql-version.txt"
docker compose -f "${compose_file}" exec -T maxscale maxscale --version \
    > "${result_dir}/environment/maxscale-version.txt"

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
            PROBE_EMULATE_PREPARES="${emulate}" PROBE_BUFFERED="${buffered}" \
                php "${probe_dir}/execution-smoke.php" "${target}" \
                --output="${result_dir}/${target}-execution-${prepare_mode}-${buffering_mode}.json"
            PROBE_EMULATE_PREPARES="${emulate}" PROBE_BUFFERED="${buffered}" \
                php "${probe_dir}/transaction-smoke.php" "${target}" \
                --output="${result_dir}/${target}-transaction-${prepare_mode}-${buffering_mode}.json"
        done
    done
done

if [[ "${RUN_BENCHMARKS:-false}" == "true" ]]; then
    benchmark_result_dir="${project_dir}/benchmarks/results"
    mkdir -p "${benchmark_result_dir}"
    find "${benchmark_result_dir}" -mindepth 1 -maxdepth 1 -type f -name '*.json' -delete

    for buffering_mode in buffered unbuffered; do
        if [[ "${buffering_mode}" == "buffered" ]]; then
            buffered=true
        else
            buffered=false
        fi

        for target in mariadb mysql proxysql maxscale; do
            PROBE_EMULATE_PREPARES=false \
            PROBE_BUFFERED="${buffered}" \
                php -d pcov.enabled=0 -d xdebug.mode=off \
                "${project_dir}/benchmarks/engine.php" "${target}" \
                > "${benchmark_result_dir}/${target}-native-${buffering_mode}.json"
        done
    done

    soak_pids=()
    for worker in 1 2 3 4; do
        php -d pcov.enabled=0 -d xdebug.mode=off "${project_dir}/benchmarks/run.php" \
            --suite=soak --profile=ci --iterations=5 --warmups=1 \
            > "${benchmark_result_dir}/soak-${worker}.json" &
        soak_pids+=("$!")
    done
    for soak_pid in "${soak_pids[@]}"; do
        wait "${soak_pid}"
    done
fi

php "${probe_dir}/summarize.php" "${result_dir}"
