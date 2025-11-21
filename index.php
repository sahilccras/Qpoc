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
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('user', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user')
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

// ---------------- APP LOGIC ----------------
$currentUserRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

// ---------------- Definitions ----------------
$categories = [
    'cat1' => ['label' => 'Category 1', 'count' => 7, 'max' => 35],
    'cat2' => ['label' => 'Category 2', 'count' => 5, 'max' => 25],
    'cat3' => ['label' => 'Category 3', 'count' => 10, 'max' => 50],
    'cat4' => ['label' => 'Category 4', 'count' => 4, 'max' => 20],
];

// Mapping from user: Q numbers => category
$cat_map = [];
foreach ([1,4,5,6,9,11,18] as $n) { $cat_map[$n] = 'cat1'; }
foreach ([2,3,7,8,12] as $n) { $cat_map[$n] = 'cat2'; }
foreach ([13,14,15,16,17,19,20,21,22,23] as $n) { $cat_map[$n] = 'cat3'; }
foreach ([10,24,25,26] as $n) { $cat_map[$n] = 'cat4'; }
for ($i=1;$i<=26;$i++){ if(!isset($cat_map[$i])) die("Missing mapping for question {$i}"); }

// ---------------- 26 SAMPLE QUESTIONS ----------------
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

// Likert labels (kept per your earlier mapping)
$likert_labels = [
    1 => '1 — Strongly disagree',
    2 => '2 — Disagree',
    3 => '3 — Neutral',
    4 => '4 — Agree',
    5 => '5 — Strongly agree'
];

// ---------------- Helpers ----------------
function render_likert($name, $labels) {
    $html = '';
    foreach ($labels as $val => $lab) {
        $html .= "<label style='margin-right:1.1rem;'><input type='radio' name='{$name}' value='{$val}' required> {$lab}</label>";
    }
    return $html;
}

// ---------------- Handle Submit (questionnaire) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
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
        if ($v < 1 || $v > 5) die("Invalid value for {$k}.");
        $answers[$k] = $v;
    }

    // compute per-category raw totals
    $cat_raw = ['cat1'=>0,'cat2'=>0,'cat3'=>0,'cat4'=>0];
    foreach ($answers as $qk => $val){
        $num = (int)substr($qk,1);
        $cat = $cat_map[$num];
        $cat_raw[$cat] += $val;
    }

    // compute percents and totals
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

    // save to DB
    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $stmt = $pdo->prepare("INSERT INTO responses
            (respondent_name, respondent_email, respondent_age, respondent_gender, patient_ref_id, user_id, answers, cat1_raw, cat2_raw, cat3_raw, cat4_raw,
             cat1_pct, cat2_pct, cat3_pct, cat4_pct, total_raw, total_pct)
            VALUES
            (:name, :email, :age, :gender, :ref, :uid, :answers, :cat1_raw, :cat2_raw, :cat3_raw, :cat4_raw,
             :cat1_pct, :cat2_pct, :cat3_pct, :cat4_pct, :total_raw, :total_pct)");
        $stmt->execute([
            ':name'=>$respondent_name?:null,
            ':email'=>$respondent_email?:null,
            ':age'=>$respondent_age,
            ':gender'=>$respondent_gender,
            ':ref'=>$patient_ref_id?:null,
            ':uid'=>$currentUserId,
            ':answers'=>json_encode($answers, JSON_UNESCAPED_UNICODE),
            ':cat1_raw'=>$cat_raw['cat1'],
            ':cat2_raw'=>$cat_raw['cat2'],
            ':cat3_raw'=>$cat_raw['cat3'],
            ':cat4_raw'=>$cat_raw['cat4'],
            ':cat1_pct'=>$cat_pct['cat1'],
            ':cat2_pct'=>$cat_pct['cat2'],
            ':cat3_pct'=>$cat_pct['cat3'],
            ':cat4_pct'=>$cat_pct['cat4'],
            ':total_raw'=>$total_raw,
            ':total_pct'=>$total_pct
        ]);
        $savedId = $pdo->lastInsertId();

        // Redirect to results
        header("Location: " . $_SERVER['PHP_SELF'] . "?thanks=1&id=" . $savedId);
        exit;

    } catch (PDOException $e){
        die("DB error: ".htmlspecialchars($e->getMessage()));
    }
}

