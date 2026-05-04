# Catálogo de tools de StifLi Flex MCP

Documento para web, documentación y tutoriales. Lista las tools nativas y modulares detectadas en el código, explica su definición y para qué sirven.

## Cómo leer este catálogo

Tipos de tool:

- **READ**: consulta información y normalmente no modifica el sitio.
- **SENSITIVE READ**: consulta información sensible, datos privados, estado técnico o red externa. Requiere más cuidado y suele pedir confirmación.
- **WRITE**: crea, actualiza, borra, ejecuta acciones, genera media o cambia configuración. Requiere confirmación y capability adecuada.
- **DYNAMIC**: se crea desde Custom Tools o WordPress Abilities. No tiene nombre fijo hasta que el usuario la importe o la cree.

Notas de alcance:

- El mensaje público del plugin habla de **117+ tools base**: WordPress, WooCommerce y Core. El código actual incluye más módulos: SEO, formularios, snippets, changelog, multimedia, integraciones y abilities dinámicas.
- WooCommerce debe estar activo para ejecutar tools `wc_*`. Se pueden configurar aunque WooCommerce no esté activo.
- Las tools de plugins, como ACF, Yoast, WPForms, Gravity Forms o Forminator, requieren que el plugin correspondiente esté instalado y activo.
- Las tools `ability_*` y `custom_*` son dinámicas y dependen de lo que el administrador importe o cree.

## Capacidades y seguridad

La ejecución de tools respeta capabilities de WordPress:

- Posts/páginas: `edit_posts`, `delete_posts`, etc.
- Media: `upload_files`.
- Opciones/settings: `manage_options`.
- WooCommerce: `edit_products`, `edit_shop_orders`, `manage_woocommerce`, etc.
- Snippets: dependen del proveedor de snippets instalado.
- Rollback/changelog: normalmente `manage_options`.

Recomendación para la web:

- Presentar StifLi Flex MCP como un sistema de **mínimo privilegio**: perfiles, toggles, OAuth, confirmaciones y rollback reducen el riesgo operativo.

## Core

### `mcp_ping` — READ

Definición: comprueba conectividad con el servidor MCP. Devuelve hora GMT, información básica del sitio y diagnósticos ligeros opcionales.

Para qué vale:

- Verificar que el cliente MCP está conectado.
- Probar autenticación y routing JSON-RPC.
- Diagnosticar problemas básicos antes de usar tools reales.

## WordPress: posts

### `wp_get_posts` — READ

Definición: lista posts con filtros y enriquecimientos opcionales como autor, imagen destacada, taxonomías y paginación.

Para qué vale:

- Consultar entradas recientes.
- Buscar contenido por estado, autor, categoría, etiqueta o fecha.
- Dar contexto a un agente antes de editar o resumir.

### `wp_get_post` — READ

Definición: obtiene un post por ID, con enriquecimientos opcionales de autor, imagen destacada y taxonomías.

Para qué vale:

- Revisar una entrada concreta.
- Dar contexto completo a la IA antes de optimizar contenido.

### `wp_create_post` — WRITE

Definición: crea un post. Acepta título, contenido, estado, tipo, extracto, autor, imagen destacada, metadatos, categorías y taxonomías.

Para qué vale:

- Crear borradores o entradas publicadas desde IA.
- Generar contenido editorial o programático.

### `wp_update_post` — WRITE

Definición: actualiza un post por ID usando campos compatibles con `wp_update_post()`. Puede cambiar imagen destacada.

Para qué vale:

- Editar título, contenido, estado, extracto, slug, autor o taxonomías.
- Aplicar optimizaciones masivas con supervisión.

### `wp_delete_post` — WRITE

Definición: elimina un post por ID.

Para qué vale:

- Mover contenido a papelera o eliminarlo según argumentos.
- Limpiar contenido generado o duplicado.

### `wp_set_featured_image` — WRITE

Definición: asigna o elimina la imagen destacada de un post. Si `attachment_id` es `0`, la quita.

Para qué vale:

- Automatizar imagen destacada después de subir o generar media.
- Corregir posts sin thumbnail.

## WordPress: páginas

### `wp_get_pages` — READ

Definición: lista páginas con filtros de estado, búsqueda, límite, offset, orden y campo de orden.

Para qué vale:

- Auditar páginas existentes.
- Encontrar una página antes de actualizarla.

### `wp_create_page` — WRITE

Definición: crea una página con título, contenido, estado, autor, padre, orden de menú y metadatos.

Para qué vale:

- Crear páginas de documentación, landing pages o borradores.
- Generar estructura de sitio desde IA.

### `wp_update_page` — WRITE

Definición: actualiza una página por ID.

Para qué vale:

- Editar contenido estático.
- Reorganizar jerarquías de páginas o cambiar `menu_order`.

### `wp_delete_page` — WRITE

