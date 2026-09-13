#!/usr/bin/env bash
set -euo pipefail

probe_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(cd "${probe_dir}/../.." && pwd)"
sqlite_work="${project_dir}/build/sqlite-minimum"
sqlite_install="${sqlite_work}/install"
mkdir -p "${sqlite_work}"

if [[ ! -f "${sqlite_install}/lib/libsqlite3.so" ]]; then
    curl --fail --location --retry 2 \
        https://www.sqlite.org/2022/sqlite-autoconf-3390200.tar.gz \
        --output "${sqlite_work}/sqlite.tar.gz"
    printf '%s  %s\n' \
        852be8a6183a17ba47cee0bbff7400b7aa5affd283bf3beefc34fcd088a239de \
        "${sqlite_work}/sqlite.tar.gz" | sha256sum --check
    tar -xzf "${sqlite_work}/sqlite.tar.gz" -C "${sqlite_work}"
    (
        cd "${sqlite_work}/sqlite-autoconf-3390200"
        ./configure --prefix="${sqlite_install}" --disable-static --enable-shared --disable-readline \
            CFLAGS='-O2 -DSQLITE_ENABLE_COLUMN_METADATA'
        make -j2
        make install
    )
fi

export LD_LIBRARY_PATH="${sqlite_install}/lib${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"
cd "${project_dir}"
php -r '$v = (new PDO("sqlite::memory:"))->query("SELECT sqlite_version()")->fetchColumn();
    if ($v !== "3.39.2") { fwrite(STDERR, "Minimum SQLite was not loaded.\n"); exit(1); }
    echo "Actual PDO SQLite runtime: ", $v, PHP_EOL;'
result_dir="${probe_dir}/results/sqlite-minimum"
mkdir -p "${result_dir}"
vendor/bin/phpunit tests/Integration/SQLite
php "${probe_dir}/run.php" sqlite --output="${result_dir}/behavior.json"
php "${probe_dir}/execution-smoke.php" sqlite --output="${result_dir}/execution.json"
php "${probe_dir}/transaction-smoke.php" sqlite --output="${result_dir}/transaction.json"
