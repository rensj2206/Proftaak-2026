<?php
// ─────────────────────────────────────────────
//  index.php  –  PlasmaticGames main page
// ─────────────────────────────────────────────
session_start();
require_once 'db.php';

// Pre-load leaderboard server-side (top 10)
$db = getDB();
$lbResult = $db->query("SELECT username, score FROM users ORDER BY score DESC LIMIT 10");
$leaderboard = [];
while ($row = $lbResult->fetch_assoc()) $leaderboard[] = $row;

// Pre-load current user if session exists
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT id, username, email, score, wins, played, DATE_FORMAT(joined,'%M %Y') AS joined FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $currentUser = $stmt->get_result()->fetch_assoc() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PlasmaticGames</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@300;400;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="style.css"/>
</head>
<body>

<!-- ── NAV ─────────────────────────────────── -->
<div class="nav-links">
    <a href="#" onclick="showPage('home')">Home</a>
    <a href="#" onclick="showPage('home')">Games</a>
    <a href="#" onclick="showPage('leaderboard')">Leaderboard</a>
    <a href="ItemShop.php">Item Shop</a>  <!-- Links to your existing ItemShop.php -->
    <a href="Locker.php">Locker</a>       <!-- Links to your existing Locker.php -->
    <a href="#" onclick="showPage('profile')">Profile</a>
</div>
    <div class="nav-user" onclick="showPage('profile')">
        <div class="nav-avatar" id="nav-avatar">
            <?= $currentUser ? strtoupper($currentUser['username'][0]) : '?' ?>
        </div>
        <span id="nav-username"><?= $currentUser ? htmlspecialchars($currentUser['username']) : 'Sign In' ?></span>
    </div>
</nav>

<!-- ── LAYOUT ──────────────────────────────── -->
<div class="layout">

    <!-- SIDEBAR LEADERBOARD -->
    <aside class="sidebar">
        <div class="lb-title">🏆 Leaderboard</div>
        <div id="sidebar-lb">
            <?php if (empty($leaderboard)): ?>
                <p class="empty-msg">No players yet. Sign up!</p>
            <?php else: foreach ($leaderboard as $i => $p):
                $isMe = $currentUser && $p['username'] === $currentUser['username'];
                $medal = match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => ($i+1) };
                $rankClass = match($i) { 0 => 'top1', 1 => 'top2', 2 => 'top3', default => '' };
            ?>
                <div class="lb-row<?= $isMe ? ' me' : '' ?>">
                    <div class="lb-rank <?= $rankClass ?>"><?= $medal ?></div>
                    <div class="lb-name"><?= htmlspecialchars($p['username']) ?><?= $isMe ? ' <span class="you">(you)</span>' : '' ?></div>
                    <div class="lb-score"><?= $p['score'] ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main id="main-content">

        <!-- HOME PAGE -->
        <div id="page-home" class="page active">
            <h1 class="page-title">Choose a Game</h1>
            <p class="page-sub">Pick a game to start playing!</p>
            <div class="games-grid">
                <div class="game-card">
                    <div class="game-icon">🎴</div>
                    <div class="game-name">UNO</div>
                    <div class="game-desc">Match colors and numbers</div>
                    <button class="btn" onclick="handlePlay('UNO')">Play</button>
                </div>
                <div class="game-card">
                    <div class="game-icon">⭕</div>
                    <div class="game-name">Noughts &amp; Crosses</div>
                    <div class="game-desc">Classic 2-player game</div>
                    <button class="btn" onclick="handlePlay('Noughts &amp; Crosses')">Play</button>
                </div>
                <div class="game-card">
                    <div class="game-icon">🔴</div>
                    <div class="game-name">4 in a Row</div>
                    <div class="game-desc">Connect four to win</div>
                    <button class="btn" onclick="handlePlay('4 in a Row')">Play</button>
                </div>
            </div>
        </div>

        <!-- LEADERBOARD PAGE -->
        <div id="page-leaderboard" class="page">
            <h1 class="page-title">Leaderboard</h1>
            <p class="page-sub">Top players on PlasmaticGames</p>
            <div class="lb-page-card" id="lb-full-list">
                <p class="empty-msg">Loading...</p>
            </div>
        </div>

        <!-- PROFILE PAGE -->
        <div id="page-profile" class="page">

            <!-- NOT LOGGED IN: AUTH FORMS -->
            <div id="auth-section" class="profile-wrap" <?= $currentUser ? 'style="display:none"' : '' ?>>
                <h1 class="page-title" style="font-size:1.5rem;margin-bottom:1.5rem">Your Account</h1>
                <div class="profile-card">
                    <div class="auth-tabs">
                        <button class="auth-tab active" id="tab-login" onclick="switchTab('login')">Sign In</button>
                        <button class="auth-tab" id="tab-signup" onclick="switchTab('signup')">Sign Up</button>
                    </div>
                    <div id="auth-msg"></div>

                    <!-- LOGIN FORM -->
                    <div id="form-login">
                        <div class="form-group">
                            <label>Username</label>
                            <input id="login-username" type="text" placeholder="YourUsername"/>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input id="login-password" type="password" placeholder="••••••••"/>
                        </div>
                        <button class="btn full" onclick="doLogin()">Sign In</button>
                    </div>

                    <!-- SIGNUP FORM -->
                    <div id="form-signup" style="display:none">
                        <div class="form-group">
                            <label>Username</label>
                            <input id="signup-username" type="text" placeholder="ChooseAUsername"/>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input id="signup-email" type="email" placeholder="you@example.com"/>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input id="signup-password" type="password" placeholder="Min 6 characters"/>
                        </div>
                        <button class="btn full" onclick="doSignup()">Create Account</button>
                    </div>
                </div>
            </div>

            <!-- LOGGED IN: PROFILE VIEW -->
            <div id="profile-section" class="profile-wrap" <?= !$currentUser ? 'style="display:none"' : '' ?>>
                <h1 class="page-title" style="font-size:1.5rem;margin-bottom:1.5rem">My Profile</h1>
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="big-avatar" id="profile-avatar">
                            <?= $currentUser ? strtoupper($currentUser['username'][0]) : '' ?>
                        </div>
                        <div class="profile-username" id="profile-username">
                            <?= $currentUser ? htmlspecialchars($currentUser['username']) : '' ?>
                        </div>
                        <div class="profile-joined" id="profile-joined">
                            Member since <?= $currentUser ? htmlspecialchars($currentUser['joined']) : '' ?>
                        </div>
                    </div>

                    <div class="stats-row">
                        <div class="stat-box">
                            <div class="stat-val" id="stat-score"><?= $currentUser ? $currentUser['score'] : 0 ?></div>
                            <div class="stat-lbl">Score</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-val" id="stat-wins"><?= $currentUser ? $currentUser['wins'] : 0 ?></div>
                            <div class="stat-lbl">Wins</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-val" id="stat-played"><?= $currentUser ? $currentUser['played'] : 0 ?></div>
                            <div class="stat-lbl">Games Played</div>
                        </div>
                    </div>

                    <hr class="divider"/>

                    <!-- EDIT SECTION -->
                    <div id="edit-msg"></div>
                    <div class="form-group">
                        <label>Email</label>
                        <input id="edit-email" type="email" placeholder="new email (optional)"
                               value="<?= $currentUser ? htmlspecialchars($currentUser['email']) : '' ?>"/>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input id="edit-password" type="password" placeholder="Leave blank to keep current"/>
                    </div>
                    <div class="edit-actions">
                        <button class="btn" onclick="doUpdateProfile()">Save Changes</button>
                        <button class="btn secondary" onclick="doLogout()">Sign Out</button>
                    </div>
                </div>
            </div>

        </div><!-- /page-profile -->
    </main>
