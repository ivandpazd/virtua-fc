#!/usr/bin/env node
// Merge <id>-pre.json + <id>-final.json -> <id>.json for every pair found in
// data/2025/WC2026/teams/.
//
// Rules:
//   - Player in -final (whether or not also in -pre): keep the -final version,
//     add "calledUp": true.
//   - Player only in -pre: keep as-is, add "calledUp": false.
//   - Matching key: "id".
//   - Player order: stable-sort by position bucket (GK -> DEF -> MID -> FWD).
//     Within each bucket, pre players come first (in pre order), then any
//     final-only players (in final order). Source files are already sorted by
//     bucket, so this only matters for final-only late call-ups.
//   - Output keys serialized alphabetically, 2-space indent, no trailing
//     newline (matches the existing format).

import { readFileSync, writeFileSync, readdirSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const TEAMS_DIR = resolve(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  "data",
  "2025",
  "WC2026",
  "teams",
);

const POSITION_BUCKET = {
  Goalkeeper: 0,
  "Centre-Back": 1,
  "Left-Back": 1,
  "Right-Back": 1,
  "Defensive Midfield": 2,
  "Central Midfield": 2,
  "Attacking Midfield": 2,
  "Left Winger": 3,
  "Right Winger": 3,
  "Second Striker": 3,
  "Centre-Forward": 3,
};

function bucketOf(position) {
  if (!(position in POSITION_BUCKET)) {
    throw new Error(`Unknown position: ${position}`);
  }
  return POSITION_BUCKET[position];
}

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

function mergeTeam(preData, finalData) {
  const finalById = new Map(finalData.players.map((p) => [p.id, p]));
  const preIds = new Set(preData.players.map((p) => p.id));

  const merged = [];
  for (const prePlayer of preData.players) {
    if (finalById.has(prePlayer.id)) {
      merged.push({ ...finalById.get(prePlayer.id), calledUp: true });
    } else {
      merged.push({ ...prePlayer, calledUp: false });
    }
  }
  for (const finalPlayer of finalData.players) {
    if (!preIds.has(finalPlayer.id)) {
      merged.push({ ...finalPlayer, calledUp: true });
    }
  }

  // Stable sort by position bucket so any final-only late call-ups land
  // alongside players of the same role.
  const sorted = merged
    .map((p, i) => ({ p, i }))
    .sort((a, b) => {
      const d = bucketOf(a.p.position) - bucketOf(b.p.position);
      return d !== 0 ? d : a.i - b.i;
    })
    .map((x) => x.p);

  return { ...finalData, players: sorted };
}

function main() {
  const entries = readdirSync(TEAMS_DIR);
  const ids = entries
    .filter((f) => f.endsWith("-final.json"))
    .map((f) => f.slice(0, -"-final.json".length))
    .filter((id) => entries.includes(`${id}-pre.json`));

  if (ids.length === 0) {
    console.log("No <id>-pre.json / <id>-final.json pairs found.");
    return;
  }

  for (const id of ids) {
    const prePath = join(TEAMS_DIR, `${id}-pre.json`);
    const finalPath = join(TEAMS_DIR, `${id}-final.json`);
    const outPath = join(TEAMS_DIR, `${id}.json`);

    const pre = JSON.parse(readFileSync(prePath, "utf8"));
    const final = JSON.parse(readFileSync(finalPath, "utf8"));

    const merged = mergeTeam(pre, final);
    writeFileSync(outPath, stringifySorted(merged));

    const calledUp = merged.players.filter((p) => p.calledUp).length;
    const notCalledUp = merged.players.length - calledUp;
    console.log(
      `${id}: ${merged.players.length} players (${calledUp} called up, ${notCalledUp} not) -> ${outPath}`,
    );
  }
}

main();
