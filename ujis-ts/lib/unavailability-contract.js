'use strict';

const { Contract } = require('fabric-contract-api');
const { version } = require('../package.json');
const crypto = require('crypto');

// Four funtions:
// 1) RecordUnavailability(system, location, startTime, endTime) -> record
// 2) GetAllUnavailabilities(system, location, date_time) -> [records]
// 3) DetailUnavailability(system, location, startTime) -> detailed record + verification hash
// 4) CheckUnavailability(system, location, startTime, expectedHash) -> diagnostic object

class UnavailabilityContract extends Contract {
  constructor() { super('org.example.unavailability'); }

  async GetVersion(ctx) 
  {
    return version;
  }

// Creates a new unavailability record in the chain state.
// ID schema: `${system}:${location}:${startTime}` — must be stable and unique across the ledger.
// Assumptions: `startTime` and `endTime` are ISO-8601 UTC strings (recommended for consistency).
// Behaviour: fails fast if a record with the same ID already exists (prevents accidental overwrites).
// Note: no validation of date formats or interval ordering is performed here by design.

async RecordUnavailability(ctx, system, location, startTime, endTime)
{
  // Compose a deterministic composite key. Using startTime disambiguates multiple incidents
  // for the same system/location on different timestamps.
  const id = `${system}:${location}:${startTime}`;

  // Guard against duplicate writes: ensures idempotency and preserves existing state.
  const exists = await this.AssetExists(ctx, id);
  if (exists) throw new Error(`Unavailability record ${id} already exists`);

  // Minimal persisted schema. Keep property names stable as other functions (hashing/verification)
  // rely on them. Store raw strings; normalisation to ISO should happen before calling this method.
  const record = {
    ID: id,
    System: system,
    Location: location,
    StartTime: startTime,
    EndTime: endTime,
  };

  // Persist as UTF-8 JSON buffer, as required by the Fabric shim.
  await ctx.stub.putState(id, Buffer.from(JSON.stringify(record)));

  // Return the stored object for immediate client confirmation.
  return record;
}

// Returns unavailability records filtered by optional criteria:
// - system: exact match on System
// - location: exact match on Location
// - date_time: instant or interval; records must intersect the time constraint
// Implementation notes:
// * Uses a prefix-constrained range scan over keys shaped as `system:location:startTime`.
// * Iterator uses the `next()` pattern for maximum shim compatibility.
// * Time comparison relies on Date.parse(); store ISO-8601 UTC to avoid locale drift.
async GetAllUnavailabilities(ctx, system, location, date_time) {
  const sys = this._norm(system);
  const loc = this._norm(location);
  const tf  = this._parseTimeFilter(date_time);

  // Build a tight start/end range based on available prefix (system and/or location).
  const { startKey, endKey } = this._makeRange(sys, loc);
  const iterator = await ctx.stub.getStateByRange(startKey, endKey);

  const out = [];
  try {
    while (true) {
      const res = await iterator.next();
      if (res.done) break;

      // Value is a Buffer; decode to UTF-8
      const buf = res.value.value;
      if (!buf) continue;

      let rec;
      try { rec = JSON.parse(buf.toString('utf8')); } catch { continue; }

      // Apply in-memory filters for exact matches and time intersection
      if (sys && rec.System !== sys) continue;
      if (loc && rec.Location !== loc) continue;
      if (tf.active && !this._matchesTime(rec.StartTime, rec.EndTime, tf)) continue;

      out.push(rec);
    }
  } finally {
    // Always release the iterator to avoid resource leaks in the peer
    await iterator.close();
  }
  return out;
}

// Normalises optional parameters: trims, converts "null"/"undefined" to null
_norm(v) {
  if (v === undefined || v === null) return null;
  const t = String(v).trim();
  if (!t || t.toLowerCase() === 'null' || t.toLowerCase() === 'undefined') return null;
  return t;
}

// Builds an inclusive range over lexicographically ordered keys.
// Keys are formatted as: system:location:startTime
// The sentinel '~' sorts after typical alphanumerics in ASCII, acting as an upper bound.
_makeRange(system, location) {
  if (system && location) {
    const p = `${system}:${location}:`;
    return { startKey: p, endKey: `${p}~` };
  }
  if (system) {
    const p = `${system}:`;
    return { startKey: p, endKey: `${p}~` };
  }
  return { startKey: '', endKey: '' }; // full scan when no prefix is available
}

// Parses date_time into either a point-in-time ('at') or an interval ('from'/'to').
// Accepts JSON ({"from":"...","to":"..."} or {"at":"..."}), "FROM|TO", "FROM,TO", or a single instant.
// Returns an object with millisecond values and an `active` flag.
_parseTimeFilter(date_time) {
  const out = { active: false, at: null, from: null, to: null };
  if (date_time === undefined || date_time === null) return out;

  const s = String(date_time).trim();
  if (!s || s.toLowerCase() === 'null' || s.toLowerCase() === 'undefined') return out;

  let at = null, from = null, to = null;

  try {
    const obj = JSON.parse(s);
    from = obj.from ?? obj.start ?? obj.startTime ?? null;
    to   = obj.to   ?? obj.end   ?? obj.endTime   ?? null;
    at   = obj.at   ?? obj.instant ?? null;
  } catch {
    // Simple textual formats: "FROM|TO" or "FROM,TO" or a single "AT"
    if (s.includes('|') || s.includes(',')) {
      const [a, b] = s.split(/[|,]/).map(x => x.trim());
      from = a || null; to = b || null;
    } else {
      at = s;
    }
  }

  const toMs = v => (v ? Date.parse(v) : null);
  out.at   = toMs(at);
  out.from = toMs(from);
  out.to   = toMs(to);
  out.active = (out.at !== null) || (out.from !== null) || (out.to !== null);
  return out;
}

// Returns true if record interval [startTime, endTime] intersects the filter:
// - Point-in-time: start <= at <= end
// - Interval: [start, end] ∩ [from, to] ≠ ∅ (open-ended bounds allowed)
_matchesTime(startTime, endTime, tf) {
  const s = Date.parse(startTime);
  const e = Date.parse(endTime);
  if (Number.isNaN(s) || Number.isNaN(e)) return false;

  if (tf.at !== null) return s <= tf.at && e >= tf.at;

  const from = (tf.from !== null) ? tf.from : Number.NEGATIVE_INFINITY;
  const to   = (tf.to   !== null) ? tf.to   : Number.POSITIVE_INFINITY;
  return s <= to && e >= from; // interval intersection
}


// Returns full details for a given unavailability record, including a verifiable hash.
// - duration: in whole minutes (rounded up), never negative
// - blockchainTimestamp: timestamp of the first creation transaction for this key (stable for hashing)
async DetailUnavailability(ctx, system, location, startTime) {
  const id = `${system}:${location}:${startTime}`;

  // 1) Read current state
  const bytes = await ctx.stub.getState(id);
  if (!bytes || bytes.length === 0) {
    throw new Error(`Unavailability ${id} not found`);
  }

  let rec;
  try {
    rec = JSON.parse(bytes.toString('utf8'));
  } catch {
    throw new Error(`Invalid JSON stored for ${id}`);
  }

  // 2) Normalise stored date strings to ISO-8601 (UTC)
  const stIso = this._toIso(rec.StartTime);
  const enIso = rec.EndTime ? this._toIso(rec.EndTime) : null;

  // 3) Compute duration in minutes:
  //    - If endTime is present, calculate ceil((end - start)/60s), minimum 0.
  //    - If endTime is missing, duration is null.
  let duration = null;
  if (enIso) {
    const deltaMs = Date.parse(enIso) - Date.parse(stIso);
    const minutes = deltaMs > 0 ? Math.ceil(deltaMs / 60000) : 0;
    duration = minutes;
  }

  // 4) Obtain stable blockchain timestamp (creation time from key history)
  const creationIso = await this._getCreationTimestampIso(ctx, id);

  // 5) Build response object in a canonical field order
  const response = {
    System: rec.System,
    Location: rec.Location,
    startTime: stIso,
    endTime: enIso,
    duration,                    // minutes; null if endTime is absent
    blockchainTimestamp: creationIso
  };

  // 6) Produce a stable verification hash across re-queries:
  //    - Join fields in a fixed order with a fixed delimiter
  //    - Any change in any returned field will change the hash
  const canonical = [
    response.System ?? '',
    response.Location ?? '',
    response.startTime ?? '',
    response.endTime ?? '',
    String(response.duration ?? ''),
    response.blockchainTimestamp ?? ''
  ].join('|');

  const verificationHash = crypto
    .createHash('sha256')
    .update(canonical, 'utf8')
    .digest('hex');

  response.verificationHash = verificationHash;
  return response;
}

// Converts an input date/time to ISO-8601 (UTC). Throws if invalid.
// Accepts any Date.parse()-compatible string (recommend using ISO when storing).
_toIso(v) {
  const ms = Date.parse(String(v));
  if (Number.isNaN(ms)) throw new Error(`Invalid date: ${v}`);
  return new Date(ms).toISOString();
}

// Scans the per-key history and returns the earliest (creation) timestamp as ISO-8601.
// Uses the iterator .next() pattern for maximum compatibility.
async _getCreationTimestampIso(ctx, id) {
  const it = await ctx.stub.getHistoryForKey(id);
  let earliest = null; // epoch millis
  try {
    while (true) {
      const r = await it.next();
      if (r.done) break;
      if (!r.value || r.value.is_delete) continue;

      // r.value.timestamp is a protobuf Timestamp {seconds, nanos}
      const ms = this._tsProtoToMillis(r.value.timestamp);
      if (earliest === null || ms < earliest) earliest = ms;
    }
  } finally {
    await it.close();
  }
  return (earliest === null) ? null : new Date(earliest).toISOString();
}

// Converts protobuf Timestamp {seconds, nanos} to epoch millis.
// Handles both plain numbers and Long objects commonly used by Fabric.
_tsProtoToMillis(ts) {
  if (!ts) return 0;
  let secs;
  if (typeof ts.seconds === 'number') {
    secs = ts.seconds;
  } else if (ts.seconds && typeof ts.seconds.low === 'number') {
    // seconds may be a Long; compute low + high*2^32
    secs = ts.seconds.low + (ts.seconds.high ? ts.seconds.high * 4294967296 : 0);
  } else {
    secs = 0;
  }
  const nanos = ts.nanos || 0;
  return secs * 1000 + Math.floor(nanos / 1e6);
}

