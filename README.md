# C-Attend

## Version 1 Documentation

C-Attend is a PHP and MySQL/MariaDB attendance portal for students, administrators, and maintenance staff. Students can register, sign in, and submit attendance for eligible sessions using browser-provided GPS coordinates. Administrators can manage sessions, students, and attendance records.

## Main Features

- Student registration and login
- Argon2id password hashing
- CSRF checks on selected state-changing forms
- Privacy policy page and recorded student consent
- Local password reset codes
- Class A and Class B student grouping
- Attendance sessions targeted to Class A, Class B, or both
- Separate portal-only GPS sessions with downloadable, retained attendance PDFs
- Server-calculated GPS distance checks
- Configurable attendance radius
- Attendance session expiry
- Student present and absent history
- Live attendance counts for administrators
- Admin student-management directory
- Student attendance-rate calculation
- Attendance-risk metric for administrators
- Main admin and class-specific sub-admin roles
- Mobile-oriented page layouts
- Portal alert logging
- CSV and PDF attendance report export

## Requirements

- PHP 8.1 or newer
- MySQL 8 or MariaDB 10.4 or newer
- PDO MySQL extension
- Apache or another PHP-compatible web server
- Browser geolocation permission for student attendance marking

## Project Structure

```text
C-attend/
├── admin/                 # Admin and sub-admin pages and report endpoints
├── student/               # Student portal pages
├── maintenance/           # Maintenance staff pages and operations
├── includes/               # Shared application setup, helpers, styles, and features
│   ├── bootstrap.php       # Shared database and helper loading entry point
│   ├── functions.php       # Shared application helpers
│   ├── portal_sessions.php # Portal-only attendance and PDF behavior
│   └── *.css               # Shared UI styles
├── config/
│   └── db.php              # Database connection and environment configuration
├── scripts/                # CLI-only scheduled jobs
├── assets/                 # Compiled styles and images
├── index.php               # Student sign-in and registration entry point
├── README.md               # Setup, operation, and project map
└── *.sql                   # Database installation/export files
```

### Where to make changes

- **Database connection:** `config/db.php`. Portal pages should load this through `includes/bootstrap.php`.
- **Shared behavior:** `includes/functions.php`; keep helpers reusable instead of copying them into portal pages.
- **Student, admin, and maintenance UI:** make portal-specific changes in the matching directory.
- **Portal-only attendance and saved PDFs:** `includes/portal_sessions.php` and the related admin/student pages.
- **Scheduled work:** add CLI-only jobs under `scripts/`.
- **Shared styling:** `includes/` for authored styles and `assets/` for deployed/compiled assets.

Portal entry points use `require_once __DIR__ . '/../includes/bootstrap.php'` (or the equivalent root-relative path) to load the database connection and shared helpers. Feature-specific helpers such as `portal_sessions.php` are loaded separately by pages that use them.

## Installation

1. Copy the project into the web server document root.
2. Create the main database named `cattxziy_cattend`.
3. Import [cattxziy_cattend.sql](cattxziy_cattend.sql).
4. Configure database credentials with environment variables:

```text
DB_HOST=localhost
DB_NAME=cattxziy_cattend
DB_USER=your_database_user
DB_PASS=your_database_password
```

5. Open `/admin/setup.php` once to create the first main administrator. It returns `404 Not Found` after an administrator exists.
6. Open `/maintenance/setup.php` once to create the maintenance account. It returns `404 Not Found` after a maintenance account exists.
7. Open the student portal at `/index.php`.

For production, set `APP_ENV=production` and configure `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`. Production rejects blank passwords and the `root` database user. When `APP_ENV` is not set, local XAMPP development uses the existing `localhost` / `cattxziy_cattend` / `root` / blank-password defaults; never use those defaults in production.

## User Roles

### Main administrator

The main administrator can:

- Create courses
- Create Class A, Class B, or combined attendance sessions
- End attendance sessions
- View live attendance counts
- Manage students
- Edit student names, phone numbers, and classes
- View student attendance history
- Mark attendance manually
- View reports and export attendance data
- Create Class A or Class B sub-admin accounts

### Class sub-admin

A sub-admin is assigned to either Class A or Class B by the main administrator. It can:

- Log in to the admin portal
- Create attendance sessions for its assigned class
- Manually mark students in its assigned class
- View shared attendance information available through the admin portal

Sub-admin restrictions are enforced server-side. A sub-admin cannot create sessions for another class or combined sessions, and cannot manually mark students outside its assigned class.

## Student Flow

1. A student registers with:
   - Unique index number
   - Full name
   - Phone number
   - Class A or Class B
   - Password
2. The student signs in through `index.php`.
3. The student sees active sessions for their class.
4. The student enables browser GPS and submits attendance.
5. The server calculates the distance between the session location and the student location.
6. The server compares the calculated distance with the configured radius and the existing GPS-accuracy tolerance; qualifying check-ins are recorded.
7. Marked sessions disappear from the active list.
8. Completed sessions appear in history as Present or Absent.

Index numbers are normalized to uppercase, spaces are removed, and the database has a unique constraint to prevent duplicates.

## Attendance Sessions

An attendance session contains:

- Course
- Target class
- Class representative GPS coordinates
- Radius in meters
- Start time
- End time
- Active status

New sessions accept a radius from 50 to 2,000 meters. Their initial radius comes from the Maintenance dashboard setting; when no valid value has been saved, the application uses 300 meters. Changing the default does not alter existing sessions.

