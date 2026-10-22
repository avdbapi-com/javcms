UE.parse.register('background', function (utils) {
    var me = this,
        root = me.root,
        p = root.getElementsByTagName('p'),
        styles;

    for (var i = 0,ci; ci = p[i++];) {
        styles = ci.getAttribute('data-background');
        if (styles){
            ci.parentNode.removeChild(ci);
        }
    }

    // Apply the background styles.
    styles && utils.cssRule('ueditor_background', me.selector + '{' + styles + '}', document);
});