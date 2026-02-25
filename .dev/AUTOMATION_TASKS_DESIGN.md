# 📋 StifLi Flex MCP - Sistema de Automatización de Tareas con IA

## Informe de Diseño y Análisis de Funcionalidad

**Versión:** 1.0  
**Fecha:** Febrero 2026  
**Estado:** Propuesta de Diseño

---

## 1. Resumen Ejecutivo

Esta propuesta define un sistema de **automatización de tareas programadas** que permite a los usuarios crear "recetas" de IA que se ejecutan automáticamente según un calendario definido. La funcionalidad integra el chat existente de IA con el sistema de cron de WordPress, permitiendo que prompts guardados se ejecuten sin intervención del usuario.

### Valor Principal
> **"Configura una vez, automatiza para siempre"** — Los usuarios podrán crear tareas que la IA ejecute periódicamente usando las herramientas MCP disponibles, ahorrando horas de trabajo manual.

---

## 2. Análisis de la Arquitectura Existente

### 2.1 Componentes Reutilizables

| Componente | Ubicación | Función | Reutilización |
|------------|-----------|---------|---------------|
| `StifliFlexMcp_Client_Admin` | `client/class-client-admin.php` | Chat UI + providers | **Alta** - Base del sistema de prompts |
| Providers (OpenAI/Claude/Gemini) | `client/providers/*.php` | Comunicación con APIs | **Total** - Sin cambios necesarios |
| Sistema de Tools | `models/model.php` | 117+ herramientas MCP | **Total** - Reutilizable completamente |
| DB Tables Pattern | `stifli-flex-mcp.php` | Patrón de creación de tablas | **Alta** - Copiar estructura |
| WP-Cron existente | `stifli-flex-mcp.php:1243` | Queue cleanup hourly | **Referencia** - Patrón a seguir |
| Admin UI Pattern | `mod.php` | Tabs + AJAX handlers | **Alta** - Extender con nueva tab |

### 2.2 Integración con Sistema Actual de Chat

El sistema de chat actual (`class-client-admin.php`) ya implementa:
- ✅ Selección de provider (OpenAI/Claude/Gemini)
- ✅ Configuración de API keys (encriptadas)
- ✅ Sistema de prompts con variables
- ✅ Ejecución de tools MCP
- ✅ Historial de conversaciones (`sflmcp_chat_history_*` transients)
- ✅ Manejo de permisos por tool

**Oportunidad**: Extraer la lógica de ejecución de chat a una clase compartida que pueda usarse tanto para chat interactivo como para tareas automatizadas.

---

## 3. Diseño de Base de Datos

### 3.1 Nueva Tabla: `wp_sflmcp_automation_tasks`

```sql
CREATE TABLE {$prefix}sflmcp_automation_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- Identificación
    task_name VARCHAR(191) NOT NULL,
    task_description TEXT,
    
    -- Configuración del Prompt
    prompt TEXT NOT NULL,
    system_prompt TEXT,
    
    -- Proveedor de IA
    provider VARCHAR(50) NOT NULL DEFAULT 'openai',
    model VARCHAR(100) NOT NULL DEFAULT 'gpt-5.2-chat-latest',
    
    -- Tools permitidas (JSON array de tool_names, NULL = usar perfil activo)
    allowed_tools LONGTEXT,
    
    -- Programación
    schedule_type ENUM('preset', 'cron') NOT NULL DEFAULT 'preset',
    schedule_preset VARCHAR(50),      -- 'hourly', 'daily', 'weekly_monday', etc.
    schedule_cron VARCHAR(100),       -- Expresión cron personalizada
    schedule_time TIME,               -- Hora específica (para daily/weekly)
    schedule_timezone VARCHAR(50) DEFAULT 'UTC',
    
    -- Acciones post-ejecución
    output_action ENUM('log', 'email', 'webhook', 'draft', 'custom') DEFAULT 'log',
    output_config LONGTEXT,           -- JSON con config específica de la acción
    
    -- Estado y control
    status ENUM('active', 'paused', 'error', 'draft') NOT NULL DEFAULT 'draft',
    max_retries TINYINT UNSIGNED DEFAULT 3,
    retry_count TINYINT UNSIGNED DEFAULT 0,
    
    -- Timestamps
    last_run DATETIME,
    next_run DATETIME,
    last_success DATETIME,
    last_error TEXT,
    
    -- Metadata
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    PRIMARY KEY (id),
    UNIQUE KEY task_name (task_name),
    KEY status_next_run (status, next_run),
    KEY created_by (created_by)
) {$charset_collate};
```

### 3.2 Nueva Tabla: `wp_sflmcp_automation_logs`

```sql
CREATE TABLE {$prefix}sflmcp_automation_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    
    -- Ejecución
    started_at DATETIME NOT NULL,
    completed_at DATETIME,
    execution_time_ms INT UNSIGNED,
    
    -- Resultado
    status ENUM('running', 'success', 'error', 'timeout', 'cancelled') NOT NULL,
    
    -- Detalle
    prompt_used TEXT,
    ai_response LONGTEXT,
    tools_called LONGTEXT,           -- JSON array de tools ejecutadas
    tools_results LONGTEXT,          -- JSON con resultados de cada tool
    
    -- Tokens/Costos
    tokens_input INT UNSIGNED DEFAULT 0,
    tokens_output INT UNSIGNED DEFAULT 0,
    estimated_cost DECIMAL(10,6) DEFAULT 0,
    
    -- Error info
    error_message TEXT,
    error_code VARCHAR(50),
    
    -- Output action result
    output_sent TINYINT(1) DEFAULT 0,
    output_result TEXT,
    
    PRIMARY KEY (id),
    KEY task_id_started (task_id, started_at),
    KEY status (status)
) {$charset_collate};
```

### 3.3 Nueva Tabla: `wp_sflmcp_automation_templates` (Galería de Recetas)

```sql
CREATE TABLE {$prefix}sflmcp_automation_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- Identificación
    template_name VARCHAR(191) NOT NULL,
    template_slug VARCHAR(100) NOT NULL,
    template_description TEXT,
    template_icon VARCHAR(50) DEFAULT 'dashicons-admin-generic',
    
    -- Categoría
    category ENUM('content', 'ecommerce', 'moderation', 'analytics', 'maintenance', 'social', 'custom') NOT NULL,
    
    -- Contenido
    default_prompt TEXT NOT NULL,
    default_system_prompt TEXT,
    suggested_tools LONGTEXT,        -- JSON array de tools sugeridas
    suggested_schedule VARCHAR(50),
    
    -- Metadata
    is_system TINYINT(1) DEFAULT 0,  -- Templates predefinidos
    popularity INT UNSIGNED DEFAULT 0,
    
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    PRIMARY KEY (id),
    UNIQUE KEY template_slug (template_slug),
    KEY category (category),
    KEY is_system (is_system)
) {$charset_collate};
```

