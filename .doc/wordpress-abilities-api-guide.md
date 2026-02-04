# WordPress Abilities API - Guía para Desarrolladores

## ¿Qué es la Abilities API?

La Abilities API de WordPress 6.9+ es un registro centralizado de capacidades (abilities) que expone funcionalidades de plugins/temas en un formato estandarizado, legible tanto por humanos como por máquinas (agentes de IA).

**Objetivo**: Permitir que código PHP, agentes de IA, o cualquier cliente pueda:
1. **Descubrir** qué abilities están disponibles
2. **Inspeccionar** sus schemas de entrada/salida
3. **Ejecutar** abilities de forma programática

---

## Arquitectura

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Core                            │
│  ┌─────────────────────────────────────────────────────┐    │
│  │              Abilities Registry                      │    │
│  │  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐   │    │
│  │  │ core/       │ │ allsi/      │ │ myplugin/   │   │    │
│  │  │ get-site-   │ │ search-     │ │ my-ability  │   │    │
│  │  │ info        │ │ image       │ │             │   │    │
│  │  └─────────────┘ └─────────────┘ └─────────────┘   │    │
│  └─────────────────────────────────────────────────────┘    │
│                           │                                  │
│              ┌────────────┴────────────┐                    │
│              ▼                         ▼                    │
│     REST API Endpoint          PHP Functions                │
│   /wp-abilities/v1/          wp_get_abilities()            │
│                              wp_get_ability()               │
└─────────────────────────────────────────────────────────────┘
```

---

## Funciones PHP Principales (WordPress 6.9+)

### 1. Descubrir Abilities Disponibles

```php
<?php
/**
 * Obtener todas las abilities registradas
 * Usa wp_get_abilities() - devuelve array de objetos WP_Ability
 */
function mi_plugin_listar_abilities() {
    // Verificar disponibilidad (WordPress 6.9+)
    if ( ! function_exists( 'wp_get_abilities' ) ) {
        return array();
    }
    
    // Obtener todas las abilities registradas
    $abilities = wp_get_abilities();
    
    $lista = array();
    foreach ( $abilities as $ability ) {
        $lista[] = array(
            'name'        => $ability->get_name(),
            'label'       => $ability->get_label(),
            'description' => $ability->get_description(),
            'category'    => $ability->get_category(),
        );
    }
    
    return $lista;
}

// Uso:
$abilities = mi_plugin_listar_abilities();
foreach ( $abilities as $ability ) {
    echo "- {$ability['name']}: {$ability['label']}\n";
}
```

### 2. Obtener Información de una Ability Específica

```php
<?php
/**
 * Obtener detalles de una ability específica
 * Usa wp_get_ability( $name ) - devuelve WP_Ability o null
 */
function mi_plugin_obtener_ability( $ability_name ) {
    if ( ! function_exists( 'wp_get_ability' ) ) {
        return null;
    }
    
    // Obtener ability directamente por nombre
    $ability = wp_get_ability( $ability_name );
    
    if ( ! $ability ) {
        return null;
    }
    
    return array(
        'name'          => $ability->get_name(),
        'label'         => $ability->get_label(),
        'description'   => $ability->get_description(),
        'category'      => $ability->get_category(),
        'input_schema'  => $ability->get_input_schema(),
        'output_schema' => $ability->get_output_schema(),
    );
}

// Uso:
$info = mi_plugin_obtener_ability( 'allsi/search-image' );
if ( $info ) {
    echo "Ability: {$info['label']}\n";
    echo "Descripción: {$info['description']}\n";
    echo "Input Schema: " . json_encode( $info['input_schema'], JSON_PRETTY_PRINT ) . "\n";
}
```

### 3. Ejecutar una Ability

```php
<?php
/**
 * Ejecutar una ability con parámetros
 * Usa $ability->execute( $args ) - método correcto en WordPress 6.9
 */
