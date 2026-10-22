/*******************************************************************************
* KindEditor - WYSIWYG HTML Editor for Internet
* Copyright (C) 2006-2011 kindsoft.net
*
* @author Roddy <luolonghao@gmail.com>
* @site http://www.kindsoft.net/
* @licence http://www.kindsoft.net/license.php
*******************************************************************************/

KindEditor.plugin('autoheight', function(K) {
	var self = this;

	if (!self.autoHeightMode) {
		return;
	}

	var minHeight;

	function hideScroll() {
		var edit = self.edit;
		var body = edit.doc.body;
		edit.iframe[0].scroll = 'no';
		body.style.overflowY = 'hidden';
	}

	function resetHeight() {
		if(self.fullscreenMode){
			return;
		}
		var edit = self.edit;
		var body = edit.doc.body;
		edit.iframe.height(minHeight);
		self.resize(null, Math.max((K.IE ? body.scrollHeight : body.offsetHeight) + 76, minHeight));
	}

	function init() {
		minHeight = K.removeUnit(self.height);

		self.edit.afterChange(resetHeight);
		if(!self.fullscreenMode){
			hideScroll();
		}
		resetHeight();
	}

	if (self.isCreated) {
		init();
	} else {
		self.afterCreate(init);
	}
});

/* * Allow automatic height adjustment to grow and shrink.
* After resizing the editor, the iframe height becomes the minimum body height, preventing the editor from shrinking.
* Reset the iframe to its minimum height before measuring the body to obtain the actual content height.
* This allows the editor height to adjust in both directions.
* Tests: Chrome, Firefox, IE9, IE8
* */