---

## 4. Presets de Programación

### 4.1 Opciones Predefinidas (UX Simplificada)

```php
const SCHEDULE_PRESETS = array(
    // === CADA HORA ===
    'every_hour' => array(
        'label' => 'Cada hora',
        'cron'  => '0 * * * *',
        'icon'  => 'dashicons-clock',
        'description' => 'Se ejecuta al inicio de cada hora'
    ),
    'every_2_hours' => array(
        'label' => 'Cada 2 horas',
        'cron'  => '0 */2 * * *',
        'icon'  => 'dashicons-clock',
        'description' => 'Se ejecuta cada 2 horas'
    ),
    'every_6_hours' => array(
        'label' => 'Cada 6 horas',
        'cron'  => '0 */6 * * *',
        'icon'  => 'dashicons-clock',
        'description' => 'Se ejecuta 4 veces al día'
    ),
    'every_12_hours' => array(
        'label' => 'Cada 12 horas',
        'cron'  => '0 */12 * * *',
        'icon'  => 'dashicons-clock',
        'description' => 'Se ejecuta 2 veces al día (mañana y noche)'
    ),
    
    // === DIARIOS ===
    'daily_morning' => array(
        'label' => 'Cada día a las 8:00',
        'cron'  => '0 8 * * *',
        'icon'  => 'dashicons-calendar-alt',
        'description' => 'Ideal para reportes matutinos'
    ),
    'daily_noon' => array(
        'label' => 'Cada día a las 12:00',
        'cron'  => '0 12 * * *',
        'icon'  => 'dashicons-calendar-alt',
        'description' => 'Ejecución al mediodía'
    ),
    'daily_evening' => array(
        'label' => 'Cada día a las 18:00',
        'cron'  => '0 18 * * *',
        'icon'  => 'dashicons-calendar-alt',
        'description' => 'Resumen de fin de jornada'
    ),
    'daily_custom' => array(
        'label' => 'Diario (hora personalizada)',
        'cron'  => '0 {hour} * * *',
        'icon'  => 'dashicons-calendar-alt',
        'description' => 'Selecciona la hora exacta',
        'requires_time' => true
    ),
    
    // === SEMANALES ===
    'weekly_monday' => array(
        'label' => 'Cada lunes',
        'cron'  => '0 9 * * 1',
        'icon'  => 'dashicons-calendar',
        'description' => 'Lunes a las 9:00 AM'
    ),
    'weekly_friday' => array(
        'label' => 'Cada viernes',
        'cron'  => '0 17 * * 5',
        'icon'  => 'dashicons-calendar',
        'description' => 'Viernes a las 17:00 (resumen semanal)'
    ),
    'weekly_sunday' => array(
        'label' => 'Cada domingo',
        'cron'  => '0 10 * * 0',
        'icon'  => 'dashicons-calendar',
        'description' => 'Domingo a las 10:00 (preparación semana)'
    ),
    'weekdays_only' => array(
        'label' => 'Días laborables',
        'cron'  => '0 9 * * 1-5',
        'icon'  => 'dashicons-calendar',
        'description' => 'Lunes a viernes a las 9:00'
    ),
    
    // === MENSUALES ===
    'monthly_first' => array(
        'label' => 'Primer día del mes',
        'cron'  => '0 9 1 * *',
        'icon'  => 'dashicons-calendar',
        'description' => 'Día 1 de cada mes a las 9:00'
    ),
    'monthly_15' => array(
        'label' => 'Día 15 del mes',
        'cron'  => '0 9 15 * *',
        'icon'  => 'dashicons-calendar',
        'description' => 'Día 15 de cada mes a las 9:00'
    ),
    'monthly_last' => array(
        'label' => 'Último día del mes',
        'cron'  => '0 18 L * *',  // WordPress Action Scheduler syntax
        'icon'  => 'dashicons-calendar',
        'description' => 'Último día de cada mes a las 18:00'
    ),
    
    // === AVANZADO ===
    'custom_cron' => array(
        'label' => 'Expresión Cron personalizada',
        'cron'  => null,
        'icon'  => 'dashicons-admin-tools',
        'description' => 'Define tu propia expresión cron',
        'requires_cron_input' => true
    ),
);
```

---

## 5. Sistema de Optimización de Tools

### 5.1 Captura Automática de Tools Utilizadas

Una de las características más innovadoras: **detectar y guardar automáticamente las tools que la IA utiliza durante la prueba del prompt**.

```php
/**
 * Analiza una ejecución de prueba y extrae las tools utilizadas
 */
class StifliFlexMcp_Automation_ToolOptimizer {
    
    /**
     * Ejecuta el prompt en modo prueba y captura tools usadas
     * 
     * @param string $prompt El prompt a ejecutar
     * @param array $options Opciones de ejecución
     * @return array Resultado con tools_used y sugerencias
     */
    public function analyze_prompt_tools($prompt, $options = array()) {
        $used_tools = array();
        $tool_calls_log = array();
        
        // Hook temporal para capturar llamadas a tools
        add_filter('sflmcp_before_tool_dispatch', function($tool_name, $args) use (&$used_tools, &$tool_calls_log) {
            if (!in_array($tool_name, $used_tools)) {
                $used_tools[] = $tool_name;
            }
            $tool_calls_log[] = array(
                'tool' => $tool_name,
                'args' => $args,
                'timestamp' => microtime(true)
            );
            return $tool_name;
        }, 10, 2);
        
        // Ejecutar prompt (límite de tools para seguridad)
        $result = $this->execute_prompt_limited($prompt, $options);
        
        // Análisis de eficiencia
        $analysis = array(
            'tools_used' => $used_tools,
            'tools_count' => count($used_tools),
            'execution_log' => $tool_calls_log,
            'tokens_estimated' => $this->estimate_tokens_for_tools($used_tools),
            'suggestions' => $this->generate_tool_suggestions($used_tools, $prompt)
        );
        
        return array(
            'success' => !isset($result['error']),
            'result' => $result,
            'analysis' => $analysis
        );
    }
    
    /**
     * Genera sugerencias de optimización
     */
    private function generate_tool_suggestions($used_tools, $prompt) {
        $suggestions = array();
        
        // Sugerir guardar solo las tools usadas
        if (count($used_tools) < 10) {
            $suggestions[] = array(
                'type' => 'save_tools',
                'message' => sprintf(
                    'Esta tarea solo usa %d tools. Guardar solo estas tools ahorrará ~%d tokens por ejecución.',
                    count($used_tools),
                    $this->calculate_token_savings($used_tools)
                ),
                'action' => 'save_used_tools',
                'tools' => $used_tools
            );
        }
        
        // Detectar tools relacionadas que podrían necesitarse
        $related = $this->find_related_tools($used_tools);
        if (!empty($related)) {
            $suggestions[] = array(
                'type' => 'add_related',
                'message' => 'Podrías necesitar estas tools relacionadas para casos edge:',
                'tools' => $related
            );
        }
        
        return $suggestions;
    }
}
```

