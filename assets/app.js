'use strict';

(() => {
    

    function setupMenu() {
        const button = document.querySelector('.menu-toggle');
        const navigation = document.querySelector('#navigation');

        if (!button || !navigation) return;

        button.setAttribute('aria-controls', 'navigation');
        button.setAttribute(
            'aria-expanded',
            String(navigation.classList.contains('open'))
        );

        button.addEventListener('click', () => {
            const open = navigation.classList.toggle('open');
            button.setAttribute('aria-expanded', String(open));
        });

        document.addEventListener('keydown', event => {
            if (
                event.key === 'Escape' &&
                navigation.classList.contains('open')
            ) {
                navigation.classList.remove('open');
                button.setAttribute('aria-expanded', 'false');
                button.focus();
            }
        });
    }

    function setupConfirmations() {
        document.querySelectorAll('form[data-confirm]').forEach(form => {
            form.addEventListener('submit', event => {
                const message =
                    form.dataset.confirm || 'Continue with this action?';

                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    function setupPhotos() {
        document.querySelectorAll('.place-photo').forEach(image => {
            function showFallback() {
                image.hidden = true;
                image.style.display = 'none';

                const fallback = image.nextElementSibling;

                if (fallback?.classList.contains('photo-fallback')) {
                    fallback.hidden = false;
                }
            }

            image.addEventListener('error', showFallback);

             
            if (image.complete && image.naturalWidth === 0) {
                showFallback();
            }
        });
    }

    function setupSelectionCount() {
        const counter = document.querySelector('#selected-count');

        if (!counter) return;

        const selector = 'input[name="places[]"]';

        counter.setAttribute('aria-live', 'polite');

        function updateCount() {
            const selected = document.querySelectorAll(
                selector + ':checked'
            ).length;

            counter.textContent = selected + ' selected';
        }

        document.querySelectorAll(selector).forEach(input => {
            input.addEventListener('change', updateCount);
        });

        
        window.addEventListener('pageshow', updateCount);

         
        document.querySelectorAll('form').forEach(form => {
            if (form.querySelector(selector)) {
                form.addEventListener('reset', () => {
                    window.setTimeout(updateCount, 0);
                });
            }
        });

        updateCount();
    }

    function detail(value, fallback = 'Not recorded') {
        if (typeof value !== 'string' && typeof value !== 'number') {
            return fallback;
        }

        return String(value).trim() || fallback;
    }

    function setupOfflineDownload() {
        const button = document.querySelector('#save-offline');

        if (!button) return;

        button.addEventListener('click', event => {
            event.preventDefault();

            let downloadUrl;
            let link;

            try {
                const data = document.querySelector('#offline-place');

                if (!data) {
                    throw new Error('Missing offline place data.');
                }

                const place = JSON.parse(data.textContent);

                if (
                    !place ||
                    typeof place !== 'object' ||
                    Array.isArray(place)
                ) {
                    throw new Error('Invalid offline place data.');
                }

                const name = detail(place.name, 'Local place');

                 
                const verified = [true, 1, '1'].includes(place.verified);

                const opening = detail(place.open_time, '');
                const closing = detail(place.close_time, '');

                const hours = opening && closing
                    ? opening + ' – ' + closing
                    : 'Not verified';

                const text = [
                    name,
                    detail(place.description),
                    'Location: ' + detail(place.address),
                    'Coordinates: ' +
                        detail(place.latitude) + ', ' +
                        detail(place.longitude),
                    'Hours: ' + hours,
                    'Visit duration (minutes): ' +
                        detail(place.visit_minutes),
                    'Best visiting time: ' + detail(place.best_time),
                    'Entry: ' + detail(place.entry_info),
                    'Facilities: ' + detail(place.facilities),
                    'Tips: ' + detail(place.travel_tips),
                    'Dress: ' + detail(place.dress_code),
                    'Footwear: ' + detail(place.footwear),
                    'Photography: ' + detail(place.photography),
                    'Contact: ' + detail(place.contact),
                    'Verification: ' + (
                        verified
                            ? 'Verified by administrator'
                            : 'Needs local verification'
                    ),
                    'Saved: ' + new Date().toISOString(),
                    'This is an offline snapshot; confirm current conditions before travel.',
                    'Interactive maps and online directions require an internet connection.'
                ].join('\n\n');

                 
                const filename = Array.from(
                    name
                        .replace(/[<>:"/\\|?*\u0000-\u001f\u007f]/g, '-')
                        .replace(/\s+/g, ' ')
                )
                    .slice(0, 80)
                    .join('')
                    .replace(/^[. ]+|[. ]+$/g, '');

                 
                const blob = new Blob(['\uFEFF', text], {
                    type: 'text/plain;charset=utf-8'
                });

                downloadUrl = URL.createObjectURL(blob);

                link = document.createElement('a');
                link.href = downloadUrl;
                link.download =
                    'daytrail-' + (filename || 'place') + '.txt';

                document.body.append(link);
                link.click();
            } catch (error) {
                window.alert(
                    'Place details could not be downloaded. ' +
                    'Reload the page and try again.'
                );
            } finally {
                link?.remove();

                if (downloadUrl) {
                    window.setTimeout(() => {
                        URL.revokeObjectURL(downloadUrl);
                    }, 2000);
                }
            }
        });
    }

    function coordinate(value, limit) {
        if (
            !['string', 'number'].includes(typeof value) ||
            String(value).trim() === ''
        ) {
            throw new Error('Missing coordinate.');
        }

        const number = Number(value);

        if (!Number.isFinite(number) || Math.abs(number) > limit) {
            throw new Error('Invalid coordinate.');
        }

        return number;
    }

    function setupMap() {
        const data = document.querySelector('#map-data');
        const container = document.querySelector('#map');
        const status = document.querySelector('#map-status');

        if (!data) return;

        function showStatus(message) {
            if (status) {
                status.textContent = message;
            }
        }

        let map;
        let loadingTimer;

        try {
            if (!container || typeof window.L === 'undefined') {
                throw new Error('Map library or container unavailable.');
            }

            const config = JSON.parse(data.textContent);

            if (
                !config ||
                typeof config !== 'object' ||
                !Array.isArray(config.home) ||
                config.home.length !== 2 ||
                !Array.isArray(config.places)
            ) {
                throw new Error('Invalid map data.');
            }

            const home = [
                coordinate(config.home[0], 90),
                coordinate(config.home[1], 180)
            ];

             
            const places = config.places.map(place => {
                if (!place || typeof place !== 'object') {
                    throw new Error('Invalid place data.');
                }

                const rawId = place.id;

                if (
                    !['string', 'number'].includes(typeof rawId) ||
                    String(rawId).trim() === ''
                ) {
                    throw new Error('Missing place ID.');
                }

                const id = Number(rawId);

                if (!Number.isSafeInteger(id) || id < 1) {
                    throw new Error('Invalid place ID.');
                }

                return {
                    id,
                    name: detail(place.name, 'Local place'),
                    point: [
                        coordinate(place.lat, 90),
                        coordinate(place.lng, 180)
                    ]
                };
            });

            const leaflet = window.L;
            const isRoute = config.route === true;

            map = leaflet.map(container).setView(home, 12);

            const tiles = leaflet.tileLayer(
                'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                {
                    maxZoom: 19,
                    attribution:
                        '&copy; ' +
                        '<a href="https://www.openstreetmap.org/copyright">' +
                        'OpenStreetMap contributors</a>'
                }
            );

            let tileFailed = false;
            let tilesCompleted = false;

            function readyMessage() {
                if (isRoute) {
                    return 'Visit-order sketch only — dashed lines are not roads.';
                }

                return places.length
                    ? 'Select a marker to view a place. Pins may require local verification.'
                    : 'Only the configured home reference is shown.';
            }

             
            tiles.on('tileerror', () => {
                tileFailed = true;

                showStatus(
                    'Some map tiles could not load. ' +
                    'Check your internet connection; ' +
                    'place details and directions remain available.'
                );
            });

            tiles.on('load', () => {
                tilesCompleted = true;
                window.clearTimeout(loadingTimer);

                if (!tileFailed) {
                    showStatus(readyMessage());
                }
            });

            leaflet.circleMarker(home, {
                radius: 7,
                color: '#833ab4',
                fillColor: '#833ab4',
                fillOpacity: 1
            })
                .addTo(map)
                .bindPopup('Configured home reference');

            const bounds = [home];

            places.forEach(place => {
                bounds.push(place.point);

                const popup = document.createElement('div');
                const link = document.createElement('a');

                link.href = '?page=place&id=' + place.id;

                
                link.textContent = place.name;

                popup.append(link);

                leaflet.marker(place.point, {
                    title: place.name,
                    alt: place.name
                })
                    .addTo(map)
                    .bindPopup(popup);
            });

            if (bounds.length > 1) {
                map.fitBounds(bounds, {
                    padding: [30, 30],
                    maxZoom: 14
                });

                if (isRoute) {
                     
                    leaflet.polyline(bounds, {
                        color: '#b51d78',
                        weight: 3,
                        dashArray: '7 9'
                    }).addTo(map);
                }
            }

            loadingTimer = window.setTimeout(() => {
                if (!tileFailed && !tilesCompleted) {
                    showStatus(
                        'Map tiles are taking longer than expected. ' +
                        'Place information and directions remain available.'
                    );
                }
            }, 8000);

            tiles.addTo(map);
        } catch (error) {
            window.clearTimeout(loadingTimer);

            if (map) {
                map.remove();
            }

            showStatus(
                'The map is unavailable. You can still browse place ' +
                'information and use available Google Maps directions links.'
            );
        }
    }

    function initialise() {
        setupMenu();
        setupConfirmations();
        setupPhotos();
        setupSelectionCount();

        const printButton = document.querySelector('#print-plan');

        printButton?.addEventListener('click', event => {
            event.preventDefault();
            window.print();
        });

        setupOfflineDownload();
        setupMap();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise, {
            once: true
        });
    } else {
        initialise();
    }
})();