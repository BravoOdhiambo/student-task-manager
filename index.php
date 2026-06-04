<?php
/**
 * ICS 2203 - Group Assignment
 * Application: TaskFlow — Student Task & Project Manager
 * Stack: PHP (backend) + SQLite (database) + JavaScript (frontend)
 * Single-file architecture with embedded SQL, API, and UI
 */

// ============================================================
// DATABASE SETUP (SQLite via PDO)
// ============================================================
$db_path = __DIR__ . '/taskflow.db';

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create tables if they don't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            avatar_color TEXT DEFAULT '#6366f1',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            color TEXT DEFAULT '#6366f1',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            priority TEXT DEFAULT 'medium',
            status TEXT DEFAULT 'todo',
            due_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ");
} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

// ============================================================
// SESSION & AUTH HELPERS
// ============================================================
session_start();

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function current_user(): ?array {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return null;
    $stmt = $pdo->prepare("SELECT id, name, email, avatar_color, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ============================================================
// API ROUTER
// ============================================================
$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    switch ($action) {

        // --- AUTH ---
        case 'register':
            if ($request_method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $body = json_decode(file_get_contents('php://input'), true);
            $name  = trim($body['name'] ?? '');
            $email = trim($body['email'] ?? '');
            $pass  = $body['password'] ?? '';

            if (!$name || !$email || !$pass)
                json_response(['error' => 'All fields are required'], 400);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                json_response(['error' => 'Invalid email address'], 400);
            if (strlen($pass) < 6)
                json_response(['error' => 'Password must be at least 6 characters'], 400);

            $colors = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#8b5cf6'];
            $color  = $colors[array_rand($colors)];

            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, avatar_color) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $color]);
                $user_id = $pdo->lastInsertId();
                $_SESSION['user_id'] = $user_id;

                // Seed a sample project
                $pdo->prepare("INSERT INTO projects (user_id, title, description, color) VALUES (?, ?, ?, ?)")
                    ->execute([$user_id, 'My First Project', 'Welcome to TaskFlow! Start adding tasks.', '#6366f1']);

                json_response(['success' => true, 'message' => 'Registration successful']);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'UNIQUE') !== false)
                    json_response(['error' => 'Email already registered'], 409);
                json_response(['error' => 'Registration failed'], 500);
            }

        case 'login':
            if ($request_method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $body  = json_decode(file_get_contents('php://input'), true);
            $email = trim($body['email'] ?? '');
            $pass  = $body['password'] ?? '';

            if (!$email || !$pass)
                json_response(['error' => 'Email and password are required'], 400);

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($pass, $user['password']))
                json_response(['error' => 'Invalid email or password'], 401);

            $_SESSION['user_id'] = $user['id'];
            json_response(['success' => true, 'user' => [
                'id' => $user['id'], 'name' => $user['name'],
                'email' => $user['email'], 'avatar_color' => $user['avatar_color']
            ]]);

        case 'logout':
            session_destroy();
            json_response(['success' => true]);

        case 'me':
            $user = current_user();
            if (!$user) json_response(['error' => 'Not authenticated'], 401);
            json_response(['user' => $user]);

        // --- PROJECTS ---
        case 'get_projects':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            $stmt = $pdo->prepare("
                SELECT p.*, COUNT(t.id) as task_count,
                       SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) as done_count
                FROM projects p
                LEFT JOIN tasks t ON t.project_id = p.id
                WHERE p.user_id = ?
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$_SESSION['user_id']]);
            json_response(['projects' => $stmt->fetchAll()]);

        case 'create_project':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            if ($request_method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $body  = json_decode(file_get_contents('php://input'), true);
            $title = trim($body['title'] ?? '');
            $desc  = trim($body['description'] ?? '');
            $color = $body['color'] ?? '#6366f1';

            if (!$title) json_response(['error' => 'Project title is required'], 400);
            if (strlen($title) > 100) json_response(['error' => 'Title too long (max 100 chars)'], 400);

            $stmt = $pdo->prepare("INSERT INTO projects (user_id, title, description, color) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $title, $desc, $color]);
            json_response(['success' => true, 'id' => $pdo->lastInsertId()]);

        case 'delete_project':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            if ($request_method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $body = json_decode(file_get_contents('php://input'), true);
            $id   = (int)($body['id'] ?? 0);
            $pdo->prepare("DELETE FROM tasks WHERE project_id = ? AND user_id = ?")->execute([$id, $_SESSION['user_id']]);
            $pdo->prepare("DELETE FROM projects WHERE id = ? AND user_id = ?")->execute([$id, $_SESSION['user_id']]);
            json_response(['success' => true]);

        // --- TASKS ---
        case 'get_tasks':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            $project_id = (int)($_GET['project_id'] ?? 0);
            if (!$project_id) json_response(['error' => 'Project ID required'], 400);
            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE project_id = ? AND user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$project_id, $_SESSION['user_id']]);
            json_response(['tasks' => $stmt->fetchAll()]);

        case 'create_task':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            if ($request_method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $body       = json_decode(file_get_contents('php://input'), true);
            $project_id = (int)($body['project_id'] ?? 0);
            $title      = trim($body['title'] ?? '');
            $desc       = trim($body['description'] ?? '');
            $priority   = in_array($body['priority'] ?? '', ['low','medium','high']) ? $body['priority'] : 'medium';
            $due_date   = $body['due_date'] ?? null;

            if (!$title) json_response(['error' => 'Task title is required'], 400);
            if (!$project_id) json_response(['error' => 'Project ID required'], 400);
            if (strlen($title) > 200) json_response(['error' => 'Title too long (max 200 chars)'], 400);
            if ($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date))
                json_response(['error' => 'Invalid due date format'], 400);

            $stmt = $pdo->prepare("INSERT INTO tasks (project_id, user_id, title, description, priority, due_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$project_id, $_SESSION['user_id'], $title, $desc, $priority, $due_date ?: null]);
            json_response(['success' => true, 'id' => $pdo->lastInsertId()]);

        case 'update_task_status':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            if ($request_method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $body   = json_decode(file_get_contents('php://input'), true);
            $id     = (int)($body['id'] ?? 0);
            $status = $body['status'] ?? '';
            if (!in_array($status, ['todo','in_progress','done']))
                json_response(['error' => 'Invalid status'], 400);
            $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ? AND user_id = ?")->execute([$status, $id, $_SESSION['user_id']]);
            json_response(['success' => true]);

        case 'delete_task':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            if ($request_method !== 'POST') json_response(['error' => 'Method not allowed'], 405);
            $body = json_decode(file_get_contents('php://input'), true);
            $id   = (int)($body['id'] ?? 0);
            $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?")->execute([$id, $_SESSION['user_id']]);
            json_response(['success' => true]);

        case 'get_stats':
            if (!is_logged_in()) json_response(['error' => 'Not authenticated'], 401);
            $uid = $_SESSION['user_id'];
            $stats = [];
            $stats['total_projects'] = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ?")->execute([$uid]) ? $pdo->query("SELECT COUNT(*) FROM projects WHERE user_id = $uid")->fetchColumn() : 0;

            $rows = $pdo->prepare("SELECT status, COUNT(*) as c FROM tasks WHERE user_id = ? GROUP BY status");
            $rows->execute([$uid]);
            $stats['tasks'] = ['todo' => 0, 'in_progress' => 0, 'done' => 0];
            foreach ($rows->fetchAll() as $r) $stats['tasks'][$r['status']] = (int)$r['c'];

            // Upcoming tasks (due in next 7 days)
            $upcoming = $pdo->prepare("SELECT t.*, p.title as project_title, p.color FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.user_id = ? AND t.due_date IS NOT NULL AND t.due_date >= date('now') AND t.due_date <= date('now','+7 days') AND t.status != 'done' ORDER BY t.due_date ASC LIMIT 5");
            $upcoming->execute([$uid]);
            $stats['upcoming'] = $upcoming->fetchAll();

            json_response(['stats' => $stats]);

        default:
            json_response(['error' => 'Unknown action'], 404);
    }
    exit;
}
// ============================================================
// HTML RENDER (only when no ?action= param)
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TaskFlow — Student Project Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<style>
/* ===== RESET & VARIABLES ===== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0d0d12;
  --bg2:       #13131c;
  --bg3:       #1a1a28;
  --border:    rgba(255,255,255,0.07);
  --text:      #e8e8f0;
  --muted:     #7878a0;
  --accent:    #6366f1;
  --accent2:   #818cf8;
  --success:   #10b981;
  --warn:      #f59e0b;
  --danger:    #ef4444;
  --pink:      #ec4899;
  --radius:    14px;
  --radius-sm: 8px;
  --shadow:    0 8px 32px rgba(0,0,0,0.4);
  --font-head: 'Syne', sans-serif;
  --font-body: 'DM Sans', sans-serif;
  --sidebar-w: 260px;
  --transition: 0.2s cubic-bezier(0.4,0,0.2,1);
}

html { scroll-behavior: smooth; }
body {
  font-family: var(--font-body);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  line-height: 1.6;
  overflow-x: hidden;
}

/* ===== SCROLLBAR ===== */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--bg3); border-radius: 3px; }

