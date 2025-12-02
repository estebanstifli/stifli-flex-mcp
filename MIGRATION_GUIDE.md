# ✅ Renombrado Completado: StifLi Flex MCP

## 🎉 Cambios Exitosos

El plugin ha sido renombrado completamente de **Easy Visual MCP** a **StifLi Flex MCP** para cumplir con las políticas de WordPress.org.

## 📝 Qué ha cambiado

### Nombres y Slugs
- **Nombre del plugin**: Easy Visual MCP → **StifLi Flex MCP**
- **Archivo principal**: `easy-visual-mcp.php` → `stifli-flex-mcp.php`
- **Slug**: `easy-visual-mcp` → `stifli-flex-mcp`
- **Text Domain**: `easy-visual-mcp` → `stifli-flex-mcp`

### Endpoints de API
Los endpoints REST han cambiado:
- **Antes**: `/wp-json/easy-visual-mcp/v1/messages`
- **Ahora**: `/wp-json/stifli-flex-mcp/v1/messages`

- **Antes**: `/wp-json/easy-visual-mcp/v1/sse`
- **Ahora**: `/wp-json/stifli-flex-mcp/v1/sse`

### Base de Datos
Las nuevas tablas usan el prefijo `sflmcp_`:
- `wp_sflmcp_queue`
- `wp_sflmcp_tools`
- `wp_sflmcp_profiles`
- `wp_sflmcp_profile_tools`

## ⚠️ IMPORTANTE: Migración para Usuarios Existentes

Si tienes el plugin instalado como "Easy Visual MCP", al actualizar:

1. **Desactivar** el plugin antiguo
2. **Desinstalar** el plugin antiguo (esto borrará las tablas `wp_evmcp_*`)
3. **Instalar** la nueva versión "StifLi Flex MCP"
4. **Reconfigurar**:
   - Generar nuevo token de autenticación
   - Reactivar las herramientas necesarias
   - Aplicar el perfil que desees

5. **Actualizar integraciones externas**:
   - ChatGPT Custom Connectors
   - Claude Desktop config
   - LibreChat settings
   - Scripts personalizados

   Cambiar la URL del endpoint a: `/wp-json/stifli-flex-mcp/v1/...`

## 🚀 Próximos Pasos

### 1. Actualizar Repositorio GitHub

```powershell
# Desde la raíz del proyecto
cd c:\prueba\easy-visual-mcp

# Añadir cambios
git add .
git commit -m "Rename plugin to StifLi Flex MCP for WordPress.org compliance"
git push origin master

# Crear tag para v1.0.0
git tag -a v1.0.0 -m "Version 1.0.0 - Initial release as StifLi Flex MCP"
git push origin v1.0.0
```

**NOTA**: Después de pushear, renombra el repositorio en GitHub:
- Settings → Repository name: `stifli-flex-mcp`
- Actualiza el README.md del repositorio con el nuevo nombre

### 2. Generar ZIP para Distribución

```powershell
# Desde el directorio dev
cd dev
.\build-plugin.ps1 -VersionTag "1.0.0"
```

El ZIP se creará en `dist/stifli-flex-mcp-1.0.0.zip`

### 3. Crear GitHub Release

Opción A - Manual:
1. Ir a https://github.com/estebanstifli/easy-visual-mcp/releases
2. Click "Draft a new release"
3. Tag: `v1.0.0`
4. Title: `1.0.0 - Initial Release`
5. Adjuntar `dist/stifli-flex-mcp-1.0.0.zip`
6. Publish

Opción B - Con GitHub CLI:
```powershell
gh release create v1.0.0 dist/stifli-flex-mcp-1.0.0.zip `
  --title "1.0.0 - Initial Release" `
  --notes "Initial release as StifLi Flex MCP (formerly Easy Visual MCP). Includes 117 tools for WordPress and WooCommerce management via MCP protocol."
```

### 4. Enviar a WordPress.org

1. Ir a https://wordpress.org/plugins/developers/add/
2. Usar el slug: `stifli-flex-mcp`
3. Subir el ZIP: `dist/stifli-flex-mcp-1.0.0.zip`
4. Esperar aprobación del equipo de WordPress.org

## 📋 Checklist de Verificación

Antes de enviar a producción, verifica:

- [x] ✅ Archivo principal renombrado a `stifli-flex-mcp.php`
- [x] ✅ Plugin Name actualizado en header
- [x] ✅ Plugin URI apunta al nuevo repo
- [x] ✅ Text Domain actualizado a `stifli-flex-mcp`
- [x] ✅ Todas las clases usan prefijo `StifliFlexMcp`
- [x] ✅ Todas las funciones usan prefijo `stifli_flex_mcp_`
- [x] ✅ Tablas de BD usan prefijo `sflmcp_`
- [x] ✅ REST API namespace es `stifli-flex-mcp/v1`
- [x] ✅ readme.txt actualizado
- [x] ✅ Documentación actualizada

## 🔧 Testing Local

Antes de distribuir, prueba localmente:

1. **Desinstalar** cualquier versión antigua
2. **Instalar** el ZIP generado
3. **Verificar** que se crean las tablas `wp_sflmcp_*`
4. **Generar** token de prueba
5. **Probar** endpoints:
   ```powershell
   # Test ping
   Invoke-RestMethod -Uri "http://tu-sitio.local/wp-json/stifli-flex-mcp/v1/messages" `
     -Method POST `
     -Headers @{"Authorization"="Bearer TU_TOKEN"} `
     -ContentType "application/json" `
     -Body '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"mcp_ping","arguments":{}},"id":1}'
   ```

6. **Probar** SSE streaming
7. **Verificar** que las herramientas funcionan correctamente

## 📚 Documentación

Toda la documentación ha sido actualizada:
- `README.md` (actualizar en GitHub)
- `readme.txt` (para WordPress.org)
- `RENAMING_CHANGES.md` (historial completo de cambios)
- Archivos en `dev/` (documentación técnica)
- `.github/copilot-instructions.md` (instrucciones para Copilot)

## 🆘 Soporte

Si encuentras alguna referencia al nombre antiguo que no se haya actualizado:
1. Buscar en el código: `Get-ChildItem -Recurse | Select-String "easy.visual.mcp"`
2. Reemplazar manualmente
3. Reportar en el repositorio

---

**¡Renombrado completado exitosamente!** 🎉

El plugin está listo para ser distribuido como **StifLi Flex MCP**.
