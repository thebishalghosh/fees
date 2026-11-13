<?php
declare(strict_types=1);

require_once __DIR__ . '/../../services/admission-conversion-service.php';

$application = [
    'academic_year_id' => 4,
    'requested_academic_group_id' => 31,
    'course_id' => 12,
    'class_id' => 8,
    'batch_id' => 7,
];
$placements = [
    ['id' => 31, 'course_id' => null, 'class_id' => 8, 'batch_id' => null, 'label' => 'Matching'],
    ['id' => 32, 'course_id' => null, 'class_id' => 9, 'batch_id' => null, 'label' => 'Other class'],
    ['id' => 33, 'course_id' => null, 'class_id' => 8, 'batch_id' => null, 'label' => 'Other section'],
];
$matching = admission_conversion_matching_placements($application, $placements, 4, 'school');
if (count($matching) !== 2
    || array_map(static fn (array $placement): int => (int) $placement['id'], $matching) !== [31, 33]) {
    throw new RuntimeException('Placements must match the academic selection on the application.');
}
if ((int) admission_conversion_select_placement($application, $placements, 4, 'school')['id'] !== 31) {
    throw new RuntimeException('Prefer the application’s requested placement when it is still active.');
}
if (admission_conversion_matching_placements($application, $placements, 5, 'school') !== []) {
    throw new RuntimeException('Placements from a different academic year must not be offered.');
}

$application['requested_academic_group_id'] = null;
$withPreferredPlacement = [
    ...$placements,
    ['id' => 34, 'course_id' => null, 'class_id' => 9, 'batch_id' => null, 'label' => 'Preferred'],
];
$application['requested_academic_group_id'] = 34;
$preferred = admission_conversion_matching_placements($application, $withPreferredPlacement, 4, 'school');
if (array_map(static fn (array $placement): int => (int) $placement['id'], $preferred) !== [31, 33]) {
    throw new RuntimeException('An unavailable preferred placement must not exclude matching alternatives.');
}
if ((int) admission_conversion_select_placement($application, $withPreferredPlacement, 4, 'school')['id'] !== 31) {
    throw new RuntimeException('Automatically select the first matching active placement when the preference is unavailable.');
}

$application['class_id'] = 9;
$preferred = admission_conversion_matching_placements($application, $withPreferredPlacement, 4, 'school');
if (count($preferred) !== 2
    || array_map(static fn (array $placement): int => (int) $placement['id'], $preferred) !== [32, 34]) {
    throw new RuntimeException('Offer every available placement for the selected class.');
}

fwrite(STDOUT, "Admission placement matching checks passed." . PHP_EOL);
