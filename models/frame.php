<?php
// Frame mínimo para EasyVisualMcp (stub)
class EasyVisualMcpFrame {
	public static function _() {
		return new self();
	}
	public function getModule($name) {
		return $this;
	}
	public function get($a = null, $b = null) {
		return null;
	}
	public function getModel() {
		return $this;
	}
	public function saveDebugLogging($data, $a = false, $b = '') {
		// Implementar si es necesario
	}
}
