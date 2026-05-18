<?php

use Illuminate\Support\Facades\Artisan;

// Closure-based artisan-команды регистрируются через Artisan::command().
// Наша основная команда notifications:consume определена классом в
// app/Console/Commands/ — auto-discovery подберёт её без регистрации здесь.
