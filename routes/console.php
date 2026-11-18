<?php

use Illuminate\Support\Facades\Schedule;

// Webhook delivery isn't guaranteed, so once a minute look up any generation
// that Pixelforge has gone quiet about.
Schedule::command('pixelforge:sync')->everyMinute()->withoutOverlapping();
