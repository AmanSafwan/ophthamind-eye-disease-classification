# OphthaMind AI Setup

## Database (.env)
```
DB_HOST=127.0.0.1
DB_PORT=3307
DB_USER=root
DB_PASS=
```

## PDF reports (optional)
Run in project root:
```
composer install
```
Without Composer, exports download as printable HTML reports.

## AI engine
The web app auto-starts `ai_api/app.py` on port 5000 when needed.
Ensure Python venv has: `flask`, `tensorflow`, `pillow`, `numpy`.
