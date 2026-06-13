"""
Evaluate CNN, VGG16, ResNet50 (and ensemble) on local fundus dataset.
Uses the same preprocessing + label map as ai_api/app.py.
"""
from __future__ import annotations

import argparse
import json
import os
import random
import sys
import time
from collections import defaultdict
from datetime import datetime
from pathlib import Path

import numpy as np
from PIL import Image

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

import tensorflow as tf  # noqa: E402

ROOT = Path(__file__).resolve().parents[1]
MODELS_DIR = ROOT / "ai" / "models"
DEFAULT_DATASET = ROOT / "dataset"
OUTPUT_DIR = ROOT / "storage" / "logs" / "model_eval"

CLASS_LABELS = {
    0: "Cataract",
    1: "Diabetic Retinopathy",
    2: "Glaucoma",
    3: "Normal",
}

FOLDER_TO_INDEX = {
    "cataract": 0,
    "diabetic_retinopathy": 1,
    "glaucoma": 2,
    "normal": 3,
}

INDEX_TO_FOLDER = {v: k for k, v in FOLDER_TO_INDEX.items()}
LABELS = [CLASS_LABELS[i] for i in range(4)]


def load_model(name: str):
    path = MODELS_DIR / name / f"{name}_final.keras"
    if not path.exists():
        raise FileNotFoundError(path)
    print(f"Loading {name} from {path} ({path.stat().st_size / 1e6:.1f} MB)")
    return tf.keras.models.load_model(path, compile=False)


def base_preprocess(image: Image.Image) -> np.ndarray:
    image = image.convert("RGB").resize((224, 224))
    return np.array(image).astype(np.float32)


def preprocess_batch(images: list[np.ndarray], model_name: str) -> np.ndarray:
    batch = np.stack(images, axis=0)
    if model_name == "cnn":
        return batch / 255.0
    if model_name == "vgg16":
        return tf.keras.applications.vgg16.preprocess_input(batch.copy())
    if model_name == "resnet50":
        return tf.keras.applications.resnet50.preprocess_input(batch.copy())
    raise ValueError(model_name)


def ensemble_probs(cnn_p, vgg_p, resnet_p):
    return cnn_p * 0.30 + vgg_p * 0.35 + resnet_p * 0.35


def confusion_matrix_np(y_true: np.ndarray, y_pred: np.ndarray, num_classes: int = 4):
    cm = np.zeros((num_classes, num_classes), dtype=np.int64)
    for t, p in zip(y_true, y_pred):
        cm[int(t), int(p)] += 1
    return cm


def classification_metrics(y_true: np.ndarray, y_pred: np.ndarray):
    cm = confusion_matrix_np(y_true, y_pred)
    n = len(y_true)
    accuracy = float(np.mean(y_true == y_pred))

    per_class = {}
    f1s = []
    supports = []
    for i, label in enumerate(LABELS):
        tp = int(cm[i, i])
        fp = int(cm[:, i].sum() - tp)
        fn = int(cm[i, :].sum() - tp)
        support = int(cm[i, :].sum())
        precision = tp / (tp + fp) if (tp + fp) else 0.0
        recall = tp / (tp + fn) if (tp + fn) else 0.0
        f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0
        per_class[label] = {
            "precision": float(precision),
            "recall": float(recall),
            "f1": float(f1),
            "support": support,
        }
        f1s.append(f1)
        supports.append(support)

    macro_f1 = float(np.mean(f1s)) if f1s else 0.0
    weighted_f1 = (
        float(np.average(f1s, weights=supports)) if supports and sum(supports) else 0.0
    )
    return {
        "accuracy": accuracy,
        "macro_f1": macro_f1,
        "weighted_f1": weighted_f1,
        "per_class": per_class,
        "confusion_matrix": cm.tolist(),
    }


def collect_samples(dataset_dir: Path) -> list[tuple[Path, int]]:
    exts = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}
    samples: list[tuple[Path, int]] = []
    for folder, idx in FOLDER_TO_INDEX.items():
        class_dir = dataset_dir / folder
        if not class_dir.is_dir():
            continue
        for path in class_dir.rglob("*"):
            if path.suffix.lower() in exts and path.is_file():
                samples.append((path, idx))
    samples.sort(key=lambda x: str(x[0]).lower())
    return samples


