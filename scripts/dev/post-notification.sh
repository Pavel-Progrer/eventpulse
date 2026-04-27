#!/usr/bin/env bash
set -euo pipefail

curl -i -X POST http://localhost:8080/api/v1/notifications \
  -H "Authorization: Bearer ${API_KEY:-ep_live_dev_001}" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d "${1:-@-}"
  