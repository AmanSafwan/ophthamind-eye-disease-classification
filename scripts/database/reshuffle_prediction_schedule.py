#!/usr/bin/env python3
"""
Reassign prediction timestamps and doctor_id for realistic dashboard analytics.

- Sequential clinic slots (10-20 min apart, no duplicate times per doctor)
- Heavier volume in the last 7 / 30 days (default dashboard range)
- Each doctor serves many distinct patients (primary clinician model)
- No AI re-run; updates existing prediction rows only
"""

from __future__ import annotations

import argparse
import sys
from collections import defaultdict
from datetime import datetime
from pathlib import Path

import pymysql

sys.path.insert(0, str(Path(__file__).resolve().parent))
from clinical_schedule import (
    assign_primary_doctors,
    build_doctor_timeline,
    pick_doctor_for_screening,
)

ROOT = Path(__file__).resolve().parents[2]
ENV_PATH = ROOT / ".env"


def load_env(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    if not path.is_file():
        return env
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, val = line.split("=", 1)
        env[key.strip()] = val.strip().strip('"').strip("'")
    return env


def log(msg: str) -> None:
    print(msg, flush=True)


def connect_db(env: dict) -> pymysql.connections.Connection:
    return pymysql.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", "3307")),
        user=env.get("DB_USER", "root"),
        password=env.get("DB_PASS", ""),
        database=env.get("DB_NAME", "eye_system"),
        charset="utf8mb4",
        autocommit=False,
    )


def reshuffle(conn: pymysql.connections.Connection, seed: int) -> None:
    rng = __import__("random").Random(seed)
    end_date = datetime.now().replace(microsecond=0)

    with conn.cursor() as cur:
        cur.execute("SELECT id FROM users WHERE role = 'clinic_doctor' ORDER BY id")
        doctor_ids = [row[0] for row in cur.fetchall()]
        if not doctor_ids:
            raise RuntimeError("No clinic_doctor accounts found.")

        cur.execute(
            """
            SELECT id, patient_id
            FROM predictions
            WHERE deleted = 0
            ORDER BY patient_id, id
            """
        )
        rows = cur.fetchall()

    if not rows:
        raise RuntimeError("No predictions to reshuffle.")

    by_patient: dict[int, list[int]] = defaultdict(list)
    for pred_id, patient_id in rows:
        by_patient[int(patient_id)].append(int(pred_id))

    primary = assign_primary_doctors(rng, list(by_patient.keys()), doctor_ids)

    doctor_assignments: dict[int, list[int]] = defaultdict(list)
    for patient_id, pred_ids in by_patient.items():
        for s_idx, pred_id in enumerate(pred_ids):
            doc = pick_doctor_for_screening(rng, patient_id, s_idx, primary, doctor_ids)
            doctor_assignments[doc].append(pred_id)

    updates: list[tuple[int, int, str]] = []
    for doc_id, pred_ids in doctor_assignments.items():
        timeline = build_doctor_timeline(rng, len(pred_ids), end_date)
        rng.shuffle(pred_ids)
        for pred_id, dt in zip(pred_ids, timeline):
            updates.append((doc_id, pred_id, dt.strftime("%Y-%m-%d %H:%M:%S")))

    log(f"Updating {len(updates)} predictions across {len(doctor_ids)} doctors...")
    batch_size = 1000
    sql = "UPDATE predictions SET doctor_id = %s, created_at = %s WHERE id = %s"

    with conn.cursor() as cur:
        for i in range(0, len(updates), batch_size):
            chunk = updates[i : i + batch_size]
            cur.executemany(sql, [(doc, ts, pid) for doc, pid, ts in chunk])
            conn.commit()
            if i == 0 or (i + batch_size) >= len(updates):
                log(f"  {min(i + batch_size, len(updates))}/{len(updates)}")

    verify(conn, doctor_ids)


def verify(conn: pymysql.connections.Connection, doctor_ids: list[int]) -> None:
    sample_doc = doctor_ids[0]
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT COUNT(*), COUNT(DISTINCT patient_id)
            FROM predictions
            WHERE deleted = 0 AND doctor_id = %s
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            """,
            (sample_doc,),
        )
        week_row = cur.fetchone()

        cur.execute(
            """
            SELECT COUNT(*), COUNT(DISTINCT patient_id)
            FROM predictions
            WHERE deleted = 0 AND doctor_id = %s
              AND created_at >= CURDATE()
              AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            """,
            (sample_doc,),
        )
        today_row = cur.fetchone()

        cur.execute(
            """
            SELECT MIN(created_at), MAX(created_at)
            FROM predictions WHERE deleted = 0
            """
        )
        range_row = cur.fetchone()

        cur.execute(
            """
            SELECT doctor_id, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS slot, COUNT(*) AS c
            FROM predictions
            WHERE deleted = 0
            GROUP BY doctor_id, slot
            HAVING c > 1
            LIMIT 5
            """
        )
        dupes = cur.fetchall()

    log("\n=== Verification (sample doctor id={}) ===".format(sample_doc))
    log(f"Last 7 days: {week_row[0]} screenings, {week_row[1]} unique patients")
    log(f"Today: {today_row[0]} screenings, {today_row[1]} unique patients")
    log(f"Date range: {range_row[0]} to {range_row[1]}")
    if dupes:
        log(f"WARNING: duplicate minute slots found (sample): {dupes}")
    else:
        log("No duplicate doctor+minute slots.")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--yes", action="store_true")
    parser.add_argument("--seed", type=int, default=2026)
    args = parser.parse_args()
    if not args.yes:
        log("Re-run with --yes")
        return 1

    env = load_env(ENV_PATH)
    conn = connect_db(env)
    try:
        log("=== Reshuffle prediction schedule ===\n")
        reshuffle(conn, args.seed)
        log("\nDone.")
        return 0
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
