<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/admin-panel-service.php';
require_once __DIR__ . '/../services/institution-branding-service.php';

$checks = 0;
$failures = [];
$check = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(admin_panel_decimal_cents('12') === 1200, 'Convert whole-unit amounts to cents');
$check(admin_panel_decimal_cents('12.3') === 1230, 'Normalize one decimal place to cents');
$check(admin_panel_decimal_cents('12.34') === 1234, 'Preserve two decimal places exactly');
$check(
    admin_panel_net_payment_amount(['amount' => '100.00', 'refunded_amount' => '20.25', 'status' => 'partial_refund']) === '79.75',
    'Subtract processed refunds using integer-cent arithmetic'
);
$check(
    admin_panel_net_payment_amount(['amount' => '100.00', 'refunded_amount' => '100.00', 'status' => 'refunded']) === '0.00',
    'Treat a fully refunded payment as zero net collection'
);

$validInstitution = admin_panel_validate_institution([
    'name' => 'Example Academy',
    'country' => 'India',
    'institution_type' => 'school',
    'currency_code' => 'INR',
    'email' => 'office@example.test',
    'website' => 'https://example.test',
]);
$check($validInstitution['name'] === 'Example Academy', 'Accept a valid institution profile');
$check($validInstitution['short_name'] === null, 'Normalize omitted optional profile values to null');
$check(
    $validInstitution['institution_type'] === 'school'
        && $validInstitution['currency_code'] === 'INR'
        && $validInstitution['established_year'] === null,
    'Validate and normalize institution type, currency, and optional established year'
);
$invalidInstitutionCodeRejected = false;
try {
    admin_panel_validate_institution([
        'name' => 'Example Academy',
        'country' => 'India',
        'institution_type' => 'school',
        'code' => 'CODE WITH SPACES',
    ]);
} catch (InvalidArgumentException) {
    $invalidInstitutionCodeRejected = true;
}
$check($invalidInstitutionCodeRejected, 'Reject profile institution codes outside the staff login format');

$invalidInstitutionTypeRejected = false;
try {
    admin_panel_validate_institution([
        'name' => 'Example Academy',
        'country' => 'India',
        'institution_type' => 'training-centre',
    ]);
} catch (InvalidArgumentException) {
    $invalidInstitutionTypeRejected = true;
}
$check($invalidInstitutionTypeRejected, 'Reject institution types outside the supported list');

$invalidInstitutionCurrencyRejected = false;
try {
    admin_panel_validate_institution([
        'name' => 'Example Academy',
        'country' => 'India',
        'institution_type' => 'school',
        'currency_code' => 'IN',
    ]);
} catch (InvalidArgumentException) {
    $invalidInstitutionCurrencyRejected = true;
}
$check($invalidInstitutionCurrencyRejected, 'Reject unsupported institution currency codes');

$logoFixture = tempnam(sys_get_temp_dir(), 'institution-logo-test-');
if ($logoFixture === false) {
    throw new RuntimeException('Could not create a temporary institution-logo fixture.');
}
$logoImage = imagecreatetruecolor(8, 5);
imagefilledrectangle($logoImage, 0, 0, 7, 4, imagecolorallocate($logoImage, 36, 75, 124));
imagepng($logoImage, $logoFixture);
imagedestroy($logoImage);
$logoUpload = [
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $logoFixture,
    'name' => 'untrusted-name.php',
    'type' => 'image/png',
    'size' => filesize($logoFixture),
];
$validLogo = institution_branding_validate_logo_upload($logoUpload, false);
$check(
    $validLogo['mime_type'] === 'image/png' && $validLogo['width'] === 8 && $validLogo['height'] === 5,
    'Validate actual PNG logo content and dimensions'
);
$jpegFixture = tempnam(sys_get_temp_dir(), 'institution-logo-jpeg-');
if ($jpegFixture === false) {
    throw new RuntimeException('Could not create a temporary JPEG fixture.');
}
$jpegImage = imagecreatetruecolor(6, 4);
imagejpeg($jpegImage, $jpegFixture);
imagedestroy($jpegImage);
$jpegLogo = institution_branding_validate_logo_upload([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $jpegFixture,
    'name' => 'institution.jpg',
    'type' => 'image/jpeg',
    'size' => filesize($jpegFixture),
], false);
$check($jpegLogo['mime_type'] === 'image/jpeg', 'Accept JPEG logos based on actual file contents');
$storedLogoPath = institution_branding_store_logo($logoUpload, false);
$storedLogo = institution_branding_logo_file($storedLogoPath);
$check(
    preg_match('#^storage/institution-logos/[a-f0-9]{32}\.png$#D', $storedLogoPath) === 1
        && is_array($storedLogo)
        && str_ends_with($storedLogo['path'], '.png'),
    'Store logos with a randomized server-generated filename in private storage'
);
institution_branding_delete_logo_file($storedLogoPath);
$check(institution_branding_logo_file($storedLogoPath) === null, 'Do not resolve a removed institution logo');
$check(institution_branding_logo_file('storage/institution-logos/../../config/app.php') === null, 'Reject unsafe logo paths');

