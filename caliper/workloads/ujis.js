'use strict';
/**
 * Caliper workload module for the "Unavailability" smart contract.
 * Namespace: org.example.unavailability
 *
 * Operations covered:
 *  - RecordUnavailability(system, location, startTime, endTime)        [write]
 *  - GetAllUnavailabilities(system, location, date_time)               [read/list]
 *  - DetailUnavailability(system, location, startTime)                 [read/detail]
 *  - CheckUnavailability(system, location, startTime, verificationHash)[read/check]
 *
 * UK English notes:
 *  - A per-worker pool of keys (system, location, startTime) is maintained during write rounds to
 *    guarantee valid targets for subsequent detail/check rounds.
 *  - A hash cache (keyed by system|location|startTime) is filled from detail responses so that the
 *    check round can run without calling detail first (isolating the check's cost profile).
 *  - startTime/endTime are ISO-8601 UTC strings with millisecond precision for uniqueness.
 *  - The contract namespace is honoured by prefixing function names as "<ns>:<fn>".
 */

const { WorkloadModuleBase } = require('@hyperledger/caliper-core');

class UjisWorkload extends WorkloadModuleBase {
  constructor() {
    super();
    this.system = null;
    this.location = null;
    this.ns = 'org.example.unavailability';
    this.dateFilterStr = null;

    // Recently created entries to support detail/check rounds
    this.pool = [];                // [{ system, location, startTime }]
    this.maxPool = 4000;

    // Hash cache populated from DetailUnavailability responses
    this.detailCache = new Map();  // key => verificationHash
  }

  _ns(fn) {
    return this.ns ? `${this.ns}:${fn}` : fn;
  }

  _isoPlus(baseMs, seconds) {
    const ms = baseMs + (seconds || 0) * 1000;
    return new Date(ms).toISOString();
  }

  _pushToPool(entry) {
    this.pool.push(entry);
    if (this.pool.length > this.maxPool) this.pool.shift();
  }

  _cacheKey(s, l, t) {
    return `${s}|${l}|${t}`;
  }

  /**
   * Attempt to extract a payload Buffer/String/JSON from a Caliper TxStatus result.
   */
  _extractPayload(result) {
    // Normalise array vs single
    let r = Array.isArray(result) ? result[0] : result;

    // Caliper TxStatus API variations
    let payload = null;
    try {
      if (r && typeof r.GetResult === 'function') {
        payload = r.GetResult();
      } else if (r && typeof r.result !== 'undefined') {
        payload = r.result;
      } else if (r && r.payload) {
        payload = r.payload;
      } else {
        payload = r;
      }
    } catch (e) {
      payload = r;
    }

    // Buffer -> string
    if (payload && Buffer.isBuffer(payload)) {
      try { payload = payload.toString('utf8'); } catch (_) { /* noop */ }
    }

    return payload;
  }

  /**
   * Recursively search for a 64-hex digest within an object/string.
   * Returns the first matching candidate, or null.
   */
  _extractHashFromDetailPayload(payload) {
    const HEX64_RE = /([A-Fa-f0-9]{64})/;

    // If already a string, try regex directly
    if (typeof payload === 'string') {
      const m = payload.match(HEX64_RE);
      return m ? m[1] : null;
    }

    // Try parse JSON if it is a stringified object
    if (typeof payload === 'string') {
      try {
        const obj = JSON.parse(payload);
        return this._extractHashFromDetailPayload(obj);
      } catch (_) { /* not JSON */ }
    }

    // Walk object/array
    const visit = (node, depth = 0) => {
      if (depth > 6 || node == null) return null;

      if (typeof node === 'string') {
        const m = node.match(HEX64_RE);
        if (m) return m[1];
      } else if (typeof node === 'object') {
        // Prefer fields named with 'hash' first
        const keys = Object.keys(node);
        const hashLike = keys.filter(k => /hash|digest|sha/i.test(k)).concat(keys);
        for (const k of hashLike) {
          if (!(k in node)) continue;
          const v = node[k];
          const found = visit(v, depth + 1);
          if (found) return found;
        }
      } else if (Array.isArray(node)) {
        for (const v of node) {
          const found = visit(v, depth + 1);
          if (found) return found;
        }
      }
      return null;
    };

    return visit(payload, 0);
  }

