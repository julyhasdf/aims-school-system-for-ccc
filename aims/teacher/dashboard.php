<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('teacher');
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

$teacherInfo = null;
$classLoads = [];
$schedules = [];
$advisorySection = null;
$tid = 0;

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $mysqli->prepare('SELECT id, first_name, last_name, department, specialization FROM teachers WHERE user_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $teacherInfo = $result->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($teacherInfo) {
        $tid = (int)$teacherInfo['id'];

        $loadStmt = $mysqli->prepare(
            'SELECT ts.id, subj.subject_code, subj.subject_name, sec.section_name, sec.grade_level, sy.name AS school_year
             FROM teacher_subjects ts
             JOIN subjects subj ON subj.id = ts.subject_id
             JOIN sections sec ON sec.id = ts.section_id
             JOIN school_years sy ON sy.id = ts.school_year_id AND sy.is_current = 1
             WHERE ts.teacher_id = ?
             ORDER BY sy.name DESC, sec.section_name ASC, subj.subject_code ASC'
        );
        if ($loadStmt) {
            $loadStmt->bind_param('i', $tid);
            $loadStmt->execute();
            $res = $loadStmt->get_result();
            while ($row = $res->fetch_assoc()) $classLoads[] = $row;
            $loadStmt->close();
        }

        $schedStmt = $mysqli->prepare(
            "SELECT sch.day_of_week, sch.start_time, sch.end_time, sch.room,
                    subj.subject_code, sec.section_name
             FROM schedules sch
             JOIN teacher_subjects ts ON sch.teacher_subject_id = ts.id
             JOIN subjects subj ON ts.subject_id = subj.id
             JOIN sections sec ON ts.section_id = sec.id
             WHERE ts.teacher_id = ?
             ORDER BY FIELD(sch.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), sch.start_time"
        );
        if ($schedStmt) {
            $schedStmt->bind_param('i', $tid);
            $schedStmt->execute();
            $res = $schedStmt->get_result();
            while ($row = $res->fetch_assoc()) $schedules[] = $row;
            $schedStmt->close();
        }

        $advStmt = $mysqli->prepare("SELECT section_name, grade_level FROM sections WHERE adviser_id = ? LIMIT 1");
        if ($advStmt) {
            $advStmt->bind_param('i', $tid);
            $advStmt->execute();
            $res = $advStmt->get_result();
            $advisorySection = $res->fetch_assoc();
            $advStmt->close();
        }
    }
}

$tsIds = [];
foreach ($classLoads as $cl) $tsIds[] = (int)$cl['id'];
$tsIdsList = $tsIds ? implode(',', array_map('intval', $tsIds)) : '0';

$kpi = [
    'my_subjects' => count($classLoads),
    'my_sections' => count(array_unique(array_column($classLoads, 'section_name'))),
    'periods_week' => 0,
    'students_taught' => 0,
    'graded_students' => 0,
    'pass_rate' => 0.0,
    'passed_count' => 0,
    'failed_count' => 0,
    'attendance_7d' => 0.0,
    'att7_on_time' => 0,
    'att7_total' => 0,
    'advisory_students' => 0,
];

