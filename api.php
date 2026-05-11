<?php
// ─────────────────────────────────────────────
//  api.php  –  Handles all AJAX actions
//  Called by JavaScript in index.php
// ─────────────────────────────────────────────

session_start();
header('Content-Type: application/json');
require_once 'db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── SIGN UP ───────────────────────────────
    case 'signup':
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password =       $_POST['password'] ?? '';

        if (strlen($username) < 3 || strlen($username) > 30)
            exit(json_encode(['ok' => false, 'msg' => 'Username must be 3–30 characters.']));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            exit(json_encode(['ok' => false, 'msg' => 'Invalid email address.']));

        if (strlen($password) < 6)
            exit(json_encode(['ok' => false, 'msg' => 'Password must be at least 6 characters.']));

        $db   = getDB();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $username, $email, $hash);

        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            $_SESSION['user_id'] = $id;
            $user = getUserById($db, $id);
            exit(json_encode(['ok' => true, 'user' => $user]));
        } else {
            $err = str_contains($db->error, 'username')
                ? 'Username already taken.'
                : 'Email already registered.';
            exit(json_encode(['ok' => false, 'msg' => $err]));
        }

    // ── SIGN IN ───────────────────────────────
    case 'login':
        $username = trim($_POST['username'] ?? '');
        $password =       $_POST['password'] ?? '';

        $db   = getDB();
        $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || !password_verify($password, $row['password']))
            exit(json_encode(['ok' => false, 'msg' => 'Incorrect username or password.']));

        $_SESSION['user_id'] = $row['id'];
        $user = getUserById($db, $row['id']);
        exit(json_encode(['ok' => true, 'user' => $user]));

    // ── SIGN OUT ──────────────────────────────
    case 'logout':
        session_destroy();
        exit(json_encode(['ok' => true]));

    // ── GET CURRENT SESSION ───────────────────
    case 'session':
        if (empty($_SESSION['user_id']))
            exit(json_encode(['ok' => false]));
        $db   = getDB();
        $user = getUserById($db, $_SESSION['user_id']);
        exit(json_encode(['ok' => (bool)$user, 'user' => $user]));

    // ── UPDATE PROFILE ────────────────────────
    case 'update_profile':
        if (empty($_SESSION['user_id']))
            exit(json_encode(['ok' => false, 'msg' => 'Not logged in.']));

        $email       = trim($_POST['email']       ?? '');
        $newPassword =       $_POST['new_password'] ?? '';
        $db          = getDB();
        $id          = $_SESSION['user_id'];

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
            exit(json_encode(['ok' => false, 'msg' => 'Invalid email address.']));

        if ($email) {
            $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->bind_param('si', $email, $id);
            if (!$stmt->execute())
                exit(json_encode(['ok' => false, 'msg' => 'Email already in use.']));
        }

        if (strlen($newPassword) >= 6) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $id);
            $stmt->execute();
        }

        $user = getUserById($db, $id);
        exit(json_encode(['ok' => true, 'user' => $user]));

    // ── LEADERBOARD ───────────────────────────
    case 'leaderboard':
        $db    = getDB();
        $limit = (int)($_GET['limit'] ?? 20);
        $result = $db->query("
            SELECT username, score, wins, played
            FROM   users
            ORDER  BY score DESC
            LIMIT  $limit
        ");
        $rows = [];
        while ($r = $result->fetch_assoc()) $rows[] = $r;
        exit(json_encode(['ok' => true, 'players' => $rows]));

    // ── ADD SCORE (call this from your game logic) ──
    case 'add_score':
        if (empty($_SESSION['user_id']))
            exit(json_encode(['ok' => false, 'msg' => 'Not logged in.']));

        $points = max(0, (int)($_POST['points'] ?? 0));
        $won    = !empty($_POST['won']) ? 1 : 0;
        $db     = getDB();
        $id     = $_SESSION['user_id'];

        $stmt = $db->prepare("
            UPDATE users
            SET    score  = score  + ?,
                   wins   = wins   + ?,
                   played = played + 1
            WHERE  id = ?
        ");
        $stmt->bind_param('iii', $points, $won, $id);
        $stmt->execute();
        $user = getUserById($db, $id);
        exit(json_encode(['ok' => true, 'user' => $user]));

    default:
        exit(json_encode(['ok' => false, 'msg' => 'Unknown action.']));
}

// ── HELPER ────────────────────────────────────
function getUserById(mysqli $db, int $id): ?array {
    $stmt = $db->prepare("
        SELECT id, username, email, score, wins, played,
               DATE_FORMAT(joined, '%M %Y') AS joined
        FROM   users WHERE id = ?
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}