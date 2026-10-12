# Rezka Sync Server

**A small server you host yourself to sync your viewing history between the Rezka apps on your devices.** Start a film on your TV, continue it on your phone from the same second. It works with [rezka-android](https://github.com/lastrowcritic/rezka-android) (phones, tablets, Android TV, Google TV) and [rezka-ios](https://github.com/lastrowcritic/rezka-ios) (iPhone).

Sync is optional. The apps work fully without it and keep everything on the device. We do not run a public sync server, so if you want sync, you host this one. It is a few PHP files and a MySQL database, and it runs on almost any web hosting plan.

## Why PHP

PHP with MySQL is available on nearly every shared hosting plan, including cheap and free ones. You upload the files, create a database and you are done: no Docker, no Node.js, no server to keep running, no command line access needed. The whole server is two PHP classes with no dependencies to install.

## What it stores

- **Accounts:** an email address and a salted, slow hash of the password (PBKDF2-SHA256). The password itself is never stored.
- **Sessions:** a hash of each sign-in token, valid for 30 days. Signing out deletes it.
- **Viewing history:** for each film or episode, the title details the app shows (name, poster, voice-overs, seasons and episodes), the position and when it was watched. At most 500 entries per account.
- **Sign-in attempts:** hashed counters to slow down password guessing. They expire after 15 minutes.

Nothing else is stored or sent anywhere. Your server only talks to the apps you sign in on.

## Requirements

- PHP 8.1 or newer with the `pdo_mysql` and `mbstring` extensions
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` (standard on shared hosting) or another web server that sends every request to `public/index.php`
- HTTPS. The apps refuse to sync over plain HTTP. Most hosts include a free certificate.

## Set it up

1. **Create a database.** In your hosting control panel, create a MySQL database and a user with full access to it. Write down the host, database name, user and password.
2. **Create the tables.** Open the database in phpMyAdmin (or any MySQL client) and import [`migrations/0001_mysql.sql`](migrations/0001_mysql.sql).
3. **Upload the files.** Download this repository ([Code → Download ZIP](https://github.com/lastrowcritic/rezka-sync-server/archive/refs/heads/main.zip)) and upload the folder to your hosting. You have two options:
   - **Best:** upload it outside the public web folder, for example to `rezka-sync/`, and point a domain or subdomain (such as `sync.example.com`) at its `public/` folder. Then only `public/index.php` can be reached from the internet.
   - **Simpler:** upload it into the public web folder, for example to `public_html/rezka-sync/`. The included `.htaccess` files send every request to `public/` and block the other files. Your server address is then `https://example.com/rezka-sync`.
4. **Configure it.** Copy `config.example.php` to `config.php` in the same folder and fill in the database details. For `session_pepper`, use a long random secret. You can make one with:

   ```sh
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```

   or with any password generator (at least 32 characters). Set `allow_registration` to `true` for now. You can also leave the file as it is and set the `REZKA_DB_HOST`, `REZKA_DB_NAME`, `REZKA_DB_USER`, `REZKA_DB_PASSWORD` and `REZKA_SESSION_PEPPER` environment variables instead.
5. **Turn on HTTPS** for the domain in your hosting panel.
6. **Check it.** Open `https://your-server-address/health` in a browser. You should see `{"ok":true,"service":"watch-sync","registration":true}`.
7. **Connect the apps.** In the app, open **Settings → Sync across devices**, enter your server address (for example `https://sync.example.com`) and apply it. Create an account on the first device, then sign in with the same email and password on the others.
8. **Close sign-ups.** Once your accounts exist, set `allow_registration` back to `false` in `config.php`, so strangers who find your server cannot create accounts. Signing in keeps working.

Keep `config.php` private and never commit it. If you change `session_pepper`, everyone is signed out.

## Updating

Upload the new files over the old ones, keeping your `config.php`. When a release adds a new file to `migrations/`, import it as well.

## API

All routes accept and return JSON. Routes under `/v1/history` and `/v1/auth/logout` need an `Authorization: Bearer <token>` header.

| Route | What it does |
| --- | --- |
| `GET /health` | Shows that the server works and whether sign-ups are open |
| `POST /v1/auth/register` | Creates an account (`email`, `password` of at least 10 characters) when sign-ups are open |
| `POST /v1/auth/login` | Signs in and returns a session token |
| `POST /v1/auth/logout` | Ends the current session |
| `GET /v1/history` | Returns the saved viewing history |
| `PUT /v1/history` | Merges `records` into the saved history. The newest version of each episode wins |
| `DELETE /v1/history` | Clears the history on every device |

## Tests

The tests run the server against a temporary SQLite database, so they need PHP with `pdo_sqlite` but no MySQL:

```sh
php tests/run.php
```

## Disclaimer

This server only stores viewing progress for the apps. It does not host, proxy or distribute any films, series or other media, and it has no connection to the HDRezka website. We are not responsible for how you run or use it.

## Keywords

HDRezka sync, Rezka sync server, sync HDRezka history between devices, self-hosted watch history sync, PHP MySQL sync server for shared hosting. Синхронизация истории просмотра HDRezka между устройствами, свой сервер синхронизации.
