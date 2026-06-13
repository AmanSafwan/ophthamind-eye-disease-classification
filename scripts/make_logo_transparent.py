"""
Remove solid blue background from OphthaMind logo → transparent PNG.
"""
from pathlib import Path
from PIL import Image
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "assets" / "images" / "ophthamind-logo.png"
OUT = ROOT / "assets" / "images" / "ophthamind-logo.png"
BACKUP = ROOT / "assets" / "images" / "ophthamind-logo-source.png"


def color_distance(rgb, target):
    return np.sqrt(np.sum((rgb.astype(np.float32) - target) ** 2))


def remove_blue_background(img: Image.Image, tolerance: float = 42.0) -> Image.Image:
    img = img.convert("RGBA")
    data = np.array(img)
    rgb = data[:, :, :3]

    # Sample corners for background color (logo has solid blue fill)
    h, w = rgb.shape[:2]
    corners = np.vstack([
        rgb[0:8, 0:8].reshape(-1, 3),
        rgb[0:8, w - 8:w].reshape(-1, 3),
        rgb[h - 8:h, 0:8].reshape(-1, 3),
        rgb[h - 8:h, w - 8:w].reshape(-1, 3),
    ])
    bg = np.median(corners, axis=0)

    dist = np.sqrt(np.sum((rgb.astype(np.float32) - bg) ** 2, axis=2))

    # Also remove pixels close to common logo blues
    for alt in ([26, 83, 161], [30, 91, 163], [23, 79, 155], [21, 74, 148]):
        d2 = color_distance(rgb, np.array(alt))
        dist = np.minimum(dist, d2)

    alpha = data[:, :, 3].astype(np.float32)
    alpha[dist < tolerance] = 0

    # Soft edge anti-alias for pixels near threshold
    edge = (dist >= tolerance) & (dist < tolerance + 18)
    alpha[edge] = np.clip((dist[edge] - tolerance) / 18.0 * 255, 0, 255)

    data[:, :, 3] = alpha.astype(np.uint8)
    return Image.fromarray(data, "RGBA")


def trim_transparent(img: Image.Image, pad: int = 8) -> Image.Image:
    bbox = img.getbbox()
    if not bbox:
        return img
    left, top, right, bottom = bbox
    left = max(0, left - pad)
    top = max(0, top - pad)
    right = min(img.width, right + pad)
    bottom = min(img.height, bottom + pad)
    return img.crop((left, top, right, bottom))


def main():
    if not SRC.exists():
        raise SystemExit(f"Missing {SRC}")

    if not BACKUP.exists():
        import shutil
        shutil.copy2(SRC, BACKUP)

    img = Image.open(BACKUP if BACKUP.exists() else SRC)
    out = remove_blue_background(img)
    out = trim_transparent(out, pad=10)
    out.save(OUT, "PNG", optimize=True)
    print(f"Saved transparent logo: {OUT} ({out.size[0]}x{out.size[1]})")


if __name__ == "__main__":
    main()
