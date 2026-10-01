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
$d30ago   = date('Y-m-d', strtotime('-30 days'));
$d7ago    = date('Y-m-d', strtotime('-7 days'));

$teacherId = 0;
$reportData = [];
$tid = 0;

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT id FROM teachers WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) $tid = (int)$row['id'];
        $stmt->close();
    }
}

$tsIds = [];
if ($tid) {
    $resTS = $mysqli->query("SELECT id FROM teacher_subjects WHERE teacher_id = {$tid}");
    if ($resTS) { while ($r = $resTS->fetch_row()) $tsIds[] = (int)$r[0]; $resTS->free(); }
}
$tsIdsList = $tsIds ? implode(',', array_map('intval', $tsIds)) : '0';

$kpi = [
    'classes' => count($tsIds),
    'graded_students' => 0,
    'total_grades' => 0,
    'passed_students' => 0,
    'failed_students' => 0,
    'pass_rate' => 0.0,
    'attendance_7d' => 0.0,
    'att7_on_time' => 0,
    'att7_total' => 0,
    'attendance_30d' => 0.0,
    'att30_on_time' => 0,
    'att30_total' => 0,
    'overall_avg' => 0.0,
];

if ($tsIds) {
    $kpi['total_grades'] = (int)agg_one($mysqli, "SELECT COUNT(*) FROM grades WHERE teacher_subject_id IN ({$tsIdsList})");
    $kpi['overall_avg'] = (float)(agg_one($mysqli, "SELECT ROUND(AVG(grade),2) FROM grades WHERE teacher_subject_id IN ({$tsIdsList})") ?: 0.0);

    $stmtPF = $mysqli->query("
        SELECT
            SUM(CASE WHEN avg_grade >= 75 THEN 1 ELSE 0 END) AS passed,
            SUM(CASE WHEN avg_grade <  75 THEN 1 ELSE 0 END) AS failed,
            COUNT(*) AS total
        FROM (
            SELECT student_id, AVG(grade) AS avg_grade
            FROM grades WHERE teacher_subject_id IN ({$tsIdsList}) GROUP BY student_id
        ) AS sg
    ");
    if ($stmtPF) {
        $r = $stmtPF->fetch_assoc();
        $kpi['passed_students'] = (int)($r['passed'] ?? 0);
        $kpi['failed_students'] = (int)($r['failed'] ?? 0);
        $kpi['graded_students'] = (int)($r['total'] ?? 0);
        if ($kpi['graded_students'] > 0) {
            $kpi['pass_rate'] = round(($kpi['passed_students'] / $kpi['graded_students']) * 100, 1);
        }
        $stmtPF->free();
    }

    function attRate(mysqli $db, string $tsList, string $from, string $to, &$onRef, &$totRef): float {
        $on = 0; $tot = 0;
        $res = $db->query("
            SELECT status, COUNT(*) AS c FROM attendance
            WHERE teacher_subject_id IN ({$tsList}) AND attendance_date BETWEEN '{$from}' AND '{$to}'
            GROUP BY status
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $c = (int)$r['c'];
                $tot += $c;
                if (in_array($r['status'], ['Present','Late','Excused'], true)) $on += $c;
            }
            $res->free();
        }
        $onRef = $on; $totRef = $tot;
        return $tot > 0 ? round(($on / $tot) * 100, 1) : 0.0;
    }
    $kpi['attendance_7d']  = attRate($mysqli, $tsIdsList, $d7ago,  $todaySql, $kpi['att7_on_time'],  $kpi['att7_total']);
    $kpi['attendance_30d'] = attRate($mysqli, $tsIdsList, $d30ago, $todaySql, $kpi['att30_on_time'], $kpi['att30_total']);

    $query = "
        SELECT ts.id,
               sub.subject_code AS code,
               sub.subject_name AS subj,
               sec.section_name AS section,
               sec.grade_level AS lvl,
               COUNT(DISTINCT g.student_id) AS students,
               COUNT(g.id) AS total_rows,
               ROUND(AVG(g.grade), 2) AS avg_grade,
               SUM(CASE WHEN g.grade >= 75 THEN 1 ELSE 0 END) AS passed_count,
               SUM(CASE WHEN g.grade <  75 THEN 1 ELSE 0 END) AS failed_count
        FROM teacher_subjects ts
        JOIN subjects sub ON ts.subject_id = sub.id
        JOIN sections sec ON ts.section_id = sec.id
        LEFT JOIN grades g ON g.teacher_subject_id = ts.id
        WHERE ts.teacher_id = {$tid}
        GROUP BY ts.id
        ORDER BY avg_grade DESC
    ";
    $resQ = $mysqli->query($query);
    if ($resQ) { while ($row = $resQ->fetch_assoc()) $reportData[] = $row; $resQ->free(); }
}