  async initializeWorkloadModule(workerIndex, totalWorkers, roundIndex, roundArguments, sutAdapter, sutContext) {
    await super.initializeWorkloadModule(workerIndex, totalWorkers, roundIndex, roundArguments, sutAdapter, sutContext);

    const systemBase = roundArguments.systemBase || 'UJIS';
    const locationBase = roundArguments.locationBase || 'PT.GMR';
    this.system = `${systemBase}-${this.workerIndex}`;
    this.location = `${locationBase}`;
    this.ns = roundArguments.namespace || 'org.example.unavailability';

    // Wide default date window unless supplied by YAML
    if (roundArguments.dateFilter) {
      this.dateFilterStr = String(roundArguments.dateFilter);
    } else {
      const now = Date.now();
      const from = this._isoPlus(now, -7 * 24 * 3600);
      const to = this._isoPlus(now, 7 * 24 * 3600);
      this.dateFilterStr = JSON.stringify({ from, to });
    }

    // Optional self-seeding for standalone read/detail/check rounds
    const seedCount = Number(roundArguments.seedCount || 0);
    if (seedCount > 0) {
      const invoker = roundArguments.invoker || 'User1';
      const durationSec = Number(roundArguments.durationSec || 60);
      const contractId = roundArguments.contractId || 'basic';
      for (let i = 0; i < seedCount; i++) {
        const now = Date.now();
        const startTime = this._isoPlus(now + i, 0);     // ensure uniqueness by +i
        const endTime = this._isoPlus(now + i, durationSec);
        const req = {
          contractId,
          contractFunction: this._ns('RecordUnavailability'),
          contractArguments: [this.system, this.location, startTime, endTime],
          invokerIdentity: invoker,
          readOnly: false
        };
        await this.sutAdapter.sendRequests(req);
        this._pushToPool({ system: this.system, location: this.location, startTime });
      }
    }
  }