$kpi['periods_week'] = (int)agg_one($mysqli, "
    SELECT COUNT(*) FROM schedules s
    JOIN teacher_subjects ts ON s.teacher_subject_id = ts.id
    JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
    WHERE ts.teacher_id = ?
", 'i', $tid);

if ($tsIds) {
    $kpi['students_taught'] = (int)agg_one($mysqli, "
        SELECT COUNT(DISTINCT e.student_id) FROM enrollments e
        JOIN teacher_subjects ts ON e.section_id = ts.section_id
        WHERE ts.id IN ({$tsIdsList}) AND e.status='enrolled'
    ");
    if ($kpi['students_taught'] === 0) {
        $secIds = array_unique(array_column($classLoads, 'section_name'));
        if (!empty($secIds)) {
            $secPlaceholders = implode(',', array_fill(0, count($secIds), '?'));
            $types = str_repeat('s', count($secIds));
            $kpi['students_taught'] = (int)agg_one($mysqli, "
                SELECT COUNT(*) FROM students s
                JOIN sections sec ON s.section_id = sec.id
                WHERE sec.section_name IN ({$secPlaceholders})
            ", $types, ...$secIds);
        }
    }

    $stmtPF = $mysqli->query("
        SELECT
            SUM(CASE WHEN avg_grade >= 75 THEN 1 ELSE 0 END) AS passed,
            SUM(CASE WHEN avg_grade <  75 THEN 1 ELSE 0 END) AS failed,
            COUNT(*) AS total
        FROM (
            SELECT g.student_id, AVG(g.grade) AS avg_grade
            FROM grades g WHERE g.teacher_subject_id IN ({$tsIdsList})
            GROUP BY g.student_id
        ) AS sg
    ");
    if ($stmtPF) {
        $r = $stmtPF->fetch_assoc();
        $kpi['passed_count'] = (int)($r['passed'] ?? 0);
        $kpi['failed_count'] = (int)($r['failed'] ?? 0);
        $kpi['graded_students'] = (int)($r['total'] ?? 0);
        if ($kpi['graded_students'] > 0) {
            $kpi['pass_rate'] = round(($kpi['passed_count'] / $kpi['graded_students']) * 100, 1);
        }
        $stmtPF->free();
    }

    $stmtAtt = $mysqli->prepare("
        SELECT a.status, COUNT(*) AS c FROM attendance a
        WHERE a.teacher_subject_id IN ({$tsIdsList}) AND a.attendance_date BETWEEN ? AND ?
        GROUP BY a.status
    ");
    if ($stmtAtt) {
        $onTime = 0; $total = 0;
        $stmtAtt->bind_param('ss', $d7ago, $todaySql);
        $stmtAtt->execute();
        $res = $stmtAtt->get_result();
        while ($r = $res->fetch_assoc()) {
            $c = (int)$r['c'];
            $total += $c;
            if (in_array($r['status'], ['Present','Late','Excused'], true)) $onTime += $c;
        }
        $stmtAtt->close();
        $kpi['att7_on_time'] = $onTime;
        $kpi['att7_total'] = $total;
        if ($total > 0) $kpi['attendance_7d'] = round(($onTime / $total) * 100, 1);
    }
}

if ($advisorySection) {
    $kpi['advisory_students'] = (int)agg_one($mysqli, "
        SELECT COUNT(*) FROM students s
        JOIN sections sec ON s.section_id = sec.id
        WHERE sec.section_name = ?
    ", 's', $advisorySection['section_name']);
}

$passPerClass = [];
if ($tsIds) {
    $resPPC = $mysqli->query("
        SELECT
            ts.id,
            CONCAT(subj.subject_code, ' · ', sec.section_name) AS label,
            subj.subject_code AS code,
            sec.section_name AS sec,
            COUNT(DISTINCT g.student_id) AS graded,
            SUM(CASE WHEN g.grade >= 75 THEN 1 ELSE 0 END) AS pass_rows,
            SUM(CASE WHEN g.grade <  75 THEN 1 ELSE 0 END) AS fail_rows,
            ROUND(AVG(g.grade), 2) AS avg_g
        FROM teacher_subjects ts
        JOIN subjects subj ON ts.subject_id = subj.id
        JOIN sections sec ON ts.section_id = sec.id
        JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
        LEFT JOIN grades g ON g.teacher_subject_id = ts.id
        WHERE ts.teacher_id = {$tid}
        GROUP BY ts.id
        ORDER BY avg_g DESC
    ");
    if ($resPPC) { while ($r = $resPPC->fetch_assoc()) $passPerClass[] = $r; $resPPC->free(); }
}

$att7Daily = [];
if ($tsIds) {
    $resA7 = $mysqli->query("
        SELECT DATE(a.attendance_date) AS d,
            SUM(CASE WHEN a.status='Present' THEN 1 ELSE 0 END) AS p,
            SUM(CASE WHEN a.status='Absent'  THEN 1 ELSE 0 END) AS a,
            SUM(CASE WHEN a.status='Late'    THEN 1 ELSE 0 END) AS l,
            SUM(CASE WHEN a.status='Excused' THEN 1 ELSE 0 END) AS e
        FROM attendance a
        WHERE a.teacher_subject_id IN ({$tsIdsList}) AND a.attendance_date BETWEEN '{$d7ago}' AND '{$todaySql}'
        GROUP BY DATE(a.attendance_date) ORDER BY d ASC
    ");
    if ($resA7) { while ($r = $resA7->fetch_assoc()) $att7Daily[] = $r; $resA7->free(); }
}

$toJson = static function($v): string {
    $j = json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS);
    return $j === false ? '[]' : $j;
};
$ppcJson    = $toJson($passPerClass);
$att7Json   = $toJson($att7Daily);
$pfJson     = $toJson(['passed'=>$kpi['passed_count'],'failed'=>$kpi['failed_count']]);

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'active' => true, 'icon' => 'dashboard'],
    ['label' => 'My Classes', 'href' => 'classes.php', 'icon' => 'classes'],
    ['label' => 'Attendance', 'href' => 'attendance.php', 'icon' => 'attendance'],
    ['label' => 'Grades', 'href' => 'grades.php', 'icon' => 'grade_book'],
    ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
];
start_layout('Teacher Dashboard - AIMS', 'AIMS - Teacher', $_SESSION['username'] ?? '', $sidebar);
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
        <h1 class="h4 mb-0">Teacher Dashboard</h1>
        <small class="text-muted">
            Welcome back, <strong><?php echo htmlspecialchars(($teacherInfo['last_name'] ?? '') . ', ' . ($teacherInfo['first_name'] ?? 'Teacher')); ?></strong>
            <?php if ($advisorySection): ?>
                · Adviser of <strong><?php echo htmlspecialchars($advisorySection['section_name']); ?></strong>
            <?php endif; ?>
        </small>
    </div>
    <small class="text-muted"><?php echo date('l, F d, Y'); ?></small>
</div>

<div class="kpi-grid mb-2">
    <div class="kpi-card kpi-primary">
        <div class="kpi-label">Class Loads</div>
        <div class="kpi-value"><?php echo $kpi['my_subjects']; ?></div>
        <div class="kpi-foot">Across <strong><?php echo $kpi['my_sections']; ?></strong> sections</div>
    </div>
    <div class="kpi-card kpi-warning">
        <div class="kpi-label">Periods / Week</div>
        <div class="kpi-value"><?php echo $kpi['periods_week']; ?></div>
        <div class="kpi-foot">Scheduled teaching periods</div>
    </div>
    <div class="kpi-card kpi-info">
        <div class="kpi-label">Students</div>
        <div class="kpi-value"><?php echo $kpi['students_taught']; ?></div>
        <div class="kpi-foot"><?php if($kpi['advisory_students']>0){echo '<strong>'.$kpi['advisory_students'].'</strong> advisees';}else{echo 'Total enrolled';} ?></div>
    </div>
    <div class="kpi-card kpi-success">
        <div class="kpi-label">Graded</div>
        <div class="kpi-value"><?php echo $kpi['graded_students']; ?></div>
        <div class="kpi-foot">Students with grades encoded</div>
    </div>
    <div class="kpi-card kpi-secondary">
        <div class="kpi-label">Attendance 7d</div>
        <div class="kpi-value"><?php echo $kpi['attendance_7d']; ?>%</div>
        <div class="kpi-foot"><strong><?php echo $kpi['att7_on_time']; ?></strong>/<?php echo $kpi['att7_total']; ?> records</div>
    </div>
    <div class="kpi-card kpi-danger">
        <div class="kpi-label">Pass Rate</div>
        <div class="kpi-value"><?php echo $kpi['pass_rate']; ?>%</div>
        <div class="kpi-foot"><strong class="text-success"><?php echo $kpi['passed_count']; ?></strong> pass · <strong><?php echo $kpi['failed_count']; ?></strong> below 75</div>
    </div>
</div>

<div class="analytics-grid mb-2">
    <div class="ins-card" id="det-ppc" data-default-open="true">
            <button type="button" class="ins-summary" aria-expanded="true" aria-controls="body-ppc" id="btn-ppc">
                <span class="ins-title">
                    <h6>My Class Pass Rates</h6>
                    <small>Average grade + pass% label on every bar</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['pass_rate']; ?>% pass</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-ppc" role="region" aria-labelledby="btn-ppc">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:240px"><canvas id="ppcChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

    <div class="ins-card" id="det-att" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-att" id="btn-att">
                <span class="ins-title">
                    <h6>Attendance — Last 7 days (My classes)</h6>
                    <small>Count labeled on each stacked slice</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['attendance_7d']; ?>%</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-att" role="region" aria-labelledby="btn-att">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:240px"><canvas id="attChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
</div>

<div class="analytics-grid mb-2">
    <div class="ins-card" id="det-pf" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-pf" id="btn-pf">
                <span class="ins-title">
                    <h6>Student Pass / Fail (My Classes)</h6>
                    <small>Count + % directly on each slice</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['pass_rate']; ?>% passing</span>
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

    <div class="ins-card" id="det-calendar" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-calendar" id="btn-calendar">
                <span class="ins-title">
                    <h6>This Month</h6>
                    <small>Calendar view</small>
                </span>
                <span class="ins-right">
                    
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-calendar" role="region" aria-labelledby="btn-calendar">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div id="rt-calendar"></div>
                    </div>
                </div>
            </div>
        </div>
</div>

<div class="analytics-grid">
    <div class="ins-card" id="det-schedule" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-schedule" id="btn-schedule">
                <span class="ins-title">
                    <h6>Weekly Schedule</h6>
                    <small><?php echo count($schedules); ?> period entries</small>
                </span>
                <span class="ins-right">
                    
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-schedule" role="region" aria-labelledby="btn-schedule">
                <div class="ins-body p-0">
                    <div class="ins-body-inner">
                        <div class="table-responsive">
                <table class="table table-sm table-striped mb-0" style="font-size:0.8rem">
                    <thead>
                        <tr><th>Day</th><th>Time</th><th>Subject</th><th>Section</th><th>Room</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($schedules)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No schedule yet.</td></tr>
                        <?php else: foreach ($schedules as $sch): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($sch['day_of_week']); ?></td>
                                <td><?php echo date('h:i A', strtotime($sch['start_time'])) . '–' . date('h:i A', strtotime($sch['end_time'])); ?></td>
                                <td><?php echo htmlspecialchars($sch['subject_code']); ?></td>
                                <td><?php echo htmlspecialchars($sch['section_name']); ?></td>
                                <td><?php echo htmlspecialchars($sch['room'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <a href="attendance.php" class="btn btn-sm btn-outline-primary m-3">Take Attendance →</a>
                    </div>
                </div>
            </div>
        </div>

    <div class="ins-card" id="det-loads" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-loads" id="btn-loads">
                <span class="ins-title">
                    <h6>My Class Loads</h6>
                    <small><?php echo count($classLoads); ?> subject-section assignments</small>
                </span>
                <span class="ins-right">
                    
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-loads" role="region" aria-labelledby="btn-loads">
                <div class="ins-body p-0">
                    <div class="ins-body-inner">
                        <div class="table-responsive">
                <table class="table table-sm table-striped mb-0" style="font-size:0.8rem">
                    <thead><tr><th>SY</th><th>Code</th><th>Subject</th><th>Section</th><th>Lvl</th></tr></thead>
                    <tbody>
                        <?php if (empty($classLoads)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No loads assigned.</td></tr>
                        <?php else: foreach ($classLoads as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['school_year'] ?? ''); ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($row['subject_code'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['subject_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['section_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['grade_level'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <a href="grades.php" class="btn btn-sm btn-outline-primary m-3">Encode Grades →</a>
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
      anchor:'end', align:'top', offset: 2,
      font:{ weight:700, size:10 }, color:'#1f2937',
      formatter:function(v){
        if (v===null||v===undefined||v===0) return '';
        return Number.isInteger(v) ? String(v) : Number(v).toFixed(1);
      }
    });
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
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
    var el = document.getElementById('rt-calendar'); if (!el) return;
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

  var ppcRaw = JSON.parse('<?php echo $ppcJson; ?>');
  lazyChart('det-ppc', function(){
  if (window.Chart && document.getElementById('ppcChart')) {
    var empty = ppcRaw.length === 0;
    new Chart(document.getElementById('ppcChart'), {
      type:'bar',
      data:{
        labels: empty ? ['(No class data yet)'] : ppcRaw.map(r => r.code + ' · ' + r.sec),
        datasets:[{
          label:'Avg Grade',
          data: empty ? [0] : ppcRaw.map(r => Number(r.avg_g||0)),
          backgroundColor: empty ? ['#cbd5e1']
            : ppcRaw.map(r => (Number(r.avg_g||0) >= 75 ? '#22c55e' : '#ef4444')),
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
                var r = ppcRaw[i.dataIndex];
                return [
                  'Avg grade: ' + Number(i.parsed.x).toFixed(2),
                  'Passing entries: ' + ((r.pass_rows)|0),
                  'Failing entries: ' + ((r.fail_rows)|0),
                  'Students graded: ' + ((r.graded)|0)
                ];
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
          x:{min:60, max:100, ticks:{stepSize:5, font:{size:10}}, title:{display:true, text:'Avg grade', font:{size:10}}},
          y:{grid:{display:false}, ticks:{font:{size:9}}}
        }
      },
      plugins:[{
        id:'passLine',
        afterDraw: function(chart){
          var x = chart.scales['x'].getPixelForValue(75);
          var c = chart.ctx; c.save();
          c.beginPath(); c.moveTo(x, chart.chartArea.top); c.lineTo(x, chart.chartArea.bottom);
          c.strokeStyle = 'rgba(239,68,68,.7)'; c.setLineDash([5,3]); c.lineWidth = 1.5; c.stroke();
          c.fillStyle='rgba(239,68,68,.9)'; c.font='bold 9px Inter';
          c.fillText('75', x + 3, chart.chartArea.top + 10);
          c.restore();
        }
      }]
    });
  }
  });

  var attRaw = JSON.parse('<?php echo $att7Json; ?>');
  lazyChart('det-att', function(){
  if (window.Chart && document.getElementById('attChart')) {
    var labels = attRaw.map(function(r){
      var d = new Date(r.d + 'T00:00:00');
      return d.toLocaleDateString(undefined,{weekday:'short', day:'numeric'});
    });
    new Chart(document.getElementById('attChart'), {
      type:'bar',
      data:{
        labels: labels.length ? labels : ['(No attendance yet)'],
        datasets:[
          { label:'Present', data:attRaw.map(r=>r.p), backgroundColor:'#22c55e', stack:'s' },
          { label:'Late',    data:attRaw.map(r=>r.l), backgroundColor:'#f59e0b', stack:'s' },
          { label:'Excused', data:attRaw.map(r=>r.e), backgroundColor:'#06b6d4', stack:'s' },
          { label:'Absent',  data:attRaw.map(r=>r.a), backgroundColor:'#ef4444', stack:'s' }
        ]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{position:'bottom', labels:{boxWidth:10, font:{size:10}}},
          tooltip:{
            callbacks:{
              footer: function(items){
                var t = items.reduce((a,i)=>a + Number(i.parsed.y||0), 0);
                return 'Day total: ' + t;
              }
            }
          },
          datalabels:{
            formatter: v => v>0 ? String(v) : '',
            color:'#fff', textStrokeColor:'rgba(0,0,0,.18)', textStrokeWidth:2,
            font:{weight:700, size:10}
          }
        },
        scales:{
          x:{stacked:true, grid:{display:false}, ticks:{font:{size:10}}},
          y:{stacked:true, beginAtZero:true, ticks:{precision:0, font:{size:10}}, title:{display:true, text:'Count', font:{size:10}}}
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
            formatter: v => {
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