/* ===== AUTH SCREEN ===== */
#auth-screen {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg);
  position: relative;
  overflow: hidden;
}

#auth-screen::before {
  content: '';
  position: absolute;
  width: 600px; height: 600px;
  background: radial-gradient(circle, rgba(99,102,241,0.15) 0%, transparent 70%);
  top: -200px; right: -200px;
  border-radius: 50%;
  animation: float 8s ease-in-out infinite;
}
#auth-screen::after {
  content: '';
  position: absolute;
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(236,72,153,0.1) 0%, transparent 70%);
  bottom: -150px; left: -100px;
  border-radius: 50%;
  animation: float 10s ease-in-out infinite reverse;
}
@keyframes float {
  0%,100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-30px) scale(1.05); }
}

.auth-card {
  width: 100%;
  max-width: 440px;
  padding: 2.5rem;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 20px;
  box-shadow: var(--shadow), 0 0 80px rgba(99,102,241,0.08);
  position: relative;
  z-index: 1;
  animation: slideUp 0.5s ease;
}
@keyframes slideUp {
  from { opacity: 0; transform: translateY(30px); }
  to   { opacity: 1; transform: translateY(0); }
}

.auth-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 2rem;
}
.auth-logo .logo-icon {
  width: 42px; height: 42px;
  background: linear-gradient(135deg, var(--accent), var(--pink));
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem;
}
.auth-logo h1 {
  font-family: var(--font-head);
  font-size: 1.5rem;
  font-weight: 800;
  background: linear-gradient(135deg, #fff 0%, var(--accent2) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.auth-tabs {
  display: flex;
  background: var(--bg3);
  border-radius: var(--radius-sm);
  padding: 4px;
  margin-bottom: 1.8rem;
}
.auth-tab {
  flex: 1;
  padding: 0.6rem;
  text-align: center;
  border-radius: 6px;
  cursor: pointer;
  font-family: var(--font-body);
  font-weight: 500;
  font-size: 0.9rem;
  color: var(--muted);
  border: none;
  background: transparent;
  transition: var(--transition);
}
.auth-tab.active {
  background: var(--accent);
  color: #fff;
  box-shadow: 0 2px 12px rgba(99,102,241,0.4);
}

.form-group { margin-bottom: 1.2rem; }
.form-label {
  display: block;
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--muted);
  margin-bottom: 0.4rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.form-input {
  width: 100%;
  padding: 0.75rem 1rem;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 0.95rem;
  transition: var(--transition);
  outline: none;
}
.form-input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.form-input::placeholder { color: var(--muted); }
.form-input.error { border-color: var(--danger); }

.error-msg {
  font-size: 0.8rem;
  color: var(--danger);
  margin-top: 0.3rem;
  display: none;
}
.error-msg.show { display: block; }

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.75rem 1.5rem;
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 0.95rem;
  font-weight: 500;
  cursor: pointer;
  border: none;
  transition: var(--transition);
  text-decoration: none;
  white-space: nowrap;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary {
  background: linear-gradient(135deg, var(--accent), #818cf8);
  color: #fff;
  width: 100%;
  padding: 0.85rem;
  font-size: 1rem;
  font-weight: 600;
  box-shadow: 0 4px 16px rgba(99,102,241,0.35);
}
.btn-primary:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 6px 24px rgba(99,102,241,0.5);
}
.btn-ghost {
  background: transparent;
  color: var(--muted);
  border: 1px solid var(--border);
}
.btn-ghost:hover { background: var(--bg3); color: var(--text); }
.btn-danger { background: rgba(239,68,68,0.15); color: var(--danger); border: 1px solid rgba(239,68,68,0.3); }
.btn-danger:hover { background: rgba(239,68,68,0.25); }
.btn-success { background: rgba(16,185,129,0.15); color: var(--success); border: 1px solid rgba(16,185,129,0.3); }
.btn-sm { padding: 0.4rem 0.8rem; font-size: 0.82rem; border-radius: 6px; }

.alert {
  padding: 0.75rem 1rem;
  border-radius: var(--radius-sm);
  font-size: 0.88rem;
  margin-bottom: 1rem;
  display: none;
}
.alert.show { display: block; animation: fadeIn 0.3s ease; }
.alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
.alert-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

/* ===== MAIN APP LAYOUT ===== */
#app { display: none; min-height: 100vh; }
#app.visible { display: flex; }

/* SIDEBAR */
.sidebar {
  width: var(--sidebar-w);
  min-height: 100vh;
  background: var(--bg2);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: fixed;
  left: 0; top: 0; bottom: 0;
  z-index: 100;
  transition: transform 0.3s ease;
}
.sidebar-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 1.5rem 1.2rem;
  border-bottom: 1px solid var(--border);
}
.sidebar-logo .logo-icon {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, var(--accent), var(--pink));
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem;
  flex-shrink: 0;
}
.sidebar-logo span {
  font-family: var(--font-head);
  font-weight: 800;
  font-size: 1.2rem;
  background: linear-gradient(135deg, #fff 0%, var(--accent2) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
.nav-section-title {
  padding: 0.5rem 1.2rem;
  font-size: 0.7rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--muted);
  font-weight: 600;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0.65rem 1.2rem;
  margin: 0 0.5rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
  color: var(--muted);
  font-size: 0.9rem;
  font-weight: 500;
  transition: var(--transition);
  user-select: none;
}
.nav-item:hover { background: var(--bg3); color: var(--text); }
.nav-item.active { background: rgba(99,102,241,0.15); color: var(--accent2); }
.nav-item .nav-icon { width: 18px; text-align: center; flex-shrink: 0; }
.nav-item .nav-badge {
  margin-left: auto;
  background: var(--accent);
  color: #fff;
  font-size: 0.7rem;
  padding: 1px 7px;
  border-radius: 20px;
  font-weight: 600;
}

.project-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.sidebar-footer {
  padding: 1rem 1.2rem;
  border-top: 1px solid var(--border);
}
.user-info {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0.6rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: var(--transition);
}
.user-info:hover { background: var(--bg3); }
.avatar {
  width: 34px; height: 34px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700;
  font-size: 0.85rem;
  color: #fff;
  flex-shrink: 0;
}
.user-details { flex: 1; min-width: 0; }
.user-name { font-size: 0.88rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-email { font-size: 0.75rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* MAIN CONTENT */
.main-content {
  flex: 1;
  margin-left: var(--sidebar-w);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.topbar {
  display: flex;
  align-items: center;
  padding: 1rem 2rem;
  border-bottom: 1px solid var(--border);
  background: var(--bg);
  position: sticky; top: 0; z-index: 50;
  gap: 1rem;
}
.topbar-title { font-family: var(--font-head); font-size: 1.2rem; font-weight: 700; flex: 1; }
.hamburger {
  display: none;
  flex-direction: column;
  gap: 5px;
  cursor: pointer;
  padding: 4px;
}
.hamburger span { width: 22px; height: 2px; background: var(--text); border-radius: 2px; transition: var(--transition); }

.page { display: none; padding: 2rem; animation: fadeIn 0.3s ease; }
.page.active { display: block; }

/* ===== DASHBOARD ===== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 1rem;
  margin-bottom: 2rem;
}
.stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.4rem;
  position: relative;
  overflow: hidden;
  transition: var(--transition);
}
.stat-card::after {
  content: '';
  position: absolute;
  top: 0; right: 0;
  width: 80px; height: 80px;
  border-radius: 50%;
  opacity: 0.08;
  transform: translate(20px, -20px);
}
.stat-card.indigo::after { background: var(--accent); }
.stat-card.green::after  { background: var(--success); }
.stat-card.amber::after  { background: var(--warn); }
.stat-card.pink::after   { background: var(--pink); }
.stat-value { font-family: var(--font-head); font-size: 2.2rem; font-weight: 800; line-height: 1; margin-bottom: 0.3rem; }
.stat-label { font-size: 0.82rem; color: var(--muted); font-weight: 500; }

.section-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1.2rem;
}
.section-title { font-family: var(--font-head); font-size: 1.1rem; font-weight: 700; }

.dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }

.card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.4rem;
}
.card-title { font-family: var(--font-head); font-size: 0.95rem; font-weight: 700; margin-bottom: 1rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; }

/* Progress bar */
.progress-wrap { margin-bottom: 1rem; }
.progress-label { display: flex; justify-content: space-between; font-size: 0.82rem; margin-bottom: 0.4rem; }
.progress-bar { height: 6px; background: var(--bg3); border-radius: 3px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 3px; transition: width 0.8s ease; }

/* Upcoming tasks */
.upcoming-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0.7rem 0;
  border-bottom: 1px solid var(--border);
}
.upcoming-item:last-child { border-bottom: none; }
.upcoming-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.upcoming-info { flex: 1; min-width: 0; }
.upcoming-title { font-size: 0.88rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.upcoming-meta { font-size: 0.75rem; color: var(--muted); }
.due-badge {
  font-size: 0.72rem;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
  flex-shrink: 0;
}
.due-soon { background: rgba(245,158,11,0.15); color: var(--warn); }
.due-today { background: rgba(239,68,68,0.15); color: var(--danger); }

/* ===== PROJECTS PAGE ===== */
.projects-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1.2rem;
}
.project-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.4rem;
  cursor: pointer;
  transition: var(--transition);
  position: relative;
  overflow: hidden;
}
.project-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
}
.project-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); border-color: rgba(255,255,255,0.12); }
.project-card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 0.8rem; }
.project-title { font-family: var(--font-head); font-size: 1rem; font-weight: 700; }
.project-desc { font-size: 0.85rem; color: var(--muted); margin-bottom: 1rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.project-footer { display: flex; align-items: center; justify-content: space-between; }
.project-progress-bar { flex: 1; height: 4px; background: var(--bg3); border-radius: 2px; overflow: hidden; margin-right: 0.8rem; }
.project-progress-fill { height: 100%; border-radius: 2px; }
.project-count { font-size: 0.78rem; color: var(--muted); }

.new-project-card {
  border: 2px dashed var(--border);
  background: transparent;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 150px;
  color: var(--muted);
  font-size: 0.9rem;
  font-weight: 500;
  transition: var(--transition);
}
.new-project-card:hover { border-color: var(--accent); color: var(--accent2); background: rgba(99,102,241,0.04); }
.new-project-card .plus { font-size: 2rem; font-weight: 300; }

/* ===== TASK VIEW ===== */
.task-header {
  display: flex; align-items: center; gap: 1rem;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
}
.back-btn {
  display: flex; align-items: center; gap: 6px;
  background: var(--bg2);
  border: 1px solid var(--border);
  color: var(--muted);
  padding: 0.5rem 0.9rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.85rem;
  transition: var(--transition);
}
.back-btn:hover { color: var(--text); border-color: rgba(255,255,255,0.15); }

.kanban-board { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
.kanban-col {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem;
  min-height: 400px;
}
.kanban-col-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1rem;
  padding-bottom: 0.8rem;
  border-bottom: 1px solid var(--border);
}
.col-title {
  display: flex; align-items: center; gap: 8px;
  font-family: var(--font-head);
  font-size: 0.88rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.col-count { font-size: 0.75rem; color: var(--muted); background: var(--bg3); padding: 1px 8px; border-radius: 10px; }
.col-dot { width: 8px; height: 8px; border-radius: 50%; }
.col-dot.todo { background: var(--muted); }
.col-dot.inprog { background: var(--warn); }
.col-dot.done { background: var(--success); }

.task-card {
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1rem;
  margin-bottom: 0.7rem;
  transition: var(--transition);
  position: relative;
}
.task-card:hover { border-color: rgba(255,255,255,0.15); }
.task-title { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; }
.task-desc { font-size: 0.8rem; color: var(--muted); margin-bottom: 0.7rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.task-meta { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.priority-badge {
  font-size: 0.7rem;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.priority-low  { background: rgba(99,102,241,0.15); color: var(--accent2); }
.priority-medium { background: rgba(245,158,11,0.15); color: var(--warn); }
.priority-high { background: rgba(239,68,68,0.15); color: var(--danger); }
.due-tag { font-size: 0.72rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }

.task-actions { display: flex; gap: 0.4rem; margin-top: 0.7rem; }

/* ===== MODAL ===== */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.7);
  backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  opacity: 0; pointer-events: none;
  transition: opacity 0.25s ease;
  padding: 1rem;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 18px;
  padding: 2rem;
  width: 100%;
  max-width: 480px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.6);
  transform: scale(0.95);
  transition: transform 0.25s ease;
  max-height: 90vh;
  overflow-y: auto;
}
.modal-overlay.open .modal { transform: scale(1); }
.modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
.modal-title { font-family: var(--font-head); font-size: 1.2rem; font-weight: 700; }
.modal-close {
  width: 30px; height: 30px;
  border-radius: 50%;
  background: var(--bg3);
  border: none;
  cursor: pointer;
  color: var(--muted);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem;
  transition: var(--transition);
}
.modal-close:hover { background: var(--bg); color: var(--text); }

/* Color picker */
.color-picker { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.color-swatch {
  width: 28px; height: 28px;
  border-radius: 50%;
  cursor: pointer;
  border: 2px solid transparent;
  transition: var(--transition);
}
.color-swatch.selected { border-color: #fff; transform: scale(1.2); }

/* ===== TOAST ===== */
#toast-container {
  position: fixed;
  bottom: 1.5rem; right: 1.5rem;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.toast {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 0.75rem 1.2rem;
  font-size: 0.88rem;
  max-width: 320px;
  box-shadow: var(--shadow);
  animation: toastIn 0.3s ease;
  display: flex; align-items: center; gap: 8px;
}
.toast.success { border-left: 3px solid var(--success); }
.toast.error   { border-left: 3px solid var(--danger); }
@keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
@keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(20px); } }

/* ===== LOADING ===== */
.spinner {
  width: 20px; height: 20px;
  border: 2px solid rgba(255,255,255,0.1);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  display: inline-block;
}
@keyframes spin { to { transform: rotate(360deg); } }

.empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--muted);
}
.empty-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
.empty-state h3 { font-family: var(--font-head); font-size: 1rem; color: var(--text); margin-bottom: 0.3rem; }
.empty-state p  { font-size: 0.85rem; }

