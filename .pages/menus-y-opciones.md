# Menús y opciones del plugin StifLi Flex MCP

Documento de referencia para preparar la página web, documentación, tutoriales y capturas del plugin. Describe lo que ve un administrador en WordPress y qué hace cada opción.

## Visión general

StifLi Flex MCP añade un menú principal en el administrador de WordPress llamado **StifLi Flex MCP**. Desde ahí se gestionan tres grandes bloques:

- Chat y Copilot con IA dentro del administrador de WordPress.
- Servidor MCP para conectar clientes externos como ChatGPT, Claude, LibreChat u otros clientes compatibles.
- Automatizaciones, logs, herramientas, perfiles, multimedia e integraciones.

El plugin es un plugin PHP puro de WordPress. No tiene build step ni panel externo: todo se configura dentro del admin de WordPress.

## Árbol de navegación

Menú principal:

```text
StifLi Flex MCP
├─ AI Chat Agent
│  ├─ Chat
│  └─ Advanced Settings
├─ MCP Server
│  ├─ Settings
│  ├─ Profiles
│  ├─ WordPress Tools
│  ├─ WooCommerce Tools
│  ├─ Abilities (solo WordPress 6.9+ con Abilities API)
│  ├─ Plugins (tab añadido por el módulo de integraciones)
│  ├─ Help
│  └─ Custom Tools (legacy, solo si se activa por filtro)
├─ Automation Tasks
│  ├─ Tasks
│  ├─ Create Task
│  ├─ Execution Logs
│  └─ Templates
├─ Event Automations
│  ├─ Event Automations
│  ├─ Create
│  └─ Execution Logs
├─ AI Copilot
│  ├─ Copilot Settings
│  └─ WebMCP Browser AI
├─ Multimedia
│  ├─ Images
│  └─ Videos
└─ Logs & Roll Back
   ├─ Changelog
   └─ Debug Log
```

## Conceptos globales

### MCP Server

El plugin convierte el sitio WordPress en un servidor MCP con herramientas JSON-RPC 2.0. Los clientes externos consultan `tools/list` para descubrir herramientas y usan `tools/call` para ejecutarlas.

Endpoints principales:

- SSE para clientes MCP: `/wp-json/stifli-flex-mcp/v1/sse`
- Mensajes JSON-RPC: `/wp-json/stifli-flex-mcp/v1/messages`
- OAuth: endpoints de autorización, token, revocación y registro dinámico.

### Herramientas habilitadas

No todas las herramientas se entregan siempre al cliente. Una tool debe existir en el registro y estar habilitada en las tablas del plugin. Los perfiles y los toggles de herramientas controlan lo que se expone.

### Perfiles

Un perfil es una selección de tools preparada para un caso de uso. Sirve para cambiar rápido entre modo seguro, WordPress completo, WooCommerce, debugging o sitio completo.

### Tools de lectura y escritura

La UI marca herramientas como `READ` o `WRITE`. Las de escritura crean, actualizan, eliminan o ejecutan acciones con efecto sobre WordPress/WooCommerce. Las de lectura solo consultan datos, aunque algunas lecturas se consideran sensibles, como opciones, metadatos o estado del sistema.

### Estimación de tokens

Muchas pantallas muestran el coste aproximado en tokens de las tools habilitadas. No es un coste de API exacto: sirve para comparar perfiles y reducir el peso del schema que se envía al modelo.

## Menú: AI Chat Agent

Página principal del plugin. Permite conversar con un proveedor de IA y darle acceso a las tools MCP habilitadas.

### Tab: Chat

Opciones visibles:

- **AI Provider**: selecciona el proveedor del chat. Opciones: OpenAI, Claude/Anthropic o Gemini/Google.
- **API Key**: guarda la clave del proveedor seleccionado. Se almacena cifrada y se puede mostrar/ocultar desde el campo.
- **Model**: selector de modelo disponible para el proveedor activo.
- **Tool Permissions**: controla si el agente puede ejecutar tools directamente o debe pedir confirmación.
  - `Always Allow`: permite ejecutar tools sin modal de confirmación.
  - `Ask User`: muestra una solicitud de ejecución antes de usar la tool.
