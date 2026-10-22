(function(){

    var editor = null;

    UM.registerWidget('emotion',{

        tpl: "<link type=\"text/css\" rel=\"stylesheet\" href=\"<%=emotion_url%>emotion.css\">" +
            "<div class=\"edui-emotion-tab-Jpanel edui-emotion-wrapper\">" +
            "<ul class=\"edui-emotion-Jtabnav edui-tab-nav\">" +
            "<li class=\"edui-tab-item\"><a data-context=\".edui-emotion-Jtab0\" hideFocus=\"true\" class=\"edui-tab-text\"><%=lang_input_choice%></a></li>" +
            "<li class=\"edui-tab-item\"><a data-context=\".edui-emotion-Jtab1\" class=\"edui-tab-text\"><%=lang_input_Tuzki%></a></li>" +
            "<li class=\"edui-tab-item\"><a data-context=\".edui-emotion-Jtab2\" hideFocus=\"true\" class=\"edui-tab-text\"><%=lang_input_lvdouwa%></a></li>" +
            "<li class=\"edui-tab-item\"><a data-context=\".edui-emotion-Jtab3\" hideFocus=\"true\" class=\"edui-tab-text\"><%=lang_input_BOBO%></a></li>" +
            "<li class=\"edui-tab-item\"><a data-context=\".edui-emotion-Jtab4\" hideFocus=\"true\" class=\"edui-tab-text\"><%=lang_input_babyCat%></a></li>" +
            "<li class=\"edui-tab-item\"><a data-context=\".edui-emotion-Jtab5\" hideFocus=\"true\" class=\"edui-tab-text\"><%=lang_input_bubble%></a></li>" +
            "<li class=\"edui-tab-item\"><a data-context=\".edui-emotion-Jtab6\" hideFocus=\"true\" class=\"edui-tab-text\"><%=lang_input_youa%></a></li>" +
            "<li class=\"edui-emotion-tabs\"></li>" +
            "</ul>" +
            "<div class=\"edui-tab-content edui-emotion-JtabBodys\">" +
            "<div class=\"edui-emotion-Jtab0 edui-tab-pane\"></div>" +
            "<div class=\"edui-emotion-Jtab1 edui-tab-pane\"></div>" +
            "<div class=\"edui-emotion-Jtab2 edui-tab-pane\"></div>" +
            "<div class=\"edui-emotion-Jtab3 edui-tab-pane\"></div>" +
            "<div class=\"edui-emotion-Jtab4 edui-tab-pane\"></div>" +
            "<div class=\"edui-emotion-Jtab5 edui-tab-pane\"></div>" +
            "<div class=\"edui-emotion-Jtab6 edui-tab-pane\"></div>" +
            "</div>" +
            "<div class=\"edui-emotion-JtabIconReview edui-emotion-preview-box\">" +
            "<img src=\"<%=cover_img%>\" class=\'edui-emotion-JfaceReview edui-emotion-preview-img\'/>" +
            "</div>",

            sourceData: {
                emotion: {
                    tabNum: 7, // Number of tabs
                    SmilingName: {
                        'edui-emotion-Jtab0': ['j_00', 84],
                        'edui-emotion-Jtab1': ['t_00', 40],
                        'edui-emotion-Jtab2': ['w_00', 52],
                        'edui-emotion-Jtab3': ['B_00', 63],
                        'edui-emotion-Jtab4': ['C_00', 20],
                        'edui-emotion-Jtab5': ['i_f', 50],
                        'edui-emotion-Jtab6': ['y_00', 40]
                    }, // Image filename prefix and count.
                    imageFolders: {
                        'edui-emotion-Jtab0': 'jx2/',
                        'edui-emotion-Jtab1': 'tsj/',
                        'edui-emotion-Jtab2': 'ldw/',
                        'edui-emotion-Jtab3': 'bobo/',
                        'edui-emotion-Jtab4': 'babycat/',
                        'edui-emotion-Jtab5': 'face/',
                        'edui-emotion-Jtab6': 'youa/'
                    }, // Image folder for each tab.
                    imageCss: {
                        'edui-emotion-Jtab0': 'jd',
                        'edui-emotion-Jtab1': 'tsj',
                        'edui-emotion-Jtab2': 'ldw',
                        'edui-emotion-Jtab3': 'bb',
                        'edui-emotion-Jtab4': 'cat',
                        'edui-emotion-Jtab5': 'pp',
                        'edui-emotion-Jtab6': 'youa'
                    }, // Image CSS class for each tab.
                    imageCssOffset: {
                        'edui-emotion-Jtab0': 35,
                        'edui-emotion-Jtab1': 35,
                        'edui-emotion-Jtab2': 35,
                        'edui-emotion-Jtab3': 35,
                        'edui-emotion-Jtab4': 35,
                        'edui-emotion-Jtab5': 25,
                        'edui-emotion-Jtab6': 35
                    }, // Vertical sprite offset for each tab.
                    SmileyInfor: {
                        'edui-emotion-Jtab0': ['Kiss', 'Love', 'Yeah', 'Ah!', 'Twisting', 'Top', 'Shake Chest', '88', 'Sweat', 'Sleepy', 'Lula', 'Brick', 'Rub Face', 'Happy Birthday', 'Laughing Out Loud', 'Heavy sweating', 'Surprised', 'Smug', 'Grinning', 'Wink', 'Angry', 'Buy Soy Sauce', 'Push-Up', 'Furious', '?', 'Kiss', 'Anger', 'Victory', 'HI', 'KISS', 'Silent', 'No', 'Tear Flower', 'Big Heart', 'Top', 'Very surprised', 'Flying Kiss', 'Ghost Face', 'Shy', 'Drool', 'Crying Out Loud', 'Come', 'Rich', 'Eat Watermelon', 'Stuck', 'Shy', 'Celebrate', 'I Am Here', 'Knock', 'Dizzy', 'Victory', 'Smug', 'Beaten', 'Greedy', 'Welcome', 'Cool', 'Smile', 'Kiss', 'Playful', 'Terrified', 'Cool', 'Angry', 'Shy', 'Sweat', 'Crying', '', 'Cheer Up', 'Sleepy', 'You Are Awesome', 'Faint', 'Happy', 'Sneaky Smile', 'Crying', 'Sweat', 'Sigh', 'Awesome', '??', 'Flying Kiss', 'Angel', 'Sprinkle Flowers', 'Angry', 'Beaten', 'Scared', 'Casual Spit'],
                        'edui-emotion-Jtab1': ['Kiss', 'Love', 'Yeah', 'Ah!', 'Twisting', 'Top', 'Shake Chest', '88', 'Sweat', 'Sleepy', 'Lula', 'Brick', 'Rub Face', 'Happy Birthday', 'Hands Up', 'Sleep', 'Slumped', 'Bored', 'Stars Flash', 'Spin', 'Not Possible', 'Depressed', 'Listening to music', 'Grab Wall', 'Banging head against a wall', 'Tilt Head', 'Poke Eye', 'Drift By', 'Mutual Bricks', 'Kill You', 'Flipping a table', 'Shaolin Temple', 'What?', 'Turn Head', 'I Love Milk', 'I Kick', 'Sway', 'Faint', 'In a cage', 'Shock'],
                        'edui-emotion-Jtab2': ['Laughing Out Loud', 'Heavy sweating', 'Surprised', 'Smug', 'Grinning', 'Wink', 'Angry', 'I Was Wrong', 'Money', 'Furious', 'Tease', 'Kiss', 'Anger', 'Victory', 'Wronged', 'Injured', 'What Are You Talking About?', 'Shut Up', 'No', 'Just Kidding', 'Flying Kiss', 'Dizzy', 'Magic', 'I Am Here', 'Sleeping', 'I Hit', 'Shut Up', 'Hit', 'Knocked Out', 'Brushing teeth', 'Beat Up', 'Bomb', 'Upside Down', 'Shaving', 'Evil Smile', 'No way', 'In Love', 'Taking a closer look', 'Peeping', 'Super Happy', 'Dizzy', 'Sigh of Relief', 'Running', 'Enjoy', 'Cultivate', 'Cry', 'Sweat', 'Ah~', 'Warm Welcome', 'Buy Soy Sauce', 'Push-Up', '?'],
                        'edui-emotion-Jtab3': ['HI', 'KISS', 'Silent', 'No', 'Tear Flower', 'Big Heart', 'Top', 'Very surprised', 'Flying Kiss', 'Ghost Face', 'Shy', 'Drool', 'Crying Out Loud', 'Come', 'Tearful', 'Tears', 'Angry', 'Stick Out Tongue', 'Like', 'Spin', 'Goodbye', 'Go Crazy', 'Sweat', 'Despise', 'Bow', 'Spit Blood', 'Shh', 'Hit', 'Jump', 'Face Change', 'Tear Flesh', 'Eat To', 'Eat Flower', 'Blow Bubble Gum', 'Transformation', 'Fly Dance', 'Look Back', 'Pitiful', 'Beat Up', 'Bubble', 'Apple', 'Kiss', '', 'Sexy Dance', 'Burn Incense', 'Sleep', 'Nesting doll', 'Poke', 'Dance Down', 'Tomato', 'Admire', 'Shake', 'Swing', 'Juggle', 'Attract Wealth', 'Getting beaten up', 'Hit by Ball', 'Very surprised', 'Ideal', 'European Hit', 'Vomit', 'Broken', 'Spit Phlegm'],
                        'edui-emotion-Jtab4': ['Rich', 'Eat Watermelon', 'Stuck', 'Shy', 'Celebrate', 'I Am Here', 'Knock', 'Dizzy', 'Victory', 'Smug', 'Beaten', 'Greedy', 'Welcome', 'Cool', 'Top', 'Lucky', 'Heart', 'Hide', 'Send Flowers', 'Choose'],
                        'edui-emotion-Jtab5': ['Smile', 'Kiss', 'Playful', 'Surprised', 'Cool', 'Angry', 'Shy', 'Sweat', 'Crying', 'Proud', 'Despise', 'Sleepy', 'Praise', 'Faint', 'Question', 'Matchmaker', 'Throw Up', 'Frog', 'Worry', 'Kiss', '', 'Heart', 'Broken Heart', 'Rose', 'Gift', 'Cry', 'Sneaky Smile', 'Cute', 'Proud', 'Show Teeth', 'Heavy Sweat', 'Pitiful', 'Sleepy', 'Cry', 'Angry', 'Surprised', 'Drool', 'Rainbow', 'Night Sky', 'Sun', 'Money', 'Light Bulb', 'Coffee', 'Cake', 'Music', 'Love', 'Victory', 'Like', 'Despise', 'OK'],
                        'edui-emotion-Jtab6': ['Male Pocket', 'Female Pocket', 'Happy', 'Good', 'Sneaky Smile', 'Laughing Out Loud', 'Sobbing', 'Crying', 'Helpless', 'Sweat Drop', 'Sigh', 'Dizzy', 'Wronged', 'Awesome', '??', 'Question', 'Flying Kiss', 'Angel', 'Sprinkle Flowers', 'Angry', 'Beaten', 'Drool', 'Tears', 'Scared', 'Stick Out Tongue', 'Nod', 'Casual Spit', 'Spin', 'Sleepy', 'Despise', 'Top', 'Basketball', 'Goodbye', 'Welcome', 'Wishing you prosperity', 'Wait a Moment', 'I Am Online', 'No Bargaining', 'In stock', 'Goods on the Way']
                    }
                }
            },
        initContent:function( _editor, $widget ){

            var me = this,
                emotion = me.sourceData.emotion,
                lang = _editor.getLang( 'emotion' )['static'],
                emotionUrl = UMEDITOR_CONFIG.UMEDITOR_HOME_URL + 'dialogs/emotion/',
                options = $.extend( {}, lang, {
                    emotion_url: emotionUrl
                }),
                $root = me.root();

            if( me.inited ) {
                me.preventDefault();
                this.switchToFirst();
                return;
            }

            me.inited = true;

            editor = _editor;
            this.widget = $widget;

            emotion.SmileyPath = _editor.options.emotionLocalization === true ? emotionUrl + 'images/' : "http://img.baidu.com/hi/";
            emotion.SmileyBox = me.createTabList( emotion.tabNum );
            emotion.tabExist = me.createArr( emotion.tabNum );

            options['cover_img'] = emotion.SmileyPath + (editor.options.emotionLocalization ? '0.gif' : 'default/0.gif');

            $root.html( $.parseTmpl( me.tpl, options ) );

            me.tabs = $.eduitab({selector:".edui-emotion-tab-Jpanel"});

            // Cache the preview elements.
            me.previewBox = $root.find(".edui-emotion-JtabIconReview");
            me.previewImg = $root.find(".edui-emotion-JfaceReview");

            me.initImgName();

        },
        initEvent:function(){

            var me = this;

            // Keep the popup open when its content is clicked.
            me.root().on('click', function(e){
                return false;
            });

            // Show or hide the preview when hovering over an emoticon.
            me.root().delegate( 'td', 'mouseover mouseout', function( evt ){

                var $td = $( this),
                    url = $td.attr('data-surl') || null;

                if( url ) {
                    me[evt.type]( this, url , $td.attr('data-posflag') );
                }

                return false;

            } );

            // Insert the selected emoticon.
            me.root().delegate( 'td', 'click', function( evt ){

                var $td = $( this),
                    realUrl = $td.attr('data-realurl') || null;

                if( realUrl ) {
                    me.insertSmiley( realUrl.replace( /'/g, "\\'" ), evt );
                }

                return false;

            } );

            // Populate a tab before it is shown.
            me.tabs.edui().on("beforeshow", function( evt ){

                var contentId = $(evt.target).attr('data-context').replace( /^.*\.(?=[^\s]*$)/, '' );

                evt.stopPropagation();

                me.updateTab( contentId );

            });

            this.switchToFirst();

        },
        initImgName: function() {

            var emotion = this.sourceData.emotion;

            for ( var pro in emotion.SmilingName ) {
                var tempName = emotion.SmilingName[pro],
                    tempBox = emotion.SmileyBox[pro],
                    tempStr = "";

                if ( tempBox.length ) return;

                for ( var i = 1; i <= tempName[1]; i++ ) {
                    tempStr = tempName[0];
                    if ( i < 10 ) tempStr = tempStr + '0';
                    tempStr = tempStr + i + '.gif';
                    tempBox.push( tempStr );
                }
            }

        },
        /* *
 * Switch to the first tab */
        switchToFirst: function(){
            this.root().find(".edui-emotion-Jtabnav .edui-tab-text:first").trigger('click');
        },
        updateTab: function( contentBoxId ) {

            var me = this,
                emotion = me.sourceData.emotion;

            me.autoHeight( contentBoxId );

            if ( !emotion.tabExist[ contentBoxId ] ) {

                emotion.tabExist[ contentBoxId ] = true;
                me.createTab( contentBoxId );

            }

        },
        autoHeight: function( ) {
            this.widget.height(this.root() + 2);
        },
        createTabList: function( tabNum ) {
            var obj = {};
            for ( var i = 0; i < tabNum; i++ ) {
                obj["edui-emotion-Jtab" + i] = [];
            }
            return obj;
        },
        mouseover: function( td, srcPath, posFlag ) {

            posFlag -= 0;

            $(td).css( 'backgroundColor', '#ACCD3C' );

            this.previewImg.css( "backgroundImage", "url(" + srcPath + ")" );
            posFlag && this.previewBox.addClass('edui-emotion-preview-left');
            this.previewBox.show();

        },
        mouseout: function( td ) {
            $(td).css( 'backgroundColor', 'transparent' );
            this.previewBox.removeClass('edui-emotion-preview-left').hide();
        },
        insertSmiley: function( url, evt ) {
            var obj = {
                src: url
            };
            obj._src = obj.src;
            editor.execCommand( 'insertimage', obj );
            if ( !evt.ctrlKey ) {
                // Close the preview and popup.
                this.previewBox.removeClass('edui-emotion-preview-left').hide();
                this.widget.edui().hide();
            }
        },
        createTab: function( contentBoxId ) {

            var faceVersion = "?v=1.1", // Preview image cache version.
                me = this,
                $contentBox = this.root().find("."+contentBoxId),
                emotion = me.sourceData.emotion,
                imagePath = emotion.SmileyPath + emotion.imageFolders[ contentBoxId ], // Path for the emoticon images and previews.
                positionLine = 11 / 2, // Column midpoint for choosing the preview side.
                iWidth = iHeight = 35, // Emoticon image width and height.
                iColWidth = 3, // Legacy spacing value.
                tableCss = emotion.imageCss[ contentBoxId ],
                cssOffset = emotion.imageCssOffset[ contentBoxId ],
                textHTML = ['<table border="1" class="edui-emotion-smileytable">'],
                i = 0, imgNum = emotion.SmileyBox[ contentBoxId ].length, imgColNum = 11, faceImage,
                sUrl, realUrl, posflag, offset, infor;

            for ( ; i < imgNum; ) {
                textHTML.push( '<tr>' );
                for ( var j = 0; j < imgColNum; j++, i++ ) {
                    faceImage = emotion.SmileyBox[ contentBoxId ][i];
                    if ( faceImage ) {
                        sUrl = imagePath + faceImage + faceVersion;
                        realUrl = imagePath + faceImage;
                        posflag = j < positionLine ? 0 : 1;
                        offset = cssOffset * i * (-1) - 1;
                        infor = emotion.SmileyInfor[ contentBoxId ][i];

                        textHTML.push( '<td  class="edui-emotion-' + tableCss + '" data-surl="'+ sUrl +'" data-realurl="'+ realUrl +'" data-posflag="'+ posflag +'" align="center">' );
                        textHTML.push( '<span>' );
                        textHTML.push( '<img  style="background-position:left ' + offset + 'px;" title="' + infor + '" src="' + emotion.SmileyPath + (editor.options.emotionLocalization ? '0.gif" width="' : 'default/0.gif" width="') + iWidth + '" height="' + iHeight + '"></img>' );
                        textHTML.push( '</span>' );
                    } else {
                        textHTML.push( '<td bgcolor="#FFFFFF">' );
                    }
                    textHTML.push( '</td>' );
                }
                textHTML.push( '</tr>' );
            }
            textHTML.push( '</table>' );
            textHTML = textHTML.join( "" );
            $contentBox.html( textHTML );
        },
        createArr: function( tabNum ) {
            var arr = [];
            for ( var i = 0; i < tabNum; i++ ) {
                arr[i] = 0;
            }
            return arr;
        },
        width:603,
        height:400
    });

})();