/* ===== MOBILE ===== */
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); }
  .main-content { margin-left: 0; }
  .hamburger { display: flex; }
  .sidebar-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 99;
    display: none;
  }
  .sidebar-overlay.show { display: block; }
  .dashboard-grid { grid-template-columns: 1fr; }
  .kanban-board { grid-template-columns: 1fr; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .page { padding: 1.2rem; }
  .topbar { padding: 0.8rem 1.2rem; }
  .projects-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ==================== AUTH SCREEN ==================== -->
<div id="auth-screen">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-icon">✦</div>
      <h1>TaskFlow</h1>
    </div>

    <div class="auth-tabs">
      <button class="auth-tab active" onclick="switchTab('login')">Sign In</button>
      <button class="auth-tab" onclick="switchTab('register')">Register</button>
    </div>

    <div id="auth-alert" class="alert"></div>

    <!-- Login Form -->
    <div id="login-form">
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input type="email" id="login-email" class="form-input" placeholder="you@example.com" autocomplete="email">
        <div class="error-msg" id="login-email-err">Please enter a valid email.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" id="login-pass" class="form-input" placeholder="••••••••" autocomplete="current-password">
        <div class="error-msg" id="login-pass-err">Password is required.</div>
      </div>
      <button class="btn btn-primary" id="login-btn" onclick="handleLogin()">Sign In</button>
    </div>

    <!-- Register Form -->
    <div id="register-form" style="display:none">
      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input type="text" id="reg-name" class="form-input" placeholder="John Doe" autocomplete="name">
        <div class="error-msg" id="reg-name-err">Name is required.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input type="email" id="reg-email" class="form-input" placeholder="you@example.com" autocomplete="email">
        <div class="error-msg" id="reg-email-err">Please enter a valid email.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" id="reg-pass" class="form-input" placeholder="Min. 6 characters" autocomplete="new-password">
        <div class="error-msg" id="reg-pass-err">Password must be at least 6 characters.</div>
      </div>
      <button class="btn btn-primary" id="register-btn" onclick="handleRegister()">Create Account</button>
    </div>
  </div>
</div>

<!-- ==================== MAIN APP ==================== -->
<div id="app">
  <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="logo-icon">✦</div>
      <span>TaskFlow</span>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-title">Menu</div>
      <div class="nav-item active" onclick="showPage('dashboard')" data-page="dashboard">
        <span class="nav-icon">⬡</span> Dashboard
      </div>
      <div class="nav-item" onclick="showPage('projects')" data-page="projects">
        <span class="nav-icon">◈</span> Projects
      </div>

      <div class="nav-section-title" style="margin-top:1rem">Projects</div>
      <div id="sidebar-projects"></div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-info" id="sidebar-user" onclick="handleLogout()">
        <div class="avatar" id="sidebar-avatar">?</div>
        <div class="user-details">
          <div class="user-name" id="sidebar-name">Loading...</div>
          <div class="user-email" id="sidebar-email">Sign out</div>
        </div>
        <span style="color:var(--muted); font-size:0.8rem;">↩</span>
      </div>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">
    <header class="topbar">
      <div class="hamburger" onclick="toggleSidebar()">
        <span></span><span></span><span></span>
      </div>
      <div class="topbar-title" id="topbar-title">Dashboard</div>
      <button class="btn btn-primary btn-sm" id="topbar-action" onclick="openModal('project')" style="display:none">+ New Project</button>
    </header>

    <!-- Dashboard Page -->
    <div class="page active" id="page-dashboard">
      <div class="stats-grid">
        <div class="stat-card indigo">
          <div class="stat-value" id="stat-projects">—</div>
          <div class="stat-label">Projects</div>
        </div>
        <div class="stat-card green">
          <div class="stat-value" id="stat-done">—</div>
          <div class="stat-label">Done</div>
        </div>
        <div class="stat-card amber">
          <div class="stat-value" id="stat-inprog">—</div>
          <div class="stat-label">In Progress</div>
        </div>
        <div class="stat-card pink">
          <div class="stat-value" id="stat-todo">—</div>
          <div class="stat-label">To Do</div>
        </div>
      </div>

      <div class="dashboard-grid">
        <div class="card">
          <div class="card-title">Task Progress</div>
          <div id="task-progress-wrap">
            <div class="empty-state"><div class="spinner"></div></div>
          </div>
        </div>
        <div class="card">
          <div class="card-title">Upcoming Deadlines</div>
          <div id="upcoming-tasks">
            <div class="empty-state"><div class="spinner"></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Projects Page -->
    <div class="page" id="page-projects">
      <div class="projects-grid" id="projects-grid">
        <div class="stat-card" style="grid-column:1/-1; text-align:center; padding:3rem;">
          <div class="spinner" style="margin:0 auto;"></div>
        </div>
      </div>
    </div>

    <!-- Task View Page -->
    <div class="page" id="page-tasks">
      <div class="task-header">
        <button class="back-btn" onclick="showPage('projects')">← Back</button>
        <div style="flex:1">
          <h2 id="task-project-title" style="font-family:var(--font-head); font-size:1.3rem; font-weight:800;"></h2>
          <p id="task-project-desc" style="font-size:0.85rem; color:var(--muted); margin-top:2px;"></p>
        </div>
        <button class="btn btn-primary btn-sm" onclick="openModal('task')">+ Add Task</button>
      </div>
      <div class="kanban-board" id="kanban-board"></div>
    </div>
  </main>
</div>

<!-- ==================== MODALS ==================== -->
<!-- New Project Modal -->
<div class="modal-overlay" id="modal-project">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">New Project</h3>
      <button class="modal-close" onclick="closeModal('project')">✕</button>
    </div>
    <div id="project-modal-alert" class="alert"></div>
    <div class="form-group">
      <label class="form-label">Project Title *</label>
      <input type="text" id="proj-title" class="form-input" placeholder="e.g. Final Year Project" maxlength="100">
      <div class="error-msg" id="proj-title-err">Title is required.</div>
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea id="proj-desc" class="form-input" rows="3" placeholder="What is this project about?" style="resize:vertical;"></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Color</label>
      <div class="color-picker" id="proj-color-picker">
        <div class="color-swatch selected" style="background:#6366f1;" data-color="#6366f1" onclick="selectColor(this,'project')"></div>
        <div class="color-swatch" style="background:#ec4899;" data-color="#ec4899" onclick="selectColor(this,'project')"></div>
        <div class="color-swatch" style="background:#f59e0b;" data-color="#f59e0b" onclick="selectColor(this,'project')"></div>
        <div class="color-swatch" style="background:#10b981;" data-color="#10b981" onclick="selectColor(this,'project')"></div>
        <div class="color-swatch" style="background:#3b82f6;" data-color="#3b82f6" onclick="selectColor(this,'project')"></div>
        <div class="color-swatch" style="background:#8b5cf6;" data-color="#8b5cf6" onclick="selectColor(this,'project')"></div>
        <div class="color-swatch" style="background:#ef4444;" data-color="#ef4444" onclick="selectColor(this,'project')"></div>
      </div>
    </div>
    <div style="display:flex; gap:0.8rem; margin-top:1rem;">
      <button class="btn btn-ghost" style="flex:1" onclick="closeModal('project')">Cancel</button>
      <button class="btn btn-primary" style="flex:2" id="create-project-btn" onclick="createProject()">Create Project</button>
    </div>
  </div>
</div>

<!-- New Task Modal -->
<div class="modal-overlay" id="modal-task">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">Add Task</h3>
      <button class="modal-close" onclick="closeModal('task')">✕</button>
    </div>
    <div id="task-modal-alert" class="alert"></div>
    <div class="form-group">
      <label class="form-label">Task Title *</label>
      <input type="text" id="task-title" class="form-input" placeholder="e.g. Design database schema" maxlength="200">
      <div class="error-msg" id="task-title-err">Title is required.</div>
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea id="task-desc" class="form-input" rows="2" placeholder="Optional details..." style="resize:vertical;"></textarea>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
      <div class="form-group">
        <label class="form-label">Priority *</label>
        <select id="task-priority" class="form-input">
          <option value="low">🟢 Low</option>
          <option value="medium" selected>🟡 Medium</option>
          <option value="high">🔴 High</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Due Date</label>
        <input type="date" id="task-due" class="form-input">
      </div>
    </div>
    <div style="display:flex; gap:0.8rem; margin-top:0.5rem;">
      <button class="btn btn-ghost" style="flex:1" onclick="closeModal('task')">Cancel</button>
      <button class="btn btn-primary" style="flex:2" id="create-task-btn" onclick="createTask()">Add Task</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<!-- ==================== JAVASCRIPT ==================== -->
<script>
'use strict';

// ============================================================
// STATE
// ============================================================
const State = {
  user: null,
  projects: [],
  currentProjectId: null,
  selectedColors: { project: '#6366f1', task: '#6366f1' },
  tasks: [],
};

// ============================================================
// API HELPER
// ============================================================
async function api(action, method = 'GET', body = null) {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  const url = `?action=${action}`;
  const res = await fetch(url, opts);
  return res.json();
}

// ============================================================
// TOAST
// ============================================================
function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${type === 'success' ? '✓' : '✕'}</span> ${msg}`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => {
    el.style.animation = 'toastOut 0.3s ease forwards';
    setTimeout(() => el.remove(), 300);
  }, 3000);
}

// ============================================================
// AUTH
// ============================================================
function switchTab(tab) {
  document.getElementById('login-form').style.display    = tab === 'login' ? '' : 'none';
  document.getElementById('register-form').style.display = tab === 'register' ? '' : 'none';
  document.querySelectorAll('.auth-tab').forEach((t, i) =>
    t.classList.toggle('active', (i === 0 && tab === 'login') || (i === 1 && tab === 'register'))
  );
  hideAlert('auth-alert');
}

function showAlert(id, msg, type = 'error') {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.className = `alert alert-${type} show`;
}
function hideAlert(id) {
  const el = document.getElementById(id);
  if (el) el.className = 'alert';
}

function validateField(inputId, errId, validator) {
  const val = document.getElementById(inputId).value.trim();
  const err = document.getElementById(errId);
  const ok  = validator(val);
  document.getElementById(inputId).classList.toggle('error', !ok);
  err.classList.toggle('show', !ok);
  return ok;
}

async function handleLogin() {
  const emailOk = validateField('login-email', 'login-email-err', v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v));
  const passOk  = validateField('login-pass',  'login-pass-err',  v => v.length > 0);
  if (!emailOk || !passOk) return;

  const btn = document.getElementById('login-btn');
  btn.disabled = true; btn.innerHTML = '<div class="spinner"></div> Signing in...';
  hideAlert('auth-alert');

  const data = await api('login', 'POST', {
    email: document.getElementById('login-email').value.trim(),
    password: document.getElementById('login-pass').value,
  });

  btn.disabled = false; btn.textContent = 'Sign In';
  if (data.error) { showAlert('auth-alert', data.error); return; }
  await bootApp();
}

async function handleRegister() {
  const nameOk  = validateField('reg-name',  'reg-name-err',  v => v.length > 0);
  const emailOk = validateField('reg-email', 'reg-email-err', v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v));
  const passOk  = validateField('reg-pass',  'reg-pass-err',  v => v.length >= 6);
  if (!nameOk || !emailOk || !passOk) return;

  const btn = document.getElementById('register-btn');
  btn.disabled = true; btn.innerHTML = '<div class="spinner"></div> Creating account...';
  hideAlert('auth-alert');

  const data = await api('register', 'POST', {
    name: document.getElementById('reg-name').value.trim(),
    email: document.getElementById('reg-email').value.trim(),
    password: document.getElementById('reg-pass').value,
  });

  btn.disabled = false; btn.textContent = 'Create Account';
  if (data.error) { showAlert('auth-alert', data.error); return; }
  await bootApp();
}

async function handleLogout() {
  await api('logout', 'POST');
  document.getElementById('app').classList.remove('visible');
  document.getElementById('app').style.display = 'none';
  document.getElementById('auth-screen').style.display = 'flex';
  State.user = null;
  toast('Signed out successfully');
}

// ============================================================
// APP BOOT
// ============================================================
async function bootApp() {
  const data = await api('me');
  if (data.error || !data.user) return;

  State.user = data.user;
  document.getElementById('auth-screen').style.display = 'none';
  document.getElementById('app').style.display = 'flex';
  document.getElementById('app').classList.add('visible');

  // Update sidebar user
  const av = document.getElementById('sidebar-avatar');
  av.textContent = State.user.name.charAt(0).toUpperCase();
  av.style.background = State.user.avatar_color || '#6366f1';
  document.getElementById('sidebar-name').textContent  = State.user.name;
  document.getElementById('sidebar-email').textContent = State.user.email;

  showPage('dashboard');
  loadProjects();
}

// ============================================================
// NAVIGATION
// ============================================================
function showPage(page) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById(`page-${page === 'tasks' ? 'tasks' : page}`).classList.add('active');

  const titles = { dashboard: 'Dashboard', projects: 'Projects', tasks: 'Tasks' };
  document.getElementById('topbar-title').textContent = titles[page] || page;

  const actionBtn = document.getElementById('topbar-action');
  actionBtn.style.display = page === 'projects' ? '' : 'none';

  const navItem = document.querySelector(`[data-page="${page}"]`);
  if (navItem) navItem.classList.add('active');

  if (page === 'dashboard') loadDashboard();
  if (page === 'projects')  renderProjects();
  closeSidebar();
}

// ============================================================
// SIDEBAR MOBILE
// ============================================================
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebar-overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').classList.remove('show');
}

// ============================================================
// MODALS
// ============================================================
function openModal(type) {
  document.getElementById(`modal-${type}`).classList.add('open');
  // reset
  if (type === 'project') {
    document.getElementById('proj-title').value = '';
    document.getElementById('proj-desc').value = '';
    document.querySelectorAll('#proj-color-picker .color-swatch').forEach((s, i) => s.classList.toggle('selected', i === 0));
    State.selectedColors.project = '#6366f1';
    hideAlert('project-modal-alert');
  }
  if (type === 'task') {
    document.getElementById('task-title').value = '';
    document.getElementById('task-desc').value = '';
    document.getElementById('task-priority').value = 'medium';
    document.getElementById('task-due').value = '';
    hideAlert('task-modal-alert');
  }
}
function closeModal(type) {
  document.getElementById(`modal-${type}`).classList.remove('open');
}

function selectColor(el, type) {
  document.querySelectorAll(`#proj-color-picker .color-swatch`).forEach(s => s.classList.remove('selected'));
  el.classList.add('selected');
  State.selectedColors[type] = el.dataset.color;
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('open'); });
});

