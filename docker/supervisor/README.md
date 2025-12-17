These are Supervisor configurations for running the long‑living RabbitMQ consumers of this project.

Included programs:
- `rabbit_consume_updates` — runs `php /app/yii rabbit:consume-updates`
- `rabbit_consume_pushes` — runs `php /app/yii rabbit:consume-pushes`
- `rabbit_consume_profile_prompt` — runs `php /app/yii rabbit:consume-profile-prompt`

How to use on a host with Supervisor:
1. Ensure PHP and the project are available under `/app` (or adjust `directory`/`command` paths in the `.conf` files).
2. Copy all `*.conf` files to your Supervisor config directory, e.g.:
   - `/etc/supervisor/conf.d/`
3. Reload Supervisor:
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   ```
4. Manage processes:
   ```bash
   sudo supervisorctl status
   sudo supervisorctl start rabbit_consume_updates
   sudo supervisorctl start rabbit_consume_pushes
   sudo supervisorctl start rabbit_consume_profile_prompt
   # or manage the whole group:
   sudo supervisorctl start consumers:
   ```

Notes:
- Logs go to Docker-friendly stdout/stderr (`/dev/stdout`, `/dev/stderr`). If you prefer files, change `stdout_logfile`/`stderr_logfile` to point to real files and ensure the directories exist.
- `APP_ENV` is set to `prod` by default in configs. The app also loads `.env` from the project root if present; adjust as needed.
- If you run Supervisor inside the provided Docker images, `www-data` is the runtime user in prod and `appuser` in dev. Uncomment/set the `user=` line accordingly.