When the end time passes, the cleanup routine deactivates the session:

- The session is deactivated.
- It disappears from student and admin active-session lists.
- Students cannot mark it.
- Administrators can still mark attendance manually for up to 24 hours after the end time.
- After the 24-hour manual-marking window closes, attendance changes are rejected.
- A student who did not attend receives an Absent history entry.

Session geofences expire at midnight on the day they were created. The Maintenance dashboard owns the daily reset and shows the last reset time, remaining previous-day active sessions, and a CSRF-protected manual reset control. The scheduled cleanup script runs the same reset even when nobody is using the portal. Student check-in also rejects sessions created before the current database date, so yesterday's geofence cannot be used if the scheduled reset is delayed. The reset deactivates prior-day sessions without changing their scheduled end time, preserving the existing administrator manual-attendance window and portal-only PDF retention period. Create a new session and capture its geofence location for each new day. The database/application timezone should match the institution's local timezone so the reset occurs at the intended midnight.

Maintenance staff can set the default geofence radius from the Maintenance dashboard. It is prefilled for new standard and portal-only sessions created in the admin portal; each admin may adjust the value per session. The allowed range is 50–2,000 meters, and changing the default does not alter existing sessions.

## Portal-only Attendance Sessions

Main administrators and sub-admins can create separate GPS-based sessions from `admin/portal_sessions.php`. These use dedicated `portal_only_sessions` and `portal_only_attendance` tables and are excluded from standard attendance reports and student attendance history.

When a portal-only session ends, its attendance PDF is saved in the database and remains available to view or download without being removed by downloads. Admins can permanently delete a session and its saved PDF. Ended sessions and PDFs are automatically removed 24 hours after the session end.

To run scheduled cleanup when nobody is using the portal, configure a cPanel cron job on Namecheap to run every five minutes:

```text
*/5 * * * * /usr/local/bin/php /home/CPANEL_USER/public_html/scripts/cleanup_portal_sessions.php
```

Replace `CPANEL_USER` with the hosting account username and use the PHP CLI path shown by Namecheap/cPanel if it differs. The script is CLI-only; web requests to it return 404. Portal pages that handle portal-only sessions may finalize ended-session PDFs and remove expired portal-only records when those pages are opened. They do not replace the scheduled daily geofence reset.

The Maintenance dashboard also runs the reset when opened, as a catch-up if the cron was delayed. Student and administrator portals do not perform the daily reset. The cron process must have the same `APP_ENV`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` environment configuration as the web application. Configure these through the hosting provider's supported environment-variable mechanism; do not put database credentials directly in the cron command.

## GPS Verification

The student browser sends:

- Latitude
- Longitude
- Reported GPS accuracy

C-Attend validates that coordinates are numeric and within latitude/longitude bounds, requires a finite nonnegative reported accuracy, and uses the Haversine formula on the server to calculate distance. Reported accuracy contributes a bounded tolerance of 25–200 meters against the configured session radius. The browser requests a fresh, high-accuracy reading and rejects a reading older than 30 seconds before submission. These checks can reject invalid readings, but browser-provided coordinates and accuracy can be altered and do not prove a device's physical location. GPS permission must be enabled, and the device must provide valid coordinates.

For a large classroom or campus area, the administrator should use a larger radius rather than repeatedly recreating sessions.

## Attendance History and Rates

Student history includes:

- Course
- Session date
- Session time
- Present, Pending, or Absent status
- Marked time
- Distance where available

Student attendance rate is calculated from completed sessions eligible for that student’s class:

```text
attended sessions / completed eligible sessions × 100
```

The admin dashboard attendance-risk metric reviews completed sessions from the last 30 days and flags students absent for at least 25% of eligible sessions.

## Admin Reports

The reports page provides attendance summaries by course and student. It offers CSV and PDF downloads for daily, weekly, monthly, and selected-date reports.

## Security

The inspected application code uses PDO for database access, Argon2id password hashing, CSRF checks on several state-changing forms, and server-side role checks in admin workflows. Session cookie options such as HttpOnly and SameSite are configured in portal entry points. These implementation details are not a substitute for a security audit or production deployment review.

For production, also use HTTPS, a dedicated database user, a strong database password, secure cookie settings, backups, and server-level rate limiting.

## Deployment Checklist

- Configure production database environment variables.
- Do not use the MySQL `root` account.
- Do not use an empty database password.
- Enable HTTPS.
- Import the schema and verify required columns.
- Remove sample/test records from the SQL dump.
- Create the first main administrator.
- Create sub-admin accounts only when needed.
- Test student registration and login.
- Test admin login and role restrictions.
- Test GPS attendance on real phones.
- Test expired sessions and absent history.
- Test live attendance updates.
- Test report downloads in CSV and PDF formats.
- Back up the database before launch.

## Known Version 1 Limitations

- Attendance uses browser GPS and depends on device accuracy.
- Standard attendance sessions do not store which admin created them. Portal-only sessions do store their creator.
- No email or SMS notification service is enabled.
- Portal-only session PDFs are separate from standard attendance reports.
- Deployment-specific behavior, including hosting configuration and scheduled jobs, must be verified in the target environment.

## Local Validation

Validate PHP syntax from the project directory:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    C:\xampp\php\php.exe -l $_.FullName
}
```

## Version 1 Scope

The current version includes class-based session targeting, attendance history, administrative pages, and portal-only session PDFs. Additional audit logging, notification services, and administrator permissions may be considered separately.
