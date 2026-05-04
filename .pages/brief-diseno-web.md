# Brief para la web de StifLi Flex MCP

Documento para pasar a un agente de diseño web. Resume posicionamiento, arquitectura de contenido, mensajes clave, bloques recomendados y material visual necesario.

## Objetivo de la web

Crear una web/documentación que explique StifLi Flex MCP como una herramienta profesional para conectar WordPress y WooCommerce con agentes de IA mediante MCP, con control de permisos, automatizaciones, integraciones y rollback.

La web debe servir para tres cosas:

- Convertir: que un usuario entienda rápido qué problema resuelve y por qué instalarlo.
- Enseñar: guiar la conexión con ChatGPT, Claude y otros clientes MCP.
- Documentar: explicar menús, tools, perfiles, automatizaciones, seguridad y casos de uso.

## Audiencias principales

### Administradores WordPress

Necesitan entender:

- Cómo conectar un asistente IA al sitio.
- Qué permisos está dando.
- Cómo limitar riesgo con perfiles y tools.
- Cómo ver logs y revertir cambios.

Mensajes que les importan:

- Control granular de tools.
- OAuth y Application Passwords.
- Confirmaciones para acciones sensibles.
- Changelog y rollback.

### Tiendas WooCommerce

Necesitan entender:

- Qué puede hacer la IA con productos, stock, pedidos, cupones y reportes.
- Cómo evitar cambios peligrosos.
- Cómo automatizar informes y alertas.

Mensajes que les importan:

- Gestión de catálogo y stock.
- Informes de ventas.
- Low stock alerts.
- Pedido, refund y order notes con permisos.

### Agencias y freelancers

Necesitan entender:

- Cómo usar el plugin en sitios de clientes.
- Cómo crear perfiles seguros por cliente o tarea.
- Cómo usar AI Chat Agent y Copilot para trabajar más rápido.
- Cómo crear custom tools e integraciones.

Mensajes que les importan:

- Productividad dentro del admin.
- Perfiles reutilizables/exportables.
- Integraciones con plugins populares.
- Diagnóstico y rollback para soporte.

### Desarrolladores

Necesitan entender:

- Arquitectura MCP/JSON-RPC/SSE.
- Cómo añadir tools nativas, abilities o custom tools.
- Cómo probar endpoints.
- Cómo depurar logs.

Mensajes que les importan:

- JSON-RPC 2.0.
- SSE para clientes MCP.
- WordPress Abilities API.
- Hooks, webhooks y extensibilidad.

## Propuesta de posicionamiento

Frase corta:

> Convierte WordPress y WooCommerce en un servidor MCP seguro para agentes de IA.

Subfrase:

> Conecta ChatGPT, Claude o tu cliente MCP favorito, expón tools controladas por perfiles, automatiza tareas y revierte cambios cuando lo necesites.

Pilares:

- **Conexión MCP real**: SSE, JSON-RPC 2.0, OAuth 2.1 y descubrimiento de tools.
- **Control granular**: perfiles, toggles por tool, READ/WRITE, estimación de tokens.
- **WordPress + WooCommerce**: contenido, media, SEO, productos, pedidos, stock, reportes y ajustes.
- **Automatización**: tareas programadas y automatizaciones por eventos.
- **Extensible**: custom tools, WordPress Abilities, snippets, ACF, SEO, formularios e integraciones.
- **Auditable**: changelog, before/after state, rollback individual y rollback por sesión.

## Tono recomendado

La web debe sentirse técnica, clara y confiable. No debería parecer una landing genérica de IA. El usuario está dando acceso a su WordPress, así que el diseño debe transmitir control, seguridad y capacidad real.

Tono de copy:

- Directo.
- Orientado a workflows.
- Con ejemplos concretos.
- Evitar promesas vagas como “IA mágica”.
- Reforzar control, permisos, perfiles y rollback.

## Arquitectura de páginas recomendada

### 1. Home

Objetivo: explicar el valor en menos de un minuto.

Secciones recomendadas:

- Hero con mensaje claro: WordPress/WooCommerce como MCP server.
- Compatibilidad: ChatGPT, Claude, LibreChat, clientes MCP.
- Tres pasos: instalar, copiar URL SSE, autorizar.
- Bloque de capacidades: contenido, WooCommerce, automatizaciones, integraciones, rollback.
- Captura del AI Chat Agent o MCP Server Settings.
- Casos de uso por perfil: Admin, Store Manager, Agency, Developer.
- Seguridad y control: OAuth, perfiles, confirmations, changelog.
- CTA: instalar/ver documentación.

