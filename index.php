<?php
/*
Single-file Questionnaire POC (index.php)
- Role-based interaction (admin/user)
- Uses 5-point Likert scale (1..5)
- Admin/User roles. Admin can see all responses. User sees only their patients.

SQL Setup (Run once):

CREATE DATABASE IF NOT EXISTS questionnaire_poc;
USE questionnaire_poc;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL, -- bcrypt hash
    role ENUM('admin', 'user') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default users (passwords: admin123, user123)
INSERT INTO users (username, password, role) VALUES
('admin', '$2y$10$jp9QU3CWvviNgP5mxpquwuSrzLzhdoOmEalSe/UHqgkTi5sOwqLtu', 'admin'),
('user', '$2y$10$zoFgHIDNOXdz5noj/.jsnuAI3u4HoamaazWZget0HEK1Vpfqk53gS', 'user')
ON DUPLICATE KEY UPDATE id=id;

CREATE TABLE IF NOT EXISTS responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  patient_ref_id VARCHAR(50) DEFAULT NULL,
  respondent_name VARCHAR(255) DEFAULT NULL,
  respondent_email VARCHAR(255) DEFAULT NULL,
  respondent_age INT DEFAULT NULL,
  respondent_gender VARCHAR(20) DEFAULT NULL,
  answers JSON NOT NULL,
  cat1_raw INT NOT NULL,
  cat2_raw INT NOT NULL,
  cat3_raw INT NOT NULL,
  cat4_raw INT NOT NULL,
  cat1_pct DECIMAL(6,2) NOT NULL,
  cat2_pct DECIMAL(6,2) NOT NULL,
  cat3_pct DECIMAL(6,2) NOT NULL,
  cat4_pct DECIMAL(6,2) NOT NULL,
  total_raw INT NOT NULL,
  total_pct DECIMAL(6,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

*/

session_start();

// ---------------- CONFIG ----------------
$dbHost = '127.0.0.1';
$dbName = 'questionnaire_poc';
$dbUser = 'root';
$dbPass = '';

// Logo path
$logoPath = '/qpoc/logo/newlogoccras_questionnaire.jpeg';

function connect_pdo($dbHost,$dbName,$dbUser,$dbPass){
    return new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

// ---------------- AUTHENTICATION ----------------
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $pdo = connect_pdo($dbHost, $dbName, $dbUser, $dbPass);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
            exit;
        } else {
            $loginError = 'Invalid credentials.';
        }
    } catch (PDOException $e) {
        $loginError = 'DB Error: ' . $e->getMessage();
    }
}

