const S = {
  user: null,
  csrf: '',
  meta: { users: [], categories: [] },
  tasks: [],
  users: [],
  charts: {},
  sheet: null,
};

const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];

async function api(action, { method='GET', body=null, params={} } = {}) {
  const u = new URL('api.php', location.href);
  u.searchParams.set('action', action);
  Object.entries(params).forEach(([k,v]) => {
    if (v !== '' && v != null) u.searchParams.set(k, v);
  });
  const opt = { method, headers: {} };
  if (body) {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(body);
  }
  if (S.csrf) opt.headers['X-CSRF-Token'] = S.csrf;
  const r = await fetch(u, opt);
  const j = await r.json().catch(() => ({ok:false,message:'Invalid server response.'}));
  if (!r.ok || !j.ok) throw new Error(j.message || 'Request failed');
  return j;
}

function toast(message) {
  const t = $('#toast');
  t.textContent = message;
  t.classList.add('show');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => t.classList.remove('show'), 3000);
}

function esc(v='') {
  return String(v).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

function fmt(v) { return v ? String(v).replace('T',' ') : '-'; }
function todayLocal() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth()+1).padStart(2,'0');
  const day = String(d.getDate()).padStart(2,'0');
  return `${y}-${m}-${day}`;
}
function qp() {
  const range = $('#range').value;
  const p = { range };
  if (range === 'custom') { p.from = $('#fromDate').value; p.to = $('#toDate').value; }
  return p;
}
function linkifyRef(value) {
  if (!value) return '-';
  const text = String(value);
  const match = text.match(/https?:\/\/[^\s]+/i);
  if (!match) return esc(text);
  const url = match[0].replace(/[),.;]+$/,'');
  return `<a class="ref-link" href="${esc(url)}" target="_blank" rel="noopener">Open link</a><div class="cell-small">${esc(text.replace(match[0], '').trim().replace(/^[-:]+/,'').trim())}</div>`;
}

function showApp() {
  $('#loginScreen').classList.add('hidden');
  $('#app').classList.remove('hidden');
  $('#topUser').textContent = S.user.name;
  $('#sideUser').innerHTML = `<b>${esc(S.user.name)}</b><br><span class="muted">${esc(S.user.role)}</span>`;
  if (S.user.role !== 'admin') $$('.admin-only').forEach(e => e.classList.add('hidden'));
  else $$('.admin-only').forEach(e => e.classList.remove('hidden'));
}

async function boot() {
  try {
    const m = await api('me');
    S.user = m.user;
    S.csrf = m.csrf;
    showApp();
    await loadMeta();
    await loadDashboard();
    maybeAutoSync(true);
  } catch (_) {}
}

$('#loginForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const j = await api('login', {method:'POST',body:{email:$('#loginEmail').value,password:$('#loginPassword').value}});
    S.user = j.user;
    S.csrf = j.csrf;
    $('#loginMsg').textContent = '';
    showApp();
    await loadMeta();
    await loadDashboard();
    maybeAutoSync(true);
  } catch (err) {
    $('#loginMsg').textContent = err.message;
  }
});

$('#logoutBtn')?.addEventListener('click', async () => {
  try { await api('logout',{method:'POST'}); } catch (_) {}
  location.reload();
});

const pageTitles = {
  dashboard: ['Dashboard','Your work performance updates automatically.'],
  tasks: ['Tasks','View manual tasks and daily Google Sheet tasks.'],
  employees: ['Employees','Manage users and Responsible editor employees.'],
  googleSheet: ['Google Sheet','Connect your existing daily task sheet.'],
};