  async AssetExists(ctx, id) {
        const bytes = await ctx.stub.getState(id);
        return bytes && bytes.length > 0;
    }

// Verifies whether there exists at least one unavailability record matching the filters
// (system, location, startTime) whose computed verification hash equals `expectedHash`.
// Returns a diagnostic object instead of a bare boolean, so the caller can present
// meaningful evidence in a certificate validation workflow.
async CheckUnavailability(ctx, system, location, startTime, expectedHash) {
  // Normalise and validate input hash
  const provided = (expectedHash || '').toString().trim().toLowerCase();
  if (!provided) {
    throw new Error('expectedHash is required to verify integrity');
  }

  // Reuse your filtered search (prefix + time filter)
  const candidates = await this.GetAllUnavailabilities(ctx, system, location, startTime);

  if (!Array.isArray(candidates) || candidates.length === 0) {
    return { exists: false, integrity: false, matchCount: 0 };
  }

  // Check each candidate by recomputing the same canonical hash used in DetailUnavailability
  for (const rec of candidates) {
    // Defensive parsing: expect the persisted shape created by RecordUnavailability
    const sys = rec.System;
    const loc = rec.Location;
    const stIso = this._toIso(rec.StartTime);
    const enIso = rec.EndTime ? this._toIso(rec.EndTime) : null;

    // Duration in whole minutes, rounded up, never negative
    let duration = null;
    if (enIso) {
      const deltaMs = Date.parse(enIso) - Date.parse(stIso);
      duration = deltaMs > 0 ? Math.ceil(deltaMs / 60000) : 0;
    }

    // Stable blockchain timestamp: creation time of this key (first write)
    const keyId = rec.ID || `${sys}:${loc}:${rec.StartTime}`;
    const creationIso = await this._getCreationTimestampIso(ctx, keyId);

    // Recompute verification hash using fixed field order and delimiter
    const canonical = [
      sys ?? '',
      loc ?? '',
      stIso ?? '',
      enIso ?? '',
      String(duration ?? ''),
      creationIso ?? ''
    ].join('|');

    const computed = crypto.createHash('sha256').update(canonical, 'utf8').digest('hex');

    if (computed.toLowerCase() === provided) {
      // Positive match: integrity confirmed
      return {
        exists: true,
        integrity: true,
        matchCount: candidates.length,
        id: keyId,
        System: sys,
        Location: loc,
        startTime: stIso,
        endTime: enIso,
        duration,                    // minutes
        blockchainTimestamp: creationIso,
        verificationHash: computed   // echo back the verified hash
      };
    }
  }

  // No candidate matched the provided hash
  return {
    exists: true,           // records exist for the given filters
    integrity: false,       // but none matches the expectedHash
    matchCount: candidates.length
  };
}

}
module.exports = UnavailabilityContract;
