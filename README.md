# 📊 Análisis de Pasos - Relojes vs Apps Móviles

Aplicación web para análisis estadístico de datos de pasos comparando relojes inteligentes con apps móviles.

## 🚀 Desplegar en la Nube

Esta aplicación puede desplegarse en varias plataformas. A continuación, las opciones recomendadas:

---

## Opción 1: Railway (Recomendado - Ya tienes la BD ahí) 🚂

Railway es la opción más recomendada ya que tu base de datos ya está alojada allí.

### Pasos:

1. **Crear cuenta en Railway:**
   - Ve a [railway.app](https://railway.app)
   - Regístrate con GitHub

2. **Subir el proyecto:**
   - Crea un nuevo proyecto en Railway
   - Selecciona "Deploy from GitHub repo"
   - Conecta tu repositorio
   - Railway detectará automáticamente el proyecto PHP

3. **Configurar variables de entorno:**
   - En el dashboard de Railway, ve a "Variables"
   - Agrega las siguientes variables:
     ```
     DB_HOST=gondola.proxy.rlwy.net
     DB_USER=root
     DB_PASSWORD=OQaQPDjxUTUnqkGKxEsnsvqlgWofOUyK
     DB_NAME=railway
     DB_PORT=45154
     ```

4. **Deploy:**
   - Railway desplegará automáticamente
   - Obtendrás una URL como: `tu-app.railway.app`

---

## Opción 2: Render (Gratis) 🎨

Render ofrece un plan gratuito ideal para proyectos PHP usando Docker.

### Pasos:

1. **Crear cuenta:**
   - Ve a [render.com](https://render.com)
   - Regístrate con GitHub

2. **Crear nuevo Web Service:**
   - Haz clic en "New +" → "Web Service"
   - Conecta tu repositorio de GitHub

3. **Configuración:**
   - **Name:** estadistica-app (o el que prefieras)
   - **Environment:** Selecciona **Docker** (no PHP directamente)
   - **Dockerfile Path:** `./Dockerfile` (o deja el campo vacío si está en la raíz)
   - **Plan:** Free

4. **Variables de entorno:**
   - En "Environment Variables", agrega:
     ```
     DB_HOST=gondola.proxy.rlwy.net
     DB_USER=root
     DB_PASSWORD=OQaQPDjxUTUnqkGKxEsnsvqlgWofOUyK
     DB_NAME=railway
     DB_PORT=45154
     ```

5. **Deploy:**
   - Haz clic en "Create Web Service"
   - Render desplegará automáticamente usando el Dockerfile
   - Obtendrás una URL como: `tu-app.onrender.com`

**Nota:** El proyecto incluye un `Dockerfile` y `render.yaml` listos para usar. Si usas el archivo `render.yaml`, Render detectará automáticamente la configuración.

---

## Opción 3: Heroku 🌐

Heroku es otra excelente opción, aunque requiere tarjeta de crédito para algunas funciones.

### Pasos:

1. **Instalar Heroku CLI:**
   ```bash
   # Windows (con chocolatey)
   choco install heroku-cli
   
   # O descarga desde: https://devcenter.heroku.com/articles/heroku-cli
   ```

2. **Login y crear app:**
   ```bash
   heroku login
   heroku create tu-app-estadistica
   ```

3. **Configurar variables:**
   ```bash
   heroku config:set DB_HOST=gondola.proxy.rlwy.net
   heroku config:set DB_USER=root
   heroku config:set DB_PASSWORD=OQaQPDjxUTUnqkGKxEsnsvqlgWofOUyK
   heroku config:set DB_NAME=railway
   heroku config:set DB_PORT=45154
   ```

4. **Desplegar:**
   ```bash
   git push heroku main
   ```

---

## Opción 4: 000webhost (Completamente Gratis) 🆓

000webhost ofrece hosting PHP completamente gratuito sin necesidad de tarjeta.

### Pasos:

1. **Crear cuenta:**
   - Ve a [000webhost.com](https://000webhost.com)
   - Regístrate gratuitamente

2. **Crear sitio web:**
   - Ve a "New Website"
   - Elige un nombre y dominio

3. **Subir archivos:**
   - Usa FileZilla o el File Manager de 000webhost
   - Sube todos los archivos del proyecto a la carpeta `public_html`

4. **Configurar .env:**
   - Crea un archivo `.env` en `public_html`
   - Agrega las variables de entorno (ver `.env.example`)

5. **Nota:** Esta opción no permite variables de entorno del sistema, así que deberás usar el archivo `.env`

---

## 📝 Configuración Local (para desarrollo)

1. **Copia el archivo de ejemplo:**
   ```bash
   cp .env.example .env
   ```

2. **Edita `.env` con tus credenciales reales**

3. **Asegúrate de tener PHP instalado:**
   ```bash
   php -v
   ```

4. **Ejecuta el servidor local:**
   ```bash
   php -S localhost:8000
   ```

5. **Abre en el navegador:**
   - `http://localhost:8000`

---

## 🔒 Seguridad

- **NUNCA** subas el archivo `.env` con credenciales reales a GitHub
- El archivo `.gitignore` ya está configurado para ignorar `.env`
- En producción, siempre usa variables de entorno proporcionadas por la plataforma

---

## 📋 Requisitos

- PHP 7.4 o superior
- Extensión MySQLi habilitada
- Conexión a base de datos MySQL/MariaDB

---

## 🆘 Solución de Problemas

### Error de conexión a la base de datos:
- Verifica que las variables de entorno estén correctamente configuradas
- Asegúrate de que el host de la BD permita conexiones desde el servidor donde está desplegada la app
- Railway: La BD debe estar en el mismo proyecto o con permisos correctos

### La aplicación no carga:
- Verifica que PHP esté instalado en el servidor
- Revisa los logs de la plataforma de despliegue
- Asegúrate de que el archivo `index.php` esté en la raíz del proyecto

### Error 500:
- Revisa los logs del servidor
- Verifica la configuración de PHP
- Asegúrate de que todas las extensiones necesarias estén instaladas

---

## 📞 Soporte

Si tienes problemas con el despliegue, revisa:
- La documentación de la plataforma elegida
- Los logs de error del servidor
- La configuración de variables de entorno

---

## ✨ Características

- ✅ Registro de datos de pasos (reloj vs app móvil)
- ✅ Análisis estadístico con pruebas de hipótesis
- ✅ Gráficos interactivos con Chart.js
- ✅ Visualización de datos por marca y tipo de actividad
- ✅ Interfaz moderna y responsive

---

¡Buena suerte con tu despliegue! 🚀