### 2. Getting Started

Objetivo: guía práctica para conectar el primer cliente.

Secciones:

- Requisitos: WordPress, HTTPS, usuario admin, plugin instalado.
- Instalación.
- Abrir `StifLi Flex MCP > MCP Server > Settings`.
- Copiar URL SSE.
- Conectar Claude Desktop.
- Conectar ChatGPT Connectors.
- Autorizar con OAuth.
- Probar `mcp_ping`.
- Solución de problemas.

### 3. Menús y configuración

Objetivo: mapa completo del admin.

Usar como fuente principal:

- `.pages/menus-y-opciones.md`

Secciones:

- AI Chat Agent.
- MCP Server.
- Profiles.
- Tools.
- Automation Tasks.
- Event Automations.
- AI Copilot.
- Multimedia.
- Logs & Roll Back.

### 4. Tools Catalog

Objetivo: catálogo de herramientas para usuarios avanzados.

Usar como fuente principal:

- `.pages/catalogo-tools.md`

Estructura recomendada:

- Qué es una tool.
- READ vs WRITE vs SENSITIVE READ.
- Tabla filtrable por categoría.
- WordPress tools.
- WooCommerce tools.
- Plugin integrations.
- Dynamic tools.
- Removed/compliance notes.

### 5. Profiles and Security

Objetivo: explicar cómo usar el plugin con mínimo privilegio.

Secciones:

- Qué es un perfil.
- Perfiles del sistema.
- Cuándo usar Safe Mode.
- Cómo activar solo tools necesarias.
- Confirmaciones para WRITE.
- OAuth clients y tokens.
- Application Passwords como alternativa.
- Changelog y rollback.
- Buenas prácticas.

### 6. AI Chat Agent and Copilot

Objetivo: documentar experiencias IA dentro de WordPress.

Secciones:

- Configurar proveedor y API key.
- Elegir modelo.
- Tool permissions.
- Chat con tools MCP.
- Sugerencias, history y parámetros avanzados.
- Copilot flotante en el admin.
- Copilot en editor de posts/páginas.
- WebMCP Browser AI beta.

### 7. Automations

Objetivo: explicar automatizaciones programadas y por eventos.

Secciones:

- Diferencia entre Automation Tasks y Event Automations.
- Crear una tarea programada.
- Probar prompt antes de activar.
- Tools selection: active profile, detected tools, custom selection.
- Token budget.
- Output actions: log, email, webhook, draft post.
- Triggers incluidos.
- Ejemplos de plantillas.

### 8. Integrations and Custom Tools

Objetivo: explicar extensibilidad.

Secciones:

- Plugin integrations tab.
- ACF, Yoast, Rank Math, WPForms, Gravity Forms, Forminator.
- Snippets providers.
- All Sources Images, AiPatch, Telegram abilities.
- Custom HTTP tools.
- Custom ACTION tools.
- WordPress hooks.
- Webhooks con Make/Zapier/n8n.
- WordPress Abilities API.

### 9. Multimedia

Objetivo: explicar generación de assets.

Secciones:

- `wp_generate_image`.
- `wp_generate_video`.
- OpenAI vs Gemini.
- Google Veo vs OpenAI Sora.
- Costes aproximados.
- Post-processing de imágenes.
- Guardado en Media Library.
- Uso desde Chat Agent, Copilot, MCP y automatizaciones.

### 10. Developer Docs

Objetivo: documentación técnica.

Secciones:

- MCP request flow.
- Endpoints.
- JSON-RPC methods.
- Tool discovery.
- Tool execution.
- SSE streaming.
- OAuth.
- Cómo añadir una tool nativa.
- Cómo añadir una integración.
- Testing con PowerShell.
- Debug logs.

## Home: estructura detallada sugerida

### Hero

Headline:

> WordPress and WooCommerce, ready for AI agents.

Alternativa en español:

> Convierte WordPress y WooCommerce en un servidor MCP para agentes de IA.

Supporting copy:

> Expón tools controladas por perfiles, conecta ChatGPT o Claude con OAuth, automatiza tareas y revierte cambios desde un changelog auditable.

CTA primario:

- Get Started.
- Install Plugin.
- View Docs.

CTA secundario:

- Explore Tools.
- Watch Setup.

Visual:

- Captura del MCP Server Settings con la URL SSE.
- O captura del AI Chat Agent ejecutando una tool.

