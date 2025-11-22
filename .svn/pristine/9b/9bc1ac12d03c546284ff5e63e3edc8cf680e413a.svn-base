(function () {
	function shouldLoadTurnstile() {
		return document.querySelector('.cf-turnstile');
	}

	function injectScript() {
		if (window.__npmpTurnstileLoading || window.turnstile) {
			return;
		}

		if (!shouldLoadTurnstile()) {
			return;
		}

		var script = document.createElement('script');
		script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
		script.async = true;
		script.defer = true;
		document.head.appendChild(script);
		window.__npmpTurnstileLoading = true;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', injectScript);
	} else {
		injectScript();
	}
})();