if (!isset($_SESSION['user_id'])) {
    // Show Login Page
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Login - Questionnaire POC</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            body{font-family:Arial,Helvetica,sans-serif;background:#f6f9fb;padding:18px;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
            .card{background:white;padding:30px;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,0.06);width:100%;max-width:400px}
            input{width:100%;padding:10px;margin:10px 0;border:1px solid #ddd;border-radius:5px;box-sizing:border-box}
            button{width:100%;padding:10px;background:#0ea5a4;color:white;border:none;border-radius:5px;cursor:pointer;font-size:16px}
            .error{color:red;margin-bottom:10px;font-size:14px}
            .logo{height:56px;display:block;margin:0 auto 20px}
        </style>
    </head>
    <body>
        <div class="card">
            <img src="<?php echo htmlspecialchars($logoPath); ?>" class="logo" alt="logo">
            <h2 style="text-align:center;margin-top:0">Login</h2>
            <?php if($loginError): ?><div class="error"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label>Username</label>
                <input type="text" name="username" required>
                <label>Password</label>
                <input type="password" name="password" required>
                <button type="submit">Sign In</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------------- APP CONTEXT ----------------
$currentUserRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';

// ---------------- DEFINITIONS ----------------
$categories = [
    'cat1' => ['label' => 'Category 1', 'count' => 7, 'max' => 35],
    'cat2' => ['label' => 'Category 2', 'count' => 5, 'max' => 25],
    'cat3' => ['label' => 'Category 3', 'count' => 10, 'max' => 50],
    'cat4' => ['label' => 'Category 4', 'count' => 4, 'max' => 20],
];

$cat_map = [];
foreach ([1,4,5,6,9,11,18] as $n) { $cat_map[$n] = 'cat1'; }
foreach ([2,3,7,8,12] as $n) { $cat_map[$n] = 'cat2'; }
foreach ([13,14,15,16,17,19,20,21,22,23] as $n) { $cat_map[$n] = 'cat3'; }
foreach ([10,24,25,26] as $n) { $cat_map[$n] = 'cat4'; }

$question_texts = [
  1  => "I feel energetic and able to complete daily tasks without excessive fatigue.",
  2  => "I am satisfied with the quality of my sleep during the past month.",
  3  => "I can concentrate on tasks without being easily distracted.",
  4  => "I find it easy to maintain a balanced diet most days.",
  5  => "I feel comfortable with my current level of physical activity.",
  6  => "I rarely experience sudden or unexplained mood swings.",
  7  => "I can manage stress effectively in most situations.",
  8  => "I have a reliable support network when I need help.",
  9  => "I am generally satisfied with my weight and body composition.",
 10 => "I can complete physical tasks that require strength or endurance.",
 11 => "I rarely experience digestive discomfort such as bloating or pain.",
 12 => "I drink enough water and stay properly hydrated during the day.",
 13 => "I follow routines that help me stay organized and productive.",
 14 => "I can control impulses that may negatively affect my health.",
 15 => "I feel emotionally balanced and resilient to setbacks.",
 16 => "I regularly engage in activities that promote mental well-being.",
 17 => "I find it easy to fall asleep and wake up feeling refreshed.",
 18 => "I maintain good posture and ergonomics during my typical day.",
 19 => "I limit my intake of highly processed or fast foods.",
 20 => "I feel confident in my ability to maintain personal hygiene routines.",
 21 => "I avoid using substances in ways that may harm my health.",
 22 => "I follow medical advice and attend recommended health checkups.",
 23 => "I feel that my breathing and respiratory health are good.",
 24 => "I am able to talk clearly and express myself when needed.",
 25 => "I find it easy to adapt my routine when circumstances change.",
 26 => "Overall, I consider my current health to be satisfactory."
];

$likert_labels = [
    1 => '1 — Strongly disagree',
    2 => '2 — Disagree',
    3 => '3 — Neutral',
    4 => '4 — Agree',
    5 => '5 — Strongly agree'
];

function render_likert($name, $labels) {
    $html = '';
    foreach ($labels as $val => $lab) {
        $html .= "<label style='margin-right:1.1rem; display:inline-block; margin-bottom:5px;'><input type='radio' name='{$name}' value='{$val}' required> {$lab}</label>";
    }
    return $html;
}

// ---------------- ROUTER / CONTROLLER ----------------

// 1. Analytics (Admin Only)
if ($action === 'analytics' && $currentUserRole === 'admin') {
    $pdo = connect_pdo($dbHost, $dbName, $dbUser, $dbPass);

    // Calculations
    $totalP = $pdo->query("SELECT COUNT(*) FROM responses")->fetchColumn();
    $avgTotal = $pdo->query("SELECT AVG(total_pct) FROM responses")->fetchColumn();
    $avgC1 = $pdo->query("SELECT AVG(cat1_pct) FROM responses")->fetchColumn();
    $avgC2 = $pdo->query("SELECT AVG(cat2_pct) FROM responses")->fetchColumn();
    $avgC3 = $pdo->query("SELECT AVG(cat3_pct) FROM responses")->fetchColumn();
    $avgC4 = $pdo->query("SELECT AVG(cat4_pct) FROM responses")->fetchColumn();

    // Gender distribution
    $genders = $pdo->query("SELECT respondent_gender, COUNT(*) as c FROM responses GROUP BY respondent_gender")->fetchAll(PDO::FETCH_KEY_PAIR);

    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Analytics</title>
    <style>body{font-family:Arial,sans-serif;background:#f6f9fb;padding:20px}.card{background:white;padding:20px;border-radius:8px;box-shadow:0 5px 15px rgba(0,0,0,0.05)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-top:20px}.stat-box{background:#fafbfc;padding:20px;border:1px solid #eee;border-radius:8px;text-align:center}.stat-val{font-size:2rem;font-weight:bold;color:#0ea5a4}.stat-label{color:#666}</style>
    </head><body>
    <div class="card" style="max-width:1000px;margin:0 auto">
        <h2>Analytics Dashboard</h2>
        <p><a href="?">Back to Dashboard</a></p>

        <div class="grid">
            <div class="stat-box"><div class="stat-val"><?php echo $totalP; ?></div><div class="stat-label">Total Patients</div></div>
            <div class="stat-box"><div class="stat-val"><?php echo number_format($avgTotal,1); ?>%</div><div class="stat-label">Avg Total Score</div></div>
        </div>

        <h3>Category Averages</h3>
        <div class="grid">
            <div class="stat-box"><div class="stat-val"><?php echo number_format($avgC1,1); ?>%</div><div class="stat-label">Cat 1</div></div>
            <div class="stat-box"><div class="stat-val"><?php echo number_format($avgC2,1); ?>%</div><div class="stat-label">Cat 2</div></div>
            <div class="stat-box"><div class="stat-val"><?php echo number_format($avgC3,1); ?>%</div><div class="stat-label">Cat 3</div></div>
            <div class="stat-box"><div class="stat-val"><?php echo number_format($avgC4,1); ?>%</div><div class="stat-label">Cat 4</div></div>
        </div>

        <h3>Gender Distribution</h3>
        <div class="grid">
            <?php foreach($genders as $g => $c): ?>
            <div class="stat-box"><div class="stat-val"><?php echo $c; ?></div><div class="stat-label"><?php echo htmlspecialchars($g ?: 'Not specified'); ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>
    </body></html>
    <?php
    exit;
}

// 2. Manage Users (Admin Only)
if ($action === 'manage_users' && $currentUserRole === 'admin') {
    $pdo = connect_pdo($dbHost, $dbName, $dbUser, $dbPass);
    $msg = '';

    // Add User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subaction']) && $_POST['subaction'] === 'add_user') {
        $new_u = trim($_POST['new_username']);
        $new_p = $_POST['new_password'];
        $new_r = $_POST['new_role'];
        if ($new_u && $new_p) {
            $hash = password_hash($new_p, PASSWORD_BCRYPT);
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (:u, :p, :r)");
                $stmt->execute([':u'=>$new_u, ':p'=>$hash, ':r'=>$new_r]);
                $msg = "User added successfully.";
            } catch (PDOException $e) {
                $msg = "Error adding user: " . $e->getMessage();
            }
        }
    }
    // Delete User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subaction']) && $_POST['subaction'] === 'delete_user') {
        $duid = (int)$_POST['user_id'];
        if ($duid != $currentUserId) { // Prevent self-delete
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$duid]);
            $msg = "User deleted.";
        } else {
            $msg = "Cannot delete yourself.";
        }
    }

    // List Users
    $users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Manage Users</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f9fb;padding:20px}.card{background:white;padding:20px;border-radius:8px;max-width:800px;margin:0 auto;box-shadow:0 5px 15px rgba(0,0,0,0.05)}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border-bottom:1px solid #eee;padding:10px;text-align:left}input,select{padding:8px;border:1px solid #ddd;border-radius:4px}</style>
    </head><body>
    <div class="card">
        <h2>Manage Users</h2>
        <p><a href="?">Back to Dashboard</a></p>
        <?php if($msg) echo "<p style='color:green'>$msg</p>"; ?>

        <h3>Add New User</h3>
        <form method="post">
            <input type="hidden" name="action" value="manage_users">
            <input type="hidden" name="subaction" value="add_user">
            <input type="text" name="new_username" placeholder="Username" required>
            <input type="password" name="new_password" placeholder="Password" required>
            <select name="new_role"><option value="user">User</option><option value="admin">Admin</option></select>
            <button type="submit" style="padding:8px 16px;background:#0ea5a4;color:white;border:none;border-radius:4px;cursor:pointer">Add</button>
        </form>

        <h3>Existing Users</h3>
        <table>
            <tr><th>ID</th><th>Username</th><th>Role</th><th>Created</th><th>Action</th></tr>
            <?php foreach($users as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['role']); ?></td>
                <td><?php echo $u['created_at']; ?></td>
                <td>
                    <?php if($u['id'] != $currentUserId): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete user?');">
                            <input type="hidden" name="action" value="manage_users">
                            <input type="hidden" name="subaction" value="delete_user">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" style="background:none;border:none;color:red;cursor:pointer;padding:0;font-size:1rem">Delete</button>
                        </form>
                    <?php else: ?>
                        (You)
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div></body></html>
    <?php
    exit;
}

