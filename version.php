<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'filter_playerhud';
$plugin->version   = 2024011201;
$plugin->requires  = 2022112800;
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.1';
// IMPORTANTE: Este filtro depende do mod existir para buscar dados
$plugin->dependencies = ['mod_playerhud' => 2024011202];