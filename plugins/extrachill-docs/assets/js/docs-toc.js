/**
 * Docs TOC Scroll Tracking
 *
 * Progressive enhancement: highlights current section as user scrolls.
 * Works without JS (links still function), enhanced with JS.
 *
 * @package ExtraChillDocs
 * @since 0.3.0
 */
(function () {
	'use strict';

	var toc = document.querySelector('.docs-toc');
	if (!toc) return;

	var links = Array.prototype.slice.call(toc.querySelectorAll('.docs-toc-link'));
	if (!links.length) return;

	// Build array of sections with their elements.
	var sections = links
		.map(function (link) {
			var id = link.getAttribute('href').slice(1);
			var header = document.getElementById(id);
			return { link: link, header: header };
		})
		.filter(function (s) {
			return s.header;
		});

	if (!sections.length) return;

	// Offset for fixed header.
	var headerOffset = 80;

	function updateActiveLink() {
		var scrollPos = window.scrollY + headerOffset;

		// Find current section (last one that's above scroll position).
		var current = sections[0];
		for (var i = 0; i < sections.length; i++) {
			if (sections[i].header.offsetTop <= scrollPos) {
				current = sections[i];
			} else {
				break;
			}
		}

		// Update active states.
		links.forEach(function (link) {
			link.classList.remove('active');
		});
		if (current) {
			current.link.classList.add('active');
		}
	}

	// Throttled scroll handler.
	var ticking = false;
	function onScroll() {
		if (ticking) return;
		ticking = true;
		requestAnimationFrame(function () {
			updateActiveLink();
			ticking = false;
		});
	}

	window.addEventListener('scroll', onScroll, { passive: true });

	// Smooth scroll on click.
	links.forEach(function (link) {
		link.addEventListener('click', function (e) {
			var id = link.getAttribute('href').slice(1);
			var target = document.getElementById(id);
			if (target) {
				e.preventDefault();
				var top = target.offsetTop - headerOffset + 10;
				window.scrollTo({ top: top, behavior: 'smooth' });
				history.pushState(null, '', '#' + id);
			}
		});
	});

	// Initial state.
	updateActiveLink();
})();
