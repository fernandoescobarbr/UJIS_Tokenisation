<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

/**
 * FabricService
 *
 * Thin wrapper around the Hyperledger Fabric `peer` CLI.
 * Responsibilities:
 *  - Source Fabric environment (envVar.sh) and select the organisation (via $envFn).
 *  - Execute `peer chaincode query` and `peer chaincode invoke`.
 *  - Build JSON payloads safely (json_encode) to avoid shell injection.
 *  - Normalise and decode CLI output into JSON when possible.
 *
 * Configuration: see config('fabric') for channel, cc_name, paths and prefixes.
 */
class FabricService
{
    private string $channel;
    private string $ccName;
    private string $envScript;
    private string $envFn;
    private string $queryPrefix;
    private string $invokePrefix;
    private bool $waitForEvent;
    private int $waitTimeout;
    private bool $jsonAssoc;
    private int $jsonDepth;
    private string $workdir;
    private string $binPath;
    private string $cfgPath;

    public function __construct()
    {
        $cfg = config('fabric');

        $this->channel      = $cfg['channel'];
        $this->ccName       = $cfg['cc_name'];
        $this->envScript    = $cfg['env_script'];
        $this->envFn        = $cfg['env_fn'];
        $this->queryPrefix  = $cfg['query_prefix'];
        $this->invokePrefix = $cfg['invoke_prefix'];
        $this->waitForEvent = (bool) $cfg['wait_for_event'];
        $this->waitTimeout  = (int) $cfg['wait_timeout'];
        $this->jsonAssoc    = (bool) $cfg['json_assoc'];
        $this->jsonDepth    = (int) $cfg['json_depth'];
        $this->workdir      = $cfg['workdir'];
        $this->binPath      = $cfg['bin_path'];
        $this->cfgPath      = $cfg['cfg_path'];
    }

    /**
     * Evaluate a transaction (read-only path).
     *
     * @param  string $fn   Chaincode function name.
     * @param  array  $args Positional arguments (strings).
     * @return mixed        Decoded JSON if possible; otherwise raw CLI output.
     */
    public function query(string $fn, array $args = []): mixed
    {
        $payload = $this->buildPayload($fn, $args);

        $cmd = sprintf(
            '%s -C %s -n %s -c %s',
            $this->queryPrefix,
            escapeshellarg($this->channel),
            escapeshellarg($this->ccName),
            escapeshellarg($payload)
        );

        $out = $this->run($cmd);
        return $this->decodeJsonOrRaw($out);
    }

    /**
     * Submit a transaction (write path).
     * Optionally adds --waitForEvent for deterministic UX.
     *
     * @param  string $fn
     * @param  array  $args
     * @return mixed  Decoded JSON if possible; otherwise raw CLI output.
     */
    public function invoke(string $fn, array $args = []): mixed
    {
        $payload = $this->buildPayload($fn, $args);

        $suffix = '';
        if ($this->waitForEvent) {
            $suffix = sprintf(' --waitForEvent --waitForEventTimeout %d', (int) $this->waitTimeout);
        }

        $cmd = sprintf(
            '%s -C %s -n %s -c %s%s',
            $this->invokePrefix,
            escapeshellarg($this->channel),
            escapeshellarg($this->ccName),
            escapeshellarg($payload),
            $suffix
        );

        $out = $this->run($cmd);
        return $this->decodeJsonOrRaw($out);
    }

    /**
     * Build the `-c` JSON payload: {"function":"Fn","Args":[...]}.
     * Uses json_encode to ensure correct escaping.
     */
    private function buildPayload(string $fn, array $args): string
    {
        return json_encode([
            'function' => $fn,
            'Args'     => array_map(fn ($x) => (string) $x, $args),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Execute a shell command under bash, ensuring:
     *  - We run inside test-network workdir (so scripts/utils.sh resolve).
     *  - PATH includes fabric-samples/bin.
     *  - FABRIC_CFG_PATH points to fabric-samples/config.
     *  - envVar.sh is sourced and $envFn (e.g., "setGlobals 1") is invoked quietly.
     *
     * @throws \RuntimeException on non-zero exit status.
     */
    private function run(string $cmd): string
    {
        $compound = sprintf(
            // enter test-network and prepare PATH/CFG
            'cd %s && export PATH="$PATH":%s && export FABRIC_CFG_PATH=%s ' .
            // source env and silence "infoln"; then call the env function without stdout
            '&& source %s >/dev/null 2>&1; { %s; } 1>/dev/null ' .
            // finally execute the peer command
            '&& %s',
            escapeshellarg($this->workdir),
            escapeshellarg($this->binPath),
            escapeshellarg($this->cfgPath),
            escapeshellarg($this->envScript),
            $this->envFn, // e.g., "setGlobals 1"
            $cmd
        );

        $process = new \Symfony\Component\Process\Process(['bash', '-lc', $compound]);
        $process->setTimeout(max(60, $this->waitTimeout + 30));
        $process->run();

        if (!$process->isSuccessful()) {
            \Log::error('Fabric CLI error', ['stderr' => $process->getErrorOutput()]);
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Fabric command failed');
        }

        return trim($process->getOutput());
    }

    /**
     * Attempt to decode JSON; if not JSON, return raw string.
     * Also strips ANSI escape sequences that the peer CLI may emit.
     */
    private function decodeJsonOrRaw(string $out): mixed
    {
        $out = trim($out);

        // 1) Strip ANSI escape codes (colours, etc.).
        $out = preg_replace('/\x1B\[[0-9;]*[ -\/]*[@-~]/', '', $out);

        // 2) Try direct JSON decode.
        $decoded = json_decode($out, true, 512);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // 3) Extract the first plausible JSON block (array/object) from noisy output.
        $candidate = $this->extractFirstJsonBlock($out);
        if ($candidate !== null) {
            $decoded2 = json_decode($candidate, true, 512);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded2;
            }
        }

        // 4) Last resort: return raw output.
        return $out;
    }

    /**
     * Find the first JSON block (array "[...]" or object "{...}") within a noisy string.
     *
     * @return string|null The JSON candidate or null when none is found.
     */
    private function extractFirstJsonBlock(string $s): ?string
    {
        $posArr = strpos($s, '[');
        $posObj = strpos($s, '{');

        if ($posArr === false && $posObj === false) {
            return null;
        }

        // Choose whichever appears first.
        $start = ($posArr !== false && ($posObj === false || $posArr < $posObj)) ? $posArr : $posObj;
        $open  = $s[$start];

        // Attempt to find the corresponding closing bracket/brace.
        $end = $open === '[' ? strrpos($s, ']') : strrpos($s, '}');

        if ($end === false || $end <= $start) {
            return null;
        }

        $json = substr($s, $start, $end - $start + 1);
        return trim($json);
    }
}