### 5.2 Modos de Selección de Tools

```php
/**
 * Tres modos de selección de tools para cada tarea
 */
const TOOL_SELECTION_MODES = array(
    'profile' => array(
        'label' => 'Usar perfil activo del MCP',
        'description' => 'Utiliza las tools habilitadas en el perfil MCP actual',
        'icon' => 'dashicons-groups',
        'token_impact' => 'variable'
    ),
    'detected' => array(
        'label' => 'Solo tools detectadas (recomendado)',
        'description' => 'Usa únicamente las tools que la IA utilizó durante la prueba',
        'icon' => 'dashicons-performance',
        'token_impact' => 'mínimo'
    ),
    'custom' => array(
        'label' => 'Selección manual',
        'description' => 'Elige manualmente qué tools puede usar esta tarea',
        'icon' => 'dashicons-admin-settings',
        'token_impact' => 'personalizado'
    ),
    'all' => array(
        'label' => 'Todas las tools',
        'description' => 'Acceso completo a todas las 117+ tools MCP',
        'icon' => 'dashicons-admin-plugins',
        'token_impact' => 'máximo (~3500 tokens)'
    ),
);
```

---

## 6. Casos de Uso Detallados (Templates Predefinidos)

### 6.1 Categoría: Generación de Contenido

#### 📝 Daily Blog Summary
```php
array(
    'template_name' => 'Resumen Diario del Blog',
    'template_slug' => 'daily-blog-summary',
    'category' => 'content',
    'template_icon' => 'dashicons-admin-post',
    'default_prompt' => <<<PROMPT
Analiza los últimos 5 posts publicados en mi blog y genera un resumen ejecutivo que incluya:

1. **Temas principales**: Identifica los temas más tratados esta semana
2. **Rendimiento**: Si hay comentarios, analiza el engagement
3. **Sugerencia de contenido**: Basándote en los temas tratados, sugiere 3 ideas para nuevos posts
4. **Borrador de post**: Crea un borrador corto (200 palabras) sobre la idea más prometedora

Formato el resultado para que sea fácil de leer y guárdalo como un post en borrador con el título "Resumen Semanal - [fecha]".
PROMPT,
    'suggested_tools' => array('wp_get_posts', 'wp_get_comments', 'wp_create_post'),
    'suggested_schedule' => 'weekly_monday'
)
```

#### 🎯 SEO Meta Optimizer
```php
array(
    'template_name' => 'Optimizador SEO Automático',
    'template_slug' => 'seo-meta-optimizer',
    'category' => 'content',
    'template_icon' => 'dashicons-search',
    'default_prompt' => <<<PROMPT
Revisa las páginas y posts del sitio que no tienen meta descripción configurada o cuya meta descripción tiene menos de 120 caracteres.

Para cada una (máximo 5):
1. Lee el contenido del post
2. Genera una meta descripción SEO-optimizada (155-160 caracteres)
3. Incluye la keyword principal del post
4. Actualiza el campo de meta descripción

Genera un reporte de las meta descripciones actualizadas.
PROMPT,
    'suggested_tools' => array('wp_get_posts', 'wp_get_post_meta', 'wp_update_post_meta'),
    'suggested_schedule' => 'weekly_sunday'
)
```

#### 📱 Social Media Content Generator
```php
array(
    'template_name' => 'Generador de Contenido Social',
    'template_slug' => 'social-content-generator',
    'category' => 'social',
    'template_icon' => 'dashicons-share',
    'default_prompt' => <<<PROMPT
Basándote en el último post publicado en el blog:

1. Lee el contenido completo del post más reciente
2. Genera 3 versiones de contenido para redes sociales:
   - **Twitter/X**: Tweet de 240 caracteres máximo con hashtags relevantes
   - **LinkedIn**: Post profesional de 200 palabras con llamada a la acción
   - **Instagram**: Caption de 150 palabras con emojis y 10 hashtags

3. Guarda estos textos como un borrador titulado "Social Media - [título del post]"

Incluye las URLs que enlacen al post original.
PROMPT,
    'suggested_tools' => array('wp_get_posts', 'wp_get_post', 'wp_create_post'),
    'suggested_schedule' => 'daily_morning'
)
```

### 6.2 Categoría: E-commerce (WooCommerce)

#### 📊 Daily Sales Report
```php
array(
    'template_name' => 'Reporte Diario de Ventas',
    'template_slug' => 'daily-sales-report',
    'category' => 'ecommerce',
    'template_icon' => 'dashicons-chart-bar',
    'default_prompt' => <<<PROMPT
Genera un reporte completo de ventas del día anterior:

1. **Resumen de ventas**: Total de pedidos, ingresos totales, ticket medio
2. **Top 5 productos**: Los productos más vendidos con unidades y revenue
3. **Estado de pedidos**: Cuántos pendientes, procesando, completados
4. **Stock crítico**: Productos con stock < 10 unidades que se vendieron ayer
5. **Comparativa**: Compara con el mismo día de la semana anterior si hay datos

Formatea el reporte de forma clara y envíalo por email al administrador.
PROMPT,
    'suggested_tools' => array('wc_get_orders', 'wc_get_products', 'wc_get_reports_sales'),
    'suggested_schedule' => 'daily_morning',
    'default_output_action' => 'email'
)
```

#### 🔔 Low Stock Alert
```php
array(
    'template_name' => 'Alerta de Stock Bajo',
    'template_slug' => 'low-stock-alert',
    'category' => 'ecommerce',
    'template_icon' => 'dashicons-warning',
    'default_prompt' => <<<PROMPT
Analiza el inventario y detecta productos con stock crítico:

1. Busca productos con stock_quantity < 5 unidades
2. Para cada producto encontrado:
   - Nombre y SKU
   - Stock actual
   - Ventas de los últimos 7 días (si hay datos)
   - Prioridad de restock (alta si se vendió recientemente)

3. Genera una lista de restock ordenada por prioridad
4. Calcula unidades recomendadas a pedir basándote en ventas promedio

Envía alerta solo si hay productos con stock crítico.
PROMPT,
    'suggested_tools' => array('wc_get_products', 'wc_get_orders', 'wc_get_reports_stock'),
    'suggested_schedule' => 'daily_evening'
)
```

