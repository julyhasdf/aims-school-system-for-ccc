<?php
declare(strict_types=1);

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/settings_store.php';
require_once __DIR__ . '/profile_photo.php';
require_once __DIR__ . '/notifications.php';

function start_layout(string $title, string $brand, string $username, array $sidebar): void
{
    $role = $_SESSION['role'] ?? '';
    $settings = get_settings();
    $accent = $settings['theme']['student']['accent'] ?? '#22c55e';
    $accentHover = $settings['theme']['student']['hover'] ?? '#16a34a';
    if ($role === 'admin') {
        $accent = $settings['theme']['admin']['accent'] ?? '#3b82f6';
        $accentHover = $settings['theme']['admin']['hover'] ?? '#2563eb';
    } elseif ($role === 'teacher') {
        $accent = $settings['theme']['teacher']['accent'] ?? '#fbbf24';
        $accentHover = $settings['theme']['teacher']['hover'] ?? '#f59e0b';
    }

    // Convert hex accent to rgb for transparent backgrounds
    $hex2rgb = function($hex) {
        $hex = str_replace('#', '', $hex);
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex,0,1).substr($hex,0,1));
            $g = hexdec(substr($hex,1,1).substr($hex,1,1));
            $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
            $r = hexdec(substr($hex,0,2));
            $g = hexdec(substr($hex,2,2));
            $b = hexdec(substr($hex,4,2));
        }
        return "$r, $g, $b";
    };
    $accentRgb = $hex2rgb($accent);

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script><script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><style>:root{--accent:' . $accent . ';--accent-rgb:' . $accentRgb . ';--accent-hover:' . $accentHover . ';--ink-900:#0f172a;--ink-800:#1e293b;--ink-700:#334155;--ink-600:#475569;--ink-400:#94a3b8;--ink-200:#e2e8f0;--ink-100:#f1f5f9;--ink-50:#f8fafc}body{background:var(--ink-50);font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink-800);-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;line-height:1.5}.layout-wrapper{min-height:100vh}
/* Accessibility: Skip Link (visible on Tab focus) */
.skip-link{position:absolute;top:-100px;left:0.5rem;z-index:9999;background:var(--accent);color:#fff;padding:0.6rem 1rem;border-radius:0.75rem;font-weight:600;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,0.15);transition:top .2s}
.skip-link:focus,.skip-link:focus-visible{top:0.5rem;outline:none}
/* Universal visible focus rings — keyboard users see this clearly */
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:6px}
.btn:focus-visible,.icon-btn:focus-visible,.card-header-btn:focus-visible,.list-group-item:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
/* Chart value labels (crisp) */
canvas{max-width:100%}.chart-container{position:relative;height:240px}
/* Dashboard grids */
.kpi-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:0.6rem}
@media(max-width:1399px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:767px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
/* Softened modern KPI cards with inner-ring accent & readable ratios (WCAG AA contrast) */
.kpi-card{position:relative;padding:0.75rem 0.95rem;border-radius:1rem;color:#fff;box-shadow:0 1px 2px rgba(15,23,42,0.06),0 4px 10px -4px rgba(0,0,0,0.1);background:linear-gradient(135deg,var(--accent),var(--accent-hover));display:flex;flex-direction:column;justify-content:space-between;min-height:86px;gap:0.2rem;border:1px solid rgba(255,255,255,0.15)}
.kpi-card::after{content:"";position:absolute;inset:0.25rem;border-radius:0.85rem;border:1px solid rgba(255,255,255,0.18);pointer-events:none}
.kpi-card.kpi-primary{background:linear-gradient(135deg,#3b82f6,#2563eb)}
.kpi-card.kpi-success{background:linear-gradient(135deg,#16a34a,#15803d)}
.kpi-card.kpi-warning{background:linear-gradient(135deg,#d97706,#b45309)}
.kpi-card.kpi-info{background:linear-gradient(135deg,#0891b2,#0e7490)}
.kpi-card.kpi-secondary{background:linear-gradient(135deg,#475569,#334155)}
.kpi-card.kpi-danger{background:linear-gradient(135deg,#dc2626,#b91c1c)}
.kpi-label{font-size:0.68rem;letter-spacing:0.4px;opacity:0.92;font-weight:600;text-transform:uppercase;line-height:1.15}
.kpi-value{font-size:1.65rem;font-weight:800;line-height:1.05;letter-spacing:-0.01em}
.kpi-foot{font-size:0.7rem;opacity:0.92;line-height:1.25}
.kpi-foot strong{font-weight:700}
/* Two-column analytics grid */
.analytics-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:0.75rem}
@media(max-width:991px){.analytics-grid{grid-template-columns:1fr}}
/* Modern Card Styles: cleaner border, soft elevation, consistent accent underline, no harsh lift */
.card{border:1px solid var(--ink-200);border-radius:1rem;box-shadow:0 1px 2px rgba(15,23,42,0.04),0 2px 6px -2px rgba(15,23,42,0.05);transition:box-shadow .22s ease,border-color .22s ease;background:#fff;position:relative;overflow:hidden}
.card::before{content:"";position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--accent-hover));opacity:0.35;transition:opacity .2s}
.card:hover{box-shadow:0 4px 10px -3px rgba(var(--accent-rgb),0.12),0 8px 20px -10px rgba(15,23,42,0.1);border-color:rgba(var(--accent-rgb),0.25)}
.card:hover::before{opacity:1}
.card-header{background:#fff;border-bottom:1px solid var(--ink-100);padding:0.85rem 1rem 0.7rem 1rem;font-weight:700;color:var(--ink-800)}
.card-header h5,.card-header h6{color:var(--ink-900);letter-spacing:-0.01em}
.card-header small{font-weight:500;color:var(--ink-600)}
.card-body{padding:1rem}
.card-footer{background:transparent;border-top:1px solid var(--ink-100);padding:0.85rem 1rem}
/* Collapsible cards: header becomes a clickable button wrapper */
.card.card-collapsible{}
.card-header-btn{display:flex;align-items:center;justify-content:space-between;gap:1rem;width:100%;background:transparent;border:0;text-align:start;padding:0;margin:0;font:inherit;color:inherit;cursor:pointer;border-radius:0}
.card-header-btn:hover,.card-header-btn:focus-visible{color:inherit}
.card-header-btn .chevron{flex:0 0 auto;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;color:var(--ink-600);transition:transform .28s cubic-bezier(.2,.8,.2,1),background .2s,color .2s}
.card-header-btn:hover .chevron{background:var(--ink-100);color:var(--ink-900)}
.card.card-collapsible.is-collapsed .chevron{transform:rotate(-90deg)}
.card.card-collapsible .card-collapse{overflow:hidden;max-height:2000px;opacity:1;transition:max-height .35s cubic-bezier(.2,.8,.2,1),opacity .25s ease,padding .2s ease}
.card.card-collapsible.is-collapsed .card-collapse{max-height:0;opacity:0;padding-top:0!important;padding-bottom:0!important}
.card.card-collapsible.is-collapsed{/* keep compact header only */}
/* Summary line visible in collapsed state for quick context (below header) */
.card-collapsed-snippet{display:none;padding:0 1rem 0.7rem 1rem;font-size:0.78rem;color:var(--ink-600);gap:0.75rem;flex-wrap:wrap}
.card.card-collapsible.is-collapsed .card-collapsed-snippet{display:flex}
.card-collapsed-snippet .chip{display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.55rem;border-radius:999px;background:var(--ink-100);color:var(--ink-700);font-weight:600;font-size:0.72rem;white-space:nowrap;border:1px solid var(--ink-200)}
.card-collapsed-snippet .chip strong{color:var(--ink-900)}
.card-collapsed-snippet .chip.pass{background:#dcfce7;color:#14532d;border-color:#86efac}
.card-collapsed-snippet .chip.rate{background:#dbeafe;color:#1e3a8a;border-color:#93c5fd}
.card-collapsed-snippet .chip.warn{background:#fef3c7;color:#78350f;border-color:#fcd34d}
/* Modern accessible tables (for Reports) */
.table-modern{width:100%;border-collapse:separate;border-spacing:0;font-size:0.85rem}
.table-modern thead th{position:sticky;top:0;background:var(--ink-100);color:var(--ink-900);font-weight:700;text-align:start;padding:0.7rem 0.9rem;border-bottom:2px solid var(--ink-200);white-space:nowrap;z-index:1}
.table-modern tbody td{padding:0.65rem 0.9rem;border-bottom:1px solid var(--ink-100);color:var(--ink-800);vertical-align:middle}
.table-modern tbody tr:nth-child(even) td{background:var(--ink-50)}
.table-modern tbody tr:hover td{background:rgba(var(--accent-rgb),0.06)}
.table-modern tfoot th,.table-modern tfoot td{padding:0.7rem 0.9rem;background:var(--ink-100);font-weight:700;color:var(--ink-900);border-top:2px solid var(--ink-200)}
.table-responsive{border-radius:0.75rem;border:1px solid var(--ink-200);overflow:auto;max-height:360px;background:#fff}
/* Buttons: larger tap targets, consistent radius, softer hover */
.btn{border-radius:0.75rem;padding-top:0.55rem;padding-bottom:0.55rem;font-weight:600;letter-spacing:0.01em;transition:all .2s ease}
.btn:active{transform:translateY(1px)}
.btn-outline-primary{border-width:1.5px}
.form-control,.form-select{border-radius:0.75rem;padding:0.55rem 0.9rem;border:1.5px solid var(--ink-200);font-size:0.9rem;transition:border-color .15s,box-shadow .15s}
.form-control:focus,.form-select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),0.15)}
.dropdown-menu{border-radius:0.85rem;border:1px solid var(--ink-200);box-shadow:0 10px 25px -10px rgba(15,23,42,0.15),0 4px 10px -4px rgba(15,23,42,0.08);padding:0.4rem}
.dropdown-item{border-radius:0.55rem;padding:0.5rem 0.75rem;font-weight:500;color:var(--ink-800)}
.dropdown-item:hover{background:rgba(var(--accent-rgb),0.1);color:var(--accent)}
/* Topbar & Sidebar: softer, more modern */
.topbar{background:rgba(255,255,255,0.75);backdrop-filter:blur(14px) saturate(1.1);-webkit-backdrop-filter:blur(14px) saturate(1.1);border-bottom:1px solid var(--ink-200);box-shadow:0 1px 2px rgba(15,23,42,0.03);z-index:50}
.topbar .brand-title,.topbar .brand-subtitle,.topbar .role-label{user-select:none;-webkit-user-select:none}
.topbar .brand-title{font-weight:800;font-size:2rem;letter-spacing:-0.02em;color:var(--accent);margin-bottom:0;line-height:1}
.topbar .brand-subtitle{font-size:0.65rem;color:var(--ink-600);font-weight:600;letter-spacing:0.7px;text-transform:uppercase}
.topbar .role-label{font-weight:700;margin-top:.1rem;color:var(--ink-900);font-size:0.92rem}
.topbar .top-clock{color:var(--ink-700);font-weight:600;display:inline-block}
.topbar .bar-inner{min-height:80px}
.brand-logo{height:56px}
.icon-btn{border:none;background:transparent;padding:.5rem;border-radius:0.75rem;transition:background .2s,color .2s;color:var(--ink-700)}
.icon-btn:hover{background:var(--ink-100);color:var(--accent)}
.sidebar{width:88px;background:#fff;border-right:1px solid var(--ink-200);box-shadow:none;transition:width .3s cubic-bezier(.4,0,.2,1);z-index:40}
.sidebar:hover{width:280px}
.sidebar .list-group-item{border:0;border-radius:0.8rem;margin-bottom:.4rem;padding:.7rem 0.9rem;color:var(--ink-600);font-weight:600;font-size:0.9rem;transition:all .18s ease}
.sidebar .list-group-item:hover{background:var(--ink-100);color:var(--ink-900);transform:translateX(2px)}
.sidebar .list-group-item.active{background:rgba(var(--accent-rgb),0.1);color:var(--accent);font-weight:700}
.item-label{opacity:0;width:0;overflow:hidden;transition:opacity .2s ease,width .3s ease;white-space:nowrap;margin-left:.75rem}
.sidebar:hover .item-label{opacity:1;width:auto}
.content{flex:1;padding:1.5rem 2rem}
.dropdown-toggle.no-arrow::after{display:none!important}
/* Mobile Responsive tweaks */
@media (max-width: 768px) {
    .layout-wrapper{flex-direction:column}
    .sidebar{width:100%;height:auto;border-right:none;border-bottom:1px solid var(--ink-200);position:fixed;bottom:0;left:0;z-index:1000;box-shadow:0 -2px 10px rgba(15,23,42,0.05)}
    .sidebar:hover{width:100%}
    .sidebar .p-3{display:none}
    .sidebar .list-group{flex-direction:row;justify-content:space-around;padding:0.4rem}
    .sidebar .list-group-item{margin-bottom:0;border-radius:0.6rem;padding:0.45rem;flex-direction:column;align-items:center;text-align:center;font-size:0.72rem}
    .sidebar .item-label{opacity:1;width:auto;margin-left:0;margin-top:0.2rem;display:block;font-size:0.68rem}
    .sidebar .me-2{margin-right:0!important}
    .content{padding:1rem;margin-bottom:76px}
    .topbar .brand-title{font-size:1.5rem}
    .topbar .brand-subtitle{display:none}
    .topbar .bar-inner{min-height:64px}
    .brand-logo{height:40px}
}
/* Limit dashboard scroll by constraining long tables */
.table-scroll-clip{max-height:240px;overflow-y:auto}
/* Ensure badges/text stay compact in KPIs */
.kpi-foot strong{font-weight:700}
/* Global Soft Edges */
.btn{border-radius:1rem;padding-top:.6rem;padding-bottom:.6rem;font-weight:500}
.form-control,.form-select{border-radius:1rem;padding:.6rem 1rem}
.dropdown-menu{border-radius:1rem;border:none;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -2px rgba(0,0,0,0.05)}
/* Topbar & Sidebar */
.topbar{background:rgba(255,255,255,0.8);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid rgba(255,255,255,0.3);box-shadow:0 4px 6px -1px rgba(31,38,135,0.05);z-index:50}
.topbar .brand-title,.topbar .brand-subtitle,.topbar .role-label{user-select:none;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none}
.topbar .brand-title{font-weight:800;font-size:2.2rem;letter-spacing:.6px;color:var(--accent);margin-bottom:0;line-height:1;text-shadow:0 2px 4px rgba(var(--accent-rgb),0.2)}
.topbar .brand-subtitle{font-size:0.65rem;color:#6b7280;font-weight:500;letter-spacing:0.5px;text-transform:uppercase}
.topbar .role-label{font-weight:700;margin-top:.05rem;color:#111827}
.topbar .top-clock{color:#374151;font-weight:600;display:inline-block}
.topbar .bar-inner{min-height:88px}
.brand-logo{height:64px}
.icon-btn{border:none;background:transparent;padding:.5rem;border-radius:1rem;transition:background .2s}
.icon-btn:hover{background:#f3f4f6;color:var(--accent)}
.sidebar{width:88px;background:#fff;border-right:none;box-shadow:4px 0 24px rgba(0,0,0,0.02);transition:width .3s cubic-bezier(.4,0,.2,1);z-index:40}
.sidebar:hover{width:280px}
.sidebar .list-group-item{border:0;border-radius:1rem;margin-bottom:.5rem;padding:.75rem 1rem;color:#4b5563;font-weight:500;transition:all .2s}
.sidebar .list-group-item:hover{background:#f9fafb;color:var(--accent);transform:translateX(3px)}
.sidebar .list-group-item.active{background:rgba(var(--accent-rgb),0.1);color:var(--accent);font-weight:700}
.item-label{opacity:0;width:0;overflow:hidden;transition:opacity .2s ease,width .3s ease;white-space:nowrap;margin-left:.75rem}
.sidebar:hover .item-label{opacity:1;width:auto}
.content{flex:1;padding:2rem}
.dropdown-toggle.no-arrow::after{display:none!important}
/* Mobile Responsive */
@media (max-width: 768px) {
    .layout-wrapper{flex-direction:column}
    .sidebar{width:100%;height:auto;border-right:none;border-bottom:1px solid #e5e7eb;position:fixed;bottom:0;left:0;z-index:1000;box-shadow:0 -4px 6px -1px rgba(0,0,0,0.02)}
    .sidebar:hover{width:100%}
    .sidebar .p-3{display:none} /* Hide profile in mobile sidebar */
    .sidebar .list-group{flex-direction:row;justify-content:space-around;padding:0.5rem}
    .sidebar .list-group-item{margin-bottom:0;border-radius:0.5rem;padding:0.5rem;flex-direction:column;align-items:center;text-align:center;font-size:0.75rem}
    .sidebar .item-label{opacity:1;width:auto;margin-left:0;margin-top:0.25rem;display:block;font-size:0.7rem}
    .sidebar .me-2{margin-right:0!important}
    .content{padding:1rem;margin-bottom:80px} /* Add bottom margin for fixed nav */
    .topbar .brand-title{font-size:1.5rem}
    .topbar .brand-subtitle{display:none}
    .topbar .bar-inner{min-height:64px}
    .brand-logo{height:40px}
}
/* Search Overlay */
#searchOverlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.9);backdrop-filter:blur(5px);z-index:2000;display:none;opacity:0;transition:opacity .3s ease}
#searchOverlay.active{display:flex;opacity:1}
#searchContainer{width:100%;max-width:600px;transform:translateY(-20px);transition:transform .3s cubic-bezier(0.175,0.885,0.32,1.275)}
#searchOverlay.active #searchContainer{transform:translateY(0)}
#searchResults{max-height:60vh;overflow-y:auto}
.search-result-item{padding:1rem;border-bottom:1px solid #f3f4f6;transition:background .2s;display:block;text-decoration:none;color:inherit}
.search-result-item:hover{background:#f9fafb}
/* Profile Picture Styles */
.profile-pic-lg{width:64px;height:64px;border:3px solid #fff;box-shadow:0 0 0 2px var(--accent),0 4px 6px -1px rgba(0,0,0,0.1);transition:transform .3s ease}
.profile-pic-lg:hover{transform:scale(1.05)}
/* Calendar compacting */
.fc{font-size:0.78rem}.fc .fc-toolbar-title{font-size:0.95rem;font-weight:700;color:var(--ink-900)}.fc .fc-button{padding:0.25rem 0.5rem;font-size:0.75rem;border-radius:0.5rem}.fc th{font-weight:600;color:var(--ink-700)}.fc .fc-daygrid-day-number{font-size:0.78rem;color:var(--ink-800);padding:0.2rem}
</style></head><body class="' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '"><a href="#main-content" class="skip-link">Skip to main content</a>';
    $roleLabel = isset($_SESSION['role']) ? ucfirst((string) $_SESSION['role']) : '';
    $logoPath = $settings['general']['logo_path'] ?? '/aims/assets/logo/makilinglogo.png';
    $clockHtml = !empty($settings['features']['topbar_clock']) ? '<span id="top-clock" class="top-clock small me-2" style="min-width:120px"></span>' : '';
    
    // Notifications Logic
    $notifBtn = '';
    if (!empty($settings['features']['notifications'])) {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $notifications = get_unread_notifications($userId);
        $notifCount = count($notifications);
        $badge = $notifCount > 0 ? '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem;padding:0.25em 0.4em;">' . ($notifCount > 9 ? '9+' : $notifCount) . '</span>' : '';
        
        $notifList = '';
        if ($notifCount === 0) {
            $notifList = '<div class="text-center p-3 text-muted small">No new notifications</div>';
        } else {
            foreach ($notifications as $n) {
                $iconType = match($n['type']) {
                    'announcement' => 'announcements', // Map to correct key in icons.php
                    'grade' => 'grade_book', // Map to correct key in icons.php
                    default => 'notifications' // Map to correct key in icons.php
                };
                
                $notifList .= '
                <a class="dropdown-item d-flex align-items-start p-2 border-bottom" href="#">
                    <div class="me-2 mt-1 text-primary">' . icon($iconType) . '</div>
                    <div class="flex-grow-1" style="min-width:0">
                        <div class="fw-semibold small text-truncate">' . htmlspecialchars($n['title']) . '</div>
                        <div class="small text-muted text-truncate">' . htmlspecialchars($n['message']) . '</div>
                        <div class="text-end" style="font-size:0.65rem;color:#9ca3af">' . date('M d, H:i', strtotime($n['created_at'])) . '</div>
                    </div>
                </a>';
            }
            $notifList .= '<div class="text-center p-2"><a href="#" class="small text-decoration-none" onclick="markRead(event)">Mark all as read</a></div>';
        }

        $notifBtn = '
        <div class="dropdown me-2">
            <button class="icon-btn position-relative" id="notifBtn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                ' . icon('notifications') . $badge . '
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm border-0" aria-labelledby="notifBtn" style="width:300px;max-height:400px;overflow-y:auto">
                <div class="px-3 py-2 border-bottom fw-bold text-muted small bg-light">Notifications</div>
                ' . $notifList . '
            </div>
        </div>
        <script>
        function markRead(e) {
            e.preventDefault();
            fetch("../api/mark_read.php", {method:"POST"})
            .then(() => {
                var badge = document.querySelector("#notifBtn .badge");
                if(badge) badge.remove();
                var list = document.querySelector(".dropdown-menu[aria-labelledby=\'notifBtn\']");
                // Clear list but keep header
                var header = list.querySelector(".border-bottom");
                list.innerHTML = "";
                list.appendChild(header);
                list.insertAdjacentHTML("beforeend", "<div class=\"text-center p-3 text-muted small\">No new notifications</div>");
            });
        }
        </script>';
    }

    $searchBtn = '<button class="icon-btn me-2" type="button" aria-label="Search" onclick="toggleSearch()">' . icon('search') . '</button>';
    $settingsLink = 'settings.php';
    echo '<nav class="navbar topbar"><div class="container-fluid bar-inner d-flex justify-content-between align-items-center"><div class="d-flex align-items-center"><img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" alt="" class="me-2 brand-logo"><div class="d-flex flex-column"><span class="brand-title">AIMS</span><span class="brand-subtitle">Academic Information Management System</span><span class="role-label">' . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . '</span></div></div><div class="d-flex align-items-center position-relative">' . $clockHtml . $searchBtn . $notifBtn . '<div class="dropdown"><button class="icon-btn dropdown-toggle no-arrow" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">' . icon('menu') . '</button><ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userDropdown"><li><div class="px-3 py-2 text-muted small">Signed in as <strong>' . htmlspecialchars($username ?? '', ENT_QUOTES, 'UTF-8') . '</strong></div></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item d-flex align-items-center" href="settings.php">' . icon('settings') . '<span class="ms-2">Settings</span></a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item d-flex align-items-center" href="help.php">' . icon('help') . '<span class="ms-2">Help and Support</span></a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item d-flex align-items-center text-danger" href="../logout.php">' . icon('logout') . '<span class="ms-2">Logout</span></a></li></ul></div></div></div></nav>';
    
    // Search Overlay HTML
    echo '
    <div id="searchOverlay" class="justify-content-center align-items-start pt-5" onclick="if(event.target===this)toggleSearch()">
        <div id="searchContainer" class="bg-white rounded-4 shadow-lg p-4 mx-3">
            <div class="input-group input-group-lg mb-3">
                <span class="input-group-text bg-transparent border-0 text-muted">' . icon('search') . '</span>
                <input type="text" id="searchInput" class="form-control border-0 shadow-none" placeholder="Search students, subjects, or teachers..." onkeyup="handleSearch(this.value)">
                <button class="btn btn-link text-muted text-decoration-none" onclick="toggleSearch()">Esc</button>
            </div>
            <div id="searchResults" class="text-center text-muted small">Type to start searching...</div>
        </div>
    </div>
    <script>
    function toggleSearch() {
        var overlay = document.getElementById("searchOverlay");
        var input = document.getElementById("searchInput");
        if(overlay.style.display === "flex") {
            overlay.classList.remove("active");
            setTimeout(() => { overlay.style.display = "none"; }, 300);
        } else {
            overlay.style.display = "flex";
            setTimeout(() => { 
                overlay.classList.add("active"); 
                input.focus();
            }, 10);
        }
    }
    // Expose to global scope for button click
    window.toggleSearch = toggleSearch;
    
    let searchTimeout;
    function handleSearch(query) {
        clearTimeout(searchTimeout);
        if(query.length < 2) {
            document.getElementById("searchResults").innerHTML = "Type to start searching...";
            return;
        }
        searchTimeout = setTimeout(() => {
            // Determine relative path based on current location
            const apiPath = window.location.pathname.includes(\'/admin/\') || window.location.pathname.includes(\'/student/\') || window.location.pathname.includes(\'/teacher/\') ? \'../api/search.php\' : \'api/search.php\';
            
            fetch(apiPath + "?q=" + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                let html = "";
                if(data.length === 0) {
                    html = "<div class=\"p-3\">No results found.</div>";
                } else {
                    data.forEach(item => {
                        let icon = "";
                        if(item.type === "student") icon = "' . icon('students') . '";
                        else if(item.type === "teacher") icon = "' . icon('teachers') . '";
                        else if(item.type === "subject") icon = "' . icon('subjects') . '";
                        
                        html += `<a href="${item.url}" class="search-result-item d-flex align-items-center">
                            <span class="me-3 text-muted">${icon}</span>
                            <div>
                                <div class="fw-bold">${item.title}</div>
                                <div class="small text-muted">${item.subtitle}</div>
                            </div>
                        </a>`;
                    });
                }
                document.getElementById("searchResults").innerHTML = html;
            });
        }, 300);
    }
    // Close on Esc
    document.addEventListener("keydown", function(e) {
        if(e.key === "Escape" && document.getElementById("searchOverlay").style.display === "flex") {
            toggleSearch();
        }
    });
    </script>';

    $photoUrl = profile_photo_url((int)($_SESSION['user_id'] ?? 0));
    $photoHtml = $photoUrl !== '' ? '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" class="rounded-circle profile-pic-lg">' : icon('profile');
    
    // Get Student Number if applicable
    $studentNumberHtml = '';
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
        global $mysqli;
        $uid = (int)$_SESSION['user_id'];
        $snStmt = $mysqli->prepare("SELECT student_number FROM students WHERE user_id = ? LIMIT 1");
        if ($snStmt) {
            $snStmt->bind_param('i', $uid);
            $snStmt->execute();
            $snRes = $snStmt->get_result();
            if ($snRow = $snRes->fetch_assoc()) {
                $studentNumberHtml = '<div class="small text-muted" style="font-size:0.75rem">' . htmlspecialchars($snRow['student_number'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $snStmt->close();
        }
    }

    echo '<div class="layout-wrapper d-flex"><aside class="border-end sidebar d-flex flex-column justify-content-between"><div class="p-3"><div class="d-flex flex-column align-items-center mb-3"><span class="mb-2">' . $photoHtml . '</span><div class="item-label text-center"><div class="fw-semibold">' . htmlspecialchars($username ?? '', ENT_QUOTES, 'UTF-8') . '</div>' . $studentNumberHtml . '<a href="settings.php#profile-section" class="small text-decoration-none">Profile</a></div></div><div class="list-group">';
    foreach ($sidebar as $item) {
        $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8');
        $active = !empty($item['active']) ? ' active' : '';
        $iconName = isset($item['icon']) ? (string) $item['icon'] : '';
        $iconHtml = $iconName !== '' ? icon($iconName) : '';
        echo '<a class="list-group-item list-group-item-action d-flex align-items-center' . $active . '" href="' . $href . '"><span class="me-2">' . $iconHtml . '</span><span class="item-label">' . $label . '</span></a>';
    }
    echo '</div></div></aside><main id="main-content" class="content p-4" tabindex="-1">';
}

function end_layout(): void
{
    echo '</main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script><script>(function(){
var b=document.getElementById("notifBtn");var m=document.getElementById("notifMenu");if(b&&m){b.addEventListener("click",function(e){e.stopPropagation();m.style.display=m.style.display==="none"||m.style.display===""?"block":"none";});document.addEventListener("click",function(){m.style.display="none";});}
function updateClock(){var c=document.getElementById("top-clock");if(!c)return;var now=new Date();c.textContent=now.toLocaleTimeString();}updateClock();setInterval(updateClock,1000);
/* Collapsible dashboard cards (only elements with .card.card-collapsible) */
function resizeChartsIn(el){if(typeof Chart==="undefined"||!Chart.instances)return;var list=Object.values(Chart.instances);for(var i=0;i<list.length;i++){var inst=list[i];if(!inst||!inst.canvas)continue;if(el===document||el.contains(inst.canvas)){try{inst.resize();}catch(e){}}}}
document.addEventListener("click",function(ev){var btn=ev.target.closest(".card-header-btn");if(!btn)return;var card=btn.closest(".card.card-collapsible");if(!card)return;ev.preventDefault();var collapsed=card.classList.toggle("is-collapsed");var expanded=!collapsed;if(btn.setAttribute)btn.setAttribute("aria-expanded",expanded?"true":"false");
var panelId=btn.getAttribute("aria-controls");var panel=panelId?document.getElementById(panelId):card.querySelector(".card-collapse");
if(panel){panel.setAttribute("aria-hidden",collapsed?"true":"false");if(!collapsed){panel.style.display="";}setTimeout(function(){resizeChartsIn(card);},380);}
});
document.addEventListener("keydown",function(ev){if(ev.key!=="Enter"&&ev.key!==" ")return;var t=ev.target;if(!t)return;var card=t.closest&&t.closest(".card-header-btn");if(!card)return;ev.preventDefault();t.click&&t.click();});
})();</script></body></html>';
}
