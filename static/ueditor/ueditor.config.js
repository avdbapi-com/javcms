/* *
 * Complete UEditor configuration
 * Configure all editor features here. */
/* *********************tips****************************
 * Commented-out configuration items show the UEditor defaults.
 * Before changing a default, make sure you understand what the parameter controls.
 * Change settings by uncommenting and editing them here, or pass them as options when creating an editor instance.
 * When upgrading, you can retain the existing configuration file. Newly introduced options use their defaults if they are absent.
 **********************tips*********************** */

(function () {

    /* *
 * Root path for editor resources, resolved relative to the page that creates the editor. This points to the editor\'s resource files (that is, folders such as dialog).
 * To avoid path-resolution problems, use a path relative to the website root.
 * A website-root-relative path starts with a slash, such as “/myProject/uEditor/”.
 * If pages at different directory levels share the same UEditor installation, a page-relative URL may not work for every page\'s editor.
 * UEditor therefore allows each page to specify its resource root. Place the following setting at the top of the page that creates the editor, using the appropriate resource URL.
 * window.ueditor_home_url = “/xxxx/xxxx/”; */
    var URL = window.UEDITOR_HOME_URL || getUEBasePath();
    /* *
 * Main editor configuration. Include the URL variable in resource-path settings. */
    window.UEDITOR_CONFIG = {

        // Set the resource path for the editor instance; do not comment out this setting.
        UEDITOR_HOME_URL: URL

        // URL of the common server request endpoint
        , serverUrl: URL + "./applcation/admin/upload/upload.html?"

        // All the function buttons and drop-down boxes on the toolbar can be redefined as needed when creating an instance of the editor
        , toolbars: [[
            'fullscreen', 'source', '|', 'undo', 'redo', '|',
            'bold', 'italic', 'underline', 'fontborder', 'strikethrough', 'superscript', 'subscript', 'autotypeset', 'pasteplain', 'cleardoc', 'removeformat' ,'|', 'forecolor', 'backcolor', 'insertorderedlist', 'insertunorderedlist',
			'lineheight', '|',
            'paragraph', 'fontfamily', 'fontsize', '|',
            'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|', 'touppercase', 'tolowercase', '|',
			],[
            'link', 'unlink', 'anchor', '|', 'imagenone', 'imageleft', 'imageright', 'imagecenter', '|',
            'simpleupload', 'insertimage', 'scrawl', 'insertvideo', 'attachment', 'map', 'insertframe', 'template', 'background', '|',
            'horizontal', 'date', 'time', 'spechars', '|',
            'inserttable', 'deletetable', 'insertparagraphbeforetable', 'insertrow', 'deleterow', 'insertcol', 'deletecol', 'mergecells', 'mergeright', 'mergedown', 'splittocells', 'splittorows', 'splittocols', '|',
            'print', 'preview', 'searchreplace', 'drafts', 'help'
        ]]
        // Toolbar tooltips. Leave labels blank to use the selected language; explicit labels override the locale.
        // , labelMap: {
        // \'anchor\':\'\', \'undo\':\'\'
        // }

        // Editor language, defaulting to zh-cn. To select a language automatically, use the expression below. Its language file must exist in the lang folder.
        // The language value can also be detected automatically with (navigator.language||navigator.browserLanguage ||navigator.userLanguage).toLowerCase()
        ,lang:"zh-cn"
        ,langPath:URL +"lang/"

        // Editor theme, defaulting to default. Other themes require corresponding files in the themes folder.
        // The following skins are available: default
        // ,theme:\'default\'
        // ,themePath:URL +\"themes/\"

        // , zIndex: 900 //Editor stacking order; default is 900

        // For the getAllHtml method, this encoding setting will be added to the corresponding head tag.
        // ,charset:\"utf-8\"

        // Set this to true if the editor page manually changes document.domain.
        // ,customDomain:false

        // Common configuration items
        // , isShow: true//Show the editor by default

        // , textarea: \'EditorValue\'//Form field name used to submit editor content. For multiple instances, give each container a name attribute to set its submission field without configuring this option separately.

        // , initialContent: \'Welcome to ueditor! \' //Initial editor content. You can also provide it through a textarea or script element; see the official examples.

        // , autoClearInitialContent: true//Clear initial content automatically. If both this option and focus are true, the initial content is cleared as soon as the editor opens.

        // , focus: false //Whether the editor receives focus on initialization

        // When customizing initial styles, set a line height on p elements to avoid visual movement while entering Chinese text.
        // , initialStyle: \'p {line-height:1em} \'//Initial editor styles, including fonts

        // , iframecSSURL: URL + \'/themes/iframe.css\'//Load a CSS file into the editing iframe

        // indentValue
        // First-line indentation; default is 2em
        // , indentValue:\ '2em\ '

        // , initialFrameWidth: 1000 //Initial editor width; default is 1000
        // , initialFrameHeight:320 //Initial editor height; default is 320

        // , readonly: false //Whether the editing area is read-only after initialization; default is false

        // , autoclearemptyNode: true //Remove empty inline elements, including nested ones, when getContent is called

        // Enable autosave
        // ,enableAutoSave: true
        // Autosave interval, in ms
        // ,saveInterval: 500

        // ,fullscreen : false //Start in fullscreen mode; disabled by default

        // , imagePopup: true//Enable the image operation popup; enabled by default

        // , autoSyncData: true//Automatically synchronize editor content with the submission field
        // , emotionLocalization: false//Use local emoticon images; disabled by default. To enable this, place the officially supplied emoticon images in the emotion folder.

        // Keep pasted tags but remove their attributes
        // ,retainOnlyLabelPasted: false

        // , pasteplain: false//Paste as plain text by default when true; retain formatting when false
        // Filter rules in plain text paste mode
        // \'filterTxtRules\' : function(){
        // function transP(node){
        // node.tagName = \'p\';
        // node.setStyle();
        // }
        // return {
        // //Remove these elements and their contents
        // \'-\' : \'script style object iframe embed input select\',
        // \ 'p\ ': {$: {}},
        // \'br\':{$:{}},
        // \ 'div\ ': {\ '$\': {}},
        // 'li': {'$': {}},
        // \'caption\':transP,
        // \'th\':transP,
        // \'tr\':transP,
        // \'h1\':transP,\'h2\':transP,\'h3\':transP,\'h4\':transP,\'h5\':transP,\'h6\':transP,
        // \'td\':function(node){
        // //Delete empty td elements
        // var txt =!! node.innerText ();
        // if(txt){
        // node.parentNode.insertAfter (ue.unode.createText (\'\'), node);
        // }
        // node.parentNode.removeChild (node, Node.innerText ())
        // }
        // }
        // }()

        // , allHtmlEnabled: false //Include the complete HTML document in the submitted content

        // insertorderedlist
        // Ordered-list menu labels. Blank labels use the selected language; explicit labels override the locale.
        // ,\ 'insertorderedlist\ ': {
        // //custom style
        // \ 'num\ ':\ '1,2,3... \ ',
        // \ 'num1\ ':\ '1) ,2) ,3)... \ ',
        // \ 'num2\ ':\' (1), (2), (3)... \ ',
        // \'cn\': \'One, two, three... \',
        // \ 'cn1\ ':\ '1), 2), 3)... \ ',
        // \ 'cn2\ ':\' (1), (2), (3)... \ ',
        // //Built-in styles
        // \'decimal\' : \'\' , //\'1,2,3...\'
        // \'lower-alpha\' : \'\' , // \'a,b,c...\'
        // \'lower-roman\' : \'\' , //\'i,ii,iii...\'
        // \'upper-alpha\' : \'\' , lang //\'A,B,C\'
        // \'upper-roman\' : \'\' //\'I,II,III...\'
        // }

        // insertunorderedlist
        // Unordered-list menu labels. Blank labels use the selected language; explicit labels override the locale.
        // , insertUnorderedlist: {//Custom unordered-list styles
        // \'dash\': \'— dash \', //-dash
        // \'dot\': \'. Little circle\', //Built-in style
        // \'circle\': “,//\'○ small circle\'
        // \'disc\': “,//\'● small dot\'
        // \'square\': \"//\'■ small square\'
        // }
        // , listDefaultPaddingLeft: \'30\'//Default left padding for lists
        // , listiconpath:\ 'https://example.com/assets/list-icons/\'//Custom list-icon path
        // , maxListLevel: 3 //Maximum list nesting depth; -1 means unlimited

        // , AutoTranswordToList:false //AutoTranswordToList: Convert Word list formatting automatically

        // fontfamily
        // Font menu labels. Blank labels use the selected language; explicit labels override the locale.
        // ,\'fontfamily\':[
        // {label:”, name: \'songti\', val: \', SimSun\'},
        // {label:”, name:\ 'kaiti\ ', val:\', _GB2312, SimKai\ '},
        // { label:\'\',name:\'yahei\',val:\'Microsoft Yahei,Microsoft YaHei\'},
        // {label:”, name: \'heiti\', val: \'No, simhei\'},
        // { label:\'\',name:\'lishu\',val:\'Liberal script, SimLi\'},
        // { label:\'\',name:\'andaleMono\',val:\'andale mono\'},
        // { label:\'\',name:\'arial\',val:\'arial, helvetica,sans-serif\'},
        // { label:\'\',name:\'arialBlack\',val:\'arial black,avant garde\'},
        // { label:\'\',name:\'comicSansMs\',val:\'comic sans ms\'},
        // { label:\'\',name:\'impact\',val:\'impact,chicago\'},
        // { label:\'\',name:\'timesNewRoman\',val:\'times new roman\'}
        // ]

        // Fontsize
        // font size
        // ,\ 'fontsize\ ': [10, 11, 12, 14, 16, 18, 20, 24, 36]

        // paragraph
        // Paragraph menu labels. Blank labels use the selected language; explicit labels override the locale.
        // ,\'paragraph\':{\'p\':\'\', \'h1\':\'\', \'h2\':\'\', \'h3\':\'\', \'h4\':\'\', \'h5\':\'\', \'h6\':\'\'}

        // rowspacingtop
        // Paragraph spacing values also serve as their displayed labels
        // ,\'rowspacingtop\':[\'5\', \'10\', \'15\', \'20\', \'25\']

        // rowspacingBottom
        // Paragraph spacing values also serve as their displayed labels
        // ,\'rowspacingbottom\':[\'5\', \'10\', \'15\', \'20\', \'25\']

        // lineheight
        // Line-height values also serve as their displayed labels
        // ,\'lineheight\':[\'1\', \'1.5\',\'1.75\',\'2\', \'3\', \'4\', \'5\']

        // customstyle
        // Custom styles do not support localization. Their configured labels appear at the end of the style menu.
        // Block styles follow paragraph formatting; inline styles follow bold, italic, and underline formatting.
        // Try to use some commonly used tags
        // Parameter description
        // tag: HTML element name
        // name: Unique identifier for the style; label: Displayed style name.
        // style: CSS declarations applied by the style
        // Each object is a custom style
        // ,\'customstyle\':[
        // {tag:\'h1\', name:\'tc\', label:\'\', style:\'border-bottom:#ccc 2px solid;padding:0 4px 0 0;text-align:center;margin:0 0 20px 0;\'},
        // {tag:\'h1\', name:\'tl\',label:\'\', style:\'border-bottom:#ccc 2px solid;padding:0 4px 0 0;margin:0 0 10px 0;\'},
        // {tag:\'span\',name:\'im\', label:\'\', style:\'font-style:italic;font-weight:bold\'},
        // {tag:\'span\',name:\'hi\', label:\'\', style:\'font-style:italic;font-weight:bold;color:rgb(51, 153, 204)\'}
        // ]

        // Enable the context menu
        // ,enableContextMenu: true
        // For context-menu items, see plugins/contextmenu.js. Blank labels use the selected language; explicit labels override the locale.
        // , ContextMenu: [
        // {
        // label:”, //displayed name
        // cmdName: \'selectall\', //Command executed when this context-menu item is clicked
        // //exec is optional. If supplied, it runs when the item is clicked and takes precedence over cmdName.
        // exec:function () {
        // //this refers to the current editor instance
        // //this.ui._dialogs[\'inserttableDialog\'].open();
        // }
        // }
        // ]

        // quick menu
        // ,shortcutMenu:[\"fontfamily\", \"fontsize\", \"bold\", \"italic\", \"underline\", \"forecolor\", \"backcolor\", \"insertorderedlist\", \"insertunorderedlist\"]

        // elementPathEnabled
        // Show the element path; enabled by default
        // ,elementPathEnabled : true

        // wordCount
        // , wordcount: true//Enable word-count statistics
        // , maximumWords: 10000 //maximum number of characters allowed
        // Character-count message. {#count} is the current count and {#leave} is the remaining allowance. Leave it blank to use the selected language; an explicit message overrides the locale.
        // , wordCountMsg:” //Currently {#count} characters have been entered, you can also enter {#leave} characters
        // Character-limit warning. Leave it blank to use the selected language; an explicit message overrides the locale.
        // <span style=\"color:red;\">, wordOverflowMsg:”//The number of characters you have entered has exceeded the maximum allowed value, and the server may refuse to save!</span>

        // tab
        // Tab inserts tabSize copies of tabNode
        // ,tabSize:4
        // , tabNode:\ '\'

        // removeFormat
        // Tags and attributes that can be removed when the format is cleared
        // removeForamtTags
        // ,removeFormatTags:\'b,big,code,del,dfn,em,font,i,ins,kbd,q,samp,small,span,strike,strong,sub,sup,tt,u,var\'
        // Attributes removed by removeFormat
        // ,removeFormatAttributes:\'class,style,lang,width,height,align,hspace,valign\'

        // undo
        // Maximum undo history length; default is 20
        // ,maxUndoCount:20
        // Save an undo snapshot after this many characters are entered
        // ,maxInputCount:1

        // autoHeightEnabled
        // Automatically increase editor height; enabled by default
        ,autoHeightEnabled:false

        // scaleEnabled
        // Enable manual height resizing; automatic height adjustment is disabled when this is enabled
        // ,scaleEnabled:false
        // , minFrameWidth: 800 //minimum width when dragging the editor, default 800
        // , minFrameHeight:220 //Minimum editor height when resizing; minFrameHeight:220

        // autoFloatEnabled
        // Keep the toolbar visible while scrolling; enabled by default
        ,autoFloatEnabled:false
        // Offset from the top of the viewport for a floating toolbar, useful for pages with fixed headers
        // ,topOffset:30
        // Distance from the editor bottom at which the toolbar stops floating; values at least as large as the editor height have no effect
        // ,toolbarTopOffset:400

        // Set whether to capture remote images and save them locally
        // ,catchRemoteImageEnable: true //Enable downloading of remote images

        // pageBreakTag
        // Page break identifier, default is _ueditor_page_break_tag_
        // ,pageBreakTag:\'_ueditor_page_break_tag_\'

        // Automatic typesetting
        // Auto layout parameters
        // , autotypeset: {
        // mergeEmptyline: true, //Merge consecutive empty lines
        // removeClass: true, //Remove redundant classes
        // removeEmptyline: false, //Remove empty lines
        // textAlign: “left”, //Paragraph alignment: left, right, center, or justify. Remove this option to leave alignment unchanged.
        // imageBlockLine: \'center\', //Image layout: center, left, right, or none. Remove this option to leave image layout unchanged.
        // pasteFilter: false, //Apply these formatting rules to content that was not pasted
        // clearFontSize: false, //Remove inline font sizes and use the editor\'s default font size
        // clearFontFamily: false, //Remove inline font families and use the editor\'s default font
        // removeEmptyNode: false,//default
        // //tags that can be removed
        // removeTagNames: {tag name: 1},
        // indent: false, // Indent the first line
        // indentValue: \'2em\', //First-line indentation amount
        // bdc2sb: false,
        // tobdc: false
        // }

        // tableDragable
        // Whether table dragging is enabled
        // ,tableDragable: true



        // sourceEditor
        // Source editor type: codemirror provides syntax highlighting; textarea uses a plain text box. The default is codemirror.
        // The default codemirror source editor supports IE8+ and other browsers.
        // ,sourceEditor:\"codemirror\"
        // The CodeMirror source editor also requires its JavaScript and CSS resource URLs.
        // CodeMirrorJsURL jsURL +\ "third-party/codemirror/codemirror.js”
        // , codeMirrorJSURL:url +\ "third-party/codemirror/codemirror.js”
        // CodeMirrorCssUrl cssPage and enter URL + “third-party/codemirror/codemirror.css”
        // , codeMirrorCssURL:url +\ "third-party/codemirror/codemirror.css”
        // Start in source mode after initialization; disabled by default.
        // ,sourceEditorFirst:false

        // IFrameURLMap
        // In dialog paths, ~ is replaced with the editor resource URL. This map overrides the default paths for the specified dialogs.
        // , iFrameURLMap: {
        // \'anchor\':\'~/dialogs/anchor/anchor.html\',
        // }

        // Allowed link prefixes. Links with these prefixes do not receive an automatic http prefix.
        // , allowLinkProtocols: [\'http:\', \'https:\', \'#\', \'/\', \'ftp:\', \'mailto:\', \'tel:\', \'git:\', \'svn:\']

        // WebAppKey is the APIKey for Baidu apps. Every webmaster must first register a key on Baidu\'s official website before they can use the app functions normally. Registration introduction, http://app.baidu.com/static/cms/getapikey.html
        // , WebAppKey: “”

        // Configuration items related to default filter rules
        // ,disabledTableInTable:true //Prevent tables from being nested inside tables
        // , allowDivTranStop: true//Convert incoming div elements to p elements
        // , rgb2hex: true//Convert output colors from RGB to hexadecimal notation

		// Enable XSS filtering for inserthtml and other operations
		,xssFilterRules: true
		// Filter incoming HTML for XSS
		,inputXssFilter: true
		// Filter outgoing HTML for XSS
		,outputXssFilter: true
		// xss filter whitelist source: https://raw.githubusercontent.com/leizongmin/js-xss/master/lib/default.js
		,whitList: {
			a:      ['target', 'href', 'title', 'class', 'style'],
			abbr:   ['title', 'class', 'style'],
			address: ['class', 'style'],
			area:   ['shape', 'coords', 'href', 'alt'],
			article: [],
			aside:  [],
			audio:  ['autoplay', 'controls', 'loop', 'preload', 'src', 'class', 'style'],
			b:      ['class', 'style'],
			bdi:    ['dir'],
			bdo:    ['dir'],
			big:    [],
			blockquote: ['cite', 'class', 'style'],
			br:     [],
			caption: ['class', 'style'],
			center: [],
			cite:   [],
			code:   ['class', 'style'],
			col:    ['align', 'valign', 'span', 'width', 'class', 'style'],
			colgroup: ['align', 'valign', 'span', 'width', 'class', 'style'],
			dd:     ['class', 'style'],
			del:    ['datetime'],
			details: ['open'],
			div:    ['class', 'style'],
			dl:     ['class', 'style'],
			dt:     ['class', 'style'],
			em:     ['class', 'style'],
			font:   ['color', 'size', 'face'],
			footer: [],
			h1:     ['class', 'style'],
			h2:     ['class', 'style'],
			h3:     ['class', 'style'],
			h4:     ['class', 'style'],
			h5:     ['class', 'style'],
			h6:     ['class', 'style'],
			header: [],
			hr:     [],
			i:      ['class', 'style'],
			img:    ['src', 'alt', 'title', 'width', 'height', 'id', '_src', 'loadingclass', 'class', 'data-latex'],
			ins:    ['datetime'],
			li:     ['class', 'style'],
			mark:   [],
			nav:    [],
			ol:     ['class', 'style'],
			p:      ['class', 'style'],
			pre:    ['class', 'style'],
			s:      [],
			section:[],
			small:  [],
			span:   ['class', 'style'],
			sub:    ['class', 'style'],
			sup:    ['class', 'style'],
			strong: ['class', 'style'],
			table:  ['width', 'border', 'align', 'valign', 'class', 'style'],
			tbody:  ['align', 'valign', 'class', 'style'],
			td:     ['width', 'rowspan', 'colspan', 'align', 'valign', 'class', 'style'],
			tfoot:  ['align', 'valign', 'class', 'style'],
			th:     ['width', 'rowspan', 'colspan', 'align', 'valign', 'class', 'style'],
			thead:  ['align', 'valign', 'class', 'style'],
			tr:     ['rowspan', 'align', 'valign', 'class', 'style'],
			tt:     [],
			u:      [],
			ul:     ['class', 'style'],
			video:  ['autoplay', 'controls', 'loop', 'preload', 'src', 'height', 'width', 'class', 'style'],
            embed: ['wdith','height','type','pluginspage','flashvars','quality','src','play','allowfullscreen','allowNetworking','menu','allowscriptaccess','wmode','always','id','name'],
			iframe: ['width','height','src','style','border','marginWidth','frameSpacing','marginHeight','frameBorder','scrolling','vspale']
		}
    };

    function getUEBasePath(docUrl, confUrl) {

        return getBasePath(docUrl || self.document.URL || self.location.href, confUrl || getConfigFilePath());

    }

    function getConfigFilePath() {

        var configPath = document.getElementsByTagName('script');

        return configPath[ configPath.length - 1 ].src;

    }

    function getBasePath(docUrl, confUrl) {

        var basePath = confUrl;


        if (/^(\/|\\\\)/.test(confUrl)) {

            basePath = /^.+?\w(\/|\\\\)/.exec(docUrl)[0] + confUrl.replace(/^(\/|\\\\)/, '');

        } else if (!/^[a-z]+:/i.test(confUrl)) {

            docUrl = docUrl.split("#")[0].split("?")[0].replace(/[^\\\/]+$/, '');

            basePath = docUrl + "" + confUrl;

        }

        return optimizationPath(basePath);

    }

    function optimizationPath(path) {

        var protocol = /^[a-z]+:\/\//.exec(path)[ 0 ],
            tmp = null,
            res = [];

        path = path.replace(protocol, "").split("?")[0].split("#")[0];

        path = path.replace(/\\/g, '/').split(/\//);

        path[ path.length - 1 ] = "";

        while (path.length) {

            if (( tmp = path.shift() ) === "..") {
                res.pop();
            } else if (tmp !== ".") {
                res.push(tmp);
            }

        }

        return protocol + res.join("/");

    }

    window.UE = {
        getUEBasePath: getUEBasePath
    };

})();
