import os
import tensorflow as tf

def load_selected_model(model_name):
    path = os.path.join("ai", "models", f"{model_name}_final.keras")
    
    if not os.path.exists(path):
        raise FileNotFoundError(f"Model not found: {path}")
    
    return tf.keras.models.load_model(path)