$gifFixture = tempnam(sys_get_temp_dir(), 'institution-logo-gif-');
if ($gifFixture === false) {
    throw new RuntimeException('Could not create a temporary GIF fixture.');
}
$gifImage = imagecreatetruecolor(2, 2);
imagegif($gifImage, $gifFixture);
imagedestroy($gifImage);
$invalidLogoRejected = false;
try {
    institution_branding_validate_logo_upload([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $gifFixture,
        'name' => 'logo.gif',
        'type' => 'image/png',
        'size' => filesize($gifFixture),
    ], false);
} catch (InvalidArgumentException) {
    $invalidLogoRejected = true;
}
$check($invalidLogoRejected, 'Reject image formats other than PNG and JPEG regardless of browser MIME');

$malformedFixture = tempnam(sys_get_temp_dir(), 'institution-logo-malformed-');
if ($malformedFixture === false) {
    throw new RuntimeException('Could not create a temporary malformed-image fixture.');
}
file_put_contents($malformedFixture, 'not an image');
$malformedLogoRejected = false;
try {
    institution_branding_validate_logo_upload([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $malformedFixture,
        'name' => 'broken.png',
        'type' => 'image/png',
        'size' => filesize($malformedFixture),
    ], false);
} catch (InvalidArgumentException) {
    $malformedLogoRejected = true;
}
$check($malformedLogoRejected, 'Reject malformed images with misleading filename and browser MIME');

$dimensionFixture = tempnam(sys_get_temp_dir(), 'institution-logo-dimensions-');
if ($dimensionFixture === false) {
    throw new RuntimeException('Could not create a temporary oversized-dimensions fixture.');
}
$dimensionImage = imagecreatetruecolor(INSTITUTION_LOGO_MAX_DIMENSION + 1, 1);
imagepng($dimensionImage, $dimensionFixture);
imagedestroy($dimensionImage);
$oversizedDimensionsRejected = false;
try {
    institution_branding_validate_logo_upload([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $dimensionFixture,
    ], false);
} catch (InvalidArgumentException) {
    $oversizedDimensionsRejected = true;
}
$check($oversizedDimensionsRejected, 'Reject logo images with excessive dimensions');

$oversizedFixture = tempnam(sys_get_temp_dir(), 'institution-logo-large-');
if ($oversizedFixture === false) {
    throw new RuntimeException('Could not create a temporary oversized-logo fixture.');
}
file_put_contents($oversizedFixture, str_repeat('x', INSTITUTION_LOGO_MAX_BYTES + 1));
$oversizedLogoRejected = false;
try {
    institution_branding_validate_logo_upload([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $oversizedFixture,
    ], false);
} catch (InvalidArgumentException) {
    $oversizedLogoRejected = true;
}
$check($oversizedLogoRejected, 'Reject institution logo uploads larger than 2 MB');
@unlink($logoFixture);
@unlink($jpegFixture);
@unlink($gifFixture);
@unlink($malformedFixture);
@unlink($dimensionFixture);
@unlink($oversizedFixture);

$settingsRoute = file_get_contents(__DIR__ . '/../admin/settings/institution.php');
$logoEndpoint = file_get_contents(__DIR__ . '/../admin/institution-logo.php');
$parentLogoEndpoint = file_get_contents(__DIR__ . '/../parent/institution-logo.php');
$check(
    is_string($settingsRoute)
        && str_contains($settingsRoute, 'require_valid_csrf_token()')
        && str_contains($settingsRoute, 'institution_branding_store_logo')
        && str_contains($settingsRoute, 'admin_panel_update_institution_logo')
        && str_contains($settingsRoute, "action'] : 'save_profile'"),
    'Protect logo upload and removal with the existing settings authorization and CSRF handler'
);
$check(
    is_string($logoEndpoint)
        && str_contains($logoEndpoint, 'require_placeholder_route_access()')
        && str_contains($parentLogoEndpoint, 'require_placeholder_route_access()')
        && str_contains($parentLogoEndpoint, "'user_type'] !== 'parent'")
        && str_contains($parentLogoEndpoint, '$user[\'institution_id\']'),
    'Enforce authenticated institution-scoped access to admin and parent logo responses'
);

