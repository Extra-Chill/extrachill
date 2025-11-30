(function() {
	var config = window.ecViewTracking;
	if (!config || !config.postId || !config.endpoint) {
		return;
	}

	var data = JSON.stringify({post_id: config.postId});

	if (navigator.sendBeacon) {
		navigator.sendBeacon(config.endpoint, new Blob([data], {type: 'application/json'}));
	} else {
		fetch(config.endpoint, {
			method: 'POST',
			body: data,
			headers: {'Content-Type': 'application/json'},
			keepalive: true
		});
	}
})();
