<?php
// Datos de conexión a Railway (actualizados)
$servername = "shuttle.proxy.rlwy.net";
$username = "root";
$password = "HYxtXzGVoWFQYPDuePQdYAslPjOyVhwS";
$database = "railway";
$port = 55685;

// Configuración de timeouts
ini_set('mysqli.default_socket', '');
ini_set('default_socket_timeout', 30);
ini_set('mysqli.connect_timeout', 30);

// Intentar conexión simple primero
$conn = @new mysqli($servername, $username, $password, $database, $port);

if ($conn->connect_error) {
    // Si falla la conexión simple, intentar con SSL (Railway requiere SSL para conexiones externas)
    $conn = mysqli_init();
    
    // Configurar opciones SSL
    mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
    
    // Configurar timeouts
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 30);
    mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 30);
    
    // Intentar conexión con SSL
    if (!mysqli_real_connect($conn, $servername, $username, $password, $database, $port, NULL, MYSQLI_CLIENT_SSL)) {
        die("Error de conexión a la base de datos: " . mysqli_connect_error() . 
            "<br><br>Verifica que:<br>" .
            "1. Las credenciales sean correctas<br>" .
            "2. Railway permita conexiones externas<br>" .
            "3. Tu conexión a Internet esté activa");
    }
}

// Configurar charset UTF-8
$conn->set_charset("utf8mb4");