#### 🎫 Expired Coupons Cleanup
```php
array(
    'template_name' => 'Limpieza de Cupones Expirados',
    'template_slug' => 'expired-coupons-cleanup',
    'category' => 'ecommerce',
    'template_icon' => 'dashicons-trash',
    'default_prompt' => <<<PROMPT
Realiza mantenimiento de cupones:

1. Lista todos los cupones expirados hace más de 30 días
2. Verifica que no tengan pedidos asociados en los últimos 60 días
3. Si cumplen ambas condiciones, elimínalos
4. Genera un reporte de:
   - Cupones eliminados (nombre, fecha expiración)
   - Cupones mantenidos (y por qué)
   - Total de cupones en el sistema

Confirma la acción antes de eliminar.
PROMPT,
    'suggested_tools' => array('wc_get_coupons', 'wc_delete_coupon'),
    'suggested_schedule' => 'monthly_first'
)
```

#### ⭐ Review Response Generator
```php
array(
    'template_name' => 'Generador de Respuestas a Reviews',
    'template_slug' => 'review-response-generator',
    'category' => 'ecommerce',
    'template_icon' => 'dashicons-star-empty',
    'default_prompt' => <<<PROMPT
Gestiona las reseñas de productos:

1. Busca reviews de productos de los últimos 7 días que no tengan respuesta
2. Para cada review:
   - Si es positiva (4-5 estrellas): genera agradecimiento personalizado
   - Si es negativa (1-2 estrellas): genera respuesta empática y profesional ofreciendo solución
   - Si es neutral (3 estrellas): genera respuesta pidiendo feedback adicional

3. Guarda las respuestas sugeridas como borrador de comentario
4. Notifica al admin con un resumen de reviews pendientes de aprobar

IMPORTANTE: Las respuestas deben ser únicas, no genéricas. Menciona el producto y aspectos específicos del review.
PROMPT,
    'suggested_tools' => array('wp_get_comments', 'wc_get_products', 'wp_create_comment'),
    'suggested_schedule' => 'daily_morning'
)
```

### 6.3 Categoría: Moderación y Mantenimiento

#### 🛡️ Comment Moderation Assistant
```php
array(
    'template_name' => 'Asistente de Moderación de Comentarios',
    'template_slug' => 'comment-moderation-assistant',
    'category' => 'moderation',
    'template_icon' => 'dashicons-shield',
    'default_prompt' => <<<PROMPT
Analiza los comentarios pendientes de moderación:

1. Lista todos los comentarios con status='hold' (pendientes)
2. Para cada uno, analiza:
   - ¿Es spam? (enlaces sospechosos, texto genérico, idioma diferente al sitio)
   - ¿Es ofensivo? (insultos, lenguaje inapropiado)
   - ¿Es una pregunta legítima? (requiere respuesta)

3. Clasifica cada comentario en:
   - ✅ APROBAR: Comentarios legítimos
   - 🗑️ SPAM: Marcar como spam
   - ⚠️ REVISAR: Requiere revisión humana (explicar por qué)

4. Genera respuestas sugeridas para los comentarios que son preguntas

NO elimines ni apruebes automáticamente. Solo genera el reporte con recomendaciones.
PROMPT,
    'suggested_tools' => array('wp_get_comments', 'wp_get_post'),
    'suggested_schedule' => 'every_6_hours'
)
```

#### 🧹 Content Quality Audit
```php
array(
    'template_name' => 'Auditoría de Calidad de Contenido',
    'template_slug' => 'content-quality-audit',
    'category' => 'maintenance',
    'template_icon' => 'dashicons-visibility',
    'default_prompt' => <<<PROMPT
Realiza una auditoría de calidad del contenido del sitio:

1. Identifica posts/páginas con problemas:
   - Contenido muy corto (< 300 palabras)
   - Sin imágenes destacadas
   - Títulos sin optimizar (muy cortos o muy largos)
   - Sin categorías o etiquetas
   - Enlaces rotos (si es posible verificar)

2. Para cada contenido problemático, genera:
   - Puntuación de calidad (1-10)
   - Lista de mejoras necesarias
   - Prioridad (baja/media/alta basada en tráfico si hay datos)

3. Crea un post tipo "reporte interno" con el resumen de la auditoría

Incluye estadísticas: total de contenidos, % con problemas, mejoras sugeridas totales.
PROMPT,
    'suggested_tools' => array('wp_get_posts', 'wp_get_pages', 'wp_get_post_meta', 'wp_get_categories', 'wp_get_tags'),
    'suggested_schedule' => 'monthly_first'
)
```

### 6.4 Categoría: Analytics e Insights

#### 📈 Weekly Performance Insights
```php
array(
    'template_name' => 'Insights de Rendimiento Semanal',
    'template_slug' => 'weekly-performance-insights',
    'category' => 'analytics',
    'template_icon' => 'dashicons-chart-line',
    'default_prompt' => <<<PROMPT
Genera un análisis de rendimiento del sitio de la última semana:

1. **Contenido**:
   - Posts publicados esta semana vs semana anterior
   - Comentarios recibidos y ratio de respuesta
   - Post más comentado/popular

2. **WooCommerce** (si aplica):
   - Comparativa de ventas semana a semana
   - Productos trending (más vendidos vs semana anterior)
   - Clientes nuevos vs recurrentes

3. **Usuarios**:
   - Nuevos registros
   - Actividad de comentarios

4. **Recomendaciones**:
   - 3 acciones sugeridas para mejorar engagement
   - 1 oportunidad de contenido detectada

Formatea como un dashboard de texto fácil de escanear.
PROMPT,
    'suggested_tools' => array('wp_get_posts', 'wp_get_comments', 'wp_get_users', 'wc_get_orders', 'wc_get_reports_sales'),
    'suggested_schedule' => 'weekly_monday'
)
```

---

## 7. Sistema de Outputs (Acciones Post-Ejecución)

### 7.1 Acciones de Output Disponibles

