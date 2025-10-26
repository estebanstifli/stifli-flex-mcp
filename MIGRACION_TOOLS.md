# Migración de herramientas MCP a EasyVisualMcp

Este documento describe los pasos para migrar todas las herramientas (tools) del modelo MCP original (`WaicMcpModel`) al nuevo plugin `EasyVisualMcpModel`, asegurando compatibilidad total.

## Pasos de migración

1. **Preparar el entorno y helpers**
   - Verificar que existen y funcionan los helpers/utilidades: `EasyVisualMcpUtils`, `EasyVisualMcpFrame`, `EasyVisualMcpDispatcher`.
   - Confirmar que el modelo base y el método `dispatchTool` están definidos.

2. **Copiar el array completo de herramientas**
   - Copiar el array `$tools` de `WaicMcpModel::getTools()` a `EasyVisualMcpModel::getTools()`, adaptando nombres y dependencias si es necesario.

3. **Migrar la lógica de dispatch por bloques**
   - Adaptar y pegar la lógica de cada herramienta en el método `dispatchTool`, bloque a bloque:
     - [x] **Posts**: `wp_get_posts`, `wp_get_post`, `wp_create_post`, `wp_update_post`, `wp_delete_post`
     - [ ] **Usuarios**: `wp_get_users`, `wp_create_user`, `wp_update_user`
     - [ ] **Comentarios**: `wp_get_comments`, `wp_create_comment`, `wp_update_comment`, `wp_delete_comment`
     - [ ] **Meta y opciones**: `wp_get_option`, `wp_update_option`, `wp_get_post_meta`, `wp_update_post_meta`, `wp_delete_post_meta`
   - [x] **Taxonomías y términos**: `wp_get_taxonomies`, `wp_get_terms`, `wp_create_term`, `wp_update_term`, `wp_delete_term`, `wp_get_post_terms`, `wp_add_post_terms`, `wp_count_terms`
   - [x] **Media**: `wp_get_media`, `wp_upload_media`, `wp_update_media`, `wp_delete_media`, `wp_set_featured_image`, `wp_count_media`, `aiwu_image`
   - [x] **Otras**: `wp_list_plugins`, `wp_count_posts`, `search`, `fetch`, etc.

4. **Adaptar dependencias y comprobaciones de permisos**
   - Cambiar `WaicUtils` por `EasyVisualMcpUtils`, `WaicFrame` por `EasyVisualMcpFrame`, etc.
   - Asegurar que cada herramienta respeta los permisos y roles necesarios.

5. **Testear cada bloque de herramientas**
   - Probar cada grupo de tools usando el endpoint `/messages`.
   - Verificar que los resultados y errores coinciden con el MCP original.

6. **Documentar y limpiar código**
   - Añadir comentarios y documentación en el código migrado.
   - Eliminar código obsoleto o duplicado.

---

## Progreso actual


 [x] Meta y opciones: `wp_get_option`, `wp_update_option`, `wp_get_post_meta`, `wp_update_post_meta`, `wp_delete_post_meta`

Se recomienda migrar y probar cada bloque de herramientas uno a uno, siguiendo este checklist.
