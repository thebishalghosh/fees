<?php
declare(strict_types=1);

require_once __DIR__ . '/header.php';

function render_primary_nav(string $context = 'public'): void
{
    render_site_header($context);
}
