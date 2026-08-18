<?php
require_once __DIR__ . '/config.php';
try { db(); $dbError=null; } catch(Throwable $e) { $dbError=$e->getMessage(); }
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Daily Task Manager</title>
  <link rel="stylesheet" href="assets/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body>
<?php if($dbError): ?>
<div class="fatal">
  <h1>One small setup is needed</h1>
  <p><?=htmlspecialchars($dbError)?></p>
  <p><b>Windows/XAMPP:</b> open <code>php.ini</code>, find <code>;extension=pdo_sqlite</code>, remove the semicolon, save, and restart PHP/Apache.</p>
</div>
<?php else: ?>
<div id="loginScreen" class="login-wrap">
  <form id="loginForm" class="login-card">
    <div class="brand-mark">DT</div>
    <h1>Daily Task Manager</h1>
    <p>Login to see tasks and automatic performance.</p>
    <label>Email<input id="loginEmail" type="email" value="admin@example.com" required></label>
    <label>Password<input id="loginPassword" type="password" value="Admin123!" required></label>
    <button>Login</button>
    <div id="loginMsg" class="msg"></div>
    <small>Default admin: admin@example.com / Admin123!</small>
  </form>
</div>

<div id="app" class="app hidden">
  <aside class="sidebar">
    <div class="logo"><span>DT</span><b>Task Manager</b></div>
    <nav>
      <button data-page="dashboard" class="nav active">Dashboard</button>
      <button data-page="tasks" class="nav">Tasks</button>
      <button data-page="employees" class="nav admin-only">Employees</button>
      <button data-page="googleSheet" class="nav admin-only">Google Sheet</button>
    </nav>
    <div class="side-bottom">
      <div id="sideUser"></div>
      <button id="logoutBtn" class="ghost">Logout</button>
    </div>
  </aside>

  <main>
    <header>
      <button id="menuBtn" class="menu">☰</button>
      <div><h1 id="pageTitle">Dashboard</h1><p id="pageSub">Your work performance updates automatically.</p></div>
      <div class="user-pill" id="topUser"></div>
    </header>

    <section id="dashboardPage" class="page active">
      <div class="toolbar">
        <select id="range">
          <option value="today">Today</option><option value="yesterday">Yesterday</option>
          <option value="this_week">This Week</option><option value="last_week">Last Week</option>
          <option value="this_month" selected>This Month</option><option value="last_month">Last Month</option>
          <option value="custom">Custom Date</option>
        </select>
        <input type="date" id="fromDate" class="custom-date hidden">
        <input type="date" id="toDate" class="custom-date hidden">
        <button id="refreshBtn" class="secondary">Refresh</button>
        <span id="sheetMiniStatus" class="sync-pill">Google Sheet: not connected</span>
      </div>

      <div id="kpis" class="kpis"></div>

      <div class="grid2">
        <div class="card"><div class="card-head"><h2>Employee Performance</h2></div><div class="chart-box"><canvas id="employeeChart"></canvas></div></div>
        <div class="card"><div class="card-head"><h2>Task Status</h2></div><div class="chart-box"><canvas id="statusChart"></canvas></div></div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Employee Ranking</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Rank</th><th>Employee</th><th>Total</th><th>Completed</th><th>Pending</th><th>In Progress</th><th>Completion</th><th>Level</th></tr></thead><tbody id="employeeRows"></tbody></table></div>
      </div>

      <div class="grid2">
        <div class="card"><div class="card-head"><h2>Daily Performance Trend</h2></div><div class="chart-box"><canvas id="dailyChart"></canvas></div></div>
        <div class="card"><div class="card-head"><h2>Today's Employee Performance</h2></div><div class="chart-box"><canvas id="todayChart"></canvas></div></div>
      </div>

      <div class="grid2">
        <div class="card"><div class="card-head"><h2>Task Type Performance</h2></div><div class="chart-box"><canvas id="categoryChart"></canvas></div></div>
        <div class="card"><div class="card-head"><h2>Priority Performance</h2></div><div class="chart-box"><canvas id="priorityChart"></canvas></div></div>
      </div>

      <div class="grid2">
        <div class="card"><div class="card-head"><h2>Client Performance</h2></div><div class="chart-box"><canvas id="clientChart"></canvas></div></div>
        <div class="card"><div class="card-head"><h2>Employee Detail</h2><select id="employeeSelect"></select></div><div id="employeeSummary" class="mini-kpis"></div><div class="chart-box"><canvas id="employeeDailyChart"></canvas></div></div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Overdue Tasks</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Employee</th><th>Task</th><th>Due Date</th><th>Priority</th><th>Status</th></tr></thead><tbody id="overdueRows"></tbody></table></div>
      </div>
    </section>

    <section id="tasksPage" class="page">
      <div class="card">
        <div class="card-head">
          <div><h2>Tasks</h2><p class="help-text">Google Sheet tasks are read-only here. Edit those rows in Google Sheet.</p></div>
          <div class="button-row"><button id="syncNowTaskBtn" class="secondary admin-only">Sync Sheet</button><button id="addTaskBtn">+ Add Task</button></div>
        </div>
        <div class="filters">
          <input id="taskSearch" placeholder="Search task, client, POC or employee">
          <select id="taskStatusFilter"><option value="">All Status</option><option>Not Started</option><option>In Progress</option><option>Completed</option><option>Pending</option><option>Blocked</option><option>Cancelled</option></select>
          <select id="taskPriorityFilter"><option value="">All Priority</option><option>Low</option><option>Medium</option><option>High</option><option>Critical</option></select>
          <select id="taskSourceFilter"><option value="">All Sources</option><option value="google_sheet">Google Sheet</option><option value="manual">Manual</option></select>
        </div>
        <div class="table-wrap task-table-wrap">
          <table class="wide-table">
            <thead><tr>
              <th>Date</th><th>Client</th><th>Type</th><th>POC</th><th>Task</th><th>Content Responsible</th><th>Responsible Editor</th>
              <th>Reference</th><th>Time</th><th>Priority</th><th>Status</th><th>Editor Remarks</th><th>Acc Manager</th><th>Manager</th><th>Source</th><th>Action</th>
            </tr></thead>
            <tbody id="taskRows"></tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="employeesPage" class="page">
      <div class="card">
        <div class="card-head"><h2>Employees</h2><button id="addUserBtn">+ Add User</button></div>
        <p class="help-text">When a new Responsible editor name appears in Google Sheet, an employee is created automatically for analytics.</p>
        <div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Department</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody id="userRows"></tbody></table></div>
      </div>
    </section>

    <section id="googleSheetPage" class="page">
      <div class="sheet-layout">
        <div class="card">
          <div class="card-head"><div><h2>Connect Your Daily Google Sheet</h2><p class="help-text">Paste the normal Google Sheet browser link. No API key is needed.</p></div><span id="sheetStatusBadge" class="sync-pill">Not connected</span></div>
          <div class="notice">
            <b>Before connecting:</b> In Google Sheets click <b>Share → General access → Anyone with the link → Viewer</b>. Your current column layout can stay the same.
          </div>
          <div class="sheet-form">
            <label class="wide">Google Sheet Link<input id="sheetUrl" placeholder="https://docs.google.com/spreadsheets/d/.../edit?gid=0#gid=0"></label>
            <label>Sheet Year<input id="sheetYear" type="number" min="2020" max="2100"></label>
            <label>Auto Sync<select id="sheetInterval"><option value="30">Every 30 seconds</option><option value="60">Every 1 minute</option><option value="120">Every 2 minutes</option><option value="300">Every 5 minutes</option><option value="600">Every 10 minutes</option></select></label>
            <label class="toggle-row"><input id="sheetEnabled" type="checkbox"> <span>Turn on automatic sync</span></label>
          </div>
          <div class="button-row sheet-actions">
            <button id="testSheetBtn" type="button" class="secondary">Test Connection</button>
            <button id="saveSheetBtn" type="button">Save Connection</button>
            <button id="syncSheetBtn" type="button">Sync Now</button>
          </div>
          <div id="sheetConnectionStatus" class="connection-box">No Google Sheet connected yet.</div>
        </div>

        <div class="card">
          <div class="card-head"><h2>Your Sheet Mapping</h2></div>
          <div class="table-wrap"><table><thead><tr><th>Your Google Sheet Column</th><th>Used In App As</th></tr></thead><tbody>
            <tr><td>Date.</td><td>Task Date</td></tr>
            <tr><td>Day</td><td>Day</td></tr>
            <tr><td>Name</td><td>Client / Brand</td></tr>
            <tr><td>Type</td><td>Task Type / Category</td></tr>
            <tr><td>POC</td><td>POC</td></tr>
            <tr><td>Tasks</td><td>Task Description</td></tr>
            <tr><td>Content Responsible</td><td>Content Responsible</td></tr>
            <tr><td>Responsible editor</td><td><b>Employee for Performance</b></td></tr>
            <tr><td>Reference links</td><td>Reference</td></tr>
            <tr><td>Time Taken ( Videos)</td><td>Time Taken</td></tr>
            <tr><td>Priority</td><td>Priority</td></tr>
            <tr><td>Remarks filled by editors</td><td><b>Task Status</b></td></tr>
            <tr><td>Editors Remarks</td><td>Editor Remarks</td></tr>
            <tr><td>Acc manager remark</td><td>Account Manager Remark</td></tr>
            <tr><td>Manager Remark</td><td>Manager Remark</td></tr>
          </tbody></table></div>
        </div>

        <div class="card">
          <div class="card-head"><h2>Status & Priority Rules</h2></div>
          <div class="mapping-grid">
            <div><h3>Sheet Status</h3><p><b>Done</b> → Completed</p><p><b>WIP</b> → In Progress</p><p><b>Pending</b> → Pending</p><p><b>Blank</b> → Not Started</p><p><b>Blocked / Hold</b> → Blocked</p></div>
            <div><h3>Sheet Priority</h3><p><b>Urgent / Critical</b> → Critical</p><p><b>High / Imp</b> → High</p><p><b>Medium</b> → Medium</p><p><b>Low</b> → Low</p><p><b>Blank</b> → Medium</p></div>
          </div>
        </div>
      </div>
    </section>
  </main>