// 2. New Patient / Start Questionnaire
// If "new patient" requested via GET, show form
if ($action === 'new_patient') {
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>New Patient</title><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f9fb;padding:18px}.card{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,0.06)}input,select{width:100%;padding:10px;margin:5px 0 15px;border:1px solid #ddd;border-radius:5px;box-sizing:border-box}.btn{padding:10px 20px;background:#0ea5a4;color:white;border:none;border-radius:5px;cursor:pointer}</style>
    </head><body><div class="card"><h2>New Patient Entry</h2>
    <form method="post"><input type="hidden" name="action" value="start_questionnaire">
    <label>Full Name</label><input type="text" name="name" required>
    <label>Email (Optional)</label><input type="email" name="email">
    <label>Age</label><input type="number" name="age" required>
    <label>Gender</label><select name="gender" required><option value="">Select...</option><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option></select>
    <button type="submit" class="btn">Start Questionnaire</button></form><p><a href="?">Cancel</a></p></div></body></html>
    <?php
    exit;
}

// 3. Handle Questionnaire Submission & Rendering form
if ($action === 'start_questionnaire') {
    // This block handles both displaying the empty questionnaire (if from New Patient)
    // AND processing the submission if it was POSTed with answers?
    // Actually, the previous code separated them.
    // Let's stick to: if POST has 'start_questionnaire' -> Render Q form.
    // If POST has answers -> Process.

    // Display Questionnaire Form
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $age = $_POST['age'];
        $gender = $_POST['gender'];
        $patient_ref_id = 'PID-' . date('Ymd') . '-' . mt_rand(1000,9999);
        ?>
        <!doctype html><html><head><meta charset="utf-8"><title>Questionnaire</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,Helvetica,sans-serif;background:#eef6f6;margin:0;padding:0}header{background:linear-gradient(135deg,#0ea5a4,#4dd0c8);padding:18px;color:white;display:flex;align-items:center;justify-content:space-between}.logo{height:56px}.container{max-width:980px;margin:22px auto;padding:18px}.card{background:white;padding:18px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.06)}.qblock{margin-bottom:12px;padding:12px;border-radius:6px;background:#fbfbfd}label.q{display:block;font-weight:600;margin-bottom:8px}.submit{background:#0ea5a4;color:white;border:none;padding:10px 16px;border-radius:6px;font-size:1rem;cursor:pointer}</style></head><body>
        <header><div style="display:flex;align-items:center;gap:12px"><img src="<?php echo htmlspecialchars($logoPath); ?>" alt="logo" class="logo"><div><div style="font-weight:700">Patient Assessment</div><div style="font-size:0.9rem">Ref: <?php echo $patient_ref_id; ?></div></div></div></header>
        <div class="container"><div class="card"><form method="post">
        <input type="hidden" name="action" value="submit_answers">
        <input type="hidden" name="name" value="<?php echo htmlspecialchars($name); ?>">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
        <input type="hidden" name="age" value="<?php echo htmlspecialchars($age); ?>">
        <input type="hidden" name="gender" value="<?php echo htmlspecialchars($gender); ?>">
        <input type="hidden" name="patient_ref_id" value="<?php echo htmlspecialchars($patient_ref_id); ?>">
        <?php for ($i=1;$i<=26;$i++): $qid = "q{$i}"; ?><div class="qblock"><label class="q">Q: <?php echo htmlspecialchars($question_texts[$i]); ?></label><div>A: <?php echo render_likert($qid, $likert_labels); ?></div></div><?php endfor; ?>
        <div style="text-align:right;margin-top:6px"><button type="submit" class="submit">Submit Assessment</button></div></form></div></div></body></html>
        <?php
        exit;
    }
}

