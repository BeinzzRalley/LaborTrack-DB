# LaborTrack API

PHP/MySQL backend for the LaborTrack employee time and attendance system.

## Setup

1. Copy `.env.example` to `.env` and fill in your database credentials:
   ```
   cp .env.example .env
   ```
2. Upload the `backend/` folder to your server.
3. Make sure `.env` sits one level above `backend/` (project root).

## Folder Structure

```
labortrack-api/
├── backend/
│   ├── config/
│   │   └── db.php
│   ├── middleware/
│   │   └── helpers.php
│   └── routes/
│       ├── auth.php
│       ├── accounts.php
│       ├── employees.php
│       ├── departments.php
│       ├── roles.php
│       ├── shift_categories.php
│       ├── time_logs.php
│       ├── leave_records.php
│       └── attendance_status.php
├── .env.example
├── .gitignore
└── README.md
```

## Security Notes
- Never commit `.env` — it contains real DB credentials.
- Passwords are hashed with `PASSWORD_BCRYPT`.
- Only admin accounts can create new accounts.