// Crear tabla actualizada si no existe
$conn->query("
CREATE TABLE IF NOT EXISTS pruebas_hipotesis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    pasos_reloj INT NOT NULL,
    pasos_app_movil INT NOT NULL,
    ritmo_cardiaco INT NOT NULL,
    marca_reloj VARCHAR(50),
    modelo_reloj VARCHAR(100),
    tipo_actividad VARCHAR(50),
    posicion_dispositivo VARCHAR(50),
    facilidad_uso INT CHECK(facilidad_uso BETWEEN 1 AND 5),
    nivel_significancia DECIMAL(4,3) NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

// Verificar y añadir columnas nuevas si la tabla ya existe (para no perder datos existentes)
$columnas_existentes = [];
$result = $conn->query("SHOW COLUMNS FROM pruebas_hipotesis");
while ($row = $result->fetch_assoc()) {
    $columnas_existentes[] = $row['Field'];
}

// Si existe la columna 'pasos' antigua, renombrarla a 'pasos_reloj'
if (in_array('pasos', $columnas_existentes) && !in_array('pasos_reloj', $columnas_existentes)) {
    $conn->query("ALTER TABLE pruebas_hipotesis CHANGE pasos pasos_reloj INT NOT NULL");
    // Actualizar el array después de renombrar
    $columnas_existentes = array_map(function($col) {
        return $col === 'pasos' ? 'pasos_reloj' : $col;
    }, $columnas_existentes);
}

// Determinar la posición correcta para las nuevas columnas de forma segura
function obtenerPosicionColumna($columna_deseada, $columnas_existentes, $columna_fallback) {
    if (in_array($columna_deseada, $columnas_existentes)) {
        return "AFTER $columna_deseada";
    } elseif (in_array($columna_fallback, $columnas_existentes)) {
        return "AFTER $columna_fallback";
    } else {
        return ""; // Sin especificar posición
    }
}

// Definir columnas nuevas con posiciones dinámicas y seguras
$columnas_nuevas = [];

// Pasos app móvil: después de pasos_reloj o pasos
if (!in_array('pasos_app_movil', $columnas_existentes)) {
    $pos = obtenerPosicionColumna('pasos_reloj', $columnas_existentes, 'pasos');
    $columnas_nuevas["pasos_app_movil"] = "INT NOT NULL DEFAULT 0" . ($pos ? " $pos" : "");
}

// Modelo reloj: después de marca_reloj
if (!in_array('modelo_reloj', $columnas_existentes)) {
    $pos = obtenerPosicionColumna('marca_reloj', $columnas_existentes, 'ritmo_cardiaco');
    $columnas_nuevas["modelo_reloj"] = "VARCHAR(100)" . ($pos ? " $pos" : "");
}

// Tipo actividad: después de modelo_reloj o marca_reloj
if (!in_array('tipo_actividad', $columnas_existentes)) {
    $columna_base = in_array('modelo_reloj', $columnas_existentes) ? 'modelo_reloj' : 
                    (in_array('marca_reloj', $columnas_existentes) ? 'marca_reloj' : 'ritmo_cardiaco');
    $pos = in_array($columna_base, $columnas_existentes) ? "AFTER $columna_base" : "";
    $columnas_nuevas["tipo_actividad"] = "VARCHAR(50)" . ($pos ? " $pos" : "");
}

// Posición dispositivo: después de tipo_actividad o modelo_reloj
if (!in_array('posicion_dispositivo', $columnas_existentes)) {
    $columna_base = in_array('tipo_actividad', $columnas_existentes) ? 'tipo_actividad' :
                    (in_array('modelo_reloj', $columnas_existentes) ? 'modelo_reloj' : 'marca_reloj');
    $pos = in_array($columna_base, $columnas_existentes) ? "AFTER $columna_base" : "";
    $columnas_nuevas["posicion_dispositivo"] = "VARCHAR(50)" . ($pos ? " $pos" : "");
}

// Facilidad uso: después de posicion_dispositivo o tipo_actividad
if (!in_array('facilidad_uso', $columnas_existentes)) {
    $columna_base = in_array('posicion_dispositivo', $columnas_existentes) ? 'posicion_dispositivo' :
                    (in_array('tipo_actividad', $columnas_existentes) ? 'tipo_actividad' : 'modelo_reloj');
    $pos = in_array($columna_base, $columnas_existentes) ? "AFTER $columna_base" : "";
    $columnas_nuevas["facilidad_uso"] = "INT CHECK(facilidad_uso BETWEEN 1 AND 5)" . ($pos ? " $pos" : "");
}

// Agregar las columnas
foreach ($columnas_nuevas as $columna => $definicion) {
    $sql = "ALTER TABLE pruebas_hipotesis ADD COLUMN $columna $definicion";
    if ($conn->query($sql)) {
        $columnas_existentes[] = $columna;
    }
}

// Variables de mensajes
$mensaje_exito = '';
$mensaje_error = '';

// Insertar datos con validaciones
if (isset($_POST['enviar'])) {
    // Validar y limpiar datos
    $nombre = trim($_POST['nombre'] ?? '');
    $pasos_reloj = isset($_POST['pasos_reloj']) ? intval($_POST['pasos_reloj']) : 0;
    $pasos_app = isset($_POST['pasos_app_movil']) ? intval($_POST['pasos_app_movil']) : 0;
    $ritmo = isset($_POST['ritmo']) ? intval($_POST['ritmo']) : 0;
    $marca = trim($_POST['marca'] ?? '');
    $modelo = trim($_POST['modelo'] ?? '');
    $tipo_actividad = $_POST['tipo_actividad'] ?? '';
    $posicion = $_POST['posicion'] ?? '';
    $facilidad = isset($_POST['facilidad']) ? intval($_POST['facilidad']) : 0;
    $nivel = isset($_POST['nivel']) ? floatval($_POST['nivel']) : 0;

    // Validaciones
    $errores = [];
    
    if (empty($nombre) || strlen($nombre) < 2 || strlen($nombre) > 100) {
        $errores[] = "El nombre debe tener entre 2 y 100 caracteres.";
    }
    
    if ($pasos_reloj < 0 || $pasos_reloj > 100000) {
        $errores[] = "Los pasos del reloj deben estar entre 0 y 100,000.";
    }
    
    if ($pasos_app < 0 || $pasos_app > 100000) {
        $errores[] = "Los pasos de la app móvil deben estar entre 0 y 100,000.";
    }
    
    if ($ritmo < 30 || $ritmo > 220) {
        $errores[] = "El ritmo cardíaco debe estar entre 30 y 220 bpm.";
    }
    
    if (empty($marca) || strlen($marca) > 50) {
        $errores[] = "La marca del reloj es requerida y debe tener máximo 50 caracteres.";
    }
    
    if ($nivel <= 0 || $nivel >= 1) {
        $errores[] = "El nivel de significancia debe estar entre 0 y 1 (ej: 0.05).";
    }
    
    if ($facilidad < 1 || $facilidad > 5) {
        $errores[] = "La facilidad de uso debe ser un valor entre 1 y 5.";
    }

    if (empty($errores)) {
        // Usar prepared statement para evitar SQL injection
        $stmt = $conn->prepare("INSERT INTO pruebas_hipotesis (nombre, pasos_reloj, pasos_app_movil, ritmo_cardiaco, marca_reloj, modelo_reloj, tipo_actividad, posicion_dispositivo, facilidad_uso, nivel_significancia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("siiissssid", $nombre, $pasos_reloj, $pasos_app, $ritmo, $marca, $modelo, $tipo_actividad, $posicion, $facilidad, $nivel);
            
            if ($stmt->execute()) {
                $mensaje_exito = "¡Datos registrados exitosamente!";
                // Limpiar formulario con redirect para evitar reenvío
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                exit();
            } else {
                $mensaje_error = "Error al insertar datos: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $mensaje_error = "Error en la preparación de la consulta: " . $conn->error;
        }
    } else {
        $mensaje_error = implode("<br>", $errores);
    }
}

// Mostrar mensaje de éxito si viene de redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $mensaje_exito = "¡Datos registrados exitosamente!";
}

// Consultar datos
$result = $conn->query("SELECT * FROM pruebas_hipotesis ORDER BY fecha_registro DESC");
$datos = [];
while ($row = $result->fetch_assoc()) {
    $datos[] = $row;
}

// Calcular estadísticas
$promedio_pasos_reloj = 0;
$promedio_pasos_app = 0;
$promedio_diferencia = 0;
$desviacion_reloj = 0;
$desviacion_app = 0;
$n = count($datos);

if ($n > 0) {
    $suma_reloj = array_sum(array_column($datos, 'pasos_reloj'));
    $suma_app = array_sum(array_column($datos, 'pasos_app_movil'));
    $promedio_pasos_reloj = $suma_reloj / $n;
    $promedio_pasos_app = $suma_app / $n;
    $promedio_diferencia = $promedio_pasos_reloj - $promedio_pasos_app;
    
    // Calcular desviación estándar
    if ($n > 1) {
        $suma_varianza_reloj = 0;
        $suma_varianza_app = 0;
        foreach ($datos as $d) {
            $suma_varianza_reloj += pow($d['pasos_reloj'] - $promedio_pasos_reloj, 2);
            $suma_varianza_app += pow($d['pasos_app_movil'] - $promedio_pasos_app, 2);
        }
        $desviacion_reloj = sqrt($suma_varianza_reloj / ($n - 1));
        $desviacion_app = sqrt($suma_varianza_app / ($n - 1));
    }
}

// Parámetros de prueba de hipótesis (por defecto)
$valor_hipotesis = isset($_GET['valor_hipotesis']) ? floatval($_GET['valor_hipotesis']) : 10000;
$nivel_significancia = isset($_GET['nivel_sign']) ? floatval($_GET['nivel_sign']) : 0.05;
$tipo_prueba = isset($_GET['tipo_prueba']) ? $_GET['tipo_prueba'] : 'reloj';

// Función para calcular prueba t de una muestra
function prueba_t_una_muestra($datos, $mu, $nivel_alpha) {
    $n = count($datos);
    if ($n < 2) return null;
    
    $promedio = array_sum($datos) / $n;
    $suma_varianza = 0;
    foreach ($datos as $valor) {
        $suma_varianza += pow($valor - $promedio, 2);
    }
    $desviacion = sqrt($suma_varianza / ($n - 1));
    $error_estandar = $desviacion / sqrt($n);
    
    if ($error_estandar == 0) return null;
    
    $t_observado = ($promedio - $mu) / $error_estandar;
    $grados_libertad = $n - 1;
    
    // Valor crítico aproximado (para muestras grandes se aproxima a la normal)
    // Para muestras pequeñas se necesitaría una tabla t más precisa
    if ($grados_libertad >= 30) {
        // Aproximación normal
        $t_critico_positivo = 1.96; // Para alpha=0.05 bilateral
        $t_critico_negativo = -1.96;
    } else {
        // Aproximación simplificada (en producción usar tabla t real)
        $t_critico_positivo = 2.045; // Para n=30, alpha=0.05
        $t_critico_negativo = -2.045;
    }
    
    return [
        'promedio' => $promedio,
        'desviacion' => $desviacion,
        't_observado' => $t_observado,
        'grados_libertad' => $grados_libertad,
        't_critico' => $t_critico_positivo,
        'rechaza_h0' => abs($t_observado) > abs($t_critico_positivo),
        'error_estandar' => $error_estandar
    ];
}

// Realizar prueba de hipótesis
$resultado_prueba = null;
$hipotesis_nula = "H₀: El promedio de pasos del reloj es igual a " . number_format($valor_hipotesis);
$hipotesis_alternativa = "H₁: El promedio de pasos del reloj es diferente de " . number_format($valor_hipotesis);
$decision = "No hay suficientes datos para calcular la prueba (se requieren al menos 2 registros).";

if ($n >= 2) {
    if ($tipo_prueba == 'reloj') {
        $datos_pasos = array_column($datos, 'pasos_reloj');
    } else {
        $datos_pasos = array_column($datos, 'pasos_app_movil');
        $hipotesis_nula = "H₀: El promedio de pasos de la app móvil es igual a " . number_format($valor_hipotesis);
        $hipotesis_alternativa = "H₁: El promedio de pasos de la app móvil es diferente de " . number_format($valor_hipotesis);
    }
    
    $resultado_prueba = prueba_t_una_muestra($datos_pasos, $valor_hipotesis, $nivel_significancia);
    
    if ($resultado_prueba) {
        if ($resultado_prueba['rechaza_h0']) {
            $decision = "Se rechaza H₀. El promedio de pasos difiere significativamente del valor hipotético (|t| = " . number_format(abs($resultado_prueba['t_observado']), 3) . " > " . number_format($resultado_prueba['t_critico'], 3) . ").";
        } else {
            $decision = "No se rechaza H₀. No hay evidencia suficiente para concluir que el promedio difiere del valor hipotético (|t| = " . number_format(abs($resultado_prueba['t_observado']), 3) . " ≤ " . number_format($resultado_prueba['t_critico'], 3) . ").";
        }
    }
}

// Datos para gráficos
$marcas = [];
$tipos_actividad = [];
foreach ($datos as $d) {
    $marca = $d['marca_reloj'] ?? 'Sin marca';
    if (!isset($marcas[$marca])) {
        $marcas[$marca] = ['reloj' => 0, 'app' => 0, 'count' => 0];
    }
    $marcas[$marca]['reloj'] += $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
    $marcas[$marca]['app'] += $d['pasos_app_movil'] ?? 0;
    $marcas[$marca]['count']++;
    
    $tipo = $d['tipo_actividad'] ?? 'No especificado';
    if (!isset($tipos_actividad[$tipo])) {
        $tipos_actividad[$tipo] = ['reloj' => 0, 'app' => 0, 'count' => 0];
    }
    $tipos_actividad[$tipo]['reloj'] += $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
    $tipos_actividad[$tipo]['app'] += $d['pasos_app_movil'] ?? 0;
    $tipos_actividad[$tipo]['count']++;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Análisis de Pasos - Relojes vs Apps Móviles | Universidad CECAR</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
    color: #333;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
}

.header {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    margin-bottom: 30px;
    text-align: center;
}

.header h1 {
    color: #667eea;
    font-size: 2.5em;
    margin-bottom: 10px;
}

.header p {
    color: #666;
    font-size: 1.1em;
}

.card {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    margin-bottom: 30px;
}

.card h2 {
    color: #667eea;
    margin-bottom: 20px;
    font-size: 1.8em;
    border-bottom: 3px solid #667eea;
    padding-bottom: 10px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    color: #555;
    font-weight: 500;
    margin-bottom: 8px;
    font-size: 0.95em;
}

.form-group input,
.form-group select {
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 1em;
    transition: all 0.3s;
    font-family: 'Poppins', sans-serif;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 40px;
    border: none;
    border-radius: 8px;
    font-size: 1.1em;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    font-family: 'Poppins', sans-serif;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
}

.btn:active {
    transform: translateY(0);
}

.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
}