$att30Daily = [];
if ($tsIds) {
    $resA30 = $mysqli->query("
        SELECT DATE(a.attendance_date) AS d,
            SUM(CASE WHEN a.status='Present' THEN 1 ELSE 0 END) AS p,
            SUM(CASE WHEN a.status='Absent'  THEN 1 ELSE 0 END) AS a,
            SUM(CASE WHEN a.status='Late'    THEN 1 ELSE 0 END) AS l,
            SUM(CASE WHEN a.status='Excused' THEN 1 ELSE 0 END) AS e
        FROM attendance a
        WHERE a.teacher_subject_id IN ({$tsIdsList}) AND a.attendance_date BETWEEN '{$d30ago}' AND '{$todaySql}'
        GROUP BY DATE(a.attendance_date) ORDER BY d ASC
    ");
    if ($resA30) { while ($r = $resA30->fetch_assoc()) $att30Daily[] = $r; $resA30->free(); }
}

$toJson = static function($v): string {
    $j = json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS);
    return $j === false ? '[]' : $j;
};
$reportDataJson = $toJson($reportData);
$att30Json      = $toJson($att30Daily);
$pfJson         = $toJson(['passed'=>$kpi['passed_students'],'failed'=>$kpi['failed_students']]);

$sidebar = [
    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
    ['label' => 'My Classes', 'href' => 'classes.php', 'icon' => 'classes'],
    ['label' => 'Attendance', 'href' => 'attendance.php', 'icon' => 'attendance'],
    ['label' => 'Grades', 'href' => 'grades.php', 'icon' => 'grade_book'],
    ['label' => 'Reports', 'href' => 'reports.php', 'active' => true, 'icon' => 'reports'],
];
start_layout('Reports', 'AIMS - Teacher', $_SESSION['username'] ?? '', $sidebar);
?>

<div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
    <div>
        <h1 class="h4 mb-0">Class Performance Reports</h1>
        <small class="text-muted">Accurate view · Data from <b><?php echo $d30ago; ?></b> → <b><?php echo $todaySql; ?></b></small>
    </div>
</div>

<div class="kpi-grid mb-2">
    <div class="kpi-card kpi-primary">
        <div class="kpi-label">Classes</div>
        <div class="kpi-value"><?php echo $kpi['classes']; ?></div>
        <div class="kpi-foot">Subject-section loads</div>
    </div>
    <div class="kpi-card kpi-success">
        <div class="kpi-label">Graded Students</div>
        <div class="kpi-value"><?php echo $kpi['graded_students']; ?></div>
        <div class="kpi-foot">Overall avg: <strong><?php echo number_format($kpi['overall_avg'],2); ?></strong></div>
    </div>
    <div class="kpi-card kpi-warning">
        <div class="kpi-label">Pass Rate</div>
        <div class="kpi-value"><?php echo $kpi['pass_rate']; ?>%</div>
        <div class="kpi-foot"><strong class="text-success"><?php echo $kpi['passed_students']; ?></strong> pass · <strong><?php echo $kpi['failed_students']; ?></strong> fail</div>
    </div>
    <div class="kpi-card kpi-info">
        <div class="kpi-label">Attendance 7d</div>
        <div class="kpi-value"><?php echo $kpi['attendance_7d']; ?>%</div>
        <div class="kpi-foot"><strong><?php echo $kpi['att7_on_time']; ?></strong>/<?php echo $kpi['att7_total']; ?> on-time</div>
    </div>
    <div class="kpi-card kpi-secondary">
        <div class="kpi-label">Attendance 30d</div>
        <div class="kpi-value"><?php echo $kpi['attendance_30d']; ?>%</div>
        <div class="kpi-foot"><strong><?php echo $kpi['att30_on_time']; ?></strong>/<?php echo $kpi['att30_total']; ?> on-time</div>
    </div>
    <div class="kpi-card kpi-danger">
        <div class="kpi-label">Grade Rows</div>
        <div class="kpi-value"><?php echo $kpi['total_grades']; ?></div>
        <div class="kpi-foot">Encoded grade entries</div>
    </div>
