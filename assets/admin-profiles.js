(function(){
	var nonce = sflmcpProfiles.nonce;
	
	// Helper for AJAX calls
	function SFLMCPAjax(action, data, successMsg) {
		data.action = action;
		data._wpnonce = nonce;
		
		return fetch(ajaxurl, {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded'},
			body: new URLSearchParams(data)
		}).then(r => r.json()).then(j => {
			if (j.success) {
				if (successMsg) alert(successMsg);
				location.reload();
			} else {
				alert('Error: ' + (j.data && j.data.message ? j.data.message : 'Unknown error'));
			}
		}).catch(err => alert('Error: ' + err));
	}
	
	// Create Profile
	var createBtn = document.getElementById('sflmcp_create_profile');
	if (createBtn) {
		createBtn.addEventListener('click', function(e){
			e.preventDefault();
			var name = prompt(sflmcpProfiles.i18n.enterProfileName || 'Enter profile name:');
			if (!name) return;
			var desc = prompt(sflmcpProfiles.i18n.enterProfileDesc || 'Enter profile description (optional):');
			SFLMCPAjax('sflmcp_create_profile', {profile_name: name, profile_description: desc || ''}, 'Profile created successfully');
		});
	}
	
	// Apply Profile
	var applyBtns = document.querySelectorAll('.SFLMCP-apply-profile');
	applyBtns.forEach(function(btn){
		btn.addEventListener('click', function(){
			if (!confirm('Apply profile "' + this.dataset.profileName + '"?\n\nThis will change the enabled tools.')) return;
			SFLMCPAjax('sflmcp_apply_profile', {profile_id: this.dataset.profileId}, 'Profile applied successfully');
		});
	});
	
	// Delete Profile
	var deleteBtns = document.querySelectorAll('.SFLMCP-delete-profile');
	deleteBtns.forEach(function(btn){
		btn.addEventListener('click', function(){
			if (!confirm('Delete profile "' + this.dataset.profileName + '"?\n\nThis action cannot be undone.')) return;
			SFLMCPAjax('sflmcp_delete_profile', {profile_id: this.dataset.profileId}, 'Profile deleted successfully');
		});
	});
	
	// Duplicate Profile
	var duplicateBtns = document.querySelectorAll('.SFLMCP-duplicate-profile');
	duplicateBtns.forEach(function(btn){
		btn.addEventListener('click', function(){
			SFLMCPAjax('sflmcp_duplicate_profile', {profile_id: this.dataset.profileId}, 'Profile duplicated successfully');
		});
	});
	
	// Export Profile
	var exportBtns = document.querySelectorAll('.SFLMCP-export-profile');
	exportBtns.forEach(function(btn){
		btn.addEventListener('click', function(){
			var profileId = this.dataset.profileId;
			window.location.href = ajaxurl + '?action=sflmcp_export_profile&profile_id=' + profileId + '&_wpnonce=' + nonce;
		});
	});
	
	// Import Profile
	var importBtn = document.getElementById('sflmcp_import_profile');
	if (importBtn) {
		importBtn.addEventListener('click', function(e){
			e.preventDefault();
			var fileInput = document.getElementById('sflmcp_import_file');
			if (fileInput) fileInput.click();
		});
	}
	
	var importFile = document.getElementById('sflmcp_import_file');
	if (importFile) {
		importFile.addEventListener('change', function(e){
			if (!this.files.length) return;
			var file = this.files[0];
			var reader = new FileReader();
			reader.onload = function(ev){
				try {
					var json = JSON.parse(ev.target.result);
					SFLMCPAjax('sflmcp_import_profile', {profile_json: JSON.stringify(json)}, 'Profile imported successfully');
				} catch(err) {
					alert('Error reading JSON file: ' + err.message);
				}
			};
			reader.readAsText(file);
			e.target.value = '';
		});
	}
	
	// Restore System Profiles
	var restoreBtn = document.getElementById('sflmcp_restore_system_profiles');
	if (restoreBtn) {
		restoreBtn.addEventListener('click', function(e){
			e.preventDefault();
			if (!confirm('Restore system profiles?\n\nThis will recreate the 8 predefined profiles.')) return;
			SFLMCPAjax('sflmcp_restore_system_profiles', {}, 'System profiles restored');
		});
	}
	
	// Edit profile (TODO: implement modal)
	var editBtns = document.querySelectorAll('.SFLMCP-edit-profile');
	editBtns.forEach(function(btn){
		btn.addEventListener('click', function(){
			alert('Profile creation/editing functionality with modal will be implemented in the next phase');
		});
	});
	
	// View tools tooltip
	var tooltip = null;
	var viewLinks = document.querySelectorAll('.SFLMCP-view-tools');
	viewLinks.forEach(function(link){
		link.addEventListener('mouseenter', function(e){
			e.preventDefault();
			// Remove existing tooltip
			if (tooltip) tooltip.remove();
			
			// Create tooltip
			tooltip = document.createElement('div');
			tooltip.style.cssText = 'position: absolute; background: #fff; border: 1px solid #ccc; padding: 10px; max-width: 400px; max-height: 300px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 9999; font-size: 12px; line-height: 1.5;';
			tooltip.innerHTML = '<strong>' + (sflmcpProfiles.i18n.includedTools || 'Included tools:') + '</strong><br>' + this.dataset.tools;
			document.body.appendChild(tooltip);
			
			// Position tooltip near mouse
			var rect = this.getBoundingClientRect();
			tooltip.style.left = (rect.left + window.scrollX) + 'px';
			tooltip.style.top = (rect.bottom + window.scrollY + 5) + 'px';
		});
		
		link.addEventListener('mouseleave', function(){
			setTimeout(function(){
				if (tooltip) {
					tooltip.remove();
					tooltip = null;
				}
			}, 200);
		});
		
		link.addEventListener('click', function(e){
			e.preventDefault();
		});
	});
})();