Definición: elimina una página por ID. Puede saltar papelera con `force=true`.

Para qué vale:

- Limpiar páginas temporales o antiguas.

## WordPress: comentarios

### `wp_get_comments` — READ

Definición: lista comentarios con filtros por post, estado, búsqueda, fechas, límite, offset y paginación.

Para qué vale:

- Moderar comentarios.
- Resumir feedback de lectores o clientes.

### `wp_create_comment` — WRITE

Definición: crea un comentario. Requiere `post_id` y `comment_content`.

Para qué vale:

- Responder comentarios desde un agente autorizado.
- Crear notas o respuestas programáticas.

### `wp_update_comment` — WRITE

Definición: actualiza un comentario por `comment_ID` con un objeto de campos.

Para qué vale:

- Aprobar, editar o cambiar estado de comentarios.

### `wp_delete_comment` — WRITE

Definición: elimina un comentario por `comment_ID`, con flag opcional `force`.

Para qué vale:

- Moderación asistida.
- Eliminar spam o comentarios problemáticos.

## WordPress: usuarios y user meta

### `wp_get_users` — READ

Definición: lista usuarios con campos básicos: ID, login, display name y roles. Puede incluir fecha de registro, avatar, conteo de posts y paginación.

Para qué vale:

- Ver usuarios y roles.
- Auditar autores o cuentas del sitio.

### `wp_get_user_meta` — SENSITIVE READ

Definición: obtiene metadatos de usuario por `user_id` y `meta_key` opcional.

Para qué vale:

- Diagnosticar datos de perfil.
- Consultar integraciones que guardan preferencias o permisos en user meta.

### `wp_update_user_meta` — WRITE

Definición: actualiza un metadato de usuario por `user_id`, `meta_key` y `meta_value`.

Para qué vale:

- Ajustar preferencias o campos personalizados de usuario.

### `wp_delete_user_meta` — WRITE

Definición: elimina metadatos de usuario por `user_id` y `meta_key`.

Para qué vale:

- Limpiar datos obsoletos o incorrectos.

Nota de compliance:

- Las tools para crear, actualizar o borrar usuarios fueron retiradas por cumplimiento de WordPress.org.

## WordPress: plugins y themes

### `wp_list_plugins` — READ

Definición: lista plugins instalados con nombre y versión.

Para qué vale:

- Diagnóstico de entorno.
- Ver dependencias antes de usar tools de integraciones.

### `wp_get_themes` — READ

Definición: lista themes instalados.

Para qué vale:

- Diagnosticar el estado visual/técnico del sitio.
- Preparar soporte o auditorías.

Nota de compliance:

- Las tools para instalar, activar o desactivar plugins y themes fueron retiradas.

## WordPress: media

### `wp_get_media` — READ

Definición: lista adjuntos de la biblioteca de medios con límite y offset.

Para qué vale:

- Encontrar imágenes o archivos existentes.
- Preparar selección de assets para posts.

### `wp_get_media_item` — READ

Definición: obtiene detalle de un media item por ID.

Para qué vale:

- Ver URL, metadatos y datos de un adjunto.

### `wp_upload_image_from_url` — WRITE

Definición: descarga una imagen desde una URL pública y crea un adjunto de media.

Para qué vale:

- Importar imágenes externas.
- Preparar imágenes destacadas desde una fuente remota.

### `wp_upload_image` — WRITE

Definición: sube una imagen desde base64 o data URL y crea un adjunto.

Para qué vale:

- Guardar imágenes generadas por IA.
- Integrar flujos donde la imagen llega como base64.

### `wp_update_media_item` — WRITE

Definición: actualiza metadatos del adjunto, como título, contenido y extracto.

Para qué vale:

- Mejorar títulos, captions, descripciones y alt text.

### `wp_delete_media_item` — WRITE

Definición: elimina un media item por ID. Puede borrar permanentemente con `force=true`.

Para qué vale:

- Limpiar archivos no usados.
- Revertir generaciones o subidas incorrectas.

## WordPress: taxonomías, categorías y etiquetas

### `wp_get_taxonomies` — READ

Definición: lista taxonomías registradas.

Para qué vale:

- Descubrir taxonomías custom.
- Preparar operaciones de términos.

### `wp_get_terms` — READ

Definición: lista términos de una taxonomía.

Para qué vale:

- Consultar categorías, tags o taxonomías custom.

### `wp_create_term` — WRITE

Definición: crea un término en cualquier taxonomía registrada.

Para qué vale:

- Crear términos de categorías, etiquetas o taxonomías personalizadas.

### `wp_update_term` — WRITE

Definición: actualiza un término en cualquier taxonomía.

Para qué vale:

- Renombrar, cambiar slug, descripción o jerarquía.

### `wp_delete_term` — WRITE

Definición: borra un término por `term_id` y taxonomía.

Para qué vale:

