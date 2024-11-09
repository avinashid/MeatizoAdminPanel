<?php

use Illuminate\Support\Facades\Artisan;

function beforeUpdate(): bool
{
    Artisan::call('backup::db');
    return true;
}


function main(): bool
{
    Artisan::call('migrate --force');

    // Run composer update
    exec('composer update');

    return true;
}

?>