### Sección: cómo funciona

Tres pasos:

1. Instala el plugin.
2. Copia la URL SSE.
3. Autoriza tu cliente IA y empieza a usar tools.

Mostrar mini-diagrama:

```text
AI Client -> SSE / JSON-RPC -> StifLi Flex MCP -> WordPress/WooCommerce APIs
```

### Sección: capacidades

Bloques recomendados:

- Manage WordPress content.
- Operate WooCommerce.
- Generate media.
- Automate scheduled tasks.
- React to events.
- Extend with plugins.
- Audit and rollback.

### Sección: seguridad

Copy recomendado:

> AI agents only see the tools you enable. Use profiles to limit access, require confirmation for write actions, and inspect every change with before/after logs.

Puntos:

- OAuth 2.1 and Application Passwords.
- READ/WRITE intent labels.
- Tool profiles.
- Changelog.
- Rollback.

### Sección: integraciones

Mostrar logos/nombres:

- WooCommerce.
- ACF.
- Yoast SEO.
- Rank Math.
- WPForms.
- Gravity Forms.
- Forminator.
- WPCode.
- Code Snippets.
- All Sources Images.
- AiPatch.
- Telegram.

### Sección: casos de uso

Cards sugeridas:

- “Create and update posts with context.”
- “Generate SEO metadata.”
- “Monitor low stock and create alerts.”
- “Summarize weekly sales.”
- “Respond to new orders or forms.”
- “Generate images and videos into the Media Library.”
- “Rollback a full AI session.”

## Tutoriales recomendados

### Primeros pasos

- Instalar y activar el plugin.
- Conectar Claude Desktop por SSE.
- Conectar ChatGPT con OAuth.
- Probar `mcp_ping`.
- Activar Safe Mode.

### Tools y perfiles

- Cómo elegir un perfil.
- Cómo crear un perfil custom duplicando uno del sistema.
- Cómo reducir tokens con tools específicas.
- Diferencia entre READ, WRITE y SENSITIVE READ.

### Chat Agent

- Configurar OpenAI/Claude/Gemini.
- Ejecutar una consulta de posts.
- Crear un borrador con confirmación de tool.
- Revisar historial y token bars.

### WooCommerce

- Listar productos y stock bajo.
- Crear un cupón.
- Ver pedidos recientes.
- Crear un reporte semanal de ventas.

### Automatizaciones

- Crear Daily Sales Report.
- Crear Low Stock Alert.
- Crear Weekly Content Summary.
- Crear Event Automation para nuevo pedido.
- Crear Event Automation para formulario enviado.

### Multimedia

- Configurar OpenAI para imágenes.
- Configurar Gemini/Imagen.
- Generar imagen destacada desde chat.
- Configurar generación de vídeo.
- Entender costes y timeouts.

### Logs y rollback

- Activar changelog.
- Ver before/after state.
- Hacer rollback de un cambio.
- Hacer rollback de una sesión completa.
- Exportar CSV.

### Integraciones

- Usar ACF fields desde IA.
- Optimizar SEO con Yoast o Rank Math.
- Leer entradas de formularios.
- Crear custom tool HTTP con Make.
- Crear custom tool ACTION con un hook WordPress.
- Importar WordPress Abilities.

## Capturas necesarias

Capturas de alta prioridad:

- Menú principal StifLi Flex MCP abierto.
- AI Chat Agent > Chat con provider/model/tools count.
- Modal de Tool Execution Request.
- AI Chat Agent > Advanced Settings.
- MCP Server > Settings con SSE URL.
- MCP Server > Profiles con perfiles del sistema.
- MCP Server > WordPress Tools con categoría desplegada.
- MCP Server > WooCommerce Tools con aviso si WooCommerce no está activo.
- MCP Server > Plugins con integraciones.
- MCP Server > Abilities si el entorno lo soporta.
- Automation Tasks > Create Task en Tools Selection.
- Automation Tasks > Templates.
- Event Automations > Create con trigger y placeholders.
- AI Copilot widget en editor.
- Multimedia > Images.
- Multimedia > Videos.
- Logs & Roll Back > Changelog detail modal.
- Logs & Roll Back > Debug Log.

Capturas secundarias:

- Connected Clients con tokens desplegados.
- Import/Export profile.
- Custom Tools legacy modal, si se decide documentarlo públicamente.
- WebMCP Browser AI settings.

## Componentes visuales recomendados

### Página de documentación

