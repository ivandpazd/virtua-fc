#!/usr/bin/env node
// Apply hand-rated overall_score / potential onto WC2026 squad files by linking
// per-nation rating files to players *by name*, scoped to each nation.
//
// The WC2026 importer (GamePlayerTemplateService::prepareTemplateRow) already
// honors `overall_score` and `potential` when present on a player record. This
// script's only job is to get those two fields onto the right player object in
// data/2025/WC2026/teams/<id>.json, with zero mislinks.
//
// Input: one JSON file per nation under data/2025/WC2026/ratings/ (override with
// a positional arg). Each file is an array of
// { full_name, name, overall_score, potential } (full_name optional).
// The target team is resolved from the file name (preferred: the nation's
// Transfermarkt id, e.g. 3375.json), or from an inner `transfermarktId` / `team`
// / `nation` field, or by matching the file's base name against a nation name.
//
// Linking is by normalized name (accent- and case-insensitive), restricted to
// the resolved nation's squad, in two passes:
//   1. exact match — squad name equals the entry's `name` or `full_name`.
//   2. partial match (over players not claimed in pass 1) — the squad name's
//      tokens are a subset (either direction) of the entry's `name` or
//      `full_name` tokens. This links e.g. squad "Gonçalo Inácio" to the entry
//      full_name "Gonçalo Bernardo Inácio".
// A partial match that hits more than one squad player is ambiguous and aborts
// (never a silent guess). Rating values are validated with the SAME rules
// the importer applies (resolveExplicitAbility / resolveExplicitPotential):
//   - overall_score: integer in 1..99
//   - potential:     integer in 1..99 AND >= overall_score
//
// Behaviour: report-and-abort. If ANY entry is unmatched, ambiguous, or invalid
// (across every file), nothing is written and the process exits non-zero. Files
// are only rewritten when the whole run is clean. Pass --dry-run to always
// report without writing.
//
// Output is serialized exactly like the existing team files: keys sorted
// alphabetically, 2-space indent, no trailing newline (see stringifySorted).

import { readFileSync, writeFileSync, readdirSync, existsSync } from "node:fs";
import { dirname, join, resolve, basename } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const TEAMS_DIR = join(ROOT, "data", "2025", "WC2026", "teams");

const argv = process.argv.slice(2);
const DRY_RUN = argv.includes("--dry-run");
const positional = argv.filter((a) => !a.startsWith("--"));
const RATINGS_DIR = positional[0]
  ? resolve(process.cwd(), positional[0])
  : join(ROOT, "data", "2025", "WC2026", "ratings");

// Serialization identical to scripts/merge-wc-squad.mjs so diffs stay minimal:
// alphabetical keys, 2-space indent, no trailing newline.
function stringifySorted(value, indent = 2, depth = 0) {
  const pad = " ".repeat(indent * depth);
  const innerPad = " ".repeat(indent * (depth + 1));
  if (value === null || typeof value !== "object") {
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) {
    if (value.length === 0) return "[]";
    const items = value.map(
      (v) => innerPad + stringifySorted(v, indent, depth + 1),
    );
    return "[\n" + items.join(",\n") + "\n" + pad + "]";
  }
  const keys = Object.keys(value).sort();
  if (keys.length === 0) return "{}";
  const items = keys.map(
    (k) =>
      innerPad +
      JSON.stringify(k) +
      ": " +
      stringifySorted(value[k], indent, depth + 1),
  );
  return "{\n" + items.join(",\n") + "\n" + pad + "}";
}

// lowercase, strip diacritics, collapse internal whitespace, trim.
function normalizeName(raw) {
  return String(raw)
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}

// Like normalizeName but KEEPS diacritics (NFC so composed/decomposed forms
// compare equal). Used only as a tiebreaker when accent-stripped names collide
// (e.g. Brazil's "Ederson" GK vs "Éderson" MF).
function normalizeKeepDiacritics(raw) {
  return String(raw).normalize("NFC").toLowerCase().replace(/\s+/g, " ").trim();
}

// Normalized token set, split on whitespace, hyphens and dots so romanized /
// reversed-order names line up: "Gonçalo Inácio" -> {goncalo, inacio};
// squad "Hyeon-woo Jo" and rating full_name "Hyeon Woo Jo" both -> {hyeon, woo, jo}
// (matching is order-insensitive, so Korean family-first order also resolves).
function tokenize(raw) {
  return new Set(normalizeName(raw).split(/[\s.\-]+/).filter(Boolean));
}

// true if every token of `a` is in `b` (a ⊆ b).
function isSubset(a, b) {
  for (const t of a) if (!b.has(t)) return false;
  return true;
}

// true if either token set fully contains the other (and neither is empty).
function tokensCompatible(a, b) {
  if (a.size === 0 || b.size === 0) return false;
  return isSubset(a, b) || isSubset(b, a);
}