$$('.nav').forEach(b => b.addEventListener('click', async () => {
  const p = b.dataset.page;
  $$('.nav').forEach(x => x.classList.remove('active'));
  b.classList.add('active');
  $$('.page').forEach(x => x.classList.remove('active'));
  $('#'+p+'Page').classList.add('active');
  $('#pageTitle').textContent = pageTitles[p]?.[0] || p;
  $('#pageSub').textContent = pageTitles[p]?.[1] || '';
  if (p === 'dashboard') await loadDashboard();
  if (p === 'tasks') await loadTasks();
  if (p === 'employees') await loadUsers();
  if (p === 'googleSheet') await loadGoogleSheetSettings();
  if (innerWidth < 1000) $('.sidebar').classList.remove('open');
}));

$('#menuBtn')?.addEventListener('click', () => $('.sidebar').classList.toggle('open'));

async function loadMeta() {
  const j = await api('meta');
  S.meta = j;
  const opts = j.users.filter(u => u.status !== 'inactive').map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
  $('#taskEmployee').innerHTML = opts;
  $('#employeeSelect').innerHTML = opts;
  $('#taskCategory').innerHTML = '<option value="">No Category</option>' + j.categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
}

function chart(id, type, labels, data, label) {
  if (typeof Chart === 'undefined') return;
  S.charts[id]?.destroy();
  const ctx = $('#'+id);
  if (!ctx) return;
  const isCircle = type === 'doughnut' || type === 'pie';
  S.charts[id] = new Chart(ctx, {
    type,
    data: { labels, datasets: [{ label, data, borderWidth:2, tension:.3 }] },
    options: {
      responsive:true,
      maintainAspectRatio:false,
      plugins:{ legend:{ display:isCircle } },
      scales:isCircle ? {} : { y:{ beginAtZero:true, max:100 } }
    }
  });
}

