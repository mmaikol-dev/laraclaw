# 24 — Production / Daily Use Tips

## What to do
Optionally configure LaraClaw to start automatically on login and run more reliably on your Linux machine.

---

## Process management with PM2 (recommended)
Instead of running 4 terminals, use PM2 to manage all processes.

Install PM2:
```bash
npm install -g pm2
```

Create `ecosystem.config.js` in the project root:

```
Define 4 apps:
  1. name: "laraclaw-api",    script: "php artisan serve"
  2. name: "laraclaw-reverb", script: "php artisan reverb:start"
  3. name: "laraclaw-horizon",script: "php artisan horizon"
  4. name: "laraclaw-vite",   script: "npm run dev"

Set cwd to the project directory for all.
Set interpreter to "none" for the php/artisan commands.
```

Then:
```bash
pm2 start ecosystem.config.js
pm2 save
pm2 startup   # generates a systemd command to run on boot
```

---

## Building the frontend for production

```bash
npm run build
```

This produces `public/build/` — Laravel will serve assets from there.
In production you no longer need the Vite dev server (remove it from PM2).

---

## Switching to MySQL for production

1. Install MySQL and create a database: `CREATE DATABASE laraclaw;`
2. Update `.env`:
   ```
   DB_CONNECTION=mysql
   DB_DATABASE=laraclaw
   DB_USERNAME=your_user
   DB_PASSWORD=your_password
   ```
3. Run `php artisan migrate:fresh --seed`

---

## Protecting LaraClaw with a password (optional)

Since LaraClaw runs locally and the agent can access your files, you may want basic auth.

Option A — Nginx basic auth in front of the app.

Option B — Add Laravel's built-in auth. The Breeze starter kit already scaffolds registration/login routes. Simply add the `auth` middleware to all `/api/v1` routes in `routes/api.php`.

---

## Keeping Ollama models up to date

```bash
ollama pull glm-5:cloud
ollama pull qwen3-embedding:0.6b
```

Run these periodically to get the latest versions.

---

## Useful artisan commands for maintenance

| Command | What it does |
|---|---|
| `php artisan horizon:pause` | Pause queue processing temporarily |
| `php artisan horizon:continue` | Resume queue processing |
| `php artisan queue:clear --queue=agent` | Clear stuck agent jobs |
| `php artisan cache:clear` | Clear all cached data |
| `php artisan tinker` | Interactive PHP REPL to inspect models |
| `php artisan migrate:fresh --seed` | Wipe and rebuild the database |
