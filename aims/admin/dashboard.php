<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('admin');
require_once __DIR__ . '/../includes/layout.php';

function agg_one(mysqli $db, string $sql, string $type = '', ...$binds) {
    $stmt = $db->prepare($sql);
    if (!$stmt) return 0;
    if ($type && $binds) { $stmt->bind_param($type, ...$binds); }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? ($row[0] ?? 0) : 0;
}

$todaySql = date('Y-m-d');
$d7ago    = date('Y-m-d', strtotime('-7 days'));
$d30ago   = date('Y-m-d', strtotime('-30 days'));
$dayOfWeek = date('l');

$kpi = [
    'students'        => (int)agg_one($mysqli, "SELECT COUNT(*) FROM students"),
    'teachers'        => (int)agg_one($mysqli, "SELECT COUNT(*) FROM teachers"),
    'subjects'        => (int)agg_one($mysqli, "SELECT COUNT(*) FROM subjects"),
    'sections'        => (int)agg_one($mysqli, "SELECT COUNT(*) FROM sections"),
    'classes_today'   => 0,
    'enrolled_sy'     => 0,
    'attendance_7d'   => 0.0,
    'attendance_30d'  => 0.0,
    'passing_rate'    => 0.0,
    'passed_count'    => 0,
    'failed_count'    => 0,
    'graded_total'    => 0,
    'att7_on_time'    => 0,
    'att7_total'      => 0,
];