- **Saved / autosave indicator**: indicador de guardado automático de los ajustes.
- **Chat with AI**: área principal de conversación.
- **Enabled tools count**: muestra cuántas tools están habilitadas y permite ir a configurarlas.
- **Tooltip de tools habilitadas**: lista los nombres internos de las tools disponibles para el chat.
- **Token bars**: barras de input, cache y output que aparecen cuando hay datos de uso.
- **Clear Chat**: borra el historial visible de la conversación.
- **Tool Execution Request modal**: cuando los permisos están en modo `Ask User`, muestra la tool, argumentos y botones `Allow` / `Deny`.
- **Input del chat**: campo para escribir la petición.
- **Send**: envía el mensaje al modelo.

Para qué sirve:

- Probar las tools del sitio sin salir del admin.
- Crear o modificar contenido con supervisión.
- Consultar posts, productos, pedidos, opciones, SEO, formularios y datos de WooCommerce.
- Depurar qué tools quiere usar el modelo antes de automatizar.

Detalles importantes para documentación:

- Usa las tools habilitadas por el perfil activo.
- El historial se guarda por usuario durante un periodo temporal.
- La configuración del proveedor también alimenta Automation Tasks, Event Automations y AI Copilot.

### Tab: Advanced Settings

Opciones visibles:

- **AI Provider & Model**: permite cambiar proveedor y modelo también desde ajustes avanzados.
- **System Prompt**: instrucciones globales enviadas con cada mensaje. Define comportamiento, tono y reglas del agente.
- **Tool Display Mode**: controla cómo se muestran las ejecuciones de tools en el chat.
  - `Full`: nombre, descripción y parámetros.
  - `Compact`: nombre y descripción resumida.
  - `Name Only`: solo nombre de tool.
  - `Hidden`: las ejecuciones aparecen colapsadas por defecto.
- **Max Tool Cycles in History**: número máximo de ciclos tool call/tool result que se mantienen en el historial enviado al proveedor.
- **Max Tools Per Turn**: límite de tools que el agente puede ejecutar en una respuesta.
- **Enable Suggestions**: activa sugerencias clicables después de respuestas del modelo.
- **Number of Suggestions**: cantidad de sugerencias, de 1 a 6.
- **Explicit Caching**: cache explícito del contexto para Gemini 2.5+ y modelos 3.x. Reduce costes en prompts repetidos al cachear system prompt y definiciones de tools durante 30 minutos.
- **Temperature**: controla creatividad/aleatoriedad.
- **Max Tokens**: longitud máxima de respuesta.
- **Top P**: muestreo nucleus, alternativa a temperature.
- **Frequency Penalty**: reduce repetición, aplicable a OpenAI.
- **Presence Penalty**: fomenta temas nuevos, aplicable a OpenAI.

Para qué sirve:

- Ajustar comportamiento del agente para producción.
- Reducir coste de tokens.
- Hacer que el chat sea más transparente o más compacto.
- Limitar el número de acciones automáticas por turno.

## Menú: MCP Server

Panel central del servidor MCP. Gestiona conexión externa, perfiles y catálogo de tools.

### Tab: Settings

Opciones visibles:

- **Connect your AI assistant**: bloque de conexión rápida.
- **SSE URL**: URL que debe copiarse en clientes MCP como Claude Desktop o ChatGPT Connectors.
- **Copy**: copia la URL SSE.
- **Pasos de conexión**:
  - Copiar la URL.
  - Pegarla en el cliente IA.
  - Autorizar desde WordPress cuando el navegador lo solicite.
- **Estado de clientes conectados**: si existen clientes OAuth, muestra número de clientes y sesiones activas.
- **View More Details**: despliega información avanzada.
- **Connected Clients**: tabla de clientes OAuth registrados.
  - Client Name.
  - Client ID.
  - Active Tokens.
  - Registered.
  - Actions.
- **Active Tokens**: al expandir tokens se ve usuario, scope, fecha de emisión, fecha de expiración y acción `Revoke`.
- **Delete client**: elimina un cliente OAuth y revoca sus tokens.
- **Troubleshooting**: ayuda para conexión fallida, autorización recurrente y clientes que no aparecen.
- **Alternative: Application Passwords**: enlace al perfil del usuario para crear una Application Password si el cliente no soporta OAuth.

