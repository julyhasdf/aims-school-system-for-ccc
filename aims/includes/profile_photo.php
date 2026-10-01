<?php
declare(strict_types=1);
function profile_photo_url(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $base = __DIR__ . '/../assets/profile/';
    $jpg  = $base . $userId . '.jpg';
    $png  = $base . $userId . '.png';
    if (is_file($jpg)) {
        return '/aims/assets/profile/' . $userId . '.jpg';
    }
    if (is_file($png)) {
        return '/aims/assets/profile/' . $userId . '.png';
    }
    return '';
}