```php
const OUTPUT_ACTIONS = array(
    'log' => array(
        'label' => 'Solo guardar en log',
        'icon' => 'dashicons-list-view',
        'description' => 'Guarda el resultado en el historial de la tarea',
        'requires_config' => false
    ),
    'email' => array(
        'label' => 'Enviar por email',
        'icon' => 'dashicons-email',
        'description' => 'Envía el resultado a una o más direcciones de email',
        'requires_config' => true,
        'config_fields' => array(
            'recipients' => array(
                'type' => 'email_list',
                'label' => 'Destinatarios',
                'default' => '{admin_email}'
            ),
            'subject_template' => array(
                'type' => 'text',
                'label' => 'Asunto',
                'default' => '[{site_name}] Tarea: {task_name} - {date}'
            ),
            'include_log' => array(
                'type' => 'checkbox',
                'label' => 'Incluir log de ejecución',
                'default' => false
            )
        )
    ),
    'webhook' => array(
        'label' => 'Enviar a Webhook',
        'icon' => 'dashicons-admin-site',
        'description' => 'POST del resultado a una URL externa (Slack, Telegram, Zapier...)',
        'requires_config' => true,
        'config_fields' => array(
            'url' => array(
                'type' => 'url',
                'label' => 'URL del Webhook',
                'required' => true
            ),
            'method' => array(
                'type' => 'select',
                'label' => 'Método HTTP',
                'options' => array('POST', 'PUT'),
                'default' => 'POST'
            ),
            'headers' => array(
                'type' => 'json',
                'label' => 'Headers adicionales (JSON)',
                'default' => '{}'
            ),
            'payload_template' => array(
                'type' => 'json',
                'label' => 'Template del payload (JSON)',
                'default' => '{"task": "{task_name}", "result": "{result}", "timestamp": "{timestamp}"}'
            )
        )
    ),
    'draft' => array(
        'label' => 'Crear borrador de post',
        'icon' => 'dashicons-edit',
        'description' => 'Guarda el resultado como un post en borrador',
        'requires_config' => true,
        'config_fields' => array(
            'post_type' => array(
                'type' => 'select',
                'label' => 'Tipo de contenido',
                'options' => 'get_post_types',
                'default' => 'post'
            ),
            'title_template' => array(
                'type' => 'text',
                'label' => 'Plantilla de título',
                'default' => '{task_name} - {date}'
            ),
            'category' => array(
                'type' => 'select',
                'label' => 'Categoría',
                'options' => 'get_categories',
                'default' => ''
            )
        )
    ),
    'custom' => array(
        'label' => 'Acción personalizada (hook)',
        'icon' => 'dashicons-admin-plugins',
        'description' => 'Ejecuta un hook de WordPress con el resultado',
        'requires_config' => true,
        'config_fields' => array(
            'hook_name' => array(
                'type' => 'text',
                'label' => 'Nombre del action hook',
                'default' => 'sflmcp_automation_custom_output'
            )
        )
    )
);
```

### 7.2 Templates de Webhook Preconfigurados

```php
const WEBHOOK_PRESETS = array(
    'slack' => array(
        'label' => 'Slack',
        'icon' => 'dashicons-format-chat',
        'payload_template' => '{
            "blocks": [
                {
                    "type": "header",
                    "text": {"type": "plain_text", "text": "🤖 {task_name}"}
                },
                {
                    "type": "section",
                    "text": {"type": "mrkdwn", "text": "{result}"}
                },
                {
                    "type": "context",
                    "elements": [{"type": "mrkdwn", "text": "Ejecutado: {timestamp}"}]
                }
            ]
        }'
    ),
    'telegram' => array(
        'label' => 'Telegram Bot',
        'icon' => 'dashicons-admin-comments',
        'url_template' => 'https://api.telegram.org/bot{bot_token}/sendMessage',
        'payload_template' => '{
            "chat_id": "{chat_id}",
            "text": "🤖 *{task_name}*\n\n{result}\n\n_Ejecutado: {timestamp}_",
            "parse_mode": "Markdown"
        }'
    ),
    'discord' => array(
        'label' => 'Discord Webhook',
        'icon' => 'dashicons-groups',
        'payload_template' => '{
            "embeds": [{
                "title": "🤖 {task_name}",
                "description": "{result}",
                "color": 5814783,
                "footer": {"text": "Ejecutado: {timestamp}"}
            }]
        }'
    ),
    'zapier' => array(
        'label' => 'Zapier Webhook',
        'icon' => 'dashicons-admin-links',
        'payload_template' => '{
            "task_name": "{task_name}",
            "task_id": "{task_id}",
            "result": "{result}",
            "status": "{status}",
            "execution_time_ms": "{execution_time}",
            "timestamp": "{timestamp}"
        }'
    )
);
```

---

## 8. Arquitectura del Motor de Ejecución

### 8.1 Clase Principal: `StifliFlexMcp_Automation_Engine`

