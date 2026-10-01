<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('student');
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

$studentInfo = null;
$schedules = [];
$studentId = 0;
$userId = 0;

if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    $stmt = $mysqli->prepare(
        'SELECT s.id, s.student_number, s.first_name, s.last_name, s.grade_level, s.section_id, sec.section_name 
         FROM students s 
         LEFT JOIN sections sec ON s.section_id = sec.id 
         WHERE s.user_id = ? 
         LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $studentInfo = $result->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($studentInfo) {
        $studentId = (int)$studentInfo['id'];
        if (!empty($studentInfo['section_id'])) {
            $secId = (int)$studentInfo['section_id'];
            $schedStmt = $mysqli->prepare(
                "SELECT sch.day_of_week, sch.start_time, sch.end_time, sch.room,
                        subj.subject_code, subj.subject_name, 
                        t.first_name AS teacher_fname, t.last_name AS teacher_lname
                 FROM schedules sch
                 JOIN teacher_subjects ts ON sch.teacher_subject_id = ts.id
                 JOIN subjects subj ON ts.subject_id = subj.id
                 JOIN teachers t ON ts.teacher_id = t.id
                 WHERE ts.section_id = ?
                 ORDER BY FIELD(sch.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), sch.start_time"
            );
            if ($schedStmt) {
                $schedStmt->bind_param('i', $secId);
                $schedStmt->execute();
                $res = $schedStmt->get_result();
                while ($row = $res->fetch_assoc()) $schedules[] = $row;
                $schedStmt->close();
            }
        }
    }
}

$kpi = [
    'gwa' => 0.0,
    'subjects' => 0,
    'graded_subjects' => 0,
    'passing' => 0,
    'failing' => 0,
    'attendance_7d' => 0.0,
    'att7_present' => 0,
    'att7_total' => 0,
    'attendance_30d' => 0.0,
    'units' => count($schedules),
];