// ============================================================
// PROJECTS
// ============================================================
async function loadProjects() {
  const data = await api('get_projects');
  State.projects = data.projects || [];
  renderSidebarProjects();
  if (document.getElementById('page-projects').classList.contains('active')) renderProjects();
}

function renderSidebarProjects() {
  const el = document.getElementById('sidebar-projects');
  el.innerHTML = State.projects.map(p => `
    <div class="nav-item" onclick="openProject(${p.id})" style="gap:10px;">
      <span class="project-dot" style="background:${p.color}"></span>
      <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escHtml(p.title)}</span>
      <span style="font-size:0.72rem; color:var(--muted);">${p.task_count || 0}</span>
    </div>
  `).join('') || '<div style="padding:0.5rem 1.2rem; font-size:0.8rem; color:var(--muted);">No projects yet</div>';
}

function renderProjects() {
  const grid = document.getElementById('projects-grid');
  if (!State.projects.length) {
    grid.innerHTML = `
      <div class="new-project-card project-card" onclick="openModal('project')">
        <div class="plus">+</div>
        <span>Create your first project</span>
      </div>`;
    return;
  }

  grid.innerHTML = State.projects.map(p => {
    const pct = p.task_count > 0 ? Math.round((p.done_count / p.task_count) * 100) : 0;
    return `
      <div class="project-card" onclick="openProject(${p.id})" style="--proj-color:${p.color}">
        <div style="position:absolute; top:0; left:0; right:0; height:3px; background:${p.color}; border-radius:${p.id}px ${p.id}px 0 0;"></div>
        <div class="project-card-header">
          <div>
            <div class="project-title">${escHtml(p.title)}</div>
          </div>
          <button class="btn btn-danger btn-sm" onclick="event.stopPropagation(); deleteProject(${p.id})" title="Delete">✕</button>
        </div>
        <div class="project-desc">${escHtml(p.description || 'No description')}</div>
        <div class="project-footer">
          <div class="project-progress-bar">
            <div class="project-progress-fill" style="width:${pct}%; background:${p.color};"></div>
          </div>
          <span class="project-count">${p.done_count || 0}/${p.task_count || 0} done</span>
        </div>
      </div>`;
  }).join('') + `
    <div class="project-card new-project-card" onclick="openModal('project')">
      <div class="plus">+</div>
      <span>New Project</span>
    </div>`;
}