- Limpiar términos duplicados u obsoletos.

### `wp_get_term_meta` — SENSITIVE READ

Definición: obtiene metadatos de término y redacta valores que parecen secretos.

Para qué vale:

- Diagnosticar taxonomías enriquecidas por plugins.

### `wp_update_term_meta` — WRITE

Definición: actualiza metadatos de término.

Para qué vale:

- Ajustar campos extra de categorías/taxonomías.

### `wp_delete_term_meta` — WRITE

Definición: elimina metadatos de término.

Para qué vale:

- Limpiar datos asociados a términos.

### `wp_get_categories` — READ

Definición: lista categorías con filtros de vacío, búsqueda y límite.

Para qué vale:

- Consultar estructura editorial.

### `wp_create_category` — WRITE

Definición: crea una categoría.

Para qué vale:

- Añadir nuevas secciones editoriales.

### `wp_update_category` — WRITE

Definición: actualiza categoría por `term_id`.

Para qué vale:

- Renombrar o reorganizar categorías.

### `wp_delete_category` — WRITE

Definición: elimina una categoría.

Para qué vale:

- Limpiar categorías antiguas.

### `wp_get_tags` — READ

Definición: lista etiquetas con filtros.

Para qué vale:

- Revisar sistema de tags.

### `wp_create_tag` — WRITE

Definición: crea una etiqueta.

Para qué vale:

- Añadir tags para contenido nuevo.

### `wp_update_tag` — WRITE

Definición: actualiza etiqueta por `term_id`.

Para qué vale:

- Corregir nombres, slugs o descripciones.

### `wp_delete_tag` — WRITE

Definición: elimina una etiqueta.

Para qué vale:

- Limpiar etiquetas duplicadas u obsoletas.

## WordPress: menús

### `wp_get_nav_menus` — READ

Definición: lista menús de navegación.

Para qué vale:

- Ver menús disponibles antes de editarlos.

### `wp_get_menus` — READ

Definición: alias de `wp_get_nav_menus`.

Para qué vale:

- Compatibilidad con prompts o clientes que pidan menús.

### `wp_get_menu` — READ

Definición: obtiene un menú concreto con sus items, por `menu_id` o `menu_location`.

Para qué vale:

- Auditar estructura de navegación.
- Preparar una reordenación.

### `wp_create_nav_menu` — WRITE

Definición: crea un menú de navegación.

Para qué vale:

- Crear menús nuevos desde IA.

### `wp_add_nav_menu_item` — WRITE

Definición: añade un item a un menú. Soporta tipos `post_type`, `custom` y `taxonomy`.

Para qué vale:

- Añadir enlaces a páginas, posts, categorías o URLs externas.

### `wp_update_nav_menu_item` — WRITE

Definición: actualiza un item de navegación.

Para qué vale:

- Cambiar título, destino, padre u orden de un item.

### `wp_delete_nav_menu_item` — WRITE

Definición: elimina un item de menú.

Para qué vale:

- Limpiar navegación.

### `wp_delete_nav_menu` — WRITE

Definición: elimina un menú por `menu_id`.

Para qué vale:

- Borrar menús antiguos o duplicados.

### `wp_reorder_menu_items` — WRITE

Definición: reordena items de un menú en una operación. Registra orden anterior para rollback.

Para qué vale:

- Reorganizar navegación de forma auditable.

## WordPress: opciones, settings y metadatos

### `wp_get_option` — SENSITIVE READ

Definición: obtiene el valor de una opción de WordPress.

Para qué vale:

- Diagnosticar configuración.
- Revisar opciones específicas sin entrar en la base de datos.

### `wp_get_plugin_settings` — SENSITIVE READ

Definición: inspecciona opciones relacionadas con plugins por slug/prefijos y redacta secretos recursivamente.

Para qué vale:

- Soporte de plugins.
- Auditar settings sin exponer claves.

### `wp_update_option` — WRITE

Definición: actualiza una opción de WordPress.

Para qué vale:

- Cambiar settings globales con permisos altos.

### `wp_get_post_meta` — SENSITIVE READ

Definición: obtiene metadatos de post.

Para qué vale:

- Diagnosticar campos custom.
- Leer datos de plugins asociados a un post.

### `wp_update_post_meta` — WRITE

Definición: actualiza metadatos de post.

Para qué vale:

- Ajustar campos custom, SEO, flags o datos técnicos.

### `wp_delete_post_meta` — WRITE

Definición: elimina metadatos de post.

Para qué vale:

- Limpiar datos incorrectos u obsoletos.

### `wp_get_settings` — SENSITIVE READ

Definición: obtiene settings de WordPress. Puede recibir un array `keys` para limitar la consulta.

Para qué vale:

- Diagnóstico de configuración general.

### `wp_update_settings` — WRITE

Definición: actualiza varios settings de WordPress.