</div>

<script>
// ── CURRENT USER (from PHP) ──────────────────
let currentUser = <?= $currentUser ? json_encode($currentUser) : 'null' ?>;

// ── PAGE SWITCHING ────────────────────────────
function showPage(name) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById('page-' + name).classList.add('active');
    if (name === 'leaderboard') loadFullLeaderboard();
}

// ── AUTH TABS ─────────────────────────────────
function switchTab(tab) {
    document.getElementById('form-login').style.display   = tab === 'login'  ? '' : 'none';
    document.getElementById('form-signup').style.display  = tab === 'signup' ? '' : 'none';
    document.getElementById('tab-login').classList.toggle('active', tab === 'login');
    document.getElementById('tab-signup').classList.toggle('active', tab === 'signup');
    document.getElementById('auth-msg').innerHTML = '';
}

// ── SHOW MESSAGE ──────────────────────────────
function showMsg(elId, msg, isOk) {
    const el = document.getElementById(elId);
    el.innerHTML = `<div class="msg ${isOk ? 'ok' : 'err'}">${msg}</div>`;
}

// ── API CALL HELPER ───────────────────────────
async function api(data) {
    const body = new URLSearchParams(data);
    const res  = await fetch('api.php', { method: 'POST', body });
    return res.json();
}

// ── LOGIN ─────────────────────────────────────
async function doLogin() {
    const username = document.getElementById('login-username').value.trim();
    const password = document.getElementById('login-password').value;
    if (!username || !password) return showMsg('auth-msg', 'Please fill in all fields.', false);

    const r = await api({ action: 'login', username, password });
    if (r.ok) {
        onLogin(r.user);
    } else {
        showMsg('auth-msg', r.msg, false);
    }
}

// ── SIGN UP ───────────────────────────────────
async function doSignup() {
    const username = document.getElementById('signup-username').value.trim();
    const email    = document.getElementById('signup-email').value.trim();
    const password = document.getElementById('signup-password').value;
    if (!username || !email || !password) return showMsg('auth-msg', 'Please fill in all fields.', false);

    const r = await api({ action: 'signup', username, email, password });
    if (r.ok) {
        onLogin(r.user);
    } else {
        showMsg('auth-msg', r.msg, false);
    }
}