</div>

<?php if (empty($reportData)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <h5 class="text-muted">No grade data available yet.</h5>
            <p class="text-muted mb-0">Encode grades in your classes first to populate analytics with values.</p>
        </div>
    </div>
<?php else: ?>

<div class="analytics-grid mb-2">
    <div class="card shadow-sm">
        <div class="card-header py-2 px-3">
            <h6 class="mb-0">Avg Grade Per Class</h6>
            <small class="text-muted">Grade printed on each bar; green ≥ 75, red below</small>
        </div>
        <div class="card-body p-2 pt-3">
            <div class="chart-container" style="height:260px"><canvas id="gradeChart"></canvas></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header py-2 px-3">
            <h6 class="mb-0">Passed vs Failed — Per Class</h6>
            <small class="text-muted">Stacked counts shown as labels on each segment</small>
        </div>
        <div class="card-body p-2 pt-3">
            <div class="chart-container" style="height:260px"><canvas id="pfStackChart"></canvas></div>
        </div>
    </div>
</div>

<div class="analytics-grid mb-2">
    <div class="card shadow-sm">
        <div class="card-header py-2 px-3">
            <h6 class="mb-0">Attendance — Last 30 days (My classes)</h6>
            <small class="text-muted">Daily stacked breakdown with values</small>
        </div>
        <div class="card-body p-2 pt-3">
            <div class="chart-container" style="height:240px"><canvas id="attChart"></canvas></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header py-2 px-3">
            <h6 class="mb-0">Overall Pass / Fail Distribution</h6>
            <small class="text-muted">Count + % label printed on each slice</small>
        </div>
        <div class="card-body p-2 pt-3">
            <div class="chart-container" style="height:240px"><canvas id="pfChart"></canvas></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header py-2 px-3">
        <h6 class="mb-0">Detailed Class Performance Table</h6>
        <small class="text-muted">Accurate counts per subject × section</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:0.8rem">
                <thead class="table-light">
                    <tr>
                        <th>Subject</th>
                        <th>Section</th>
                        <th>Lvl</th>
                        <th class="text-end">Students</th>
                        <th class="text-end">Avg Grade</th>
                        <th class="text-end">Pass %</th>
                        <th class="text-end text-success">Passed</th>
                        <th class="text-end text-danger">Failed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData as $data):
                        $totalRows = (int)$data['passed_count'] + (int)$data['failed_count'];
                        $rate = $totalRows > 0 ? round(((int)$data['passed_count'] / $totalRows) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($data['code']); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($data['subj']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($data['section']); ?></td>
                            <td><?php echo htmlspecialchars($data['lvl'] ?? '—'); ?></td>
                            <td class="text-end fw-bold"><?php echo (int)$data['students']; ?></td>
                            <td class="text-end fw-bold <?php echo (float)$data['avg_grade']>=75?'text-success':'text-danger'; ?>">
                                <?php echo number_format((float)$data['avg_grade'], 2); ?>
                            </td>
                            <td class="text-end">
                                <span class="badge <?php echo $rate>=75?'bg-success':'bg-danger'; ?>"><?php echo $rate; ?>%</span>
                            </td>
                            <td class="text-end text-success fw-bold"><?php echo (int)$data['passed_count']; ?></td>
                            <td class="text-end text-danger fw-bold"><?php echo (int)$data['failed_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

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

  var repRaw = JSON.parse('<?php echo $reportDataJson; ?>');
  if (window.Chart && document.getElementById('gradeChart') && repRaw.length) {
    new Chart(document.getElementById('gradeChart'), {
      type:'bar',
      data:{
        labels: repRaw.map(r => r.code + ' · ' + r.section),
        datasets:[{
          label:'Avg Grade',
          data: repRaw.map(r => Number(r.avg_grade||0)),
          backgroundColor: repRaw.map(r => (Number(r.avg_grade||0)>=75?'#22c55e':'#ef4444')),
          borderRadius:6, borderSkipped:false
        }]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{display:false},
          tooltip:{
            callbacks:{
              afterTitle: items => 'Students graded: ' + ((repRaw[items[0].dataIndex].students)|0),
              label: i => {
                var n = Number(i.parsed.x);
                var r = repRaw[i.dataIndex];
                return [
                  'Average: ' + n.toFixed(2) + ' / 100',
                  'Passed grade rows: ' + ((r.passed_count)|0),
                  'Failed grade rows: ' + ((r.failed_count)|0)
                ];
              }
            }
          },
          datalabels:{
            anchor:'end', align:'right',
            formatter: v => v>0 ? Number(v).toFixed(2) : '',
            color:'#0f172a', font:{weight:700, size:10}
          }
        },
        scales:{
          x:{min:60, max:100, ticks:{stepSize:5, font:{size:10}}, title:{display:true, text:'Avg grade (75 = pass)', font:{size:10}}},
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

  if (window.Chart && document.getElementById('pfStackChart') && repRaw.length) {
    new Chart(document.getElementById('pfStackChart'), {
      type:'bar',
      data:{
        labels: repRaw.map(r => r.code + ' · ' + r.section),
        datasets:[
          { label:'Passed (≥75)', data: repRaw.map(r => Number(r.passed_count)|0), backgroundColor:'#22c55e', stack:'s' },
          { label:'Failed (<75)',  data: repRaw.map(r => Number(r.failed_count)|0), backgroundColor:'#ef4444', stack:'s' }
        ]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{position:'bottom', labels:{boxWidth:10, font:{size:10}}},
          tooltip:{
            callbacks:{
              footer: items => {
                var t = items.reduce((a,i)=>a + Number(i.parsed.x||0), 0);
                return 'Total grade rows: ' + t;
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
          x:{stacked:true, beginAtZero:true, ticks:{precision:0, font:{size:10}}, title:{display:true, text:'Student count', font:{size:10}}},
          y:{stacked:true, grid:{display:false}, ticks:{font:{size:9}}}
        }
      }
    });
  }

  var attRaw = JSON.parse('<?php echo $att30Json; ?>');
  if (window.Chart && document.getElementById('attChart')) {
    var labels = attRaw.length ? attRaw.map(function(r){
      var d = new Date(r.d + 'T00:00:00');
      return d.toLocaleDateString(undefined,{month:'short', day:'numeric'});
    }) : ['(No attendance data)'];
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
                return 'Day total: ' + t + ' records';
              }
            }
          },
          datalabels:{
            formatter: v => v>0 ? String(v) : '',
            color:'#fff', textStrokeColor:'rgba(0,0,0,.18)', textStrokeWidth:2,
            font:{weight:700, size:9}
          }
        },
        scales:{
          x:{stacked:true, grid:{display:false}, ticks:{maxRotation:0, autoSkip:true, maxTicksLimit:10, font:{size:9}}},
          y:{stacked:true, beginAtZero:true, ticks:{precision:0, font:{size:10}}, title:{display:true, text:'Count', font:{size:10}}}
        }
      }
    });
  }

  var pf = JSON.parse('<?php echo $pfJson; ?>');
  if (window.Chart && document.getElementById('pfChart')) {
    var passed = Number(pf.passed)|0, failed = Number(pf.failed)|0, total = passed + failed;
    new Chart(document.getElementById('pfChart'), {
      type:'doughnut',
      data:{
        labels:['Passed (avg ≥75)','Failed (avg <75)'],
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
            font:{weight:700, size:12}, textAlign:'center',
            textStrokeColor:'rgba(0,0,0,.2)', textStrokeWidth:2
          }
        }
      }
    });
  }
})();
</script>
