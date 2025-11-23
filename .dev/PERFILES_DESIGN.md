# 📋 INFORME: Sistema de Perfiles para StifLi Flex MCP

## 1. ANÁLISIS DE LA SITUACIÓN ACTUAL

### Estado Actual
- **Tabla `wp_SFLMCP_tools`**: Almacena todas las herramientas (135 tools) con campos:
  - `id`, `tool_name`, `tool_description`, `category`, `enabled`, `created_at`, `updated_at`
- **Admin UI**: 2 pestañas (Configuración + Gestión de Herramientas)
- **Gestión actual**: ON/OFF individual por tool, agrupadas por categoría
- **Filtrado**: `getToolsList()` retorna solo tools con `enabled = 1`

### Problema
- Cambiar configuración para diferentes contextos requiere activar/desactivar manualmente docenas de tools
- No hay forma de guardar configuraciones predefinidas
- No se pueden compartir configuraciones entre sitios

---

## 2. PROPUESTA DE SOLUCIÓN: SISTEMA DE PERFILES

### 2.1 Concepto

**Perfil** = Conjunto nombrado de herramientas habilitadas/deshabilitadas que representa un caso de uso específico.

**Ejemplos de perfiles predefinidos**:
1. **WordPress Lectura** - Solo consultas WP (posts, users, taxonomías) - ~35 tools
2. **WordPress Gestión Completa** - Todo WordPress incluyendo write - ~69 tools
3. **WooCommerce Solo Lectura** - Consultas de productos, órdenes, clientes - ~20 tools
4. **WooCommerce Gestión Tienda** - Stock, productos, órdenes, cupones - ~40 tools
5. **E-commerce Completo** - Todo WooCommerce - ~66 tools
6. **Sitio Completo** - Todas las herramientas - 135 tools
7. **Modo Seguro** - Solo lectura no sensible (sin get_option, get_user_meta) - ~50 tools
8. **Desarrollo/Debug** - Health, post types, settings, system status - ~15 tools

### 2.2 Funcionalidades Requeridas

#### Gestión de Perfiles
- ✅ **Crear perfil personalizado** (nombre, descripción)
- ✅ **Editar perfil** (cambiar nombre, descripción, tools incluidas)
- ✅ **Duplicar perfil** (clonar como base para nuevo perfil)
- ✅ **Eliminar perfil**
- ✅ **Aplicar perfil** (activar/desactivar tools en `wp_SFLMCP_tools` según perfil)
- ✅ **Exportar perfil** (JSON descargable)
- ✅ **Importar perfil** (subir JSON desde otro sitio)

#### Perfiles Predefinidos
- ✅ Seed inicial con 8 perfiles recomendados
- ✅ Marcar perfiles como "system" (no eliminables, solo clonables)
- ✅ Botón "Restaurar perfiles del sistema" en caso de borrado accidental

---

## 3. DISEÑO DE BASE DE DATOS

### 3.1 Nueva Tabla: `wp_SFLMCP_profiles`