// 4. Process Answers
if ($action === 'submit_answers' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $respondent_name = trim($_POST['name'] ?? '');
    $respondent_email = trim($_POST['email'] ?? '');
    $respondent_age = isset($_POST['age']) ? (int)$_POST['age'] : null;
    $respondent_gender = trim($_POST['gender'] ?? '');
    $patient_ref_id = trim($_POST['patient_ref_id'] ?? '');

    $answers = [];
    for ($i=1;$i<=26;$i++){
        $k = "q{$i}";
        if (!isset($_POST[$k])) die("Missing answer for {$k}.");
        $v = (int)$_POST[$k];
        $answers[$k] = $v;
    }

    // Calculations
    $cat_raw = ['cat1'=>0,'cat2'=>0,'cat3'=>0,'cat4'=>0];
    foreach ($answers as $qk => $val){
        $num = (int)substr($qk,1);
        $cat = $cat_map[$num];
        $cat_raw[$cat] += $val;
    }
    $cat_pct = [];
    $total_raw = 0; $total_max = 0;
    foreach ($categories as $catKey => $ci) {
        $raw = $cat_raw[$catKey];
        $max = $ci['max'];
        $pct = $max>0 ? round(($raw / $max) * 100, 2) : 0.00;
        $cat_pct[$catKey] = $pct;
        $total_raw += $raw;
        $total_max += $max;
    }
    $total_pct = $total_max>0 ? round(($total_raw / $total_max) * 100, 2) : 0.00;

    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $stmt = $pdo->prepare("INSERT INTO responses
            (respondent_name, respondent_email, respondent_age, respondent_gender, patient_ref_id, user_id, answers, cat1_raw, cat2_raw, cat3_raw, cat4_raw, cat1_pct, cat2_pct, cat3_pct, cat4_pct, total_raw, total_pct)
            VALUES (:name, :email, :age, :gender, :ref, :uid, :answers, :c1r, :c2r, :c3r, :c4r, :c1p, :c2p, :c3p, :c4p, :tr, :tp)");
        $stmt->execute([
            ':name'=>$respondent_name, ':email'=>$respondent_email, ':age'=>$respondent_age, ':gender'=>$respondent_gender, ':ref'=>$patient_ref_id, ':uid'=>$currentUserId,
            ':answers'=>json_encode($answers), ':c1r'=>$cat_raw['cat1'], ':c2r'=>$cat_raw['cat2'], ':c3r'=>$cat_raw['cat3'], ':c4r'=>$cat_raw['cat4'],
            ':c1p'=>$cat_pct['cat1'], ':c2p'=>$cat_pct['cat2'], ':c3p'=>$cat_pct['cat3'], ':c4p'=>$cat_pct['cat4'], ':tr'=>$total_raw, ':tp'=>$total_pct
        ]);
        $savedId = $pdo->lastInsertId();
        header("Location: ?action=view_report&id=$savedId");
        exit;
    } catch (PDOException $e){
        die("DB Error: ".$e->getMessage());
    }
}

