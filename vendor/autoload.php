<?php
/**
 * Simple PSR-4 Autoloader for XAMPP
 * No Composer required - Self-contained autoloader
 * 
 * Place this file at vendor/autoload.php
 * Include it in your entry point: require_once __DIR__ . '/../vendor/autoload.php';
 */

// Define namespace to directory mappings
$namespaceMap = [
    'App\\' => __DIR__ . '/../app/',
];

// Register autoloader
spl_autoload_register(function ($class) use ($namespaceMap) {
    foreach ($namespaceMap as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    return false;
});

// Also support legacy class names without namespace (if any)
spl_autoload_register(function ($class) {
    // Check for classes in app root
    $file = __DIR__ . '/../app/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
});

// Compatibility: Define a simple class for older code if needed
if (!class_exists('Dotenv\\Dotenv')) {
    // Provide a no-op Dotenv class if code tries to use it
    class Dotenv {
        public static function createImmutable($path) {
            return new class {
                public function safeLoad() {}
                public function load() {}
            };
        }
    }
}