```sql
CREATE TABLE wp_SFLMCP_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  profile_name VARCHAR(191) NOT NULL,
  profile_description TEXT,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY profile_name (profile_name),
  KEY is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Campos**:
- `id`: PK autoincremental
- `profile_name`: Nombre único del perfil (ej: "WordPress Lectura")
- `profile_description`: Texto descriptivo
- `is_system`: 1 = perfil predefinido (no eliminable), 0 = personalizado
- `is_active`: 1 = perfil actualmente aplicado, 0 = inactivo (solo 1 puede estar activo)
- `created_at`, `updated_at`: Timestamps

### 3.2 Nueva Tabla: `wp_SFLMCP_profile_tools`

```sql
CREATE TABLE wp_SFLMCP_profile_tools (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  profile_id BIGINT UNSIGNED NOT NULL,
  tool_name VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY profile_tool (profile_id, tool_name),
  KEY profile_id (profile_id),
  FOREIGN KEY (profile_id) REFERENCES wp_SFLMCP_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Campos**:
- `profile_id`: FK a `wp_SFLMCP_profiles`
- `tool_name`: Nombre de la tool incluida en el perfil (ej: "wp_get_posts")
- **Relación**: Si una tool está en esta tabla para un profile_id, está incluida en ese perfil

### 3.3 Modificaciones a Tabla Existente

**`wp_SFLMCP_tools`**: ❌ **NO requiere cambios**
- Sigue siendo la "fuente de verdad" de qué está habilitado/deshabilitado AHORA
- Los perfiles modifican el campo `enabled` al aplicarse

---

## 4. ARQUITECTURA DE LA UI

### 4.1 Nueva Pestaña: "Perfiles"

**Ubicación**: Entre "Configuración" y "Gestión de Herramientas"

```
[Configuración] [Perfiles] [Gestión de Herramientas]
```

### 4.2 Secciones de la Pestaña Perfiles

#### A) Lista de Perfiles (Tabla Principal)
```
┌─────────────────────────────────────────────────────────────────────┐
│ Perfiles Disponibles                                                │
├──────────────────┬─────────────────┬──────────┬─────────────────────┤
│ Nombre           │ Descripción     │ Tools    │ Acciones            │
├──────────────────┼─────────────────┼──────────┼─────────────────────┤
│ ● WP Lectura     │ Solo consultas  │ 35/135   │ [Aplicar] [Editar]  │
│   (Sistema)      │ WordPress       │          │ [Duplicar] [Export] │
├──────────────────┼─────────────────┼──────────┼─────────────────────┤
│   Mi Perfil Blog │ Blog personal   │ 42/135   │ [Aplicar] [Editar]  │
│                  │ sin WooCommerce │          │ [Duplicar] [Eliminar]│
└──────────────────┴─────────────────┴──────────┴─────────────────────┘
```

- **● (bullet activo)**: Indica el perfil actualmente aplicado
- **Botones por perfil**:
  - **Aplicar**: Actualiza `wp_SFLMCP_tools.enabled` según tools del perfil
  - **Editar**: Abre modal para seleccionar tools
  - **Duplicar**: Crea copia con nombre "Copia de [nombre]"
  - **Exportar**: Descarga JSON
  - **Eliminar**: Solo visible si `is_system = 0`

#### B) Acciones Globales (Botones Superiores)
```
[+ Nuevo Perfil] [⬆ Importar JSON] [🔄 Restaurar Perfiles Sistema]
```

- **Nuevo Perfil**: Modal para crear desde cero
- **Importar JSON**: Upload de archivo `.json`
- **Restaurar Perfiles Sistema**: Re-seed los 8 perfiles predefinidos

#### C) Modal de Edición de Perfil
```
┌─────────────────────────────────────────────────────┐
│ Editar Perfil: "Mi Perfil Blog"                    │
├─────────────────────────────────────────────────────┤
│ Nombre: [____________________________]              │
│ Descripción: [________________________]             │
│                                                     │
│ Herramientas Incluidas (42 seleccionadas):         │
│                                                     │
│ ☑ Seleccionar/Deseleccionar Todo                   │
│                                                     │
│ ▼ WordPress - Posts                                │
│   ☑ wp_get_posts                                   │
│   ☑ wp_get_post                                    │
│   ☐ wp_create_post      (write)                    │
│   ☐ wp_update_post      (write)                    │
│   ☐ wp_delete_post      (write)                    │
│                                                     │
│ ▼ WordPress - Users                                │
│   ☑ wp_get_users                                   │
│   ☐ wp_create_user      (write)                    │
│   ...                                              │
│                                                     │
│ [Guardar Cambios] [Cancelar]                       │
└─────────────────────────────────────────────────────┘
```

**Características del modal**:
- Agrupado por categoría (collapsible accordions)
- Checkboxes individuales por tool
- Indicador visual: `(write)`, `(sensitive)` según intent
- Contador dinámico de tools seleccionadas
- Búsqueda/filtro por nombre de tool

---

## 5. FORMATO DE EXPORTACIÓN (JSON)

```json
{
  "format_version": "1.0",
  "export_date": "2025-11-05T14:30:00Z",
  "plugin_version": "0.1.0",
  "profile": {
    "name": "WordPress Lectura",
    "description": "Solo herramientas de consulta WordPress sin operaciones de escritura",
    "tools": [
      "mcp_ping",
      "wp_get_posts",
      "wp_get_post",
      "wp_get_pages",
      "wp_get_comments",
      "wp_get_users",
      "wp_get_taxonomies",
      "wp_get_terms",
      "wp_get_categories",
      "wp_get_tags",
      "wp_get_media",
      "wp_get_post_types",
      "wp_get_post_revisions"
    ],
    "tools_count": 35,
    "categories_included": [
      "Core",
      "WordPress - Posts",
      "WordPress - Pages",
      "WordPress - Comments",
      "WordPress - Users",
      "WordPress - Taxonomies"
    ]
  }
}
```

**Validación en importación**:
- Verificar `format_version` compatible
- Validar que todas las tools existen en `wp_SFLMCP_tools`
- Ignorar tools no existentes (con warning)
- Permitir renombrar perfil si ya existe

---

## 6. PERFILES PREDEFINIDOS (SEED INICIAL)

### 6.1 WordPress Lectura
- **Tools**: 35
- **Incluye**: Todos los `wp_get_*` (posts, pages, users, comments, taxonomies, media, post_types, post_revisions)
- **Excluye**: wp_get_option, wp_get_post_meta, wp_get_user_meta, wp_get_settings, wp_get_site_health (sensibles)

### 6.2 WordPress Gestión Completa
- **Tools**: 69 (todas las WP)
- **Incluye**: CRUD completo de posts, users, media, taxonomías, plugins, themes, options

### 6.3 WooCommerce Solo Lectura
- **Tools**: ~20
- **Incluye**: wc_get_products, wc_get_orders, wc_get_customers, wc_get_coupons, wc_get_reviews, wc_get_low_stock_products, wc_get_refunds

### 6.4 WooCommerce Gestión Tienda
- **Tools**: ~40
- **Incluye**: Productos (CRUD + stock), Órdenes (CRUD + notes), Cupones (CRUD), Stock management
- **Excluye**: System, Tax, Shipping, Webhooks (más avanzado)

### 6.5 E-commerce Completo
- **Tools**: 66 (todas las WC)
- **Incluye**: Todo WooCommerce

### 6.6 Sitio Completo
- **Tools**: 135 (todas)
- **Incluye**: TODO

### 6.7 Modo Seguro (Solo Lectura No Sensible)
- **Tools**: ~50
- **Incluye**: wp_get_* básicos, wc_get_* básicos
- **Excluye**: get_option, get_settings, get_user_meta, get_site_health, system_status

### 6.8 Desarrollo/Debug
- **Tools**: ~15
- **Incluye**: mcp_ping, wp_get_site_health, wp_get_post_types, wp_get_settings, wc_get_system_status, wp_list_plugins, wp_get_themes

---

## 7. FLUJO DE APLICACIÓN DE PERFILES

### Cuando usuario hace clic en "Aplicar"

```php
1. Obtener todas las tools del perfil desde wp_SFLMCP_profile_tools
2. Obtener todas las tools existentes desde wp_SFLMCP_tools
3. Para cada tool en wp_SFLMCP_tools:
   - Si está en la lista del perfil → enabled = 1
   - Si NO está en la lista del perfil → enabled = 0
4. Marcar perfil como activo: UPDATE wp_SFLMCP_profiles SET is_active = 0 (all)
5. UPDATE wp_SFLMCP_profiles SET is_active = 1 WHERE id = [profile_id]
6. Mostrar mensaje: "Perfil 'X' aplicado. 42/135 herramientas habilitadas"
```

### Detección de Cambios Manuales

**Problema**: Si usuario va a "Gestión de Herramientas" y cambia enabled manualmente, el perfil activo ya no coincide.

**Solución**:
- En pestaña "Gestión de Herramientas", mostrar banner si hay perfil activo:
  ```
  ⚠️ Perfil activo: "WordPress Lectura" (35 tools)
  Si modificas herramientas manualmente, el perfil se desactivará.
  [Ver Perfiles] [Desactivar Perfil]
  ```
- Al guardar cambios manuales, ejecutar:
  ```php
  UPDATE wp_SFLMCP_profiles SET is_active = 0 WHERE is_active = 1
  ```

---

## 8. ENDPOINTS AJAX

### Nuevos handlers en `mod.php`

```php
// AJAX actions
add_action('wp_ajax_SFLMCP_create_profile', array($this, 'ajax_create_profile'));
add_action('wp_ajax_SFLMCP_update_profile', array($this, 'ajax_update_profile'));
add_action('wp_ajax_SFLMCP_delete_profile', array($this, 'ajax_delete_profile'));
add_action('wp_ajax_SFLMCP_duplicate_profile', array($this, 'ajax_duplicate_profile'));
add_action('wp_ajax_SFLMCP_apply_profile', array($this, 'ajax_apply_profile'));
add_action('wp_ajax_SFLMCP_export_profile', array($this, 'ajax_export_profile'));
add_action('wp_ajax_SFLMCP_import_profile', array($this, 'ajax_import_profile'));
add_action('wp_ajax_SFLMCP_restore_system_profiles', array($this, 'ajax_restore_system_profiles'));
```

---

## 9. VENTAJAS DEL DISEÑO PROPUESTO

✅ **Flexibilidad**: Usuarios pueden crear perfiles infinitos  
✅ **Portabilidad**: Export/Import entre sitios  
✅ **Seguridad**: Perfiles predefinidos seguros (solo lectura, modo seguro)  
✅ **UX**: 1 clic para cambiar de contexto (dev → producción → cliente)  
✅ **Escalabilidad**: Fácil añadir nuevos perfiles en updates del plugin  
✅ **Compatibilidad**: No rompe configuración actual (tabla tools sigue igual)  
✅ **Performance**: Foreign key con CASCADE elimina tools huérfanas automáticamente  

---

## 10. PLAN DE IMPLEMENTACIÓN (RECOMENDADO)

### Fase 1: Base de Datos (30 min)
1. Crear función `stifli_flex_mcp_maybe_create_profiles_table()`
2. Crear función `stifli_flex_mcp_maybe_create_profile_tools_table()`
3. Crear función `stifli_flex_mcp_seed_system_profiles()` (8 perfiles)
4. Hook en `register_activation_hook`

### Fase 2: Backend Logic (1-2 horas)
1. Métodos CRUD en `mod.php`:
   - `createProfile($name, $description, $tools)`
   - `updateProfile($id, $name, $description, $tools)`
   - `deleteProfile($id)`
   - `duplicateProfile($id)`
   - `applyProfile($id)` → actualiza `wp_SFLMCP_tools.enabled`
   - `exportProfile($id)` → genera JSON
   - `importProfile($json_data)`
   - `restoreSystemProfiles()`
2. AJAX handlers (8 funciones)

### Fase 3: Frontend UI (2-3 horas)
1. Nueva pestaña "Perfiles" en admin menu
2. Renderizar `renderProfilesTab()`
3. Tabla de perfiles con acciones
4. Modal de edición (accordion por categoría)
5. Botones globales (nuevo, importar, restaurar)
6. JavaScript para AJAX calls

### Fase 4: Testing (30 min)
1. Crear perfil personalizado
2. Aplicar perfil → verificar tools enabled
3. Exportar → importar en otro sitio
4. Modificar manualmente → verificar desactivación de perfil

**Tiempo total estimado: 4-6 horas**

---

## 11. CONSIDERACIONES ADICIONALES

### Seguridad
- ✅ `wp_verify_nonce()` en todos los AJAX handlers
- ✅ `current_user_can('manage_options')` en todos los endpoints
- ✅ Sanitizar `profile_name` con `sanitize_text_field()`
- ✅ Validar JSON en importación (evitar injection)

### Performance
- ✅ Index en `is_active` para lookup rápido
- ✅ UNIQUE constraint en `profile_name` evita duplicados
- ✅ Foreign key CASCADE elimina profile_tools automáticamente

### Backup
- ✅ Antes de aplicar perfil, guardar estado actual en option:
  ```php
  update_option('SFLMCP_last_manual_config', $current_tools_state);
  ```
- ✅ Botón "Deshacer último cambio" en UI

### Multisite
- ✅ Usar `$wpdb->prefix` correctamente
- ✅ Cada site tiene sus propios perfiles
- ✅ Considerar export/import para clonar entre sites

---

## 12. MOCKUP VISUAL COMPLETO

```
┌──────────────────────────────────────────────────────────────────────┐
│ StifLi Flex MCP                                                      │
├──────────────────────────────────────────────────────────────────────┤
│ [Configuración] [Perfiles ●] [Gestión de Herramientas]              │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ Gestiona perfiles de herramientas para diferentes casos de uso      │
│                                                                      │
│ [+ Nuevo Perfil] [⬆ Importar] [🔄 Restaurar Sistema]               │
│                                                                      │
│ ┌────────────────────────────────────────────────────────────────┐  │
│ │ Perfiles del Sistema (no eliminables)                         │  │
│ ├──────────────────┬─────────────────┬─────────┬────────────────┤  │
│ │ ● WP Lectura     │ Solo consultas  │  35/135 │ [Editar] [Exp] │  │
│ │   WP Completo    │ Todo WordPress  │  69/135 │ [Aplicar] [E]  │  │
│ │   WC Lectura     │ WooCommerce GET │  20/135 │ [Aplicar] [E]  │  │
│ │   WC Tienda      │ Gestión tienda  │  40/135 │ [Aplicar] [E]  │  │
│ │   E-com Completo │ Todo WC         │  66/135 │ [Aplicar] [E]  │  │
│ │   Sitio Completo │ Todas (135)     │ 135/135 │ [Aplicar] [E]  │  │
│ │   Modo Seguro    │ Solo lectura    │  50/135 │ [Aplicar] [E]  │  │
│ │   Debug          │ Diagnóstico     │  15/135 │ [Aplicar] [E]  │  │
│ └──────────────────┴─────────────────┴─────────┴────────────────┘  │
│                                                                      │
│ ┌────────────────────────────────────────────────────────────────┐  │
│ │ Perfiles Personalizados                                        │  │
│ ├──────────────────┬─────────────────┬─────────┬────────────────┤  │
│ │   Mi Blog        │ Sin WooCommerce │  42/135 │ [Aplicar] [Ed] │  │
│ │                  │                 │         │ [Dup] [Del]    │  │
│ └──────────────────┴─────────────────┴─────────┴────────────────┘  │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

---

## ✅ CONCLUSIÓN

Este diseño proporciona un **sistema robusto, flexible y user-friendly** para gestionar perfiles de herramientas que:

1. ✅ Resuelve el problema de cambios de contexto frecuentes
2. ✅ Permite portabilidad entre sitios
3. ✅ Mantiene compatibilidad con sistema actual
4. ✅ Es extensible para futuras features
5. ✅ Sigue best practices de WordPress (nonces, capabilities, wpdb)
6. ✅ Tiene UX intuitiva con 3 clics máximo para cualquier acción
