<?php
/**
 * Bootstrap - Load environment variables and helper functions
 * This file is loaded by the entry point (public/index.php)
 */

// Load the env loader function
require_once __DIR__ . '/env.php';

// Load helper functions
require_once __DIR__ . '/../app/Helpers/functions.php';

// Load .env file automatically when this file is included
loadEnv();