// ---------------- Admin Logic ----------------
if ($currentUserRole === 'admin') {
    // CSV export
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        try {
            $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
            $stmt = $pdo->query("SELECT * FROM responses ORDER BY created_at DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=responses_export_'.date('Ymd_His').'.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id','user_id','patient_ref_id','respondent_name','respondent_email','age','gender','cat1_raw','cat2_raw','cat3_raw','cat4_raw','total_pct','created_at']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['id'],$r['user_id'],$r['patient_ref_id'],$r['respondent_name'],$r['respondent_email'],$r['respondent_age'],$r['respondent_gender'],$r['cat1_raw'],$r['cat2_raw'],$r['cat3_raw'],$r['cat4_raw'],$r['total_pct'],$r['created_at']]);
            }
            fclose($out);
            exit;
        } catch (PDOException $e) {
            die("DB error: ".htmlspecialchars($e->getMessage()));
        }
    }

    // View single response
    if (isset($_GET['view']) && is_numeric($_GET['view'])) {
        try {
            $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
            $stmt = $pdo->prepare("SELECT * FROM responses WHERE id = :id");
            $stmt->execute([':id'=> (int)$_GET['view']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) die("Response not found.");
        } catch (PDOException $e) {
            die("DB error: ".htmlspecialchars($e->getMessage()));
        }
        // Render View (Using existing style but wrapped)
        // For now, I'll inline it to keep single file structure as requested.
        ?>
        <!doctype html><html><head><meta charset="utf-8"><title>Response #<?php echo htmlspecialchars($row['id']); ?></title>
        <style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f8fb;padding:18px}.card{max-width:1000px;margin:0 auto;background:white;padding:18px;border-radius:8px;box-shadow:0 10px 26px rgba(0,0,0,0.06)}table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}pre{background:#f7fbff;padding:12px;border-radius:6px}</style>
        </head><body>
          <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div><img src="<?php echo htmlspecialchars($logoPath); ?>" style="height:56px" alt="logo"></div>
              <div style="text-align:right"><h2>Response #<?php echo htmlspecialchars($row['id']); ?></h2>
              <div style="color:#666"><?php echo htmlspecialchars($row['created_at']); ?></div></div>
            </div>
            <h3>Respondent</h3>
            <table>
                <tr><th>Name</th><td><?php echo htmlspecialchars($row['respondent_name']); ?></td></tr>
                <tr><th>Email</th><td><?php echo htmlspecialchars($row['respondent_email']); ?></td></tr>
                <tr><th>Ref ID</th><td><?php echo htmlspecialchars($row['patient_ref_id']); ?></td></tr>
                <tr><th>Age</th><td><?php echo htmlspecialchars($row['respondent_age']); ?></td></tr>
                <tr><th>Gender</th><td><?php echo htmlspecialchars($row['respondent_gender']); ?></td></tr>
            </table>
            <h3 style="margin-top:12px">Category Scores</h3>
            <table>
              <thead><tr><th>Category</th><th>Raw</th><th>Max</th><th>Percent</th></tr></thead>
              <tbody>
                <?php foreach ($categories as $ck=>$ci): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($ci['label']); ?></td>
                    <td><?php echo (int)$row[$ck . '_raw']; ?></td>
                    <td><?php echo (int)$ci['max']; ?></td>
                    <td><?php echo number_format($row[$ck . '_pct'],2); ?>%</td>
                  </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold"><td>Total</td><td><?php echo (int)$row['total_raw']; ?></td><td><?php echo array_sum(array_column($categories,'max')); ?></td><td><?php echo number_format($row['total_pct'],2); ?>%</td></tr>
              </tbody>
            </table>
            <p><a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">← Back to Dashboard</a></p>
          </div>
        </body></html>
        <?php
        exit;
    }

    // Admin Dashboard (List all)
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;
    $offset = ($page-1)*$perPage;
    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM responses");
        $total = (int)$totalStmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, respondent_name, respondent_email, patient_ref_id, total_pct, total_raw, created_at FROM responses ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("DB error: ".htmlspecialchars($e->getMessage()));
    }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Admin — Responses</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;background:#eef4f6;margin:0;padding:0}header{background:linear-gradient(135deg,#0ea5a4,#4dd0c8);padding:14px;color:white;display:flex;align-items:center;justify-content:space-between}.logo{height:48px}.container{max-width:1100px;margin:18px auto;padding:12px}.card{background:white;padding:14px;border-radius:8px;box-shadow:0 10px 26px rgba(0,0,0,0.06)}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}a.button{display:inline-block;padding:8px 10px;background:#ffffff;border:1px solid rgba(0,0,0,0.08);border-radius:6px;text-decoration:none;color:#0a6}.muted{color:#666;font-size:0.95rem}</style>
    </head><body>
      <header>
        <div style="display:flex;align-items:center;gap:12px">
          <img src="<?php echo htmlspecialchars($logoPath); ?>" class="logo" alt="logo">
          <div>
            <div style="font-weight:700">Questionnaire Admin</div>
            <div class="muted">Admin Dashboard</div>
          </div>
        </div>
        <div style="text-align:right">
            <span>Welcome, Admin</span> &nbsp; <a href="?logout=1" style="color:white;text-decoration:underline">Logout</a>
            <div style="margin-top:6px"><a href="?export=csv" class="button">Export CSV</a></div>
        </div>
      </header>
      <div class="container">
        <div class="card">
          <h2>All Responses (<?php echo $total; ?>)</h2>
          <table>
            <thead><tr><th>ID</th><th>Patient Ref</th><th>Name</th><th>Date</th><th>Score</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo htmlspecialchars($r['id']); ?></td>
                  <td><?php echo htmlspecialchars($r['patient_ref_id'] ?: '-'); ?></td>
                  <td><?php echo htmlspecialchars($r['respondent_name'] ?: $r['respondent_email'] ?: '—'); ?></td>
                  <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                  <td><?php echo htmlspecialchars(number_format($r['total_pct'],2)); ?>%</td>
                  <td><a href="?view=<?php echo $r['id']; ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php
            $pages = (int)ceil($total / $perPage);
            if ($pages > 1) {
              echo '<div style="margin-top:12px">';
              for ($p=1;$p<=$pages;$p++){ $url = '?page='.$p; $style = $p==$page ? 'font-weight:bold;margin-right:8px' : 'margin-right:8px'; echo "<a href='".htmlspecialchars($url)."' style='$style'>".$p."</a>"; }
              echo '</div>';
            }
          ?>
        </div>
      </div>
    </body></html>
    <?php
    exit;
}

