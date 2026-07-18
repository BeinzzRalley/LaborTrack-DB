# LaborTrack API

PHP/MySQL backend for the LaborTrack employee time and attendance system.

## Setup

1. Copy `.env.example` to `.env` and fill in your database credentials:
   ```
   cp .env.example .env
   ```
2. Upload the `backend/` folder to your server.
3. Make sure `.env` sits one level above `backend/` (project root).
4. Run any new files in `backend/migrations/` against your database, in order:
   ```
   mysql -u <user> -p <database> < backend/migrations/001_create_audit_log.sql
   ```

## Folder Structure

```
labortrack-api/
├── backend/
│   ├── config/
│   │   └── db.php
│   ├── middleware/
│   │   └── helpers.php
│   ├── migrations/
│   │   └── 001_create_audit_log.sql
│   └── routes/
│       ├── auth.php
│       ├── accounts.php
│       ├── audit_log.php
│       ├── employees.php
│       ├── departments.php
│       ├── roles.php
│       ├── shift_categories.php
│       ├── time_logs.php
│       ├── leave_records.php
│       ├── payroll.php
│       ├── reports.php
│       ├── dashboard.php
│       ├── overtime_categories.php
│       └── attendance_status.php
├── .env.example
├── .gitignore
└── README.md
```

## Audit Log
- `audit_log` table (see `migrations/001_create_audit_log.sql`) records who did what, to which record, and when.
- Entries are written automatically via `logAudit()` in `middleware/helpers.php` whenever:
  - an account is created, updated, or deleted (`routes/accounts.php`)
  - a payroll period is approved or unapproved (`routes/payroll.php`)
- `GET routes/audit_log.php` (admin only) lists/filters entries — see the header comment in that file for query params.
- Writing an audit entry never blocks or fails the action it's logging; a logging error only gets written to the PHP error log.

## Security Notes
- Never commit `.env` — it contains real DB credentials.
- Passwords are hashed with `PASSWORD_BCRYPT`.
- Only admin accounts can create new accounts.