def make_splits(samples: list[tuple[Path, int]], seed: int) -> dict[str, list[tuple[Path, int]]]:
    by_class: dict[int, list[tuple[Path, int]]] = defaultdict(list)
    for item in samples:
        by_class[item[1]].append(item)

    rng = random.Random(seed)

    split_a: list[tuple[Path, int]] = []
    split_b: list[tuple[Path, int]] = []
    split_c: list[tuple[Path, int]] = []

    for idx, items in by_class.items():
        rng.shuffle(items)
        n = len(items)
        a_end = int(n * 0.70)
        b_end = int(n * 0.85)
        split_a.extend(items[:a_end])
        split_b.extend(items[a_end:b_end])
        split_c.extend(items[b_end:])

    rng.shuffle(split_a)
    rng.shuffle(split_b)
    rng.shuffle(split_c)

    return {
        "full": samples,
        "split_70pct": split_a,
        "split_15pct_A": split_b,
        "split_15pct_B": split_c,
    }


def predict_split(
    split_name: str,
    samples: list[tuple[Path, int]],
    models: dict[str, tf.keras.Model],
    batch_size: int,
) -> dict:
    y_true = np.array([y for _, y in samples], dtype=np.int32)
    n = len(samples)
    cnn_probs = np.zeros((n, 4), dtype=np.float32)
    vgg_probs = np.zeros((n, 4), dtype=np.float32)
    res_probs = np.zeros((n, 4), dtype=np.float32)

    t0 = time.time()
    paths = [p for p, _ in samples]

    for start in range(0, n, batch_size):
        end = min(start + batch_size, n)
        batch_paths = paths[start:end]
        raw_images = []
        for p in batch_paths:
            with Image.open(p) as img:
                raw_images.append(base_preprocess(img))

        cnn_probs[start:end] = models["cnn"].predict(
            preprocess_batch(raw_images, "cnn"), verbose=0
        )
        vgg_probs[start:end] = models["vgg16"].predict(
            preprocess_batch(raw_images, "vgg16"), verbose=0
        )
        res_probs[start:end] = models["resnet50"].predict(
            preprocess_batch(raw_images, "resnet50"), verbose=0
        )

        done = end
        if done % (batch_size * 10) == 0 or done == n:
            elapsed = time.time() - t0
            rate = done / elapsed if elapsed else 0
            print(f"  [{split_name}] {done}/{n} ({rate:.1f} img/s)")

    ens_probs = ensemble_probs(cnn_probs, vgg_probs, res_probs)

    results = {}
    for name, probs in [
        ("cnn", cnn_probs),
        ("vgg16", vgg_probs),
        ("resnet50", res_probs),
        ("ensemble", ens_probs),
    ]:
        y_pred = np.argmax(probs, axis=1)
        results[name] = classification_metrics(y_true, y_pred)

    # Pairwise agreement between individual models
    cnn_pred = np.argmax(cnn_probs, axis=1)
    vgg_pred = np.argmax(vgg_probs, axis=1)
    res_pred = np.argmax(res_probs, axis=1)
    results["agreement"] = {
        "cnn_vs_vgg16": float(np.mean(cnn_pred == vgg_pred)),
        "cnn_vs_resnet50": float(np.mean(cnn_pred == res_pred)),
        "vgg16_vs_resnet50": float(np.mean(vgg_pred == res_pred)),
        "all_three_same": float(np.mean((cnn_pred == vgg_pred) & (vgg_pred == res_pred))),
    }

    results["elapsed_sec"] = round(time.time() - t0, 2)
    results["n_samples"] = n
    return results


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dataset", default=str(DEFAULT_DATASET))
    parser.add_argument("--batch-size", type=int, default=16)
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument(
        "--splits",
        default="full,split_70pct,split_15pct_A,split_15pct_B",
        help="Comma-separated split names",
    )
    args = parser.parse_args()

    dataset_dir = Path(args.dataset)
    if not dataset_dir.is_dir():
        print(f"Dataset not found: {dataset_dir}", file=sys.stderr)
        sys.exit(1)

    samples = collect_samples(dataset_dir)
    if not samples:
        print(f"No images found under {dataset_dir}", file=sys.stderr)
        sys.exit(1)

    counts = defaultdict(int)
    for _, y in samples:
        counts[y] += 1

    print("=" * 60)
    print("OphthaMind model evaluation")
    print("=" * 60)
    print(f"Dataset : {dataset_dir}")
    print(f"Total   : {len(samples)} images")
    for i in range(4):
        print(f"  {CLASS_LABELS[i]:22s} {counts[i]:5d}  ({INDEX_TO_FOLDER[i]}/)")
    print(f"TF      : {tf.__version__}")
    print()

    models = {
        "cnn": load_model("cnn"),
        "vgg16": load_model("vgg16"),
        "resnet50": load_model("resnet50"),
    }

    all_splits = make_splits(samples, args.seed)
    selected = [s.strip() for s in args.splits.split(",") if s.strip()]

    report = {
        "generated_at": datetime.now().isoformat(timespec="seconds"),
        "dataset": str(dataset_dir),
        "tensorflow": tf.__version__,
        "class_map": CLASS_LABELS,
        "folder_map": FOLDER_TO_INDEX,
        "class_counts": {CLASS_LABELS[k]: v for k, v in sorted(counts.items())},
        "models": {
            name: {
                "path": str(MODELS_DIR / name / f"{name}_final.keras"),
                "size_mb": round((MODELS_DIR / name / f"{name}_final.keras").stat().st_size / 1e6, 2),
                "modified": datetime.fromtimestamp(
                    (MODELS_DIR / name / f"{name}_final.keras").stat().st_mtime
                ).isoformat(timespec="seconds"),
            }
            for name in models
        },
        "ensemble_weights": {"cnn": 0.30, "vgg16": 0.35, "resnet50": 0.35},
        "splits": {},
    }

    for split_name in selected:
        if split_name not in all_splits:
            print(f"Unknown split: {split_name}", file=sys.stderr)
            continue
        split_samples = all_splits[split_name]
        print(f"\n--- Evaluating: {split_name} ({len(split_samples)} images) ---")
        report["splits"][split_name] = predict_split(
            split_name, split_samples, models, args.batch_size
        )

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    json_path = OUTPUT_DIR / f"model_eval_{stamp}.json"
    txt_path = OUTPUT_DIR / f"model_eval_{stamp}.txt"

    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)

    lines = [
        "OphthaMind Model Evaluation Report",
        f"Generated: {report['generated_at']}",
        f"Dataset: {dataset_dir} ({len(samples)} images)",
        "",
        "Model files:",
    ]
    for name, info in report["models"].items():
        lines.append(f"  {name:10s} {info['size_mb']} MB  modified {info['modified']}")

    for split_name, split_data in report["splits"].items():
        lines.append("")
        lines.append("=" * 60)
        lines.append(f"SPLIT: {split_name}  (n={split_data['n_samples']})")
        lines.append("=" * 60)
        lines.append(f"{'Model':12s} {'Accuracy':>10s} {'Macro-F1':>10s} {'Weighted-F1':>12s}")
        for model_name in ("cnn", "vgg16", "resnet50", "ensemble"):
            m = split_data[model_name]
            lines.append(
                f"{model_name:12s} {m['accuracy']*100:9.2f}% {m['macro_f1']*100:9.2f}% {m['weighted_f1']*100:11.2f}%"
            )
        lines.append("")
        lines.append("Per-class F1 (%):")
        lines.append(f"{'Model':12s} " + "  ".join(f"{lb[:8]:>8s}" for lb in LABELS))
        for model_name in ("cnn", "vgg16", "resnet50", "ensemble"):
            m = split_data[model_name]
            row = f"{model_name:12s} "
            row += "  ".join(f"{m['per_class'][lb]['f1']*100:8.2f}" for lb in LABELS)
            lines.append(row)
        agr = split_data["agreement"]
        lines.append("")
        lines.append(
            f"Agreement: CNN-VGG {agr['cnn_vs_vgg16']*100:.1f}% | "
            f"CNN-ResNet {agr['cnn_vs_resnet50']*100:.1f}% | "
            f"VGG-ResNet {agr['vgg16_vs_resnet50']*100:.1f}% | "
            f"All same {agr['all_three_same']*100:.1f}%"
        )

    txt_path.write_text("\n".join(lines), encoding="utf-8")

    print("\n" + "=" * 60)
    print("SUMMARY (full split)")
    print("=" * 60)
    if "full" in report["splits"]:
        full = report["splits"]["full"]
        for model_name in ("cnn", "vgg16", "resnet50", "ensemble"):
            m = full[model_name]
            print(f"{model_name:10s} acc={m['accuracy']*100:.2f}%  macro-F1={m['macro_f1']*100:.2f}%")
        agr = full["agreement"]
        print(
            f"\nVGG16 agrees with CNN {agr['cnn_vs_vgg16']*100:.1f}% of the time — "
            f"if very high, ensemble output may look similar even after VGG upgrade."
        )

    print(f"\nSaved: {json_path}")
    print(f"Saved: {txt_path}")


if __name__ == "__main__":
    main()