Para qué vale:

- Cambios administrativos controlados.

## WordPress: revisiones, post types y salud

### `wp_get_post_revisions` — READ

Definición: obtiene revisiones de un post.

Para qué vale:

- Comparar historial de contenido.
- Preparar restauración.

### `wp_restore_post_revision` — WRITE

Definición: restaura un post a una revisión por `revision_id`.

Para qué vale:

- Recuperar contenido anterior.

### `wp_get_post_types` — READ

Definición: lista post types registrados con labels, capabilities y visibilidad.

Para qué vale:

- Descubrir CPTs.
- Preparar automatizaciones sobre tipos personalizados.

### `wp_get_site_health` — SENSITIVE READ

Definición: ejecuta auditoría del sitio con niveles de profundidad 0, 1 o 2.

Para qué vale:

- Diagnóstico técnico.
- Soporte y desarrollo.

## WordPress: utilidades, búsqueda y red

### `search` — READ

Definición: busca posts con filtros por tipo, autor, categoría, tag, estado, fecha, orden y paginación.

Para qué vale:

- Localizar contenido desde lenguaje natural.
- Preparar operaciones editoriales.

### `fetch` — SENSITIVE READ

Definición: obtiene una URL con la HTTP API de WordPress. Soporta método, query params, headers, timeout, redirects, modo HEAD, extracción de texto y límite de bytes.

Para qué vale:

- Consultar APIs externas.
- Comprobar una URL o leer contenido remoto.

### `wp_generate_image` — WRITE

Definición: genera una imagen con IA y la guarda como adjunto de WordPress. Usa proveedor configurado en Multimedia.

Para qué vale:

- Crear imágenes destacadas.
- Generar assets para posts, productos o campañas.

### `wp_generate_video` — WRITE

Definición: genera un vídeo con IA usando Google Veo u OpenAI Sora y lo guarda como media attachment. Es asíncrona y puede tardar varios minutos.

Para qué vale:

- Crear vídeos para contenido, ecommerce o redes.
- Automatizar multimedia desde prompts.

## WordPress: SEO e integraciones editoriales

### `wp_rm_get_head` — SENSITIVE READ

Definición: obtiene el HTML SEO head renderizado para una URL mediante Rank Math Headless CMS Support.

Para qué vale:

- Auditar salida SEO real.
- Ver tags meta generadas.

### `wp_rm_get_post_seo` — SENSITIVE READ

Definición: obtiene campos SEO de Rank Math para un post.

Para qué vale:

- Revisar title, description y metadatos SEO.

### `wp_rm_update_post_seo` — WRITE

Definición: actualiza campos SEO de Rank Math para un post.

Para qué vale:

- Optimizar SEO con IA.

### `yoast_get_meta` — SENSITIVE READ

Definición: obtiene metadatos Yoast SEO de un post: title, description, focus keyword, canonical, robots, OG y Twitter.

Para qué vale:

- Auditar SEO Yoast.

### `yoast_set_meta` — WRITE

Definición: establece metadatos Yoast SEO para un post.

Para qué vale:

- Generar títulos y descripciones SEO.
- Ajustar indexación o social previews.

### `yoast_reindex` — SENSITIVE READ

Definición: limpia indexables cache de Yoast para un post o para todos los posts.

Para qué vale:

- Forzar reconstrucción de datos SEO.

### `acf_get_field_groups` — SENSITIVE READ

Definición: lista field groups de ACF con claves, títulos y reglas de ubicación.

Para qué vale:

- Entender estructura de campos personalizados.

### `acf_get_fields` — SENSITIVE READ

Definición: obtiene valores ACF de un post, con keys, nombres, tipos y valores.

Para qué vale:

- Leer contenido estructurado.

### `acf_update_field` — WRITE

Definición: actualiza un campo ACF por nombre o key en un post.

Para qué vale:

- Rellenar contenido estructurado con IA.

## WordPress: formularios

### `wpforms_list_forms` — SENSITIVE READ

Definición: lista formularios WPForms con ID, título, estado y fecha de creación.

Para qué vale:

- Descubrir formularios disponibles.

### `wpforms_get_entries` — SENSITIVE READ

Definición: obtiene entradas de un formulario WPForms.

Para qué vale:

- Analizar leads o solicitudes.

### `gf_list_forms` — SENSITIVE READ

Definición: lista formularios Gravity Forms con ID, título, descripción y conteo de entradas.

Para qué vale:

- Descubrir formularios Gravity Forms.

### `gf_get_entries` — SENSITIVE READ

Definición: obtiene entradas de un formulario Gravity Forms.

Para qué vale:

- Revisar submissions y datos de clientes.

### `gf_update_entry` — WRITE

Definición: actualiza una entrada Gravity Forms: estado, leído, destacado o valores de campos.

Para qué vale:

- Marcar leads procesados.
- Corregir datos o clasificaciones.

### `forminator_list_forms` — SENSITIVE READ

Definición: lista formularios, polls y quizzes de Forminator.

Para qué vale:

- Descubrir assets de Forminator.

### `forminator_get_entries` — SENSITIVE READ

Definición: obtiene entradas de un formulario Forminator.

Para qué vale:

- Analizar datos capturados por formularios.

## Snippets

Requiere WPCode, Code Snippets o Woody Code Snippets.

### `snippet_list` — SENSITIVE READ

Definición: lista snippets con límite y offset. Devuelve ID, título, estado activo, tipo de código y ubicación.

Para qué vale:

- Auditar snippets instalados.
- Ver qué código custom existe.

### `snippet_get` — SENSITIVE READ

Definición: obtiene un snippet completo por ID, incluyendo código.

Para qué vale:

- Revisar código antes de modificarlo.

### `snippet_create` — WRITE

Definición: crea un snippet nuevo, inactivo por defecto. No ejecuta el código, solo lo almacena.

Para qué vale:

- Preparar snippets con revisión humana.

### `snippet_update` — WRITE

Definición: actualiza un snippet existente. Solo modifica campos proporcionados y no ejecuta código.

Para qué vale:

- Corregir snippets existentes.

### `snippet_delete` — WRITE

Definición: elimina un snippet por ID.

Para qué vale:

- Limpiar snippets obsoletos.

### `snippet_activate` — WRITE

Definición: activa un snippet por ID.

Para qué vale:

- Poner en marcha una automatización o ajuste de código.

### `snippet_deactivate` — WRITE

Definición: desactiva un snippet por ID.

Para qué vale:

- Apagar código custom sin borrarlo.

## Changelog y rollback

### `mcp_get_changelog` — SENSITIVE READ

Definición: obtiene el changelog/audit log de operaciones MCP con filtros y paginación.

Para qué vale:

- Auditar acciones realizadas por IA.
- Ver cambios por tool, operación, objeto, fecha o estado.

### `mcp_get_change_detail` — SENSITIVE READ

Definición: obtiene detalle completo de una entrada de changelog, incluyendo before/after state y argumentos.

Para qué vale:

- Investigar qué cambió exactamente.

### `mcp_rollback_change` — WRITE

Definición: revierte una entrada concreta a su estado anterior.

Para qué vale:

- Deshacer un cambio individual.

### `mcp_redo_change` — WRITE

Definición: reaplica una entrada previamente revertida.

Para qué vale:

- Rehacer cambios si el rollback fue innecesario.

### `mcp_rollback_session` — WRITE

Definición: revierte todos los cambios de una sesión en orden inverso.

Para qué vale:

- Deshacer una conversación o automatización completa.

## WooCommerce: productos y variaciones

### `wc_get_products` — READ

Definición: lista productos con filtros por estado, categoría, tag, búsqueda, paginación, orden y tipo. Puede incluir imágenes, categorías, atributos, conteo de variaciones y metadata de paginación.

Para qué vale:

- Consultar catálogo.
- Encontrar productos por estado, categoría, tipo o búsqueda.

### `wc_create_product` — WRITE

Definición: crea un producto WooCommerce con nombre, tipo, precios, descripción, SKU, stock, categorías, tags, imágenes y estado.

Para qué vale:

- Crear productos simples, variables, agrupados o externos.

### `wc_update_product` — WRITE

Definición: actualiza un producto por ID.

Para qué vale:

- Cambiar precio, stock, descripción, SKU, categorías, tags o estado.

### `wc_delete_product` — WRITE

Definición: elimina un producto por ID. `force=true` borra permanentemente.

Para qué vale:

- Retirar productos antiguos o erróneos.

### `wc_batch_update_products` — WRITE

Definición: actualiza múltiples productos en lote.

Para qué vale:

- Cambios masivos de stock, precios o estado.

### `wc_get_product_variations` — READ

Definición: obtiene variaciones de un producto variable.

Para qué vale:

- Revisar tallas, colores, SKUs o precios por variación.

### `wc_create_product_variation` — WRITE

Definición: crea una variación de producto.

Para qué vale:

- Añadir nuevas combinaciones de producto variable.

### `wc_update_product_variation` — WRITE

Definición: actualiza una variación.

Para qué vale:

- Cambiar precio, atributos o stock de una variación.

### `wc_delete_product_variation` — WRITE

Definición: elimina una variación.

Para qué vale:

- Limpiar variaciones obsoletas.

## WooCommerce: categorías, tags y reviews

### `wc_get_product_categories` — READ

Definición: lista categorías de producto.

Para qué vale:

- Ver estructura del catálogo.

### `wc_create_product_category` — WRITE

Definición: crea categoría de producto.

Para qué vale:

- Organizar catálogo ecommerce.

### `wc_update_product_category` — WRITE

