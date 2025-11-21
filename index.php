<?php
/*
Single-file Questionnaire POC (index.php)
- Presents 26 mixed questions (sample texts included)
- Uses 5-point Likert scale (1..5) as requested
- Category mapping (provided by user):
    cat1: Q1,4,5,6,9,11,18 (max 35)
    cat2: Q2,3,7,8,12 (max 25)
    cat3: Q13..17,19..23 (10 questions, max 50)
    cat4: Q10,24,25,26 (max 20)
- Saves responses into MySQL table `responses` (schema below)
- Admin page: ?admin=1&key=ADMIN_KEY with list, view, CSV export
- Results page shows category-wise raw scores and percentages (clean UI)

SQL to create DB/table (run once):

CREATE DATABASE IF NOT EXISTS questionnaire_poc;
USE questionnaire_poc;

CREATE TABLE responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  respondent_name VARCHAR(255) DEFAULT NULL,
  respondent_email VARCHAR(255) DEFAULT NULL,
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

USAGE:
1. Edit DB credentials and ADMIN_KEY below.
2. Place this file in your web root (e.g., htdocs or www). Save as index.php.
3. Import the SQL above into MySQL.
4. Open http://localhost/index.php to use the questionnaire.
5. Admin: http://localhost/index.php?admin=1&key=YOUR_ADMIN_KEY

Logo: this file uses the uploaded image path as logo:
/mnt/data/56defa3e-7032-4792-8ed5-b9e5c0df2d7d.png
(the system will transform this local path to an accessible URL in your environment)

*/

// ---------------- CONFIG ----------------
$dbHost = '127.0.0.1';
$dbName = 'questionnaire_poc';
$dbUser = 'root';
$dbPass = ''; // set your DB password

// Change to a strong secret before deploying
$ADMIN_KEY = 'ccras54321';

// Logo path (uploaded file path)
$logoPath = '/qpoc/logo/newlogoccras_questionnaire.jpeg';

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

