/* *
 * umEditor configuration options
 * Configure editor features here. */
/* *********************tips****************************
 * Commented configuration options show UEditor defaults.
 * Before changing a default, make sure you understand what the option controls.
 * Either uncomment and edit an option here, or pass it when creating the editor instance.
 * When upgrading, you can keep the existing configuration file. New features use defaults for options missing from the old file.
 **********************tips*********************** */


(function () {
    /* *
 * Editor resource root: resolve the resource directory, including dialog folders, relative to the page that creates the editor.
 * To avoid path problems, use a path relative to the website root.
 * A website-root-relative path begins with a slash, for example /myProject/UMEditor/.
 * A relative URL may not work for every editor when pages at different directory levels share the same UEditor resources.
 * uEditor supports a separate resource root for each page. Set the following value before creating the editor, and make it match the resource directory.
 * window.umeditor_home_url = “/xxxx/xxxx/”; */
    var URL = window.UMEDITOR_HOME_URL || (function(){

        function PathStack() {

            this.documentURL = self.document.URL || self.location.href;

            this.separator = '/';
            this.separatorPattern = /\\|\//g;
            this.currentDir = './';
            this.currentDirPattern = /^[.]\/]/;

            this.path = this.documentURL;
            this.stack = [];

            this.push( this.documentURL );

        }

        PathStack.isParentPath = function( path ){
            return path === '..';
        };

        PathStack.hasProtocol = function( path ){
            return !!PathStack.getProtocol( path );
        };

        PathStack.getProtocol = function( path ){

            var protocol = /^[^:]*:\/*/.exec( path );

            return protocol ? protocol[0] : null;

        };

        PathStack.prototype = {
            push: function( path ){

                this.path = path;

                update.call( this );
                parse.call( this );

                return this;

            },
            getPath: function(){
                return this + "";
            },
            toString: function(){
                return this.protocol + ( this.stack.concat( [''] ) ).join( this.separator );
            }
        };

        function update() {

            var protocol = PathStack.getProtocol( this.path || '' );

            if( protocol ) {

                // root protocol
                this.protocol = protocol;

                // local
                this.localSeparator = /\\|\//.exec( this.path.replace( protocol, '' ) )[0];

                this.stack = [];
            } else {
                protocol = /\\|\//.exec( this.path );
                protocol && (this.localSeparator = protocol[0]);
            }

        }

        function parse(){

            var parsedStack = this.path.replace( this.currentDirPattern, '' );

            if( PathStack.hasProtocol( this.path ) ) {
                parsedStack = parsedStack.replace( this.protocol , '');
            }

            parsedStack = parsedStack.split( this.localSeparator );
            parsedStack.length = parsedStack.length - 1;

            for(var i= 0,tempPath,l=parsedStack.length,root = this.stack;i<l;i++){
                tempPath = parsedStack[i];
                if(tempPath){
                    if( PathStack.isParentPath( tempPath ) ) {
                        root.pop();
                    } else {
                        root.push( tempPath );
                    }
                }

            }


        }

        var currentPath = document.getElementsByTagName('script');

        currentPath = currentPath[ currentPath.length -1 ].src;

        return new PathStack().push( currentPath ) + "";


    })();

    /* *
 * Editor configuration. Include the URL variable in all resource paths below. */
    window.UMEDITOR_CONFIG = {

        // Set the resource path for the editor instance. Do not comment out this option.
        UMEDITOR_HOME_URL : URL

        // Image upload configuration
        ,imageUrl:URL+"php/imageUp.php"             // Image upload endpoint
        ,imagePath:URL + "php/"                     // Image URL prefix; see fixedImagePath. Customize it if needed.
        ,imageFieldName:"upfile"                   // Image upload field name. Update the corresponding backend field if this changes.


        // Choose toolbar buttons and dropdowns when creating the editor instance.
        ,toolbar:[
            'source | undo redo | bold italic underline strikethrough | superscript subscript | forecolor backcolor | removeformat |',
            'insertorderedlist insertunorderedlist | selectall cleardoc paragraph | fontfamily fontsize' ,
            '| justifyleft justifycenter justifyright justifyjustify |',
            'link unlink | image video  | map',
            '| horizontal print preview fullscreen', 'drafts', 'formula'
        ]

        // Language defaults to zh-cn. To switch languages, provide the corresponding file in the lang folder.
        // The lang value can also be detected using (navigator.language||navigator.browserLanguage ||navigator.userLanguage).toLowerCase()
        // , language: \"zh-cn”
        // , langPath:URL +\ "lang/”

        // Automatic link monitoring under IE
        // , autourldetectinie:false

        // Theme defaults to default. To switch themes, provide the corresponding file in the themes folder.
        // Available theme: default
        // ,theme:\'default\'
        // ,themePath:URL +\"themes/\"



        // getAllHtml adds this character encoding to the HTML head.
        // ,charset:\"utf-8\"

        // Common configuration items
        // , isShow: true//Show the editor by default.

        // , initialContent: \'Welcome to UMEDITOR! \' //Initial editor content. A textarea or script element can also supply it; see the official examples.

        // , initialFrameWidth: 500 //Initial editor width; default: 500.
        // , initialFrameHeight:500 //Initial editor frame height, >500.

        // , autoClearInitialContent: true//Automatically clear initial content. With focus enabled, it is cleared as soon as the editor initializes.

        // , textarea: \'EditorValue\'//Form field name used to submit editor content. For multiple instances, each container can supply its own name attribute.

        // , focus: false //Focus the editor when it initializes.

        // , autoclearemptyNode: true //Remove empty inlineElement nodes, including nested ones, when calling getContent.

        // ,fullscreen : false //Start in fullscreen mode; default: false.

        // , readonly: false //Make the editing area read-only after initialization; default: false.

        // , zIndex: 900 //Editor stacking order; default: 900.

        // Set a line height for p elements to prevent visible shifts when entering Chinese text.
        // Scope this style under .edui-editor-body .edui-body-container to avoid conflicts with page CSS.
        // ,initialStyle:\'.edui-editor-body .edui-body-container p{line-height:1em}\'

        // , autoSyncData: true//Automatically synchronize editor content for submission.

        // , emotionLocalization: false//Use local emoticon images; default: false. Supply the official images emoji folder inside the emotion directory first.

        // , allHtmlEnabled: false //Submit the complete HTML document to the backend.

        // fontfamily
        // Font settings
// ,\'fontfamily\':[
// {name:\ 'sont\ ', val:\ 'simSun\'},
// ]

        // Fontsize
        // font size
        // ,\ 'fontsize\ ': [10, 11, 12, 14, 16, 18, 20, 24, 36]

        // paragraph
        // Leave paragraph labels empty to use the language file. Configured labels take precedence.
        // ,\'paragraph\':{\'p\':\'\', \'h1\':\'\', \'h2\':\'\', \'h3\':\'\', \'h4\':\'\', \'h5\':\'\', \'h6\':\'\'}

        // undo
        // Maximum number of undo steps; default: 20.
        // ,maxUndoCount:20
        // Save an undo snapshot after this many characters have been entered.
        // ,maxInputCount:1

        // imageScaleEnabled
        // Allow image resizing by dragging; default: true.
        // ,imageScaleEnabled:true

        // dropFileEnabled
        // Allow images to be dropped into the editor, uploaded and inserted; default: true.
        // ,dropFileEnabled:true

        // pasteImageEnabled
        // Allow QQ screenshots to be pasted, uploaded and inserted; default: true.
        // ,pasteImageEnabled:true

        // autoHeightEnabled
        // Automatically increase the editor height; default: true.
        // ,autoHeightEnabled:true

        // autoFloatEnabled
        // Keep the toolbar visible at a fixed position while scrolling; default: true.
        // ,autoFloatEnabled:true

        // Distance from the browser top when the toolbar floats; useful for pages with fixed headers.
        // ,topOffset:30

        // Content filter rules
        ,filterRules: {}
        // Enable XSS filtering for inserthtml and other operations.
 		,xssFilterRules: true
 		// Input XSS filtering
 		,inputXssFilter: true
 		// Output XSS filtering
 		,outputXssFilter: true
 		// xss filter whitelist source: https://raw.githubusercontent.com/leizongmin/js-xss/master/lib/default.js
        ,whiteList: {
            a:      ['target', 'href', 'title', 'style', 'class', 'id'],
			abbr:   ['title', 'style', 'class', 'id'],
			address: ['style', 'class', 'id'],
			area:   ['shape', 'coords', 'href', 'alt', 'style', 'class', 'id'],
			article: ['style', 'class', 'id'],
			aside:  ['style', 'class', 'id'],
			audio:  ['autoplay', 'controls', 'loop', 'preload', 'src', 'style', 'class', 'id'],
			b:      ['style', 'class', 'id'],
			bdi:    ['dir'],
			bdo:    ['dir'],
			big:    [],
			blockquote: ['cite', 'style', 'class', 'id'],
			br:     [],
			caption: ['style', 'class', 'id'],
			center: [],
			cite:   [],
			code:   ['style', 'class', 'id'],
			col:    ['align', 'valign', 'span', 'width', 'style', 'class', 'id'],
			colgroup: ['align', 'valign', 'span', 'width', 'style', 'class', 'id'],
			dd:     ['style', 'class', 'id'],
			del:    ['datetime', 'style', 'class', 'id'],
			details: ['open', 'style', 'class', 'id'],
			div:    ['style', 'class', 'id'],
			dl:     ['style', 'class', 'id'],
			dt:     ['style', 'class', 'id'],
			em:     ['style', 'class', 'id'],
            embed:  ['style', 'class', 'id', '_url', 'type', 'pluginspage', 'src', 'width', 'height', 'wmode', 'play', 'loop', 'menu', 'allowscriptaccess', 'allowfullscreen'],
			font:   ['color', 'size', 'face', 'style', 'class', 'id'],
			footer: ['style', 'class', 'id'],
			h1:     ['style', 'class', 'id'],
			h2:     ['style', 'class', 'id'],
			h3:     ['style', 'class', 'id'],
			h4:     ['style', 'class', 'id'],
			h5:     ['style', 'class', 'id'],
			h6:     ['style', 'class', 'id'],
			header: ['style', 'class', 'id'],
			hr:     ['style', 'class', 'id'],
			i:      ['style', 'class', 'id'],
            iframe: ['style', 'class', 'id', 'src', 'frameborder', 'data-latex'],
			img:    ['src', 'alt', 'title', 'width', 'height', 'style', 'class', 'id', '_url'],
			ins:    ['datetime', 'style', 'class', 'id'],
			li:     ['style', 'class', 'id'],
			mark:   [],
			nav:    [],
			ol:     ['style', 'class', 'id'],
			p:      ['style', 'class', 'id'],
			pre:    ['style', 'class', 'id'],
			s:      [],
			section:[],
			small:  ['style', 'class', 'id'],
			span:   ['style', 'class', 'id'],
			sub:    ['style', 'class', 'id'],
			sup:    ['style', 'class', 'id'],
			strong: ['style', 'class', 'id'],
			table:  ['width', 'border', 'align', 'valign', 'style', 'class', 'id'],
			tbody:  ['align', 'valign', 'style', 'class', 'id'],
			td:     ['width', 'rowspan', 'colspan', 'align', 'valign', 'style', 'class', 'id'],
			tfoot:  ['align', 'valign', 'style', 'class', 'id'],
			th:     ['width', 'rowspan', 'colspan', 'align', 'valign', 'style', 'class', 'id'],
			thead:  ['align', 'valign', 'style', 'class', 'id'],
			tr:     ['rowspan', 'align', 'valign', 'style', 'class', 'id'],
			tt:     ['style', 'class', 'id'],
			u:      [],
			ul:     ['style', 'class', 'id'],
            svg:    ['style', 'class', 'id', 'width', 'height', 'xmlns', 'fill', 'viewBox'],
			video:  ['autoplay', 'controls', 'loop', 'preload', 'src', 'height', 'width', 'style', 'class', 'id']
        }
    };
})();
