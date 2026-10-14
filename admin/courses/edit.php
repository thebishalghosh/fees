<?php
declare(strict_types=1);

$requestedCourseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$adminCourseId = is_int($requestedCourseId) && $requestedCourseId > 0 ? $requestedCourseId : -1;
require_once __DIR__ . '/../../includes/admin-course-form.php';
