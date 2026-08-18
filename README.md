# Daily Task Manager + Google Sheet

A local PHP + SQLite task-performance dashboard that can read the user's existing daily Google Sheet without Node.js, MySQL, or a Google API key.

## Run on Windows

1. Install XAMPP if PHP is not already installed.
2. Extract the ZIP.
3. Double-click `start.bat`.
4. Open `http://localhost:8000`.

PowerShell command:

```powershell
.\start.bat
```

Default admin:

- Email: `admin@example.com`
- Password: `Admin123!`

The SQLite database is created automatically at `data/task_manager.sqlite`.

## Connect the existing daily Google Sheet

Open the exact Google Sheet tab you want to connect and share it as **Anyone with the link → Viewer**. Copy the normal Sheet URL, then open **Google Sheet** in the application, paste the link, test it, save it, and press **Sync Now**.

The connector reads the selected tab through its `gid`; no Google App ID, secret, OAuth client, Node.js service, or API key is required for this simple public-viewer setup.

### Column mapping

| Google Sheet | Application |
|---|---|
| Date. | Task Date |
| Day | Day |
| Name | Client / Brand |
| Type | Task Type / Category |
| POC | POC |
| Tasks | Task Description |
| Content Responsible | Content Responsible |
| Responsible editor | Employee used for analytics |
| Reference links | Reference Link |
| Time Taken ( Videos) | Time Taken |
| Priority | Priority |
| Remarks filled by editors | Task Status |
| Editors Remarks | Editor Remarks |
| Acc manager remark | Account Manager Remark |
| Manager Remark | Manager Remark |

Status normalization: Done → Completed, WIP → In Progress, Pending → Pending, blank → Not Started, Blocked/Hold → Blocked, Cancelled → Cancelled.

Priority normalization: Urgent/Critical → Critical, High/Imp → High, Medium → Medium, Low → Low, blank → Medium.

## Automatic sync

The browser checks the PHP backend regularly. The backend respects the selected interval (30 seconds to 10 minutes). On a successful sync it replaces the previous Google-Sheet snapshot in SQLite and recalculates all dashboard analytics from the latest Sheet data.

Google Sheet tasks are read-only in the application. Edit them in Google Sheets. Manual tasks created inside the Tasks page remain editable.

## XAMPP extensions

SQLite requires `pdo_sqlite`. Google Sheet fetching works best with `curl`.

In `C:\xampp\php\php.ini`, make sure these are enabled:

```ini
extension=pdo_sqlite
extension=sqlite3
extension=curl
```

Restart the local PHP process after changing `php.ini`.
