<?php
/**
 * ============================================
 * SISTEMA DE ANÁLISIS DE PASOS - UNIVERSIDAD CECAR
 * ============================================
 * 
 * Este sistema permite registrar y analizar datos de pasos medidos
 * por relojes inteligentes y aplicaciones móviles, incluyendo:
 * - Registro de datos con validación
 * - Análisis estadístico (promedios, desviaciones)
 * - Pruebas de hipótesis (prueba t de Student)
 * - Visualización gráfica de datos
 * - Eliminación de registros mediante AJAX
 * 
 * Autor: Universidad CECAR
 * Versión: 2.0
 * ============================================
 */

// ============================================
// CONFIGURACIÓN DE CONEXIÓN A BASE DE DATOS
// ============================================
// Datos de conexión a Railway (servidor de base de datos MySQL)
$servername = "shuttle.proxy.rlwy.net";  // Servidor de la base de datos
$username = "root";                       // Usuario de la base de datos
$password = "HYxtXzGVoWFQYPDuePQdYAslPjOyVhwS";  // Contraseña de la base de datos
$database = "railway";                    // Nombre de la base de datos
$port = 55685;                            // Puerto de conexión

// ============================================
// CONFIGURACIÓN DE TIMEOUTS
// ============================================
// Establecer timeouts para evitar que la conexión se cuelgue indefinidamente
ini_set('mysqli.default_socket', '');           // Deshabilitar socket por defecto
ini_set('default_socket_timeout', 30);          // Timeout general de 30 segundos
ini_set('mysqli.connect_timeout', 30);          // Timeout de conexión de 30 segundos

// ============================================
// ESTABLECER CONEXIÓN CON LA BASE DE DATOS
// ============================================
// Intentar conexión simple primero (sin SSL)
// El @ suprime los errores para manejarlos manualmente
$conn = @new mysqli($servername, $username, $password, $database, $port);

// Si la conexión simple falla, intentar con SSL
// Railway (el servicio de hosting) requiere SSL para conexiones externas
if ($conn->connect_error) {
    // Inicializar objeto mysqli para configuración manual
    $conn = mysqli_init();
    
    // ============================================
    // CONFIGURACIÓN SSL
    // ============================================
    // Railway requiere conexiones SSL, pero no verificamos el certificado
    mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);
    
    // ============================================
    // CONFIGURAR TIMEOUTS PARA SSL
    // ============================================
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 30);  // Timeout de conexión
    mysqli_options($conn, MYSQLI_OPT_READ_TIMEOUT, 30);      // Timeout de lectura
    
    // Intentar conexión con SSL habilitado
    if (!mysqli_real_connect($conn, $servername, $username, $password, $database, $port, NULL, MYSQLI_CLIENT_SSL)) {
        // Si falla la conexión SSL, mostrar mensaje de error detallado
        die("Error de conexión a la base de datos: " . mysqli_connect_error() . 
            "<br><br>Verifica que:<br>" .
            "1. Las credenciales sean correctas<br>" .
            "2. Railway permita conexiones externas<br>" .
            "3. Tu conexión a Internet esté activa");
    }
}

// ============================================
// CONFIGURAR CHARSET UTF-8
// ============================================
// Asegurar que la comunicación con la BD use UTF-8 para caracteres especiales
$conn->set_charset("utf8mb4");

