/**
 * The world map — client side.
 *
 * The wheel zooms about the pointer, dragging moves the view, and on the
 * location form a click drops the pin. All of it works by rewriting the SVG's
 * viewBox, which is why the projection had to be plain: a coordinate is still
 * just longitude and latitude however far in you are.
 *
 * The map is server-rendered and complete without this file. Everything here
 * is an addition to a picture that already says the right thing.
 */
(function () {
    'use strict';

    // Past this the coastlines are further off than the pin is wide, and
    // zooming in only makes the drawing's own coarseness legible.
    var MAX_ZOOM = 16;

    // Pixels of movement below which a gesture was a click, not a drag.
    var DRAG_SLOP = 3;

    Array.prototype.forEach.call(document.querySelectorAll('.wmap'), function (root) {
        var svg = root.querySelector('.wmap__svg');
        if (!svg) return;

        var width = parseFloat(root.getAttribute('data-wmap-width'));
        var height = parseFloat(root.getAttribute('data-wmap-height'));
        var topLat = parseFloat(root.getAttribute('data-wmap-top'));
        if (!isFinite(width) || !isFinite(height) || !isFinite(topLat)) return;

        var isPicker = root.hasAttribute('data-wmap-picker');
        var latInput = isPicker ? document.querySelector('[data-wmap-lat]') : null;
        var lngInput = isPicker ? document.querySelector('[data-wmap-lng]') : null;
        var pins = root.querySelectorAll('.wmap__pin');

        var view = { x: 0, y: 0, w: width, h: height };
        var drag = null;
        var suppressClick = false;

        function clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        /**
         * Pins are drawn in map units, so left alone they would grow with the
         * zoom. Each one is scaled back down about its own anchor instead, and
         * keeps the size it has on screen.
         */
        function drawPins() {
            var k = view.w / width;

            Array.prototype.forEach.call(pins, function (pin) {
                var x = pin.getAttribute('data-x');
                var y = pin.getAttribute('data-y');
                if (x === null || y === null) return;
                pin.setAttribute('transform', 'translate(' + x + ' ' + y + ') scale(' + k + ')');
            });
        }

        function apply() {
            // The view never leaves the world. Whatever the zoom and however
            // far it has been dragged, scrolling back out lands on the whole
            // map again.
            view.x = clamp(view.x, 0, width - view.w);
            view.y = clamp(view.y, 0, height - view.h);
            svg.setAttribute('viewBox', view.x + ' ' + view.y + ' ' + view.w + ' ' + view.h);
            drawPins();
        }

        /** Where the drawing sits in the page, and how big a map unit is there. */
        function geometry() {
            var box = svg.getBoundingClientRect();
            if (!box.width || !box.height) return null;

            var scale = Math.min(box.width / view.w, box.height / view.h);

            return {
                scale: scale,
                left: box.left + (box.width - view.w * scale) / 2,
                top: box.top + (box.height - view.h * scale) / 2
            };
        }

        function pointerToMap(event) {
            var g = geometry();
            if (!g) return null;

            return {
                x: view.x + (event.clientX - g.left) / g.scale,
                y: view.y + (event.clientY - g.top) / g.scale
            };
        }

        /* ── Zoom ───────────────────────────────────────────────────────── */

        svg.addEventListener('wheel', function (event) {
            var delta = event.deltaY;
            if (event.deltaMode === 1) delta *= 16;
            else if (event.deltaMode === 2) delta *= 100;
            if (delta === 0) return;

            var zoom = width / view.w;
            var next = clamp(zoom * Math.pow(1.0016, -delta), 1, MAX_ZOOM);

            // Already as far in, or as far out, as it goes. Let the wheel
            // scroll the page rather than swallowing it over the map.
            if (next === zoom) return;

            event.preventDefault();

            var point = pointerToMap(event);
            if (!point) return;

            // Whatever was under the pointer stays under the pointer.
            var w = width / next;
            var h = height / next;
            view.x = point.x - (point.x - view.x) * (w / view.w);
            view.y = point.y - (point.y - view.y) * (h / view.h);
            view.w = w;
            view.h = h;
            apply();
        }, { passive: false });

        /* ── Pan, and the click the form is waiting for ─────────────────── */

        svg.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'mouse' && event.button !== 0) return;

            suppressClick = false;
            drag = {
                x: event.clientX,
                y: event.clientY,
                viewX: view.x,
                viewY: view.y,
                moved: false,
                // Touch keeps its usual job of scrolling the page. A tap still
                // lands on the branch below, because a tap does not move.
                pans: event.pointerType === 'mouse'
            };
        });

        svg.addEventListener('pointermove', function (event) {
            if (drag === null) return;

            // The button was let go somewhere we never heard about. Nothing is
            // being dragged, whatever the last press left behind.
            if (drag.pans && event.buttons === 0) {
                drag = null;
                return;
            }

            if (!drag.moved) {
                if (Math.abs(event.clientX - drag.x) <= DRAG_SLOP &&
                    Math.abs(event.clientY - drag.y) <= DRAG_SLOP) {
                    return;
                }

                drag.moved = true;

                // The pointer is taken here rather than on the press, now that
                // this is certainly a drag. Capturing it up front would
                // retarget the click that follows to the map itself, and a pin
                // would quietly stop being a link.
                if (drag.pans) {
                    if (svg.setPointerCapture) svg.setPointerCapture(event.pointerId);
                    root.setAttribute('data-wmap-panning', 'true');

                    // Panning starts from here, so the slop is spent rather
                    // than arriving as a jump.
                    drag.x = event.clientX;
                    drag.y = event.clientY;
                }
            }

            if (!drag.pans) return;

            var g = geometry();
            if (g === null) return;

            view.x = drag.viewX - (event.clientX - drag.x) / g.scale;
            view.y = drag.viewY - (event.clientY - drag.y) / g.scale;
            apply();
        });

        function endDrag(event) {
            if (drag === null) return;

            var moved = drag.moved;

            root.removeAttribute('data-wmap-panning');
            if (svg.hasPointerCapture && svg.hasPointerCapture(event.pointerId)) {
                svg.releasePointerCapture(event.pointerId);
            }

            drag = null;

            // A gesture that moved was a pan. It must not also drop a pin, and
            // it must not follow the link it happened to start on.
            suppressClick = moved;
            if (!moved && isPicker) {
                placeFrom(event);
            }
        }

        svg.addEventListener('pointerup', endDrag);
        svg.addEventListener('pointercancel', endDrag);

        // A pin is a link, and a link is something browsers offer to drag away.
        // Here the gesture means "move the map".
        svg.addEventListener('dragstart', function (event) {
            event.preventDefault();
        });

        svg.addEventListener('click', function (event) {
            if (!suppressClick) return;
            suppressClick = false;
            event.preventDefault();
            event.stopPropagation();
        }, true);

        /* ── Placing the pin on the location form ───────────────────────── */

        function marker() {
            return root.querySelector('[data-wmap-marker]');
        }

        function placeMarker(lat, lng) {
            var pin = marker();
            if (pin === null) return;

            pin.setAttribute('data-x', lng + 180);
            pin.setAttribute('data-y', topLat - lat);
            drawPins();
        }

        function placeFrom(event) {
            if (latInput === null || lngInput === null) return;

            var point = pointerToMap(event);
            if (point === null) return;

            var lng = Math.round(clamp(point.x - 180, -180, 180) * 10000) / 10000;
            var lat = Math.round(clamp(topLat - point.y, topLat - height, topLat) * 10000) / 10000;

            latInput.value = lat;
            lngInput.value = lng;
            placeMarker(lat, lng);
        }

        if (isPicker && latInput !== null && lngInput !== null) {
            // The two fields stay the record. The click writes into them, and
            // the pin follows whatever they end up saying.
            var followInputs = function () {
                var lat = parseFloat(latInput.value);
                var lng = parseFloat(lngInput.value);
                if (isFinite(lat) && isFinite(lng)) {
                    placeMarker(clamp(lat, topLat - height, topLat), clamp(lng, -180, 180));
                }
            };

            latInput.addEventListener('input', followInputs);
            lngInput.addEventListener('input', followInputs);
        }

        apply();
    });
})();
