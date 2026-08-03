# School ERP System

A MyStack PHP application with a public website, admission and result lookup flows, notices, authentication, and role-protected dashboard shells.

## Requirements

- PHP 8.1 or newer with `mysqli`, `curl`, `mbstring`, and `openssl`
- MySQL or MariaDB
- No Composer, NPM, or third-party runtime dependencies

## Configuration

The application reads configuration from process environment variables:

| Variable | Required | Default |
| --- | --- | --- |
| `APP_ENV` | Production only | `development` |
| `APP_DEBUG` | No | `true` in development; `false` in production |
| `APP_KEY` | Production; minimum 32 characters | Machine-local development key |
| `JWT_KEY` | Production; minimum 32 characters | Machine-local development key |
| `DB_HOST` | No | `localhost` |
| `DB_USERNAME` | No | `root` |
| `DB_PASSWORD` | No | `root` |
| `DB_DATABASE` | No | `mystack_app_db` |
| `CORS_ORIGIN` | Only for cross-origin access | Disabled |

Do not commit production keys or database passwords.

## Database

Create the database and import [database_schema.sql](database_schema.sql). User passwords must be created with PHP's `password_hash()` output. The `users.token` column is required by the framework-native authentication flow.

## Run locally

From the project root:

```powershell
$env:APP_ENV = 'development'
$env:APP_DEBUG = 'true'
php -S 127.0.0.1:8000 index.php
```

Then open `http://127.0.0.1:8000`.

## Verification

The doctor command is read-only by default:

```powershell
php mystack doctor
```

Safe formatting and permission fixes require an explicit flag:

```powershell
php mystack doctor --fix
```

Application and component files follow MyStack's flat-directory convention. Framework files remain in `library/`, views in `component/`, backend classes in `app/`, and browser assets in `src/`.
