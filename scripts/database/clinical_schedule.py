"""Realistic per-doctor clinic appointment scheduling (no overlapping slots)."""

from __future__ import annotations

import random
from datetime import date, datetime, timedelta


def _weekday_on_or_before(day: datetime) -> datetime:
    d = day
    while d.weekday() == 6:
        d -= timedelta(days=1)
    return d.replace(hour=0, minute=0, second=0, microsecond=0)


def build_doctor_timeline(rng: random.Random, count: int, end_date: datetime) -> list[datetime]:
    """
    One doctor, one patient at a time: slots are 10-20 minutes apart.
    No duplicate date+hour+minute; nothing scheduled in the future.
    """
    if count <= 0:
        return []

    now = end_date.replace(microsecond=0)
    today = now.replace(hour=0, minute=0, second=0, microsecond=0)
    open_min = 8 * 60
    close_min = 17 * 60 + 45
    bucket_ranges = [(0, 6), (7, 29), (30, 89), (90, 330)]
    bucket_weights = [36, 26, 22, 16]

    sessions: list[tuple[datetime, int]] = []
    planned = 0
    while planned < count:
        pick = rng.randint(1, sum(bucket_weights))
        cum = 0
        start_off, end_off = bucket_ranges[-1]
        for (lo, hi), w in zip(bucket_ranges, bucket_weights):
            cum += w
            if pick <= cum:
                start_off, end_off = lo, hi
                break
        day = _weekday_on_or_before(today - timedelta(days=rng.randint(start_off, end_off)))
        batch = min(count - planned, rng.randint(8, 14))
        sessions.append((day, batch))
        planned += batch

    day_cursor: dict[date, int] = {}
    used_minutes: set[tuple[date, int, int]] = set()
    slots: list[datetime] = []

    def reserve_slot(clinic_day: datetime, minute_of_day: int) -> datetime:
        key = clinic_day.date()
        attempts = 0
        while attempts < 500:
            while minute_of_day > close_min:
                clinic_day = _weekday_on_or_before(clinic_day - timedelta(days=1))
                key = clinic_day.date()
                minute_of_day = day_cursor.get(key, open_min + rng.randint(0, 30))

            while minute_of_day < open_min:
                minute_of_day = open_min + rng.randint(0, 20)

            hour, minute = divmod(minute_of_day, 60)
            candidate = clinic_day.replace(
                hour=hour,
                minute=minute,
                second=rng.randint(0, 59),
                microsecond=0,
            )
            if candidate > now:
                minute_of_day -= rng.randint(12, 25)
                attempts += 1
                continue

            stamp = (key, hour, minute)
            if stamp not in used_minutes:
                used_minutes.add(stamp)
                day_cursor[key] = minute_of_day + rng.randint(10, 20)
                return candidate

            minute_of_day += rng.randint(10, 20)
            attempts += 1

        fallback = now - timedelta(minutes=len(slots) * 15 + rng.randint(1, 9))
        return fallback.replace(second=rng.randint(0, 59), microsecond=0)

    for clinic_day, batch in sessions:
        if len(slots) >= count:
            break
        key = clinic_day.date()
        if key not in day_cursor:
            day_cursor[key] = open_min + rng.randint(0, 35)

        cursor = day_cursor[key]
        for _ in range(batch):
            if len(slots) >= count:
                break
            slots.append(reserve_slot(clinic_day, cursor))
            cursor = day_cursor[clinic_day.date()]

    while len(slots) < count:
        day = _weekday_on_or_before(today - timedelta(days=rng.randint(0, 30)))
        key = day.date()
        if key not in day_cursor:
            day_cursor[key] = open_min + rng.randint(0, 35)
        slots.append(reserve_slot(day, day_cursor[key]))

    slots.sort()
    return slots[:count]


def assign_primary_doctors(
    rng: random.Random, patient_ids: list[int], doctor_ids: list[int]
) -> dict[int, int]:
    shuffled = patient_ids[:]
    rng.shuffle(shuffled)
    return {pid: doctor_ids[idx % len(doctor_ids)] for idx, pid in enumerate(shuffled)}


def pick_doctor_for_screening(
    rng: random.Random,
    patient_id: int,
    screening_index: int,
    primary: dict[int, int],
    doctor_ids: list[int],
) -> int:
    primary_doc = primary[patient_id]
    if screening_index == 0:
        return primary_doc
    if screening_index == 1 and rng.random() < 0.82:
        return primary_doc
    if screening_index >= 2 and rng.random() < 0.68:
        return primary_doc
    others = [d for d in doctor_ids if d != primary_doc]
    return rng.choice(others)