$invalidEmailRejected = false;
try {
    admin_panel_validate_institution([
        'name' => 'Example Academy',
        'country' => 'India',
        'institution_type' => 'school',
        'email' => 'invalid',
    ]);
} catch (InvalidArgumentException) {
    $invalidEmailRejected = true;
}
$check($invalidEmailRejected, 'Reject invalid institution email addresses');

$unsafeWebsiteRejected = false;
try {
    admin_panel_validate_institution([
        'name' => 'Example Academy',
        'country' => 'India',
        'institution_type' => 'school',
        'website' => 'javascript:alert(1)',
    ]);
} catch (InvalidArgumentException) {
    $unsafeWebsiteRejected = true;
}
$check($unsafeWebsiteRejected, 'Reject non-HTTP institution website schemes');

$courseValues = admin_panel_validate_course([
    'name' => 'Mathematics',
    'code' => 'MATH',
    'description' => '',
    'duration_months' => '12',
    'status' => 'active',
]);
$check($courseValues['duration_months'] === 12, 'Normalize a valid course duration');

$invalidCourseRejected = false;
try {
    admin_panel_validate_course([
        'name' => '',
        'code' => 'BAD',
        'description' => '',
        'duration_months' => '12',
        'status' => 'active',
    ]);
} catch (InvalidArgumentException) {
    $invalidCourseRejected = true;
}
$check($invalidCourseRejected, 'Reject invalid course form values');

$classValues = admin_panel_validate_class([
    'name' => '  Grade 8  ',
    'code' => '',
    'description' => 'Middle school',
    'status' => 'active',
]);
$check(
    $classValues['name'] === 'Grade 8' && $classValues['code'] === null
        && $classValues['status'] === 'active',
    'Normalize valid class details and require an explicit selected status'
);

foreach ([
    ['name' => '', 'code' => '', 'description' => '', 'status' => 'active'],
    ['name' => str_repeat('x', 121), 'code' => '', 'description' => '', 'status' => 'active'],
    ['name' => 'Grade 8', 'code' => '', 'description' => '', 'status' => 'draft'],
    ['name' => 'Grade 8', 'code' => '', 'description' => '', 'status' => ''],
    ['name' => ['Grade 8'], 'code' => '', 'description' => '', 'status' => 'active'],
] as $invalidClassInput) {
    $invalidClassRejected = false;
    try {
        admin_panel_validate_class($invalidClassInput);
    } catch (InvalidArgumentException) {
        $invalidClassRejected = true;
    }
    $check($invalidClassRejected, 'Reject invalid class form values');
}

$batchValues = admin_panel_validate_batch([
    'name' => 'Morning batch',
    'code' => '',
    'course_id' => '1',
    'class_id' => '2',
    'start_time' => '09:00',
    'end_time' => '10:00',
    'capacity' => '30',
    'status' => 'active',
]);
$check($batchValues['start_time'] === '09:00:00', 'Normalize valid batch times for MySQL');

$invalidBatchTimesRejected = false;
try {
    admin_panel_validate_batch([
        'name' => 'Invalid batch',
        'course_id' => '1',
        'class_id' => '2',
        'start_time' => '09:00',
        'end_time' => '09:00',
        'capacity' => '',
        'status' => 'active',
    ]);
} catch (InvalidArgumentException) {
    $invalidBatchTimesRejected = true;
}
$check($invalidBatchTimesRejected, 'Reject non-positive batch time ranges');