// ── AFTER LOGIN / SIGNUP ──────────────────────
function onLogin(user) {
    currentUser = user;
    // Update nav
    document.getElementById('nav-avatar').textContent   = user.username[0].toUpperCase();
    document.getElementById('nav-username').textContent = user.username;
    // Show profile section
    document.getElementById('auth-section').style.display    = 'none';
    document.getElementById('profile-section').style.display = '';
    // Fill profile
    fillProfile(user);
    // Refresh leaderboard sidebar
    refreshSidebar();
}

// ── FILL PROFILE FIELDS ───────────────────────
function fillProfile(user) {
    document.getElementById('profile-avatar').textContent  = user.username[0].toUpperCase();
    document.getElementById('profile-username').textContent = user.username;
    document.getElementById('profile-joined').textContent  = 'Member since ' + user.joined;
    document.getElementById('stat-score').textContent  = user.score;
    document.getElementById('stat-wins').textContent   = user.wins;
    document.getElementById('stat-played').textContent = user.played;
    document.getElementById('edit-email').value = user.email;
}

// ── UPDATE PROFILE ────────────────────────────
async function doUpdateProfile() {
    const email       = document.getElementById('edit-email').value.trim();
    const new_password = document.getElementById('edit-password').value;
    const r = await api({ action: 'update_profile', email, new_password });
    if (r.ok) {
        currentUser = r.user;
        fillProfile(r.user);
        document.getElementById('edit-password').value = '';
        showMsg('edit-msg', 'Profile updated!', true);
    } else {
        showMsg('edit-msg', r.msg, false);
    }
}

// ── LOGOUT ────────────────────────────────────
async function doLogout() {
    await api({ action: 'logout' });
    currentUser = null;
    document.getElementById('nav-avatar').textContent   = '?';
    document.getElementById('nav-username').textContent = 'Sign In';
    document.getElementById('auth-section').style.display    = '';
    document.getElementById('profile-section').style.display = 'none';
    document.getElementById('auth-msg').innerHTML = '';
    refreshSidebar();
}

// ── LOAD FULL LEADERBOARD PAGE ────────────────
async function loadFullLeaderboard() {
    const res  = await fetch('api.php?action=leaderboard&limit=50');
    const data = await res.json();
    const el   = document.getElementById('lb-full-list');
    if (!data.ok || !data.players.length) {
        el.innerHTML = '<p class="empty-msg">No players yet. Sign up and start playing!</p>';
        return;
    }
    el.innerHTML = data.players.map((p, i) => {
        const isMe  = currentUser && p.username === currentUser.username;
        const medal = [, '🥇','🥈','🥉'][i+1] || ('#'+(i+1));
        return `<div class="lb-page-row${isMe ? ' me' : ''}">
            <div class="lb-page-rank">${medal}</div>
            <div class="lb-page-avatar">${p.username[0].toUpperCase()}</div>
            <div class="lb-page-info">
                <div class="lb-page-name">${p.username}${isMe ? ' <span class="badge">You</span>' : ''}</div>
                <div class="lb-page-sub">${p.wins} wins · ${p.played} games</div>
            </div>
            <div class="lb-page-score">${p.score} pts</div>
        </div>`;
    }).join('');
}

// ── REFRESH SIDEBAR LEADERBOARD ───────────────
async function refreshSidebar() {
    const res  = await fetch('api.php?action=leaderboard&limit=10');
    const data = await res.json();
    const el   = document.getElementById('sidebar-lb');
    if (!data.ok || !data.players.length) {
        el.innerHTML = '<p class="empty-msg">No players yet. Sign up!</p>';
        return;
    }
    el.innerHTML = data.players.map((p, i) => {
        const isMe     = currentUser && p.username === currentUser.username;
        const medal    = [,'🥇','🥈','🥉'][i+1] || (i+1);
        const rankClass = i === 0 ? 'top1' : i === 1 ? 'top2' : i === 2 ? 'top3' : '';
        return `<div class="lb-row${isMe ? ' me' : ''}">
            <div class="lb-rank ${rankClass}">${medal}</div>
            <div class="lb-name">${p.username}${isMe ? ' <span class="you">(you)</span>' : ''}</div>
            <div class="lb-score">${p.score}</div>
        </div>`;
    }).join('');
}

// ── PLAY BUTTON ───────────────────────────────
function handlePlay(name) {
    if (!currentUser) {
        alert('Please sign in to play!');
        showPage('profile');
        return;
    }
    alert('Launching ' + name + '…\n(Connect your game engine here)');
    // To add score after a game, call:
    // await api({ action: 'add_score', points: 100, won: 1 });
}
</script>
</body>
</html>