.stat-card h3 {
    font-size: 0.9em;
    opacity: 0.9;
    margin-bottom: 10px;
}

.stat-card .value {
    font-size: 2em;
    font-weight: 700;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

table th {
    background: #667eea;
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 600;
}

table td {
    padding: 12px 15px;
    border-bottom: 1px solid #e0e0e0;
}

table tr:hover {
    background: #f8f9fa;
}

.chart-container {
    position: relative;
    height: 400px;
    margin-top: 20px;
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 30px;
}

.hipotesis-controls {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.hipotesis-controls form {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.hipotesis-controls .form-group {
    flex: 1;
    min-width: 200px;
}

.hipotesis-result {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-top: 20px;
}

.hipotesis-result p {
    margin: 10px 0;
    font-size: 1.05em;
}

.hipotesis-result strong {
    color: #667eea;
}

.result-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.result-stat {
    background: white;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}

.result-stat label {
    display: block;
    font-size: 0.9em;
    color: #666;
    margin-bottom: 5px;
}

.result-stat .value {
    font-size: 1.3em;
    font-weight: 600;
    color: #333;
}

@media (max-width: 768px) {
    .header h1 {
        font-size: 1.8em;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .charts-grid {
        grid-template-columns: 1fr;
    }
    
    .hipotesis-controls form {
        flex-direction: column;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📊 Análisis de Pasos: Relojes Inteligentes vs Apps Móviles</h1>
        <p>Proyecto de Investigación - Universidad CECAR</p>
    </div>

    <div class="card">
        <h2>📝 Registrar Nuevos Datos</h2>
        <?php if ($mensaje_exito): ?>
            <div class="alert alert-success"><?= $mensaje_exito ?></div>
        <?php endif; ?>
        <?php if ($mensaje_error): ?>
            <div class="alert alert-error"><?= $mensaje_error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label for="nombre">Nombre del Estudiante *</label>
                    <input type="text" id="nombre" name="nombre" placeholder="Ej: Juan Pérez" required minlength="2" maxlength="100">
                </div>
                
                <div class="form-group">
                    <label for="pasos_reloj">Pasos del Reloj Inteligente *</label>
                    <input type="number" id="pasos_reloj" name="pasos_reloj" placeholder="Ej: 8520" required min="0" max="100000">
                </div>
                
                <div class="form-group">
                    <label for="pasos_app_movil">Pasos de App Móvil *</label>
                    <input type="number" id="pasos_app_movil" name="pasos_app_movil" placeholder="Ej: 8400" required min="0" max="100000">
                </div>
                
                <div class="form-group">
                    <label for="ritmo">Ritmo Cardíaco (bpm) *</label>
                    <input type="number" id="ritmo" name="ritmo" placeholder="Ej: 75" required min="30" max="220">
                </div>
                
                <div class="form-group">
                    <label for="marca">Marca del Reloj *</label>
                    <input type="text" id="marca" name="marca" placeholder="Ej: Apple Watch, Fitbit" required maxlength="50">
                </div>
                
                <div class="form-group">
                    <label for="modelo">Modelo del Reloj</label>
                    <input type="text" id="modelo" name="modelo" placeholder="Ej: Series 9, Charge 5" maxlength="100">
                </div>
                
                <div class="form-group">
                    <label for="tipo_actividad">Tipo de Actividad *</label>
                    <select id="tipo_actividad" name="tipo_actividad" required>
                        <option value="">Seleccione...</option>
                        <option value="Caminar">Caminar</option>
                        <option value="Correr">Correr</option>
                        <option value="Subir escaleras">Subir escaleras</option>
                        <option value="Trote">Trote</option>
                        <option value="Otra">Otra</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="posicion">Posición del Dispositivo *</label>
                    <select id="posicion" name="posicion" required>
                        <option value="">Seleccione...</option>
                        <option value="Muñeca">Muñeca</option>
                        <option value="Bolsillo">Bolsillo</option>
                        <option value="Cintura">Cintura</option>
                        <option value="Brazo">Brazo</option>
                        <option value="Otra">Otra</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="facilidad">Facilidad de Uso (1-5) *</label>
                    <input type="number" id="facilidad" name="facilidad" placeholder="5 = Muy fácil" required min="1" max="5">
                </div>
                
                <div class="form-group">
                    <label for="nivel">Nivel de Significancia (α) *</label>
                    <input type="number" step="0.001" id="nivel" name="nivel" placeholder="Ej: 0.05" required min="0.001" max="0.999" value="0.05">
                </div>
            </div>
            
            <button type="submit" name="enviar" class="btn">💾 Guardar Registro</button>
        </form>
    </div>

    <?php if ($n > 0): ?>
    <div class="card">
        <h2>📈 Estadísticas Generales</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Promedio Pasos (Reloj)</h3>
                <div class="value"><?= number_format($promedio_pasos_reloj, 0) ?></div>
            </div>
            <div class="stat-card">
                <h3>Promedio Pasos (App)</h3>
                <div class="value"><?= number_format($promedio_pasos_app, 0) ?></div>
            </div>
            <div class="stat-card">
                <h3>Diferencia Promedio</h3>
                <div class="value"><?= number_format($promedio_diferencia, 0) ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Registros</h3>
                <div class="value"><?= $n ?></div>
            </div>
            <?php if ($n > 1): ?>
            <div class="stat-card">
                <h3>Desv. Estándar (Reloj)</h3>
                <div class="value"><?= number_format($desviacion_reloj, 2) ?></div>
            </div>
            <div class="stat-card">
                <h3>Desv. Estándar (App)</h3>
                <div class="value"><?= number_format($desviacion_app, 2) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>📊 Gráficos Comparativos</h2>
        <div class="charts-grid">
            <div>
                <h3 style="margin-bottom: 15px; color: #667eea;">Comparación: Reloj vs App Móvil</h3>
                <div class="chart-container">
                    <canvas id="graficoComparacion"></canvas>
                </div>
            </div>
            <div>
                <h3 style="margin-bottom: 15px; color: #667eea;">Diferencia de Pasos</h3>
                <div class="chart-container">
                    <canvas id="graficoDiferencia"></canvas>
                </div>
            </div>
            <div>
                <h3 style="margin-bottom: 15px; color: #667eea;">Pasos por Tipo de Actividad</h3>
                <div class="chart-container">
                    <canvas id="graficoActividad"></canvas>
                </div>
            </div>
            <div>
                <h3 style="margin-bottom: 15px; color: #667eea;">Precisión por Marca</h3>
                <div class="chart-container">
                    <canvas id="graficoMarca"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>🧠 Prueba de Hipótesis</h2>
        <div class="hipotesis-controls">
            <form method="GET">
                <div class="form-group">
                    <label for="valor_hipotesis">Valor Hipotético</label>
                    <input type="number" id="valor_hipotesis" name="valor_hipotesis" value="<?= $valor_hipotesis ?>" min="0" required>
                </div>
                <div class="form-group">
                    <label for="nivel_sign">Nivel de Significancia (α)</label>
                    <input type="number" step="0.001" id="nivel_sign" name="nivel_sign" value="<?= $nivel_significancia ?>" min="0.001" max="0.999" required>
                </div>
                <div class="form-group">
                    <label for="tipo_prueba">Tipo de Prueba</label>
                    <select id="tipo_prueba" name="tipo_prueba">
                        <option value="reloj" <?= $tipo_prueba == 'reloj' ? 'selected' : '' ?>>Pasos del Reloj</option>
                        <option value="app" <?= $tipo_prueba == 'app' ? 'selected' : '' ?>>Pasos de App Móvil</option>
                    </select>
                </div>
                <button type="submit" class="btn">🔬 Calcular Prueba</button>
            </form>
        </div>
        
        <div class="hipotesis-result">
            <p><strong><?= $hipotesis_nula ?></strong></p>
            <p><strong><?= $hipotesis_alternativa ?></strong></p>
            <p style="margin-top: 15px;"><strong>Resultado:</strong> <?= $decision ?></p>
            
            <?php if ($resultado_prueba): ?>
            <div class="chart-container" style="margin-top: 30px;">
                <h3 style="margin-bottom: 15px; color: #667eea;">Distribución t y Regiones de Rechazo</h3>
                <canvas id="graficoHipotesis"></canvas>
            </div>
            <div class="result-stats">
                <div class="result-stat">
                    <label>Promedio Observado</label>
                    <div class="value"><?= number_format($resultado_prueba['promedio'], 2) ?></div>
                </div>
                <div class="result-stat">
                    <label>Desviación Estándar</label>
                    <div class="value"><?= number_format($resultado_prueba['desviacion'], 2) ?></div>
                </div>
                <div class="result-stat">
                    <label>Estadístico t</label>
                    <div class="value"><?= number_format($resultado_prueba['t_observado'], 3) ?></div>
                </div>
                <div class="result-stat">
                    <label>Valor Crítico t</label>
                    <div class="value">±<?= number_format($resultado_prueba['t_critico'], 3) ?></div>
                </div>
                <div class="result-stat">
                    <label>Grados de Libertad</label>
                    <div class="value"><?= $resultado_prueba['grados_libertad'] ?></div>
                </div>
                <div class="result-stat">
                    <label>Error Estándar</label>
                    <div class="value"><?= number_format($resultado_prueba['error_estandar'], 2) ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>📋 Registros de Datos</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Pasos Reloj</th>
                        <th>Pasos App</th>
                        <th>Diferencia</th>
                        <th>Ritmo</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Actividad</th>
                        <th>Posición</th>
                        <th>Facilidad</th>
                        <th>α</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($datos as $d): 
                        $pasos_reloj = $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
                        $pasos_app = $d['pasos_app_movil'] ?? 0;
                        $diferencia = $pasos_reloj - $pasos_app;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($d['nombre']) ?></td>
                        <td><?= number_format($pasos_reloj) ?></td>
                        <td><?= number_format($pasos_app) ?></td>
                        <td style="color: <?= $diferencia >= 0 ? '#28a745' : '#dc3545' ?>;">
                            <?= $diferencia >= 0 ? '+' : '' ?><?= number_format($diferencia) ?>
                        </td>
                        <td><?= $d['ritmo_cardiaco'] ?></td>
                        <td><?= htmlspecialchars($d['marca_reloj'] ?? '') ?></td>
                        <td><?= htmlspecialchars($d['modelo_reloj'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($d['tipo_actividad'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($d['posicion_dispositivo'] ?? '-') ?></td>
                        <td><?= $d['facilidad_uso'] ?? '-' ?></td>
                        <td><?= $d['nivel_significancia'] ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($d['fecha_registro'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const datos = <?= json_encode($datos) ?>;

// Gráfico de comparación Reloj vs App
const ctxComparacion = document.getElementById('graficoComparacion')?.getContext('2d');
if (ctxComparacion) {
    const labels = datos.map((d, i) => 'Registro ' + (i + 1));
    const pasosReloj = datos.map(d => d.pasos_reloj ?? d.pasos ?? 0);
    const pasosApp = datos.map(d => d.pasos_app_movil ?? 0);
    
    new Chart(ctxComparacion, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Pasos Reloj',
                data: pasosReloj,
                backgroundColor: 'rgba(102, 126, 234, 0.8)'
            }, {
                label: 'Pasos App Móvil',
                data: pasosApp,
                backgroundColor: 'rgba(118, 75, 162, 0.8)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Cantidad de Pasos'
                    }
                }
            }
        }
    });
}

// Gráfico de diferencia
const ctxDiferencia = document.getElementById('graficoDiferencia')?.getContext('2d');
if (ctxDiferencia) {
    const diferencias = datos.map(d => (d.pasos_reloj ?? d.pasos ?? 0) - (d.pasos_app_movil ?? 0));
    const labels = datos.map((d, i) => 'Registro ' + (i + 1));
    
    new Chart(ctxDiferencia, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Diferencia (Reloj - App)',
                data: diferencias,
                borderColor: 'rgba(220, 53, 69, 0.8)',
                backgroundColor: 'rgba(220, 53, 69, 0.2)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    title: {
                        display: true,
                        text: 'Diferencia de Pasos'
                    }
                }
            },
            plugins: {
                annotation: {
                    annotations: {
                        line1: {
                            type: 'line',
                            yMin: 0,
                            yMax: 0,
                            borderColor: 'rgba(0, 0, 0, 0.5)',
                            borderDash: [5, 5]
                        }
                    }
                }
            }
        }
    });
}

// Gráfico por tipo de actividad
const ctxActividad = document.getElementById('graficoActividad')?.getContext('2d');
if (ctxActividad) {
    const tipos = <?= json_encode($tipos_actividad) ?>;
    const labels = Object.keys(tipos);
    const promedioReloj = labels.map(t => tipos[t].reloj / tipos[t].count);
    const promedioApp = labels.map(t => tipos[t].app / tipos[t].count);
    
    new Chart(ctxActividad, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Promedio Pasos Reloj',
                data: promedioReloj,
                backgroundColor: 'rgba(102, 126, 234, 0.8)'
            }, {
                label: 'Promedio Pasos App',
                data: promedioApp,
                backgroundColor: 'rgba(118, 75, 162, 0.8)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Promedio de Pasos'
                    }
                }
            }
        }
    });
}

