# 🚀 Guía Rápida de Despliegue

## ⚡ Despliegue Rápido en Render

### Paso a Paso:

1. **Ve a Render.com y crea cuenta**
   - https://render.com
   - Regístrate con GitHub

2. **Crea un nuevo Web Service**
   - Click en "New +" → "Web Service"
   - Conecta tu repositorio de GitHub

3. **En la pantalla de configuración:**
   ```
   Name: estadistica-app (o el nombre que prefieras)
   
   Environment: Selecciona **Docker** ⚠️ (NO PHP, Node, etc.)
   
   Plan: Free
   ```

4. **Variables de Entorno** (Importante - Click en "Advanced")
   Agrega estas 5 variables:
   ```
   DB_HOST = gondola.proxy.rlwy.net
   DB_USER = root
   DB_PASSWORD = OQaQPDjxUTUnqkGKxEsnsvqlgWofOUyK
   DB_NAME = railway
   DB_PORT = 45154
   ```

5. **Click en "Create Web Service"**
   - Render comenzará a construir la imagen Docker
   - Esto tomará 2-5 minutos la primera vez

6. **¡Listo!**
   - Obtendrás una URL como: `tu-app.onrender.com`
   - La aplicación estará disponible públicamente

---

## 🔧 Si algo sale mal

### El build falla:
- Verifica que el Dockerfile esté en la raíz del repositorio
- Revisa los logs en Render para ver el error específico

### Error de conexión a la BD:
- Verifica que todas las variables de entorno estén correctas
- Asegúrate de que la BD de Railway permita conexiones externas
- Revisa que el puerto sea correcto

### La página muestra error 500:
- Ve a los logs de Render (pestaña "Logs")
- Revisa los mensajes de error de PHP

---

## 📝 Archivos Importantes

- `Dockerfile` - Configuración de Docker (ya está creado)
- `render.yaml` - Configuración automática (opcional, ya está creado)
- `.env.example` - Plantilla de variables (NO subir .env real)
- `.gitignore` - Protege tus credenciales (ya configurado)

---

## ✅ Checklist antes de desplegar

- [ ] Archivo Dockerfile existe en la raíz
- [ ] Variables de entorno configuradas en Render
- [ ] Repositorio conectado a Render
- [ ] Build completado exitosamente
- [ ] URL accesible y funcionando

---

## 🎉 ¡Listo para compartir!

Una vez desplegado, comparte tu URL con quien quieras. La aplicación será accesible 24/7 (con algunas limitaciones en el plan gratuito de Render).

