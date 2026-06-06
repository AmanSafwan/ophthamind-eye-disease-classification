# GitHub publish checklist

The project is committed locally on branch `main`. Complete these steps once to publish:

## 1. Create the repository on GitHub

1. Sign in to [github.com/AmanSafwan](https://github.com/AmanSafwan)
2. Click **New repository**
3. **Repository name:** `ophthamind-eye-disease-classification`
4. **Description:** `FYP — Eye disease classification using deep learning (CNN, VGG16, ResNet50 ensemble) with clinical web workflow`
5. Set visibility: **Public** (recommended for employers / viva panel)
6. Do **not** add README, `.gitignore`, or license (already in this project)
7. Click **Create repository**

## 2. Push from your machine

```bash
cd C:\xampp\htdocs\eye_system
git remote add origin https://github.com/AmanSafwan/ophthamind-eye-disease-classification.git
git push -u origin main
```

If `origin` already exists:

```bash
git remote set-url origin https://github.com/AmanSafwan/ophthamind-eye-disease-classification.git
git push -u origin main
```

## 3. Pin the repository on your GitHub profile

On your profile → **Customize your pins** → select this repo so recruiters see it first.

## 4. Optional polish

- Add screenshots under `docs/screenshots/` and link them in `README.md`
- Add topics on GitHub: `deep-learning`, `tensorflow`, `flask`, `php`, `healthcare-ai`, `fyp`, `computer-vision`
- Attach model weights separately or via Git LFS if you need them on GitHub