async function createProject() {
  const title = document.getElementById('proj-title').value.trim();
  const desc  = document.getElementById('proj-desc').value.trim();

  if (!title) {
    document.getElementById('proj-title').classList.add('error');
    document.getElementById('proj-title-err').classList.add('show');
    return;
  }

  const btn = document.getElementById('create-project-btn');
  btn.disabled = true; btn.innerHTML = '<div class="spinner"></div> Creating...';

  const data = await api('create_project', 'POST', { title, description: desc, color: State.selectedColors.project });

  btn.disabled = false; btn.textContent = 'Create Project';
  if (data.error) { showAlert('project-modal-alert', data.error); return; }

  closeModal('project');
  toast('Project created!');
  await loadProjects();
  renderProjects();
}

async function deleteProject(id) {
  if (!confirm('Delete this project and all its tasks?')) return;
  const data = await api('delete_project', 'POST', { id });
  if (data.error) { toast(data.error, 'error'); return; }
  toast('Project deleted');
  await loadProjects();
  renderProjects();
}

function openProject(id) {
  const p = State.projects.find(x => x.id == id);
  if (!p) return;
  State.currentProjectId = id;
  document.getElementById('task-project-title').textContent = p.title;
  document.getElementById('task-project-desc').textContent  = p.description || '';
  document.getElementById('topbar-title').textContent = p.title;
  showTaskPage(id);
}