- Sidebar con navegación por secciones.
- Buscador de docs.
- Bloques de “Requires plugin” para tools de terceros.
- Badges: READ, WRITE, SENSITIVE READ, DYNAMIC.
- Badges: WordPress, WooCommerce, SEO, Forms, Snippets, Multimedia.
- Tablas filtrables para tools.
- Callouts para seguridad y costes.

### Tools Catalog

Campos recomendados para una tabla:

- Tool name.
- Category.
- Intent.
- Requires.
- Description.
- Use cases.

Filtros:

- WordPress.
- WooCommerce.
- SEO.
- Forms.
- Snippets.
- Multimedia.
- Changelog.
- Dynamic.
- READ/WRITE/SENSITIVE.

### Tutorials

Formato recomendado:

- Objetivo.
- Requisitos.
- Pasos.
- Resultado esperado.
- Troubleshooting.
- Siguiente tutorial recomendado.

## Mensajes clave por feature

### MCP Server

> Expose WordPress as an MCP server with SSE, JSON-RPC 2.0, OAuth and tool discovery.

### Profiles

> Give every AI client exactly the tools it needs, and nothing more.

### AI Chat Agent

> Test and use your MCP tools directly inside WordPress before connecting external agents.

### AI Copilot

> A contextual assistant inside the WordPress admin and editor.

### Automation Tasks

> Schedule AI workflows that use your site's tools, with budgets and logs.

### Event Automations

> Trigger AI workflows from WordPress, WooCommerce and form events.

### Multimedia

> Generate images and videos with AI and save them directly to the Media Library.

### Logs & Roll Back

> See every AI-driven change, compare before/after state and roll it back.

### Custom Tools

> Turn any webhook, API or WordPress action hook into an MCP tool.

### Plugin Integrations

> Extend AI capabilities through ACF, SEO plugins, forms, snippets and WordPress Abilities.

## SEO y contenido sugerido

Keywords principales:

- WordPress MCP server.
- WooCommerce MCP tools.
- ChatGPT WordPress integration.
- Claude Desktop WordPress MCP.
- AI agent WordPress plugin.
- WordPress AI automation.
- WooCommerce AI automation.
- WordPress rollback AI changes.
- MCP plugin for WordPress.

Títulos sugeridos:

- “StifLi Flex MCP: WordPress MCP Server for AI Agents”.
- “Connect ChatGPT and Claude to WordPress with MCP”.
- “Manage WooCommerce with AI Tools and Rollback”.
- “Build WordPress AI Automations with MCP Tools”.

FAQs recomendadas:

- ¿Qué es MCP?
- ¿Funciona con ChatGPT?
- ¿Funciona con Claude Desktop?
- ¿Necesito WooCommerce?
- ¿La IA puede modificar mi sitio?
- ¿Cómo limito permisos?
- ¿Puedo revertir cambios?
- ¿Puedo crear tools propias?
- ¿Funciona con ACF/Yoast/Rank Math/forms?
- ¿Qué pasa si WooCommerce no está activo?
- ¿Qué datos se envían al proveedor de IA?
- ¿Necesito HTTPS?

## Recomendaciones de UX para la web

- La primera pantalla debe mostrar producto real, no una promesa abstracta.
- Usar capturas del admin como señal principal.
- Evitar una estética demasiado “AI hype”; priorizar confianza y control.
- Mostrar flujos reales con pasos cortos.
- Separar “usar IA dentro de WordPress” de “conectar clientes MCP externos”.
- Hacer visibles los mecanismos de seguridad: perfiles, confirmación, changelog y rollback.
- Para el catálogo de tools, usar tablas filtrables; no hacer listas gigantes sin filtros.
- Para tutoriales, incluir resultados esperados y troubleshooting.
- Para WooCommerce, remarcar que las tools se configuran aunque WooCommerce no esté activo, pero solo ejecutan si WooCommerce está instalado.
- Para multimedia, incluir nota de costes y tiempos de generación.

## Orden recomendado de entrega del contenido

1. Home y Getting Started.
2. Menús y configuración.
3. Tools Catalog filtrable.
4. Profiles and Security.
5. Automations.
6. Integrations and Custom Tools.
7. Developer Docs.

## Archivos fuente de documentación generados

- `.pages/menus-y-opciones.md`: mapa completo del admin y opciones.
- `.pages/catalogo-tools.md`: catálogo de tools, definición, intención y casos de uso.
- `.pages/brief-diseno-web.md`: este brief de diseño/contenido para la web.