$batchCreateRoute = file_get_contents(__DIR__ . '/../admin/batches/create.php');
$courseCreateRoute = file_get_contents(__DIR__ . '/../admin/courses/create.php');
$batchForm = file_get_contents(__DIR__ . '/../includes/admin-batch-form.php');
$courseForm = file_get_contents(__DIR__ . '/../includes/admin-course-form.php');
$classCreateRoute = file_get_contents(__DIR__ . '/../admin/academics/create-class.php');
$adminList = file_get_contents(__DIR__ . '/../includes/admin-simple-list.php');
$adminPanelService = file_get_contents(__DIR__ . '/../services/admin-panel-service.php');
$academicManagementService = file_get_contents(__DIR__ . '/../services/academic-management-service.php');
$sidebarLayout = file_get_contents(__DIR__ . '/../includes/layouts/sidebar.php');
$academicRoutes = [
    file_get_contents(__DIR__ . '/../admin/academics/years.php'),
    file_get_contents(__DIR__ . '/../admin/academics/classes.php'),
    file_get_contents(__DIR__ . '/../admin/academics/programmes.php'),
    file_get_contents(__DIR__ . '/../admin/academics/subjects.php'),
    file_get_contents(__DIR__ . '/../admin/academics/enrolments.php'),
    file_get_contents(__DIR__ . '/../admin/academics/attendance.php'),
];
$rewriteRules = file_get_contents(__DIR__ . '/../.htaccess');
$check(
    is_string($batchCreateRoute)
        && is_string($courseCreateRoute)
        && is_string($batchForm)
        && is_string($courseForm)
        && str_contains($batchCreateRoute, '$adminBatchId = null;')
        && str_contains($courseCreateRoute, '$adminCourseId = null;')
        && str_contains($batchForm, "array_key_exists('adminBatchId', \$GLOBALS)")
        && str_contains($courseForm, "array_key_exists('adminCourseId', \$GLOBALS)"),
    'Accept explicitly configured null IDs for both create routes'
);
$check(
    is_string($classCreateRoute)
        && str_contains($classCreateRoute, 'require_placeholder_route_access()')
        && str_contains($classCreateRoute, 'require_valid_csrf_token()')
        && str_contains($classCreateRoute, 'academic_management_resolve_institution_id')
        && str_contains($classCreateRoute, 'institution_id')
        && str_contains($classCreateRoute, "route_url('/admin/academics/classes/create')")
        && str_contains($classCreateRoute, 'admin_panel_create_class'),
    'Protect class creation with existing authorization, CSRF, and institution context at the canonical URL'
);
$check(
    is_string($adminPanelService)
        && str_contains($adminPanelService, 'WHERE institution_id = ? AND (name = ?')
        && str_contains($adminPanelService, 'INSERT INTO classes (institution_id, name, code, description, status)')
        && str_contains($adminPanelService, "'class.created'")
        && is_string($adminList)
        && str_contains($adminList, "'classes' => '/admin/academics/classes/create'")
        && is_string($rewriteRules)
        && str_contains($rewriteRules, 'RewriteRule ^admin/academics/classes/create/?$ admin/academics/create-class.php'),
    'Create classes within their institution, reject duplicates, audit creation, and link the list action'
);
$check(
    str_contains($batchForm, '$classes = admin_panel_simple_list($db, $institutionId, \'classes\')')
        && str_contains($adminPanelService, 'SELECT id, name, code, status, created_at FROM classes WHERE institution_id = ?'),
    'Make institution-scoped new classes available in the existing batch form'
);
$check(
    str_contains($adminPanelService, "if (\$academicModel === 'school')")
        && str_contains($adminPanelService, "in_array(\$academicModel, ['college', 'university'], true)")
        && str_contains($adminPanelService, 'academic administration uses classes and sections instead of courses')
        && str_contains($adminPanelService, 'placement uses academic terms instead of batches'),
    'Enforce model-specific restrictions on direct course and batch writes'
);
$check(
    is_string($sidebarLayout)
        && str_contains($sidebarLayout, 'information_schema.columns')
        && str_contains($sidebarLayout, "column_name = 'academic_model'")
        && str_contains($sidebarLayout, 'if ($hasAcademicModel)'),
    'Keep the admin navigation usable before the academic-model migration is applied'
);
$check(
    is_array($academicRoutes)
        && !in_array(false, $academicRoutes, true)
        && count(array_filter(
            $academicRoutes,
            static fn(string $route): bool => str_contains($route, 'academic_management_schema_ready($db)')
                && str_contains($route, 'academic_management_render_schema_required_page()')
        )) === count($academicRoutes)
        && is_string($academicManagementService)
        && str_contains($academicManagementService, 'database/migrations/20261104_add_academic_management.sql'),
    'Explain the required migration instead of raising an uncaught error on academic pages'
);
$classManagementPage = is_array($academicRoutes) ? ($academicRoutes[1] ?? null) : null;
$classManagementCss = file_get_contents(__DIR__ . '/../public/assets/css/pages/admin-academic-classes.css');
$classCreationPage = file_get_contents(__DIR__ . '/../admin/academics/create-class.php');
$authRoutes = file_get_contents(__DIR__ . '/../includes/auth.php');
$check(
    is_string($classManagementPage)
        && str_contains($classManagementPage, 'Add a class for this year')
        && str_contains($classManagementPage, 'academic-classes-create-button')
        && str_contains($classManagementPage, 'Create class</a>')
        && str_contains($classManagementPage, 'Add a section to')
        && str_contains($classManagementPage, 'year_id')
        && str_contains($classManagementPage, 'offeringGroups')
        && str_contains($classManagementPage, 'institution_id')
        && str_contains($classManagementPage, '$createClassUrl')
        && str_contains($classManagementPage, 'update_class')
        && str_contains($classManagementPage, 'update_section')
        && str_contains($classManagementPage, 'Inactive (archive)')
        && is_string($classManagementCss)
        && str_contains($classManagementCss, '@media (max-width: 560px)')
        && is_string($classCreationPage)
        && str_contains($classCreationPage, 'institution_id')
        && str_contains($academicManagementService, 'academic_management_resolve_institution_id')
        && str_contains($academicManagementService, 'academic_management_institution_options')
        && str_contains($adminPanelService, 'function admin_panel_update_class')
        && str_contains($academicManagementService, 'function academic_management_update_section')
        && str_contains($sidebarLayout, "!== 'super_admin'")
        && str_contains($sidebarLayout, "\$href === '/admin/academics/classes'")
        && is_string($authRoutes)
        && str_contains($authRoutes, "'academics/classes.php', 'academics/create-class.php'"),
    'Guide school users through class/section management and let super admins select the institution'
);