// 5. Delete Response
if ($action === 'delete_response' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    try {
        $pdo = connect_pdo($dbHost, $dbName, $dbUser, $dbPass);
        if ($currentUserRole === 'admin') {
            $stmt = $pdo->prepare("DELETE FROM responses WHERE id = :id");
            $stmt->execute([':id' => (int)$_POST['id']]);
        } else {
            // User can only delete own
            $stmt = $pdo->prepare("DELETE FROM responses WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => (int)$_POST['id'], ':uid' => $currentUserId]);
        }
        header("Location: ?");
        exit;
    } catch (PDOException $e) {
        die("DB Error: " . $e->getMessage());
    }
}

// 6. Edit Response (PI Only for now, as answers are complex to re-populate without huge code)
if ($action === 'edit_response' && isset($_GET['id'])) {
    try {
        $pdo = connect_pdo($dbHost, $dbName, $dbUser, $dbPass);
        $stmt = $pdo->prepare("SELECT * FROM responses WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($currentUserRole === 'user' && $row['user_id'] != $currentUserId)) {
            die("Access Denied or Not Found");
        }

        // Handle Update
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = $_POST['name'];
            $email = $_POST['email'];
            $age = $_POST['age'];
            $gender = $_POST['gender'];

            $upd = $pdo->prepare("UPDATE responses SET respondent_name=:n, respondent_email=:e, respondent_age=:a, respondent_gender=:g WHERE id=:id");
            $upd->execute([':n'=>$name, ':e'=>$email, ':a'=>$age, ':g'=>$gender, ':id'=>$row['id']]);
            header("Location: ?");
            exit;
        }

    } catch (PDOException $e) { die("DB Error"); }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Edit Patient</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,sans-serif;background:#f6f9fb;padding:20px}.card{max-width:600px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 5px 15px rgba(0,0,0,0.05)}input,select{width:100%;padding:10px;margin:5px 0 15px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box}.btn{padding:10px 20px;background:#0ea5a4;color:white;border:none;border-radius:4px;cursor:pointer}</style></head><body>
    <div class="card"><h2>Edit Patient Info</h2>
    <form method="post">
        <label>Name</label><input type="text" name="name" value="<?php echo htmlspecialchars($row['respondent_name']); ?>" required>
        <label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($row['respondent_email']); ?>">
        <label>Age</label><input type="number" name="age" value="<?php echo htmlspecialchars($row['respondent_age']); ?>" required>
        <label>Gender</label><select name="gender">
            <option value="Male" <?php if($row['respondent_gender']=='Male') echo 'selected'; ?>>Male</option>
            <option value="Female" <?php if($row['respondent_gender']=='Female') echo 'selected'; ?>>Female</option>
            <option value="Other" <?php if($row['respondent_gender']=='Other') echo 'selected'; ?>>Other</option>
        </select>
        <button type="submit" class="btn">Update</button>
    </form><p><a href="?">Cancel</a></p></div></body></html>
    <?php
    exit;
}

