/**
 * Docs TOC Scroll Tracking
 *
 * Progressive enhancement: highlights current section as user scrolls.
 * Works without JS (links still function), enhanced with JS.
 *
 * @since 0.3.0
 */
(function () {
	'use strict';

	const toc = document.querySelector('.docs-toc');
	if (!toc) {
		return;
	}

	const links = Array.prototype.slice.call(toc.querySelectorAll('.docs-toc-link'));
	if (!links.length) {
		return;
	}

	// Build array of sections with their elements.
	const sections = links
		.map(function (link) {
			const id = link.getAttribute('href').slice(1);
			const header = document.getElementById(id);
			return { link, header };
		})
		.filter(function (s) {
			return s.header;
		});

	if (!sections.length) {
		return;
	}

	// Offset for fixed header.
	const headerOffset = 80;

	function updateActiveLink() {
		const scrollPos = window.scrollY + headerOffset;

		// Find current section (last one that's above scroll position).
		let current = sections[0];
		for (let i = 0; i < sections.length; i++) {
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
	let ticking = false;
	function onScroll() {
		if (ticking) {
			return;
		}
		ticking = true;
		window.requestAnimationFrame(function () {
			updateActiveLink();
			ticking = false;
		});
	}

	window.addEventListener('scroll', onScroll, { passive: true });

	// Smooth scroll on click.
	links.forEach(function (link) {
		link.addEventListener('click', function (e) {
			const id = link.getAttribute('href').slice(1);
			const target = document.getElementById(id);
			if (target) {
				e.preventDefault();
				const top = target.offsetTop - headerOffset + 10;
				window.scrollTo({ top, behavior: 'smooth' });
				window.history.pushState(null, '', '#' + id);
			}
		});
	});

	// Initial state.
	updateActiveLink();
})();