</div>

<div id="taskModal" class="modal hidden">
  <form id="taskForm" class="modal-card">
    <div class="modal-head"><h2 id="taskModalTitle">Add Task</h2><button type="button" class="x" data-close="taskModal">×</button></div>
    <input type="hidden" id="taskId">
    <div class="form-grid">
      <label>Date<input type="date" id="taskDate" required></label>
      <label class="admin-only">Employee<select id="taskEmployee"></select></label>
      <label class="wide">Task Description<textarea id="taskDescription" required></textarea></label>
      <label>Category<select id="taskCategory"></select></label>
      <label>Priority<select id="taskPriority"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></label>
      <label>Due Date & Time<input type="datetime-local" id="taskDue"></label>
      <label>Status<select id="taskStatus"><option>Not Started</option><option>In Progress</option><option>Completed</option><option>Pending</option><option>Blocked</option><option>Cancelled</option></select></label>
      <label class="wide">Remarks<textarea id="taskRemarks"></textarea></label>
    </div>
    <div class="modal-actions"><button type="button" class="secondary" data-close="taskModal">Cancel</button><button>Save Task</button></div>
  </form>
</div>

<div id="userModal" class="modal hidden">
  <form id="userForm" class="modal-card">
    <div class="modal-head"><h2 id="userModalTitle">Add User</h2><button type="button" class="x" data-close="userModal">×</button></div>
    <input type="hidden" id="userId">
    <div class="form-grid">
      <label>Name<input id="userName" required></label><label>Email<input id="userEmail" type="email" required></label>
      <label>Department<input id="userDepartment"></label><label>Role<select id="userRole"><option value="employee">Employee</option><option value="admin">Admin</option></select></label>
      <label>Password<input id="userPassword" type="password" placeholder="Minimum 6 characters"></label><label>Status<select id="userStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
    </div>
    <div class="modal-actions"><button type="button" class="secondary" data-close="userModal">Cancel</button><button>Save User</button></div>
  </form>
</div>

<div id="toast"></div>
<script src="assets/app.js"></script>
<?php endif; ?>
</body>
</html>