// ============================================
// CREACIÓN DE TABLA EN BASE DE DATOS
// ============================================
// Crear la tabla principal si no existe
// Esta tabla almacena todos los registros de pasos y datos relacionados
$conn->query("
CREATE TABLE IF NOT EXISTS pruebas_hipotesis (
    id INT AUTO_INCREMENT PRIMARY KEY,              -- ID único autoincremental
    nombre VARCHAR(100) NOT NULL,                    -- Nombre del estudiante
    pasos_reloj INT NOT NULL,                       -- Pasos medidos por el reloj
    pasos_app_movil INT NOT NULL,                   -- Pasos medidos por la app móvil
    ritmo_cardiaco INT NOT NULL,                    -- Ritmo cardíaco en bpm
    marca_reloj VARCHAR(50),                        -- Marca del reloj (ej: Apple Watch)
    modelo_reloj VARCHAR(100),                      -- Modelo específico del reloj
    tipo_actividad VARCHAR(50),                      -- Tipo de actividad realizada
    posicion_dispositivo VARCHAR(50),                -- Posición donde se llevó el dispositivo
    facilidad_uso INT CHECK(facilidad_uso BETWEEN 1 AND 5),  -- Calificación 1-5
    nivel_significancia DECIMAL(4,3) NOT NULL,       -- Nivel de significancia estadística
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP  -- Fecha y hora automática
)
");

// ============================================
// MIGRACIÓN DE ESQUEMA DE BASE DE DATOS
// ============================================
// Verificar columnas existentes para migraciones seguras
// Esto evita perder datos cuando se agregan nuevas columnas
$columnas_existentes = [];
$result = $conn->query("SHOW COLUMNS FROM pruebas_hipotesis");
// Recorrer todas las columnas existentes y guardarlas en un array
while ($row = $result->fetch_assoc()) {
    $columnas_existentes[] = $row['Field'];
}

// ============================================
// MIGRACIÓN: Renombrar columna antigua
// ============================================
// Si existe la columna 'pasos' de una versión anterior, renombrarla a 'pasos_reloj'
// Esto mantiene compatibilidad con versiones anteriores del sistema
if (in_array('pasos', $columnas_existentes) && !in_array('pasos_reloj', $columnas_existentes)) {
    $conn->query("ALTER TABLE pruebas_hipotesis CHANGE pasos pasos_reloj INT NOT NULL");
    // Actualizar el array después de renombrar para reflejar el cambio
    $columnas_existentes = array_map(function($col) {
        return $col === 'pasos' ? 'pasos_reloj' : $col;
    }, $columnas_existentes);
}

// ============================================
// FUNCIÓN AUXILIAR: Posicionar columnas
// ============================================
/**
 * Determina la posición correcta para agregar nuevas columnas
 * @param string $columna_deseada - Columna ideal después de la cual agregar
 * @param array $columnas_existentes - Array de columnas que ya existen
 * @param string $columna_fallback - Columna alternativa si la deseada no existe
 * @return string - Cláusula SQL "AFTER columna" o cadena vacía
 */
function obtenerPosicionColumna($columna_deseada, $columnas_existentes, $columna_fallback) {
    // Si la columna deseada existe, usar esa posición
    if (in_array($columna_deseada, $columnas_existentes)) {
        return "AFTER $columna_deseada";
    } 
    // Si no existe, intentar con la columna alternativa
    elseif (in_array($columna_fallback, $columnas_existentes)) {
        return "AFTER $columna_fallback";
    } 
    // Si ninguna existe, agregar al final (sin especificar posición)
    else {
        return "";
    }
}

// ============================================
// AGREGAR COLUMNAS NUEVAS AL ESQUEMA
// ============================================
// Definir array de columnas nuevas a agregar
$columnas_nuevas = [];

// Agregar columna de pasos de app móvil si no existe
// Se posiciona después de pasos_reloj para mantener orden lógico
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

// Ejecutar todas las adiciones de columnas nuevas
foreach ($columnas_nuevas as $columna => $definicion) {
    $sql = "ALTER TABLE pruebas_hipotesis ADD COLUMN $columna $definicion";
    if ($conn->query($sql)) {
        // Si se agregó exitosamente, actualizar el array de columnas existentes
        $columnas_existentes[] = $columna;
    }
}

// ============================================
// AGREGAR COLUMNA DE EDAD
// ============================================
// Columna agregada en versión 2.0 para análisis por edad
if (!in_array('edad', $columnas_existentes)) {
    $pos = obtenerPosicionColumna('nombre', $columnas_existentes, 'pasos_reloj');
    $sql = "ALTER TABLE pruebas_hipotesis ADD COLUMN edad INT" . ($pos ? " $pos" : "");
    if ($conn->query($sql)) {
        $columnas_existentes[] = 'edad';
    }
}

// ============================================
// AGREGAR COLUMNA DE INTERVALO DE HORAS
// ============================================
// Columna agregada en versión 2.0 para análisis por intervalo temporal
// Almacena el rango de horas en que se midieron los pasos (ej: "08:00-12:00")
if (!in_array('intervalo_horas', $columnas_existentes)) {
    $pos = obtenerPosicionColumna('pasos_reloj', $columnas_existentes, 'pasos_app_movil');
    $sql = "ALTER TABLE pruebas_hipotesis ADD COLUMN intervalo_horas VARCHAR(50)" . ($pos ? " $pos" : "");
    if ($conn->query($sql)) {
        $columnas_existentes[] = 'intervalo_horas';
    }
}

// ============================================
// INICIALIZACIÓN DE VARIABLES DE MENSAJES
// ============================================
// Variables para mostrar mensajes de éxito o error al usuario
$mensaje_exito = '';
$mensaje_error = '';

// ============================================
// PROCESAMIENTO DE ELIMINACIÓN DE REGISTRO (AJAX)
// ============================================
// Esta sección maneja las peticiones AJAX para eliminar registros
// Responde con JSON en lugar de HTML para permitir actualización sin recargar
if (isset($_POST['eliminar']) && isset($_POST['id_eliminar'])) {
    // Establecer header JSON para respuesta AJAX
    header('Content-Type: application/json');
    
    // Obtener y validar el ID a eliminar (convertir a entero para seguridad)
    $id_eliminar = intval($_POST['id_eliminar']);
    
    // Preparar consulta DELETE usando prepared statement (previene SQL injection)
    $stmt = $conn->prepare("DELETE FROM pruebas_hipotesis WHERE id = ?");
    
    if ($stmt) {
        // Vincular parámetro: "i" = integer
        $stmt->bind_param("i", $id_eliminar);
        
        // Ejecutar la eliminación
        if ($stmt->execute()) {
            // Éxito: devolver JSON con mensaje positivo
            echo json_encode(['success' => true, 'message' => '¡Registro eliminado exitosamente!']);
        } else {
            // Error en ejecución: devolver mensaje de error
            echo json_encode(['success' => false, 'message' => 'Error al eliminar registro: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        // Error en preparación: devolver mensaje de error
        echo json_encode(['success' => false, 'message' => 'Error en la preparación de la consulta']);
    }
    // Terminar ejecución aquí (no mostrar HTML)
    exit();
}

// ============================================
// PROCESAMIENTO DE INSERCIÓN DE DATOS
// ============================================
// Maneja el envío del formulario de registro de nuevos datos
if (isset($_POST['enviar'])) {
    // ============================================
    // CAPTURA Y LIMPIEZA DE DATOS DEL FORMULARIO
    // ============================================
    // Usar trim() para eliminar espacios en blanco
    // Usar ?? para valores por defecto si no existen
    // Convertir a tipos apropiados (intval, floatval) para seguridad
    
    $nombre = trim($_POST['nombre'] ?? '');                                    // Nombre del estudiante
    $edad = isset($_POST['edad']) ? intval($_POST['edad']) : 0;                // Edad en años
    $pasos_reloj = isset($_POST['pasos_reloj']) ? intval($_POST['pasos_reloj']) : 0;  // Pasos del reloj
    $pasos_app = isset($_POST['pasos_app_movil']) ? intval($_POST['pasos_app_movil']) : 0;  // Pasos de la app
    $intervalo_horas = trim($_POST['intervalo_horas'] ?? '');                   // Intervalo de horas (ej: "08:00-12:00")
    $ritmo = isset($_POST['ritmo']) ? intval($_POST['ritmo']) : 0;             // Ritmo cardíaco en bpm
    $marca = trim($_POST['marca'] ?? '');                                       // Marca del reloj
    $modelo = trim($_POST['modelo'] ?? '');                                     // Modelo del reloj
    $tipo_actividad = $_POST['tipo_actividad'] ?? '';                          // Tipo de actividad
    $posicion = $_POST['posicion'] ?? '';                                       // Posición del dispositivo
    $facilidad = isset($_POST['facilidad']) ? intval($_POST['facilidad']) : 0;  // Facilidad de uso (1-5)
    $nivel = isset($_POST['nivel']) ? floatval($_POST['nivel']) : 0;           // Nivel de significancia estadística

    // ============================================
    // VALIDACIÓN DE DATOS
    // ============================================
    // Array para almacenar todos los errores encontrados
    $errores = [];
    
    // Validar nombre: debe tener entre 2 y 100 caracteres
    if (empty($nombre) || strlen($nombre) < 2 || strlen($nombre) > 100) {
        $errores[] = "El nombre debe tener entre 2 y 100 caracteres.";
    }
    
    // Validar edad: debe ser un valor razonable entre 1 y 120 años
    if ($edad < 1 || $edad > 120) {
        $errores[] = "La edad debe estar entre 1 y 120 años.";
    }
    
    // Validar pasos del reloj: debe ser un número positivo razonable
    if ($pasos_reloj < 0 || $pasos_reloj > 100000) {
        $errores[] = "Los pasos del reloj deben estar entre 0 y 100,000.";
    }
    
    // Validar pasos de la app móvil
    if ($pasos_app < 0 || $pasos_app > 100000) {
        $errores[] = "Los pasos de la app móvil deben estar entre 0 y 100,000.";
    }
    
    // Validar ritmo cardíaco: valores normales están entre 30 y 220 bpm
    if ($ritmo < 30 || $ritmo > 220) {
        $errores[] = "El ritmo cardíaco debe estar entre 30 y 220 bpm.";
    }
    
    // Validar marca del reloj: requerida y con límite de caracteres
    if (empty($marca) || strlen($marca) > 50) {
        $errores[] = "La marca del reloj es requerida y debe tener máximo 50 caracteres.";
    }
    
    // Validar nivel de significancia: debe estar entre 0 y 1 (ej: 0.05 para 5%)
    if ($nivel <= 0 || $nivel >= 1) {
        $errores[] = "El nivel de significancia debe estar entre 0 y 1 (ej: 0.05).";
    }
    
    // Validar facilidad de uso: escala de 1 a 5
    if ($facilidad < 1 || $facilidad > 5) {
        $errores[] = "La facilidad de uso debe ser un valor entre 1 y 5.";
    }
    
    // Validar intervalo de horas: requerido y con formato adecuado
    if (empty($intervalo_horas) || strlen($intervalo_horas) > 50) {
        $errores[] = "El intervalo de horas es requerido y debe tener máximo 50 caracteres (ej: 08:00-12:00).";
    }

    // ============================================
    // INSERTAR DATOS SI NO HAY ERRORES
    // ============================================
    if (empty($errores)) {
        // Usar prepared statement para prevenir ataques de SQL injection
        // Los ? son placeholders que se reemplazarán de forma segura
        $stmt = $conn->prepare("INSERT INTO pruebas_hipotesis (nombre, edad, pasos_reloj, pasos_app_movil, intervalo_horas, ritmo_cardiaco, marca_reloj, modelo_reloj, tipo_actividad, posicion_dispositivo, facilidad_uso, nivel_significancia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            // Definir tipos de datos para bind_param:
            // s = string (texto), i = integer (entero), d = double/decimal (decimal)
            // Orden: nombre(s), edad(i), pasos_reloj(i), pasos_app(i), intervalo_horas(s), 
            //        ritmo(i), marca(s), modelo(s), tipo_actividad(s), posicion(s), facilidad(i), nivel(d)
            $tipos = "s" . "i" . "i" . "i" . "s" . "i" . "s" . "s" . "s" . "s" . "i" . "d";
            
            // Vincular los parámetros con sus valores
            $stmt->bind_param($tipos, $nombre, $edad, $pasos_reloj, $pasos_app, $intervalo_horas, $ritmo, $marca, $modelo, $tipo_actividad, $posicion, $facilidad, $nivel);
            
            // Ejecutar la inserción
            if ($stmt->execute()) {
                $mensaje_exito = "¡Datos registrados exitosamente!";
                // Redirigir con parámetro de éxito para evitar reenvío del formulario (F5)
                // Esto es una práctica común para evitar duplicación de registros
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                exit();
            } else {
                // Error en la ejecución: mostrar mensaje de error
                $mensaje_error = "Error al insertar datos: " . $stmt->error;
            }
            $stmt->close();
        } else {
            // Error en la preparación de la consulta
            $mensaje_error = "Error en la preparación de la consulta: " . $conn->error;
        }
    } else {
        // Si hay errores de validación, unirlos en un solo mensaje
        $mensaje_error = implode("<br>", $errores);
    }
}

// ============================================
// MOSTRAR MENSAJE DE ÉXITO DESPUÉS DE REDIRECT
// ============================================
// Si viene de un redirect exitoso (después de insertar), mostrar mensaje
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $mensaje_exito = "¡Datos registrados exitosamente!";
}

// ============================================
// CONSULTAR TODOS LOS REGISTROS
// ============================================
// Obtener todos los registros ordenados por fecha (más recientes primero)
$result = $conn->query("SELECT * FROM pruebas_hipotesis ORDER BY fecha_registro DESC");
$datos = [];
// Convertir el resultado en un array asociativo
while ($row = $result->fetch_assoc()) {
    $datos[] = $row;
}

// ============================================
// CÁLCULO DE ESTADÍSTICAS DESCRIPTIVAS
// ============================================
// Inicializar variables para estadísticas
$promedio_pasos_reloj = 0;      // Promedio de pasos del reloj
$promedio_pasos_app = 0;        // Promedio de pasos de la app móvil
$promedio_diferencia = 0;        // Diferencia promedio entre reloj y app
$desviacion_reloj = 0;          // Desviación estándar de pasos del reloj
$desviacion_app = 0;            // Desviación estándar de pasos de la app
$n = count($datos);              // Número total de registros

// Solo calcular estadísticas si hay datos
if ($n > 0) {
    // Calcular promedios: suma todos los valores y divide entre el total
    $suma_reloj = array_sum(array_column($datos, 'pasos_reloj'));
    $suma_app = array_sum(array_column($datos, 'pasos_app_movil'));
    $promedio_pasos_reloj = $suma_reloj / $n;
    $promedio_pasos_app = $suma_app / $n;
    $promedio_diferencia = $promedio_pasos_reloj - $promedio_pasos_app;
    
    // Calcular desviación estándar (solo si hay más de 1 registro)
    // La desviación estándar requiere al menos 2 valores
    if ($n > 1) {
        $suma_varianza_reloj = 0;
        $suma_varianza_app = 0;
        
        // Calcular la varianza: suma de (valor - promedio)^2
        foreach ($datos as $d) {
            $suma_varianza_reloj += pow($d['pasos_reloj'] - $promedio_pasos_reloj, 2);
            $suma_varianza_app += pow($d['pasos_app_movil'] - $promedio_pasos_app, 2);
        }
        
        // Desviación estándar = raíz cuadrada de (varianza / (n-1))
        // Se usa (n-1) en lugar de n para obtener la desviación estándar muestral
        $desviacion_reloj = sqrt($suma_varianza_reloj / ($n - 1));
        $desviacion_app = sqrt($suma_varianza_app / ($n - 1));
    }
}

// ============================================
// CONFIGURACIÓN DE PRUEBA DE HIPÓTESIS
// ============================================
// Obtener parámetros de la prueba desde la URL (GET) o usar valores por defecto
$valor_hipotesis = isset($_GET['valor_hipotesis']) ? floatval($_GET['valor_hipotesis']) : 10000;  // Valor hipotético a probar
$nivel_significancia = isset($_GET['nivel_sign']) ? floatval($_GET['nivel_sign']) : 0.05;          // Nivel alfa (ej: 0.05 = 5%)
$tipo_prueba = isset($_GET['tipo_prueba']) ? $_GET['tipo_prueba'] : 'reloj';                       // Tipo: 'reloj' o 'app'

// ============================================
// FUNCIÓN: Prueba t de Student para una muestra
// ============================================
/**
 * Calcula la prueba t de Student para una muestra
 * Esta prueba determina si el promedio de una muestra difiere significativamente de un valor hipotético
 * 
 * @param array $datos - Array de valores numéricos (muestra)
 * @param float $mu - Valor hipotético a probar (μ)
 * @param float $nivel_alpha - Nivel de significancia (ej: 0.05)
 * @return array|null - Array con estadísticos o null si no hay suficientes datos
 */
function prueba_t_una_muestra($datos, $mu, $nivel_alpha) {
    // Verificar que haya suficientes datos (mínimo 2 para calcular varianza)
    $n = count($datos);
    if ($n < 2) return null;
    
    // Calcular el promedio muestral (x̄)
    $promedio = array_sum($datos) / $n;
    
    // Calcular la varianza muestral
    $suma_varianza = 0;
    foreach ($datos as $valor) {
        $suma_varianza += pow($valor - $promedio, 2);  // Suma de (x - x̄)²
    }
    $desviacion = sqrt($suma_varianza / ($n - 1));  // Desviación estándar muestral
    
    // Calcular el error estándar de la media: SE = s / √n
    $error_estandar = $desviacion / sqrt($n);
    
    // Si el error estándar es 0, no se puede calcular (todos los valores son iguales)
    if ($error_estandar == 0) return null;
    
    // Calcular el estadístico t observado: t = (x̄ - μ) / SE
    $t_observado = ($promedio - $mu) / $error_estandar;
    
    // Grados de libertad: n - 1
    $grados_libertad = $n - 1;
    
    // ============================================
    // OBTENER VALOR CRÍTICO DE t
    // ============================================
    // Para muestras grandes (n >= 30), la distribución t se aproxima a la normal
    // Para muestras pequeñas, se necesita una tabla t más precisa
    if ($grados_libertad >= 30) {
        // Aproximación normal (distribución t con muchos grados de libertad)
        $t_critico_positivo = 1.96;  // Para alpha=0.05, prueba bilateral
        $t_critico_negativo = -1.96;
    } else {
        // Aproximación simplificada para muestras pequeñas
        // NOTA: En producción, usar una tabla t real o función estadística
        $t_critico_positivo = 2.045;  // Aproximado para n=30, alpha=0.05
        $t_critico_negativo = -2.045;
    }
    
    // Retornar todos los estadísticos calculados
    return [
        'promedio' => $promedio,                      // Promedio muestral
        'desviacion' => $desviacion,                  // Desviación estándar muestral
        't_observado' => $t_observado,                // Estadístico t calculado
        'grados_libertad' => $grados_libertad,        // Grados de libertad (n-1)
        't_critico' => $t_critico_positivo,           // Valor crítico de t
        'rechaza_h0' => abs($t_observado) > abs($t_critico_positivo),  // ¿Se rechaza H₀?
        'error_estandar' => $error_estandar           // Error estándar de la media
    ];
}

// ============================================
// EJECUTAR PRUEBA DE HIPÓTESIS
// ============================================
// Inicializar variables para la prueba
$resultado_prueba = null;
$hipotesis_nula = "H₀: El promedio de pasos del reloj es igual a " . number_format($valor_hipotesis);
$hipotesis_alternativa = "H₁: El promedio de pasos del reloj es diferente de " . number_format($valor_hipotesis);
$decision = "No hay suficientes datos para calcular la prueba (se requieren al menos 2 registros).";

// Solo ejecutar la prueba si hay suficientes datos (mínimo 2 registros)
if ($n >= 2) {
    // Seleccionar datos según el tipo de prueba elegido
    if ($tipo_prueba == 'reloj') {
        // Usar datos de pasos del reloj
        $datos_pasos = array_column($datos, 'pasos_reloj');
    } else {
        // Usar datos de pasos de la app móvil y actualizar textos de hipótesis
        $datos_pasos = array_column($datos, 'pasos_app_movil');
        $hipotesis_nula = "H₀: El promedio de pasos de la app móvil es igual a " . number_format($valor_hipotesis);
        $hipotesis_alternativa = "H₁: El promedio de pasos de la app móvil es diferente de " . number_format($valor_hipotesis);
    }
    
    // Ejecutar la prueba t de Student
    $resultado_prueba = prueba_t_una_muestra($datos_pasos, $valor_hipotesis, $nivel_significancia);
    
    // Interpretar los resultados de la prueba
    if ($resultado_prueba) {
        if ($resultado_prueba['rechaza_h0']) {
            // Se rechaza H₀: hay diferencia significativa
            $decision = "Se rechaza H₀. El promedio de pasos difiere significativamente del valor hipotético (|t| = " . number_format(abs($resultado_prueba['t_observado']), 3) . " > " . number_format($resultado_prueba['t_critico'], 3) . ").";
        } else {
            // No se rechaza H₀: no hay evidencia suficiente de diferencia
            $decision = "No se rechaza H₀. No hay evidencia suficiente para concluir que el promedio difiere del valor hipotético (|t| = " . number_format(abs($resultado_prueba['t_observado']), 3) . " ≤ " . number_format($resultado_prueba['t_critico'], 3) . ").";
        }
    }
}

// ============================================
// PREPARACIÓN DE DATOS PARA GRÁFICOS
// ============================================
// Agrupar datos por diferentes categorías para visualización
$marcas = [];              // Agrupar por marca de reloj
$tipos_actividad = [];     // Agrupar por tipo de actividad
$edades = [];              // Agrupar por rangos de edad
$intervalos_horas = [];    // Agrupar por intervalo de horas

// Recorrer todos los registros y agruparlos
foreach ($datos as $d) {
    // ============================================
    // AGRUPAR POR MARCA DE RELOJ
    // ============================================
    $marca = $d['marca_reloj'] ?? 'Sin marca';
    if (!isset($marcas[$marca])) {
        $marcas[$marca] = ['reloj' => 0, 'app' => 0, 'count' => 0];
    }
    $marcas[$marca]['reloj'] += $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
    $marcas[$marca]['app'] += $d['pasos_app_movil'] ?? 0;
    $marcas[$marca]['count']++;
    
    // ============================================
    // AGRUPAR POR TIPO DE ACTIVIDAD
    // ============================================
    $tipo = $d['tipo_actividad'] ?? 'No especificado';
    if (!isset($tipos_actividad[$tipo])) {
        $tipos_actividad[$tipo] = ['reloj' => 0, 'app' => 0, 'count' => 0];
    }
    $tipos_actividad[$tipo]['reloj'] += $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
    $tipos_actividad[$tipo]['app'] += $d['pasos_app_movil'] ?? 0;
    $tipos_actividad[$tipo]['count']++;
    
    // ============================================
    // AGRUPAR POR RANGOS DE EDAD
    // ============================================
    $edad = $d['edad'] ?? 0;
    if ($edad > 0) {
        // Clasificar en rangos de edad
        $rango_edad = '';
        if ($edad < 20) $rango_edad = 'Menor a 20';
        elseif ($edad < 30) $rango_edad = '20-29';
        elseif ($edad < 40) $rango_edad = '30-39';
        elseif ($edad < 50) $rango_edad = '40-49';
        elseif ($edad < 60) $rango_edad = '50-59';
        else $rango_edad = '60 o más';
        
        // Acumular datos por rango
        if (!isset($edades[$rango_edad])) {
            $edades[$rango_edad] = ['reloj' => 0, 'app' => 0, 'count' => 0];
        }
        $edades[$rango_edad]['reloj'] += $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
        $edades[$rango_edad]['app'] += $d['pasos_app_movil'] ?? 0;
        $edades[$rango_edad]['count']++;
    }
    
    // ============================================
    // AGRUPAR POR INTERVALO DE HORAS
    // ============================================
    $intervalo = $d['intervalo_horas'] ?? '';
    if (!empty($intervalo)) {
        // Acumular datos por intervalo temporal
        if (!isset($intervalos_horas[$intervalo])) {
            $intervalos_horas[$intervalo] = ['reloj' => 0, 'app' => 0, 'count' => 0];
        }
        $intervalos_horas[$intervalo]['reloj'] += $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
        $intervalos_horas[$intervalo]['app'] += $d['pasos_app_movil'] ?? 0;
        $intervalos_horas[$intervalo]['count']++;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<!-- ============================================
     CONFIGURACIÓN DEL DOCUMENTO HTML
     ============================================ -->
<meta charset="UTF-8">  <!-- Codificación UTF-8 para caracteres especiales -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">  <!-- Responsive design -->
<title>Análisis de Pasos - Relojes vs Apps Móviles | Universidad CECAR</title>

<!-- ============================================
     LIBRERÍAS EXTERNAS
     ============================================ -->
<!-- Chart.js: librería para crear gráficos interactivos -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Plugin de anotaciones para Chart.js (líneas de referencia, etc.) -->
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
<!-- Fuente Poppins de Google Fonts para diseño moderno -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- ============================================
     ESTILOS CSS
     ============================================ -->
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

.btn-eliminar:hover {
    background: #c82333 !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(220, 53, 69, 0.3);
}

.btn-eliminar:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

#mensaje-ajax {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    z-index: 10000;
    max-width: 400px;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

#mensaje-ajax.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

#mensaje-ajax.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.registro-eliminando {
    opacity: 0.5;
    background-color: #f8f9fa;
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
                    <label for="edad">Edad *</label>
                    <input type="number" id="edad" name="edad" placeholder="Ej: 25" required min="1" max="120">
                </div>
                
                <div class="form-group">
                    <label for="pasos_reloj">Pasos del Reloj Inteligente *</label>
                    <input type="number" id="pasos_reloj" name="pasos_reloj" placeholder="Ej: 8520" required min="0" max="100000">
                </div>
                
                <div class="form-group">
                    <label for="intervalo_horas">Intervalo de Horas *</label>
                    <input type="text" id="intervalo_horas" name="intervalo_horas" placeholder="Ej: 08:00-12:00" required maxlength="50">
                    <small style="color: #666; font-size: 0.85em; margin-top: 5px;">Formato: HH:MM-HH:MM</small>
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
            <div>
                <h3 style="margin-bottom: 15px; color: #667eea;">Pasos por Rango de Edad</h3>
                <div class="chart-container">
                    <canvas id="graficoEdad"></canvas>
                </div>
            </div>
            <div>
                <h3 style="margin-bottom: 15px; color: #667eea;">Pasos por Intervalo de Horas</h3>
                <div class="chart-container">
                    <canvas id="graficoIntervaloHoras"></canvas>
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
                        <th>Edad</th>
                        <th>Pasos Reloj</th>
                        <th>Pasos App</th>
                        <th>Intervalo Horas</th>
                        <th>Diferencia</th>
                        <th>Ritmo</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Actividad</th>
                        <th>Posición</th>
                        <th>Facilidad</th>
                        <th>α</th>
                        <th>Fecha</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($datos as $d): 
                        $pasos_reloj = $d['pasos_reloj'] ?? $d['pasos'] ?? 0;
                        $pasos_app = $d['pasos_app_movil'] ?? 0;
                        $diferencia = $pasos_reloj - $pasos_app;
                    ?>
                    <tr id="registro-<?= $d['id'] ?>">
                        <td><?= htmlspecialchars($d['nombre']) ?></td>
                        <td><?= $d['edad'] ?? '-' ?></td>
                        <td><?= number_format($pasos_reloj) ?></td>
                        <td><?= number_format($pasos_app) ?></td>
                        <td><?= htmlspecialchars($d['intervalo_horas'] ?? '-') ?></td>
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
                        <td>
                            <button type="button" class="btn-eliminar" data-id="<?= $d['id'] ?>" style="background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 5px; cursor: pointer; font-size: 0.85em;">🗑️ Eliminar</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================
     CÓDIGO JAVASCRIPT
     ============================================ -->
<script>
// ============================================
// INICIALIZACIÓN: Pasar datos PHP a JavaScript
// ============================================
// Convertir array PHP a JSON para uso en JavaScript
const datos = <?= json_encode($datos) ?>;

// ============================================
// FUNCIÓN: Mostrar mensajes al usuario
// ============================================
/**
 * Muestra un mensaje temporal en la esquina superior derecha
 * @param {string} mensaje - Texto del mensaje a mostrar
 * @param {string} tipo - Tipo de mensaje: 'success' o 'error'
 */
function mostrarMensaje(mensaje, tipo = 'success') {
    // Eliminar mensaje anterior si existe
    const mensajeAnterior = document.getElementById('mensaje-ajax');
    if (mensajeAnterior) {
        mensajeAnterior.remove();
    }
    
    // Crear nuevo mensaje
    const div = document.createElement('div');
    div.id = 'mensaje-ajax';
    div.className = tipo;
    div.textContent = mensaje;
    document.body.appendChild(div);
    
    // Eliminar después de 3 segundos
    setTimeout(() => {
        div.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => div.remove(), 300);
    }, 3000);
}

// ============================================
// FUNCIÓN: Eliminar registro con AJAX
// ============================================
/**
 * Elimina un registro de la base de datos usando AJAX
 * No recarga la página, solo actualiza la interfaz
 * @param {number} id - ID del registro a eliminar
 */
function eliminarRegistro(id) {
    if (!confirm('¿Está seguro de eliminar este registro?')) {
        return;
    }
    
    const fila = document.getElementById('registro-' + id);
    if (!fila) return;
    
    // Deshabilitar botón y marcar fila
    const boton = fila.querySelector('.btn-eliminar');
    boton.disabled = true;
    fila.classList.add('registro-eliminando');
    
    // Crear FormData para enviar
    const formData = new FormData();
    formData.append('eliminar', '1');
    formData.append('id_eliminar', id);
    
    // Enviar petición AJAX
    fetch('<?= $_SERVER['PHP_SELF'] ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Mostrar mensaje de éxito
            mostrarMensaje(data.message, 'success');
            
            // Eliminar fila con animación
            fila.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
            fila.style.opacity = '0';
            fila.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                fila.remove();
                
                // Verificar si quedan registros
                const tabla = document.querySelector('table tbody');
                if (tabla && tabla.children.length === 0) {
                    // Si no quedan registros, mostrar mensaje y recargar solo para actualizar la UI
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            }, 300);
        } else {
            // Mostrar mensaje de error
            mostrarMensaje(data.message, 'error');
            
            // Rehabilitar botón
            boton.disabled = false;
            fila.classList.remove('registro-eliminando');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarMensaje('Error al eliminar el registro. Por favor, intente nuevamente.', 'error');
        boton.disabled = false;
        fila.classList.remove('registro-eliminando');
    });
}

// ============================================
// ASIGNAR EVENT LISTENERS A BOTONES
// ============================================
// Esperar a que el DOM esté completamente cargado
document.addEventListener('DOMContentLoaded', function() {
    const botonesEliminar = document.querySelectorAll('.btn-eliminar');
    botonesEliminar.forEach(boton => {
        boton.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            if (id) {
                eliminarRegistro(id);
            }
        });
    });
});

