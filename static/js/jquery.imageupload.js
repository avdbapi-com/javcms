/*!
 * jQuery imageUpload: upload controls for an <img>. The picked file is posted through a hidden
 * iframe form to options.formAction; when the server answers {code: 1, file: "<new url>"} the
 * image is swapped, {code: 0, msg: "..."} reports a failure.
 *
 * Readable rewrite (JavCMS, 2026) of the plugin that used to ship packed with eval(); same API,
 * options and events. The server answer is parsed with JSON.parse instead of eval().
 *   $('img.avatar').imageUpload({formAction: '/upload/url', automaticUpload: true});
 *   $.imageUpload.defaults                  -> the default options
 * Events on the image: imageUpload.reload, imageUpload.destroy, imageUpload.imageRemoved,
 * imageUpload.uploadFailed (message), imageUpload.imageChanged.
 */
(function ($) {
    'use strict';
    if (!$) {
        return;
    }

    var defaults = {
        wrapContent: "<div class='jQuery-imageUpload'>",
        inputFileName: 'inputFile',
        inputFileClass: 'inputFile',
        uploadButtonValue: 'Upload',
        uploadButtonClass: 'uploadButton',
        browseButtonValue: 'Browse',
        browseButtonClass: 'browseButton',
        deleteButtonValue: 'Delete image',
        deleteButtonClass: 'deleteButton',
        automaticUpload: false,
        formClass: 'controlForm',
        hideFileInput: true,
        hideDeleteButton: false,
        hover: true,
        addClass: 'jQuery-image-upload'
    };

    function parseAnswer(text) {
        try {
            var answer = JSON.parse(text);
            return answer && typeof answer === 'object' ? answer : null;
        } catch (error) {
            return null;
        }
    }

    function whenLoaded(image, callback) {
        $(image).each(function () {
            if (this.complete) {
                callback.apply(this);
            } else {
                $(this).on('load', function () {
                    callback.apply(this);
                });
            }
        });
    }

    function showImage(image, src, callback) {
        image.fadeOut(function () {
            image.attr('src', src);
            whenLoaded(image, function () {
                image.removeClass('loading');
                image.fadeIn();
                if (typeof callback === 'function') {
                    callback();
                }
            });
        });
    }

    $.fn.imageUpload = function (options) {
        var image = this;
        if (!image.length) {
            return image;
        }
        var settings = $.extend({}, defaults, options);
        if (image.length > 1) {
            image.each(function () {
                $(this).imageUpload(settings);
            });
            return image;
        }
        if (image.data('imageUpload')) {
            image.trigger('imageUpload.reload');
            return image;
        }
        image.addClass(settings.addClass);
        image.data('imageUpload', options);
        if (!settings.formAction) {
            throw new Error("Form action was not provided. Please provide it: $(...).imageUpload({formAction: '...'})");
        }
        if (!settings.hover) {
            image.wrap(settings.wrapContent);
        }

        var controls = $('<div>').addClass('controls');
        var fileInput = $('<input>').attr({type: 'file', name: settings.inputFileName}).addClass(settings.inputFileClass);
        var uploadButton = $('<button>').attr('type', 'submit').addClass(settings.uploadButtonClass).html(settings.uploadButtonValue);
        var browseButton = $('<button>').addClass(settings.browseButtonClass).html(settings.browseButtonValue).on('click', function () {
            fileInput.click();
            return false;
        });
        var deleteButton = $('<button>').addClass(settings.deleteButtonClass).html(settings.deleteButtonValue).on('click', function () {
            image.trigger('imageUpload.destroy');
            image.trigger('imageUpload.imageRemoved');
            image.remove();
            return false;
        });
        var frameName = 'uploadIframe-' + Math.random().toString(36).substring(5, 20).toLowerCase();
        var frame = $('<iframe>').attr({id: frameName, name: frameName}).hide();
        var form = $('<form>').addClass(settings.formClass).attr({
            target: frameName,
            enctype: 'multipart/form-data',
            method: 'post',
            action: settings.formAction
        });
        form.append([browseButton, fileInput, uploadButton, deleteButton, frame]);
        if (settings.hideDeleteButton) {
            deleteButton.remove();
        }
        if (settings.automaticUpload) {
            uploadButton.hide();
            fileInput.on('change', function () {
                if (!$(this).val()) {
                    return;
                }
                uploadButton.click();
            });
        }
        if (settings.hideFileInput) {
            fileInput.hide();
        } else {
            browseButton.hide();
        }
        controls.append(form);

        form.on('submit', function () {
            frame.off('load');
            var previous = image.attr('src');
            if (typeof settings.waiter === 'string') {
                image.attr('src', settings.waiter);
            }
            image.addClass('loading');
            controls.hide();
            frame.on('load', function () {
                var answerDocument = this.contentWindow && this.contentWindow.document;
                if (!answerDocument || String(answerDocument.URL || '') === 'about:blank') {
                    return; // the frame's initial blank document, not the server answer
                }
                var answer = parseAnswer($(answerDocument).text());
                if (!answer) {
                    showImage(image, previous);
                    image.trigger('imageUpload.uploadFailed', ['The upload response has an invalid format.']);
                    return;
                }
                // Loose comparison on purpose: servers answer 0 or "0".
                if (answer.code == 0) {
                    showImage(image, previous);
                    image.trigger('imageUpload.uploadFailed', [answer.msg]);
                    return;
                }
                if (settings.hideFileInput) {
                    image.trigger('imageUpload.reload');
                }
                if (!fileInput.val()) {
                    showImage(image, previous);
                    return;
                }
                frame.attr('src', '');
                showImage(image, answer.file, function () {
                    image.trigger('imageUpload.imageChanged');
                });
                fileInput.replaceWith(fileInput.clone(true));
            });
        });

        if (!settings.hover) {
            image.parent().append(controls);
        } else {
            controls.css({position: 'absolute'});
            controls.addClass('jQuery-image-upload-controls');
            $('body').append(controls.hide());
            image.on('mouseenter', function () {
                if (image.hasClass('loading')) {
                    return;
                }
                var offset = image.offset();
                controls.css({top: offset.top, left: offset.left});
                controls.show();
            });
            $('body').on('mouseleave', '.' + settings.addClass, function (event) {
                var offset = image.offset();
                var width = image.width();
                var height = image.height();
                if (event.pageX < offset.left || event.pageX > offset.left + width
                    || event.pageY < offset.top || event.pageY > offset.top + height) {
                    controls.hide();
                }
            });
        }

        image.on('imageUpload.destroy', function () {
            controls.remove();
            image.off('imageUpload.destroy');
            image.off('imageUpload.reload');
            image.data('imageUpload', null);
        });
        image.on('imageUpload.reload', function () {
            image.trigger('imageUpload.destroy');
            image.imageUpload(options);
        });
        return image;
    };

    $.imageUpload = $.fn.imageUpload;
    $.imageUpload.defaults = defaults;
})(window.jQuery || window.$);