function mi_plugin_ejecutar_ability( $ability_name, $input = array() ) {
    if ( ! function_exists( 'wp_get_ability' ) ) {
        return new WP_Error( 'no_api', 'Abilities API not available. Requires WordPress 6.9+' );
    }
    
    // Obtener la ability
    $ability = wp_get_ability( $ability_name );
    
    if ( ! $ability ) {
        return new WP_Error( 'not_found', "Ability '{$ability_name}' not found" );
    }
    
    // Ejecutar la ability - el método es execute(), no run()
    // Los permisos se verifican internamente por la ability
    $result = $ability->execute( $input );
    
    return $result;
}

// Uso - Buscar imágenes:
$resultado = mi_plugin_ejecutar_ability( 'allsi/search-image', array(
    'search_term' => 'sunset beach',
    'source'      => 'pexels',
    'count'       => 3,
) );

if ( is_wp_error( $resultado ) ) {
    echo "Error: " . $resultado->get_error_message();
} else {
    // El resultado depende de la ability específica
    print_r( $resultado );
}
```

---

## Ejemplos Prácticos

### Ejemplo 1: Buscar y Establecer Imagen Destacada

```php
<?php
/**
 * Buscar una imagen y establecerla como featured image de un post
 */
function mi_plugin_auto_imagen_destacada( $post_id, $search_term, $source = 'pixabay' ) {
    // Paso 1: Buscar imagen
    $search_result = mi_plugin_ejecutar_ability( 'allsi/search-image', array(
        'search_term' => $search_term,
        'source'      => $source,
        'count'       => 1,
    ) );
    
    if ( is_wp_error( $search_result ) || empty( $search_result['images'] ) ) {
        return new WP_Error( 'no_images', 'No se encontraron imágenes' );
    }
    
    $image_url = $search_result['images'][0]['url'];
    $alt_text  = $search_result['images'][0]['alt'];
    $caption   = $search_result['images'][0]['caption'];
    
    // Paso 2: Establecer como featured image
    $set_result = mi_plugin_ejecutar_ability( 'allsi/set-featured-image', array(
        'post_id'   => $post_id,
        'image_url' => $image_url,
        'alt_text'  => $alt_text,
        'caption'   => $caption,
    ) );
    
    return $set_result;
}

// Uso:
$resultado = mi_plugin_auto_imagen_destacada( 123, 'mountain landscape', 'pexels' );
if ( ! is_wp_error( $resultado ) && $resultado['success'] ) {
    echo "Imagen establecida! Attachment ID: {$resultado['attachment_id']}";
}
```

### Ejemplo 2: Generar Imagen con IA e Insertarla en Contenido

```php
<?php
/**
 * Generar imagen con IA e insertarla en el contenido del post
 */
function mi_plugin_generar_e_insertar( $post_id, $prompt, $ai_source = 'dallev1' ) {
    // Paso 1: Generar imagen con IA
    $generate_result = mi_plugin_ejecutar_ability( 'allsi/generate-ai-image', array(
        'prompt' => $prompt,
        'source' => $ai_source,
        'size'   => '1024x1024',
    ) );
    
    if ( is_wp_error( $generate_result ) || ! $generate_result['success'] ) {
        return new WP_Error( 'generation_failed', 'No se pudo generar la imagen' );
    }
    
    $image_url = $generate_result['url'];
    
    // Paso 2: Insertar en contenido después del primer párrafo
    $insert_result = mi_plugin_ejecutar_ability( 'allsi/insert-image-in-content', array(
        'post_id'   => $post_id,
        'image_url' => $image_url,
        'position'  => 1,
        'placement' => 'after',
        'element'   => 'p',
        'alt_text'  => $prompt,
    ) );
    
    return $insert_result;
}

// Uso:
$resultado = mi_plugin_generar_e_insertar( 
    456, 
    'A futuristic city with flying cars at sunset',
    'dallev1'
);
```

### Ejemplo 3: Descubrir Abilities por Categoría

```php
<?php
/**
 * Obtener abilities filtradas por categoría
 */
function mi_plugin_abilities_por_categoria( $categoria ) {
    if ( ! function_exists( 'wp_get_abilities' ) ) {
        return array();
    }
    
    $todas = wp_get_abilities();
    $filtradas = array();
    
    foreach ( $todas as $ability ) {
        if ( $ability->get_category() === $categoria ) {
            $filtradas[ $ability->get_name() ] = array(
                'label'       => $ability->get_label(),
                'description' => $ability->get_description(),
            );
        }
    }
    
    return $filtradas;
}