Para qué sirve:

- Conectar el sitio con clientes MCP externos.
- Gestionar clientes OAuth y sesiones activas.
- Explicar el onboarding de ChatGPT y Claude.
- Resolver problemas comunes de conexión.

Notas para tutorial:

- ChatGPT Connectors debe usar SSE.
- El sitio debe usar HTTPS para integraciones reales.
- Los clientes OAuth se registran automáticamente mediante Dynamic Client Registration cuando está permitido.
- También se puede usar Basic Auth con Application Passwords para clientes compatibles.

### Tab: Profiles

Opciones visibles:

- **Import JSON**: importa un perfil desde un archivo JSON con lista de tools.
- **Restore System Profiles**: recrea los perfiles del sistema si fueron borrados o quedaron desactualizados.
- **Currently active profile**: muestra el perfil activo y su huella estimada de tokens.
- **System Profiles (non-deletable)**: tabla de perfiles del sistema.
- **Custom Profiles**: tabla de perfiles personalizados.

Columnas de las tablas:

- Indicador de activo.
- Name.
- Description.
- Tools: conteo de tools incluidas frente al total.
- Tokens: suma estimada de tokens.
- Actions.

Acciones disponibles:

- **Apply**: aplica el perfil y habilita únicamente las tools incluidas en él.
- **View tools**: muestra el listado de tools dentro del perfil.
- **Duplicate**: crea una copia editable/personalizable del perfil.
- **Export**: descarga el perfil como JSON.
- **Edit**: aparece en perfiles custom. En esta versión el backend de edición directa está marcado como pendiente, por lo que el flujo más fiable es duplicar, importar/exportar o modificar tools con un perfil activo.
- **Delete**: elimina perfiles custom. Los perfiles del sistema no se pueden borrar.

Perfiles del sistema:

- **WordPress Read Only**: lectura segura de WordPress sin operaciones de escritura ni datos sensibles.
- **WordPress Full Management**: gestión completa de WordPress: posts, páginas, comentarios, taxonomías, media, opciones, settings, snippets y diagnóstico.
- **WooCommerce Read Only**: lectura de productos, variaciones, categorías, tags, reviews, pedidos, notas, cupones, stock bajo, refunds y reportes.
- **WooCommerce Store Management**: gestión de productos, stock, pedidos, refunds, cupones y reportes, sin settings avanzados.
- **Complete E-commerce**: todas las tools WooCommerce, incluyendo impuestos, envíos, gateways, settings y webhooks.
- **Complete Site**: todas las tools disponibles en el registro.
- **Safe Mode**: lectura no sensible, sin options, settings, user_meta ni system status.
- **Development/Debug**: diagnóstico, site health, settings, plugins, themes y tools de inspección.

Para qué sirve:

- Reducir riesgo y coste antes de conectar un cliente IA.
- Dar a cada caso de uso solo las tools necesarias.
- Preparar perfiles para agencias, soporte, ecommerce, desarrollo o solo lectura.

### Tab: WordPress Tools

Opciones visibles:

- **Total estimated tokens for enabled WordPress tools**: suma de tokens de tools WordPress activas.
- **Reset and Reseed Tools**: borra y vuelve a sembrar el catálogo de tools desde el plugin. Útil tras actualizar.
- **Active profile notice**: si hay perfil activo, avisa de que los cambios de tools se sincronizan automáticamente con ese perfil.
- **Categorías desplegables**: agrupan tools por área funcional.
- **Checkbox de categoría**: activa/desactiva una categoría completa.
- **Checkbox de tool**: activa/desactiva una tool concreta.
- **Tool name**: nombre técnico usado por MCP.
- **Description**: qué hace la tool.
- **READ/WRITE badge**: indica si la tool solo lee o modifica/ejecuta acciones.
- **Token estimate**: coste aproximado del schema de la tool.

Categorías habituales:

- Core.
- WordPress - Posts.
- WordPress - Pages.
- WordPress - Comments.
- WordPress - Users.
- WordPress - User Meta.
- WordPress - Media.
- WordPress - Taxonomies.
- WordPress - Categories.
- WordPress - Tags.
- WordPress - Menus.
- WordPress - Options.
- WordPress - Meta.
- WordPress - Settings.
- WordPress - Revisions.
- WordPress - Post Types.
- WordPress - Health.
- WordPress - Utilities.
- WordPress - SEO.
- WordPress - Changelog.
- Snippets.

