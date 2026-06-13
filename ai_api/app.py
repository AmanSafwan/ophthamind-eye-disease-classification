from flask import Flask, request, jsonify
import tensorflow as tf
import numpy as np
from PIL import Image
import os
import logging
import json
from datetime import datetime, timezone

CONFIG_PATH = os.path.join(os.path.dirname(__file__), "..", "config", "ai_models.json")


def load_ai_config() -> dict:
    if not os.path.isfile(CONFIG_PATH):
        raise FileNotFoundError(f"AI config not found: {CONFIG_PATH}")
    with open(CONFIG_PATH, encoding="utf-8") as fh:
        return json.load(fh)


AI_CONFIG = load_ai_config()

# =====================================================
# APP INIT
# =====================================================
app = Flask(__name__)

@app.route("/")
def home():
    return "AI API is running"

logging.basicConfig(level=logging.INFO)

# =====================================================
# BASE PATH (ai_api/ -> ../ai/models/)
# =====================================================
BASE_DIR = os.path.join(os.path.dirname(__file__), "..", "ai", "models")

# =====================================================
# LABEL MAP (GLOBAL FIXED)
# =====================================================
CLASS_LABELS = {
    0: "Cataract",
    1: "Diabetic Retinopathy",
    2: "Glaucoma",
    3: "Normal"
}

RISK_MAP = {
    "Cataract": "Medium",
    "Diabetic Retinopathy": "High",
    "Glaucoma": "High",
    "Normal": "Low",
}

VALID_LABELS = ("Normal", "Cataract", "Glaucoma", "Diabetic Retinopathy")


def normalize_label(label: str) -> str:
    """Map any variant to one of the four clinical diagnoses."""
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
    if "normal" in key or "healthy" in key:
        return "Normal"
    return "Normal"

# =====================================================
# MODEL LOADER
# =====================================================
MODEL_NAMES = ("cnn", "vgg16", "resnet50")
LOADED_AT = None
MODEL_MANIFEST = {}


def model_file_path(name: str) -> str:
    return os.path.join(BASE_DIR, name, f"{name}_final.keras")


def build_model_manifest() -> dict:
    manifest = {}
    for name in MODEL_NAMES:
        path = model_file_path(name)
        if not os.path.exists(path):
            raise FileNotFoundError(f"Model not found: {path}")
        stat = os.stat(path)
        manifest[name] = {
            "path": os.path.abspath(path),
            "size_bytes": int(stat.st_size),
            "modified_unix": int(stat.st_mtime),
            "modified": datetime.fromtimestamp(stat.st_mtime, tz=timezone.utc)
            .astimezone()
            .isoformat(timespec="seconds"),
        }
    return manifest


def load_model_by_name(name):
    path = model_file_path(name)

    if not os.path.exists(path):
        raise FileNotFoundError(f"Model not found: {path}")

    logging.info(f"[MODEL LOAD] {name} -> {path}")
    return tf.keras.models.load_model(path, compile=False)


# =====================================================
# LOAD MODELS (ONCE PER PROCESS — restart API after file swap)
# =====================================================
MODEL_MANIFEST = build_model_manifest()
LOADED_AT = datetime.now().astimezone().isoformat(timespec="seconds")
cnn_model = load_model_by_name("cnn")
vgg_model = load_model_by_name("vgg16")
resnet_model = load_model_by_name("resnet50")
logging.info(f"[MODEL LOAD] All models loaded at {LOADED_AT}")

# =====================================================
# CORE IMAGE PREPROCESS
# =====================================================
def base_preprocess(image: Image.Image):
    image = image.convert("RGB")
    image = image.resize((224, 224))
    img = np.array(image).astype(np.float32)
    return img

# =====================================================
# MODEL-SPECIFIC PREPROCESSING
# =====================================================
def preprocess_for_cnn(img):
    return np.expand_dims(img / 255.0, axis=0)

def preprocess_for_vgg(img):
    return tf.keras.applications.vgg16.preprocess_input(np.expand_dims(img.copy(), axis=0))

def preprocess_for_resnet(img):
    return tf.keras.applications.resnet50.preprocess_input(np.expand_dims(img.copy(), axis=0))

# =====================================================
# SAFE PREDICTION
# =====================================================
def predict_model(model, x):
    pred = model.predict(x, verbose=0)[0]
    pred = np.nan_to_num(pred)
    return pred

# =====================================================
# ENSEMBLE STRATEGY (WEIGHTED)
# =====================================================
def ensemble_predictions(cnn_pred, vgg_pred, resnet_pred):
    w = AI_CONFIG["ensemble_weights"]
    return (
        cnn_pred * w["cnn"]
        + vgg_pred * w["vgg16"]
        + resnet_pred * w["resnet50"]
    )

def get_label(index):
    return normalize_label(CLASS_LABELS.get(int(index), "Normal"))

def get_risk(label):
    return RISK_MAP.get(label, "Unknown")