```php
/**
 * Motor de ejecución de tareas automatizadas
 */
class StifliFlexMcp_Automation_Engine {
    
    const HOOK_WORKER = 'sflmcp_automation_worker';
    const HOOK_SINGLE = 'sflmcp_automation_run_task';
    
    /**
     * Registra los hooks de cron
     */
    public function register_hooks() {
        // Worker principal - ejecuta cada minuto
        add_action(self::HOOK_WORKER, array($this, 'process_pending_tasks'));
        
        // Hook para ejecución individual
        add_action(self::HOOK_SINGLE, array($this, 'run_single_task'), 10, 1);
        
        // Registrar schedule personalizado (cada minuto)
        add_filter('cron_schedules', function($schedules) {
            $schedules['sflmcp_every_minute'] = array(
                'interval' => 60,
                'display' => __('Every Minute', 'stifli-flex-mcp')
            );
            return $schedules;
        });
    }
    
    /**
     * Activa el sistema de automatización
     */
    public function activate() {
        if (!wp_next_scheduled(self::HOOK_WORKER)) {
            wp_schedule_event(time(), 'sflmcp_every_minute', self::HOOK_WORKER);
        }
    }
    
    /**
     * Procesa tareas pendientes
     */
    public function process_pending_tasks() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'sflmcp_automation_tasks';
        $now = current_time('mysql', true);
        
        // Obtener tareas activas cuyo next_run ya pasó
        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = 'active' 
             AND next_run <= %s 
             ORDER BY next_run ASC 
             LIMIT 5",
            $now
        ));
        
        foreach ($tasks as $task) {
            $this->execute_task($task);
        }
    }
    
    /**
     * Ejecuta una tarea individual
     */
    public function execute_task($task) {
        global $wpdb;
        
        $log_table = $wpdb->prefix . 'sflmcp_automation_logs';
        $task_table = $wpdb->prefix . 'sflmcp_automation_tasks';
        
        $start_time = microtime(true);
        
        // Crear entrada de log
        $log_id = $this->create_log_entry($task->id, $task->prompt);
        
        try {
            // Obtener configuración del provider
            $client_settings = get_option('sflmcp_client_settings', array());
            $advanced_settings = get_option('sflmcp_client_settings_advanced', array());
            
            // Determinar provider y model
            $provider = $task->provider ?: ($client_settings['provider'] ?? 'openai');
            $model = $task->model ?: ($client_settings['model'] ?? 'gpt-5.2-chat-latest');
            $api_key = $client_settings['api_key'] ?? '';
            
            if (empty($api_key)) {
                throw new Exception('API key not configured');
            }
            
            // Obtener tools permitidas
            $tools = $this->get_task_tools($task);
            
            // Preparar argumentos para el provider
            $args = array(
                'api_key' => $this->decrypt_api_key($api_key),
                'model' => $model,
                'message' => $task->prompt,
                'conversation' => array(),
                'tools' => $tools,
                'system_prompt' => $task->system_prompt ?: ($advanced_settings['system_prompt'] ?? ''),
                'temperature' => $advanced_settings['temperature'] ?? 0.7,
                'max_tokens' => $advanced_settings['max_tokens'] ?? 4096,
            );
            
            // Ejecutar con el provider apropiado
            $provider_class = $this->get_provider_class($provider);
            $provider_instance = new $provider_class();
            
            // Bucle agentic: ejecutar hasta que no haya tool calls
            $result = $this->run_agentic_loop($provider_instance, $args, $log_id);
            
            // Actualizar task con resultado exitoso
            $execution_time = (microtime(true) - $start_time) * 1000;
            $this->complete_log_entry($log_id, 'success', $result, $execution_time);
            $this->update_task_success($task->id);
            
            // Ejecutar acción de output
            $this->execute_output_action($task, $result);
            
        } catch (Exception $e) {
            $execution_time = (microtime(true) - $start_time) * 1000;
            $this->complete_log_entry($log_id, 'error', null, $execution_time, $e->getMessage());
            $this->update_task_error($task->id, $e->getMessage());
        }
    }
    
    /**
     * Ejecuta el bucle agentic (prompt → tool calls → results → prompt...)
     */
    private function run_agentic_loop($provider, $args, $log_id, $max_iterations = 10) {
        $iteration = 0;
        $tools_called = array();
        $tools_results = array();
        $final_response = '';
        
        while ($iteration < $max_iterations) {
            $response = $provider->send_message($args);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            // Verificar si hay tool calls
            $tool_calls = $this->extract_tool_calls($response);
            
            if (empty($tool_calls)) {
                // No más tool calls, extraer respuesta final
                $final_response = $this->extract_text_response($response);
                break;
            }
            
            // Ejecutar cada tool call
            foreach ($tool_calls as $tool_call) {
                $tool_name = $tool_call['name'];
                $tool_args = $tool_call['arguments'];
                
                $tools_called[] = array(
                    'name' => $tool_name,
                    'arguments' => $tool_args,
                    'iteration' => $iteration
                );
                
                // Ejecutar tool
                $result = $this->execute_tool($tool_name, $tool_args);
                $tools_results[$tool_name] = $result;
                
                // Preparar resultado para siguiente iteración
                $args['tool_result'] = $this->format_tool_result($tool_call, $result);
                $args['message'] = ''; // Ya no enviamos mensaje de usuario
            }
            
            // Actualizar conversación para siguiente iteración
            $args['conversation'] = $this->build_conversation_context($response, $tools_results);
            
            $iteration++;
        }
        
        // Actualizar log con tools usadas
        $this->update_log_tools($log_id, $tools_called, $tools_results);
        
        return array(
            'response' => $final_response,
            'tools_called' => $tools_called,
            'tools_results' => $tools_results,
            'iterations' => $iteration
        );
    }
}
```

---

## 9. Interfaz de Usuario (UI/UX)

### 9.1 Estructura de Pestañas

```
┌─────────────────────────────────────────────────────────────────┐
│  StifLi Flex MCP > Automation Tasks                             │
├─────────────────────────────────────────────────────────────────┤
│  [📝 Tasks List] [➕ Create Task] [📊 Execution Logs] [📁 Templates] │
└─────────────────────────────────────────────────────────────────┘
```

### 9.2 Pestaña "Tasks List" (Lista de Tareas)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  🤖 My Automation Tasks                                      [+ New Task]   │
├─────────────────────────────────────────────────────────────────────────────┤
│  🔍 Filter: [All ▼] [Active ▼] Search: [____________] [🔄 Refresh]          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─ Daily Sales Report ──────────────────────────────────────────────────┐  │
│  │ 📊  Daily at 8:00 AM                            [▶ Active] [⋮ Menu]   │  │
│  │     Last run: Today 08:00 ✅ Success                                  │  │
│  │     Next run: Tomorrow 08:00 (in 23h 45m)                             │  │
│  │     Tools: wc_get_orders, wc_get_products (2 tools)                   │  │
│  │     Output: Email to admin@site.com                                   │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│  ┌─ Comment Moderation ──────────────────────────────────────────────────┐  │
│  │ 🛡️  Every 6 hours                              [⏸ Paused] [⋮ Menu]   │  │
│  │     Last run: Yesterday 18:00 ✅ Success                              │  │
│  │     Next run: Paused                                                   │  │
│  │     Tools: wp_get_comments, wp_get_post (2 tools)                     │  │
│  │     Output: Log only                                                   │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│  ┌─ Low Stock Alert ─────────────────────────────────────────────────────┐  │
│  │ 🔔  Daily at 18:00                              [⚠ Error] [⋮ Menu]   │  │
│  │     Last run: Today 18:00 ❌ Error: API rate limit exceeded           │  │
│  │     Next run: Tomorrow 18:00 (retry 1/3)                              │  │
│  │     Tools: wc_get_products (1 tool)                                   │  │
│  │     Output: Email + Slack webhook                                      │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│  ───────────────────────────────────────────────────────────────────────    │
│  Showing 3 of 3 tasks | Total executions today: 8 | Success rate: 87.5%     │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 9.3 Modal/Página "Create Task"

#### Paso 1: Selección de Template o Blank

```
┌───────────────────────────────────────────────────────────────────────────┐
│  ➕ Create New Automation Task                                    [✕]     │
├───────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  How would you like to start?                                             │
│                                                                           │
│  ┌─────────────────────┐  ┌─────────────────────┐  ┌────────────────────┐ │
│  │                     │  │                     │  │                    │ │
│  │   📄                │  │   📁                │  │   🎯               │ │
│  │   Blank Task        │  │   From Template     │  │   Import/Duplicate │ │
│  │                     │  │                     │  │                    │ │
│  │   Start from        │  │   Choose from       │  │   Copy an existing │ │
│  │   scratch           │  │   pre-built recipes │  │   task             │ │
│  │                     │  │                     │  │                    │ │
│  └─────────────────────┘  └─────────────────────┘  └────────────────────┘ │
│                                                                           │
│  ───── Popular Templates ─────────────────────────────────────────────   │
│                                                                           │
│  📊 Daily Sales Report    📝 Blog Summary      🔔 Low Stock Alert         │
│  🛡️ Comment Moderation    📈 Weekly Insights   🎫 Expired Coupons         │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘
```

#### Paso 2: Configuración del Prompt (con Test)

