/**
 * Location Venue Map
 *
 * Renders a multi-marker Leaflet map on location archive pages showing
 * all venues in the city. Centers on the location geo tag and auto-fits
 * bounds to show all markers.
 *
 * @package ExtraChillEvents
 * @since 0.6.0
 */

(function() {
    'use strict';

    function initLocationMaps() {
        const containers = document.querySelectorAll('.location-venue-map');

        if (containers.length === 0 || typeof L === 'undefined') {
            return;
        }

        containers.forEach(function(container) {
            initMap(container);
        });
    }

    function initMap(container) {
        if (container.classList.contains('map-initialized')) {
            return;
        }

        var centerLat = parseFloat(container.getAttribute('data-center-lat'));
        var centerLon = parseFloat(container.getAttribute('data-center-lon'));
        var mapType = container.getAttribute('data-map-type') || 'osm-standard';
        var venuesJson = container.getAttribute('data-venues') || '[]';

        if (isNaN(centerLat) || isNaN(centerLon)) {
            return;
        }

        var venues = [];
        try {
            venues = JSON.parse(venuesJson);
        } catch (e) {
            venues = [];
        }

        try {
            var map = L.map(container.id, {
                scrollWheelZoom: false,
            }).setView([centerLat, centerLon], 12);

            var tileConfigs = {
                'osm-standard': {
                    url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                },
                'carto-positron': {
                    url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                },
                'carto-voyager': {
                    url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
                },
                'carto-dark': {
                    url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                },
                'humanitarian': {
                    url: 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
                },
            };

            var tileConfig = tileConfigs[mapType] || tileConfigs['osm-standard'];

            L.tileLayer(tileConfig.url, {
                attribution: '',
                maxZoom: 18,
                minZoom: 8,
            }).addTo(map);

            // Add venue markers.
            var markers = [];
            var emojiIcon = L.divIcon({
                html: '<span style="font-size: 28px; line-height: 1; display: block;">📍</span>',
                className: 'emoji-marker',
                iconSize: [28, 28],
                iconAnchor: [14, 28],
                popupAnchor: [0, -28],
            });

            venues.forEach(function(venue) {
                if (!venue.lat || !venue.lon) {
                    return;
                }

                var marker = L.marker([venue.lat, venue.lon], { icon: emojiIcon }).addTo(map);

                var popup = '<div class="venue-popup">';
                if (venue.url) {
                    popup += '<strong><a href="' + escapeHtml(venue.url) + '">' + escapeHtml(venue.name) + '</a></strong>';
                } else {
                    popup += '<strong>' + escapeHtml(venue.name) + '</strong>';
                }
                if (venue.address) {
                    popup += '<br><small>' + escapeHtml(venue.address) + '</small>';
                }
                popup += '</div>';

                marker.bindPopup(popup);
                markers.push(marker);
            });

            // Fit bounds to show all markers if we have any.
            if (markers.length > 1) {
                var group = L.featureGroup(markers);
                map.fitBounds(group.getBounds().pad(0.1));
            } else if (markers.length === 1) {
                map.setView([venues[0].lat, venues[0].lon], 13);
            }

            container.classList.add('map-initialized');

            setTimeout(function() {
                map.invalidateSize();
            }, 100);

        } catch (error) {
            console.error('Error initializing location map:', error);
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLocationMaps);
    } else {
        initLocationMaps();
    }

})();