// ============================================================
// TASKS
// ============================================================
async function showTaskPage(projectId) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-tasks').classList.add('active');
  document.getElementById('topbar-action').style.display = 'none';
  renderKanban([]);
  const data = await api(`get_tasks&project_id=${projectId}`);
  State.tasks = data.tasks || [];
  renderKanban(State.tasks);
}

function renderKanban(tasks) {
  const cols = [
    { status: 'todo',        label: 'To Do',       dotClass: 'todo' },
    { status: 'in_progress', label: 'In Progress',  dotClass: 'inprog' },
    { status: 'done',        label: 'Done',         dotClass: 'done' },
  ];

  document.getElementById('kanban-board').innerHTML = cols.map(col => {
    const colTasks = tasks.filter(t => t.status === col.status);
    return `
      <div class="kanban-col">
        <div class="kanban-col-header">
          <div class="col-title">
            <span class="col-dot ${col.dotClass}"></span>
            ${col.label}
          </div>
          <span class="col-count">${colTasks.length}</span>
        </div>
        <div id="col-${col.status}">
          ${colTasks.length ? colTasks.map(renderTaskCard).join('') : `<div class="empty-state" style="padding:2rem 0;"><div class="empty-icon" style="font-size:2rem;">○</div><p style="font-size:0.8rem;">No tasks here</p></div>`}
        </div>
      </div>`;
  }).join('');
}

