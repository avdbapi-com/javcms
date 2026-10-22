/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
(function (window, document) {
    "use strict";

    function setDimensions(image, dimension) {
        if (!image.getAttribute("width") && !image.style.width) {
            image.setAttribute("width", String(dimension));
        }
        if (!image.getAttribute("height") && !image.style.height) {
            image.setAttribute("height", String(dimension));
        }
    }

    function renderControllerFallback(image, text, dimension) {
        var root = window.maccms && window.maccms.path ? String(window.maccms.path) : "";
        var separator = root && root.charAt(root.length - 1) === "/" ? "" : "/";
        image.setAttribute("src", root + separator + "index.php/qrcode/index.html?url=" + encodeURIComponent(String(text)));
        setDimensions(image, dimension);
        image.setAttribute("data-qrcode-source", "local-controller");
        return true;
    }

    function renderImage(image, text, size) {
        if (!image || !text) {
            return false;
        }

        var dimension = parseInt(size, 10);
        if (!dimension || dimension < 64 || dimension > 512) {
            dimension = 180;
        }

        if (!window.QRCode) {
            return renderControllerFallback(image, text, dimension);
        }

        var holder = document.createElement("span");
        holder.setAttribute("aria-hidden", "true");
        holder.style.position = "absolute";
        holder.style.width = "1px";
        holder.style.height = "1px";
        holder.style.overflow = "hidden";
        holder.style.clip = "rect(0 0 0 0)";
        holder.style.clipPath = "inset(50%)";
        document.body.appendChild(holder);

        try {
            new window.QRCode(holder, {
                text: String(text),
                width: dimension,
                height: dimension,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: window.QRCode.CorrectLevel.L
            });

            var canvas = holder.querySelector("canvas");
            var generated = canvas ? canvas.toDataURL("image/png") : null;
            if (!generated) {
                var generatedImage = holder.querySelector("img");
                generated = generatedImage && generatedImage.src ? generatedImage.src : null;
            }
            if (!generated) {
                return renderControllerFallback(image, text, dimension);
            }

            image.setAttribute("src", generated);
            setDimensions(image, dimension);
            image.setAttribute("data-qrcode-source", "local");
            return true;
        } catch (error) {
            return renderControllerFallback(image, text, dimension);
        } finally {
            if (holder.parentNode) {
                holder.parentNode.removeChild(holder);
            }
        }
    }

    function render(selector, text, size) {
        var images = document.querySelectorAll(selector || "img.qrcode");
        var rendered = 0;
        for (var index = 0; index < images.length; index += 1) {
            if (renderImage(images[index], text, size)) {
                rendered += 1;
            }
        }
        return rendered;
    }

    window.MacLocalQrCode = {
        render: render,
        renderImage: renderImage,
        version: "qrcodejs-2026-09-30-04f46c6"
    };
}(window, document));