Definición: actualiza categoría de producto.

Para qué vale:

- Renombrar o cambiar descripción/slug.

### `wc_delete_product_category` — WRITE

Definición: borra categoría de producto.

Para qué vale:

- Limpiar categorías antiguas.

### `wc_get_product_tags` — READ

Definición: lista tags de producto.

Para qué vale:

- Revisar clasificación del catálogo.

### `wc_create_product_tag` — WRITE

Definición: crea tag de producto.

Para qué vale:

- Etiquetar productos por campañas, atributos o colecciones.

### `wc_update_product_tag` — WRITE

Definición: actualiza tag de producto.

Para qué vale:

- Corregir nombres o slugs.

### `wc_delete_product_tag` — WRITE

Definición: borra tag de producto.

Para qué vale:

- Limpiar etiquetas no usadas.

### `wc_get_product_reviews` — READ

Definición: lista reviews de productos, opcionalmente por `product_id`.

Para qué vale:

- Analizar reseñas y satisfacción.

### `wc_create_product_review` — WRITE

Definición: crea una reseña de producto.

Para qué vale:

- Importar reseñas o crear respuestas internas controladas.

### `wc_update_product_review` — WRITE

Definición: actualiza una reseña.

Para qué vale:

- Moderar contenido, estado o puntuación.

### `wc_delete_product_review` — WRITE

Definición: elimina una reseña.

Para qué vale:

- Moderación de reseñas.

## WooCommerce: stock

### `wc_update_stock` — WRITE

Definición: actualiza cantidad de stock de un producto con operación `set`, `increase` o `decrease`.

Para qué vale:

- Ajustar inventario desde IA o automatizaciones.

### `wc_get_low_stock_products` — READ

Definición: obtiene productos por debajo de un umbral de stock.

Para qué vale:

- Alertas de inventario.
- Informes de reposición.

### `wc_set_stock_status` — WRITE

Definición: cambia estado de stock: `instock`, `outofstock` u `onbackorder`.

Para qué vale:

- Marcar productos agotados o disponibles.

## WooCommerce: pedidos, notas y refunds

### `wc_get_orders` — SENSITIVE READ

Definición: lista pedidos con filtros de estado, cliente, producto, fechas, paginación y enriquecimientos opcionales de items, totales y envío.

Para qué vale:

- Revisar ventas.
- Crear informes y análisis.
- Preparar acciones sobre pedidos.

### `wc_create_order` — WRITE

Definición: crea un pedido con cliente, billing, shipping, line items, envíos, fees, cupones, estado y método de pago.

Para qué vale:

- Crear pedidos manuales desde IA autorizada.

### `wc_update_order` — WRITE

Definición: actualiza un pedido por ID.

Para qué vale:

- Cambiar estado, datos de facturación/envío o líneas.

### `wc_delete_order` — WRITE

Definición: elimina un pedido por ID. `force=true` borra permanentemente.

Para qué vale:

- Limpiar pedidos de prueba o erróneos.

### `wc_batch_update_orders` — WRITE

Definición: actualiza varios pedidos en lote.

Para qué vale:

- Cambios masivos de estado o metadata.

### `wc_get_order_notes` — SENSITIVE READ

Definición: obtiene notas de un pedido.

Para qué vale:

- Revisar historial operativo o comunicación de un pedido.

### `wc_create_order_note` — WRITE

Definición: crea una nota en un pedido. Puede ser nota de cliente o interna.

Para qué vale:

- Registrar acciones o mensajes.

### `wc_delete_order_note` — WRITE

Definición: elimina una nota de pedido.

Para qué vale:

- Limpiar notas erróneas.

### `wc_create_refund` — WRITE

Definición: crea un reembolso para un pedido, con importe, motivo, líneas e inventario opcional.

Para qué vale:

- Gestionar devoluciones con trazabilidad.

### `wc_get_refunds` — READ

Definición: obtiene refunds de un pedido o todos los refunds.

Para qué vale:

- Auditar reembolsos.

### `wc_delete_refund` — WRITE

Definición: elimina un refund por ID.

Para qué vale:

- Corregir refunds creados por error.

## WooCommerce: cupones

### `wc_get_coupons` — READ

Definición: lista cupones con filtros por código, límite, offset y orden.

Para qué vale:

- Auditar promociones activas.

### `wc_create_coupon` — WRITE

Definición: crea un cupón con código, tipo de descuento, importe, expiración, límite de uso, uso individual y productos incluidos/excluidos.

Para qué vale:

- Crear campañas promocionales.

### `wc_update_coupon` — WRITE

Definición: actualiza un cupón por ID.

Para qué vale:

- Cambiar descuentos, expiración o límites.

### `wc_delete_coupon` — WRITE

Definición: elimina un cupón por ID.

Para qué vale:

- Limpiar promociones caducadas.