$feeStructureValues = admin_panel_validate_fee_structure([
    'name' => 'Annual fee',
    'description' => '',
    'academic_year_id' => '1',
    'course_id' => '',
    'class_id' => '',
    'status' => 'draft',
    'category_ids' => ['3'],
    'items' => ['3' => '1250.50'],
]);
$check($feeStructureValues['total_amount'] === '1250.50', 'Calculate fee structure totals from selected category items');
$customComponent = admin_panel_validate_fee_structure([
    'name' => 'Term fee',
    'description' => '',
    'target_scope' => 'class:1:2:3',
    'fee_group_code' => 'STANDARD',
    'effective_from' => '2026-04-01',
    'effective_until' => '',
    'status' => 'active',
    'category_ids' => ['3'],
    'items' => ['3' => '1250.50'],
    'frequencies' => ['3' => 'custom'],
    'component_schedules' => ['3' => "2026-04-15, 500.00\n2026-07-15, 750.50"],
]);
$check(
    $customComponent['target_model'] === 'class'
        && $customComponent['academic_year_id'] === 1
        && $customComponent['class_id'] === 2
        && $customComponent['section_id'] === 3
        && count($customComponent['items'][0]['schedule']) === 2,
    'Validate a section-scoped custom fee schedule and preserve its component entries'
);
$customComponentMismatchRejected = false;
try {
    admin_panel_validate_fee_structure([
        'name' => 'Term fee',
        'description' => '',
        'academic_year_id' => '1',
        'course_id' => '',
        'class_id' => '',
        'status' => 'active',
        'category_ids' => ['3'],
        'items' => ['3' => '100.00'],
        'frequencies' => ['3' => 'custom'],
        'component_schedules' => ['3' => "2026-04-15, 40.00\n2026-07-15, 50.00"],
    ]);
} catch (InvalidArgumentException) {
    $customComponentMismatchRejected = true;
}
$check($customComponentMismatchRejected, 'Reject custom component schedules whose amounts do not total the fee');

$feeCategoryValues = admin_panel_validate_fee_category([
    'name' => 'Laboratory',
    'code' => 'LAB',
    'description' => '',
    'is_mandatory' => '1',
]);
$check($feeCategoryValues['is_mandatory'] === 1, 'Normalize fee category mandatory status');

$academicYearValues = admin_panel_validate_academic_year([
    'name' => '2026-2027',
    'start_date' => '2026-04-01',
    'end_date' => '2027-03-31',
]);
$check($academicYearValues['end_date'] === '2027-03-31', 'Accept a valid academic-year date range');

$feeStructureForm = file_get_contents(__DIR__ . '/../includes/admin-fee-structure-form.php');
$createFeeStructureRoute = file_get_contents(__DIR__ . '/../admin/fees/create-structure.php');
$check(
    is_string($feeStructureForm)
        && is_string($createFeeStructureRoute)
        && str_contains($createFeeStructureRoute, '$adminFeeStructureId = null;')
        && str_contains($feeStructureForm, "array_key_exists('adminFeeStructureId', \$GLOBALS)")
        && !str_contains($feeStructureForm, '!isset($adminFeeStructureId)'),
    'Allow a deliberately null fee structure ID on the create form while detecting missing configuration'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Passed ' . $checks . ' admin panel checks.' . PHP_EOL);