$kpi['enrolled_sy'] = (int)agg_one($mysqli, "
    SELECT COUNT(DISTINCT e.student_id)
    FROM enrollments e
    JOIN school_years sy ON e.school_year_id = sy.id AND sy.is_current = 1
    WHERE e.status = 'enrolled'
");
if ($kpi['enrolled_sy'] === 0 && $kpi['students'] > 0) {
    $kpi['enrolled_sy'] = $kpi['students'];
}

$stmtClass = $mysqli->prepare("
    SELECT COUNT(DISTINCT CONCAT(ts.teacher_id,'-',ts.section_id,'-',ts.subject_id))
    FROM schedules s
    JOIN teacher_subjects ts ON s.teacher_subject_id = ts.id
    JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
    WHERE s.day_of_week = ?
");
if ($stmtClass) {
    $stmtClass->bind_param('s', $dayOfWeek);
    $stmtClass->execute();
    $row = $stmtClass->get_result()->fetch_row();
    $kpi['classes_today'] = (int)($row[0] ?? 0);
    $stmtClass->close();
}

function att_rate(mysqli $db, string $from, string $to, &$onTimeRef, &$totalRef): float {
    $stmt = $db->prepare("
        SELECT status, COUNT(*) AS c FROM attendance
        WHERE attendance_date BETWEEN ? AND ? GROUP BY status
    ");
    $onTime = 0; $total = 0;
    if ($stmt) {
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $c = (int)$r['c'];
            $total += $c;
            if (in_array($r['status'], ['Present','Late'], true)) $onTime += $c;
        }
        $stmt->close();
    }
    $onTimeRef = $onTime;
    $totalRef = $total;
    return $total > 0 ? round(($onTime / $total) * 100, 1) : 0.0;
}
$kpi['attendance_7d']  = att_rate($mysqli, $d7ago,  $todaySql, $kpi['att7_on_time'], $kpi['att7_total']);
$kpi['attendance_30d'] = att_rate($mysqli, $d30ago, $todaySql, $_tmp1, $_tmp2);

$stmtPF = $mysqli->query("
    SELECT
        SUM(CASE WHEN avg_grade >= 75 THEN 1 ELSE 0 END) AS passed,
        SUM(CASE WHEN avg_grade <  75 THEN 1 ELSE 0 END) AS failed,
        COUNT(*) AS total
    FROM (
        SELECT g.student_id, AVG(g.grade) AS avg_grade
        FROM grades g
        JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
        JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
        GROUP BY g.student_id
    ) AS stu
");
if ($stmtPF) {
    $r = $stmtPF->fetch_assoc();
    $kpi['passed_count'] = (int)($r['passed'] ?? 0);
    $kpi['failed_count'] = (int)($r['failed'] ?? 0);
    $kpi['graded_total'] = (int)($r['total'] ?? 0);
    if ($kpi['graded_total'] > 0) {
        $kpi['passing_rate'] = round(($kpi['passed_count'] / $kpi['graded_total']) * 100, 1);
    }
    $stmtPF->free();
}

$attDaily = [];
$resDaily = $mysqli->query("
    SELECT DATE(attendance_date) AS d,
        SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) AS p,
        SUM(CASE WHEN status='Absent'  THEN 1 ELSE 0 END) AS a,
        SUM(CASE WHEN status='Late'    THEN 1 ELSE 0 END) AS l,
        SUM(CASE WHEN status='Excused' THEN 1 ELSE 0 END) AS e
    FROM attendance WHERE attendance_date BETWEEN '{$d7ago}' AND '{$todaySql}'
    GROUP BY DATE(attendance_date) ORDER BY d ASC
");
if ($resDaily) { while ($r = $resDaily->fetch_assoc()) $attDaily[] = $r; $resDaily->free(); }

$gradeByLvl = [];
$resGrd = $mysqli->query("
    SELECT s.grade_level AS lvl, ROUND(AVG(g.grade),2) AS g,
           COUNT(DISTINCT g.student_id) AS cnt
    FROM grades g
    JOIN students s ON g.student_id = s.id
    JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
    JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
    GROUP BY s.grade_level ORDER BY s.grade_level ASC
");
if ($resGrd) { while ($r = $resGrd->fetch_assoc()) $gradeByLvl[] = $r; $resGrd->free(); }

$subjPerf = [];
$resSub = $mysqli->query("
    SELECT sub.subject_name AS s, ROUND(AVG(g.grade),2) AS g,
           COUNT(DISTINCT g.student_id) AS c
    FROM grades g JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
    JOIN subjects sub ON ts.subject_id = sub.id
    JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
    GROUP BY sub.id ORDER BY g DESC LIMIT 6
");
if ($resSub) { while ($r = $resSub->fetch_assoc()) $subjPerf[] = $r; $resSub->free(); }

$recentActivities = [];
$resAct = $mysqli->query("
    (SELECT 'Student' AS type, CONCAT(last_name, ', ', first_name) AS description, created_at
     FROM students ORDER BY created_at DESC LIMIT 3)
    UNION ALL
    (SELECT CONCAT('User (', role, ')') AS type, username AS description, created_at
     FROM users ORDER BY created_at DESC LIMIT 3)
    UNION ALL
    (SELECT 'Announcement' AS type, title AS description, created_at
     FROM announcements ORDER BY created_at DESC LIMIT 2)
    ORDER BY created_at DESC LIMIT 5
");
if ($resAct) { while ($row = $resAct->fetch_assoc()) $recentActivities[] = $row; $resAct->free(); }

$toJson = static function($v): string {
    $j = json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS);
    return $j === false ? '[]' : $j;
};
$attDailyJson    = $toJson($attDaily);
$gradeByLvlJson  = $toJson($gradeByLvl);
$subjPerfJson    = $toJson($subjPerf);
$pfJson = $toJson(['passed'=>$kpi['passed_count'],'failed'=>$kpi['failed_count']]);

$sidebar = [
    ['label' => 'Dashboard',     'href' => 'dashboard.php',     'active' => true, 'icon' => 'dashboard'],
    ['label' => 'Students',      'href' => 'students.php',      'icon' => 'students'],
    ['label' => 'Teachers',      'href' => 'teachers.php',      'icon' => 'teachers'],
    ['label' => 'Subjects',      'href' => 'subjects.php',      'icon' => 'subjects'],
    ['label' => 'Schedules',     'href' => 'sections.php',      'icon' => 'sections'],
    ['label' => 'Users',         'href' => 'users.php',         'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'icon' => 'announcements'],
    ['label' => 'Reports',       'href' => 'reports.php',       'icon' => 'reports'],
];
start_layout('Admin Dashboard - AIMS', 'AIMS - Admin', $_SESSION['username'] ?? '', $sidebar);
?>
<style>
  /* ===== Modern dashboard system (dashboard pages only) ===== */
  :root{ --ins-radius:14px; --ins-border:#e5e7eb; --ins-shadow:0 1px 3px rgba(15,23,42,.06); --ins-shadow-hover:0 8px 20px rgba(15,23,42,.08); }
  .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.9rem;}
  .kpi-card{border-radius:var(--ins-radius);padding:1rem 1.15rem;box-shadow:var(--ins-shadow);transition:transform .15s ease,box-shadow .15s ease;}
  .kpi-card:hover{transform:translateY(-2px);box-shadow:var(--ins-shadow-hover);}
  .kpi-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;opacity:.92;}
  .kpi-value{font-size:1.85rem;font-weight:800;line-height:1.15;margin:.15rem 0;}
  .kpi-foot{font-size:.78rem;opacity:.95;}
  .kpi-card a{text-decoration:underline;text-underline-offset:2px;}
  .kpi-card a:focus-visible,.ins-summary:focus-visible,.btn:focus-visible{outline:2px solid #2563eb;outline-offset:2px;border-radius:6px;}
  .analytics-grid{display:flex;flex-wrap:wrap;align-items:flex-start;gap:1rem;}
  /* Theater mode: growing a card's flex-grow smoothly pulls the shared edge toward its neighbor.
     A left-hand card visibly expands rightward, a right-hand card expands leftward, and the
     collapsed sibling shrinks in place in the same motion instead of snapping to a new row. */
  .analytics-grid > .ins-card{flex-grow:1;flex-shrink:1;flex-basis:320px;min-width:0;transition:flex-grow .45s cubic-bezier(.4,0,.2,1),flex-basis .45s cubic-bezier(.4,0,.2,1),box-shadow .15s ease;}
  .analytics-grid > .ins-card.is-open{flex-grow:2.4;flex-basis:380px;}
  /* Collapsible insight card: button-based disclosure with a diagonal reveal animation */
  .ins-card{border:1px solid var(--ins-border);border-radius:var(--ins-radius);background:#fff;box-shadow:var(--ins-shadow);overflow:hidden;transition:box-shadow .15s ease;}
  .ins-card:hover{box-shadow:var(--ins-shadow-hover);}
  .ins-summary{all:unset;box-sizing:border-box;width:100%;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.9rem 1.1rem;}
  .ins-summary .ins-title{min-width:0;}
  .ins-summary .ins-title h6{margin:0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .ins-summary .ins-title small{display:block;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .ins-summary .ins-right{display:flex;align-items:center;gap:.6rem;flex-shrink:0;}
  .ins-summary .ins-stat{font-weight:700;font-size:.95rem;color:#0f172a;white-space:nowrap;}
  .ins-chevron{width:18px;height:18px;transition:transform .3s cubic-bezier(.4,0,.2,1);color:#64748b;flex-shrink:0;}
  .ins-card.is-open .ins-chevron{transform:rotate(180deg);}
  /* Height reveal via grid-template-rows: animates smoothly with no fixed max-height */
  .ins-body-wrap{display:grid;grid-template-rows:0fr;transition:grid-template-rows .35s cubic-bezier(.4,0,.2,1);}
  .ins-card.is-open .ins-body-wrap{grid-template-rows:1fr;}
  .ins-body{overflow:hidden;min-height:0;border-top:1px solid transparent;}
  .ins-card.is-open .ins-body{border-top-color:var(--ins-border);}
  /* Diagonal reveal: content grows + fades in from the top-left corner */
  .ins-body-inner{padding:1rem 1.1rem 1.1rem;transform-origin:top left;transform:scale(.92) translate(-14px,-14px);opacity:0;transition:transform .3s cubic-bezier(.4,0,.2,1),opacity .25s ease;}
  .ins-card.is-open .ins-body-inner{transform:scale(1) translate(0,0);opacity:1;}
  .ins-body.p-0 .ins-body-inner{padding:0;}
  .ins-summary:focus-visible{outline:2px solid #2563eb;outline-offset:-2px;border-radius:var(--ins-radius);}
  @media (prefers-reduced-motion: reduce){
    .ins-chevron,.ins-body-wrap,.ins-body-inner{transition:none;}
  }
</style>
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
        <div>
            <h1 class="h4 mb-0">Admin Dashboard</h1>
            <small class="text-muted">Overview of today and key metrics</small>
        </div>
        <small class="text-muted"><?php echo date('l, F d, Y — H:i A'); ?></small>
    </div>

    <div class="kpi-grid mb-2">
        <div class="kpi-card kpi-success">
            <div class="kpi-label">Students</div>
            <div class="kpi-value"><?php echo $kpi['students']; ?></div>
            <div class="kpi-foot"><span class="fw-bold"><?php echo $kpi['enrolled_sy']; ?></span> enrolled SY · <a class="text-white" href="students.php">Manage →</a></div>
        </div>
        <div class="kpi-card kpi-primary">
            <div class="kpi-label">Teachers</div>
            <div class="kpi-value"><?php echo $kpi['teachers']; ?></div>
            <a class="kpi-foot text-white d-block" href="teachers.php">Manage →</a>
        </div>
        <div class="kpi-card kpi-info">
            <div class="kpi-label">Subjects</div>
            <div class="kpi-value"><?php echo $kpi['subjects']; ?></div>
            <div class="kpi-foot">Across <strong><?php echo $kpi['sections']; ?></strong> sections</div>
        </div>
        <div class="kpi-card kpi-warning">
            <div class="kpi-label">Today · <?php echo $dayOfWeek; ?></div>
            <div class="kpi-value"><?php echo $kpi['classes_today']; ?></div>
            <div class="kpi-foot">Scheduled class sections</div>
        </div>
        <div class="kpi-card kpi-secondary">
            <div class="kpi-label">Attendance 7d</div>
            <div class="kpi-value"><?php echo $kpi['attendance_7d']; ?>%</div>
            <div class="kpi-foot"><strong><?php echo $kpi['att7_on_time']; ?></strong>/<?php echo $kpi['att7_total']; ?> on-time · 30d: <strong><?php echo $kpi['attendance_30d']; ?>%</strong></div>
        </div>
        <div class="kpi-card kpi-danger">
            <div class="kpi-label">Passing Rate</div>
            <div class="kpi-value"><?php echo $kpi['passing_rate']; ?>%</div>
            <div class="kpi-foot"><strong class="text-success"><?php echo $kpi['passed_count']; ?></strong> pass · <strong><?php echo $kpi['failed_count']; ?></strong> below (of <?php echo $kpi['graded_total']; ?>)</div>
        </div>
    </div>

    <?php $topSubjStat = !empty($subjPerf) ? number_format((float)$subjPerf[0]['g'], 1) . ' avg' : '—'; ?>
    <div class="analytics-grid mb-2">
        <div class="ins-card" id="det-att7" data-default-open="true">
            <button type="button" class="ins-summary" aria-expanded="true" aria-controls="body-att7" id="btn-att7">
                <span class="ins-title">
                    <h6>Attendance — Last 7 days</h6>
                    <small>Values on each slice · hover for exact totals</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['attendance_7d']; ?>%</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-att7" role="region" aria-labelledby="btn-att7">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:240px"><canvas id="att7Chart"></canvas></div>
                <a href="reports.php" class="btn btn-sm btn-outline-primary mt-2">Reports →</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="ins-card" id="det-gradelvl" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-gradelvl" id="btn-gradelvl">
                <span class="ins-title">
                    <h6>Average Grade by Level</h6>
                    <small>Grade printed on bar · red = below 75</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['passing_rate']; ?>% passing</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-gradelvl" role="region" aria-labelledby="btn-gradelvl">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:240px"><canvas id="gradeLvlChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="analytics-grid mb-2">
        <div class="ins-card" id="det-subj" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-subj" id="btn-subj">
                <span class="ins-title">
                    <h6>Top Subjects by Avg Grade</h6>
                    <small>Numerical labels · count of graded students in tooltip</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $topSubjStat; ?></span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-subj" role="region" aria-labelledby="btn-subj">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:240px"><canvas id="subjChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ins-card" id="det-pf" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-pf" id="btn-pf">
                <span class="ins-title">
                    <h6>Pass / Fail Distribution</h6>
                    <small>Count + percentage shown directly on slice</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['passing_rate']; ?>% passing</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-pf" role="region" aria-labelledby="btn-pf">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:240px"><canvas id="pfChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="analytics-grid">
        <div class="ins-card" id="det-activities" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-activities" id="btn-activities">
                <span class="ins-title">
                    <h6>Recent Activities</h6>
                    <small>Latest 5 records</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo count($recentActivities); ?> recent</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-activities" role="region" aria-labelledby="btn-activities">
                <div class="ins-body p-0">
                    <div class="ins-body-inner">
                        <ul class="list-group list-group-flush">
                    <?php if (empty($recentActivities)): ?>
                        <li class="list-group-item text-muted small py-2 px-3">No recent activities yet.</li>
                    <?php else: ?>
                        <?php foreach ($recentActivities as $act): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                <div class="small">
                                    <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($act['type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php echo htmlspecialchars($act['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <small class="text-muted"><?php echo htmlspecialchars(date('M d · H:i', strtotime($act['created_at'])), ENT_QUOTES, 'UTF-8'); ?></small>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="ins-card" id="det-calendar" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-calendar" id="btn-calendar">
                <span class="ins-title">
                    <h6>This Month</h6>
                    <small>Date reference</small>
                </span>
                <span class="ins-right">
                    
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-calendar" role="region" aria-labelledby="btn-calendar">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div id="rt-calendar-admin"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php end_layout(); ?>

<script>
(function(){
  if (window.Chart) {
    Chart.register(window.ChartDataLabels);
    Chart.defaults.set('plugins.datalabels', {
      anchor: 'end', align: 'top', offset: 2,
      font: { weight: 700, size: 11 }, color: '#1f2937',
      formatter: function(v){
        if (v === null || v === undefined || v === 0) return '';
        return Number.isInteger(v) ? String(v) : Number(v).toFixed(1);
      }
    });
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    Chart.defaults.plugins.legend.labels.padding = 8;
    Chart.defaults.plugins.legend.position = 'bottom';
  }

  // Wire up every collapsible dashboard card: a button toggles the
  // .is-open class, which drives the CSS diagonal-reveal animation, and
  // fires an 'ins-toggle' event so charts can be built lazily below.
  document.querySelectorAll('.ins-card').forEach(function (card) {
    var btn = card.querySelector('.ins-summary');
    if (!btn) return;
    if (card.dataset.defaultOpen === 'true') card.classList.add('is-open');
    btn.addEventListener('click', function () {
      var open = !card.classList.contains('is-open');
      card.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      card.dispatchEvent(new CustomEvent('ins-toggle', { detail: { open: open } }));
    });
  });

  // Charts inside a closed card render into a 0-width canvas, so each
  // chart is built the first time its card is actually opened.
  function lazyChart(cardId, factory) {
    var card = document.getElementById(cardId);
    if (!card) return;
    var built = false;
    function run() { if (!built) { built = true; factory(); } }
    if (card.classList.contains('is-open')) run();
    card.addEventListener('ins-toggle', function (e) { if (e.detail.open) run(); });
  }

  (function renderCalendar(){
    var el = document.getElementById('rt-calendar-admin'); if (!el) return;
    var now = new Date(); var year = now.getFullYear(); var month = now.getMonth();
    var first = new Date(year, month, 1).getDay(); var days = new Date(year, month+1, 0).getDate();
    var weekDays = ['S','M','T','W','T','F','S']; var todayDay = now.getDay();
    var html = '<table class="table table-sm mb-0 text-center" style="font-size:0.75rem"><thead><tr>';
    for (var i=0;i<7;i++){
      var s = (i===todayDay) ? ' style="background:rgba(var(--accent-rgb),0.15);border-radius:4px"' : '';
      html += '<th'+s+'>'+weekDays[i]+'</th>';
    }
    html += '</tr></thead><tbody><tr>';
    for (var i=0;i<first;i++) html += '<td></td>';
    var day=1;
    for (; day<=days; day++){
      var c = (day===now.getDate()) ? ' class="text-white rounded-circle" style="background:var(--accent);width:22px;height:22px;display:inline-block;line-height:22px;font-weight:700"' : '';
      html += '<td><span'+c+'>'+day+'</span></td>';
      if (((day+first)%7)===0 && day!==days) html += '</tr><tr>';
    }
    var rem = (first+days)%7; if (rem!==0){ for(var r=rem;r<7;r++) html += '<td></td>'; }
    html += '</tr></tbody></table>';
    var head = '<div class="d-flex justify-content-between align-items-center mb-1"><strong class="small">'+now.toLocaleString(undefined,{month:'long'})+' '+year+'</strong></div>';
    el.innerHTML = head + html;
  })();

  var attRaw = JSON.parse('<?php echo $attDailyJson; ?>');
  lazyChart('det-att7', function(){
  if (window.Chart && document.getElementById('att7Chart')) {
    var labels = attRaw.map(function(r){
      var d = new Date(r.d + 'T00:00:00');
      return d.toLocaleDateString(undefined,{weekday:'short', day:'numeric'});
    });
    new Chart(document.getElementById('att7Chart'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label:'Present', data:attRaw.map(r=>r.p), backgroundColor:'#22c55e', stack:'s' },
          { label:'Late',    data:attRaw.map(r=>r.l), backgroundColor:'#f59e0b', stack:'s' },
          { label:'Excused', data:attRaw.map(r=>r.e), backgroundColor:'#06b6d4', stack:'s' },
          { label:'Absent',  data:attRaw.map(r=>r.a), backgroundColor:'#ef4444', stack:'s' }
        ]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ position:'bottom', labels:{ boxWidth:10 } },
          tooltip:{
            callbacks:{
              footer: function(items){
                var t = items.reduce((a,i)=>a + Number(i.parsed.y||0),0);
                return 'Day total: ' + t + ' records';
              }
            }
          },
          datalabels:{
            formatter: v => v>0 ? String(v) : '',
            color:'#fff', textStrokeColor:'rgba(0,0,0,.18)', textStrokeWidth:2,
            font:{ weight:700, size:10 }
          }
        },
        scales:{
          x:{ stacked:true, grid:{display:false}, ticks:{ font:{size:10}} },
          y:{ stacked:true, beginAtZero:true, ticks:{ precision:0, font:{size:10}}, title:{display:true, text:'Students', font:{size:10}} }
        }
      }
    });
  }
  });

  var grdRaw = JSON.parse('<?php echo $gradeByLvlJson; ?>');
  lazyChart('det-gradelvl', function(){
  if (window.Chart && document.getElementById('gradeLvlChart')) {
    new Chart(document.getElementById('gradeLvlChart'), {
      type:'bar',
      data:{
        labels: grdRaw.length ? grdRaw.map(r=>r.lvl) : ['(No data)'],
        datasets:[{
          label:'Avg Grade',
          data: grdRaw.length ? grdRaw.map(r=>Number(r.g)) : [0],
          backgroundColor: grdRaw.length ? grdRaw.map(r=>Number(r.g)>=75?'#22c55e':'#ef4444') : ['#cbd5e1'],
          borderRadius:6, borderSkipped:false
        }]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{display:false},
          tooltip:{
            callbacks:{
              afterTitle: items => grdRaw.length ? ('Students: ' + (grdRaw[items[0].dataIndex].cnt|0)) : '',
              label: i => {
                var n = Number(i.parsed.y);
                return 'Average: ' + n.toFixed(2) + ' / 100' + (n>=75?'  (Passing)':'  (Below)');
              }
            }
          },
          datalabels:{
            formatter: v => v>0 ? Number(v).toFixed(1) : '',
            color:'#0f172a', font:{weight:700, size:11}
          }
        },
        scales:{
          x:{grid:{display:false}, ticks:{font:{size:10}}},
          y:{min:60, max:100, ticks:{stepSize:5, font:{size:10}}, title:{display:true, text:'Grade (75 = pass)', font:{size:10}}}
        }
      },
      plugins:[{
        id:'passLine',
        afterDraw:function(chart){
          if (!grdRaw.length) return;
          var y = chart.scales['y'].getPixelForValue(75);
          var c = chart.ctx; c.save();
          c.beginPath(); c.moveTo(chart.chartArea.left, y); c.lineTo(chart.chartArea.right, y);
          c.strokeStyle = 'rgba(239,68,68,.7)'; c.setLineDash([5,3]); c.lineWidth = 1.5; c.stroke();
          c.fillStyle='rgba(239,68,68,.9)'; c.font='bold 9px Inter';
          c.fillText('75 (Pass)', chart.chartArea.right - 68, y - 3);
          c.restore();
        }
      }]
    });
  }
  });

  var subjRaw = JSON.parse('<?php echo $subjPerfJson; ?>');
  lazyChart('det-subj', function(){
  if (window.Chart && document.getElementById('subjChart')) {
    var empty = subjRaw.length === 0;
    new Chart(document.getElementById('subjChart'), {
      type:'bar',
      data:{
        labels: empty ? ['(No grades yet)'] : subjRaw.map(r=>r.s),
        datasets:[{
          label:'Avg Grade',
          data: empty ? [0] : subjRaw.map(r=>Number(r.g)),
          backgroundColor: empty ? ['#cbd5e1'] : subjRaw.map(r=>Number(r.g)>=75?'#22c55e':'#ef4444'),
          borderRadius:6, borderSkipped:false
        }]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{display:false},
          tooltip:{
            callbacks:{
              label: i => {
                if (empty) return 'Encode grades to populate.';
                var r = subjRaw[i.dataIndex];
                return ['Avg: ' + Number(i.parsed.x).toFixed(2), 'Students graded: ' + (r.c|0)];
              }
            }
          },
          datalabels:{
            anchor:'end', align:'right',
            formatter: v => v>0 ? Number(v).toFixed(1) : '',
            color:'#0f172a', font:{weight:700, size:10}
          }
        },
        scales:{
          x:{min:60, max:100, ticks:{stepSize:5, font:{size:10}}},
          y:{grid:{display:false}, ticks:{font:{size:10}}}
        }
      }
    });
  }
  });

  var pf = JSON.parse('<?php echo $pfJson; ?>');
  lazyChart('det-pf', function(){
  if (window.Chart && document.getElementById('pfChart')) {
    var passed = Number(pf.passed)|0, failed = Number(pf.failed)|0;
    var total = passed + failed;
    new Chart(document.getElementById('pfChart'), {
      type:'doughnut',
      data:{
        labels:['Passed (≥75)','Failed (<75)'],
        datasets:[{
          data:[passed, failed],
          backgroundColor:['#22c55e','#ef4444'],
          borderWidth:2, borderColor:'#fff'
        }]
      },
      options:{
        responsive:true, maintainAspectRatio:false, cutout:'62%',
        plugins:{
          legend:{position:'bottom', labels:{boxWidth:10, padding:8, font:{size:10}}},
          tooltip:{
            callbacks:{
              label: i => {
                var v = Number(i.parsed);
                var pct = total>0 ? (v/total*100).toFixed(1) : '0.0';
                return i.label + ': ' + v + '  (' + pct + '%)';
              }
            }
          },
          datalabels:{
            color:'#fff',
            formatter: (v) => {
              if (!v) return '';
              var pct = total>0 ? (v/total*100).toFixed(0) : '0';
              return v + '\n' + pct + '%';
            },
            font:{weight:700, size:11}, textAlign:'center',
            textStrokeColor:'rgba(0,0,0,.2)', textStrokeWidth:2
          }
        }
      }
    });
  }
  });
})();
</script>
