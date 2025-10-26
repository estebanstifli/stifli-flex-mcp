<?php
// Utilidades mínimas para EasyVisualMcp (stub)
class EasyVisualMcpUtils {
	public static function getUserAgent() {
		return $_SERVER['HTTP_USER_AGENT'] ?? '';
	}
	public static function getIP() {
		return $_SERVER['REMOTE_ADDR'] ?? '';
	}
	public static function setAdminUser() {
		// Implementar si es necesario
	}
	public static function getArrayValue($arr, $key, $default = null, $depth = 1) {
		if (!is_array($arr)) return $default;
		if (!array_key_exists($key, $arr)) return $default;
		return $arr[$key];
	}
}
