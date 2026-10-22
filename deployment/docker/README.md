# JAVCMS Docker profile

Status: this profile built and ran on a private Linux Docker engine with PHP 8.4.26, Nginx 1.30.5 and MariaDB 11.4.13. The finite reference run passed 39 neutral checks, six fresh-installer checks and 37 installed checks both before and after PHP/web container recreation. Schema and administrator password hashing were verified, and `database.php`, `maccms.php` and `install.lock` retained their exact hashes after recreation. This does not certify arbitrary hosts, hosting-panel UIs, remote integrations or real media decoding. Run the acceptance checks on your own deployment.

This recipe uses digest-pinned official PHP 8.4.26 FPM (Bookworm), Nginx 1.30.5 and MariaDB 11.4 images. The PHP builder compiles GD, ZIP and pdo_mysql; the runtime checks PDO/XML/mbstring/cURL/fileinfo/ctype/OpenSSL and fails on module startup warnings. Compilation packages stay out of the final runtime. PHP runs as the app user; Nginx runs as UID/GID101 on port8080.

The community installer edits configuration files, so PHP deliberately binds the extracted source at `/var/www/javcms` read/write. Nginx binds the same source read-only. Source, generated settings, uploads and runtime data therefore survive container recreation on the host. Only the small `deployment/docker` directory is the build context; no application configuration or password file is copied into an image. PHP receives neither database root secret nor Docker socket. The database has no published port and is on an internal network with a persistent named volume.

## Host preparation

Use a clean extracted source and Linux Docker/Compose v2. Set APP_UID and APP_GID to an available host service account (default1000). These IDs must be unused in the base image; build fails on collisions. Make the extracted source readable by that account and the Nginx UID101, and allow the app account to write `application` during installation, `application/extra`, `runtime`, `upload`, and `application/data/backup`, `application/data/update`, `application/data/install`, plus the existing configuration files checked by the installer. Ownership/ACLs must cover both directory creation and atomic config replacement. Do not use world-writable777 permissions. Check service-account ownership after extracting or restoring an archive. Shared-host/panel accounts may need a named group/ACL rather than changing all source ownership.

Create two different strong password files outside the project and webroot, readable only by the Docker operator. Set their **absolute paths**, not their contents:

```sh
export JAVCMS_DB_PASSWORD_FILE=/secure/javcms-db-password
export JAVCMS_DB_ROOT_PASSWORD_FILE=/secure/javcms-root-password
export APP_UID=1000 APP_GID=1000
cd deployment/docker
docker compose config --quiet
docker compose build
docker compose up -d
```

Optional non-secret settings: JAVCMS_DB_NAME and JAVCMS_DB_USER (both defaultjavcms), JAVCMS_HTTP_PORT (default8080, published on127.0.0.1 only). The database files initialize only an empty volume; changing environment/password files later does not automatically migrate an existing database account. Keep password files out of archives and source control.

Open `http://127.0.0.1:8080/install.php`. Use database host`db`, port3306, the configured database/user, and the application password from your external password file; choose an unused table prefix and administrator account. Do not use the database root account for the installer. The installer persists the application database password in `application/database.php`, so protect host source backups and that file. Readiness checks cover database startup and the FPM listening socket, not successful installation or application correctness.

The source requires PHP>=7.4 with no upper cap; this image recipe selects one8.4patch, not all PHP branches. Host/FPM request limits and remote integration connectivity need deployment testing. Entry files request their own memory/time settings; adjust PHP/FPM administrative limits intentionally if enforcing resource budgets. PHP/Nginx temporary files and default sessions use container-local `/tmp`; uploads/config/database persist but sessions are ephemeral when the PHP container is replaced.

## Routing and acceptance

`nginx.conf` renders the shared Nginx profile for root installation, four allowlisted entrypoints and `php:9000`. Shared FastCGI parameters are read-only mounted from `deployment/nginx/fastcgi-params.conf`. Query arguments, request method/body and PATH_INFO must remain intact; no generic PHP or upload execution handler is configured. A renamed administrator entry or subdirectory installation requires a reviewed regenerated profile, not only changing a URL.

Before publishing beyond loopback, execute: `docker compose exec web nginx -t`, `docker compose exec php php -m`, a clean installer run, generated home/detail/search routes, admin/login/captcha, default-closed API, explicit/?s= routes and forbidden private-path/upload probes. Real media/integrations need their own checks. Configure TLS/reverse-proxy host trust deliberately; this local profile does not supply public TLS.

Back up the host source/config/uploads and MariaDB named volume using a consistent database backup method before upgrades. `docker compose restart` preserves data. Removing the named database volume is destructive and is not a routine update step.

Primary implementation reference: [official PHP image Dockerfile](https://github.com/docker-library/php/blob/master/8.4/bookworm/fpm/Dockerfile). Exact reviewed image digest evidence is recorded privately in container-image-pins.json.