function connect_pdo($dbHost,$dbName,$dbUser,$dbPass){
    return new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

// ---------------- Handle Submit (public form) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['admin'])) {
    $respondent_name = trim($_POST['name'] ?? '');
    $respondent_email = trim($_POST['email'] ?? '');
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
            (respondent_name, respondent_email, answers, cat1_raw, cat2_raw, cat3_raw, cat4_raw,
             cat1_pct, cat2_pct, cat3_pct, cat4_pct, total_raw, total_pct)
            VALUES
            (:name, :email, :answers, :cat1_raw, :cat2_raw, :cat3_raw, :cat4_raw,
             :cat1_pct, :cat2_pct, :cat3_pct, :cat4_pct, :total_raw, :total_pct)");
        $stmt->execute([
            ':name'=>$respondent_name?:null,
            ':email'=>$respondent_email?:null,
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
    } catch (PDOException $e){
        die("DB error: ".htmlspecialchars($e->getMessage()));
    }

    // Show improved results page (category-wise scores visible)
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Your Scores</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
            body{font-family:Arial,Helvetica,sans-serif;background:#f6f9fb;padding:18px}
            .card{max-width:920px;margin:0 auto;background:white;padding:20px;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,0.06)}
            .header{display:flex;align-items:center;justify-content:space-between}
            .logo{height:56px}
            .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:18px}
            .box{background:#fbfcff;padding:12px;border-radius:8px;border:1px solid #eef6f6}
            .title{font-weight:700;margin-bottom:6px}
            .muted{color:#666;font-size:0.95rem}
            .progress{background:#eee;border-radius:999px;height:12px;overflow:hidden;margin-top:8px}
            .bar{height:12px;border-radius:999px}
            .overall{background:#fff9f0;border-left:6px solid #ff9900}
            .actions{margin-top:14px}
            a.btn{display:inline-block;padding:8px 12px;background:#0ea5a4;color:white;border-radius:6px;text-decoration:none}
        </style>
    </head>
    <body>
      <div class="card">
        <div class="header">
          <div style="display:flex;align-items:center;gap:12px">
            <img src="<?php echo htmlspecialchars($logoPath); ?>" class="logo" alt="logo">
            <div>
              <div style="font-weight:700;font-size:18px">Questionnaire — Results</div>
              <div class="muted">Reference ID: <?php echo htmlspecialchars($savedId); ?> &nbsp; • &nbsp; <?php echo date('Y-m-d H:i:s'); ?></div>
            </div>
          </div>
          <div style="text-align:right">
            <div style="font-weight:700">Summary</div>
            <div class="muted">Category-wise raw & percentage scores</div>
          </div>
        </div>

        <div class="grid">
          <?php foreach ($categories as $key => $cat): ?>
            <div class="box">
              <div class="title"><?php echo htmlspecialchars($cat['label']); ?> (Max <?php echo (int)$cat['max']; ?>)</div>
              <div><strong>Raw Score:</strong> <?php echo (int)$cat_raw[$key]; ?> / <?php echo (int)$cat['max']; ?></div>
              <div><strong>Percentage:</strong> <?php echo number_format($cat_pct[$key],2); ?>%</div>
              <div class="progress"><div class="bar" style="width:<?php echo min(100,$cat_pct[$key]); ?>%;background:linear-gradient(90deg,#2bb673,#14aaf5)"></div></div>
            </div>
          <?php endforeach; ?>

          <div class="box overall">
            <div class="title">Overall (Max <?php echo (int)$total_max; ?>)</div>
            <div><strong>Total Raw:</strong> <?php echo (int)$total_raw; ?> / <?php echo (int)$total_max; ?></div>
            <div><strong>Total Percentage:</strong> <?php echo number_format($total_pct,2); ?>%</div>
            <div class="progress"><div class="bar" style="width:<?php echo min(100,$total_pct); ?>%;background:linear-gradient(90deg,#ffb86b,#ff8a65)"></div></div>
          </div>
        </div>

        <div style="margin-top:16px">
          <div style="font-weight:700;margin-bottom:6px">Answers (raw values)</div>
          <pre style="background:#f7fbff;padding:10px;border-radius:6px;border:1px solid #eef6f6"><?php echo htmlspecialchars(json_encode($answers, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>

        <div class="actions">
          <a class="btn" href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">Fill new response</a>
          &nbsp; <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?admin=1&key=<?php echo urlencode($ADMIN_KEY); ?>" class="btn" style="background:#556bff">Open Admin</a>
        </div>

      </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------------- Admin / exports / view ----------------
$isAdmin = false;
if (isset($_GET['admin']) && isset($_GET['key']) && $_GET['key'] === $ADMIN_KEY) {
    $isAdmin = true;
}

// CSV export
if ($isAdmin && isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $stmt = $pdo->query("SELECT * FROM responses ORDER BY created_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=responses_export_'.date('Ymd_His').'.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','respondent_name','respondent_email','answers_json','cat1_raw','cat2_raw','cat3_raw','cat4_raw','cat1_pct','cat2_pct','cat3_pct','cat4_pct','total_raw','total_pct','created_at']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'],$r['respondent_name'],$r['respondent_email'],$r['answers'],$r['cat1_raw'],$r['cat2_raw'],$r['cat3_raw'],$r['cat4_raw'],$r['cat1_pct'],$r['cat2_pct'],$r['cat3_pct'],$r['cat4_pct'],$r['total_raw'],$r['total_pct'],$r['created_at']]);
        }
        fclose($out);
        exit;
    } catch (PDOException $e) {
        die("DB error: ".htmlspecialchars($e->getMessage()));
    }
}

// View single response
if ($isAdmin && isset($_GET['view']) && is_numeric($_GET['view'])) {
    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $stmt = $pdo->prepare("SELECT * FROM responses WHERE id = :id");
        $stmt->execute([':id'=> (int)$_GET['view']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) die("Response not found.");
    } catch (PDOException $e) {
        die("DB error: ".htmlspecialchars($e->getMessage()));
    }

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
        <table><tr><th>Name</th><td><?php echo htmlspecialchars($row['respondent_name']); ?></td></tr>
        <tr><th>Email</th><td><?php echo htmlspecialchars($row['respondent_email']); ?></td></tr></table>

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

        <h3 style="margin-top:12px">Answers (raw values)</h3>
        <pre><?php echo htmlspecialchars(json_encode(json_decode($row['answers'], true), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></pre>

        <p><a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'].'?admin=1&key='.$_GET['key']); ?>">← Back to list</a></p>
      </div>
    </body></html>
    <?php
    exit;
}

// Admin list
if ($isAdmin) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;
    $offset = ($page-1)*$perPage;

    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM responses");
        $total = (int)$totalStmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT id, respondent_name, respondent_email, cat1_pct, cat2_pct, cat3_pct, cat4_pct, total_pct, total_raw, created_at FROM responses ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
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
            <div class="muted">Stored responses</div>
          </div>
        </div>
        <div style="text-align:right">
          <div>Admin</div>
          <div style="margin-top:6px"><a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'].'?admin=1&key='.$_GET['key'].'&export=csv'); ?>" class="button">Export CSV</a></div>
        </div>
      </header>

      <div class="container">
        <div class="card">
          <h2>Responses (<?php echo $total; ?>)</h2>
          <table>
            <thead>
              <tr>
                <th>ID</th><th>Respondent</th><th>Submitted</th><th>Raw total</th><th>Total %</th><th>View</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo htmlspecialchars($r['id']); ?></td>
                  <td><?php echo htmlspecialchars($r['respondent_name'] ?: $r['respondent_email'] ?: '—'); ?></td>
                  <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                  <td><?php echo htmlspecialchars($r['total_raw']); ?></td>
                  <td><?php echo htmlspecialchars(number_format($r['total_pct'],2)); ?>%</td>
                  <td><a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF'].'?admin=1&key='.$_GET['key'].'&view='.$r['id']); ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php
            $pages = (int)ceil($total / $perPage);
            if ($pages > 1) {
              echo '<div style="margin-top:12px">';
              for ($p=1;$p<=$pages;$p++){ $url = $_SERVER['PHP_SELF'].'?admin=1&key='.urlencode($_GET['key']).'&page='.$p; $style = $p==$page ? 'font-weight:bold;margin-right:8px' : 'margin-right:8px'; echo "<a href='".htmlspecialchars($url)."' style='$style'>".$p."</a>"; }
              echo '</div>';
            }
          ?>

        </div>
      </div>
    </body></html>
    <?php
    exit;
}

// ---------------- Public: thank you / summary or show form ----------------
if (isset($_GET['thanks']) && isset($_GET['id'])) {
    try {
        $pdo = connect_pdo($dbHost,$dbName,$dbUser,$dbPass);
        $stmt = $pdo->prepare("SELECT * FROM responses WHERE id = :id");
        $stmt->execute([':id'=> (int)$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $row = false;
    }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Thanks</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f8fb;padding:18px}.card{max-width:860px;margin:0 auto;background:white;padding:18px;border-radius:8px;box-shadow:0 8px 26px rgba(0,0,0,0.06)}</style>
    </head><body>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center"><div><img src="<?php echo htmlspecialchars($logoPath); ?>" style="height:56px" alt="logo"></div><div style="text-align:right"><h2>Thank you!</h2></div></div>

        <p>Your responses were recorded. <?php if($row) echo 'Reference ID: <strong>'.htmlspecialchars($row['id']).'</strong>.' ?></p>

        <?php if ($row): ?>
          <h3>Summary</h3>
          <table style="width:100%;border-collapse:collapse">
            <tr><th style="text-align:left;padding:8px">Total raw</th><td style="padding:8px"><?php echo (int)$row['total_raw']; ?></td></tr>
            <tr><th style="text-align:left;padding:8px">Total %</th><td style="padding:8px"><?php echo number_format($row['total_pct'],2); ?>%</td></tr>
          </table>
        <?php endif; ?>

        <p><a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">← Back to questionnaire</a></p>
      </div>
    </body></html>
    <?php
    exit;
}

// Public: show the questionnaire form
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Questionnaire POC</title>
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
        <div style="font-weight:700">Questionnaire POC</div>
        <div style="font-size:0.9rem;opacity:0.95">26 mixed questions — backend scores by category</div>
      </div>
    </div>
    <div style="text-align:right">
      <div style="font-weight:700">POC</div>
      <div style="font-size:0.9rem">Stored to MySQL</div>
    </div>
  </header>

  <div class="container">
    <div class="card">
      <h1>Questionnaire</h1>
      <p class="hint">Answer each question using the 5-point Likert scale. Values are saved as raw points (category scoring happens in the backend).</p>

      <form method="post" action="">
        <div style="margin-bottom:12px">
          <label>Name: <input type="text" name="name" placeholder="Optional"></label>
          &nbsp;&nbsp;
          <label>Email: <input type="email" name="email" placeholder="Optional"></label>
        </div>

        <?php for ($i=1;$i<=26;$i++): $qid = "q{$i}"; ?>
          <div class="qblock">
            <label class="q">Q: <?php echo htmlspecialchars($question_texts[$i]); ?></label>
            <div>A: <?php echo render_likert($qid, $likert_labels); ?></div>
            <!-- <div style="font-size:0.85rem;color:#666;margin-top:6px"><strong>Note:</strong> This question will be scored as <em><?php //echo htmlspecialchars($categories[$cat_map[$i]]['label']); ?></em>.</div> -->
          </div>
        <?php endfor; ?>

        <div style="text-align:right;margin-top:6px">
          <button type="submit" class="submit">Submit &amp; Calculate</button>
        </div>
      </form>

      <!-- <div style="margin-top:18px;color:#666;font-size:0.9rem">
        Admin? Open: <code><?php //echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?admin=1&key=YOUR_KEY</code> (replace <strong>YOUR_KEY</strong> with the value of <code>$ADMIN_KEY</code> in this file).
      </div> -->
    </div>
  </div>
</body>
</html>
