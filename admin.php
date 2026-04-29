<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['auth']) || ($_SESSION['auth']['role'] ?? '') !== 'admin') {
    header('Location: /price/login.html');
    exit;
}
$me = $_SESSION['auth'];
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#02132C">
<title>ניהול משתמשים · Quotes</title>
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;font-family:'Heebo',-apple-system,sans-serif;direction:rtl;color:#f1f5f9;-webkit-font-smoothing:antialiased}
:root{
  --navy-deepest:#010918;--navy-deep:#02132C;--navy-dark:#0a2456;
  --blue-primary:#428EFF;--blue-bright:#60a5fa;--indigo:#404BF2;
  --success:#10b981;--success-bright:#6ee7b7;
  --danger:#ef4444;--danger-soft:#fca5a5;
  --warning:#f59e0b;--warning-bright:#fcd34d;
  --text:#f1f5f9;--text-muted:#cbd5e1;--text-dim:#94a3b8;
  --glass-bg:linear-gradient(135deg,rgba(66,142,255,0.08) 0%,rgba(10,36,86,0.35) 50%,rgba(2,19,44,0.5) 100%);
  --glass-border:rgba(255,255,255,0.1);
  --radius-sm:10px;--radius-md:14px;--radius-lg:18px;
}
body{background:radial-gradient(ellipse at top,var(--navy-dark) 0%,var(--navy-deep) 55%,var(--navy-deepest) 100%);min-height:100vh}
.bg-fx{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.bg-glow{position:absolute;border-radius:50%;filter:blur(120px)}
.bg-glow-1{width:500px;height:500px;background:radial-gradient(circle,rgba(66,142,255,0.25) 0%,transparent 70%);top:-200px;right:-150px}
.bg-glow-2{width:600px;height:600px;background:radial-gradient(circle,rgba(64,75,242,0.2) 0%,transparent 70%);bottom:-250px;left:-200px}
.bg-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(66,142,255,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(66,142,255,0.04) 1px,transparent 1px);background-size:48px 48px;mask-image:radial-gradient(ellipse at center,black 30%,transparent 75%)}

/* ── HEADER ── */
.header{position:sticky;top:0;z-index:50;background:rgba(2,13,38,0.9);backdrop-filter:blur(30px);border-bottom:1px solid rgba(66,142,255,0.15);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.header-left{display:flex;align-items:center;gap:14px}
.back-btn{display:flex;align-items:center;gap:6px;padding:8px 14px;background:rgba(66,142,255,0.08);border:1px solid rgba(66,142,255,0.2);border-radius:var(--radius-sm);color:var(--text-muted);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;transition:all 0.2s}
.back-btn:hover{background:rgba(66,142,255,0.14);color:white}
.header-title{font-size:18px;font-weight:900;letter-spacing:-0.02em}
.header-title span{color:var(--text-dim);font-weight:400;font-size:13px;margin-right:8px}
.btn-add-user{display:flex;align-items:center;gap:7px;padding:10px 18px;background:linear-gradient(135deg,var(--blue-primary),var(--indigo));border:none;border-radius:var(--radius-sm);color:white;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;box-shadow:0 4px 16px rgba(66,142,255,0.4);transition:all 0.2s}
.btn-add-user:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(66,142,255,0.5)}

/* ── MAIN ── */
.main{position:relative;z-index:1;max-width:900px;margin:0 auto;padding:28px 20px 60px}

/* ── STATS ── */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.stat-card{background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:var(--radius-md);padding:16px 18px;backdrop-filter:blur(20px)}
.stat-card-num{font-size:28px;font-weight:900;line-height:1}
.stat-card-label{font-size:11px;color:var(--text-dim);margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:0.6px}
.stat-card.blue .stat-card-num{color:var(--blue-bright)}
.stat-card.green .stat-card-num{color:var(--success-bright)}
.stat-card.orange .stat-card-num{color:var(--warning-bright)}

