<?php
declare(strict_types=1);

function render_alert_block(): string
{
    return render_flash_messages();
}