  async submitTransaction() {
    const mode = this.roundArguments.mode || 'write';
    const invoker = this.roundArguments.invoker || 'User1';
    const contractId = this.roundArguments.contractId || 'basic';

    if (mode === 'read') {
      // GetAllUnavailabilities(system, location, date_time)
      const args = [this.system, this.location, this.dateFilterStr];
      const req = {
        contractId,
        contractFunction: this._ns('GetAllUnavailabilities'),
        contractArguments: args,
        invokerIdentity: invoker,
        readOnly: true
      };
      return this.sutAdapter.sendRequests(req);
    }

    if (mode === 'detail') {
      // Ensure at least one valid target
      if (this.pool.length === 0) {
        const durationSec = Number(this.roundArguments.durationSec || 60);
        const now = Date.now();
        const startTime = this._isoPlus(now, 0);
        const endTime = this._isoPlus(now, durationSec);
        const seedReq = {
          contractId,
          contractFunction: this._ns('RecordUnavailability'),
          contractArguments: [this.system, this.location, startTime, endTime],
          invokerIdentity: invoker,
          readOnly: false
        };
        await this.sutAdapter.sendRequests(seedReq);
        this._pushToPool({ system: this.system, location: this.location, startTime });
      }

      const target = this.pool[Math.floor(Math.random() * this.pool.length)];
      const req = {
        contractId,
        contractFunction: this._ns('DetailUnavailability'),
        contractArguments: [target.system, target.location, target.startTime],
        invokerIdentity: invoker,
        readOnly: true
      };
      const res = await this.sutAdapter.sendRequests(req);

      // Try to extract and cache a verification hash for later check rounds
      try {
        const payload = this._extractPayload(res);
        const hash = this._extractHashFromDetailPayload(payload);
        if (hash) {
          const k = this._cacheKey(target.system, target.location, target.startTime);
          this.detailCache.set(k, hash);
        }
      } catch (_) { /* best-effort only */ }

      return res;
    }

    if (mode === 'check') {
      // Prefer entries with known hash
      let entryKey = null;
      if (this.detailCache.size > 0) {
        // Pick a random cached key
        const keys = Array.from(this.detailCache.keys());
        entryKey = keys[Math.floor(Math.random() * keys.length)];
      } else if (this.pool.length > 0) {
        // If no cached hashes yet, detail one to populate cache (one-off)
        const pick = this.pool[Math.floor(Math.random() * this.pool.length)];
        const detailReq = {
          contractId,
          contractFunction: this._ns('DetailUnavailability'),
          contractArguments: [pick.system, pick.location, pick.startTime],
          invokerIdentity: invoker,
          readOnly: true
        };
        const detailRes = await this.sutAdapter.sendRequests(detailReq);
        const payload = this._extractPayload(detailRes);
        const hash = this._extractHashFromDetailPayload(payload);
        if (hash) {
          this.detailCache.set(this._cacheKey(pick.system, pick.location, pick.startTime), hash);
          entryKey = this._cacheKey(pick.system, pick.location, pick.startTime);
        }
      }

      // If still nothing, seed one record and detail it
      if (!entryKey) {
        const durationSec = Number(this.roundArguments.durationSec || 60);
        const now = Date.now();
        const startTime = this._isoPlus(now, 0);
        const endTime = this._isoPlus(now, durationSec);
        // seed write
        const seedReq = {
          contractId,
          contractFunction: this._ns('RecordUnavailability'),
          contractArguments: [this.system, this.location, startTime, endTime],
          invokerIdentity: invoker,
          readOnly: false
        };
        await this.sutAdapter.sendRequests(seedReq);
        this._pushToPool({ system: this.system, location: this.location, startTime });

        // detail to obtain hash
        const detailReq = {
          contractId,
          contractFunction: this._ns('DetailUnavailability'),
          contractArguments: [this.system, this.location, startTime],
          invokerIdentity: invoker,
          readOnly: true
        };
        const detailRes = await this.sutAdapter.sendRequests(detailReq);
        const payload = this._extractPayload(detailRes);
        const hash = this._extractHashFromDetailPayload(payload);
        if (hash) {
          entryKey = this._cacheKey(this.system, this.location, startTime);
          this.detailCache.set(entryKey, hash);
        }
      }

      // Finally, perform the check call if we have a hash
      if (entryKey) {
        const [s, l, t] = entryKey.split('|');
        const hash = this.detailCache.get(entryKey);
        const req = {
          contractId,
          contractFunction: this._ns('CheckUnavailability'),
          contractArguments: [s, l, t, hash],
          invokerIdentity: invoker,
          readOnly: true
        };
        return this.sutAdapter.sendRequests(req);
      } else {
        // As a fallback, perform a harmless list read so the round still contributes a datapoint
        const args = [this.system, this.location, this.dateFilterStr];
        const req = {
          contractId,
          contractFunction: this._ns('GetAllUnavailabilities'),
          contractArguments: args,
          invokerIdentity: invoker,
          readOnly: true
        };
        return this.sutAdapter.sendRequests(req);
      }
    }

    // Default mode === 'write'
    const now = Date.now();
    const durationSec = Number(this.roundArguments.durationSec || 60);
    const startTime = this._isoPlus(now, 0);
    const endTime = this._isoPlus(now, durationSec);
    const args = [this.system, this.location, startTime, endTime];
    const req = {
      contractId,
      contractFunction: this._ns('RecordUnavailability'),
      contractArguments: args,
      invokerIdentity: invoker,
      readOnly: false
    };
    const res = await this.sutAdapter.sendRequests(req);
    this._pushToPool({ system: this.system, location: this.location, startTime });
    return res;
  }

  async cleanupWorkloadModule() {
    // No clean-up: data is deliberately retained to support read/detail/check rounds.
  }
}

function createWorkloadModule() {
  return new UjisWorkload();
}

module.exports.createWorkloadModule = createWorkloadModule;