Para qué sirve:

- Decidir qué puede hacer un cliente MCP externo.
- Mantener un perfil de mínimo privilegio.
- Desactivar tools de alto riesgo o alto coste.
- Sincronizar cambios con el perfil activo.

### Tab: WooCommerce Tools

Opciones visibles:

- **Total estimated tokens for enabled WooCommerce tools**.
- **WooCommerce warning**: si WooCommerce no está activo, las tools pueden configurarse pero no funcionarán hasta activar WooCommerce.
- **Categorías desplegables**.
- **Checkbox de categoría y de tool**.
- **READ/WRITE badge**.
- **Token estimate**.

Categorías habituales:

- WooCommerce - Products.
- WooCommerce - Categories.
- WooCommerce - Tags.
- WooCommerce - Reviews.
- WooCommerce - Stock.
- WooCommerce - Orders.
- WooCommerce - Refunds.
- WooCommerce - Coupons.
- WooCommerce - Reports.
- WooCommerce - Tax.
- WooCommerce - Shipping.
- WooCommerce - Gateways.
- WooCommerce - System.
- WooCommerce - Settings.
- WooCommerce - Webhooks.

Para qué sirve:

- Exponer operaciones ecommerce a IA de forma granular.
- Separar lectura de pedidos/productos de acciones de gestión.
- Activar herramientas avanzadas solo cuando sean necesarias.

### Tab: Abilities

Visible solo cuando WordPress tiene disponible la Abilities API, actualmente pensada para WordPress 6.9+.

Opciones visibles:

- **Discover Abilities**: escanea WordPress para encontrar abilities registradas por otros plugins.
- **Discovered abilities**: lista abilities disponibles para importar.
- **Imported Abilities**: lista abilities ya expuestas como tools MCP.
- **Enabled toggle**: activa/desactiva una ability importada.
- **Delete ability**: elimina una ability importada del catálogo MCP.

Para qué sirve:

- Convertir abilities de plugins en tools MCP sin escribir integración manual.
- Importar capacidades de plugins como All Sources Images, AiPatch u otros que adopten Abilities API.

Nomenclatura:

- Una ability como `plugin/action-name` se expone como tool `ability_plugin_action_name`.

### Tab: Plugins

Tab añadido por el módulo de integraciones de plugins. Agrupa tools nativas y abilities según el plugin que las proporciona o que las necesita.

Integraciones catalogadas:

- **All Sources Images**: abilities `ability_allsi_*` para buscar/generar/insertar imágenes.
- **AiPatch Security Scanner**: abilities `ability_aipatch_*` para auditoría y recomendaciones de seguridad.
- **Notification for Telegram**: abilities `ability_notification_for_telegram_*` para enviar mensajes por Telegram.
- **WPCode**: tools `snippet_*` para gestionar snippets.
- **Code Snippets**: usa el mismo pack `snippet_*`.
- **Woody Snippets**: usa el mismo pack `snippet_*`.
- **Advanced Custom Fields**: `acf_get_field_groups`, `acf_get_fields`, `acf_update_field`.
- **Yoast SEO**: `yoast_get_meta`, `yoast_set_meta`, `yoast_reindex`.
- **Rank Math**: `wp_rm_get_head`, `wp_rm_get_post_seo`, `wp_rm_update_post_seo`.
- **WPForms**: `wpforms_list_forms`, `wpforms_get_entries`.
- **Gravity Forms**: `gf_list_forms`, `gf_get_entries`, `gf_update_entry`.
- **Forminator**: `forminator_list_forms`, `forminator_get_entries`.

Opciones funcionales esperadas:

- Ver estado de cada integración: disponible, activa, no instalada o pendiente.
- Activar/desactivar grupos de tools relacionados con un plugin.
- Ver conteos de tools, lectura/escritura y tokens.
- Instalar o activar plugin cuando WordPress lo permita.
- Descubrir abilities para plugins que las registren.
- Sincronizar herramientas con el perfil activo.

Para qué sirve:

- Separar herramientas dependientes de plugins del catálogo WordPress general.
- Explicar al usuario qué plugin necesita para que una tool funcione.
- Promover integraciones recomendadas.

### Tab: Custom Tools (legacy)

Este tab no aparece por defecto. Se activa mediante el filtro `sflmcp_enable_legacy_custom_tab`.

Opciones visibles:

- **Your Custom Tools**: tabla AJAX de tools personalizadas.
- **Add New Tool**: abre modal para crear una tool.
- **Internal Name**: nombre único; debe empezar por `custom_` y usar minúsculas, números y guiones bajos.
- **Description**: instrucción visible para la IA.
- **Type**:
  - `GET`, `POST`, `PUT`, `DELETE` para llamadas HTTP.
  - `ACTION` para ejecutar un hook interno de WordPress con `do_action`.
- **Webhook URL / Endpoint**:
  - En HTTP: URL completa.
  - En ACTION: nombre de action hook de WordPress.
- **Parameters**: constructor de schema para que la IA sepa qué argumentos pedir.
- **Required**: marca parámetros obligatorios.
- **Advanced Settings (Headers)**: cabeceras HTTP en JSON.
- **Enable this tool**: activa/desactiva la tool custom.
- **Test Connection**: prueba endpoint o hook con datos de ejemplo.
- **Save Tool**: guarda la tool.

Para qué sirve:

- Crear webhooks hacia Zapier, Make, n8n, Slack, Discord, Jira, Notion, APIs internas, etc.
- Exponer hooks de WordPress o plugins como herramientas para IA.
- Prototipar integraciones sin programar una tool nativa.

### Tab: Help

Guía embebida con documentación interna.

Secciones principales:

- Qué es MCP.
- Tools built-in.
- Custom Tools overview.
- WordPress Action Hooks.
- Cómo encontrar actions de plugins.
- Webhooks y APIs externas.
- REST API interna de WordPress.
- Casos de uso reales.
- Seguridad.
- Troubleshooting.

Para qué sirve:

- Onboarding dentro del plugin.
- Material base para tutoriales web.
- Explicar casos de uso de custom tools y hooks.

## Menú: Automation Tasks

Permite crear tareas programadas que ejecutan prompts de IA con tools MCP.

### Tab: Tasks

Opciones visibles:

- **All Status**: filtro por estado.
  - Active.
  - Paused.
  - Error.
  - Draft.
- **Refresh**: recarga la lista.
- **New Task**: abre el formulario de creación.
- **Task cards/list**: lista tareas existentes.
- **Task actions modal**: modal para detalles o acciones sobre tareas.
- **WP-Cron notice**: aviso de que WP-Cron depende de visitas y recomendación de cron real de servidor.

Para qué sirve:

- Ver automatizaciones programadas.
- Pausar/activar/ejecutar/eliminar tareas.
- Detectar problemas de cron en sitios de poco tráfico.

### Tab: Create Task

Formulario por pasos:

1. **Basic Information**
   - Task Name.
   - Quick Start from Template.

2. **AI Prompt**
   - Provider/model heredado de AI Chat Agent.
   - Aviso si la API key no está configurada.
   - Test Chat para probar el prompt.
   - Prompt principal.
   - Variables disponibles: `{site_name}`, `{date}`, `{datetime}`, `{admin_email}`, `{day_of_week}`.
   - System Prompt opcional.
   - Tools Detected summary tras probar.

3. **Tools Selection**
   - Use Active Profile.
   - Detected Tools Only: usa solo las tools detectadas durante el test para reducir tokens.
   - Custom Selection: selección manual.
   - Search tools.
   - Show all tools, incluyendo deshabilitadas.
   - Manage Tools link.

4. **Schedule**
   - Run Frequency con presets.
   - Custom Time.
   - Timezone.
   - Next executions preview.

5. **Guardrails**
   - Monthly Token Budget.
   - `0` significa sin límite.
   - Si se supera, la tarea se salta hasta el mes siguiente.

6. **Output Actions**
   - Save to execution log, siempre activo.
   - Send email notification.
   - Send to Webhook.
   - Create draft post.
   - Email recipients.
   - Email subject.
   - Webhook preset: Custom, Slack, Discord, Telegram.
   - Webhook URL.
   - Draft post type.

