<?php
/*
Plugin Name: Easy Visual MCP
Description: Servidor MCP independiente basado en el módulo mcp de ai-copilot, adaptado y renombrado.
Version: 0.1.0
Author: Tu Nombre
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bootstrap: cargar helpers y clases necesarias antes del módulo principal
require_once __DIR__ . '/models/dispatcher.php';
require_once __DIR__ . '/models/utils.php';
require_once __DIR__ . '/models/frame.php';
require_once __DIR__ . '/models/req.php';
require_once __DIR__ . '/models/model.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/mod.php';

// Inicialización del plugin
add_action('plugins_loaded', function() {
	if (class_exists('EasyVisualMcp')) {
		$mod = new EasyVisualMcp();
		$mod->init();
	}
});