BENCHMARK_ACCURACY = AI_CONFIG["benchmark_accuracy"]
ENSEMBLE_WEIGHTS = AI_CONFIG["ensemble_weights"]
MODEL_VERSION = AI_CONFIG.get("model_version", {})


def calculate_agreement_metrics(labels, confidences, final_label):
    """Composite agreement from label concordance, benchmark accuracy, and case confidence."""
    models = [
        {"key": "cnn", "label": labels[0], "confidence": confidences[0], "accuracy": BENCHMARK_ACCURACY["cnn"]},
        {"key": "vgg16", "label": labels[1], "confidence": confidences[1], "accuracy": BENCHMARK_ACCURACY["vgg16"]},
        {"key": "resnet50", "label": labels[2], "confidence": confidences[2], "accuracy": BENCHMARK_ACCURACY["resnet50"]},
    ]

    majority = max(set(labels), key=labels.count)
    label_concordance = (labels.count(majority) / len(labels)) * 100

    weighted_confidence = 0.0
    weight_sum = 0.0
    for model in models:
        weight = ENSEMBLE_WEIGHTS[model["key"]]
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

    composite = (
        0.35 * label_concordance
        + 0.35 * agreement_accuracy
        + 0.30 * agreement_confidence
    )

    return {
        "cnn_accuracy": BENCHMARK_ACCURACY["cnn"],
        "vgg_accuracy": BENCHMARK_ACCURACY["vgg16"],
        "resnet_accuracy": BENCHMARK_ACCURACY["resnet50"],
        "agreement_label_pct": round(label_concordance, 2),
        "agreement_accuracy_pct": round(agreement_accuracy, 2),
        "agreement_confidence_pct": round(agreement_confidence, 2),
        "model_agreement_score": round(composite, 2),
    }

@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok",
        "message": "AI API running",
        "pid": os.getpid(),
        "loaded_at": LOADED_AT,
        "models": MODEL_MANIFEST,
        "benchmark_accuracy": BENCHMARK_ACCURACY,
        "ensemble_weights": ENSEMBLE_WEIGHTS,
        "model_version": MODEL_VERSION,
    })

@app.route("/predict", methods=["POST"])
def predict():
    if "image" not in request.files:
        return jsonify({"error": "No image uploaded"}), 400

    file = request.files["image"]

    try:
        image = Image.open(file.stream)
        base_img = base_preprocess(image)

        cnn_input = preprocess_for_cnn(base_img)
        vgg_input = preprocess_for_vgg(base_img)
        resnet_input = preprocess_for_resnet(base_img)

        cnn_pred = predict_model(cnn_model, cnn_input)
        vgg_pred = predict_model(vgg_model, vgg_input)
        resnet_pred = predict_model(resnet_model, resnet_input)

        cnn_class = np.argmax(cnn_pred)
        vgg_class = np.argmax(vgg_pred)
        resnet_class = np.argmax(resnet_pred)

        final_pred = ensemble_predictions(cnn_pred, vgg_pred, resnet_pred)
        final_class = np.argmax(final_pred)
        final_conf = float(np.max(final_pred))

        cnn_label = normalize_label(get_label(cnn_class))
        vgg_label = normalize_label(get_label(vgg_class))
        resnet_label = normalize_label(get_label(resnet_class))
        final_label = normalize_label(get_label(final_class))

        cnn_conf = round(float(np.max(cnn_pred)) * 100, 2)
        vgg_conf = round(float(np.max(vgg_pred)) * 100, 2)
        resnet_conf = round(float(np.max(resnet_pred)) * 100, 2)

        agreement_metrics = calculate_agreement_metrics(
            [cnn_label, vgg_label, resnet_label],
            [cnn_conf, vgg_conf, resnet_conf],
            final_label,
        )

        return jsonify({
            "final_result": final_label,
            "final_confidence": round(final_conf * 100, 2),
            "risk_level": get_risk(final_label),
            "cnn_result": cnn_label,
            "vgg_result": vgg_label,
            "resnet_result": resnet_label,
            "cnn_confidence": cnn_conf,
            "vgg_confidence": vgg_conf,
            "resnet_confidence": resnet_conf,
            **agreement_metrics,
        })

    except Exception as e:
        logging.error(f"Prediction error: {str(e)}")
        return jsonify({
            "error": "Prediction failed",
            "details": str(e)
        }), 500


def _repair_stdio_for_background_launch():
    import sys
    for stream in (sys.stdout, sys.stderr):
        if stream is None:
            continue
        try:
            stream.flush()
        except OSError:
            try:
                devnull = open(os.devnull, "w", encoding="utf-8")
            except OSError:
                devnull = open(os.devnull, "w")
            if stream is sys.stdout:
                sys.stdout = devnull
            else:
                sys.stderr = devnull


if __name__ == "__main__":
    _repair_stdio_for_background_launch()
    api_port = int(os.environ.get("AI_PORT", "5000"))
    logging.info(f"[AI API] Listening on 127.0.0.1:{api_port}")
    app.run(
        host="127.0.0.1",
        port=api_port,
        debug=False,
        use_reloader=False,
    )