/* ── USER CARDS ── */
.user-card{background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:var(--radius-md);padding:18px 20px;margin-bottom:10px;display:flex;align-items:center;gap:16px;backdrop-filter:blur(20px);transition:border-color 0.2s}
.user-card:hover{border-color:rgba(66,142,255,0.3)}
.avatar{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--blue-primary),var(--indigo));display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;color:white;flex-shrink:0;box-shadow:0 4px 14px rgba(66,142,255,0.4)}
.user-info{flex:1;min-width:0}
.user-name{font-size:15px;font-weight:700;margin-bottom:3px}
.user-meta{font-size:12px;color:var(--text-dim);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.role-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:100px;font-size:10px;font-weight:700}
.role-badge.admin{background:rgba(66,142,255,0.18);color:var(--blue-bright);border:1px solid rgba(66,142,255,0.3)}
.role-badge.agent{background:rgba(16,185,129,0.12);color:var(--success-bright);border:1px solid rgba(16,185,129,0.25)}
.you-tag{background:rgba(245,158,11,0.12);color:var(--warning-bright);border:1px solid rgba(245,158,11,0.25);padding:2px 9px;border-radius:100px;font-size:10px;font-weight:700}
.user-actions{display:flex;gap:6px;flex-shrink:0}
.action-btn{padding:7px 12px;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;border:1px solid;transition:all 0.2s;display:inline-flex;align-items:center;gap:5px}
.action-btn.edit{background:rgba(66,142,255,0.08);border-color:rgba(66,142,255,0.25);color:white}
.action-btn.edit:hover{background:rgba(66,142,255,0.16)}
.action-btn.pwd{background:rgba(245,158,11,0.08);border-color:rgba(245,158,11,0.25);color:var(--warning-bright)}
.action-btn.pwd:hover{background:rgba(245,158,11,0.15)}
.action-btn.del{background:rgba(239,68,68,0.08);border-color:rgba(239,68,68,0.25);color:var(--danger-soft)}
.action-btn.del:hover{background:rgba(239,68,68,0.16)}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)}
.modal-overlay.hidden{display:none}
.modal{background:linear-gradient(135deg,#010f28 0%,#020c20 100%);border:1px solid rgba(66,142,255,0.2);border-radius:var(--radius-lg);padding:28px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,0.6)}
.modal-title{font-size:17px;font-weight:800;margin-bottom:6px}
.modal-subtitle{font-size:12px;color:var(--text-dim);margin-bottom:22px}
.field{margin-bottom:14px}
.field label{display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px}
.field input,.field select{width:100%;padding:11px 13px;background:rgba(2,19,44,0.7);border:1px solid rgba(66,142,255,0.2);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;color:white;transition:all 0.2s;-webkit-appearance:none}
.field input:focus,.field select:focus{outline:none;border-color:var(--blue-primary);background:rgba(2,19,44,0.9)}
.field input::placeholder{color:rgba(148,163,184,0.5)}
.field select option{background:#02132C}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.modal-actions{display:flex;gap:8px;margin-top:20px}
.btn-primary{flex:2;padding:12px;background:linear-gradient(135deg,var(--blue-primary),var(--indigo));border:none;border-radius:var(--radius-sm);color:white;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all 0.2s}
.btn-primary:hover{opacity:0.9}
.btn-primary.danger{background:linear-gradient(135deg,#ef4444,#b91c1c)}
.btn-cancel{flex:1;padding:12px;background:rgba(66,142,255,0.07);border:1px solid rgba(66,142,255,0.2);border-radius:var(--radius-sm);color:var(--text-muted);font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;transition:all 0.2s}
.btn-cancel:hover{background:rgba(66,142,255,0.12);color:white}
.error-box{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger-soft);padding:10px 14px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:14px}
.error-box.hidden{display:none}

/* ── TOAST ── */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:rgba(10,36,86,0.95);border:1px solid rgba(66,142,255,0.3);color:white;padding:11px 20px;border-radius:100px;font-size:13px;font-weight:600;z-index:999;opacity:0;transition:all 0.3s;pointer-events:none;white-space:nowrap}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.success{border-color:rgba(16,185,129,0.4);color:var(--success-bright)}
.toast.error{border-color:rgba(239,68,68,0.4);color:var(--danger-soft)}
</style>
</head>
<body>
<div class="bg-fx"><div class="bg-glow bg-glow-1"></div><div class="bg-glow bg-glow-2"></div><div class="bg-grid"></div></div>

<div class="header">
  <div class="header-left">
    <a href="/price/index.html" class="back-btn">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      חזרה למערכת
    </a>
    <div class="header-title">ניהול משתמשים <span>Admin Panel</span></div>
  </div>
  <button class="btn-add-user" onclick="openAdd()">
    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    הוסף משתמש
  </button>
</div>

<div class="main">
  <div class="stats-row">
    <div class="stat-card blue"><div class="stat-card-num" id="stat-total">—</div><div class="stat-card-label">סה"כ משתמשים</div></div>
    <div class="stat-card orange"><div class="stat-card-num" id="stat-admins">—</div><div class="stat-card-label">מנהלים</div></div>
    <div class="stat-card green"><div class="stat-card-num" id="stat-agents">—</div><div class="stat-card-label">נציגים</div></div>
  </div>
  <div id="users-list"></div>
</div>

<!-- Modal: Add / Edit -->
<div class="modal-overlay hidden" id="modal-user">
  <div class="modal">
    <div class="modal-title" id="modal-user-title">הוסף משתמש</div>
    <div class="modal-subtitle" id="modal-user-sub">מלא את פרטי המשתמש החדש</div>
    <div class="error-box hidden" id="user-error"></div>
    <input type="hidden" id="edit-id">
    <div class="field-row">
      <div class="field"><label>שם מלא</label><input type="text" id="f-name" placeholder="ישראל ישראלי"></div>
      <div class="field"><label>שם משתמש</label><input type="text" id="f-username" placeholder="israel" autocorrect="off" autocapitalize="off"></div>
    </div>
    <div class="field"><label>מייל</label><input type="email" id="f-email" placeholder="israel@example.com"></div>
    <div class="field-row">
      <div class="field"><label>תפקיד</label>
        <select id="f-role"><option value="agent">נציג מכירות</option><option value="admin">מנהל</option></select>
      </div>
      <div class="field" id="field-password"><label>סיסמה</label><input type="password" id="f-password" placeholder="לפחות 6 תווים"></div>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModals()">ביטול</button>
      <button class="btn-primary" id="modal-user-btn" onclick="submitUser()">הוסף משתמש</button>
    </div>
  </div>
</div>

<!-- Modal: Reset Password -->
<div class="modal-overlay hidden" id="modal-pwd">
  <div class="modal">
    <div class="modal-title">איפוס סיסמה</div>
    <div class="modal-subtitle" id="pwd-subtitle"></div>
    <div class="error-box hidden" id="pwd-error"></div>
    <input type="hidden" id="pwd-user-id">
    <div class="field"><label>סיסמה חדשה</label><input type="password" id="new-pwd" placeholder="לפחות 6 תווים"></div>
    <div class="field"><label>אימות סיסמה</label><input type="password" id="confirm-pwd" placeholder="הזן שוב"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModals()">ביטול</button>
      <button class="btn-primary" onclick="submitPwd()">שמור סיסמה</button>
    </div>
  </div>
</div>

<!-- Modal: Delete -->
<div class="modal-overlay hidden" id="modal-del">
  <div class="modal">
    <div class="modal-title">מחיקת משתמש</div>
    <div class="modal-subtitle" id="del-subtitle">האם למחוק את המשתמש? פעולה זו בלתי הפיכה.</div>
    <input type="hidden" id="del-user-id">
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModals()">ביטול</button>
      <button class="btn-primary danger" onclick="submitDelete()">מחק</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const BASE = '/price';
const API  = BASE + '/admin-api.php';
const ME   = '<?= htmlspecialchars($me['id']) ?>';

let users = [];

async function call(action, body = null) {
  const opts = { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch(API + '?action=' + action, opts);
  return r.json();
}

function toast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + type;
  setTimeout(() => t.classList.remove('show'), 2500);
}

function esc(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function loadUsers() {
  const r = await call('list');
  if (!r.ok) return;
  users = r.users;
  render();
}

function render() {
  document.getElementById('stat-total').textContent  = users.length;
  document.getElementById('stat-admins').textContent = users.filter(u => u.role === 'admin').length;
  document.getElementById('stat-agents').textContent = users.filter(u => u.role === 'agent').length;

  const list = document.getElementById('users-list');
  if (!users.length) {
    list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-dim)">אין משתמשים</div>';
    return;
  }

  list.innerHTML = users.map(u => `
    <div class="user-card">
      <div class="avatar">${esc(u.avatar || u.name?.charAt(0) || '?')}</div>
      <div class="user-info">
        <div class="user-name">${esc(u.name)} ${u.id === ME ? '<span class="you-tag">אתה</span>' : ''}</div>
        <div class="user-meta">
          <span>${esc(u.username)}</span>
          <span>·</span>
          <span>${esc(u.email)}</span>
          <span class="role-badge ${u.role}">${u.role === 'admin' ? 'מנהל' : 'נציג'}</span>
        </div>
      </div>
      <div class="user-actions">
        <button class="action-btn edit" onclick="openEdit('${esc(u.id)}')">עריכה</button>
        <button class="action-btn pwd"  onclick="openPwd('${esc(u.id)}','${esc(u.name)}')">סיסמה</button>
        ${u.id !== ME ? `<button class="action-btn del" onclick="openDel('${esc(u.id)}','${esc(u.name)}')">מחק</button>` : ''}
      </div>
    </div>`).join('');
}

// ── Add ──────────────────────────────────────────────────────
function openAdd() {
  document.getElementById('modal-user-title').textContent = 'הוסף משתמש';
  document.getElementById('modal-user-sub').textContent   = 'מלא את פרטי המשתמש החדש';
  document.getElementById('modal-user-btn').textContent   = 'הוסף משתמש';
  document.getElementById('edit-id').value = '';
  document.getElementById('f-name').value     = '';
  document.getElementById('f-username').value = '';
  document.getElementById('f-email').value    = '';
  document.getElementById('f-password').value = '';
  document.getElementById('f-role').value     = 'agent';
  document.getElementById('field-password').style.display = '';
  document.getElementById('f-username').disabled = false;
  setError('user', '');
  document.getElementById('modal-user').classList.remove('hidden');
  setTimeout(() => document.getElementById('f-name').focus(), 100);
}

function openEdit(id) {
  const u = users.find(x => x.id === id);
  if (!u) return;
  document.getElementById('modal-user-title').textContent = 'עריכת משתמש';
  document.getElementById('modal-user-sub').textContent   = 'עדכן את פרטי המשתמש';
  document.getElementById('modal-user-btn').textContent   = 'שמור שינויים';
  document.getElementById('edit-id').value    = u.id;
  document.getElementById('f-name').value     = u.name;
  document.getElementById('f-username').value = u.username;
  document.getElementById('f-email').value    = u.email;
  document.getElementById('f-role').value     = u.role;
  document.getElementById('field-password').style.display = 'none';
  document.getElementById('f-username').disabled = true;
  setError('user', '');
  document.getElementById('modal-user').classList.remove('hidden');
}

async function submitUser() {
  const id = document.getElementById('edit-id').value;
  const payload = {
    name:     document.getElementById('f-name').value.trim(),
    username: document.getElementById('f-username').value.trim(),
    email:    document.getElementById('f-email').value.trim(),
    role:     document.getElementById('f-role').value,
  };
  if (!id) payload.password = document.getElementById('f-password').value.trim();

  const action = id ? 'edit' : 'add';
  if (id) payload.id = id;

  const btn = document.getElementById('modal-user-btn');
  btn.disabled = true; btn.textContent = 'שומר...';
  const r = await call(action, payload);
  btn.disabled = false; btn.textContent = id ? 'שמור שינויים' : 'הוסף משתמש';

  if (!r.ok) { setError('user', r.error); return; }
  closeModals();
  toast(id ? 'המשתמש עודכן' : 'המשתמש נוסף', 'success');
  loadUsers();
}

// ── Password ──────────────────────────────────────────────────
function openPwd(id, name) {
  document.getElementById('pwd-subtitle').textContent = 'עדכן סיסמה עבור ' + name;
  document.getElementById('pwd-user-id').value = id;
  document.getElementById('new-pwd').value     = '';
  document.getElementById('confirm-pwd').value = '';
  setError('pwd', '');
  document.getElementById('modal-pwd').classList.remove('hidden');
  setTimeout(() => document.getElementById('new-pwd').focus(), 100);
}

async function submitPwd() {
  const id  = document.getElementById('pwd-user-id').value;
  const pwd = document.getElementById('new-pwd').value.trim();
  const cfm = document.getElementById('confirm-pwd').value.trim();
  if (pwd !== cfm) { setError('pwd', 'הסיסמאות אינן תואמות'); return; }
  const r = await call('set-password', { id, password: pwd });
  if (!r.ok) { setError('pwd', r.error); return; }
  closeModals();
  toast('הסיסמה עודכנה', 'success');
}

// ── Delete ────────────────────────────────────────────────────
function openDel(id, name) {
  document.getElementById('del-subtitle').textContent = `האם למחוק את "${name}"? פעולה זו בלתי הפיכה.`;
  document.getElementById('del-user-id').value = id;
  document.getElementById('modal-del').classList.remove('hidden');
}

async function submitDelete() {
  const id = document.getElementById('del-user-id').value;
  const r  = await call('delete', { id });
  if (!r.ok) { toast(r.error, 'error'); return; }
  closeModals();
  toast('המשתמש נמחק');
  loadUsers();
}

// ── Utils ─────────────────────────────────────────────────────
function closeModals() {
  ['modal-user','modal-pwd','modal-del'].forEach(id => document.getElementById(id).classList.add('hidden'));
}

function setError(prefix, msg) {
  const el = document.getElementById(prefix + '-error');
  el.textContent = msg;
  el.classList.toggle('hidden', !msg);
}

document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if (e.target === m) closeModals(); }));

loadUsers();
</script>
</body>
</html>