// Gráfico por marca
const ctxMarca = document.getElementById('graficoMarca')?.getContext('2d');
if (ctxMarca) {
    const marcas = <?= json_encode($marcas) ?>;
    const labels = Object.keys(marcas);
    const precision = labels.map(m => {
        const reloj = marcas[m].reloj / marcas[m].count;
        const app = marcas[m].app / marcas[m].count;
        return reloj !== 0 ? (1 - Math.abs(reloj - app) / reloj) * 100 : 0;
    });
    
    new Chart(ctxMarca, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Precisión (%)',
                data: precision,
                backgroundColor: precision.map(p => p >= 95 ? 'rgba(40, 167, 69, 0.8)' : p >= 90 ? 'rgba(255, 193, 7, 0.8)' : 'rgba(220, 53, 69, 0.8)')
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Precisión (%)'
                    }
                }
            }
        }
    });
}

// Gráfico de Prueba de Hipótesis
const ctxHipotesis = document.getElementById('graficoHipotesis')?.getContext('2d');
<?php if ($resultado_prueba): ?>
const resultadoPrueba = <?= json_encode($resultado_prueba) ?>;
if (ctxHipotesis && resultadoPrueba) {
    // Generar puntos para la distribución t (aproximada como normal para visualización)
    const tCritico = Math.abs(resultadoPrueba.t_critico);
    const tObservado = resultadoPrueba.t_observado;
    const puntos = 200;
    const rango = Math.max(Math.abs(tObservado), tCritico) * 2.5;
    const xValues = [];
    const yValues = [];
    
    // Función de densidad aproximada (distribución t normalizada)
    for (let i = 0; i <= puntos; i++) {
        const x = -rango + (i / puntos) * (2 * rango);
        xValues.push(x.toFixed(2));
        // Aproximación de distribución t (similar a normal para visualización)
        const y = Math.exp(-0.5 * x * x) / Math.sqrt(2 * Math.PI);
        yValues.push(y);
    }
    
    // Crear datasets para regiones de rechazo
    const regionRechazoIzq = [];
    const regionRechazoDer = [];
    const regionAceptacion = [];
    const yAceptacion = [];
    
    xValues.forEach((x, i) => {
        const xNum = parseFloat(x);
        if (xNum <= -tCritico) {
            regionRechazoIzq.push(yValues[i]);
            regionRechazoDer.push(null);
            regionAceptacion.push(null);
            yAceptacion.push(null);
        } else if (xNum >= tCritico) {
            regionRechazoIzq.push(null);
            regionRechazoDer.push(yValues[i]);
            regionAceptacion.push(null);
            yAceptacion.push(null);
        } else {
            regionRechazoIzq.push(null);
            regionRechazoDer.push(null);
            regionAceptacion.push(yValues[i]);
            yAceptacion.push(yValues[i]);
        }
    });
    
    // Dataset para línea del valor t observado
    const tObservadoData = xValues.map((x, i) => {
        const xNum = parseFloat(x);
        if (Math.abs(xNum - tObservado) < (2 * rango / puntos)) {
            return yValues[i] * 1.1; // Ligeramente más alto para visibilidad
        }
        return null;
    });
    
    new Chart(ctxHipotesis, {
        type: 'line',
        data: {
            labels: xValues,
            datasets: [
                {
                    label: 'Región de Rechazo (Izquierda)',
                    data: regionRechazoIzq,
                    borderColor: 'rgba(220, 53, 69, 0)',
                    backgroundColor: 'rgba(220, 53, 69, 0.3)',
                    fill: true,
                    pointRadius: 0,
                    tension: 0.4
                },
                {
                    label: 'Región de Rechazo (Derecha)',
                    data: regionRechazoDer,
                    borderColor: 'rgba(220, 53, 69, 0)',
                    backgroundColor: 'rgba(220, 53, 69, 0.3)',
                    fill: true,
                    pointRadius: 0,
                    tension: 0.4
                },
                {
                    label: 'Distribución t',
                    data: yAceptacion,
                    borderColor: 'rgba(102, 126, 234, 0.8)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    fill: false,
                    pointRadius: 0,
                    tension: 0.4,
                    borderWidth: 2
                },
                {
                    label: 'Valor t Observado: ' + tObservado.toFixed(3),
                    data: tObservadoData,
                    borderColor: 'rgba(255, 193, 7, 1)',
                    backgroundColor: 'rgba(255, 193, 7, 0.2)',
                    pointRadius: 6,
                    pointBackgroundColor: 'rgba(255, 193, 7, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    showLine: false,
                    tension: 0
                },
                // Líneas verticales para valores críticos
                {
                    label: 'Valor Crítico: ±' + tCritico.toFixed(3),
                    data: xValues.map((x, i) => {
                        const xNum = parseFloat(x);
                        if (Math.abs(xNum + tCritico) < (2 * rango / puntos) || Math.abs(xNum - tCritico) < (2 * rango / puntos)) {
                            return yValues[i] * 1.05;
                        }
                        return null;
                    }),
                    borderColor: 'rgba(220, 53, 69, 0.8)',
                    backgroundColor: 'rgba(220, 53, 69, 0.2)',
                    pointRadius: 0,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    showLine: false,
                    tension: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.datasetIndex === 3) {
                                return 't observado = ' + tObservado.toFixed(3);
                            }
                            if (context.datasetIndex === 4) {
                                return 't crítico = ±' + tCritico.toFixed(3);
                            }
                            return context.dataset.label + ': ' + context.parsed.y.toFixed(4);
                        }
                    }
                },
                annotation: {
                    annotations: {
                        lineZero: {
                            type: 'line',
                            xMin: 0,
                            xMax: 0,
                            borderColor: 'rgba(0, 0, 0, 0.5)',
                            borderWidth: 1,
                            borderDash: [2, 2]
                        },
                        tCriticoPos: {
                            type: 'line',
                            xMin: tCritico,
                            xMax: tCritico,
                            borderColor: 'rgba(220, 53, 69, 0.8)',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            label: {
                                display: true,
                                content: 't crítico = +' + tCritico.toFixed(3),
                                position: 'end',
                                backgroundColor: 'rgba(220, 53, 69, 0.8)',
                                color: 'white',
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tCriticoNeg: {
                            type: 'line',
                            xMin: -tCritico,
                            xMax: -tCritico,
                            borderColor: 'rgba(220, 53, 69, 0.8)',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            label: {
                                display: true,
                                content: 't crítico = -' + tCritico.toFixed(3),
                                position: 'end',
                                backgroundColor: 'rgba(220, 53, 69, 0.8)',
                                color: 'white',
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tObservadoLine: {
                            type: 'line',
                            xMin: tObservado,
                            xMax: tObservado,
                            borderColor: 'rgba(255, 193, 7, 1)',
                            borderWidth: 2,
                            label: {
                                display: true,
                                content: 't observado = ' + tObservado.toFixed(3),
                                position: 'end',
                                backgroundColor: 'rgba(255, 193, 7, 1)',
                                color: '#333',
                                font: {
                                    size: 11,
                                    weight: 'bold'
                                }
                            }
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Valor t',
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    grid: {
                        display: true
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Densidad de Probabilidad',
                        font: {
                            size: 14,
                            weight: 'bold'
                        }
                    },
                    beginAtZero: true,
                    grid: {
                        display: true
                    }
                }
            }
        }
    });
}
<?php endif; ?>
</script>
</body>
</html>