// 7. Export CSV
if ($action === 'export_csv') {
    $pdo = connect_pdo($dbHost, $dbName, $dbUser, $dbPass);

    // Re-use filter logic
    $search = trim($_GET['search'] ?? '');
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    $query = "SELECT * FROM responses";
    $whereClauses = [];
    $params = [];

    if ($currentUserRole === 'user') {
        $whereClauses[] = "user_id = :uid";
        $params[':uid'] = $currentUserId;
    }

    if ($search) {
        $whereClauses[] = "(respondent_name LIKE :s OR patient_ref_id LIKE :s)";
        $params[':s'] = "%$search%";
    }
    if ($start_date) {
        $whereClauses[] = "created_at >= :sd";
        $params[':sd'] = "$start_date 00:00:00";
    }
    if ($end_date) {
        $whereClauses[] = "created_at <= :ed";
        $params[':ed'] = "$end_date 23:59:59";
    }

    if (!empty($whereClauses)) {
        $query .= " WHERE " . implode(' AND ', $whereClauses);
    }
    $query .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=export_'.date('YmdHis').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','Ref ID','Name','Email','Age','Gender','Cat1','Cat2','Cat3','Cat4','Total %','Date']);
    foreach($rows as $r){
        fputcsv($out, [
            $r['id'], $r['user_id'], $r['patient_ref_id'], $r['respondent_name'], $r['respondent_email'], $r['respondent_age'], $r['respondent_gender'],
            $r['cat1_pct'], $r['cat2_pct'], $r['cat3_pct'], $r['cat4_pct'], $r['total_pct'], $r['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// 8. View Report
if ($action === 'view_report' && isset($_GET['id'])) {
    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $stmt = $pdo->prepare("SELECT * FROM responses WHERE id = :id");
        $stmt->execute([':id'=> (int)$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Access Check: Admin can see all, User can see own
            if ($currentUserRole === 'user' && $row['user_id'] != $currentUserId) {
                die("Access Denied");
            }
        } else {
            die("Not found.");
        }
    } catch (PDOException $e) { die("DB Error"); }

    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Report - <?php echo htmlspecialchars($row['patient_ref_id']); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"><style>body{font-family:'Inter',sans-serif;background:#f4f7f6;margin:0;padding:20px;color:#333}.report-card{max-width:800px;margin:0 auto;background:white;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.05);overflow:hidden}.report-header{background:linear-gradient(135deg,#0ea5a4,#20c997);color:white;padding:30px;text-align:center}.report-body{padding:30px}.score-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px}.score-box{padding:15px;border:1px solid #eee;border-radius:12px;background:#fafbfc}.score-label{display:flex;justify-content:space-between;font-weight:600}.progress-bg{background:#e9ecef;height:8px;border-radius:4px;overflow:hidden;margin-top:8px}.progress-bar{height:100%;background:#0ea5a4;border-radius:4px}.actions{margin-top:30px;text-align:center;display:flex;gap:10px;justify-content:center}.btn{padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block}.btn-primary{background:#0ea5a4;color:white}.btn-secondary{background:#e9ecef;color:#495057}@media print{.report-header{-webkit-print-color-adjust:exact;print-color-adjust:exact}.actions{display:none}}</style>
    </head><body><div class="report-card">
        <div class="report-header"><h1>Health Report</h1><div style="opacity:0.9">ID: <?php echo htmlspecialchars($row['patient_ref_id']); ?></div></div>
        <div class="report-body">
            <div style="display:flex;justify-content:space-between;margin-bottom:30px;border-bottom:1px solid #eee;padding-bottom:15px;flex-wrap:wrap;gap:15px">
                <div>Name: <strong><?php echo htmlspecialchars($row['respondent_name']); ?></strong></div>
                <div>Age: <strong><?php echo htmlspecialchars($row['respondent_age']); ?></strong></div>
                <div>Gender: <strong><?php echo htmlspecialchars($row['respondent_gender']); ?></strong></div>
                <div>Date: <strong><?php echo date('M d, Y', strtotime($row['created_at'])); ?></strong></div>
            </div>
            <div class="score-grid">
                <?php foreach ($categories as $ck=>$ci): $pct = $row[$ck . '_pct']; ?>
                <div class="score-box"><div class="score-label"><span><?php echo $ci['label']; ?></span><span style="color:#0ea5a4"><?php echo number_format($pct,1); ?>%</span></div>
                <div class="progress-bg"><div class="progress-bar" style="width:<?php echo $pct; ?>%"></div></div></div>
                <?php endforeach; ?>
            </div>
            <div style="text-align:center;margin-top:30px;padding:20px;background:#f0fdfa;border-radius:12px">
                <div style="color:#0f766e;font-weight:600">Overall Score</div><div style="font-size:2.5rem;font-weight:800;color:#0ea5a4"><?php echo number_format($row['total_pct'],1); ?>%</div>
            </div>
            <div class="actions">
                <a href="javascript:window.print()" class="btn btn-secondary">Print</a>
                <a href="?" class="btn btn-primary">Dashboard</a>
            </div>
        </div>
    </div></body></html>
    <?php
    exit;
}

// ---------------- DEFAULT: DASHBOARD ----------------
// Default action is dashboard
$pdo = connect_pdo($dbHost, $dbName, $dbUser, $dbPass);

// Prepare Dashboard Data (for Admin or User)
$search = trim($_GET['search'] ?? '');
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$query = "SELECT * FROM responses";
$whereClauses = [];
$params = [];

if ($currentUserRole === 'user') {
    $whereClauses[] = "user_id = :uid";
    $params[':uid'] = $currentUserId;
}

if ($search) {
    $whereClauses[] = "(respondent_name LIKE :s OR patient_ref_id LIKE :s)";
    $params[':s'] = "%$search%";
}

if ($start_date) {
    $whereClauses[] = "created_at >= :sd";
    $params[':sd'] = "$start_date 00:00:00";
}

if ($end_date) {
    $whereClauses[] = "created_at <= :ed";
    $params[':ed'] = "$end_date 23:59:59";
}

if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(' AND ', $whereClauses);
}

$query .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body{font-family:Arial,Helvetica,sans-serif;background:#eef4f6;margin:0;padding:0}
        header{background:linear-gradient(135deg,#0ea5a4,#4dd0c8);padding:15px;color:white;display:flex;align-items:center;justify-content:space-between}
        .logo{height:40px}
        .container{max-width:1200px;margin:20px auto;padding:15px}
        .card{background:white;padding:20px;border-radius:8px;box-shadow:0 5px 15px rgba(0,0,0,0.05);overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:600px}
        th,td{padding:12px;border-bottom:1px solid #eee;text-align:left}
        .btn{display:inline-block;padding:8px 12px;background:#0ea5a4;border-radius:4px;text-decoration:none;color:white;font-size:0.9rem}
        .btn-sm{padding:4px 8px;font-size:0.8rem}
        .nav-links a{color:white;margin-left:15px;text-decoration:none;font-size:0.9rem}
    </style>
</head>
<body>
<header>
    <div style="display:flex;align-items:center;gap:10px">
        <img src="<?php echo htmlspecialchars($logoPath); ?>" class="logo" alt="logo">
        <div><strong>Dashboard</strong></div>
    </div>
    <div class="nav-links">
        <?php if ($currentUserRole === 'admin'): ?>
            <a href="?action=manage_users">Manage Users</a>
            <a href="?action=analytics">Analytics</a>
        <?php endif; ?>
        <a href="?logout=1">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a>
    </div>
</header>

<div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px">
        <h2>Patients</h2>
        <div>
            <a href="?action=export_csv&search=<?php echo urlencode($search); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn" style="background:#556bff">Export CSV</a>
            <a href="?action=new_patient" class="btn">+ New Patient</a>
        </div>
    </div>

    <div class="card" style="margin-bottom:20px">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <input type="text" name="search" placeholder="Name or Ref ID" value="<?php echo htmlspecialchars($search); ?>" style="padding:8px;border:1px solid #ddd;border-radius:4px;width:auto;flex:1">
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" style="padding:8px;border:1px solid #ddd;border-radius:4px;width:auto">
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" style="padding:8px;border:1px solid #ddd;border-radius:4px;width:auto">
            <button type="submit" class="btn btn-sm">Filter</button>
            <?php if($search || $start_date || $end_date): ?>
                <a href="?" style="color:#666;text-decoration:none;font-size:0.9rem">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Ref ID</th>
                    <th>Name</th>
                    <th>Date</th>
                    <th>Cat 1</th>
                    <th>Cat 2</th>
                    <th>Cat 3</th>
                    <th>Cat 4</th>
                    <th>Total %</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['patient_ref_id']); ?></td>
                    <td><?php echo htmlspecialchars($r['respondent_name']); ?></td>
                    <td><?php echo date('M d', strtotime($r['created_at'])); ?></td>
                    <td><?php echo number_format($r['cat1_pct'],0); ?>%</td>
                    <td><?php echo number_format($r['cat2_pct'],0); ?>%</td>
                    <td><?php echo number_format($r['cat3_pct'],0); ?>%</td>
                    <td><?php echo number_format($r['cat4_pct'],0); ?>%</td>
                    <td><strong><?php echo number_format($r['total_pct'],1); ?>%</strong></td>
                    <td style="white-space:nowrap">
                        <a href="?action=view_report&id=<?php echo $r['id']; ?>" class="btn btn-sm">View</a>
                        <a href="?action=edit_response&id=<?php echo $r['id']; ?>" class="btn btn-sm" style="background:#ffc107;color:black">Edit</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this record?');">
                            <input type="hidden" name="action" value="delete_response">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <button type="submit" class="btn btn-sm" style="background:#dc3545;border:none;cursor:pointer">Del</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($rows) === 0) echo "<p style='padding:20px;text-align:center;color:#666'>No records found.</p>"; ?>
    </div>
</div>
</body>
</html>
