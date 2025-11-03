# 🚀 Guía de Despliegue en Render

## 📦 Archivos de Configuración Creados

✅ **Dockerfile** - Configuración Docker para PHP con Apache  
✅ **composer.json** - Metadatos del proyecto  
✅ **render.yaml** - Configuración automática de Render  
✅ **.gitignore** - Protege archivos sensibles  

## 🎯 Pasos para Desplegar en Render

### 1. Sube tu código a GitHub
```bash
git add .
git commit -m "Preparado para deploy en Render"
git push origin main
```

### 2. Ve a Render.com
- Accede a [render.com](https://render.com)
- Regístrate o inicia sesión con GitHub

### 3. Crea un nuevo Web Service
- Click en **"New +"** → **"Web Service"**
- Conecta tu repositorio de GitHub
- Selecciona el repositorio con este proyecto

### 4. Configuración en Render
**Importante:** Cuando Render pregunte por el lenguaje, selecciona **"Docker"** (NO PHP, Node, etc.)

Los campos se llenarán automáticamente si usas `render.yaml`, o configura manualmente:
- **Name:** estadistica-app (o el que prefieras)
- **Environment:** Docker
- **Dockerfile Path:** `./Dockerfile`
- **Plan:** Free

### 5. Variables de Entorno
En la sección **"Environment Variables"**, agrega estas 5 variables:

```
DB_HOST = shuttle.proxy.rlwy.net
DB_USER = root
DB_PASSWORD = HYxtXzGVoWFQYPDuePQdYAslPjOyVhwS
DB_NAME = railway
DB_PORT = 55685
```

**Nota:** Las credenciales están en `index.php`. Si cambian, actualiza las variables aquí.

### 6. Deploy
- Click en **"Create Web Service"**
- Render comenzará a construir la imagen Docker (tardará 2-5 minutos)
- Una vez completado, obtendrás una URL como: `tu-app.onrender.com`

## ✅ Verificación

1. **Verifica el Build:**
   - Ve a la pestaña "Logs" en Render
   - Debe mostrar "Build successful"

2. **Prueba la URL:**
   - Accede a la URL proporcionada por Render
   - La aplicación debería cargar correctamente

3. **Si hay errores:**
   - Revisa los logs en Render
   - Verifica que las variables de entorno estén correctas
   - Asegúrate de que Railway permita conexiones externas

## 📝 Notas Importantes

- ⚠️ **Las credenciales están hardcodeadas en `index.php`**
- 🔒 Para producción, considera mover las credenciales a variables de entorno
- 🌐 Render mapea automáticamente el puerto 80 interno al puerto externo
- 🔄 Cada vez que hagas push a GitHub, Render redesplegará automáticamente

## 🆘 Solución de Problemas

### Error: "Build failed"
- Verifica que el Dockerfile esté en la raíz del proyecto
- Revisa los logs para ver el error específico

### Error: "Connection refused"
- Verifica las variables de entorno en Render
- Asegúrate de que Railway permita conexiones externas
- Verifica que las credenciales sean correctas

### Error: "MySQL server has gone away"
- Railway puede requerir SSL para conexiones externas
- El código ya incluye soporte SSL
- Verifica los logs en Render para más detalles

---

¡Listo! Tu aplicación estará disponible públicamente una vez completado el deploy. 🎉

