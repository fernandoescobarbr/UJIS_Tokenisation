#!/usr/bin/env bash
# Purpose: Run Caliper benchmark profiles (baseline, stress, sweep) with cool-downs between runs.
# Notes:
# - Each profile YAML already specifies all rounds; this script only sequences full profiles.
# - Reports are timestamped and moved into ./results/ after each run.
# - Tweak repetitions and cool-downs via the variables below or environment overrides.

set -Eeuo pipefail

############### Adjustable parameters ###############
# Repetitions per profile
REPS_BASELINE="${REPS_BASELINE:-3}"
REPS_STRESS="${REPS_STRESS:-3}"
REPS_SWEEP="${REPS_SWEEP:-1}"

# Cool-downs (seconds)
COOLDOWN_BETWEEN_RUNS="${COOLDOWN_BETWEEN_RUNS:-90}"   # between runs within the same profile
COOLDOWN_AFTER_PROFILE="${COOLDOWN_AFTER_PROFILE:-90}" # between different profiles
COOLDOWN_AFTER_STRESS="${COOLDOWN_AFTER_STRESS:-120}"  # after finishing the stress profile

# Paths (relative to this script's directory)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WS_DIR="${SCRIPT_DIR}"
RESULTS_DIR="${WS_DIR}/results"
NETWORK_CFG="${WS_DIR}/networks/fabric-ujis.yaml"

BASELINE_BENCH="${WS_DIR}/benchmarks/ujis-benchmark-baseline.yaml"
STRESS_BENCH="${WS_DIR}/benchmarks/ujis-benchmark-stress.yaml"
SWEEP_BENCH="${WS_DIR}/benchmarks/ujis-benchmark-sweep.yaml"

# Caliper flags
CALIPER_FLAGS=(--caliper-workspace "${WS_DIR}"
               --caliper-networkconfig "${NETWORK_CFG}"
               --caliper-flow-only-test)
#####################################################

log() { printf "[%(%Y-%m-%d %H:%M:%S)T] %s\n" -1 "$*"; }

preflight() {
  mkdir -p "${RESULTS_DIR}"

  [[ -x "$(command -v npx)" ]] || { echo "npx not found in PATH"; exit 1; }
  [[ -f "${NETWORK_CFG}" ]] || { echo "Network config not found: ${NETWORK_CFG}"; exit 1; }

  for f in "${BASELINE_BENCH}" "${STRESS_BENCH}" "${SWEEP_BENCH}"; do
    [[ -f "${f}" ]] || { echo "Benchmark file not found: ${f}"; exit 1; }
  done

  # Helpful hint if Docker monitor won't show up:
  log "Ensure Docker is running and Caliper has access to the Docker socket."
}

run_once() {
  local profile_yaml="$2"
  local profile_name="$1"

  log "Starting Caliper run: ${profile_name}"
  npx caliper launch manager \
    "${CALIPER_FLAGS[@]}" \
    --caliper-benchconfig "${profile_yaml}"

  # Move report.html to results/ with timestamp and profile label
  if [[ -f "${WS_DIR}/report.html" ]]; then
    local ts; ts="$(date +%Y%m%d-%H%M%S)"
    local out="${RESULTS_DIR}/report-${profile_name}-${ts}.html"
    mv "${WS_DIR}/report.html" "${out}"
    log "Report saved: ${out}"
  else
    log "WARNING: report.html not found after run: ${profile_name}"
  fi
}

run_profile() {
  local profile_name="$1"
  local profile_yaml="$2"
  local reps="$3"
  local cooldown_between="${4}"

  for ((i=1; i<=reps; i++)); do
    log "=== ${profile_name}: run ${i}/${reps} ==="
    run_once "${profile_name}-run${i}" "${profile_yaml}"
    if (( i < reps )); then
      log "Cooling down ${cooldown_between}s before next run of ${profile_name}..."
      sleep "${cooldown_between}"
    fi
  done
}

main() {
  preflight

  # Baseline
  run_profile "baseline" "${BASELINE_BENCH}" "${REPS_BASELINE}" "${COOLDOWN_BETWEEN_RUNS}"
  log "Cooling down ${COOLDOWN_AFTER_PROFILE}s before next profile..."
  sleep "${COOLDOWN_AFTER_PROFILE}"

  # Stress
  run_profile "stress" "${STRESS_BENCH}" "${REPS_STRESS}" "${COOLDOWN_BETWEEN_RUNS}"
  log "Cooling down ${COOLDOWN_AFTER_STRESS}s after stress profile..."
  sleep "${COOLDOWN_AFTER_STRESS}"

  # Sweep
  run_profile "sweep" "${SWEEP_BENCH}" "${REPS_SWEEP}" "${COOLDOWN_BETWEEN_RUNS}"

  log "All profiles completed. Reports are in: ${RESULTS_DIR}"
}

main "$@"