// ============================================
// GRÁFICO 1: Comparación Reloj vs App Móvil
// ============================================
// Gráfico de barras comparando pasos del reloj vs app móvil
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

// ============================================
// GRÁFICO 2: Diferencia de Pasos
// ============================================
// Gráfico de línea mostrando la diferencia entre reloj y app
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

// ============================================
// GRÁFICO 3: Pasos por Tipo de Actividad
// ============================================
// Gráfico de barras agrupadas por tipo de actividad
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

// ============================================
// GRÁFICO 4: Precisión por Marca
// ============================================
// Gráfico mostrando la precisión porcentual de cada marca
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

// ============================================
// GRÁFICO 5: Pasos por Rango de Edad
// ============================================
// Gráfico de barras agrupadas por rangos de edad
const ctxEdad = document.getElementById('graficoEdad')?.getContext('2d');
if (ctxEdad) {
    const edades = <?= json_encode($edades) ?>;
    const labels = Object.keys(edades);
    if (labels.length > 0) {
        const promedioReloj = labels.map(e => edades[e].reloj / edades[e].count);
        const promedioApp = labels.map(e => edades[e].app / edades[e].count);
        
        new Chart(ctxEdad, {
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
}

// ============================================
// GRÁFICO 6: Pasos por Intervalo de Horas
// ============================================
// Gráfico de línea mostrando pasos por intervalo temporal
const ctxIntervaloHoras = document.getElementById('graficoIntervaloHoras')?.getContext('2d');
if (ctxIntervaloHoras) {
    const intervalos = <?= json_encode($intervalos_horas) ?>;
    const labels = Object.keys(intervalos);
    if (labels.length > 0) {
        const promedioReloj = labels.map(i => intervalos[i].reloj / intervalos[i].count);
        const promedioApp = labels.map(i => intervalos[i].app / intervalos[i].count);
        
        new Chart(ctxIntervaloHoras, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Promedio Pasos Reloj',
                    data: promedioReloj,
                    borderColor: 'rgba(102, 126, 234, 0.8)',
                    backgroundColor: 'rgba(102, 126, 234, 0.2)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Promedio Pasos App',
                    data: promedioApp,
                    borderColor: 'rgba(118, 75, 162, 0.8)',
                    backgroundColor: 'rgba(118, 75, 162, 0.2)',
                    fill: true,
                    tension: 0.4
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
}

// ============================================
// GRÁFICO 7: Distribución t de Prueba de Hipótesis
// ============================================
// Gráfico avanzado mostrando la distribución t, regiones de rechazo y valor observado
const ctxHipotesis = document.getElementById('graficoHipotesis')?.getContext('2d');
<?php if ($resultado_prueba): ?>
// Convertir resultado de PHP a JavaScript
const resultadoPrueba = <?= json_encode($resultado_prueba) ?>;
if (ctxHipotesis && resultadoPrueba) {
    // ============================================
    // GENERAR DATOS PARA LA DISTRIBUCIÓN t
    // ============================================
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