// Mirror of GamePlayerTemplateService::resolveExplicitAbility — but here an
// invalid value is an ERROR (the importer silently drops it; we refuse to write
// junk). Returns { ok, value } so the caller can report the bad input.
function validateAbility(raw) {
  if (raw === null || raw === undefined || raw === "") {
    return { ok: false, reason: "missing" };
  }
  // Accept ints and integer-valued strings; reject anything else (1..99).
  const n = typeof raw === "number" ? raw : Number(raw);
  if (!Number.isInteger(n) || n < 1 || n > 99) {
    return { ok: false, reason: `not an integer in 1..99 (${JSON.stringify(raw)})` };
  }
  return { ok: true, value: n };
}

// Build id -> path and normalized team-name -> id, from the canonical team files
// (ignore any leftover -pre / -final partials).
function buildTeamIndex() {
  const byId = new Map();
  const byName = new Map();
  const ambiguousNames = new Set();
  for (const f of readdirSync(TEAMS_DIR)) {
    if (!f.endsWith(".json")) continue;
    if (f.endsWith("-pre.json") || f.endsWith("-final.json")) continue;
    const id = f.slice(0, -".json".length);
    const path = join(TEAMS_DIR, f);
    byId.set(id, path);
    const data = JSON.parse(readFileSync(path, "utf8"));
    const norm = normalizeName(data.name ?? "");
    if (norm) {
      if (byName.has(norm)) ambiguousNames.add(norm);
      byName.set(norm, id);
    }
  }
  for (const norm of ambiguousNames) byName.delete(norm);
  return { byId, byName };
}

// Resolve which team a ratings file targets.
function resolveTeamId(filePath, parsed, index) {
  const stem = basename(filePath, ".json");
  if (index.byId.has(stem)) return { id: stem };

  const inner =
    (parsed && !Array.isArray(parsed) && (parsed.transfermarktId ?? parsed.team ?? parsed.nation)) ||
    null;
  if (inner != null && index.byId.has(String(inner))) return { id: String(inner) };
  if (inner != null) {
    const byInnerName = index.byName.get(normalizeName(inner));
    if (byInnerName) return { id: byInnerName };
  }

  const byStemName = index.byName.get(normalizeName(stem));
  if (byStemName) return { id: byStemName };

  return {
    error: `cannot resolve target team (file "${basename(filePath)}" is not a known team id, and no matching transfermarktId/team/nation field or nation name)`,
  };
}

// A ratings file is either an array of entries, or an object with a `players`
// (or `ratings`) array. Returns the entry array.
function extractEntries(parsed) {
  if (Array.isArray(parsed)) return parsed;
  if (parsed && Array.isArray(parsed.players)) return parsed.players;
  if (parsed && Array.isArray(parsed.ratings)) return parsed.ratings;
  return null;
}

