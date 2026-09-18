#!/usr/bin/env bash
#
# FrankenPHP worker rehearsal orchestration.
#
# Starts disposable MariaDB/MySQL fixtures, runs the synthetic rehearsal app in
# classic and worker mode, drives deterministic scenarios, and optionally runs
# a bounded mixed-workload soak. Reports and environment captures are written
# to tools/worker-rehearsal/results/ (ignored by Git).

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(cd "${script_dir}/../.." && pwd)"
compose_file="${project_dir}/tools/database-probes/compose.yaml"
image="${REHEARSAL_IMAGE:-simplequery-worker-rehearsal:1-php8.4}"
network="php-simplequery-database-probes_probe"
soak_seconds="${REHEARSAL_SOAK_SECONDS:-60}"
idle_seconds="${REHEARSAL_IDLE_SECONDS:-1.0}"
engines="${REHEARSAL_ENGINES:-mariadb mysql}"
run_id="${REHEARSAL_RUN_ID:-$$}"
port_base="${REHEARSAL_PORT_BASE:-18080}"
result_dir="${script_dir}/results/${run_id}"

mkdir -p "${result_dir}"
find "${result_dir}" -mindepth 1 -maxdepth 1 -type f -name '*.json' -delete

container_prefix="sq-rehearsal-${run_id}"
cleanup() {
    docker ps -aq --filter "name=${container_prefix}-" | xargs -r docker rm -f >/dev/null 2>&1 || true
    if [[ "${REHEARSAL_KEEP_SERVICES:-false}" != "true" ]]; then
        docker compose -f "${compose_file}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

docker build -q -t "${image}" "${script_dir}" >/dev/null
docker compose -f "${compose_file}" up --detach --wait --wait-timeout 300 mariadb mysql

for engine in ${engines}; do
    if [[ "${engine}" == "mariadb" ]]; then
        client="mariadb"
    else
        client="mysql"
    fi
    docker compose -f "${compose_file}" exec -T "${engine}" \
        "${client}" -uroot -psimplequery-root simplequery \
        -e "CREATE TABLE IF NOT EXISTS simplequery_rehearsal_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            marker VARCHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
done

start_server() {
    local name="$1" port="$2" mode="$3" engine="$4"
    docker run --detach --rm --name "${name}" --network "${network}" -p "${port}:8080" \
        --volume "${project_dir}:/app:ro" \
        --env "REHEARSAL_DRIVER=${engine}" \
        --env "REHEARSAL_DSN=mysql:host=${engine};port=3306;dbname=simplequery;charset=utf8mb4" \
        --env "REHEARSAL_USER=simplequery" \
        --env "REHEARSAL_PASSWORD=simplequery" \
        --env "REHEARSAL_ADMIN_USER=root" \
        --env "REHEARSAL_ADMIN_PASSWORD=simplequery-root" \
        --env "REHEARSAL_IDLE_SECONDS=${idle_seconds}" \
        "${image}" frankenphp run \
        --config "/app/tools/worker-rehearsal/Caddyfile.${mode}" \
        --adapter caddyfile
}

wait_ready() {
    local url="$1"
    for _ in $(seq 1 90); do
        if curl --fail --silent "${url}/health" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Server at ${url} did not become ready." >&2
    return 1
}

docker run --rm --entrypoint frankenphp "${image}" version \
    > "${result_dir}/frankenphp-version.txt" 2>&1
docker image inspect --format '{{.Id}}' "${image}" \
    > "${result_dir}/image-id.txt" 2>&1
docker run --rm --entrypoint php "${image}" -r \
    'echo PHP_VERSION, " zts=", ZEND_THREAD_SAFE ? "1" : "0", PHP_EOL;' \
    > "${result_dir}/php-version.txt" 2>&1
docker run --rm --entrypoint php "${image}" -m \
    > "${result_dir}/php-modules.txt" 2>&1

for engine in ${engines}; do
    for mode in classic worker; do
        if [[ "${mode}" == "classic" ]]; then
            port="${port_base}"
        else
            port="$((port_base + 1))"
        fi
        name="${container_prefix}-${mode}-${engine}"
        start_server "${name}" "${port}" "${mode}" "${engine}"
        wait_ready "http://127.0.0.1:${port}"
        soak=0
        if [[ "${mode}" == "worker" ]]; then
            soak="${soak_seconds}"
        fi
        php "${script_dir}/drive.php" \
            --base-url="http://127.0.0.1:${port}" \
            --mode="${mode}" \
            --idle-seconds="${idle_seconds}" \
            --soak-seconds="${soak}" \
            --output="${result_dir}/${engine}-${mode}.json"
        docker rm -f "${name}" >/dev/null
    done
done

echo "Worker rehearsal complete; reports in ${result_dir}."
