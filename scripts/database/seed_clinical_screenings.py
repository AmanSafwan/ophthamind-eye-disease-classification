#!/usr/bin/env python3
"""
Seed realistic multi-clinician screening history for Eye System demo.

- Keeps existing ameer@clinic.my, creates 99 more clinic_doctor accounts
- Deletes old predictions + upload/predictions/*
- Pre-runs real CNN/VGG16/ResNet50 ensemble on every dataset image
- Assigns 2-3 screenings per patient with age-weighted image selection
- Ensures all 100 ophthalmologists appear in doctor_id distribution
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import pickle
import random
import re
import shutil
import sys
import time
import uuid
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path

import bcrypt
import numpy as np
import pymysql
import tensorflow as tf
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from clinical_schedule import assign_primary_doctors, build_doctor_timeline, pick_doctor_for_screening
DATASET_ROOT = ROOT / "dataset"
UPLOAD_DIR = ROOT / "upload" / "predictions"
CONFIG_PATH = ROOT / "config" / "ai_models.json"
MODELS_DIR = ROOT / "ai" / "models"
ENV_PATH = ROOT / ".env"
PREDICTION_CACHE_PATH = ROOT / "storage" / "cache" / "dataset_predictions.pkl"

DATASET_FOLDERS = ("normal", "cataract", "glaucoma", "diabetic_retinopathy")
IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}

CLASS_LABELS = {
    0: "Cataract",
    1: "Diabetic Retinopathy",
    2: "Glaucoma",
    3: "Normal",
}

RISK_MAP = {
    "Cataract": "Medium",
    "Diabetic Retinopathy": "High",
    "Glaucoma": "High",
    "Normal": "Low",
}

DOCTOR_PASSWORD = "password123"

MALAY_MALE = [
    "Ahmad", "Faizal", "Khairul", "Hafiz", "Amirul", "Haziq", "Syafiq", "Rizal", "Ikmal", "Arif",
    "Hakim", "Nazri", "Farid", "Ashraf", "Luqman", "Firdaus", "Jamal", "Danish", "Amir", "Zulkifli",
]
MALAY_FEMALE = [
    "Siti", "Nurul", "Fatimah", "Aisyah", "Rokiah", "Zarina", "Rohani", "Sofea", "Nurin", "Arina",
    "Halimah", "Mariam", "Noraini", "Syafiqah", "Wan Nur", "Azlina", "Haslina", "Suriani", "Mazni", "Hasnita",
]
CHINESE_SURNAMES = ["Tan", "Lim", "Lee", "Wong", "Ng", "Chong", "Goh", "Teh", "Chew", "Yap", "Lau", "Khoo", "Ong"]
CHINESE_GIVEN = [
    "Wei Ming", "Jun Hao", "Mei Ling", "Su Chin", "Kok Wai", "Pei Ying", "Yong Kang", "Jia Wei", "Xin Yi", "Boon Heng",
]
INDIAN_MALE = ["Rajesh", "Suresh", "Murugan", "Prakash", "Vijay", "Ganesh", "Karthick", "Ramesh", "Selvam", "Arvind"]
INDIAN_FEMALE = ["Priya", "Lakshmi", "Kavitha", "Meena", "Shanti", "Devi", "Anitha", "Malar", "Revathi", "Vasanthi"]


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


def normalize_label(label: str) -> str:
    if not label:
        return "Normal"
    key = label.lower().strip().replace("_", " ").replace("-", " ")
    exact = {
        "normal": "Normal",
        "cataract": "Cataract",
        "glaucoma": "Glaucoma",
        "diabetic retinopathy": "Diabetic Retinopathy",
        "diabeticretinopathy": "Diabetic Retinopathy",
        "retinopathy": "Diabetic Retinopathy",
        "dr": "Diabetic Retinopathy",
    }
    if key in exact:
        return exact[key]
    if "diabet" in key or "retinopath" in key:
        return "Diabetic Retinopathy"
    if "glauc" in key:
        return "Glaucoma"
    if "catar" in key:
        return "Cataract"
    return "Normal"


def compute_risk_level(diagnosis: str, certainty_pct: float, agreement_pct: float) -> str:
    dx = normalize_label(diagnosis)
    certainty = max(0.0, min(100.0, certainty_pct))
    agreement = max(0.0, min(100.0, agreement_pct))

    if dx == "Normal":
        return "Low" if certainty >= 75.0 and agreement >= 75.0 else "Medium"
    if dx == "Cataract":
        return "Low" if certainty < 55.0 or agreement < 55.0 else "Medium"
    if dx in ("Diabetic Retinopathy", "Glaucoma"):
        return "High" if certainty >= 70.0 and agreement >= 70.0 else "Medium"
    return RISK_MAP.get(dx, "Medium")


def php_bcrypt(password: str) -> str:
    hashed = bcrypt.hashpw(password.encode("utf-8"), bcrypt.gensalt(rounds=10))
    return hashed.decode("utf-8").replace("$2b$", "$2y$")


def age_folder_weights(age: int) -> list[tuple[str, float]]:
    if age < 30:
        return [("normal", 0.78), ("cataract", 0.07), ("glaucoma", 0.07), ("diabetic_retinopathy", 0.08)]
    if age < 45:
        return [("normal", 0.55), ("cataract", 0.15), ("glaucoma", 0.15), ("diabetic_retinopathy", 0.15)]
    if age < 60:
        return [("normal", 0.32), ("cataract", 0.24), ("glaucoma", 0.22), ("diabetic_retinopathy", 0.22)]
    if age < 75:
        return [("normal", 0.16), ("cataract", 0.30), ("glaucoma", 0.27), ("diabetic_retinopathy", 0.27)]
    return [("normal", 0.08), ("cataract", 0.34), ("glaucoma", 0.29), ("diabetic_retinopathy", 0.29)]


def pick_weighted_folder(rng: random.Random, age: int) -> str:
    weights = age_folder_weights(age)
    folders, probs = zip(*weights)
    return rng.choices(list(folders), weights=list(probs), k=1)[0]


def generate_doctor_name(rng: random.Random, index: int) -> str:
    pool = rng.randint(0, 2)
    if pool == 0:
        if rng.random() < 0.55:
            return f"Dr. {rng.choice(MALAY_MALE)} {rng.choice(['Bin Hassan', 'Bin Ismail', 'Bin Abdullah', 'Bin Rahman'])}"
        return f"Dr. {rng.choice(MALAY_FEMALE)} {rng.choice(['Binti Hassan', 'Binti Ismail', 'Binti Abdullah', 'Binti Rahman'])}"
    if pool == 1:
        return f"Dr. {rng.choice(CHINESE_SURNAMES)} {rng.choice(CHINESE_GIVEN)}"
    if rng.random() < 0.5:
        return f"Dr. {rng.choice(INDIAN_MALE)} A/L {rng.choice(['Rajan', 'Kumar', 'Muthu', 'Subramaniam', 'Nathan'])}"
    return f"Dr. {rng.choice(INDIAN_FEMALE)} A/P {rng.choice(['Devi', 'Letchumy', 'Rani', 'Kamala', 'Lakshmi'])}"


def index_dataset() -> tuple[dict[str, list[Path]], list[Path]]:
    by_folder: dict[str, list[Path]] = {f: [] for f in DATASET_FOLDERS}
    all_images: list[Path] = []
    for folder in DATASET_FOLDERS:
        base = DATASET_ROOT / folder
        if not base.is_dir():
            raise FileNotFoundError(f"Dataset folder missing: {base}")
        for path in base.rglob("*"):
            if path.is_file() and path.suffix.lower() in IMAGE_EXTENSIONS:
                by_folder[folder].append(path)
                all_images.append(path)
    total = len(all_images)
    if total == 0:
        raise RuntimeError(f"No images found under {DATASET_ROOT}")
    for folder in DATASET_FOLDERS:
        log(f"  {folder}: {len(by_folder[folder])} images")
    log(f"  total dataset images: {total}")
    return by_folder, all_images


def load_ai_config() -> dict:
    with CONFIG_PATH.open(encoding="utf-8") as fh:
        return json.load(fh)


def load_models():
    models = {}
    for name in ("cnn", "vgg16", "resnet50"):
        path = MODELS_DIR / name / f"{name}_final.keras"
        if not path.is_file():
            raise FileNotFoundError(f"Model not found: {path}")
        log(f"Loading model {name}...")
        models[name] = tf.keras.models.load_model(str(path), compile=False)
    return models


def base_preprocess(image: Image.Image) -> np.ndarray:
    image = image.convert("RGB").resize((224, 224))
    return np.array(image).astype(np.float32)


def preprocess_for_cnn(img: np.ndarray) -> np.ndarray:
    return np.expand_dims(img / 255.0, axis=0)


def preprocess_for_vgg(img: np.ndarray) -> np.ndarray:
    return tf.keras.applications.vgg16.preprocess_input(np.expand_dims(img.copy(), axis=0))


def preprocess_for_resnet(img: np.ndarray) -> np.ndarray:
    return tf.keras.applications.resnet50.preprocess_input(np.expand_dims(img.copy(), axis=0))


def predict_single(models: dict, image_path: Path, ai_config: dict) -> dict:
    image = Image.open(image_path)
    base_img = base_preprocess(image)

    cnn_pred = models["cnn"].predict(preprocess_for_cnn(base_img), verbose=0)[0]
    vgg_pred = models["vgg16"].predict(preprocess_for_vgg(base_img), verbose=0)[0]
    resnet_pred = models["resnet50"].predict(preprocess_for_resnet(base_img), verbose=0)[0]

    cnn_pred = np.nan_to_num(cnn_pred)
    vgg_pred = np.nan_to_num(vgg_pred)
    resnet_pred = np.nan_to_num(resnet_pred)

    w = ai_config["ensemble_weights"]
    final_pred = cnn_pred * w["cnn"] + vgg_pred * w["vgg16"] + resnet_pred * w["resnet50"]

    cnn_class = int(np.argmax(cnn_pred))
    vgg_class = int(np.argmax(vgg_pred))
    resnet_class = int(np.argmax(resnet_pred))
    final_class = int(np.argmax(final_pred))

    cnn_label = normalize_label(CLASS_LABELS.get(cnn_class, "Normal"))
    vgg_label = normalize_label(CLASS_LABELS.get(vgg_class, "Normal"))
    resnet_label = normalize_label(CLASS_LABELS.get(resnet_class, "Normal"))
    final_label = normalize_label(CLASS_LABELS.get(final_class, "Normal"))

    cnn_conf = round(float(np.max(cnn_pred)) * 100, 2)
    vgg_conf = round(float(np.max(vgg_pred)) * 100, 2)
    resnet_conf = round(float(np.max(resnet_pred)) * 100, 2)
    final_conf = round(float(np.max(final_pred)) * 100, 2)

    agreement = calculate_agreement_metrics(
        ai_config,
        [cnn_label, vgg_label, resnet_label],
        [cnn_conf, vgg_conf, resnet_conf],
        final_label,
    )

    risk = compute_risk_level(final_label, final_conf, agreement["model_agreement_score"])

    return {
        "cnn_result": cnn_label,
        "vgg_result": vgg_label,
        "resnet_result": resnet_label,
        "cnn_confidence": cnn_conf,
        "vgg_confidence": vgg_conf,
        "resnet_confidence": resnet_conf,
        "final_result": final_label,
        "confidence": final_conf,
        "final_confidence": final_conf,
        "model_agreement_score": agreement["model_agreement_score"],
        "risk_level": risk,
    }


def calculate_agreement_metrics(ai_config: dict, labels: list[str], confidences: list[float], final_label: str) -> dict:
    bench = ai_config["benchmark_accuracy"]
    weights = ai_config["ensemble_weights"]
    models = [
        {"key": "cnn", "label": labels[0], "confidence": confidences[0], "accuracy": bench["cnn"]},
        {"key": "vgg16", "label": labels[1], "confidence": confidences[1], "accuracy": bench["vgg16"]},
        {"key": "resnet50", "label": labels[2], "confidence": confidences[2], "accuracy": bench["resnet50"]},
    ]

    majority = max(set(labels), key=labels.count)
    label_concordance = (labels.count(majority) / len(labels)) * 100

    weighted_confidence = 0.0
    weight_sum = 0.0
    for model in models:
        weight = weights[model["key"]]
        weighted_confidence += weight * (model["accuracy"] / 100.0) * (model["confidence"] / 100.0)
        weight_sum += weight
    weighted_confidence = (weighted_confidence / weight_sum * 100.0) if weight_sum else 0.0

    aligned = [m for m in models if m["label"] == final_label]
    if aligned:
        agreement_accuracy = sum(m["accuracy"] for m in aligned) / len(aligned)
        acc_sum = sum(m["accuracy"] for m in aligned)
        agreement_confidence = (
            sum(m["accuracy"] * m["confidence"] for m in aligned) / acc_sum if acc_sum else 0.0
        )
    else:
        agreement_accuracy = 0.0
        agreement_confidence = weighted_confidence * 0.5

    composite = 0.35 * label_concordance + 0.35 * agreement_accuracy + 0.30 * agreement_confidence

    return {
        "model_agreement_score": round(composite, 2),
        "agreement_label_pct": round(label_concordance, 2),
        "agreement_accuracy_pct": round(agreement_accuracy, 2),
        "agreement_confidence_pct": round(agreement_confidence, 2),
    }


def cache_fingerprint(all_images: list[Path]) -> str:
    parts = [str(p.resolve()) for p in sorted(all_images, key=lambda x: str(x))]
    for name in ("cnn", "vgg16", "resnet50"):
        path = MODELS_DIR / name / f"{name}_final.keras"
        if path.is_file():
            stat = path.stat()
            parts.append(f"{name}:{stat.st_size}:{int(stat.st_mtime)}")
    if CONFIG_PATH.is_file():
        stat = CONFIG_PATH.stat()
        parts.append(f"cfg:{stat.st_size}:{int(stat.st_mtime)}")
    return hashlib.sha256("\n".join(parts).encode("utf-8")).hexdigest()


def load_prediction_cache(all_images: list[Path]) -> dict[str, dict] | None:
    if not PREDICTION_CACHE_PATH.is_file():
        return None
    try:
        with PREDICTION_CACHE_PATH.open("rb") as fh:
            payload = pickle.load(fh)
        if payload.get("fingerprint") != cache_fingerprint(all_images):
            log("Prediction cache stale — recomputing.")
            return None
        log(f"Loaded prediction cache ({len(payload.get('items', {}))} images).")
        return payload.get("items", {})
    except Exception as exc:
        log(f"Could not load prediction cache: {exc}")
        return None


def save_prediction_cache(all_images: list[Path], items: dict[str, dict]) -> None:
    PREDICTION_CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
    payload = {"fingerprint": cache_fingerprint(all_images), "items": items}
    with PREDICTION_CACHE_PATH.open("wb") as fh:
        pickle.dump(payload, fh, protocol=pickle.HIGHEST_PROTOCOL)
    log(f"Saved prediction cache to {PREDICTION_CACHE_PATH}")


def precompute_all_predictions(models: dict, all_images: list[Path], ai_config: dict) -> dict[str, dict]:
    cached = load_prediction_cache(all_images)
    if cached is not None:
        return cached

    cache: dict[str, dict] = {}
    total = len(all_images)
    log(f"Running real AI inference on {total} dataset images...")
    started = time.time()
    for idx, path in enumerate(all_images, start=1):
        cache[str(path.resolve())] = predict_single(models, path, ai_config)
        if idx == 1 or idx % 100 == 0 or idx == total:
            elapsed = time.time() - started
            rate = idx / elapsed if elapsed > 0 else 0
            log(f"  [{idx}/{total}] {rate:.1f} img/s")
    log(f"Precompute done in {time.time() - started:.1f}s")
    save_prediction_cache(all_images, cache)
    return cache


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


def ensure_doctors(conn: pymysql.connections.Connection, rng: random.Random) -> list[int]:
    with conn.cursor() as cur:
        cur.execute("SELECT id, email FROM users WHERE role = 'clinic_doctor' ORDER BY id")
        existing = {row[1].lower(): row[0] for row in cur.fetchall()}

        if "ameer@clinic.my" not in existing:
            log("WARNING: ameer@clinic.my not found — continuing with existing clinic doctors.")

        password_hash = php_bcrypt(DOCTOR_PASSWORD)
        created = 0
        for i in range(1, 100):
            email = f"oph{i:03d}@clinic.my"
            if email.lower() in existing:
                continue
            name = generate_doctor_name(rng, i)
            created_at = datetime.now() - timedelta(days=rng.randint(120, 720))
            cur.execute(
                "INSERT INTO users (name, email, password, role, created_at) VALUES (%s, %s, %s, 'clinic_doctor', %s)",
                (name, email, password_hash, created_at.strftime("%Y-%m-%d %H:%M:%S")),
            )
            created += 1

        conn.commit()

        cur.execute("SELECT id FROM users WHERE role = 'clinic_doctor' ORDER BY id")
        doctor_ids = [row[0] for row in cur.fetchall()]

    log(f"Ophthalmologists ready: {len(doctor_ids)} (created {created} new, password: {DOCTOR_PASSWORD})")
    if len(doctor_ids) < 100:
        log(f"WARNING: expected 100 doctors, found {len(doctor_ids)}")
    return doctor_ids


def clear_old_predictions(conn: pymysql.connections.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM predictions")
        old_count = cur.fetchone()[0]
        cur.execute("DELETE FROM predictions")
        conn.commit()
    log(f"Deleted {old_count} old prediction rows.")

    if UPLOAD_DIR.is_dir():
        removed = 0
        for item in UPLOAD_DIR.iterdir():
            if item.is_file():
                item.unlink(missing_ok=True)
                removed += 1
        log(f"Cleared {removed} files from upload/predictions/")
    else:
        UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
        log("Created upload/predictions/")


def unique_upload_name(src: Path) -> str:
    stem = hashlib.md5(f"{uuid.uuid4().hex}{time.time()}".encode()).hexdigest()[:16]
    ext = src.suffix.lower() if src.suffix else ".jpg"
    if ext not in IMAGE_EXTENSIONS:
        ext = ".jpg"
    return f"img_{stem}{ext}"


def pick_image_path(rng: random.Random, age: int, by_folder: dict[str, list[Path]], used_for_patient: set[str]) -> Path:
    for _ in range(12):
        folder = pick_weighted_folder(rng, age)
        pool = by_folder[folder]
        if not pool:
            continue
        candidate = rng.choice(pool)
        key = str(candidate.resolve())
        if key not in used_for_patient:
            return candidate
    # fallback: any unused image globally
    for _ in range(20):
        folder = rng.choice(DATASET_FOLDERS)
        candidate = rng.choice(by_folder[folder])
        key = str(candidate.resolve())
        if key not in used_for_patient:
            return candidate
    return rng.choice(by_folder[pick_weighted_folder(rng, age)])


def seed_screenings(
    conn: pymysql.connections.Connection,
    doctor_ids: list[int],
    by_folder: dict[str, list[Path]],
    prediction_cache: dict[str, dict],
    rng: random.Random,
    sample_limit: int | None,
) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT id, age FROM patients ORDER BY id")
        patients = cur.fetchall()

    if sample_limit:
        patients = patients[:sample_limit]

    total_patients = len(patients)
    end_date = datetime.now().replace(microsecond=0)
    patient_ids = [int(p[0]) for p in patients]
    primary_doctors = assign_primary_doctors(rng, patient_ids, doctor_ids)

    # Collect assignments per doctor, then assign timelines (dashboard-friendly dates)
    pending_by_doctor: dict[int, list[tuple]] = defaultdict(list)

    insert_sql = """
        INSERT INTO predictions (
            patient_id, doctor_id, image_path,
            cnn_result, vgg_result, resnet_result,
            cnn_confidence, vgg_confidence, resnet_confidence,
            final_result, confidence, final_confidence,
            model_agreement_score, risk_level, created_at, deleted
        ) VALUES (
            %s, %s, %s,
            %s, %s, %s,
            %s, %s, %s,
            %s, %s, %s,
            %s, %s, %s, 0
        )
    """

    batch: list[tuple] = []
    batch_size = 500
    total_rows = 0
    started = time.time()

    for p_idx, (patient_id, age) in enumerate(patients, start=1):
        age = int(age or 40)
        screening_count = 2 if rng.random() < 0.38 else 3
        used_images: set[str] = set()

        for s_idx in range(screening_count):
            src = pick_image_path(rng, age, by_folder, used_images)
            used_images.add(str(src.resolve()))

            filename = unique_upload_name(src)
            dest = UPLOAD_DIR / filename
            shutil.copy2(src, dest)

            ai = prediction_cache[str(src.resolve())]
            doctor_id = pick_doctor_for_screening(rng, int(patient_id), s_idx, primary_doctors, doctor_ids)

            pending_by_doctor[doctor_id].append((
                patient_id,
                doctor_id,
                f"predictions/{filename}",
                ai["cnn_result"],
                ai["vgg_result"],
                ai["resnet_result"],
                ai["cnn_confidence"],
                ai["vgg_confidence"],
                ai["resnet_confidence"],
                ai["final_result"],
                ai["confidence"],
                ai["final_confidence"],
                ai["model_agreement_score"],
                ai["risk_level"],
            ))

        if p_idx == 1 or p_idx % 1000 == 0 or p_idx == total_patients:
            pending = sum(len(v) for v in pending_by_doctor.values())
            log(f"  staged patients {p_idx}/{total_patients} · screenings {pending}")

    log("Assigning clinic dates per doctor...")
    for doc_id in doctor_ids:
        rows = pending_by_doctor.get(doc_id, [])
        if not rows:
            continue
        timeline = build_doctor_timeline(rng, len(rows), end_date)
        rng.shuffle(rows)
        for row, dt in zip(rows, timeline):
            batch.append((*row, dt.strftime("%Y-%m-%d %H:%M:%S")))
            total_rows += 1
            if len(batch) >= batch_size:
                with conn.cursor() as cur:
                    cur.executemany(insert_sql, batch)
                conn.commit()
                batch.clear()

    if batch:
        with conn.cursor() as cur:
            cur.executemany(insert_sql, batch)
        conn.commit()

    log(f"Inserted {total_rows} screenings for {total_patients} patients.")


def verify_results(conn: pymysql.connections.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM predictions WHERE deleted = 0")
        pred_count = cur.fetchone()[0]

        cur.execute("SELECT COUNT(DISTINCT patient_id) FROM predictions WHERE deleted = 0")
        patients_with = cur.fetchone()[0]

        cur.execute("SELECT COUNT(*) FROM patients")
        patient_total = cur.fetchone()[0]

        cur.execute("SELECT COUNT(DISTINCT doctor_id) FROM predictions WHERE deleted = 0")
        doctors_used = cur.fetchone()[0]

        cur.execute("SELECT COUNT(*) FROM users WHERE role = 'clinic_doctor'")
        doctor_total = cur.fetchone()[0]

        cur.execute(
            """
            SELECT p.id FROM patients p
            LEFT JOIN predictions pr ON pr.patient_id = p.id AND pr.deleted = 0
            WHERE pr.id IS NULL
            LIMIT 5
            """
        )
        missing = cur.fetchall()

        cur.execute(
            """
            SELECT u.id, u.email FROM users u
            LEFT JOIN predictions pr ON pr.doctor_id = u.id AND pr.deleted = 0
            WHERE u.role = 'clinic_doctor' AND pr.id IS NULL
            LIMIT 5
            """
        )
        idle_doctors = cur.fetchall()

        cur.execute(
            """
            SELECT final_result, COUNT(*) AS c
            FROM predictions WHERE deleted = 0
            GROUP BY final_result ORDER BY c DESC
            """
        )
        diagnosis_mix = cur.fetchall()

    log("\n=== Verification ===")
    log(f"Predictions: {pred_count}")
    log(f"Patients with history: {patients_with}/{patient_total}")
    log(f"Doctors with screenings: {doctors_used}/{doctor_total}")
    if missing:
        log(f"WARNING: {len(missing)}+ patients still missing predictions (sample ids: {[m[0] for m in missing]})")
    else:
        log("All patients have at least one screening.")
    if idle_doctors:
        log(f"WARNING: idle doctors (sample): {idle_doctors}")
    else:
        log("All ophthalmologists have at least one screening.")
    log("Diagnosis mix:")
    for row in diagnosis_mix:
        log(f"  {row[0]}: {row[1]}")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Seed realistic clinical screening demo data.")
    parser.add_argument("--yes", action="store_true", help="Skip confirmation prompt")
    parser.add_argument("--sample", type=int, default=0, help="Limit to first N patients (testing)")
    parser.add_argument("--seed", type=int, default=42, help="Random seed for reproducibility")
    parser.add_argument("--cache-only", action="store_true", help="Only build/save dataset AI cache, then exit")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if not args.yes:
        log("Re-run with --yes to execute.")
        return 1

    env = load_env(ENV_PATH)
    rng = random.Random(args.seed)

    log("=== Eye System Clinical Demo Seeder ===\n")
    log("Indexing dataset...")
    by_folder, all_images = index_dataset()

    log("\nConnecting to database...")
    conn = connect_db(env)

    try:
        log("\nEnsuring ophthalmologist accounts...")
        doctor_ids = ensure_doctors(conn, rng)

        log("\nClearing old predictions...")
        clear_old_predictions(conn)

        log("\nLoading AI models...")
        ai_config = load_ai_config()
        models = load_models()
        prediction_cache = precompute_all_predictions(models, all_images, ai_config)

        log("\nSeeding patient screenings...")
        sample_limit = args.sample if args.sample > 0 else None
        seed_screenings(conn, doctor_ids, by_folder, prediction_cache, rng, sample_limit)

        verify_results(conn)
        log("\nDone.")
        return 0
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
