<?php
// Req mínimo para EasyVisualMcp (stub)
class EasyVisualMcpReq {
	public static function getRequestUri() {
		return $_SERVER['REQUEST_URI'] ?? '';
	}
}