Acciones finales:

- **Save as Draft**.
- **Create & Activate Task**.

Para qué sirve:

- Crear informes diarios/semanales.
- Crear borradores recurrentes.
- Monitorizar stock, comentarios o pedidos.
- Ejecutar mantenimiento o análisis con IA.

### Tab: Execution Logs

Opciones visibles:

- **All Tasks**: filtro por tarea.
- **All Status**: Success, Error, Running.
- **Date range**: últimos 7, 30 o 90 días.
- **Refresh**.
- **Stats**: métricas agregadas cargadas por JS.
- **Logs table**: historial de ejecuciones.
- **Log detail modal**.

Para qué sirve:

- Auditar tareas automáticas.
- Revisar respuestas de la IA.
- Ver errores y consumo.

### Tab: Templates

Plantillas predefinidas detectadas:

- Daily Sales Report.
- Daily Trending Article.
- Weekly Content Roundup.
- Low Stock Alert.
- Comment Moderation Assistant.
- Weekly Content Summary.
- SEO Meta Optimizer.
- Review Response Generator.
- Expired Coupons Cleanup.
- Weekly Performance Insights.

Para qué sirve:

- Dar ejemplos listos para usar.
- Acelerar onboarding de usuarios no técnicos.
- Mostrar casos de uso de ecommerce, contenido, SEO y mantenimiento.

## Menú: Event Automations

Automatizaciones disparadas por eventos de WordPress, WooCommerce o plugins de formularios.

### Tab: Event Automations

Opciones visibles:

- **All Triggers**: filtro por trigger.
- **All Status**: Active, Paused, Error, Draft.
- **Refresh**.
- **New Automation**.
- Lista de automatizaciones.

Para qué sirve:

- Ver automatizaciones que responden a eventos.
- Gestionar flujos reactivos: nuevo pedido, post publicado, formulario enviado, etc.

### Tab: Create

Formulario por pasos:

1. **Basic Information**
   - Automation Name.

2. **Trigger Event**
   - Platform cards: WordPress o WooCommerce.
   - Warning si WooCommerce no está instalado.
   - Trigger selector.
   - Trigger description.
   - Available Placeholders: variables del evento que se pueden insertar en el prompt.
   - Conditions builder: reglas opcionales para ejecutar solo cuando se cumplan condiciones.

3. **AI Prompt**
   - Provider/model heredado de AI Chat Agent.
   - Test Chat.
   - Prompt con placeholders tipo `{{post_title}}`, `{{post_content}}`, `{{order_id}}`.
   - System Prompt opcional.
   - Tools Detected summary.

4. **Tools Selection**
   - Use Active Profile.
   - Detected Tools Only.
   - Custom Selection.
   - Search tools.
   - Show all tools.

5. **Output Actions**
   - Save to execution log.
   - Send email notification.
   - Send to Webhook.
   - Create draft post.
   - Email recipients y subject.
   - Webhook preset: Custom, Slack, Discord, Telegram.
   - Draft post type.

Acciones finales:

- **Save as Draft**.
- **Create & Activate** o **Update & Activate**.

### Tab: Execution Logs

Opciones visibles:

- **All Automations**.
- **All Status**: Success, Error, Skipped.
- **Refresh**.
- Lista de logs.
- Modal de detalle.

### Triggers incluidos

WordPress - Posts:

- Post Published.
- Post Updated.
- Post Trashed.
- Post Deleted.
- Page Published.
- Post Status Changed.

WordPress - Users:

- User Registered.
- User Logged In.
- User Logged Out.
- Login Failed.
- Profile Updated.
- User Role Changed.
- User Deleted.

WordPress - Comments:

- Comment Posted.
- Comment Approved.
- Comment Marked Spam.
- Comment Status Changed.

WordPress - Media:

- Media Uploaded.
- Media Deleted.

WordPress - System:

- Plugin Activated.
- Plugin Deactivated.
- Theme Switched.

WooCommerce - Orders:

- New Order Created.
- Order Status Changed.
- Order Completed.
- Order Processing.
- Order Cancelled.
- Order Refunded.
- Payment Complete.

WooCommerce - Products:

