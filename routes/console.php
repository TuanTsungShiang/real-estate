<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('real-estate:import --fresh')->monthlyOn(1, '03:00')->withoutOverlapping();