function renderTaskCard(t) {
  const nextStatus = { todo: 'in_progress', in_progress: 'done', done: 'todo' };
  const nextLabel  = { todo: '▶ Start', in_progress: '✓ Done', done: '↩ Reset' };
  const dueStr = t.due_date ? `📅 ${t.due_date}` : '';
  return `
    <div class="task-card">
      <div class="task-title">${escHtml(t.title)}</div>
      ${t.description ? `<div class="task-desc">${escHtml(t.description)}</div>` : ''}
      <div class="task-meta">
        <span class="priority-badge priority-${t.priority}">${t.priority}</span>
        ${dueStr ? `<span class="due-tag">${dueStr}</span>` : ''}
      </div>
      <div class="task-actions">
        <button class="btn btn-success btn-sm" onclick="updateTaskStatus(${t.id}, '${nextStatus[t.status]}')">${nextLabel[t.status]}</button>
        <button class="btn btn-danger btn-sm" onclick="deleteTask(${t.id})">✕</button>
      </div>
    </div>`;
}

async function createTask() {
  const title    = document.getElementById('task-title').value.trim();
  const desc     = document.getElementById('task-desc').value.trim();
  const priority = document.getElementById('task-priority').value;
  const due_date = document.getElementById('task-due').value;

  if (!title) {
    document.getElementById('task-title').classList.add('error');
    document.getElementById('task-title-err').classList.add('show');
    return;
  }

  const btn = document.getElementById('create-task-btn');
  btn.disabled = true; btn.innerHTML = '<div class="spinner"></div>';

  const data = await api('create_task', 'POST', {
    project_id: State.currentProjectId, title, description: desc, priority, due_date
  });

  btn.disabled = false; btn.textContent = 'Add Task';
  if (data.error) { showAlert('task-modal-alert', data.error); return; }

  closeModal('task');
  toast('Task added!');
  await showTaskPage(State.currentProjectId);
  await loadProjects(); // refresh counts
}

