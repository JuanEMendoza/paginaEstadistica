<?php
// Script de prueba de conexión - Usa esto para diagnosticar problemas
// Elimina este archivo después de solucionar el problema

// Cargar variables de entorno
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Obtener variables
$servername = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? "gondola.proxy.rlwy.net";
$username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? "root";
$password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?? "";
$database = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? "railway";
$port = isset($_ENV['DB_PORT']) ? (int)$_ENV['DB_PORT'] : (int)(getenv('DB_PORT') ?: 45154);

echo "<h2>Diagnóstico de Conexión</h2>";
echo "<pre>";
echo "DB_HOST: " . ($servername ?: "NO DEFINIDO") . "\n";
echo "DB_USER: " . ($username ?: "NO DEFINIDO") . "\n";
echo "DB_PASSWORD: " . (strlen($password) > 0 ? "***" : "NO DEFINIDO") . "\n";
echo "DB_NAME: " . ($database ?: "NO DEFINIDO") . "\n";
echo "DB_PORT: " . $port . "\n\n";

// Intentar conexión simple
echo "Intentando conexión simple...\n";
$conn = @new mysqli($servername, $username, $password, $database, $port);
if ($conn->connect_error) {
    echo "ERROR: " . $conn->connect_error . "\n\n";
} else {
    echo "✓ Conexión simple exitosa\n\n";
    $conn->close();
}

// Intentar con SSL
echo "Intentando conexión con SSL...\n";
$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

if (mysqli_real_connect($conn, $servername, $username, $password, $database, $port, NULL, MYSQLI_CLIENT_SSL)) {
    echo "✓ Conexión con SSL exitosa\n";
    $conn->close();
} else {
    echo "ERROR: " . mysqli_connect_error() . "\n";
}

echo "</pre>";
?>