Nota de compliance:

- Las tools de clientes WooCommerce (`wc_get_customers`, `wc_create_customer`, `wc_update_customer`, `wc_delete_customer`) fueron retiradas.

## WooCommerce: reportes

### `wc_get_sales_report` — READ

Definición: obtiene reporte de ventas para rango de fechas.

Para qué vale:

- Resúmenes diarios, semanales o mensuales.

### `wc_get_top_sellers_report` — READ

Definición: obtiene productos más vendidos.

Para qué vale:

- Analizar catálogo y tendencias.

## WooCommerce: impuestos

### `wc_get_tax_classes` — READ

Definición: lista clases de impuestos.

Para qué vale:

- Revisar configuración fiscal.

### `wc_get_tax_rates` — READ

Definición: lista tasas de impuestos, con filtro opcional por clase.

Para qué vale:

- Auditar tasas por país/estado/clase.

### `wc_create_tax_rate` — WRITE

Definición: crea una tasa de impuesto.

Para qué vale:

- Configurar impuestos programáticamente.

### `wc_update_tax_rate` — WRITE

Definición: actualiza una tasa por ID.

Para qué vale:

- Corregir porcentaje, nombre o prioridad.

### `wc_delete_tax_rate` — WRITE

Definición: elimina una tasa por ID.

Para qué vale:

- Limpiar configuración fiscal antigua.

## WooCommerce: envíos

### `wc_get_shipping_zones` — READ

Definición: lista zonas de envío.

Para qué vale:

- Auditar cobertura y regiones.

### `wc_get_shipping_zone_methods` — READ

Definición: obtiene métodos de envío de una zona.

Para qué vale:

- Revisar métodos activos por zona.

### `wc_create_shipping_zone` — WRITE

Definición: crea una zona de envío.

Para qué vale:

- Configurar logística.

### `wc_update_shipping_zone` — WRITE

Definición: actualiza una zona.

Para qué vale:

- Cambiar nombre u orden de zona.

### `wc_delete_shipping_zone` — WRITE

Definición: elimina una zona.

Para qué vale:

- Limpiar zonas no usadas.

## WooCommerce: gateways, sistema, settings y webhooks

### `wc_get_payment_gateways` — READ

Definición: lista gateways de pago.

Para qué vale:

- Revisar medios de pago disponibles y activos.

### `wc_update_payment_gateway` — WRITE

Definición: actualiza ajustes de un gateway por ID.

Para qué vale:

- Activar/desactivar o modificar título/settings de un método de pago.

### `wc_get_system_status` — SENSITIVE READ

Definición: obtiene estado técnico de WooCommerce: entorno, versiones, base de datos y plugins activos.

Para qué vale:

- Soporte técnico y diagnóstico.

### `wc_run_system_status_tool` — WRITE

Definición: ejecuta herramientas de sistema, como limpiar transients o borrar variaciones huérfanas.

Para qué vale:

- Mantenimiento WooCommerce.

### `wc_get_settings` — SENSITIVE READ

Definición: obtiene settings WooCommerce por grupo: general, products, tax, shipping, checkout, account.

Para qué vale:

- Auditar configuración de tienda.

### `wc_update_setting_option` — WRITE

Definición: actualiza una opción de settings WooCommerce.

Para qué vale:

- Cambios administrativos sobre la tienda.

### `wc_get_webhooks` — READ

Definición: lista webhooks WooCommerce.

Para qué vale:

- Ver integraciones salientes configuradas.

### `wc_create_webhook` — WRITE

Definición: crea webhook WooCommerce con nombre, estado, topic y delivery URL.

Para qué vale:

- Integrar WooCommerce con sistemas externos.

### `wc_update_webhook` — WRITE

Definición: actualiza webhook.

Para qué vale:

- Cambiar URL, estado o nombre de integración.

### `wc_delete_webhook` — WRITE

Definición: elimina webhook.

Para qué vale:

- Retirar integraciones antiguas.

## Integraciones por plugin

Estas tools se agrupan en el tab Plugins cuando el plugin correspondiente está instalado/activo o cuando pertenecen al catálogo de integración.

### All Sources Images — DYNAMIC

Tools esperadas: `ability_allsi_*`.

Definición: abilities descubiertas del plugin All Sources Images.

Para qué vale:

- Buscar imágenes de stock.
- Generar imágenes IA.
- Insertar imágenes destacadas o inline en posts.

### AiPatch Security Scanner — DYNAMIC

Tools esperadas: `ability_aipatch_*`.

Definición: abilities del scanner de seguridad AiPatch.

Para qué vale:

- Auditoría de seguridad.
- Detección de vulnerabilidades.
- Recomendaciones asistidas por IA.

### Notification for Telegram — DYNAMIC

Tools esperadas: `ability_notification_for_telegram_*`.

Definición: abilities para enviar notificaciones por Telegram.