- Product Created.
- Product Updated.
- Product Stock Changed.
- Product Low Stock.
- Product Out of Stock.

WooCommerce - Customers:

- Customer Created.

WooCommerce - Cart:

- Product Added to Cart.
- Checkout Complete.
- Coupon Applied.

Forms:

- Contact Form 7 Submitted.
- Gravity Form Submitted.
- WPForms Submitted.

Para qué sirve:

- Responder con IA a eventos reales.
- Enviar alertas, generar drafts, moderar, clasificar, resumir o llamar webhooks.
- Crear automatizaciones sin depender de un horario.

## Menú: AI Copilot

Configura el asistente flotante dentro del admin de WordPress y el editor.

### Copilot Settings

Opciones principales:

- **Enable AI Copilot**: activa/desactiva el widget flotante.
- **Tools Mode**: define qué tools puede usar el Copilot.
  - `local`: solo herramientas locales del editor y, cuando corresponde, `wp_generate_image`.
  - `local_mcp_subset`: herramientas locales más un subconjunto MCP relacionado con el contexto.
  - `local_mcp_full`: herramientas locales más el catálogo MCP habilitado.

Comportamiento:

- Usa la configuración de proveedor/API key de AI Chat Agent.
- Aparece como widget flotante en el admin.
- En el editor de posts/páginas añade contexto del contenido actual.
- Puede usar tools locales `copilot_*` para modificar visualmente título, extracto, slug, estado, categorías, tags, contenido, bloques e imágenes.
- Las modificaciones locales son instantáneas en el editor y el usuario puede mantenerlas o deshacerlas.

### WebMCP Browser AI

Opciones principales:

- **Enable WebMCP Bridge**: activa modo navegador/Browser AI cuando existe soporte del navegador.
- **Language**: idioma preferido del agente local.
- **System Prompt**: instrucciones para WebMCP.
- **Disabled Tools**: lista de tools locales desactivadas.

Para qué sirve:

- Probar IA local del navegador sin depender siempre de API cloud.
- Registrar herramientas locales en `navigator.modelContext` cuando el navegador lo soporte.
- Dar asistencia contextual en el editor.

## Menú: Multimedia

Configura generación de imágenes y vídeos por IA. Las tools relacionadas son `wp_generate_image` y `wp_generate_video`.

### Tab: Images

Opciones visibles:

- **Tool toggle `wp_generate_image`**: activa/desactiva la tool para MCP clients y AI agents.
- **Pricing notice**: costes aproximados por imagen según proveedor/modelo.
- **Image Generation Provider**: OpenAI o Gemini.

OpenAI Image Settings:

- **API Key**: clave OpenAI compartida para imagen/vídeo, independiente del Chat Agent.
- **Model**:
  - `gpt-image-1`.
  - `dall-e-3`.
  - `dall-e-2`.
- **Default Quality**: low, medium, high.
- **Default Size**: square, landscape, portrait.
- **Style**: natural o vivid, aplica a DALL-E 3.
- **Background**: auto, transparent, opaque; aplica a `gpt-image-1`.
- **Output Format**: png, jpeg, webp; aplica a `gpt-image-1`.

Gemini Image Settings:

- **API Key**: clave Gemini compartida para imagen/vídeo.
- **Model**:
  - `gemini-2.5-flash-image`.
  - `imagen-4.0-generate-001`.
  - `imagen-4.0-fast-generate-001`.
  - `imagen-4.0-ultra-generate-001`.
- **Default Aspect Ratio**: 1:1, 16:9, 9:16, 4:3, 3:4, 3:2, 2:3.

Image Post-Processing:

- **Enable Post-Processing**: comprime o redimensiona imágenes generadas antes de guardarlas en Media Library.
- **Max Width**.
- **Max Height**.
- **Compression Quality**.
- **Convert Format**: original, jpeg, webp, png.
- **GD Library status**: indica si GD está disponible o si WordPress usará otro editor de imagen.

Para qué sirve:

- Generar imágenes desde chat, copilot, automatizaciones o clientes externos.
- Guardar resultados como media attachments.
- Optimizar tamaño/formato automáticamente.

### Tab: Videos

Opciones visibles:

