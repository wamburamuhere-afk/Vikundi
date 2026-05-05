Create a timestamped MySQL database backup for the Vikundi project.

Database: `vikundi` | Host: `localhost` | User: `root` | Password: (none)

Steps:
1. Generate a timestamp in `YYYY-MM-DD_HH-MM-SS` format
2. Run mysqldump and save the file to `backups/vikundi_backup_<timestamp>.sql`
3. Confirm the backup file exists and report its size
4. Add an entry to `sessions.md` noting the backup was created

Command to run:
```
mysqldump -u root vikundi > backups/vikundi_backup_<timestamp>.sql
```

If mysqldump is not found, check that WAMP MySQL bin is in PATH (`C:\wamp64\bin\mysql\mysqlX.X.X\bin`).

After backup, report: filename, file size, and where it is stored.
