<?php
declare(strict_types=1);
function get_settings(): array
{
    $path = __DIR__ . '/site_settings.json';
    if (is_file($path)) {
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return [
        'general' => [
            'site_name' => 'AIMS',
            'logo_path' => '/aims/assets/logo/makilinglogo.png',
        ],
        'theme' => [
            'student' => ['accent' => '#22c55e', 'hover' => '#16a34a'],
            'teacher' => ['accent' => '#fbbf24', 'hover' => '#f59e0b'],
            'admin'   => ['accent' => '#3b82f6', 'hover' => '#2563eb'],
        ],
        'features' => [
            'topbar_clock' => true,
            'student_sidebar_calendar' => true,
            'notifications' => true,
        ],
        'school' => [
            'current_year' => '',
        ],
        'smtp' => [
            'host' => '',
            'port' => '',
            'username' => '',
            'password' => '',
        ],
        'security' => [
            'password_min_length' => 8,
        ],
    ];
}
function save_settings(array $settings): bool
{
    $path = __DIR__ . '/site_settings.json';
    $json = json_encode($settings, JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return (bool) file_put_contents($path, $json);
}