async function updateTaskStatus(id, status) {
  const data = await api('update_task_status', 'POST', { id, status });
  if (data.error) { toast(data.error, 'error'); return; }
  await showTaskPage(State.currentProjectId);
  await loadProjects();
}

async function deleteTask(id) {
  if (!confirm('Delete this task?')) return;
  const data = await api('delete_task', 'POST', { id });
  if (data.error) { toast(data.error, 'error'); return; }
  toast('Task deleted');
  await showTaskPage(State.currentProjectId);
  await loadProjects();
}

// ============================================================
// DASHBOARD
// ============================================================
async function loadDashboard() {
  const data = await api('get_stats');
  if (!data.stats) return;
  const { stats } = data;

  document.getElementById('stat-projects').textContent = stats.total_projects;
  document.getElementById('stat-done').textContent     = stats.tasks.done;
  document.getElementById('stat-inprog').textContent   = stats.tasks.in_progress;
  document.getElementById('stat-todo').textContent     = stats.tasks.todo;

  // Progress bars
  const total = stats.tasks.todo + stats.tasks.in_progress + stats.tasks.done;
  document.getElementById('task-progress-wrap').innerHTML = total > 0 ? `
    <div class="progress-wrap">
      <div class="progress-label"><span>To Do</span><span>${stats.tasks.todo}</span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:${(stats.tasks.todo/total*100).toFixed(1)}%; background:var(--muted);"></div></div>
    </div>
    <div class="progress-wrap">
      <div class="progress-label"><span>In Progress</span><span>${stats.tasks.in_progress}</span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:${(stats.tasks.in_progress/total*100).toFixed(1)}%; background:var(--warn);"></div></div>
    </div>
    <div class="progress-wrap">
      <div class="progress-label"><span>Done</span><span>${stats.tasks.done}</span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:${(stats.tasks.done/total*100).toFixed(1)}%; background:var(--success);"></div></div>
    </div>
  ` : '<div class="empty-state"><div class="empty-icon">📋</div><h3>No tasks yet</h3><p>Create a project and start adding tasks.</p></div>';

  // Upcoming tasks
  const upEl = document.getElementById('upcoming-tasks');
  if (!stats.upcoming.length) {
    upEl.innerHTML = '<div class="empty-state"><div class="empty-icon">🎉</div><h3>All clear!</h3><p>No upcoming deadlines.</p></div>';
  } else {
    const today = new Date().toISOString().split('T')[0];
    upEl.innerHTML = stats.upcoming.map(t => {
      const isToday = t.due_date === today;
      return `
        <div class="upcoming-item">
          <span class="upcoming-dot" style="background:${t.color || '#6366f1'}"></span>
          <div class="upcoming-info">
            <div class="upcoming-title">${escHtml(t.title)}</div>
            <div class="upcoming-meta">${escHtml(t.project_title)}</div>
          </div>
          <span class="due-badge ${isToday ? 'due-today' : 'due-soon'}">${isToday ? 'Today' : t.due_date}</span>
        </div>`;
    }).join('');
  }
}

// ============================================================
// UTILS
// ============================================================
function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Enter key support for auth forms
document.getElementById('login-pass').addEventListener('keydown', e => { if (e.key === 'Enter') handleLogin(); });
document.getElementById('reg-pass').addEventListener('keydown',  e => { if (e.key === 'Enter') handleRegister(); });
document.getElementById('task-title').addEventListener('keydown', e => { if (e.key === 'Enter') createTask(); });

// ============================================================
// INIT — check session on load
// ============================================================
(async () => {
  const data = await api('me');
  if (data.user) {
    State.user = data.user;
    document.getElementById('auth-screen').style.display = 'none';
    document.getElementById('app').style.display = 'flex';
    document.getElementById('app').classList.add('visible');
    const av = document.getElementById('sidebar-avatar');
    av.textContent = State.user.name.charAt(0).toUpperCase();
    av.style.background = State.user.avatar_color || '#6366f1';
    document.getElementById('sidebar-name').textContent  = State.user.name;
    document.getElementById('sidebar-email').textContent = State.user.email;
    showPage('dashboard');
    loadProjects();
  }
})();
</script>
</body>
</html>
