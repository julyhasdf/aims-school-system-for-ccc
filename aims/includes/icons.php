<?php
declare(strict_types=1);

function icon(string $name): string
{
    $size = 24;
    $map = [
        'dashboard'    => 'house-regular-full.svg',
        'classes'      => 'calendar-regular-full.svg',
        'attendance'   => 'list-check-solid-full.svg',
        'grades'       => 'chart-column-solid-full.svg',
        'reports'      => 'chart-column-solid-full.svg',
        'students'     => 'graduation-cap-solid-full.svg',
        'teachers'     => 'chalkboard-user-solid-full.svg',
        'subjects'     => 'list-check-solid-full.svg',
        'sections'     => 'school-solid-full.svg',
        'users'        => 'address-book-regular-full.svg',
        'profile'      => 'user-solid-full.svg',
        'eye'          => 'eye-regular-full.svg',
        'notifications'=> 'bell-regular-full.svg',
        'announcements'=> 'bullhorn-solid-full.svg',
        'help'         => 'question-solid-full.svg',
        'settings'     => 'gear-solid-full.svg',
        'logout'       => 'right-to-bracket-solid-full.svg',
        'search'       => 'magnifying-glass-solid-full.svg',
        'menu'         => 'bars-solid-full.svg',
        'grade_book'   => 'book-solid-full.svg',
    ];
    $file = $map[$name] ?? ($name . '.svg');
    $fsPath = __DIR__ . '/../assets/icons/' . $file;
    if (!file_exists($fsPath)) {
        // Fallback: check if $name is a key in the map
        if (isset($map[$name])) {
            $file = $map[$name];
            $fsPath = __DIR__ . '/../assets/icons/' . $file;
        }
    }
    
    if (is_file($fsPath)) {
        $svg = file_get_contents($fsPath);
        // Remove XML declaration if present
        $svg = preg_replace('/<\?xml.*?\?>/', '', $svg);
        // Ensure width/height 100% and fill currentColor
        $svg = str_replace('<svg ', '<svg style="width:100%;height:100%;fill:currentColor;" ', $svg);
        
        return '<span class="d-inline-flex align-items-center justify-content-center" style="width:' . $size . 'px;height:' . $size . 'px;color:currentColor;vertical-align:middle;">' . $svg . '</span>';
    }
    
    // Fallback placeholder
    return '<span style="display:inline-block;width:' . $size . 'px;height:' . $size . 'px;background:#6b7280;border-radius:4px"></span>';
}