async function loadDashboard() {
  const p = qp();
  try {
    const [d,e,day,st,cat,pri,today,over,clients] = await Promise.all([
      api('analytics.dashboard',{params:p}), api('analytics.employees',{params:p}), api('analytics.daily',{params:p}),
      api('analytics.status',{params:p}), api('analytics.categories',{params:p}), api('analytics.priorities',{params:p}),
      api('analytics.todayEmployees'), api('analytics.overdue',{params:p}), api('analytics.clients',{params:p})
    ]);
    const k = d.data;
    $('#kpis').innerHTML = [
      ['Total Tasks',k.total],['Completed',k.completed],['Pending',k.pending],['In Progress',k.in_progress],
      ['Blocked',k.blocked],['Overdue',k.overdue],['Completion %',k.completion+'%'],['Productivity %',k.productivity+'%']
    ].map(x => `<div class="kpi"><span>${x[0]}</span><strong>${x[1]}</strong></div>`).join('');

    $('#employeeRows').innerHTML = e.employees.length ? e.employees.map(r => `<tr>
      <td>#${r.rank}</td><td><b>${esc(r.name)}</b><br><span class="muted">${esc(r.department||'')}</span></td>
      <td>${r.total}</td><td>${r.completed}</td><td>${r.pending}</td><td>${r.in_progress}</td><td><b>${r.completion}%</b></td>
      <td><span class="badge level-${r.level.split(' ')[0]}">${r.level}</span></td>
    </tr>`).join('') : '<tr><td colspan="8" class="empty">No task data yet.</td></tr>';

    chart('employeeChart','bar',e.employees.map(x=>x.name),e.employees.map(x=>x.completion),'Completion %');
    chart('statusChart','doughnut',st.items.map(x=>x.label),st.items.map(x=>x.value),'Tasks');
    chart('dailyChart','line',day.daily.map(x=>x.date),day.daily.map(x=>x.completion),'Completion %');
    chart('todayChart','bar',today.employees.map(x=>x.name),today.employees.map(x=>x.completion),'Today %');
    chart('categoryChart','bar',cat.items.map(x=>x.label),cat.items.map(x=>x.completion),'Completion %');
    chart('priorityChart','bar',pri.items.map(x=>x.label),pri.items.map(x=>x.completion),'Completion %');
    chart('clientChart','bar',clients.items.map(x=>x.label),clients.items.map(x=>x.completion),'Completion %');

    $('#overdueRows').innerHTML = over.tasks.length ? over.tasks.map(t => `<tr><td>${esc(t.employee_name)}</td><td>${esc(t.task_description)}</td><td>${fmt(t.due_date)}</td><td><span class="badge">${esc(t.priority)}</span></td><td>${esc(t.status)}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">No overdue tasks.</td></tr>';
    await loadEmployeeDetail();
    await loadSheetStatus();
  } catch (err) { toast(err.message); }
}

async function loadEmployeeDetail() {
  const id = $('#employeeSelect').value;
  if (!id) {
    $('#employeeSummary').innerHTML = '<div class="empty">No employee selected.</div>';
    return;
  }
  try {
    const [j,t,w,m] = await Promise.all([
      api('analytics.employee',{params:{...qp(),employee_id:id}}),
      api('analytics.employee',{params:{range:'today',employee_id:id}}),
      api('analytics.employee',{params:{range:'this_week',employee_id:id}}),
      api('analytics.employee',{params:{range:'this_month',employee_id:id}})
    ]);
    const s = j.summary;
    $('#employeeSummary').innerHTML = [['Today',t.summary.completion+'%'],['This Week',w.summary.completion+'%'],['This Month',m.summary.completion+'%'],['Selected Range',s.completion+'%']]
      .map(x => `<div class="mini-kpi"><span>${x[0]}</span><strong>${x[1]}</strong></div>`).join('');
    chart('employeeDailyChart','line',j.daily.map(x=>x.date),j.daily.map(x=>x.completion),'Daily Completion %');
  } catch (e) { toast(e.message); }
}

$('#employeeSelect')?.addEventListener('change', loadEmployeeDetail);
$('#refreshBtn')?.addEventListener('click', async () => { await maybeAutoSync(true); await loadDashboard(); });
$('#range')?.addEventListener('change', () => {
  const c = $('#range').value === 'custom';
  $$('.custom-date').forEach(e => e.classList.toggle('hidden',!c));
  if (!c) loadDashboard();
});
$$('.custom-date').forEach(e => e.addEventListener('change', () => {
  if ($('#fromDate').value && $('#toDate').value) loadDashboard();
}));

// ---------- TASKS ----------
async function loadTasks() {
  try {
    const j = await api('tasks',{params:{
      search:$('#taskSearch').value,
      status:$('#taskStatusFilter').value,
      priority:$('#taskPriorityFilter').value,
      source:$('#taskSourceFilter').value,
    }});
    S.tasks = j.tasks;
    $('#taskRows').innerHTML = S.tasks.length ? S.tasks.map(t => {
      const sheet = t.source === 'google_sheet';
      const action = sheet ? '<span class="readonly-label">Edit in Google Sheet</span>' : `<div class="actions"><button onclick="editTask(${t.id})">Edit</button><button class="danger" onclick="deleteTask(${t.id})">Delete</button></div>`;
      return `<tr>
        <td>${esc(t.task_date)}</td>
        <td><b>${esc(t.client_name||'-')}</b></td>
        <td>${esc(t.task_type||t.category_name||'-')}</td>
        <td>${esc(t.poc||'-')}</td>
        <td class="task-cell"><b>${esc(t.task_description)}</b></td>
        <td>${esc(t.content_responsible||'-')}</td>
        <td><b>${esc(t.responsible_editor||t.employee_name)}</b></td>
        <td>${linkifyRef(t.reference_links)}</td>
        <td>${esc(t.time_taken||'-')}</td>
        <td><span class="badge">${esc(t.raw_priority||t.priority)}</span></td>
        <td><span class="badge">${esc(t.raw_status||t.status)}</span><div class="cell-small">${esc(t.status)}</div></td>
        <td>${esc(t.editor_remarks||t.remarks||'-')}</td>
        <td>${esc(t.acc_manager_remark||'-')}</td>
        <td>${esc(t.manager_remark||'-')}</td>
        <td><span class="source-badge ${sheet?'sheet-source':'manual-source'}">${sheet?'Google Sheet':'Manual'}</span></td>
        <td>${action}</td>
      </tr>`;
    }).join('') : '<tr><td colspan="16" class="empty">No tasks found.</td></tr>';
  } catch (e) { toast(e.message); }
}

let searchTimer;
$('#taskSearch')?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer=setTimeout(loadTasks,300); });
$('#taskStatusFilter')?.addEventListener('change',loadTasks);
$('#taskPriorityFilter')?.addEventListener('change',loadTasks);
$('#taskSourceFilter')?.addEventListener('change',loadTasks);

function openTask(t=null) {
  if (t?.source === 'google_sheet') { toast('Edit this task in Google Sheet.'); return; }
  $('#taskForm').reset();
  $('#taskId').value=t?.id||'';
  $('#taskModalTitle').textContent=t?'Edit Task':'Add Task';
  $('#taskDate').value=t?.task_date||todayLocal();
  $('#taskEmployee').value=t?.employee_id||S.user.id;
  $('#taskDescription').value=t?.task_description||'';
  $('#taskCategory').value=t?.category_id||'';
  $('#taskPriority').value=t?.priority||'Medium';
  $('#taskDue').value=t?.due_date?t.due_date.replace(' ','T').slice(0,16):'';
  $('#taskStatus').value=t?.status||'Not Started';
  $('#taskRemarks').value=t?.remarks||'';
  $('#taskModal').classList.remove('hidden');
}

$('#addTaskBtn')?.addEventListener('click',()=>openTask());
window.editTask = id => openTask(S.tasks.find(x=>+x.id===+id));
window.deleteTask = async id => {
  if(!confirm('Delete this task?')) return;
  try {
    await api('tasks',{method:'DELETE',params:{id}});
    toast('Task deleted.');
    await loadTasks();
    await loadDashboard();
  } catch(e) { toast(e.message); }
};

$('#taskForm')?.addEventListener('submit', async e => {
  e.preventDefault();
  const id=$('#taskId').value;
  const b={
    employee_id:$('#taskEmployee').value,task_date:$('#taskDate').value,task_description:$('#taskDescription').value,
    category_id:$('#taskCategory').value,priority:$('#taskPriority').value,due_date:$('#taskDue').value,
    status:$('#taskStatus').value,remarks:$('#taskRemarks').value
  };
  try {
    await api('tasks',{method:id?'PUT':'POST',params:id?{id}:{},body:b});
    $('#taskModal').classList.add('hidden');
    toast(id?'Task updated.':'Task added.');
    await loadTasks();
    await loadDashboard();
  } catch(er) { toast(er.message); }
});

// ---------- USERS ----------
async function loadUsers() {
  if(S.user.role!=='admin') return;
  try {
    const j=await api('users');
    S.users=j.users;
    $('#userRows').innerHTML=S.users.map(u=>`<tr><td>${esc(u.name)}</td><td>${esc(u.email)}</td><td>${esc(u.department||'-')}</td><td>${esc(u.role)}</td><td>${esc(u.status)}</td><td><button onclick="editUser(${u.id})">Edit</button></td></tr>`).join('');
  } catch(e) { toast(e.message); }
}

function openUser(u=null) {
  $('#userForm').reset();
  $('#userId').value=u?.id||'';
  $('#userModalTitle').textContent=u?'Edit User':'Add User';
  $('#userName').value=u?.name||'';
  $('#userEmail').value=u?.email||'';
  $('#userDepartment').value=u?.department||'';
  $('#userRole').value=u?.role||'employee';
  $('#userStatus').value=u?.status||'active';
  $('#userPassword').required=!u;
  $('#userPassword').placeholder=u?'Leave blank to keep current password':'Minimum 6 characters';
  $('#userModal').classList.remove('hidden');
}

$('#addUserBtn')?.addEventListener('click',()=>openUser());
window.editUser=id=>openUser(S.users.find(x=>+x.id===+id));
$('#userForm')?.addEventListener('submit',async e=>{
  e.preventDefault();
  const id=$('#userId').value;
  const b={name:$('#userName').value,email:$('#userEmail').value,department:$('#userDepartment').value,role:$('#userRole').value,status:$('#userStatus').value,password:$('#userPassword').value};
  try {
    await api('users',{method:id?'PUT':'POST',params:id?{id}:{},body:b});
    $('#userModal').classList.add('hidden');
    toast(id?'User updated.':'User added.');
    await loadMeta();
    await loadUsers();
  } catch(er) { toast(er.message); }
});

// ---------- GOOGLE SHEET ----------
function sheetSettingsBody() {
  return {
    sheet_url: $('#sheetUrl').value.trim(),
    sync_year: Number($('#sheetYear').value || new Date().getFullYear()),
    sync_interval: Number($('#sheetInterval').value || 60),
    enabled: $('#sheetEnabled').checked,
  };
}

function renderSheetStatus(status, target='#sheetConnectionStatus') {
  const el = $(target);
  if (!el) return;
  if (!status?.connected) {
    el.className = target === '#sheetConnectionStatus' ? 'connection-box' : 'sync-pill';
    el.textContent = 'Google Sheet: not connected';
    return;
  }
  const ok = status.last_sync_status === 'success';
  const err = status.last_sync_status === 'error';
  const text = status.last_sync_at
    ? `${ok?'Connected':'Connected'} • Last sync: ${status.last_sync_at} • ${status.last_sync_count||0} tasks${err?' • Error':''}`
    : 'Connected • Not synced yet';
  el.textContent = target === '#sheetConnectionStatus' ? `${text}${status.last_sync_message ? ' — '+status.last_sync_message : ''}` : `Google Sheet: ${text}`;
  if (target === '#sheetConnectionStatus') el.className = `connection-box ${err?'connection-error':ok?'connection-ok':''}`;
  else el.className = `sync-pill ${err?'sync-error':ok?'sync-ok':''}`;
}

async function loadSheetStatus() {
  try {
    const j=await api('google_sheet.status');
    S.sheet=j.status;
    renderSheetStatus(j.status,'#sheetMiniStatus');
    if ($('#sheetStatusBadge')) {
      $('#sheetStatusBadge').textContent = j.status.connected ? (j.status.last_sync_status==='success'?'Connected':'Connected') : 'Not connected';
      $('#sheetStatusBadge').className = `sync-pill ${j.status.last_sync_status==='success'?'sync-ok':j.status.last_sync_status==='error'?'sync-error':''}`;
    }
  } catch (_) {}
}

async function loadGoogleSheetSettings() {
  if (S.user.role !== 'admin') return;
  try {
    const j=await api('google_sheet.settings');
    const s=j.settings||{};
    $('#sheetUrl').value=s.sheet_url||'';
    $('#sheetYear').value=s.sync_year||new Date().getFullYear();
    $('#sheetInterval').value=String(s.sync_interval||60);
    $('#sheetEnabled').checked=Number(s.enabled)===1;
    const status={
      connected:!!s.sheet_url,enabled:Number(s.enabled)||0,last_sync_at:s.last_sync_at,last_sync_status:s.last_sync_status,
      last_sync_message:s.last_sync_message,last_sync_count:Number(s.last_sync_count)||0,sync_interval:Number(s.sync_interval)||60
    };
    renderSheetStatus(status,'#sheetConnectionStatus');
    renderSheetStatus(status,'#sheetMiniStatus');
    $('#sheetStatusBadge').textContent=status.connected?'Connected':'Not connected';
    $('#sheetStatusBadge').className=`sync-pill ${status.last_sync_status==='success'?'sync-ok':status.last_sync_status==='error'?'sync-error':''}`;
  } catch(e) { toast(e.message); }
}

async function saveSheetSettings(showToast=true) {
  const body=sheetSettingsBody();
  if(!body.sheet_url) throw new Error('Paste your Google Sheet link first.');
  const j=await api('google_sheet.settings',{method:'POST',body});
  if(showToast) toast(j.message);
  await loadGoogleSheetSettings();
  return j;
}

$('#testSheetBtn')?.addEventListener('click',async()=>{
  const url=$('#sheetUrl').value.trim();
  if(!url){toast('Paste your Google Sheet link first.');return;}
  const btn=$('#testSheetBtn'); const old=btn.textContent; btn.disabled=true; btn.textContent='Testing...';
  try {
    const j=await api('google_sheet.test',{method:'POST',body:{sheet_url:url}});
    $('#sheetConnectionStatus').className='connection-box connection-ok';
    $('#sheetConnectionStatus').textContent=`Connection successful. ${j.task_rows} task rows found in this sheet tab.`;
    toast('Google Sheet connection successful.');
  } catch(e) {
    $('#sheetConnectionStatus').className='connection-box connection-error';
    $('#sheetConnectionStatus').textContent=e.message;
    toast(e.message);
  } finally {btn.disabled=false;btn.textContent=old;}
});

$('#saveSheetBtn')?.addEventListener('click',async()=>{
  try { await saveSheetSettings(true); } catch(e){ toast(e.message); }
});

async function syncSheet(force=true, showToast=true) {
  const j=await api('google_sheet.sync',{method:'POST',body:{force}});
  if(showToast && !j.skipped) toast(j.message || 'Google Sheet synced.');
  if(showToast && j.skipped && j.message!=='Already up to date.') toast(j.message);
  await loadSheetStatus();
  if(!j.skipped) {
    await loadMeta();
    if($('#tasksPage')?.classList.contains('active')) await loadTasks();
    if($('#employeesPage')?.classList.contains('active')) await loadUsers();
    if($('#dashboardPage')?.classList.contains('active')) await loadDashboard();
    if($('#googleSheetPage')?.classList.contains('active')) await loadGoogleSheetSettings();
  }
  return j;
}

$('#syncSheetBtn')?.addEventListener('click',async()=>{
  const btn=$('#syncSheetBtn');const old=btn.textContent;btn.disabled=true;btn.textContent='Syncing...';
  try { await saveSheetSettings(false); await syncSheet(true,true); }
  catch(e){ toast(e.message); $('#sheetConnectionStatus').className='connection-box connection-error'; $('#sheetConnectionStatus').textContent=e.message; }
  finally{btn.disabled=false;btn.textContent=old;}
});

$('#syncNowTaskBtn')?.addEventListener('click',async()=>{
  try { await syncSheet(true,true); await loadTasks(); } catch(e){toast(e.message);}
});

async function maybeAutoSync(refreshUI=false) {
  if(!S.user) return;
  try {
    const j=await api('google_sheet.sync',{method:'POST',body:{force:false}});
    if(!j.skipped && refreshUI) {
      await loadMeta();
      if($('#dashboardPage')?.classList.contains('active')) await loadDashboard();
      if($('#tasksPage')?.classList.contains('active')) await loadTasks();
    }
    await loadSheetStatus();
  } catch(e) {
    await loadSheetStatus();
    if(refreshUI) console.warn('Google Sheet auto sync:',e.message);
  }
}

// ---------- MODALS ----------
$$('[data-close]').forEach(b=>b.addEventListener('click',()=>$('#'+b.dataset.close).classList.add('hidden')));
$$('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.add('hidden')}));

boot();

// Dashboard refresh + Google Sheet check. Backend respects the selected sync interval.
setInterval(()=>{if(S.user) maybeAutoSync(true)},30000);
setInterval(()=>{if(S.user && $('#dashboardPage')?.classList.contains('active')) loadDashboard()},60000);