```
┌───────────────────────────────────────────────────────────────────────────┐
│  ➕ Create New Task: Daily Sales Report                           [✕]     │
├───────────────────────────────────────────────────────────────────────────┤
│  Step 2 of 4: Configure Prompt                    [< Back] [Next >]       │
├───────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  Task Name: [Daily Sales Report_________________________]                 │
│                                                                           │
│  ┌─ Prompt ───────────────────────────────────────────────────────────┐  │
│  │                                                                     │  │
│  │  Genera un reporte completo de ventas del día anterior:            │  │
│  │                                                                     │  │
│  │  1. **Resumen de ventas**: Total de pedidos, ingresos totales     │  │
│  │  2. **Top 5 productos**: Los productos más vendidos               │  │
│  │  3. **Stock crítico**: Productos con stock < 10                   │  │
│  │  ...                                                               │  │
│  │                                                                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
│  📎 Variables disponibles: {site_name}, {date}, {admin_email}            │
│                                                                           │
│  ┌─ System Prompt (opcional) ─────────────────────────────────────────┐  │
│  │ You are a WooCommerce analytics assistant. Be concise and use      │  │
│  │ markdown formatting for reports.                                   │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
│  ──────────────────────────────────────────────────────────────────────  │
│                                                                           │
│  [🧪 Test Prompt Now]                                                     │
│                                                                           │
│  ┌─ Test Results ─────────────────────────────────────────────────────┐  │
│  │  ✅ Prompt executed successfully in 4.2s                           │  │
│  │                                                                     │  │
│  │  📦 Tools used (3):                                                │  │
│  │     • wc_get_orders (2 calls)                                      │  │
│  │     • wc_get_products (1 call)                                     │  │
│  │     • wc_get_reports_sales (1 call)                                │  │
│  │                                                                     │  │
│  │  💡 Suggestion: Save only these 3 tools to reduce token usage      │  │
│  │     by ~2,800 tokens per execution                                 │  │
│  │                                                                     │  │
│  │  [✅ Save detected tools] [View full response]                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘
```

#### Paso 3: Programación

```
┌───────────────────────────────────────────────────────────────────────────┐
│  ➕ Create New Task: Daily Sales Report                           [✕]     │
├───────────────────────────────────────────────────────────────────────────┤
│  Step 3 of 4: Schedule                            [< Back] [Next >]       │
├───────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  When should this task run?                                               │
│                                                                           │
│  ┌─ Quick Presets ────────────────────────────────────────────────────┐  │
│  │                                                                     │  │
│  │  ⏰ HOURLY                                                         │  │
│  │  ○ Every hour      ○ Every 2h      ○ Every 6h      ○ Every 12h    │  │
│  │                                                                     │  │
│  │  📅 DAILY                           [SELECTED]                     │  │
│  │  ○ Morning (8:00)  ● Custom time   ○ Evening (18:00)              │  │
│  │                                                                     │  │
│  │  📆 WEEKLY                                                         │  │
│  │  ○ Monday          ○ Friday         ○ Sunday        ○ Weekdays    │  │
│  │                                                                     │  │
│  │  🗓️ MONTHLY                                                        │  │
│  │  ○ 1st day         ○ 15th day       ○ Last day                    │  │
│  │                                                                     │  │
│  │  ⚙️ ADVANCED                                                       │  │
│  │  ○ Custom cron expression                                          │  │
│  │                                                                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
│  ┌─ Time Settings ────────────────────────────────────────────────────┐  │
│  │                                                                     │  │
│  │  Time: [08] : [00]   Timezone: [Europe/Madrid (UTC+1) ▼]          │  │
│  │                                                                     │  │
│  │  📌 Next 5 executions:                                             │  │
│  │     • Tomorrow Feb 25, 2026 at 08:00                               │  │
│  │     • Wed Feb 26, 2026 at 08:00                                    │  │
│  │     • Thu Feb 27, 2026 at 08:00                                    │  │
│  │     • Fri Feb 28, 2026 at 08:00                                    │  │
│  │     • Sat Mar 01, 2026 at 08:00                                    │  │
│  │                                                                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘
```

#### Paso 4: Output y Finalización

```
┌───────────────────────────────────────────────────────────────────────────┐
│  ➕ Create New Task: Daily Sales Report                           [✕]     │
├───────────────────────────────────────────────────────────────────────────┤
│  Step 4 of 4: Output & Actions                    [< Back] [Create Task]  │
├───────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  What should happen with the AI's response?                               │
│                                                                           │
│  ┌─ Output Actions ───────────────────────────────────────────────────┐  │
│  │                                                                     │  │
│  │  ☑ Save to execution log (always enabled)                         │  │
│  │                                                                     │  │
│  │  ☑ Send email notification                                         │  │
│  │    Recipients: [admin@mysite.com, manager@mysite.com_____]        │  │
│  │    Subject: [StifLi Report: {task_name} - {date}__________]       │  │
│  │    □ Include execution log                                         │  │
│  │                                                                     │  │
│  │  □ Send to Webhook                                                 │  │
│  │    [Slack ▼] URL: [https://hooks.slack.com/services/...]          │  │
│  │                                                                     │  │
│  │  □ Create draft post                                               │  │
│  │    Post type: [Post ▼]  Category: [Reports ▼]                     │  │
│  │                                                                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
│  ┌─ Error Handling ───────────────────────────────────────────────────┐  │
│  │                                                                     │  │
│  │  If task fails:                                                    │  │
│  │  ○ Retry up to [3▼] times with [5▼] min delay                     │  │
│  │  ☑ Send error notification to admin                                │  │
│  │  ○ Pause task after [5▼] consecutive failures                     │  │
│  │                                                                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
│  ───────────────────────────────────────────────────────────────────────  │
│                                                                           │
│  ┌─ Summary ──────────────────────────────────────────────────────────┐  │
│  │  📋 Daily Sales Report                                             │  │
│  │  ⏰ Daily at 08:00 (Europe/Madrid)                                 │  │
│  │  🤖 OpenAI GPT-5.2 | 3 tools                                       │  │
│  │  📤 Email + Log                                                    │  │
│  │  💰 Est. cost: ~$0.015/execution (~$0.45/month)                    │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
│                              [Save as Draft] [Create & Activate Task]     │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘
```

### 9.4 Pestaña "Execution Logs"

