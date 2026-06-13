# Trained model weights

Place your exported Keras models in this directory before running the AI service:

```
ai/models/
├── cnn/cnn_final.keras
├── vgg16/vgg16_final.keras
└── resnet50/resnet50_final.keras   ← replace & restart AI API after retrain
```

Benchmark accuracy lives in `config/ai_models.php` (and `config/ai_models.json` for Python).
Re-run after a model swap:

```
venv\Scripts\python.exe scripts\evaluate_models.py --splits full
```

Latest full eval (2026-06-13): CNN 51.53% · VGG16 91.01% · **ResNet50 95.54%** · Ensemble 95.21%
