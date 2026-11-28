#!/bin/bash
# Build the drill-only image offline from the locked QA image and a verified local wheel.
set -euo pipefail
export MSYS_NO_PATHCONV=1
if [ "$#" != 1 ] || [ ! -f "$1" ]; then
    echo "usage: prepare-drill-image.sh PATH/TO/pymysql-1.1.2-py3-none-any.whl" >&2
    exit 2
fi
base=${REPRO_QA_BASE_IMAGE:-repro-mysql-rehearsal-qa:20261122}
target=${REPRO_QA_IMAGE:-repro-mysql-drill-qa:pymysql-1.1.2}
docker image inspect "$base" >/dev/null
name=pymysql-1.1.2-py3-none-any.whl
digest=e6b1d89711dd51f8f74b1631fe08f039e7d76cf67a42a323d3178f0f25762ed9
context=$(mktemp -d)
trap 'rm -rf -- "$context"' EXIT
cp -- "$1" "$context/$name"
(cd "$context" && printf '%s  %s\n' "$digest" "$name" | sha256sum -c -)
cat >"$context/Dockerfile" <<'DOCKER'
ARG BASE=repro-mysql-rehearsal-qa:20261122
FROM ${BASE}
COPY pymysql-1.1.2-py3-none-any.whl /opt/repro-drill-tools/pymysql-1.1.2-py3-none-any.whl
DOCKER
(cd "$context" && docker build --network none --pull=false --build-arg "BASE=$base" -t "$target" .)