```
┌───────────────────────────────────────────────────────────────────────────┐
│  📊 Execution Logs                                                        │
├───────────────────────────────────────────────────────────────────────────┤
│  Task: [All Tasks ▼]  Status: [All ▼]  Date: [Last 7 days ▼]  [🔄]       │
├───────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  │ Time              │ Task                │ Status  │ Duration │ Tokens │ │
│  ├───────────────────┼─────────────────────┼─────────┼──────────┼────────┤ │
│  │ Today 08:00       │ Daily Sales Report  │ ✅      │ 4.2s     │ 2,847  │ │
│  │ Today 06:00       │ Comment Moderation  │ ✅      │ 2.1s     │ 1,203  │ │
│  │ Yesterday 18:00   │ Low Stock Alert     │ ❌      │ 0.8s     │ 512    │ │
│  │ Yesterday 18:00   │ Daily Sales Report  │ ✅      │ 3.9s     │ 2,654  │ │
│  │ Yesterday 12:00   │ Comment Moderation  │ ✅      │ 1.8s     │ 1,102  │ │
│                                                                           │
│  ───────────────────────────────────────────────────────────────────────  │
│                                                                           │
│  ┌─ Log Detail: Daily Sales Report - Today 08:00 ─────────────────────┐  │
│  │                                                                     │  │
│  │  Status: ✅ Success                                                │  │
│  │  Duration: 4.2 seconds (3 iterations)                              │  │
│  │  Tokens: 1,234 input + 1,613 output = 2,847 total (~$0.014)       │  │
│  │                                                                     │  │
│  │  ─── Tools Executed ───                                            │  │
│  │  1. wc_get_orders → 45 orders found                                │  │
│  │  2. wc_get_products → 12 low stock items                           │  │
│  │  3. wc_get_reports_sales → report generated                        │  │
│  │                                                                     │  │
│  │  ─── AI Response ───                                               │  │
│  │  ## Daily Sales Report - Feb 24, 2026                              │  │
│  │                                                                     │  │
│  │  **Summary**                                                       │  │
│  │  - Total Orders: 45                                                │  │
│  │  - Revenue: $3,456.78                                              │  │
│  │  - Average Order: $76.82                                           │  │
│  │  ...                                                               │  │
│  │                                                                     │  │
│  │  ─── Output Actions ───                                            │  │
│  │  ✅ Email sent to admin@site.com                                   │  │
│  │                                                                     │  │
│  │                          [📋 Copy Response] [🔄 Re-run Now]        │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘
```

---

## 10. Integración con el Sistema Existente

### 10.1 Nuevo Archivo: `class-automation-admin.php`

```
client/
├── class-client-admin.php          (existente - Chat)
├── class-automation-admin.php      (NUEVO - Automation Tasks)
└── ...
```

### 10.2 Modificaciones Requeridas

| Archivo | Cambio | Descripción |
|---------|--------|-------------|
| `stifli-flex-mcp.php` | Agregar | Creación de tablas, registro de cron, carga de clase |
| `mod.php` | Agregar | Nuevo submenu "Automation Tasks", AJAX handlers |
| `class-client-admin.php` | Refactor menor | Extraer lógica de providers a método compartido |
| Nuevo CSS/JS | Crear | `admin-automation.css`, `admin-automation.js` |

### 10.3 Hooks y Filtros Propuestos

```php
// Filtros para extensibilidad
apply_filters('sflmcp_automation_schedule_presets', $presets);
apply_filters('sflmcp_automation_output_actions', $actions);
apply_filters('sflmcp_automation_before_execute', $task, $args);
apply_filters('sflmcp_automation_after_execute', $task, $result);
apply_filters('sflmcp_automation_templates', $templates);

// Actions para integraciones
do_action('sflmcp_automation_task_created', $task_id);
do_action('sflmcp_automation_task_started', $task_id, $log_id);
do_action('sflmcp_automation_task_completed', $task_id, $log_id, $result);
do_action('sflmcp_automation_task_failed', $task_id, $log_id, $error);
do_action('sflmcp_automation_custom_output', $task, $result, $config);
```

---

## 11. Estimación de Esfuerzo

### 11.1 Fases de Desarrollo

| Fase | Componente | Estimación | Prioridad |
|------|------------|------------|-----------|
| 1 | Base de datos (3 tablas) | 2-3 horas | Alta |
| 2 | Motor de ejecución (Engine) | 8-10 horas | Alta |
| 3 | UI Lista de Tareas | 4-6 horas | Alta |
| 4 | UI Crear/Editar Tarea | 6-8 horas | Alta |
| 5 | Sistema de Test de Prompt | 3-4 horas | Alta |
| 6 | Optimizador de Tools | 4-5 horas | Media |
| 7 | Outputs (email, webhook) | 4-5 horas | Media |
| 8 | Templates predefinidos | 3-4 horas | Media |
| 9 | UI Logs de ejecución | 3-4 horas | Media |
| 10 | Documentación y tests | 4-5 horas | Media |

**Total estimado: 40-55 horas de desarrollo**

### 11.2 MVP Mínimo (Primera Versión)

Para una primera versión funcional, priorizar:
1. ✅ Tabla de tareas + motor de ejecución
2. ✅ UI básica de crear/listar tareas
3. ✅ Presets de schedule (sin cron custom)
4. ✅ Output solo a log
5. ⏳ Email como output adicional

**MVP estimado: 20-25 horas**

---

## 12. Consideraciones de Seguridad

1. **API Keys**: Reutilizar el sistema de encriptación existente (`encrypt_value`/`decrypt_value`)
2. **Capacidades**: Solo usuarios con `manage_options` pueden crear/editar tareas
3. **Tools sensibles**: Respetar el sistema de `getToolCapability()` existente
4. **Rate Limiting**: Implementar límite de ejecuciones por hora para evitar costos excesivos
5. **Validación de Cron**: Validar expresiones cron para evitar ejecuciones excesivas
6. **Timeout**: Límite de 120s por ejecución de tarea
7. **Logs de auditoría**: Registrar quién crea/modifica tareas

---

## 13. Conclusiones y Recomendaciones

### Valor Diferencial
Esta funcionalidad posiciona a StifLi Flex MCP como una herramienta única en el ecosistema WordPress:
- **Automatización sin código**: Cualquier usuario puede crear automatizaciones complejas
- **Integración profunda**: Acceso a 117+ tools de WordPress/WooCommerce
- **Ahorro real**: Tareas que tomarían horas se ejecutan automáticamente
- **Flexibilidad**: Desde reportes simples hasta workflows complejos

### Próximos Pasos Sugeridos
1. Validar diseño con feedback de usuarios
2. Implementar MVP (fases 1-4)
3. Beta testing con early adopters
4. Iterar basándose en feedback
5. Añadir templates y outputs avanzados

### Posibles Extensiones Futuras
- **Cadenas de tareas**: Ejecutar tarea B cuando tarea A complete
- **Condiciones**: Ejecutar solo si se cumple condición (ej: ventas > $1000)
- **Variables dinámicas**: Pasar datos entre ejecuciones
- **API externa**: Permitir activar tareas vía API REST
- **Marketplace de templates**: Compartir/importar templates de la comunidad

---

*Documento preparado para StifLi Flex MCP v2.1+*
