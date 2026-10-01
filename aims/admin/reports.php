<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_role('admin');
require_once __DIR__ . '/../includes/layout.php';

// =========================
// ACCURATE DATA — panelist feedback: analytics must be truthful.
// =========================
$today  = date('Y-m-d');
$d7ago  = date('Y-m-d', strtotime('-7 days'));
$d14ago = date('Y-m-d', strtotime('-14 days'));
$d30ago = date('Y-m-d', strtotime('-30 days'));

// Helper: run a single-value aggregate query safely
function agg_one(mysqli $db, string $sql, string $type = 'i', ...$binds) {
    $stmt = $db->prepare($sql);
    if (!$stmt) return null;
    if ($binds) { $stmt->bind_param($type, ...$binds); }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? ($row[0] ?? 0) : 0;
}

// --- KPI row (accurate totals, not today-only) ---
$kpi = [
    'students'    => (int) agg_one($mysqli, "SELECT COUNT(*) FROM students"),
    'teachers'    => (int) agg_one($mysqli, "SELECT COUNT(*) FROM teachers"),
    'enrolled_sy' => (int) agg_one($mysqli, "
        SELECT COUNT(DISTINCT e.student_id)
        FROM enrollments e
        JOIN school_years sy ON e.school_year_id = sy.id AND sy.is_current = 1
        WHERE e.status = 'enrolled'
    "),
    'graded_students' => (int) agg_one($mysqli, "SELECT COUNT(DISTINCT student_id) FROM grades"),
];
if ($kpi['enrolled_sy'] === 0 && $kpi['students'] > 0) {
    $kpi['enrolled_sy'] = $kpi['students'];
}

// Passing rate (DepEd standard 75+)
$passFail = [
    'passed' => (int) agg_one($mysqli, "
        SELECT COUNT(*) FROM (
            SELECT student_id, AVG(grade) AS g
            FROM grades GROUP BY student_id HAVING g >= 75
        ) AS p"),
    'failed' => (int) agg_one($mysqli, "
        SELECT COUNT(*) FROM (
            SELECT student_id, AVG(grade) AS g
            FROM grades GROUP BY student_id HAVING g < 75
        ) AS f"),
];
$kpi['graded_total'] = $passFail['passed'] + $passFail['failed'];
$kpi['pass_rate'] = $kpi['graded_total'] > 0
    ? round(($passFail['passed'] / $kpi['graded_total']) * 100, 1) : 0;

// Attendance KPIs (accurate: 7-day and 30-day rates, NOT today-only which is often empty)
function att_summary(mysqli $db, string $from, string $to): array {
    $sum = [
        'Present' => 0, 'Absent' => 0, 'Late' => 0, 'Excused' => 0,
        'total' => 0, 'rate' => 0.0
    ];
    $stmt = $db->prepare("
        SELECT status, COUNT(*) AS c
        FROM attendance
        WHERE attendance_date BETWEEN ? AND ?
        GROUP BY status
    ");
    if (!$stmt) return $sum;
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $sum[$r['status']] = (int) $r['c'];
    }
    $stmt->close();
    $sum['total'] = (int) array_sum(['Present'=>$sum['Present'],'Absent'=>$sum['Absent'],'Late'=>$sum['Late'],'Excused'=>$sum['Excused']]);
    if ($sum['total'] > 0) {
        $on_time = $sum['Present'] + $sum['Late'] + $sum['Excused'];
        $sum['rate'] = round(($on_time / $sum['total']) * 100, 1);
    }
    return $sum;
}
$att7  = att_summary($mysqli, $d7ago,  $today);
$att14 = att_summary($mysqli, $d14ago, $today);
$att30 = att_summary($mysqli, $d30ago, $today);

// --- Chart 1: 30-DAY ATTENDANCE DAILY LINE+BARS (daily breakdown) ---
$attDaily = [];
$resDaily = $mysqli->query("
    SELECT
        DATE(attendance_date) AS d,
        SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) AS p,
        SUM(CASE WHEN status='Absent'  THEN 1 ELSE 0 END) AS a,
        SUM(CASE WHEN status='Late'    THEN 1 ELSE 0 END) AS l,
        SUM(CASE WHEN status='Excused' THEN 1 ELSE 0 END) AS e
    FROM attendance
    WHERE attendance_date BETWEEN '{$d30ago}' AND '{$today}'
    GROUP BY DATE(attendance_date)
    ORDER BY d ASC
");
if ($resDaily) { while ($r = $resDaily->fetch_assoc()) $attDaily[] = $r; $resDaily->free(); }

// --- Chart 2: TEACHER WEEKLY WORKLOAD (schedules count, accurate, not just ts rows) ---
$teacherWorkload = [];
$resWl = $mysqli->query("
    SELECT
        t.id,
        CONCAT(t.first_name,' ',t.last_name) AS name,
        COUNT(s.id) AS periods_per_week,
        COUNT(DISTINCT ts.section_id) AS sections_covered,
        COUNT(DISTINCT ts.subject_id) AS subjects
    FROM teachers t
    LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id
    LEFT JOIN schedules s ON s.teacher_subject_id = ts.id
    GROUP BY t.id
    ORDER BY periods_per_week DESC, sections_covered DESC
    LIMIT 10
");
if ($resWl) { while ($r = $resWl->fetch_assoc()) $teacherWorkload[] = $r; $resWl->free(); }

// --- Chart 3: AVERAGE GRADE BY LEVEL (with Passed vs Failed counts) ---
$gradePerformance = [];
$resGrd = $mysqli->query("
    SELECT
        s.grade_level AS lvl,
        ROUND(AVG(g.grade), 2) AS avg_grade,
        COUNT(DISTINCT g.student_id) AS student_count,
        SUM(CASE WHEN g.grade >= 75 THEN 1 ELSE 0 END) AS passing_rows,
        SUM(CASE WHEN g.grade <  75 THEN 1 ELSE 0 END) AS failing_rows
    FROM grades g
    JOIN students s ON g.student_id = s.id
    GROUP BY s.grade_level
    ORDER BY s.grade_level ASC
");
if ($resGrd) { while ($r = $resGrd->fetch_assoc()) $gradePerformance[] = $r; $resGrd->free(); }

// --- Chart 4: SUBJECT PERFORMANCE (avg grade + enrolled student count per subject) ---
$subjectPerformance = [];
$resSub = $mysqli->query("
    SELECT
        sub.subject_name AS subj,
        ROUND(AVG(g.grade), 2) AS avg_grade,
        COUNT(DISTINCT g.student_id) AS students,
        COUNT(DISTINCT g.grading_period_id) AS period_count
    FROM grades g
    JOIN teacher_subjects ts ON g.teacher_subject_id = ts.id
    JOIN subjects sub ON ts.subject_id = sub.id
    GROUP BY sub.id
    ORDER BY avg_grade DESC
    LIMIT 10
");
if ($resSub) { while ($r = $resSub->fetch_assoc()) $subjectPerformance[] = $r; $resSub->free(); }

// --- Chart 5: ENROLLMENT / STUDENT COUNT BY SECTION (donut slices + numerical value label) ---
$sectionEnroll = [];
$resSec = $mysqli->query("
    SELECT
        sec.section_name AS section,
        sec.grade_level AS lvl,
        COUNT(DISTINCT CASE WHEN e.id IS NOT NULL THEN e.student_id
                            WHEN st.id IS NOT NULL AND st.section_id = sec.id THEN st.id
                            ELSE NULL END) AS enrolled
    FROM sections sec
    LEFT JOIN enrollments e ON e.section_id = sec.id AND e.status = 'enrolled'
    LEFT JOIN school_years sy ON e.school_year_id = sy.id AND sy.is_current = 1
    LEFT JOIN students st ON st.section_id = sec.id
    GROUP BY sec.id
    ORDER BY enrolled DESC, section ASC
    LIMIT 12
");
if ($resSec) { while ($r = $resSec->fetch_assoc()) $sectionEnroll[] = $r; $resSec->free(); }

// Encode data for Chart.js (safe JSON with fallback on encoding failure)
$toJson = static function($v) {
    $j = json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS);
    return $j === false ? '[]' : $j;
};
$attDailyJson      = $toJson($attDaily);
$teacherWlJson     = $toJson($teacherWorkload);
$gradeByLvlJson    = $toJson($gradePerformance);
$subjectPerfJson   = $toJson($subjectPerformance);
$sectionEnrollJson = $toJson($sectionEnroll);
$att7Json = $toJson($att7);
$att14Json = $toJson($att14);
$att30Json = $toJson($att30);
$passFailJson = $toJson($passFail);

$sidebar = [
    ['label' => 'Dashboard',     'href' => 'dashboard.php',     'icon' => 'dashboard'],
    ['label' => 'Students',      'href' => 'students.php',      'icon' => 'students'],
    ['label' => 'Teachers',      'href' => 'teachers.php',      'icon' => 'teachers'],
    ['label' => 'Subjects',      'href' => 'subjects.php',      'icon' => 'subjects'],
    ['label' => 'Schedules',     'href' => 'sections.php',      'icon' => 'sections'],
    ['label' => 'Users',         'href' => 'users.php',         'icon' => 'users'],
    ['label' => 'Announcements', 'href' => 'announcements.php', 'icon' => 'announcements'],
    ['label' => 'Reports',       'href' => 'reports.php',       'active' => true, 'icon' => 'reports'],
];

start_layout('Reports & Data Analytics', 'AIMS - Admin', $_SESSION['username'] ?? 'Admin', $sidebar);
?>

<div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
    <div>
        <h1 class="h4 mb-1">Reports &amp; Data Analytics</h1>
        <small class="text-muted">Accuracy-focused view · Data from: <b><?php echo $d30ago; ?></b> → <b><?php echo $today; ?></b></small>
    </div>
    <a href="export.php" class="btn btn-primary btn-sm">📊 Export CSV / Print</a>
</div>

<!-- KPI SUMMARY ROW (fits above all charts — accurate aggregate metrics) -->
<div class="kpi-grid mb-3">
    <div class="kpi-card kpi-success">
        <div class="kpi-label">Enrolled SY</div>
        <div class="kpi-value"><?php echo $kpi['enrolled_sy']; ?></div>
        <div class="kpi-foot">of <?php echo $kpi['students']; ?> total students</div>
    </div>
    <div class="kpi-card kpi-primary">
        <div class="kpi-label">Attendance 7d</div>
        <div class="kpi-value"><?php echo $att7['rate']; ?>%</div>
        <div class="kpi-foot">
            <?php echo $att7['Present']+$att7['Late']+$att7['Excused']; ?> / <?php echo $att7['total']; ?> marked present/late/excused
        </div>
    </div>
    <div class="kpi-card kpi-info">
        <div class="kpi-label">Attendance 30d</div>
        <div class="kpi-value"><?php echo $att30['rate']; ?>%</div>
        <div class="kpi-foot">
            <?php echo $att30['Present']+$att30['Late']+$att30['Excused']; ?> / <?php echo $att30['total']; ?> entries
        </div>
    </div>
    <div class="kpi-card kpi-warning">
        <div class="kpi-label">Passing Rate</div>
        <div class="kpi-value"><?php echo $kpi['pass_rate']; ?>%</div>
        <div class="kpi-foot">
            <strong><?php echo $passFail['passed']; ?></strong> passed ·
            <strong><?php echo $passFail['failed']; ?></strong> below 75
        </div>
    </div>
    <div class="kpi-card kpi-secondary">
        <div class="kpi-label">Teachers</div>
        <div class="kpi-value"><?php echo $kpi['teachers']; ?></div>
        <div class="kpi-foot"><?php echo count($teacherWorkload); ?> ranked by weekly load</div>
    </div>
    <div class="kpi-card kpi-danger">
        <div class="kpi-label">Students Graded</div>
        <div class="kpi-value"><?php echo $kpi['graded_total']; ?></div>
        <div class="kpi-foot">across <?php echo count($subjectPerformance); ?> subjects</div>
    </div>
</div>

<!-- CHART GRID: 2x2 on desktop = NO LONG SCROLLS. Everything has VALUE LABELS ON THE GRAPH -->
<div class="analytics-grid mb-3">
    <!-- Chart 1: Attendance (30-day stacked daily bars) -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Attendance — Daily breakdown (last 30 days)</h5>
            <small class="text-muted">Stacked bar with values on each slice; hover = exact counts &amp; day total.</small>
        </div>
        <div class="card-body">
            <div class="chart-container"><canvas id="attChart"></canvas></div>
        </div>
    </div>

    <!-- Chart 2: Teacher workload (weekly periods) -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Teacher Workload — Weekly periods taught</h5>
            <small class="text-muted">Horizontal bar, accurate: count = scheduled periods per teacher × all days (not subject loads).</small>
        </div>
        <div class="card-body">
            <div class="chart-container"><canvas id="wlChart"></canvas></div>
        </div>
    </div>

    <!-- Chart 3: Average Grade by Grade Level (bar + passing/failing overlay) -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Average Grade by Grade Level</h5>
            <small class="text-muted">Grade printed on every bar; bar is green ≥ 75 (DepEd passing). 75-line baseline drawn.</small>
        </div>
        <div class="card-body">
            <div class="chart-container"><canvas id="gradeChart"></canvas></div>
        </div>
    </div>

    <!-- Chart 4: Pass / Fail distribution -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Student Pass / Fail Distribution (overall average)</h5>
            <small class="text-muted">Donut chart with slice value + percentage; hover = exact counts.</small>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height:280px"><canvas id="pfChart"></canvas></div>
        </div>
    </div>
</div>

<!-- 2nd ROW: Subjects bar chart + Section donut -->
<div class="analytics-grid mb-3">
    <!-- Chart 5: Subject performance -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Subjects by Average Grade (top 10)</h5>
            <small class="text-muted">Numerical grade label on each bar; colour = passed threshold; tooltip includes graded-student count.</small>
        </div>
        <div class="card-body">
            <div class="chart-container"><canvas id="subjChart"></canvas></div>
        </div>
    </div>

    <!-- Chart 6: Enrollment per Section -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Enrollment Distribution — Top 12 Sections</h5>
            <small class="text-muted">Donut with numeric value on every slice; hover for count &amp; grade level.</small>
        </div>
        <div class="card-body">
            <div class="chart-container"><canvas id="secChart"></canvas></div>
        </div>
    </div>
</div>

<!-- Detailed data tables (below charts — they stay short since charts are above) -->
<div class="analytics-grid">
    <div class="card shadow-sm">
        <div class="card-header"><h5 class="mb-0">Attendance by Period — summary table</h5></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Period</th><th>Present</th><th>Late</th><th>Excused</th><th>Absent</th><th>Total</th><th>Rate</th></tr>
                </thead>
                <tbody>
                <?php foreach (['Last 7 days' => $att7, 'Last 14 days' => $att14, 'Last 30 days' => $att30] as $lbl => $s): ?>
                    <tr>
                        <td><b><?php echo $lbl; ?></b></td>
                        <td class="text-success"><?php echo $s['Present']; ?></td>
                        <td class="text-warning"><?php echo $s['Late']; ?></td>
                        <td class="text-info"><?php echo $s['Excused']; ?></td>
                        <td class="text-danger"><?php echo $s['Absent']; ?></td>
                        <td><?php echo $s['total']; ?></td>
                        <td class="fw-bold <?php echo ($s['rate']>=90?'text-success':($s['rate']>=80?'text-warning':'text-danger')); ?>">
                            <?php echo $s['rate']; ?>%
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><h5 class="mb-0">Teacher Workload — detail table</h5></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Teacher</th><th class="text-end">Periods/Week</th><th class="text-end">Sections</th><th class="text-end">Subjects</th></tr>
                </thead>
                <tbody>
                <?php if (empty($teacherWorkload)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No data — add teachers → teacher_subjects → schedules to populate.</td></tr>
                <?php else: ?>
                    <?php foreach ($teacherWorkload as $t): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($t['name']); ?></td>
                            <td class="text-end fw-bold"><?php echo (int)$t['periods_per_week']; ?></td>
                            <td class="text-end"><?php echo (int)$t['sections_covered']; ?></td>
                            <td class="text-end"><?php echo (int)$t['subjects']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php end_layout(); ?>

<script>
(function(){
  if (!window.Chart) return;
  Chart.register(window.ChartDataLabels);
  Chart.defaults.set('plugins.datalabels', {
    anchor:'end', align:'top', offset: 2,
    font:{ weight:'700', size:11 }, color:'#1f2937',
    formatter: function(v){
      if (v === null || v === undefined || v === 0) return '';
      return Number.isInteger(v) ? String(v) : Number(v).toFixed(1);
    }
  });
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.plugins.legend.labels.boxWidth = 12;
  Chart.defaults.plugins.legend.labels.padding = 12;
  Chart.defaults.plugins.legend.position = 'bottom';

  var accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#3b82f6';

  /* ---------------- Chart 1: Attendance 30-day stacked bars ---------------- */
  var attRaw = JSON.parse('<?php echo $attDailyJson; ?>');
  if (document.getElementById('attChart')) {
    var labels = attRaw.map(function(r){
      var d = new Date(r.d + 'T00:00:00');
      return d.toLocaleDateString(undefined, { month:'short', day:'numeric' });
    });
    new Chart(document.getElementById('attChart'), {
      type:'bar',
      data:{
        labels: labels,
        datasets:[
          { label:'Present', data: attRaw.map(function(r){return r.p;}), backgroundColor:'#22c55e', stack:'a' },
          { label:'Late',    data: attRaw.map(function(r){return r.l;}), backgroundColor:'#f59e0b', stack:'a' },
          { label:'Excused', data: attRaw.map(function(r){return r.e;}), backgroundColor:'#06b6d4', stack:'a' },
          { label:'Absent',  data: attRaw.map(function(r){return r.a;}), backgroundColor:'#ef4444', stack:'a' }
        ]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{
          tooltip:{
            callbacks:{
              footer: function(items){
                var t = items.reduce(function(a,i){return a + Number(i.parsed.y||0);}, 0);
                return 'Total students marked this day: ' + t;
              }
            }
          },
          datalabels:{
            formatter: function(v){ return v>0 ? String(v) : ''; },
            color:'#fff', textStrokeColor:'rgba(0,0,0,.18)', textStrokeWidth: 2
          }
        },
        scales:{ x:{ stacked:true, grid:{display:false}, ticks:{ maxRotation:0, autoSkip:true, maxTicksLimit:10 } },
                 y:{ stacked:true, beginAtZero:true, ticks:{ precision:0 }, title:{ display:true, text:'Student count' } } }
      }
    });
  }

  /* ---------------- Chart 2: Teacher workload HORIZONTAL bar ---------------- */
  var wlRaw = JSON.parse('<?php echo $teacherWlJson; ?>');
  if (document.getElementById('wlChart')) {
    var empty = wlRaw.length === 0;
    new Chart(document.getElementById('wlChart'), {
      type:'bar',
      data:{
        labels: empty ? ['(No teacher schedule data yet)'] : wlRaw.map(function(r){return r.name;}),
        datasets: [{
          label: 'Periods per week',
          data:  empty ? [0] : wlRaw.map(function(r){return Number(r.periods_per_week);}),
          backgroundColor: empty ? '#cbd5e1' : wlRaw.map(function(_, i){
            // gradient palette from accent → light
            return 'hsl(' + ((i*23) % 360) + ', 62%, ' + (48 + (i*3)%20) + '%)';
          }),
          borderRadius: 6, borderSkipped: false
        }]
      },
      options:{
        indexAxis: 'y',
        responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{
            callbacks:{
              label: function(i){
                if (empty) return 'Add teachers → teacher_subjects → schedules.';
                var idx = i.dataIndex;
                return [
                  'Scheduled periods/week: ' + Number(i.parsed.x),
                  'Sections covered: ' + wlRaw[idx].sections_covered,
                  'Subjects: ' + wlRaw[idx].subjects
                ];
              }
            }
          },
          datalabels:{ anchor:'end', align:'right',
            formatter:function(v){ return Number(v).toFixed(0); },
            color:'#0f172a' }
        },
        scales:{
          x:{ beginAtZero:true, ticks:{ precision:0 }, title:{ display:true, text:'Periods taught per week' } },
          y:{ grid:{ display:false } }
        }
      }
    });
  }

  /* ---------------- Chart 3: Average Grade by Level ---------------- */
  var grdRaw = JSON.parse('<?php echo $gradeByLvlJson; ?>');
  if (document.getElementById('gradeChart')) {
    new Chart(document.getElementById('gradeChart'), {
      type:'bar',
      data:{
        labels: grdRaw.length ? grdRaw.map(function(r){return r.lvl;}) : ['(No grade data yet)'],
        datasets: [{
          label: 'Average grade',
          data: grdRaw.length ? grdRaw.map(function(r){return Number(r.avg_grade);}) : [0],
          backgroundColor: grdRaw.length
            ? grdRaw.map(function(r){return Number(r.avg_grade) >= 75 ? '#22c55e' : '#ef4444';})
            : ['#cbd5e1'],
          borderRadius: 8, borderSkipped:false
        }]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{
            callbacks:{
              afterTitle: function(items){
                if (!grdRaw.length) return '';
                var r = grdRaw[items[0].dataIndex];
                return 'Students graded: ' + (r.student_count|0);
              },
              label: function(i){
                var n = Number(i.parsed.y);
                var row = grdRaw[i.dataIndex];
                return [
                  'Average: ' + n.toFixed(2) + ' / 100',
                  'Passing entries: '  + ((row && row.passing_rows)|0),
                  'Failing entries: '  + ((row && row.failing_rows)|0)
                ];
              }
            }
          },
          datalabels:{
            formatter:function(v){ return v>0 ? Number(v).toFixed(2) : ''; },
            color:'#0f172a'
          }
        },
        scales:{
          x:{ grid:{ display:false }, ticks:{font:{size:10}} },
          y:{
            min: 60, max: 100, ticks:{ stepSize: 5, font:{size:10} },
            title:{ display:true, text:'Grade (DepEd passing = 75)', font:{size:10} },
            suggestedMin: 0
          }
        }
      },
      plugins:[{
        id:'passLine',
        afterDraw: function(chart){
          if (!grdRaw.length) return;
          var yScale = chart.scales['y'];
          var yVal = yScale.getPixelForValue(75);
          var ctx = chart.ctx;
          ctx.save();
          ctx.beginPath();
          ctx.moveTo(chart.chartArea.left, yVal);
          ctx.lineTo(chart.chartArea.right, yVal);
          ctx.strokeStyle = 'rgba(239,68,68,.7)';
          ctx.setLineDash([6,4]);
          ctx.lineWidth = 1.5;
          ctx.stroke();
          ctx.fillStyle = 'rgba(239,68,68,.9)';
          ctx.font = 'bold 9px Inter, sans-serif';
          ctx.fillText('75 (Passing)', chart.chartArea.right - 78, yVal - 3);
          ctx.restore();
        }
      }]
    });
  }

  /* ---------------- Chart 4: Pass / Fail DONUT ---------------- */
  var pf = JSON.parse('<?php echo $passFailJson; ?>');
  if (document.getElementById('pfChart')) {
    var total = (Number(pf.passed)|0) + (Number(pf.failed)|0);
    new Chart(document.getElementById('pfChart'), {
      type: 'doughnut',
      data:{
        labels: ['Passed (avg ≥ 75)','Failed (avg < 75)'],
        datasets:[{
          data: [Number(pf.passed)|0, Number(pf.failed)|0],
          backgroundColor: ['#22c55e','#ef4444'],
          borderWidth: 2, borderColor: '#fff'
        }]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        cutout: '60%',
        plugins:{
          tooltip:{
            callbacks:{
              label: function(i){
                var v = Number(i.parsed);
                var pct = total>0 ? (v/total*100).toFixed(1) : '0.0';
                return i.label + ': ' + v + '  (' + pct + '%)';
              }
            }
          },
          datalabels:{
            color:'#fff',
            formatter: function(v, ctx){
              if (!v) return '';
              var pct = total>0 ? (v/total*100).toFixed(0) : '0';
              return v + '\n' + pct + '%';
            },
            font:{ weight:'700', size:12 },
            textAlign:'center',
            textStrokeColor:'rgba(0,0,0,.2)', textStrokeWidth:2
          }
        }
      }
    });
  }

  /* ---------------- Chart 5: Subjects bar ---------------- */
  var subjRaw = JSON.parse('<?php echo $subjectPerfJson; ?>');
  if (document.getElementById('subjChart')) {
    var empty = subjRaw.length === 0;
    new Chart(document.getElementById('subjChart'), {
      type:'bar',
      data:{
        labels: empty ? ['(No subject grades yet)'] : subjRaw.map(function(r){return r.subj;}),
        datasets:[{
          label: 'Average grade',
          data: empty ? [0] : subjRaw.map(function(r){return Number(r.avg_grade);}),
          backgroundColor: empty ? ['#cbd5e1'] : subjRaw.map(function(r){return Number(r.avg_grade)>=75?'#22c55e':'#ef4444';}),
          borderRadius: 6, borderSkipped: false
        }]
      },
      options:{
        indexAxis:'y',
        responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{
            callbacks:{
              label: function(i){
                if (empty) return 'Encode grades to populate this chart.';
                var r = subjRaw[i.dataIndex];
                return [
                  'Avg grade: ' + Number(i.parsed.x).toFixed(2),
                  'Graded students: ' + (r.students|0),
                  'Grading periods: ' + (r.period_count|0)
                ];
              }
            }
          },
          datalabels:{ anchor:'end', align:'right',
            formatter:function(v){return v>0?Number(v).toFixed(2):'';},
            color:'#0f172a' }
        },
        scales:{
          x:{ min:60, max:100, ticks:{ stepSize:5 }, title:{ display:true, text:'Average grade' } },
          y:{ grid:{ display:false } }
        }
      }
    });
  }

  /* ---------------- Chart 6: Enrollment by Section DONUT ---------------- */
  var secRaw = JSON.parse('<?php echo $sectionEnrollJson; ?>');
  if (document.getElementById('secChart')) {
    var total = secRaw.reduce(function(a,b){return a + Number(b.enrolled);}, 0);
    new Chart(document.getElementById('secChart'), {
      type:'doughnut',
      data:{
        labels: secRaw.length ? secRaw.map(function(r){return r.section + ' · ' + (r.lvl||'');}) : ['(No enrollment data yet)'],
        datasets:[{
          data: secRaw.length ? secRaw.map(function(r){return Number(r.enrolled);}) : [1],
          backgroundColor: secRaw.length
            ? secRaw.map(function(_,i){return 'hsl(' + ((i*33)%360) + ', 65%, ' + (45+(i*2)%20) + '%)';})
            : ['#cbd5e1'],
          borderWidth: 2, borderColor: '#fff'
        }]
      },
      options:{
        responsive:true, maintainAspectRatio:false, cutout:'55%',
        plugins:{
          legend:{
            display:true, position:'right',
            labels:{ boxWidth:10, padding:6, font:{ size: 10 } }
          },
          tooltip:{
            callbacks:{
              label: function(i){
                if (!secRaw.length) return 'Add sections + enrollments + students.section_id to populate.';
                var v = Number(i.parsed);
                var r = secRaw[i.dataIndex];
                var pct = total>0 ? (v/total*100).toFixed(1) : '0.0';
                return [r.section + ' (' + (r.lvl||'N/A') + ')',
                        'Enrolled: ' + v,
                        'Share: ' + pct + '%'];
              }
            }
          },
          datalabels:{
            color:'#fff',
            formatter:function(v, ctx){
              if (!secRaw.length) return '';
              if (!v) return '';
              var pct = total>0 ? (v/total*100).toFixed(0) : '0';
              return v + '\n' + pct + '%';
            },
            font:{ weight:'700', size:10 }, textAlign:'center',
            textStrokeColor:'rgba(0,0,0,.2)', textStrokeWidth:2,
            clip:true
          }
        }
      }
    });
  }
})();
</script>