// Obtener todas las abilities de la categoría "media"
$media_abilities = mi_plugin_abilities_por_categoria( 'media' );
foreach ( $media_abilities as $name => $info ) {
    echo "{$name}: {$info['label']}\n";
}

// Resultado esperado:
// allsi/search-image: Search Images
// allsi/set-featured-image: Set Featured Image
// allsi/auto-generate-for-post: Auto Generate Image for Post
// allsi/insert-image-in-content: Insert Image in Post Content
// allsi/generate-ai-image: Generate AI Image
```

---

## Acceso via REST API

Las abilities también están disponibles via REST API:

### Listar Abilities

```bash
GET /wp-json/wp-abilities/v1/abilities

# O con permalinks desactivados:
GET /?rest_route=/wp-abilities/v1/abilities
```

### Obtener Información de una Ability

```bash
GET /wp-json/wp-abilities/v1/abilities/allsi/search-image
```

### Ejecutar una Ability

```bash
POST /wp-json/wp-abilities/v1/abilities/allsi/search-image/run
Content-Type: application/json
Authorization: Basic <base64_credentials>

{
  "input": {
    "search_term": "sunset beach",
    "source": "pexels",
    "count": 3
  }
}
```

---

## Verificar si Abilities API está Disponible

```php
<?php
/**
 * Verificar si la Abilities API está disponible (WordPress 6.9+)
 */
function mi_plugin_abilities_disponible() {
    return function_exists( 'wp_get_abilities' );
}

// Uso seguro:
if ( mi_plugin_abilities_disponible() ) {
    // Usar Abilities API
    $abilities = wp_get_abilities(); // Listar todas
    $ability = wp_get_ability( 'allsi/search-image' ); // Obtener una
    $result = $ability->execute( array( 'search_term' => 'sunset' ) ); // Ejecutar
} else {
    // Fallback para versiones anteriores de WordPress
    // o llamar directamente a las funciones del plugin
}
```

---

## Abilities de All Sources Images Disponibles

| Ability | Descripción | Parámetros Principales |
|---------|-------------|------------------------|
| `allsi/search-image` | Buscar imágenes en stock o AI | `search_term`, `source`, `count` |
| `allsi/set-featured-image` | Establecer imagen destacada | `post_id`, `image_url` |
| `allsi/auto-generate-for-post` | Auto-generar imagen para post | `post_id`, `source`, `overwrite` |
| `allsi/insert-image-in-content` | Insertar imagen en contenido | `post_id`, `image_url`, `position` |
| `allsi/generate-ai-image` | Generar imagen con IA | `prompt`, `source`, `size` |

### Sources Disponibles

**Stock Photos:**
- `pixabay` - Gratis, gran biblioteca (default)
- `pexels` - Alta calidad, gratis
- `unsplash` - Fotos artísticas
- `flickr` - Contenido diverso
- `openverse` - Creative Commons
- `giphy` - GIFs animados

**AI Generators:**
- `dallev1` - OpenAI DALL-E 3
- `stability` - Stable Diffusion
- `gemini` - Google Gemini
- `replicate` - Varios modelos de IA
- `workers_ai` - Cloudflare AI

---

## Notas Importantes

1. **Permisos**: Cada ability verifica permisos antes de ejecutar. El usuario debe tener `edit_posts` o el permiso específico de la ability.

2. **MCP Integration**: Para exponer abilities a agentes de IA externos (Claude, GPT, etc.), se requiere el plugin MCP Adapter y añadir `'mcp' => array('public' => true)` en el meta de la ability.

3. **Validación**: Las abilities validan los inputs según su `input_schema`. Parámetros inválidos retornan `WP_Error`.

4. **URLs Temporales**: Las imágenes generadas con IA tienen URLs temporales (~1 hora). Usa `allsi/set-featured-image` para guardarlas permanentemente.

5. **API Keys**: Los sources de IA requieren API keys configuradas en los ajustes del plugin.