function main() {
  if (!existsSync(RATINGS_DIR)) {
    console.error(`Ratings directory not found: ${RATINGS_DIR}`);
    console.error(
      "Create it and drop one JSON file per nation (ideally named <transfermarktId>.json), " +
        'each an array of { "name", "overall_score", "potential" }.',
    );
    process.exit(1);
  }

  const ratingFiles = readdirSync(RATINGS_DIR)
    .filter((f) => f.endsWith(".json"))
    .sort();
  if (ratingFiles.length === 0) {
    console.error(`No .json rating files in ${RATINGS_DIR}`);
    process.exit(1);
  }

  const index = buildTeamIndex();

  const errors = []; // { file, msg }
  const reports = []; // { file, id, teamName, matched, total }
  const pendingWrites = new Map(); // id -> { path, teamData }

  for (const file of ratingFiles) {
    const filePath = join(RATINGS_DIR, file);
    let parsed;
    try {
      parsed = JSON.parse(readFileSync(filePath, "utf8"));
    } catch (e) {
      errors.push({ file, msg: `invalid JSON: ${e.message}` });
      continue;
    }

    const entries = extractEntries(parsed);
    if (!entries) {
      errors.push({ file, msg: "expected an array of { name, overall_score, potential }" });
      continue;
    }

    const resolved = resolveTeamId(filePath, parsed, index);
    if (resolved.error) {
      errors.push({ file, msg: resolved.error });
      continue;
    }
    const { id } = resolved;

    // Reuse an already-staged copy if two files target the same team.
    let staged = pendingWrites.get(id);
    if (!staged) {
      const path = index.byId.get(id);
      staged = { path, teamData: JSON.parse(readFileSync(path, "utf8")) };
    }
    const teamData = staged.teamData;
    const teamName = teamData.name ?? id;

    // Squad index over players[] ONLY (never club.name): normalized name + tokens.
    const squad = teamData.players.map((p, i) => ({
      i,
      norm: normalizeName(p.name ?? ""),
      normAccent: normalizeKeepDiacritics(p.name ?? ""),
      tokens: tokenize(p.name ?? ""),
    }));

    // Validate every entry up front; collect the valid ones with both name
    // candidates (`name` and `full_name`). Invalid values are errors — we refuse
    // to write junk the importer would silently drop.
    const valid = [];
    for (const entry of entries) {
      const nameStrings = [entry?.name, entry?.full_name, entry?.player, entry?.playerName]
        .filter((s) => s != null && String(s).trim() !== "");
      if (nameStrings.length === 0) {
        errors.push({ file, msg: `entry without a name: ${JSON.stringify(entry)}` });
        continue;
      }
      const label = String(entry?.full_name ?? entry?.name ?? nameStrings[0]);

      const ability = validateAbility(entry.overall_score);
      if (!ability.ok) {
        errors.push({ file, msg: `"${label}": overall_score ${ability.reason}` });
        continue;
      }
      const potential = validateAbility(entry.potential);
      if (!potential.ok) {
        errors.push({ file, msg: `"${label}": potential ${potential.reason}` });
        continue;
      }
      if (potential.value < ability.value) {
        errors.push({
          file,
          msg: `"${label}": potential ${potential.value} < overall_score ${ability.value}`,
        });
        continue;
      }

      valid.push({
        label,
        ability,
        potential,
        norms: nameStrings.map(normalizeName),
        accents: nameStrings.map(normalizeKeepDiacritics),
        tokenSets: nameStrings.map(tokenize),
      });
    }

    // Assign a rating to a squad player; flag a conflict if two entries target one.
    const claimedBy = new Map(); // playerIdx -> entry label
    const assign = (playerIdx, v) => {
      if (claimedBy.has(playerIdx)) {
        errors.push({
          file,
          msg: `two entries map to the same player "${teamData.players[playerIdx].name}" in ${teamName}: "${claimedBy.get(playerIdx)}" and "${v.label}"`,
        });
        return;
      }
      claimedBy.set(playerIdx, v.label);
      const player = teamData.players[playerIdx];
      player.overall_score = v.ability.value;
      player.potential = v.potential.value;
    };

    // Pass 1 — exact normalized match on either provided name.
    const deferred = [];
    for (const v of valid) {
      const exact = squad.filter((s) => v.norms.includes(s.norm));
      let uniq = [...new Set(exact.map((s) => s.i))];
      if (uniq.length > 1) {
        // Tiebreak: prefer candidates whose accented spelling matches the entry
        // exactly (e.g. entry "Ederson" -> GK "Ederson", not MF "Éderson").
        const narrowed = [...new Set(exact.filter((s) => v.accents.includes(s.normAccent)).map((s) => s.i))];
        if (narrowed.length === 1) uniq = narrowed;
      }
      if (uniq.length === 1) assign(uniq[0], v);
      else if (uniq.length > 1)
        errors.push({ file, msg: `ambiguous (exact) name "${v.label}" matches ${uniq.length} ${teamName} players` });
      else deferred.push(v);
    }

    // Pass 2 — partial token match against squad players not claimed in pass 1.
    for (const v of deferred) {
      const uniq = [
        ...new Set(
          squad
            .filter((s) => !claimedBy.has(s.i) && v.tokenSets.some((t) => tokensCompatible(s.tokens, t)))
            .map((s) => s.i),
        ),
      ];
      if (uniq.length === 1) assign(uniq[0], v);
      else if (uniq.length > 1)
        errors.push({
          file,
          msg: `ambiguous (partial) name "${v.label}" matches: ${uniq.map((i) => `"${teamData.players[i].name}"`).join(", ")}`,
        });
      else errors.push({ file, msg: `unmatched name "${v.label}" — no player in ${teamName} squad` });
    }

    pendingWrites.set(id, staged);
    reports.push({ file, id, teamName, matched: claimedBy.size, total: entries.length });
  }

  // Report
  console.log("Per-file match report:");
  for (const r of reports) {
    console.log(`  ${r.file} -> ${r.teamName} (${r.id}): ${r.matched}/${r.total} matched`);
  }

  if (errors.length > 0) {
    console.error(`\n${errors.length} problem(s) — nothing written:`);
    for (const e of errors) console.error(`  [${e.file}] ${e.msg}`);
    process.exit(1);
  }

  if (DRY_RUN) {
    console.log("\nDry run: all entries matched and valid. No files written.");
    return;
  }

  for (const [id, { path, teamData }] of pendingWrites) {
    writeFileSync(path, stringifySorted(teamData));
    console.log(`Wrote ${path}`);
  }
  console.log(`\nDone: updated ${pendingWrites.size} team file(s).`);
}

main();
