from flask import Flask, request, jsonify
import tensorflow as tf
import numpy as np
from PIL import Image
import os
import logging

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
def load_model_by_name(name):
    path = os.path.join(BASE_DIR, name, f"{name}_final.keras")

    if not os.path.exists(path):
        raise FileNotFoundError(f"Model not found: {path}")

    logging.info(f"[MODEL LOAD] {name} -> {path}")
    return tf.keras.models.load_model(path, compile=False)

# =====================================================
# LOAD MODELS (ONE TIME)
# =====================================================
cnn_model = load_model_by_name("cnn")
vgg_model = load_model_by_name("vgg16")
resnet_model = load_model_by_name("resnet50")

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
    return (
        cnn_pred * 0.30 +
        vgg_pred * 0.35 +
        resnet_pred * 0.35
    )

def get_label(index):
    return normalize_label(CLASS_LABELS.get(int(index), "Normal"))

def get_risk(label):
    return RISK_MAP.get(label, "Unknown")

def calculate_agreement(labels):
    final = max(set(labels), key=labels.count)
    return (labels.count(final) / len(labels)) * 100

@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok",
        "message": "AI API running"
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

        agreement = calculate_agreement([cnn_label, vgg_label, resnet_label])

        return jsonify({
            "final_result": final_label,
            "final_confidence": round(final_conf * 100, 2),
            "risk_level": get_risk(final_label),
            "cnn_result": cnn_label,
            "vgg_result": vgg_label,
            "resnet_result": resnet_label,
            "cnn_confidence": round(float(np.max(cnn_pred)) * 100, 2),
            "vgg_confidence": round(float(np.max(vgg_pred)) * 100, 2),
            "resnet_confidence": round(float(np.max(resnet_pred)) * 100, 2),
            "model_agreement_score": round(agreement, 2)
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
    app.run(
        host="127.0.0.1",
        port=5000,
        debug=False,
        use_reloader=False,
    )
