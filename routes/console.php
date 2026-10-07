<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('agent-audit:health', function () {
    $this->info('Agent Audit Platform is bootable.');
})->purpose('Check application boot health');