// ---------------- User Logic ----------------

// If we are viewing results (Thanks page)
if (isset($_GET['thanks']) && isset($_GET['id'])) {
    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $stmt = $pdo->prepare("SELECT * FROM responses WHERE id = :id");
        $stmt->execute([':id'=> (int)$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $currentUserRole === 'user' && $row['user_id'] != $currentUserId) {
            die("Access Denied");
        }
    } catch (PDOException $e) {
        $row = false;
    }

    if (!$row) die("Report not found.");
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Patient Report - <?php echo htmlspecialchars($row['patient_ref_id']); ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; background: #f4f7f6; margin: 0; padding: 40px 20px; color: #333; }
            .report-card { max-width: 800px; margin: 0 auto; background: white; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.05); overflow: hidden; }
            .report-header { background: linear-gradient(135deg, #0ea5a4, #20c997); color: white; padding: 40px; text-align: center; }
            .report-header h1 { margin: 0; font-size: 2rem; font-weight: 700; }
            .report-header .ref { opacity: 0.9; margin-top: 10px; font-size: 0.9rem; }
            .report-body { padding: 40px; }
            .patient-info { display: flex; justify-content: space-between; margin-bottom: 40px; border-bottom: 1px solid #eee; padding-bottom: 20px; flex-wrap: wrap; gap: 20px; }
            .patient-info div { font-size: 0.95rem; color: #666; }
            .patient-info strong { color: #333; }

            .score-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
            .score-box { padding: 20px; border: 1px solid #eee; border-radius: 12px; background: #fafbfc; }
            .score-label { font-weight: 600; margin-bottom: 8px; display: flex; justify-content: space-between; }
            .score-val { font-weight: 700; color: #0ea5a4; }
            .progress-bg { background: #e9ecef; height: 8px; border-radius: 4px; overflow: hidden; margin-top: 8px; }
            .progress-bar { height: 100%; background: #0ea5a4; border-radius: 4px; }

            .total-score { text-align: center; margin-top: 40px; padding: 30px; background: #f0fdfa; border-radius: 12px; border: 1px solid #ccfbf1; }
            .total-score .label { font-size: 1.1rem; color: #0f766e; margin-bottom: 10px; font-weight: 600; }
            .total-score .value { font-size: 3rem; font-weight: 800; color: #0ea5a4; line-height: 1; }

            .actions { margin-top: 40px; text-align: center; display: flex; gap: 10px; justify-content: center; }
            .btn { padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.2s; display: inline-block; }
            .btn-primary { background: #0ea5a4; color: white; }
            .btn-primary:hover { background: #0d9494; }
            .btn-secondary { background: #e9ecef; color: #495057; }
            .btn-secondary:hover { background: #dee2e6; }

            @media print {
                body { background: white; padding: 0; }
                .report-card { box-shadow: none; border: none; }
                .report-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .actions { display: none; }
                .score-box { page-break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="report-card">
            <div class="report-header">
                <h1>Health Assessment Report</h1>
                <div class="ref">Reference ID: <?php echo htmlspecialchars($row['patient_ref_id']); ?></div>
            </div>
            <div class="report-body">
                <div class="patient-info">
                    <div><strong>Name:</strong> <?php echo htmlspecialchars($row['respondent_name']); ?></div>
                    <div><strong>Age:</strong> <?php echo htmlspecialchars($row['respondent_age']); ?></div>
                    <div><strong>Gender:</strong> <?php echo htmlspecialchars($row['respondent_gender']); ?></div>
                    <div><strong>Date:</strong> <?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                </div>

                <div class="score-grid">
                    <?php foreach ($categories as $ck=>$ci):
                        $pct = $row[$ck . '_pct'];
                    ?>
                    <div class="score-box">
                        <div class="score-label">
                            <span><?php echo htmlspecialchars($ci['label']); ?></span>
                            <span class="score-val"><?php echo number_format($pct, 1); ?>%</span>
                        </div>
                        <div class="progress-bg">
                            <div class="progress-bar" style="width: <?php echo $pct; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="total-score">
                    <div class="label">Overall Wellness Score</div>
                    <div class="value"><?php echo number_format($row['total_pct'], 1); ?>%</div>
                </div>

                <div class="actions">
                    <a href="javascript:window.print()" class="btn btn-secondary">Print Report</a>
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-primary">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle "start_questionnaire" (Transition from PI form to Questions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_questionnaire') {
    // We can render the questionnaire form here, pre-filling hidden fields
    $name = $_POST['name'];
    $email = $_POST['email'];
    $age = $_POST['age'];
    $gender = $_POST['gender'];
    // Generate Patient ID
    $patient_ref_id = 'PID-' . date('Ymd') . '-' . mt_rand(1000,9999);

    ?>
    <!doctype html>
    <html>
    <head>
    <meta charset="utf-8">
    <title>Questionnaire</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      body{font-family:Arial,Helvetica,sans-serif;background:#eef6f6;margin:0;padding:0}
      header{background:linear-gradient(135deg,#0ea5a4,#4dd0c8);padding:18px;color:white;display:flex;align-items:center;justify-content:space-between}
      .logo{height:56px}
      .container{max-width:980px;margin:22px auto;padding:18px}
      .card{background:white;padding:18px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.06)}
      .qblock{margin-bottom:12px;padding:12px;border-radius:6px;background:#fbfbfd}
      label.q{display:block;font-weight:600;margin-bottom:8px}
      .submit{background:#0ea5a4;color:white;border:none;padding:10px 16px;border-radius:6px;font-size:1rem;cursor:pointer}
      .hint{font-size:0.95rem;color:#444}
    </style>
    </head>
    <body>
      <header>
        <div style="display:flex;align-items:center;gap:12px">
          <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="logo" class="logo">
          <div>
            <div style="font-weight:700">Patient Assessment</div>
            <div style="font-size:0.9rem">Ref: <?php echo $patient_ref_id; ?></div>
          </div>
        </div>
      </header>
      <div class="container">
        <div class="card">
          <form method="post" action="">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($name); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <input type="hidden" name="age" value="<?php echo htmlspecialchars($age); ?>">
            <input type="hidden" name="gender" value="<?php echo htmlspecialchars($gender); ?>">
            <input type="hidden" name="patient_ref_id" value="<?php echo htmlspecialchars($patient_ref_id); ?>">

            <?php for ($i=1;$i<=26;$i++): $qid = "q{$i}"; ?>
              <div class="qblock">
                <label class="q">Q: <?php echo htmlspecialchars($question_texts[$i]); ?></label>
                <div>A: <?php echo render_likert($qid, $likert_labels); ?></div>
              </div>
            <?php endfor; ?>
            <div style="text-align:right;margin-top:6px">
              <button type="submit" class="submit">Submit Assessment</button>
            </div>
          </form>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// If "new patient" is requested
if (isset($_GET['new_patient'])) {
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>New Patient</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            body{font-family:Arial,Helvetica,sans-serif;background:#f6f9fb;padding:18px}
            .card{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,0.06)}
            input, select {width:100%;padding:10px;margin:5px 0 15px;border:1px solid #ddd;border-radius:5px;box-sizing:border-box}
            .btn{padding:10px 20px;background:#0ea5a4;color:white;border:none;border-radius:5px;cursor:pointer}
        </style>
    </head>
    <body>
      <div class="card">
        <h2>New Patient Entry</h2>
        <form method="post" action="">
            <input type="hidden" name="action" value="start_questionnaire">
            <label>Full Name</label>
            <input type="text" name="name" required>

            <label>Email (Optional)</label>
            <input type="email" name="email">

            <label>Age</label>
            <input type="number" name="age" required>

            <label>Gender</label>
            <select name="gender" required>
                <option value="">Select...</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
            </select>

            <button type="submit" class="btn">Start Questionnaire</button>
        </form>
        <p><a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">Cancel</a></p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// User Dashboard (Default view for user)
// List user's patients
try {
    $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
    $stmt = $pdo->prepare("SELECT * FROM responses WHERE user_id = :uid ORDER BY created_at DESC");
    $stmt->execute([':uid' => $currentUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("DB Error");
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Dashboard</title>
<style>body{font-family:Arial,Helvetica,sans-serif;background:#eef4f6;margin:0;padding:0}header{background:linear-gradient(135deg,#0ea5a4,#4dd0c8);padding:14px;color:white;display:flex;align-items:center;justify-content:space-between}.logo{height:48px}.container{max-width:1100px;margin:18px auto;padding:12px}.card{background:white;padding:14px;border-radius:8px;box-shadow:0 10px 26px rgba(0,0,0,0.06)}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}a.button{display:inline-block;padding:8px 10px;background:#0ea5a4;border-radius:6px;text-decoration:none;color:white}.muted{color:#666;font-size:0.95rem}</style>
</head><body>
  <header>
    <div style="display:flex;align-items:center;gap:12px">
      <img src="<?php echo htmlspecialchars($logoPath); ?>" class="logo" alt="logo">
      <div>
        <div style="font-weight:700">User Dashboard</div>
        <div class="muted">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></div>
      </div>
    </div>
    <div style="text-align:right">
      <a href="?logout=1" style="color:white">Logout</a>
    </div>
  </header>
  <div class="container">
    <div style="text-align:right;margin-bottom:10px">
        <a href="?new_patient=1" class="button">+ Add New Patient</a>
    </div>
    <div class="card">
      <h2>My Patients</h2>
      <table>
        <thead><tr><th>Ref ID</th><th>Name</th><th>Date</th><th>Score</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['patient_ref_id']); ?></td>
              <td><?php echo htmlspecialchars($r['respondent_name']); ?></td>
              <td><?php echo htmlspecialchars($r['created_at']); ?></td>
              <td><?php echo htmlspecialchars(number_format($r['total_pct'],2)); ?>%</td>
              <td><a href="?thanks=1&id=<?php echo $r['id']; ?>">View Report</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body></html>
