# Deploy to 076790.unisza.work

## Upload package

```
C:\xampp\htdocs\eye_system\deploy\ophthamind-hosting.zip   (~367 MB)
```

SQL dump (for manual import or new builds):

```
C:\xampp\htdocs\eye_system\deploy\eye_system_hosting.sql
```

## Steps (~5 minutes)

1. Log in at **onceamonth.work** → **My Hosting/Domain** → open **076790.unisza.work**
2. **File Manager** → **Upload** → select `ophthamind-hosting.zip`
3. **Extract** the zip (your site may be in `ophthamind-hosting/` subfolder — that is OK)
4. In the hosting panel, create a **MySQL database + user** (note name, user, password)
5. Open: **http://076790.unisza.work/ophthamind-hosting/hosting_setup.php**
   - Site URL should auto-fill as `http://076790.unisza.work/ophthamind-hosting`
6. Enter DB credentials → **Test connection** → tick **Import bundled database** (if available) → **Save & finish setup**
7. Click **Open OphthaMind**

If there is no import checkbox, import `eye_system_hosting.sql` via phpMyAdmin first, then run setup without ticking import.

## Local XAMPP (unchanged)

- URL: http://localhost/eye_system
- Your laptop `.env` stays the same (`ENABLE_AI=true`, port 3307)
- AI predict still works locally

## Notes

- Hosting: website + database + login OK; **AI predict offline** (shared hosting does not run Python)
- For live AI demo during presentation, use XAMPP on your laptop
