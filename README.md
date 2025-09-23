# mobilo

## Quick test: Hello World page

Open `index.html` in your browser to verify the project opens correctly. It shows a simple "Hello, World!" message.

## Local PHP auth demo

Setup:

```powershell
cd C:\Users\Admin\mobilo
& "C:\\php\\php.exe" -v  # ensure PHP works
php -v                       # if in PATH
```

Initialize database (creates `app.sqlite` and seeds demo user `demo/demo123`):

```powershell
php init_db.php | cat
```

Run local server:

```powershell
php -S 127.0.0.1:8000 -t .
```

Pages:
- `index.html` — login form posts to `login.php`
- `dashboard.php` — protected page after login
- `logout.php` — end session
- `page_1.php` — sample secondary page