Para qué vale:

- Alertas desde automatizaciones.
- Notificaciones de pedidos, errores o tareas completadas.

### WPCode, Code Snippets y Woody Snippets

Tools relacionadas:

- `snippet_list`.
- `snippet_get`.
- `snippet_create`.
- `snippet_update`.
- `snippet_delete`.
- `snippet_activate`.
- `snippet_deactivate`.

Para qué vale:

- Gestionar código custom desde IA con controles y confirmación.

### ACF

Tools relacionadas:

- `acf_get_field_groups`.
- `acf_get_fields`.
- `acf_update_field`.

Para qué vale:

- Leer y actualizar campos personalizados estructurados.

### Yoast SEO

Tools relacionadas:

- `yoast_get_meta`.
- `yoast_set_meta`.
- `yoast_reindex`.

Para qué vale:

- Optimizar SEO on-page desde IA.

### Rank Math

Tools relacionadas:

- `wp_rm_get_head`.
- `wp_rm_get_post_seo`.
- `wp_rm_update_post_seo`.

Para qué vale:

- Leer y actualizar SEO de Rank Math.

### WPForms, Gravity Forms y Forminator

Tools relacionadas:

- `wpforms_list_forms`.
- `wpforms_get_entries`.
- `gf_list_forms`.
- `gf_get_entries`.
- `gf_update_entry`.
- `forminator_list_forms`.
- `forminator_get_entries`.

Para qué vale:

- Analizar leads, submissions y formularios.
- Marcar entradas o modificar Gravity Forms cuando corresponde.

## Tools dinámicas: `custom_*`

Definición: tools creadas por el administrador desde Custom Tools.

Prefijo obligatorio:

- `custom_`

Tipos:

- HTTP `GET`, `POST`, `PUT`, `DELETE`.
- ACTION para ejecutar hooks WordPress con `do_action()`.

Para qué vale:

- Conectar Zapier, Make, n8n, Slack, Discord, Jira, Notion, Twilio, CRMs o APIs privadas.
- Exponer acciones de plugins sin escribir código nativo.
- Crear prototipos de integraciones.

Ejemplos conceptuales:

- `custom_create_jira_ticket`.
- `custom_send_slack_message`.
- `custom_clear_cache`.
- `custom_run_crm_webhook`.

## Tools dinámicas: `ability_*`

Definición: tools importadas desde WordPress Abilities API.

Formato:

- Una ability `vendor/action-name` se transforma en `ability_vendor_action_name`.

Para qué vale:

- Exponer capacidades de plugins modernos como tools MCP.
- Mantener descripciones y schemas aportados por el plugin original.
- Crear un ecosistema extensible sin añadir código al core de StifLi Flex MCP.

## Tools retiradas por compliance

El código conserva notas de herramientas retiradas para cumplir WordPress.org.

Retiradas en WordPress:

- Crear usuario.
- Actualizar usuario.
- Borrar usuario.
- Instalar plugin.
- Activar plugin.
- Desactivar plugin.
- Instalar theme.
- Cambiar theme.
- Borrar opción directa `wp_delete_option`.

Retiradas en WooCommerce:

- `wc_get_customers`.
- `wc_create_customer`.
- `wc_update_customer`.
- `wc_delete_customer`.

Mensaje recomendado para documentación pública:

- El plugin evita operaciones sensibles de gestión de usuarios, instalación de plugins/themes y borrado directo de opciones para ajustarse a buenas prácticas y políticas de WordPress.org.

## Resumen por casos de uso

### Contenido editorial

Tools principales:

- `wp_get_posts`, `wp_get_post`, `wp_create_post`, `wp_update_post`, `wp_delete_post`.
- `wp_get_pages`, `wp_create_page`, `wp_update_page`.
- `wp_set_featured_image`.
- `wp_generate_image`.
- SEO: `yoast_*`, `wp_rm_*`.

### Ecommerce

Tools principales:

- `wc_get_products`, `wc_create_product`, `wc_update_product`.
- `wc_get_orders`, `wc_update_order`, `wc_create_order_note`.
- `wc_update_stock`, `wc_get_low_stock_products`.
- `wc_create_coupon`, `wc_get_sales_report`.

### Soporte y mantenimiento

Tools principales:

- `mcp_ping`.
- `wp_get_site_health`.
- `wp_list_plugins`.
- `wp_get_themes`.
- `wc_get_system_status`.
- `fetch`.
- `mcp_get_changelog` y rollback.

### Automatización e integraciones

Tools principales:

- `custom_*`.
- `ability_*`.
- Webhooks WooCommerce.
- Notification for Telegram abilities.
- Formularios y snippets.

### Seguridad operacional

Tools principales:

- Perfiles.
- READ/WRITE confirmations.
- Changelog.
- Rollback individual.
- Rollback por sesión.
- Debug log.
