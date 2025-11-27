(function(){
	var ajaxUrl = sflmcpSettings.ajaxUrl;
	var nonce = sflmcpSettings.nonce;
	var initialToken = sflmcpSettings.token;
	
	function setFields(token) {
		var endpoint = document.getElementById('sflmcp_endpoint') ? document.getElementById('sflmcp_endpoint').textContent : '';
		var tokenField = document.getElementById('sflmcp_token_field');
		var urlField = document.getElementById('sflmcp_url_with_token');
		var headerField = document.getElementById('sflmcp_auth_header');
		
		if (tokenField) tokenField.value = token || '';
		if (urlField) urlField.value = endpoint + (endpoint.indexOf('?')===-1 ? '?token=' : '&token=') + (token || '');
		if (headerField) headerField.value = 'Authorization: Bearer ' + (token || '');
	}
	
	// init with existing token
	setFields(initialToken);
	
	var genBtn = document.getElementById('sflmcp_generate');
	var spinner = document.getElementById('sflmcp_spinner');
	if (genBtn) {
		genBtn.addEventListener('click', function(e){
			e.preventDefault();
			if (spinner) spinner.style.display = '';
			fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type':'application/x-www-form-urlencoded'},
				body: 'action=sflmcp_generate_token&_wpnonce=' + encodeURIComponent(nonce)
			}).then(function(r){ return r.json(); }).then(function(j){
				if (spinner) spinner.style.display = 'none';
				if (j.success && j.data && j.data.token) {
					setFields(j.data.token);
					alert(sflmcpSettings.i18n.tokenGenerated || 'Token generated. Save changes to persist it.');
				} else {
					alert((sflmcpSettings.i18n.errorGenerating || 'Error generating token') + ': ' + (j.data && j.data.message ? j.data.message : ''));
				}
			}).catch(function(err){
				if (spinner) spinner.style.display = 'none';
				alert('Error: ' + err);
			});
		});
	}
	
	var copyUrlBtn = document.getElementById('sflmcp_copy_url');
	if (copyUrlBtn) {
		copyUrlBtn.addEventListener('click', function(e){
			e.preventDefault();
			var urlField = document.getElementById('sflmcp_url_with_token');
			if (urlField && navigator.clipboard) {
				navigator.clipboard.writeText(urlField.value).then(function(){
					alert(sflmcpSettings.i18n.urlCopied || 'URL copied');
				});
			}
		});
	}
	
	var copyHeaderBtn = document.getElementById('sflmcp_copy_header');
	if (copyHeaderBtn) {
		copyHeaderBtn.addEventListener('click', function(e){
			e.preventDefault();
			var headerField = document.getElementById('sflmcp_auth_header');
			if (headerField && navigator.clipboard) {
				navigator.clipboard.writeText(headerField.value).then(function(){
					alert(sflmcpSettings.i18n.headerCopied || 'Header copied');
				});
			}
		});
	}
})();