- **Tool toggle `wp_generate_video`**: activa/desactiva la tool para MCP clients y AI agents.
- **Pricing notice**: aviso de coste por segundo/video.
- **Video Generation Provider**: Google Veo o OpenAI Sora.

Google Veo Settings:

- **API Key**: clave Gemini compartida.
- **Model**:
  - `veo-3.0-generate-preview`.
  - `veo-2.0-generate-001`.

OpenAI Sora Settings:

- **API Key**: clave OpenAI compartida.
- **Model**:
  - `sora-2`.
  - `sora-2-pro`.

Default Video Parameters:

- **Duration**: 4s, 5s, 6s, 8s, 12s según soporte del proveedor.
- **Aspect Ratio**: 16:9, 9:16, 1:1.
- **Resolution**: 480p, 720p, 1080p según soporte.

Generation Timeout:

- **Poll Interval**: frecuencia de consulta al proveedor.
- **Max Wait**: tiempo máximo de espera, de 60 a 600 segundos.

Para qué sirve:

- Generar vídeos por IA desde una tool MCP.
- Guardar vídeos como media attachments.
- Permitir que una automatización cree recursos multimedia asíncronos.

## Menú: Logs & Roll Back

Panel de auditoría, rollback y depuración.

### Tab: Changelog

Opciones visibles:

- **Enable Change Tracking**: activa/desactiva tracking de operaciones mutating.
- **Stats**:
  - Total.
  - Creates.
  - Updates.
  - Deletes.
  - Rolled Back.
- **Filtros**:
  - Tool.
  - Operation: create, update, delete, file_create, file_delete.
  - Object Type.
  - Status: active o rolled back.
  - Source: MCP Connection, AI Chat Agent, Copilot Editor, Automation Task, Event Automation, WP Admin.
  - From.
  - To.
- **Filter** y **Reset**.
- **Export CSV**.
- **Purge older than days**.
- **Changelog table**:
  - ID.
  - Tool.
  - Operation.
  - Object.
  - Source.
  - Date.
  - User.
  - Status.
  - Actions.
- **Change Detail modal**:
  - Tool.
  - Operation.
  - Object.
  - Subtype.
  - User.
  - IP Address.
  - Source.
  - Date.
  - Session.
  - Status.
  - Arguments.
  - Before State.
  - After State.
- **Rollback Entire Session**: deshace todas las acciones de una sesión en orden inverso.
- **Rollback/Redo individual**: deshace o reaplica una entrada concreta cuando el estado lo permite.

Para qué sirve:

- Ver qué cambió la IA.
- Auditar acciones de herramientas.
- Recuperar cambios de una tool concreta o una conversación completa.
- Exportar evidencias para soporte.

### Tab: Debug Log

Opciones visibles:

- **Enable Logging**: activa logging de debug.
- **Log File**: ruta del archivo de log.
- **Current file size**.
- **Refresh Logs**.
- **Clear Logs**.
- **Log Contents**: muestra las últimas 500 líneas.

Notas:

- También puede activarse con `SFLMCP_DEBUG` en `wp-config.php`.
- El log incluye requests API, eventos de autenticación y ejecución de tools.

Para qué sirve:

- Diagnosticar conexión MCP.
- Ver errores de auth, JSON-RPC o tools.
- Ayudar a soporte técnico.

## Recomendaciones para capturas de la web

Capturas prioritarias:

- AI Chat Agent con proveedor configurado y tools habilitadas.
- Modal de confirmación de tool.
- MCP Server Settings mostrando URL SSE.
- Profiles con varios perfiles y token estimates.
- WordPress Tools con categorías desplegadas y badges READ/WRITE.
- Plugins tab con integraciones disponibles.
- Automation Task wizard en la sección de Tools Selection.
- Event Automation con trigger, placeholders y conditions.
- AI Copilot flotante dentro del editor.
- Multimedia Images con provider tabs.
- Logs & Roll Back con before/after state.

Mensajes clave para la web:

- Conecta WordPress con ChatGPT, Claude y clientes MCP.
- Gestiona WordPress y WooCommerce con tools controladas por perfiles.
- Crea automatizaciones programadas o basadas en eventos.
- Audita y revierte cambios con changelog y rollback.
- Extiende el sistema con plugins, snippets, abilities y custom tools.