if ($studentId) {
    $stmtGW = $mysqli->query("
        SELECT
            ROUND(AVG(g.grade), 2) AS gwa,
            COUNT(DISTINCT g.teacher_subject_id) AS graded,
            SUM(CASE WHEN g.grade >= 75 THEN 1 ELSE 0 END) AS passing,
            SUM(CASE WHEN g.grade <  75 THEN 1 ELSE 0 END) AS failing
        FROM grades g
        JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
        JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
        WHERE g.student_id = {$studentId}
    ");
    if ($stmtGW) {
        $r = $stmtGW->fetch_assoc();
        $kpi['gwa']              = (float)($r['gwa'] ?? 0.0);
        $kpi['graded_subjects']  = (int)($r['graded'] ?? 0);
        $kpi['passing']          = (int)($r['passing'] ?? 0);
        $kpi['failing']          = (int)($r['failing'] ?? 0);
        $stmtGW->free();
    }

    $kpi['subjects'] = (int)agg_one($mysqli, "
        SELECT COUNT(DISTINCT ts.subject_id)
        FROM teacher_subjects ts
        JOIN enrollments e ON ts.section_id = e.section_id AND e.status='enrolled'
        JOIN school_years sy ON e.school_year_id = sy.id AND sy.is_current = 1
        WHERE e.student_id = {$studentId}
    ");
    if ($kpi['subjects'] === 0 && !empty($studentInfo['section_id'])) {
        $kpi['subjects'] = (int)agg_one($mysqli, "
            SELECT COUNT(DISTINCT ts.subject_id) FROM teacher_subjects ts
            WHERE ts.section_id = " . (int)$studentInfo['section_id']
        );
    }
    if ($kpi['subjects'] === 0) {
        $seen = [];
        foreach ($schedules as $s) { $seen[$s['subject_code']] = true; }
        $kpi['subjects'] = count($seen);
    }

    function attRateStu(mysqli $db, int $sid, string $from, string $to, &$pRef, &$tRef): float {
        $present = 0; $total = 0;
        $res = $db->query("
            SELECT status, COUNT(*) AS c FROM attendance
            WHERE student_id = {$sid} AND attendance_date BETWEEN '{$from}' AND '{$to}'
            GROUP BY status
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $c = (int)$r['c'];
                $total += $c;
                if (in_array($r['status'], ['Present','Late','Excused'], true)) $present += $c;
            }
            $res->free();
        }
        $pRef = $present; $tRef = $total;
        return $total > 0 ? round(($present / $total) * 100, 1) : 0.0;
    }
    $kpi['attendance_7d']  = attRateStu($mysqli, $studentId, $d7ago,  $todaySql, $kpi['att7_present'], $kpi['att7_total']);
    $_tmp_a = 0; $_tmp_b = 0;
    $kpi['attendance_30d'] = attRateStu($mysqli, $studentId, $d30ago, $todaySql, $_tmp_a, $_tmp_b);
}

$gradesPerSubj = [];
if ($studentId) {
    $resGPS = $mysqli->query("
        SELECT
            sub.subject_code AS code,
            sub.subject_name AS subj,
            ROUND(AVG(g.grade), 2) AS avg_g,
            COUNT(g.id) AS cnt,
            MAX(g.grade) AS max_g,
            MIN(g.grade) AS min_g
        FROM grades g
        JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
        JOIN school_years sy ON ts.school_year_id = sy.id AND sy.is_current = 1
        JOIN subjects sub ON ts.subject_id = sub.id
        WHERE g.student_id = {$studentId}
        GROUP BY sub.id
        ORDER BY avg_g DESC
    ");
    if ($resGPS) { while ($r = $resGPS->fetch_assoc()) $gradesPerSubj[] = $r; $resGPS->free(); }
}

$att7Daily = [];
if ($studentId) {
    $resA7 = $mysqli->query("
        SELECT DATE(attendance_date) AS d,
            SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) AS p,
            SUM(CASE WHEN status='Absent'  THEN 1 ELSE 0 END) AS a,
            SUM(CASE WHEN status='Late'    THEN 1 ELSE 0 END) AS l,
            SUM(CASE WHEN status='Excused' THEN 1 ELSE 0 END) AS e
        FROM attendance
        WHERE student_id = {$studentId} AND attendance_date BETWEEN '{$d7ago}' AND '{$todaySql}'
        GROUP BY DATE(attendance_date) ORDER BY d ASC
    ");
    if ($resA7) { while ($r = $resA7->fetch_assoc()) $att7Daily[] = $r; $resA7->free(); }
}

$announcements = [];
$stmtAnn = $mysqli->prepare(
    "SELECT title, message, created_at FROM announcements 
     WHERE (target_role='all' OR target_role='students') 
       AND (expires_at IS NULL OR expires_at >= NOW())
     ORDER BY created_at DESC
     LIMIT 5"
);
if ($stmtAnn) {
    $stmtAnn->execute();
    $resAnn = $stmtAnn->get_result();
    while ($row = $resAnn->fetch_assoc()) $announcements[] = $row;
    $stmtAnn->close();
}

$toJson = static function($v): string {
    $j = json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS);
    return $j === false ? '[]' : $j;
};
$gpsJson    = $toJson($gradesPerSubj);
$att7Json   = $toJson($att7Daily);
$pfJson     = $toJson(['passing'=>$kpi['passing'],'failing'=>$kpi['failing']]);

$sidebar = [
    ['label' => 'Dashboard',      'href' => 'dashboard.php',  'active' => true, 'icon' => 'dashboard'],
    ['label' => 'My Grades',      'href' => 'grades.php',     'icon' => 'grades'],
    ['label' => 'My Schedule',    'href' => 'classes.php',    'icon' => 'classes'],
    ['label' => 'My Attendance',  'href' => 'attendance.php', 'icon' => 'attendance'],
];
start_layout('Student Dashboard - AIMS', 'AIMS - Student', $_SESSION['username'] ?? '', $sidebar);
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
        <h1 class="h4 mb-0">Student Dashboard</h1>
        <small class="text-muted">
            Welcome back, <strong><?php echo htmlspecialchars(($studentInfo['last_name'] ?? '') . ', ' . ($studentInfo['first_name'] ?? 'Student')); ?></strong>
            · <strong><?php echo htmlspecialchars($studentInfo['grade_level'] ?? '—'); ?></strong>
            · Section <strong><?php echo htmlspecialchars($studentInfo['section_name'] ?? '—'); ?></strong>
        </small>
    </div>
    <small class="text-muted"><?php echo date('l, F d, Y'); ?></small>
</div>

<div class="kpi-grid mb-2">
    <div class="kpi-card kpi-success">
        <div class="kpi-label">General Average</div>
        <div class="kpi-value"><?php echo $kpi['gwa'] > 0 ? number_format($kpi['gwa'], 2) : '—'; ?></div>
        <div class="kpi-foot">/ 100 · <?php echo $kpi['gwa']>=75 ? '<strong class="text-white">Passing</strong>' : '<strong>Below passing</strong>'; ?></div>
    </div>
    <div class="kpi-card kpi-primary">
        <div class="kpi-label">Subjects</div>
        <div class="kpi-value"><?php echo $kpi['subjects']; ?></div>
        <div class="kpi-foot"><?php echo $kpi['graded_subjects']; ?> with grades · <?php echo $kpi['units']; ?> weekly slots</div>
    </div>
    <div class="kpi-card kpi-warning">
        <div class="kpi-label">Grade Status</div>
        <div class="kpi-value"><?php echo $kpi['passing']; ?> / <?php echo $kpi['passing']+$kpi['failing'] ? : 0; ?></div>
        <div class="kpi-foot"><strong class="text-success"><?php echo $kpi['passing']; ?></strong> pass · <strong><?php echo $kpi['failing']; ?></strong> below</div>
    </div>
    <div class="kpi-card kpi-info">
        <div class="kpi-label">Attendance 7d</div>
        <div class="kpi-value"><?php echo $kpi['attendance_7d']; ?>%</div>
        <div class="kpi-foot"><strong><?php echo $kpi['att7_present']; ?></strong>/<?php echo $kpi['att7_total']; ?> on-time</div>
    </div>
    <div class="kpi-card kpi-secondary">
        <div class="kpi-label">Attendance 30d</div>
        <div class="kpi-value"><?php echo $kpi['attendance_30d']; ?>%</div>
        <div class="kpi-foot">Term performance</div>
    </div>
    <div class="kpi-card kpi-danger">
        <div class="kpi-label">Student No.</div>
        <div class="kpi-value" style="font-size:1.3rem"><?php echo htmlspecialchars($studentInfo['student_number'] ?? '—'); ?></div>
        <div class="kpi-foot">ID on file</div>
    </div>
</div>

<div class="analytics-grid mb-2">
    <div class="ins-card" id="det-grades" data-default-open="true">
            <button type="button" class="ins-summary" aria-expanded="true" aria-controls="body-grades" id="btn-grades">
                <span class="ins-title">
                    <h6>My Grades — Per Subject Average</h6>
                    <small>Numerical grade on each bar; 75 is the DepEd passing line</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['gwa'] > 0 ? number_format($kpi['gwa'], 2) : '—'; ?> GWA</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-grades" role="region" aria-labelledby="btn-grades">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:260px"><canvas id="gradeChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

    <div class="ins-card" id="det-att" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-att" id="btn-att">
                <span class="ins-title">
                    <h6>My Attendance — Last 7 days</h6>
                    <small>Daily status; values shown on each slice</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['attendance_7d']; ?>%</span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-att" role="region" aria-labelledby="btn-att">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:260px"><canvas id="attChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
</div>

<div class="analytics-grid mb-2">
    <div class="ins-card" id="det-pf" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-pf" id="btn-pf">
                <span class="ins-title">
                    <h6>My Grade Entries — Pass vs Fail</h6>
                    <small>Count and % printed on each slice</small>
                </span>
                <span class="ins-right">
                    <span class="ins-stat"><?php echo $kpi['passing']; ?> / <?php echo $kpi['passing']+$kpi['failing']; ?></span>
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-pf" role="region" aria-labelledby="btn-pf">
                <div class="ins-body">
                    <div class="ins-body-inner">
                        <div class="chart-container" style="height:220px"><canvas id="pfChart"></canvas></div>
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
                        <div id="dashboard-calendar"></div>
                    </div>
                </div>
            </div>
        </div>
</div>

<div class="analytics-grid">
    <div class="ins-card" id="det-announcements" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-announcements" id="btn-announcements">
                <span class="ins-title">
                    <h6>Announcements</h6>
                    <small><?php echo count($announcements); ?> latest for students</small>
                </span>
                <span class="ins-right">
                    
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-announcements" role="region" aria-labelledby="btn-announcements">
                <div class="ins-body p-0">
                    <div class="ins-body-inner">
                        <?php if (count($announcements) === 0): ?>
                <p class="text-muted small py-3 px-3 mb-0">No announcements at the moment.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush" style="font-size:0.8rem">
                    <?php foreach ($announcements as $a): ?>
                        <li class="list-group-item py-2 px-3">
                            <div class="d-flex justify-content-between">
                                <strong><?php echo htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small class="text-muted"><?php echo htmlspecialchars(date('M d', strtotime($a['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <div class="mt-1 small text-muted"><?php echo nl2br(htmlspecialchars(mb_strimwidth($a['message'],0,140,'...'), ENT_QUOTES, 'UTF-8')); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <div class="ins-card" id="det-schedule" data-default-open="false">
            <button type="button" class="ins-summary" aria-expanded="false" aria-controls="body-schedule" id="btn-schedule">
                <span class="ins-title">
                    <h6>Weekly Schedule</h6>
                    <small><?php echo count($schedules); ?> class entries</small>
                </span>
                <span class="ins-right">
                    
                    <svg class="ins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </span>
            </button>
            <div class="ins-body-wrap" id="body-schedule" role="region" aria-labelledby="btn-schedule">
                <div class="ins-body p-0">
                    <div class="ins-body-inner">
                        <div class="table-responsive">
                <table class="table table-sm table-striped mb-0" style="font-size:0.75rem">
                    <thead>
                        <tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($schedules)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No schedule available.</td></tr>
                        <?php else: foreach ($schedules as $sch): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($sch['day_of_week']); ?></td>
                                <td><?php echo date('h:i A', strtotime($sch['start_time'])) . '–' . date('h:i A', strtotime($sch['end_time'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($sch['subject_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($sch['teacher_lname'] . ', ' . mb_substr($sch['teacher_fname'],0,1) . '.'); ?></td>
                                <td><?php echo htmlspecialchars($sch['room'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <a href="classes.php" class="btn btn-sm btn-outline-primary m-3">Full View →</a>
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
    var el = document.getElementById('dashboard-calendar'); if (!el) return;
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
    var d=1;
    for (; d<=days; d++){
      var c = (d===now.getDate()) ? ' class="text-white rounded-circle" style="background:var(--accent);width:22px;height:22px;display:inline-block;line-height:22px;font-weight:700"' : '';
      html += '<td><span'+c+'>'+d+'</span></td>';
      if (((d+first)%7)===0 && d!==days) html += '</tr><tr>';
    }
    var rem=(first+days)%7; if(rem!==0){for(var r=rem;r<7;r++)html+='<td></td>';}
    html += '</tr></tbody></table>';
    var head = '<div class="d-flex justify-content-between align-items-center mb-1"><strong class="small">'+now.toLocaleString(undefined,{month:'long'})+' '+year+'</strong></div>';
    el.innerHTML = head + html;
  })();

  var gpsRaw = JSON.parse('<?php echo $gpsJson; ?>');
  lazyChart('det-grades', function(){
  if (window.Chart && document.getElementById('gradeChart')) {
    var empty = gpsRaw.length === 0;
    new Chart(document.getElementById('gradeChart'), {
      type:'bar',
      data:{
        labels: empty ? ['(No grades yet)'] : gpsRaw.map(r => r.code),
        datasets:[{
          label:'Avg Grade',
          data: empty ? [0] : gpsRaw.map(r => Number(r.avg_g||0)),
          backgroundColor: empty ? ['#cbd5e1']
            : gpsRaw.map(r => (Number(r.avg_g||0)>=75?'#22c55e':'#ef4444')),
          borderRadius:6, borderSkipped:false
        }]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{display:false},
          tooltip:{
            callbacks:{
              afterTitle: items => {
                if (empty) return '';
                var r = gpsRaw[items[0].dataIndex];
                return r.subj + '  ·  ' + (r.cnt|0) + ' grade entries  ·  hi: ' + (r.max_g|0) + ' / lo: ' + (r.min_g|0);
              },
              label: i => {
                if (empty) return 'Ask teacher to encode your grades to populate.';
                var n = Number(i.parsed.x);
                return 'Average: ' + n.toFixed(2) + ' / 100' + (n>=75?'  (Passing)':'  (Below 75)');
              }
            }
          },
          datalabels:{
            anchor:'end', align:'right',
            formatter: v => v>0 ? Number(v).toFixed(2) : '',
            color:'#0f172a', font:{weight:700, size:11}
          }
        },
        scales:{
          x:{min:60, max:100, ticks:{stepSize:5, font:{size:10}}, title:{display:true, text:'Average grade (75 = pass)', font:{size:10}}},
          y:{grid:{display:false}, ticks:{font:{size:10}}}
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
    var labels = attRaw.length ? attRaw.map(function(r){
      var d = new Date(r.d + 'T00:00:00');
      return d.toLocaleDateString(undefined,{weekday:'short', day:'numeric'});
    }) : ['(No attendance yet)'];
    new Chart(document.getElementById('attChart'), {
      type:'bar',
      data:{
        labels: labels,
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
              footer: items => {
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
          y:{stacked:true, beginAtZero:true, ticks:{precision:0, font:{size:10}}, title:{display:true, text:'Days marked', font:{size:10}}}
        }
      }
    });
  }
  });

  var pf = JSON.parse('<?php echo $pfJson; ?>');
  lazyChart('det-pf', function(){
  if (window.Chart && document.getElementById('pfChart')) {
    var pass = Number(pf.passing)|0, fail = Number(pf.failing)|0, total = pass + fail;
    new Chart(document.getElementById('pfChart'), {
      type:'doughnut',
      data:{
        labels:['Passing (≥75)','Failing (<75)'],
        datasets:[{
          data:[pass, fail],
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
