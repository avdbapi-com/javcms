/*! WebUploader 0.1.2 */


/**
 * @fileOverview Organize internal component definitions using [AMD](https://github.com/amdjs/amdjs-api/wiki/AMD).
 *
 * This simplified internal AMD API is incomplete. It is included only when WebUploader is bundled into a single file.
 */
(function( root, factory ) {
    var modules = {},

        // Internal require: a simplified, incomplete implementation.
        // https://github.com/amdjs/amdjs-api/wiki/require
        _require = function( deps, callback ) {
            var args, len, i;

            // If deps is not an array, return the specified module directly.
            if ( typeof deps === 'string' ) {
                return getModule( deps );
            } else {
                args = [];
                for( len = deps.length, i = 0; i < len; i++ ) {
                    args.push( getModule( deps[ i ] ) );
                }

                return callback.apply( null, args );
            }
        },

        // Internal define: a module id is currently required.
        _define = function( id, deps, factory ) {
            if ( arguments.length === 2 ) {
                factory = deps;
                deps = null;
            }

            _require( deps || [], function() {
                setModule( id, factory, arguments );
            });
        },

        // Define the module with CommonJS compatibility.
        setModule = function( id, factory, args ) {
            var module = {
                    exports: factory
                },
                returned;

            if ( typeof factory === 'function' ) {
                args.length || (args = [ _require, module.exports, module ]);
                returned = factory.apply( null, args );
                returned !== undefined && (module.exports = returned);
            }

            modules[ id ] = module.exports;
        },

        // Get module by id
        getModule = function( id ) {
            var module = modules[ id ] || root[ id ];

            if ( !module ) {
                throw new Error( '`' + id + '` is undefined' );
            }

            return module;
        },

        // Export all modules, converting path ids into nested objects.
        exportsTo = function( obj ) {
            var key, host, parts, part, last, ucFirst;

            // make the first character upper case.
            ucFirst = function( str ) {
                return str && (str.charAt( 0 ).toUpperCase() + str.substr( 1 ));
            };

            for ( key in modules ) {
                host = obj;

                if ( !modules.hasOwnProperty( key ) ) {
                    continue;
                }

                parts = key.split('/');
                last = ucFirst( parts.pop() );

                while( (part = ucFirst( parts.shift() )) ) {
                    host[ part ] = host[ part ] || {};
                    host = host[ part ];
                }

                host[ last ] = modules[ key ];
            }
        },

        exports = factory( root, _define, _require ),
        origin;

    // exports every module.
    exportsTo( exports );

    if ( typeof module === 'object' && typeof module.exports === 'object' ) {

        // For CommonJS and CommonJS-like environments where a proper window is present,
        module.exports = exports;
    } else if ( typeof define === 'function' && define.amd ) {

        // Allow using this built library as an AMD module
        // in another project. That other project will only
        // see this AMD call, not the internal modules in
        // the closure below.
        define([], exports );
    } else {

        // Browser globals case. Just assign the
        // result to a property on the global.
        origin = root.WebUploader;
        root.WebUploader = exports;
        root.WebUploader.noConflict = function() {
            root.WebUploader = origin;
        };
    }
})( this, function( window, define, require ) {


    /**
     * @fileOverview jQuery or Zepto
     */
    define('dollar-third',[],function() {
        return window.jQuery || window.Zepto;
    });
    /**
     * @fileOverview DOM operations
     */
    define('dollar',[
        'dollar-third'
    ], function( _ ) {
        return _;
    });
    /**
     * @fileOverview Promises using jQuery
     */
    define('promise-third',[
        'dollar'
    ], function( $ ) {
        return {
            Deferred: $.Deferred,
            when: $.when,
    
            isPromise: function( anything ) {
                return anything && typeof anything.then === 'function';
            }
        };
    });
    /**
     * @fileOverview Promise/A+
     */
    define('promise',[
        'promise-third'
    ], function( _ ) {
        return _;
    });
    /**
     * @fileOverview Basic methods.
     */
    
    /**
     * Documentation for WebUploader internal classes. The classes below are accessible through `WebUploader`.
     *
     * Each WebUploader file uses the [AMD](https://github.com/amdjs/amdjs-api/wiki/AMD) `define` convention and has a module id.
     * By default, the module id is its file path, which becomes a namespace in WebUploader. For example:
     *
     * * module `base`: WebUploader.Base
     * * module `file`: WebUploader.File
     * * module `lib/dnd`: WebUploader.Lib.Dnd
     * * module `runtime/html5/dnd`: WebUploader.Runtime.Html5.Dnd
     *
     *
     * The documentation below generally omits the `WebUploader` prefix.
     * @module WebUploader
     * @title WebUploader API documentation
     */
    define('base',[
        'dollar',
        'promise'
    ], function( $, promise ) {
    
        var noop = function() {},
            call = Function.call;
    
        // http://jsperf.com/uncurrythis
        // Uncurry the function.
        function uncurryThis( fn ) {
            return function() {
                return call.apply( fn, arguments );
            };
        }
    
        function bindFn( fn, context ) {
            return function() {
                return fn.apply( context, arguments );
            };
        }
    
        function createObject( proto ) {
            var f;
    
            if ( Object.create ) {
                return Object.create( proto );
            } else {
                f = function() {};
                f.prototype = proto;
                return new f();
            }
        }
    
    
        /**
         * Base class providing simple, commonly used methods.
         * @class Base
         */
        return {
    
            /**
             * @property {String} version Current version number.
             */
            version: '0.1.2',
    
            /**
             * @property {jQuery|Zepto} $ Reference to the jQuery or Zepto dependency.
             */
            $: $,
    
            Deferred: promise.Deferred,
    
            isPromise: promise.isPromise,
    
            when: promise.when,
    
            /**
             * @description Simple browser detection results.
             *
             * * `webkit` WebKit version, or `undefined` for a browser without a WebKit engine.
             * * `chrome` Chrome version, or `undefined` if the browser is not Chrome.
             * * `ie` Internet Explorer version, or `undefined` if the browser is not Internet Explorer. **IE10+ is not supported yet.**
             * * `firefox` Firefox version, or `undefined` if the browser is not Firefox.
             * * `safari` Safari version, or `undefined` if the browser is not Safari.
             * * `opera` Opera version, or `undefined` if the browser is not Opera.
             *
             * @property {Object} [browser]
             */
            browser: (function( ua ) {
                var ret = {},
                    webkit = ua.match( /WebKit\/([\d.]+)/ ),
                    chrome = ua.match( /Chrome\/([\d.]+)/ ) ||
                        ua.match( /CriOS\/([\d.]+)/ ),
    
                    ie = ua.match( /MSIE\s([\d\.]+)/ ) ||
                        ua.match(/(?:trident)(?:.*rv:([\w.]+))?/i),
                    firefox = ua.match( /Firefox\/([\d.]+)/ ),
                    safari = ua.match( /Safari\/([\d.]+)/ ),
                    opera = ua.match( /OPR\/([\d.]+)/ );
    
                webkit && (ret.webkit = parseFloat( webkit[ 1 ] ));
                chrome && (ret.chrome = parseFloat( chrome[ 1 ] ));
                ie && (ret.ie = parseFloat( ie[ 1 ] ));
                firefox && (ret.firefox = parseFloat( firefox[ 1 ] ));
                safari && (ret.safari = parseFloat( safari[ 1 ] ));
                opera && (ret.opera = parseFloat( opera[ 1 ] ));
    
                return ret;
            })( navigator.userAgent ),
    
            /**
             * @description Operating system detection results.
             *
             * * `android` Android version in an Android browser, or `undefined` otherwise.
             * * `ios` iOS version in an iOS browser, or `undefined` otherwise.
             * @property {Object} [os]
             */
            os: (function( ua ) {
                var ret = {},
    
                    // osx = !!ua.match( /\(Macintosh\; Intel / ),
                    android = ua.match( /(?:Android);?[\s\/]+([\d.]+)?/ ),
                    ios = ua.match( /(?:iPad|iPod|iPhone).*OS\s([\d_]+)/ );
    
                // osx && (ret.osx = true);
                android && (ret.android = parseFloat( android[ 1 ] ));
                ios && (ret.ios = parseFloat( ios[ 1 ].replace( /_/g, '.' ) ));
    
                return ret;
            })( navigator.userAgent ),
    
            /**
             * Implement class inheritance.
             * @method inherits
             * @grammar Base.inherits( super ) => child
             * @grammar Base.inherits( super, protos ) => child
             * @grammar Base.inherits( super, protos, statics ) => child
             * @param  {Class} super parent class
             * @param {Object | Function} [protos] Subclass constructor or prototype object. If the object defines a constructor, the subclass uses it.
             * @param {Function} [protos.constructor] Subclass constructor. If omitted, a constructor that calls the parent constructor is created.
             * @param {Object} [statics] Static properties or methods.
             * @return {Class} The subclass.
             * @example
             * function Person() {
             *     console.log( 'Super' );
             * }
             * Person.prototype.hello = function() {
             *     console.log( 'hello' );
             * };
             *
             * var Manager = Base.inherits( Person, {
             *     world: function() {
             *         console.log( 'World' );
             *     }
             * });
             *
             * // No constructor was specified, so the parent constructor runs.
             * var instance = new Manager();    // => Super
             *
             * // The subclass inherits the parent methods.
             * instance.hello();    // => hello
             * instance.world();    // => World
             *
             * // The child class's __super__ attribute points to the parent class
             * console.log( Manager.__super__ === Person );    // => true
             */
            inherits: function( Super, protos, staticProtos ) {
                var child;
    
                if ( typeof protos === 'function' ) {
                    child = protos;
                    protos = null;
                } else if ( protos && protos.hasOwnProperty('constructor') ) {
                    child = protos.constructor;
                } else {
                    child = function() {
                        return Super.apply( this, arguments );
                    };
                }
    
                // Copy static methods.
                $.extend( true, child, Super, staticProtos || {} );
    
                /* jshint camelcase: false */
    
                // Point the subclass __super__ property to the parent prototype.
                child.__super__ = Super.prototype;
    
                // Build the prototype and add prototype methods or properties.
                // Use the Object.create implementation above.
                child.prototype = createObject( Super.prototype );
                protos && $.extend( true, child.prototype, protos );
    
                return child;
            },
    
            /**
             * A function that does nothing, suitable as a default callback.
             * @method noop
             */
            noop: noop,
    
            /**
             * Return a function that runs with the specified `context`.
             * @grammar Base.bindFn( fn, context ) => Function
             * @method bindFn
             * @example
             * var doSomething = function() {
             *         console.log( this.name );
             *     },
             *     obj = {
             *         name: 'Object Name'
             *     },
             *     aliasFn = Base.bind( doSomething, obj );
             *
             *  aliasFn();    // => Object Name
             *
             */
            bindFn: bindFn,
    
            /**
             * Use console.log if available; otherwise use a [no-op function](#WebUploader:Base.log).
             * @grammar Base.log( args... ) => undefined
             * @method log
             */
            log: (function() {
                if ( window.console ) {
                    return bindFn( console.log, console );
                }
                return noop;
            })(),
    
            nextTick: (function() {
    
                return function( cb ) {
                    setTimeout( cb, 1 );
                };
    
                // @bug The browser pauses callbacks when the window is inactive.
                // var next = window.requestAnimationFrame ||
                //     window.webkitRequestAnimationFrame ||
                //     window.mozRequestAnimationFrame ||
                //     function( cb ) {
                //         window.setTimeout( cb, 1000 / 60 );
                //     };
    
                // // fix: Uncaught TypeError: Illegal invocation
                // return bindFn( next, window );
            })(),
    
            /**
             * The array slice method adapted with [uncurrythis](http://www.2ality.com/2011/11/uncurrying-this.html).
             * Convert array-like objects into arrays.
             * @grammar Base.slice( target, start[, end] ) => Array
             * @method slice
             * @example
             * function doSomthing() {
             *     var args = Base.slice( arguments, 1 );
             *     console.log( args );
             * }
             *
             * doSomthing( 'ignored', 'arg2', 'arg3' );    // => Array ["arg2", "arg3"]
             */
            slice: uncurryThis( [].slice ),
    
            /**
             * Generating a unique ID
             * @method guid
             * @grammar Base.guid() => String
             * @grammar Base.guid( prefx ) => String
             */
            guid: (function() {
                var counter = 0;
    
                return function( prefix ) {
                    var guid = (+new Date()).toString( 32 ),
                        i = 0;
    
                    for ( ; i < 5; i++ ) {
                        guid += Math.floor( Math.random() * 65535 ).toString( 32 );
                    }
    
                    return (prefix || 'wu_') + guid + (counter++).toString( 32 );
                };
            })(),
    
            /**
             * Format a file size as a string with units.
             * @method formatSize
             * @grammar Base.formatSize( size ) => String
             * @grammar Base.formatSize( size, pointLength ) => String
             * @grammar Base.formatSize( size, pointLength, units ) => String
             * @param {Number} size file size
             * @param {Number} [pointLength=2] Number of decimal places.
             * @param {Array} [units=[ 'B', 'K', 'M', 'G', 'TB' ]] Units in ascending order, starting with bytes. If K (kilobytes) is the highest specified unit, larger sizes are still expressed in K.
             * @example
             * console.log( Base.formatSize( 100 ) );    // => 100B
             * console.log( Base.formatSize( 1024 ) );    // => 1.00K
             * console.log( Base.formatSize( 1024, 0 ) );    // => 1K
             * console.log( Base.formatSize( 1024 * 1024 ) );    // => 1.00M
             * console.log( Base.formatSize( 1024 * 1024 * 1024 ) );    // => 1.00G
             * console.log( Base.formatSize( 1024 * 1024 * 1024, 0, ['B', 'KB', 'MB'] ) );    // => 1024MB
             */
            formatSize: function( size, pointLength, units ) {
                var unit;
    
                units = units || [ 'B', 'K', 'M', 'G', 'TB' ];
    
                while ( (unit = units.shift()) && size > 1024 ) {
                    size = size / 1024;
                }
    
                return (unit === 'B' ? size : size.toFixed( pointLength || 2 )) +
                        unit;
            }
        };
    });
    /**
     * Event handling class usable independently or mixed into other objects.
     * @fileOverview Mediator
     */
    define('mediator',[
        'base'
    ], function( Base ) {
        var $ = Base.$,
            slice = [].slice,
            separator = /\s+/,
            protos;
    
        // Filter event handlers based on conditions.
        function findHandlers( arr, name, callback, context ) {
            return $.grep( arr, function( handler ) {
                return handler &&
                        (!name || handler.e === name) &&
                        (!callback || handler.cb === callback ||
                        handler.cb._cb === callback) &&
                        (!context || handler.ctx === context);
            });
        }
    
        function eachEvent( events, callback, iterator ) {
            // Object syntax is unsupported; use space-separated event names.
            $.each( (events || '').split( separator ), function( _, key ) {
                iterator( key, callback );
            });
        }
    
        function triggerHanders( events, args ) {
            var stoped = false,
                i = -1,
                len = events.length,
                handler;
    
            while ( ++i < len ) {
                handler = events[ i ];
    
                if ( handler.cb.apply( handler.ctx2, args ) === false ) {
                    stoped = true;
                    break;
                }
            }
    
            return !stoped;
        }
    
        protos = {
    
            /**
             * Bind events.
             *
             * The `callback` receives the arguments supplied to trigger. For example:
             * ```javascript
             * var obj = {};
             *
             * // Give obj event handling methods.
             * Mediator.installTo( obj );
             *
             * obj.on( 'testa', function( arg1, arg2 ) {
             *     console.log( arg1, arg2 ); // => 'arg1', 'arg2'
             * });
             *
             * obj.trigger( 'testa', 'arg1', 'arg2' );
             * ```
             *
             * If a `callback` returns false, subsequent callbacks are not executed.
             * In that case, `trigger` also returns false.
             *
             * The special `all` event receives every event. Its callback arguments differ:
             * The first argument is the event `type`. These callbacks run after the regular event callbacks.
             * ```javascript
             * obj.on( 'all', function( type, arg1, arg2 ) {
             *     console.log( type, arg1, arg2 ); // => 'testa', 'arg1', 'arg2'
             * });
             * ```
             *
             * @method on
             * @grammar on( name, callback[, context] ) => self
             * @param {String} name Event name; multiple names may be separated by spaces.
             * @param  {Function} callback event handler
             * @param {Object} [context] Context for the event handler.
             * @return {self} This object, for chaining.
             * @chainable
             * @class Mediator
             */
            on: function( name, callback, context ) {
                var me = this,
                    set;
    
                if ( !callback ) {
                    return this;
                }
    
                set = this._events || (this._events = []);
    
                eachEvent( name, callback, function( name, callback ) {
                    var handler = { e: name };
    
                    handler.cb = callback;
                    handler.ctx = context;
                    handler.ctx2 = context || me;
                    handler.id = set.length;
    
                    set.push( handler );
                });
    
                return this;
            },
    
            /**
             * Bind an event and automatically unbind it after the handler runs.
             * @method once
             * @grammar once( name, callback[, context] ) => self
             * @param {String} name Event name; multiple names may be separated by spaces.
             * @param  {Function} callback event handler
             * @param {Object} [context] Context for the event handler.
             * @return {self} This object, for chaining.
             * @chainable
             */
            once: function( name, callback, context ) {
                var me = this;
    
                if ( !callback ) {
                    return me;
                }
    
                eachEvent( name, callback, function( name, callback ) {
                    var once = function() {
                            me.off( name, once );
                            return callback.apply( context || me, arguments );
                        };
    
                    once._cb = callback;
                    me.on( name, once, context );
                });
    
                return me;
            },
    
            /**
             * Unbind an event
             * @method off
             * @grammar off( [name[, callback[, context] ] ] ) => self
             * @param {String} [name] Event name.
             * @param  {Function} [callback] event handler
             * @param {Object} [context] Context for the event handler.
             * @return {self} This object, for chaining.
             * @chainable
             */
            off: function( name, cb, ctx ) {
                var events = this._events;
    
                if ( !events ) {
                    return this;
                }
    
                if ( !name && !cb && !ctx ) {
                    this._events = [];
                    return this;
                }
    
                eachEvent( name, cb, function( name, cb ) {
                    $.each( findHandlers( events, name, cb, ctx ), function() {
                        delete events[ this.id ];
                    });
                });
    
                return this;
            },
    
            /**
             * Trigger events
             * @method trigger
             * @grammar trigger( name[, args...] ) => self
             * @param {String} type Event name.
             * @param  {*} [...] Any parameter
             * @return {Boolean} False if a handler returns false; otherwise true.
             */
            trigger: function( type ) {
                var args, events, allEvents;
    
                if ( !this._events || !type ) {
                    return this;
                }
    
                args = slice.call( arguments, 1 );
                events = findHandlers( this._events, type );
                allEvents = findHandlers( this._events, 'all' );
    
                return triggerHanders( events, args ) &&
                        triggerHanders( allEvents, arguments );
            }
        };
    
        /**
         * Singleton mediator. Use [installTo](#WebUploader:Mediator:installTo) to add event behavior to any object.
         * Coordinate modules while reducing coupling.
         *
         * @class Mediator
         */
        return $.extend({
    
            /**
             * Add event behavior to any object.
             * @method installTo
             * @param {Object} obj Object to receive event behavior.
             * @return {Object} The supplied obj.
             */
            installTo: function( obj ) {
                return $.extend( obj, protos );
            }
    
        }, protos );
    });
    /**
     * @fileOverview Uploader upload class
     */
    define('uploader',[
        'base',
        'mediator'
    ], function( Base, Mediator ) {
    
        var $ = Base.$;
    
        /**
         * Main uploader class.
         * @class Uploader
         * @constructor
         * @grammar new Uploader( opts ) => Uploader
         * @example
         * var uploader = WebUploader.Uploader({
         *     swf: 'path_of_swf/Uploader.swf',
         *
         *     // Enable chunked uploads.
         *     chunked: true
         * });
         */
        function Uploader( opts ) {
            this.options = $.extend( true, {}, Uploader.options, opts );
            this._init( this.options );
        }
    
        // Default options.
        // Widgets extend these options.
        Uploader.options = {};
        Mediator.installTo( Uploader.prototype );
    
        // Add methods that forward commands.
        $.each({
            upload: 'start-upload',
            stop: 'stop-upload',
            getFile: 'get-file',
            getFiles: 'get-files',
            addFile: 'add-file',
            addFiles: 'add-file',
            sort: 'sort-files',
            removeFile: 'remove-file',
            skipFile: 'skip-file',
            retry: 'retry',
            isInProgress: 'is-in-progress',
            makeThumb: 'make-thumb',
            getDimension: 'get-dimension',
            addButton: 'add-btn',
            getRuntimeType: 'get-runtime-type',
            refresh: 'refresh',
            disable: 'disable',
            enable: 'enable',
            reset: 'reset'
        }, function( fn, command ) {
            Uploader.prototype[ fn ] = function() {
                return this.request( command, arguments );
            };
        });
    
        $.extend( Uploader.prototype, {
            state: 'pending',
    
            _init: function( opts ) {
                var me = this;
    
                me.request( 'init', opts, function() {
                    me.state = 'ready';
                    me.trigger('ready');
                });
            },
    
            /**
             * Get or set uploader configuration options.
             * @method option
             * @grammar option( key ) => *
             * @grammar option( key, val ) => self
             * @example
             *
             * // Initially, images are not compressed before upload.
             * var uploader = new WebUploader.Uploader({
             *     resize: null;
             * });
             *
             * // Update the options to resize images to fit 1600 * 1600 before upload.
             * uploader.options( 'resize', {
             *     width: 1600,
             *     height: 1600
             * });
             */
            option: function( key, val ) {
                var opts = this.options;
    
                // setter
                if ( arguments.length > 1 ) {
    
                    if ( $.isPlainObject( val ) &&
                            $.isPlainObject( opts[ key ] ) ) {
                        $.extend( opts[ key ], val );
                    } else {
                        opts[ key ] = val;
                    }
    
                } else {    // getter
                    return key ? opts[ key ] : opts;
                }
            },
    
            /**
             * Get an object containing the following file statistics.
             * * `successNum` Number of successfully uploaded files
             * * `uploadFailNum` Number of files that failed to be uploaded
             * * `cancelNum` Number of deleted files
             * * `invalidNum` Number of invalid files
             * * `queueNum` The number of files still in the queue
             * @method getStats
             * @grammar getStats() => Object
             */
            getStats: function() {
                // return this._mgr.getStats.apply( this._mgr, arguments );
                var stats = this.request('get-stats');
    
                return {
                    successNum: stats.numOfSuccess,
    
                    // Optional queue failure count.
                    // queueFailNum: 0,
                    cancelNum: stats.numOfCancel,
                    invalidNum: stats.numOfInvalid,
                    uploadFailNum: stats.numOfUploadFailed,
                    queueNum: stats.numOfQueue
                };
            },
    
            // Override this method to support opts.onEvent and instance.onEvent handlers.
            trigger: function( type/*, args...*/ ) {
                var args = [].slice.call( arguments, 1 ),
                    opts = this.options,
                    name = 'on' + type.substring( 0, 1 ).toUpperCase() +
                        type.substring( 1 );
    
                if (
                        // Call a handler registered through the on method.
                        Mediator.trigger.apply( this, arguments ) === false ||
    
                        // Call opts.onEvent
                        $.isFunction( opts[ name ] ) &&
                        opts[ name ].apply( this, args ) === false ||
    
                        // Invoke this.onEvent
                        $.isFunction( this[ name ] ) &&
                        this[ name ].apply( this, args ) === false ||
    
                        // Broadcast events to all uploaders.
                        Mediator.trigger.apply( Mediator,
                        [ this, type ].concat( args ) ) === false ) {
    
                    return false;
                }
    
                return true;
            },
    
            // widgets/widget.js provides detailed documentation for this method.
            request: Base.noop
        });
    
        /**
         * Create an Uploader instance, equivalent to new Uploader( opts ).
         * @method create
         * @class Base
         * @static
         * @grammar Base.create( opts ) => Uploader
         */
        Base.create = Uploader.create = function( opts ) {
            return new Uploader( opts );
        };
    
        // Expose Uploader for application-specific extensions.
        Base.Uploader = Uploader;
    
        return Uploader;
    });
    /**
     * @fileOverview Runtime management: select and connect runtimes.
     */
    define('runtime/runtime',[
        'base',
        'mediator'
    ], function( Base, Mediator ) {
    
        var $ = Base.$,
            factories = {},
    
            // Get the first key of an object
            getFirstKey = function( obj ) {
                for ( var key in obj ) {
                    if ( obj.hasOwnProperty( key ) ) {
                        return key;
                    }
                }
                return null;
            };
    
        // Runtime interface class.
        function Runtime( options ) {
            this.options = $.extend({
                container: document.body
            }, options );
            this.uid = Base.guid('rt_');
        }
    
        $.extend( Runtime.prototype, {
    
            getContainer: function() {
                var opts = this.options,
                    parent, container;
    
                if ( this._container ) {
                    return this._container;
                }
    
                parent = $( opts.container || document.body );
                container = $( document.createElement('div') );
    
                container.attr( 'id', 'rt_' + this.uid );
                container.css({
                    position: 'absolute',
                    top: '0px',
                    left: '0px',
                    width: '1px',
                    height: '1px',
                    overflow: 'hidden'
                });
    
                parent.append( container );
                parent.addClass('webuploader-container');
                this._container = container;
                return container;
            },
    
            init: Base.noop,
            exec: Base.noop,
    
            destroy: function() {
                if ( this._container ) {
                    this._container.parentNode.removeChild( this.__container );
                }
    
                this.off();
            }
        });
    
        Runtime.orders = 'html5,flash';
    
    
        /**
         * Add a runtime implementation.
         * @param {String} type    types
         * @param {Runtime} factory Runtime implementation.
         */
        Runtime.addRuntime = function( type, factory ) {
            factories[ type ] = factory;
        };
    
        Runtime.hasRuntime = function( type ) {
            return !!(type ? factories[ type ] : getFirstKey( factories ));
        };
    
        Runtime.create = function( opts, orders ) {
            var type, runtime;
    
            orders = orders || Runtime.orders;
            $.each( orders.split( /\s*,\s*/g ), function() {
                if ( factories[ this ] ) {
                    type = this;
                    return false;
                }
            });
    
            type = type || getFirstKey( factories );
    
            if ( !type ) {
                throw new Error('Runtime Error');
            }
    
            runtime = new factories[ type ]( opts );
            return runtime;
        };
    
        Mediator.installTo( Runtime.prototype );
        return Runtime;
    });
    
    /**
     * @fileOverview Runtime management: select and connect runtimes.
     */
    define('runtime/client',[
        'base',
        'mediator',
        'runtime/runtime'
    ], function( Base, Mediator, Runtime ) {
    
        var cache;
    
        cache = (function() {
            var obj = {};
    
            return {
                add: function( runtime ) {
                    obj[ runtime.uid ] = runtime;
                },
    
                get: function( ruid, standalone ) {
                    var i;
    
                    if ( ruid ) {
                        return obj[ ruid ];
                    }
    
                    for ( i in obj ) {
                        // Some components, such as Filepicker, require a separate runtime.
                        if ( standalone && obj[ i ].__standalone ) {
                            continue;
                        }
    
                        return obj[ i ];
                    }
    
                    return null;
                },
    
                remove: function( runtime ) {
                    delete obj[ runtime.uid ];
                }
            };
        })();
    
        function RuntimeClient( component, standalone ) {
            var deferred = Base.Deferred(),
                runtime;
    
            this.uid = Base.guid('client_');
    
            // Register callbacks to run once the runtime is initialized.
            this.runtimeReady = function( cb ) {
                return deferred.done( cb );
            };
    
            this.connectRuntime = function( opts, cb ) {
    
                // already connected.
                if ( runtime ) {
                    throw new Error('already connected!');
                }
    
                deferred.done( cb );
    
                if ( typeof opts === 'string' && cache.get( opts ) ) {
                    runtime = cache.get( opts );
                }
    
                // Components such as FilePicker require a standalone runtime that cannot be shared.
                runtime = runtime || cache.get( null, standalone );
    
                // needs to be created
                if ( !runtime ) {
                    runtime = Runtime.create( opts, opts.runtimeOrder );
                    runtime.__promise = deferred.promise();
                    runtime.once( 'ready', deferred.resolve );
                    runtime.init();
                    cache.add( runtime );
                    runtime.__client = 1;
                } else {
                    // from cache
                    Base.$.extend( runtime.options, opts );
                    runtime.__promise.then( deferred.resolve );
                    runtime.__client++;
                }
    
                standalone && (runtime.__standalone = standalone);
                return runtime;
            };
    
            this.getRuntime = function() {
                return runtime;
            };
    
            this.disconnectRuntime = function() {
                if ( !runtime ) {
                    return;
                }
    
                runtime.__client--;
    
                if ( runtime.__client <= 0 ) {
                    cache.remove( runtime );
                    delete runtime.__promise;
                    runtime.destroy();
                }
    
                runtime = null;
            };
    
            this.exec = function() {
                if ( !runtime ) {
                    return;
                }
    
                var args = Base.slice( arguments );
                component && args.unshift( component );
    
                return runtime.exec.apply( this, args );
            };
    
            this.getRuid = function() {
                return runtime && runtime.uid;
            };
    
            this.destroy = (function( destroy ) {
                return function() {
                    destroy && destroy.apply( this, arguments );
                    this.trigger('destroy');
                    this.off();
                    this.exec('destroy');
                    this.disconnectRuntime();
                };
            })( this.destroy );
        }
    
        Mediator.installTo( RuntimeClient.prototype );
        return RuntimeClient;
    });
    /**
     * @fileOverview Drag-and-drop file input.
     */
    define('lib/dnd',[
        'base',
        'mediator',
        'runtime/client'
    ], function( Base, Mediator, RuntimeClent ) {
    
        var $ = Base.$;
    
        function DragAndDrop( opts ) {
            opts = this.options = $.extend({}, DragAndDrop.options, opts );
    
            opts.container = $( opts.container );
    
            if ( !opts.container.length ) {
                return;
            }
    
            RuntimeClent.call( this, 'DragAndDrop' );
        }
    
        DragAndDrop.options = {
            accept: null,
            disableGlobalDnd: false
        };
    
        Base.inherits( RuntimeClent, {
            constructor: DragAndDrop,
    
            init: function() {
                var me = this;
    
                me.connectRuntime( me.options, function() {
                    me.exec('init');
                    me.trigger('ready');
                });
            },
    
            destroy: function() {
                this.disconnectRuntime();
            }
        });
    
        Mediator.installTo( DragAndDrop.prototype );
    
        return DragAndDrop;
    });
    /**
     * @fileOverview Component base classes.
     */
    define('widgets/widget',[
        'base',
        'uploader'
    ], function( Base, Uploader ) {
    
        var $ = Base.$,
            _init = Uploader.prototype._init,
            IGNORE = {},
            widgetClass = [];
    
        function isArrayLike( obj ) {
            if ( !obj ) {
                return false;
            }
    
            var length = obj.length,
                type = $.type( obj );
    
            if ( obj.nodeType === 1 && length ) {
                return true;
            }
    
            return type === 'array' || type !== 'function' && type !== 'string' &&
                    (length === 0 || typeof length === 'number' && length > 0 &&
                    (length - 1) in obj);
        }
    
        function Widget( uploader ) {
            this.owner = uploader;
            this.options = uploader.options;
        }
    
        $.extend( Widget.prototype, {
    
            init: Base.noop,
    
            // Declare event listeners on the uploader instance, as in Backbone.
            // Widgets receive events through the uploader rather than listening directly.
            invoke: function( apiName, args ) {
    
                /*
                    {
                        'make-thumb': 'makeThumb'
                    }
                 */
                var map = this.responseMap;
    
                // Ignore commands without a corresponding API handler.
                if ( !map || !(apiName in map) || !(map[ apiName ] in this) ||
                        !$.isFunction( this[ map[ apiName ] ] ) ) {
    
                    return IGNORE;
                }
    
                return this[ map[ apiName ] ].apply( this, args );
    
            },
    
            /**
             * Send a command. If a callback is supplied or a handler returns a promise, return a promise that resolves after all handler promises resolve.
             * @method request
             * @grammar request( command, args ) => * | Promise
             * @grammar request( command, args, callback ) => Promise
             * @for  Uploader
             */
            request: function() {
                return this.owner.request.apply( this.owner, arguments );
            }
        });
    
        // Extending the Uploader.
        $.extend( Uploader.prototype, {
    
            // Override _init to initialize widgets
            _init: function() {
                var me = this,
                    widgets = me._widgets = [];
    
                $.each( widgetClass, function( _, klass ) {
                    widgets.push( new klass( me ) );
                });
    
                return _init.apply( me, arguments );
            },
    
            request: function( apiName, args, callback ) {
                var i = 0,
                    widgets = this._widgets,
                    len = widgets.length,
                    rlts = [],
                    dfds = [],
                    widget, rlt, promise, key;
    
                args = isArrayLike( args ) ? args : [ args ];
    
                for ( ; i < len; i++ ) {
                    widget = widgets[ i ];
                    rlt = widget.invoke( apiName, args );
    
                    if ( rlt !== IGNORE ) {
    
                        // Deferred object
                        if ( Base.isPromise( rlt ) ) {
                            dfds.push( rlt );
                        } else {
                            rlts.push( rlt );
                        }
                    }
                }
    
                // Use asynchronous processing if a callback or promise is present.
                if ( callback || dfds.length ) {
                    promise = Base.when.apply( Base, dfds );
                    key = promise.pipe ? 'pipe' : 'then';
    
                    // Keep this asynchronous step to prevent an infinite loop.
                    // Preserve execution order by running the callback on the next tick.
                    return promise[ key ](function() {
                                var deferred = Base.Deferred(),
                                    args = arguments;
    
                                setTimeout(function() {
                                    deferred.resolve.apply( deferred, args );
                                }, 1 );
    
                                return deferred.promise();
                            })[ key ]( callback || Base.noop );
                } else {
                    return rlts[ 0 ];
                }
            }
        });
    
        /**
         * Adding components
         * @param {object} widgetProto Component prototype; define the constructor through its constructor property.
         * @param  {object} responseMap Mapping of API names to function implementations
         * @example
         *     Uploader.register( {
         *         init: function( options ) {},
         *         makeThumb: function() {}
         *     }, {
         *         'make-thumb': 'makeThumb'
         *     } );
         */
        Uploader.register = Widget.register = function( responseMap, widgetProto ) {
            var map = { init: 'init' },
                klass;
    
            if ( arguments.length === 1 ) {
                widgetProto = responseMap;
                widgetProto.responseMap = map;
            } else {
                widgetProto.responseMap = $.extend( map, responseMap );
            }
    
            klass = Base.inherits( Widget, widgetProto );
            widgetClass.push( klass );
    
            return klass;
        };
    
        return Widget;
    });
    /**
     * @fileOverview DragAndDrop Widget。
     */
    define('widgets/filednd',[
        'base',
        'uploader',
        'lib/dnd',
        'widgets/widget'
    ], function( Base, Uploader, Dnd ) {
        var $ = Base.$;
    
        Uploader.options.dnd = '';
    
        /**
         * @property {Selector} [dnd=undefined] Specify the drag-and-drop container. Leave unset to disable drag-and-drop.
         * @namespace options
         * @for Uploader
         */
    
        /**
         * @event dndAccept
         * @param {DataTransferItemList} items DataTransferItem
         * @description Reject this event to prevent certain file types from being dropped. Currently only chrome provides this API, and it only validates mime-type.
         * @for  Uploader
         */
        return Uploader.register({
            init: function( opts ) {
    
                if ( !opts.dnd ||
                        this.request('predict-runtime-type') !== 'html5' ) {
                    return;
                }
    
                var me = this,
                    deferred = Base.Deferred(),
                    options = $.extend({}, {
                        disableGlobalDnd: opts.disableGlobalDnd,
                        container: opts.dnd,
                        accept: opts.accept
                    }),
                    dnd;
    
                dnd = new Dnd( options );
    
                dnd.once( 'ready', deferred.resolve );
                dnd.on( 'drop', function( files ) {
                    me.request( 'add-file', [ files ]);
                });
    
                // Check if all files are allowed to be added。
                dnd.on( 'accept', function( items ) {
                    return me.owner.trigger( 'dndAccept', items );
                });
    
                dnd.init();
    
                return deferred.promise();
            }
        });
    });
    
    /**
     * @fileOverview Pasted file input.
     */
    define('lib/filepaste',[
        'base',
        'mediator',
        'runtime/client'
    ], function( Base, Mediator, RuntimeClent ) {
    
        var $ = Base.$;
    
        function FilePaste( opts ) {
            opts = this.options = $.extend({}, opts );
            opts.container = $( opts.container || document.body );
            RuntimeClent.call( this, 'FilePaste' );
        }
    
        Base.inherits( RuntimeClent, {
            constructor: FilePaste,
    
            init: function() {
                var me = this;
    
                me.connectRuntime( me.options, function() {
                    me.exec('init');
                    me.trigger('ready');
                });
            },
    
            destroy: function() {
                this.exec('destroy');
                this.disconnectRuntime();
                this.off();
            }
        });
    
        Mediator.installTo( FilePaste.prototype );
    
        return FilePaste;
    });
    /**
     * @fileOverview Component base classes.
     */
    define('widgets/filepaste',[
        'base',
        'uploader',
        'lib/filepaste',
        'widgets/widget'
    ], function( Base, Uploader, FilePaste ) {
        var $ = Base.$;
    
        /**
         * @property {Selector} [paste=undefined] Specify the container for paste events. Leave unset to disable screenshot pasting. The recommended container is `document.body`.
         * @namespace options
         * @for Uploader
         */
        return Uploader.register({
            init: function( opts ) {
    
                if ( !opts.paste ||
                        this.request('predict-runtime-type') !== 'html5' ) {
                    return;
                }
    
                var me = this,
                    deferred = Base.Deferred(),
                    options = $.extend({}, {
                        container: opts.paste,
                        accept: opts.accept
                    }),
                    paste;
    
                paste = new FilePaste( options );
    
                paste.once( 'ready', deferred.resolve );
                paste.on( 'paste', function( files ) {
                    me.owner.request( 'add-file', [ files ]);
                });
                paste.init();
    
                return deferred.promise();
            }
        });
    });
    /**
     * @fileOverview Blob
     */
    define('lib/blob',[
        'base',
        'runtime/client'
    ], function( Base, RuntimeClient ) {
    
        function Blob( ruid, source ) {
            var me = this;
    
            me.source = source;
            me.ruid = ruid;
    
            RuntimeClient.call( me, 'Blob' );
    
            this.uid = source.uid || this.uid;
            this.type = source.type || '';
            this.size = source.size || 0;
    
            if ( ruid ) {
                me.connectRuntime( ruid );
            }
        }
    
        Base.inherits( RuntimeClient, {
            constructor: Blob,
    
            slice: function( start, end ) {
                return this.exec( 'slice', start, end );
            },
    
            getSource: function() {
                return this.source;
            }
        });
    
        return Blob;
    });
    /**
     * Provide a common interface for Flash and HTML5 files.
     * Access Flash files through the same interface as HTML5 files.
     * @fileOverview File
     */
    define('lib/file',[
        'base',
        'lib/blob'
    ], function( Base, Blob ) {
    
        var uid = 1,
            rExt = /\.([^.]+)$/;
    
        function File( ruid, file ) {
            var ext;
    
            Blob.apply( this, arguments );
            this.name = file.name || ('untitled' + uid++);
            ext = rExt.exec( file.name ) ? RegExp.$1.toLowerCase() : '';
    
            // TODO: Support other file types.
    
            // Infer the extension from the MIME type if the filename has no extension.
            if ( !ext && this.type ) {
                ext = /\/(jpg|jpeg|png|gif|bmp)$/i.exec( this.type ) ?
                        RegExp.$1.toLowerCase() : '';
                this.name += '.' + ext;
            }
    
            // Infer the MIME type from the known file extension.
            if ( !this.type &&  ~'jpg,jpeg,png,gif,bmp'.indexOf( ext ) ) {
                this.type = 'image/' + (ext === 'jpg' ? 'jpeg' : ext);
            }
    
            this.ext = ext;
            this.lastModifiedDate = file.lastModifiedDate ||
                    (new Date()).toLocaleString();
        }
    
        return Base.inherits( Blob, File );
    });
    
    /**
     * @fileOverview File selection.
     */
    define('lib/filepicker',[
        'base',
        'runtime/client',
        'lib/file'
    ], function( Base, RuntimeClent, File ) {
    
        var $ = Base.$;
    
        function FilePicker( opts ) {
            opts = this.options = $.extend({}, FilePicker.options, opts );
    
            opts.container = $( opts.id );
    
            if ( !opts.container.length ) {
                throw new Error('Button specification error');
            }
    
            opts.innerHTML = opts.innerHTML || opts.label ||
                    opts.container.html() || '';
    
            opts.button = $( opts.button || document.createElement('div') );
            opts.button.html( opts.innerHTML );
            opts.container.html( opts.button );
    
            RuntimeClent.call( this, 'FilePicker', true );
        }
    
        FilePicker.options = {
            button: null,
            container: null,
            label: null,
            innerHTML: null,
            multiple: true,
            accept: null,
            name: 'file'
        };
    
        Base.inherits( RuntimeClent, {
            constructor: FilePicker,
    
            init: function() {
                var me = this,
                    opts = me.options,
                    button = opts.button;
    
                button.addClass('webuploader-pick');
    
                me.on( 'all', function( type ) {
                    var files;
    
                    switch ( type ) {
                        case 'mouseenter':
                            button.addClass('webuploader-pick-hover');
                            break;
    
                        case 'mouseleave':
                            button.removeClass('webuploader-pick-hover');
                            break;
    
                        case 'change':
                            files = me.exec('getFiles');
                            me.trigger( 'select', $.map( files, function( file ) {
                                file = new File( me.getRuid(), file );
    
                                // Record the file selection source.
                                file._refer = opts.container;
                                return file;
                            }), opts.container );
                            break;
                    }
                });
    
                me.connectRuntime( opts, function() {
                    me.refresh();
                    me.exec( 'init', opts );
                    me.trigger('ready');
                });
    
                $( window ).on( 'resize', function() {
                    me.refresh();
                });
            },
    
            refresh: function() {
                var shimContainer = this.getRuntime().getContainer(),
                    button = this.options.button,
                    width = button.outerWidth ?
                            button.outerWidth() : button.width(),
    
                    height = button.outerHeight ?
                            button.outerHeight() : button.height(),
    
                    pos = button.offset();
    
                width && height && shimContainer.css({
                    bottom: 'auto',
                    right: 'auto',
                    width: width + 'px',
                    height: height + 'px'
                }).offset( pos );
            },
    
            enable: function() {
                var btn = this.options.button;
    
                btn.removeClass('webuploader-pick-disable');
                this.refresh();
            },
    
            disable: function() {
                var btn = this.options.button;
    
                this.getRuntime().getContainer().css({
                    top: '-99999px'
                });
    
                btn.addClass('webuploader-pick-disable');
            },
    
            destroy: function() {
                if ( this.runtime ) {
                    this.exec('destroy');
                    this.disconnectRuntime();
                }
            }
        });
    
        return FilePicker;
    });
    
    /**
     * @fileOverview File selection related
     */
    define('widgets/filepicker',[
        'base',
        'uploader',
        'lib/filepicker',
        'widgets/widget'
    ], function( Base, Uploader, FilePicker ) {
        var $ = Base.$;
    
        $.extend( Uploader.options, {
    
            /**
             * @property {Selector | Object} [pick=undefined]
             * @namespace options
             * @for Uploader
             * @description Specify the container for a file selection button. No button is created if omitted.
             *
             * * `id` {Seletor} Container for a file selection button. No button is created if omitted.
             * * `label` {String} Use `innerHTML` instead.
             * * `innerHTML` {String} Button text. If omitted, use the existing container text.
             * * `multiple` {Boolean} Allow selection of multiple files at once.
             */
            pick: null,
    
            /**
             * @property {Arroy} [accept=null]
             * @namespace options
             * @for Uploader
             * @description Specify accepted file types. There is currently no extension-to-MIME-type mapping, so specify both separately.
             *
             * * `title` {String} Text description
             * * `extensions` {String} Allowed file extensions without dots, separated by commas.
             * * `mimeTypes` {String} MIME types, separated by commas.
             *
             * For example:
             *
             * ```
             * {
             *     title: 'Images',
             *     extensions: 'gif,jpg,jpeg,bmp,png',
             *     mimeTypes: 'image/*'
             * }
             * ```
             */
            accept: null/*{
                title: 'Images',
                extensions: 'gif,jpg,jpeg,bmp,png',
                mimeTypes: 'image/*'
            }*/
        });
    
        return Uploader.register({
            'add-btn': 'addButton',
            refresh: 'refresh',
            disable: 'disable',
            enable: 'enable'
        }, {
    
            init: function( opts ) {
                this.pickers = [];
                return opts.pick && this.addButton( opts.pick );
            },
    
            refresh: function() {
                $.each( this.pickers, function() {
                    this.refresh();
                });
            },
    
            /**
             * @method addButton
             * @for Uploader
             * @grammar addButton( pick ) => Promise
             * @description
             * Add another file selection button. Parameters match [options.pick](#WebUploader:Uploader:options).
             * @example
             * uploader.addButton({
             *     id: '#btnContainer',
             *     innerHTML: 'Select a file'
             * });
             */
            addButton: function( pick ) {
                var me = this,
                    opts = me.options,
                    accept = opts.accept,
                    options, picker, deferred;
    
                if ( !pick ) {
                    return;
                }
    
                deferred = Base.Deferred();
                $.isPlainObject( pick ) || (pick = {
                    id: pick
                });
    
                options = $.extend({}, pick, {
                    accept: $.isPlainObject( accept ) ? [ accept ] : accept,
                    swf: opts.swf,
                    runtimeOrder: opts.runtimeOrder
                });
    
                picker = new FilePicker( options );
    
                picker.once( 'ready', deferred.resolve );
                picker.on( 'select', function( files ) {
                    me.owner.request( 'add-file', [ files ]);
                });
                picker.init();
    
                this.pickers.push( picker );
    
                return deferred.promise();
            },
    
            disable: function() {
                $.each( this.pickers, function() {
                    this.disable();
                });
            },
    
            enable: function() {
                $.each( this.pickers, function() {
                    this.enable();
                });
            }
        });
    });
    /**
     * @fileOverview File attribute encapsulation
     */
    define('file',[
        'base',
        'mediator'
    ], function( Base, Mediator ) {
    
        var $ = Base.$,
            idPrefix = 'WU_FILE_',
            idSuffix = 0,
            rExt = /\.([^.]+)$/,
            statusMap = {};
    
        function gid() {
            return idPrefix + idSuffix++;
        }
    
        /**
         * File class
         * @class File
         * @constructor
         * @grammar new File( source ) => File
         * @param {Lib.File} source [lib.File](#Lib.File) instance containing runtime information.
         */
        function WUFile( source ) {
    
            /**
             * Filename, including its extension.
             * @property name
             * @type {string}
             */
            this.name = source.name || 'Untitled';
    
            /**
             * File size in bytes.
             * @property size
             * @type {uint}
             * @default 0
             */
            this.size = source.size || 0;
    
            /**
             * MIME type. For file type mappings, see [http://t.cn/z8ZnFny](http://t.cn/z8ZnFny).
             * @property type
             * @type {string}
             * @default 'application'
             */
            this.type = source.type || 'application';
    
            /**
             * Date the file was last modified
             * @property lastModifiedDate
             * @type {int}
             * @default current timestamp
             */
            this.lastModifiedDate = source.lastModifiedDate || (new Date() * 1);
    
            /**
             * Unique file ID, independent of the filename.
             * @property id
             * @type {string}
             */
            this.id = gid();
    
            /**
             * File extension from the filename; for example, test.PNG has the extension PNG.
             * @property ext
             * @type {string}
             */
            this.ext = rExt.exec( this.name ) ? RegExp.$1 : '';
    
    
            /**
             * Status description; its meaning depends on the current status.
             * @property statusText
             * @type {string}
             */
            this.statusText = '';
    
            // Store file status separately to prevent direct property changes.
            statusMap[ this.id ] = WUFile.Status.INITED;
    
            this.source = source;
            this.loaded = 0;
    
            this.on( 'error', function( msg ) {
                this.setStatus( WUFile.Status.ERROR, msg );
            });
        }
    
        $.extend( WUFile.prototype, {
    
            /**
             * Set the status，Triggers when the state changes`change`occurrences。
             * @method setStatus
             * @grammar setStatus( status[, statusText] );
             * @param {File.Status|String} status [File status values](#WebUploader:File:File.Status)
             * @param {String} [statusText=''] Status description. Error states commonly use http, abort, or server to identify the cause.
             */
            setStatus: function( status, text ) {
    
                var prevStatus = statusMap[ this.id ];
    
                typeof text !== 'undefined' && (this.statusText = text);
    
                if ( status !== prevStatus ) {
                    statusMap[ this.id ] = status;
                    /**
                     * File status changes
                     * @event statuschange
                     */
                    this.trigger( 'statuschange', status, prevStatus );
                }
    
            },
    
            /**
             * Get file status
             * @return {File.Status}
             * @example
                     The file status includes the following values:
                     {
                         // initialized
                        INITED:     0,
                        // Queued
                        QUEUED:     1,
                        // uploading
                        PROGRESS:     2,
                        // Upload error
                        ERROR:         3,
                        // Successfully uploaded
                        COMPLETE:     4,
                        // Upload cancelled
                        CANCELLED:     5
                    }
             */
            getStatus: function() {
                return statusMap[ this.id ];
            },
    
            /**
             * Get the original file information.
             * @return {*}
             */
            getSource: function() {
                return this.source;
            },
    
            destory: function() {
                delete statusMap[ this.id ];
            }
        });
    
        Mediator.installTo( WUFile.prototype );
    
        /**
         * File status values:
         * * `inited` initial state
         * * `queued` Queued and waiting for upload.
         * * `progress` uploading
         * * `complete` Upload completed.
         * * `error` Upload failed; retry is allowed.
         * * `interrupt` Upload interrupted; it can be resumed.
         * * `invalid` Invalid file; cannot be retried and is removed from the queue.
         * * `cancelled` File removed.
         * @property {Object} Status
         * @namespace File
         * @class File
         * @static
         */
        WUFile.Status = {
            INITED:     'inited',    // initial state
            QUEUED:     'queued',    // Queued and waiting for upload.
            PROGRESS:   'progress',    // uploading
            ERROR:      'error',    // Upload failed; retry is allowed.
            COMPLETE:   'complete',    // Upload completed.
            CANCELLED:  'cancelled',    // Upload cancelled.
            INTERRUPT:  'interrupt',    // Upload interrupted; it can be resumed.
            INVALID:    'invalid'    // Invalid file; cannot be retried.
        };
    
        return WUFile;
    });
    
    /**
     * @fileOverview file queue
     */
    define('queue',[
        'base',
        'mediator',
        'file'
    ], function( Base, Mediator, WUFile ) {
    
        var $ = Base.$,
            STATUS = WUFile.Status;
    
        /**
         * File queue for storing files in different states.
         * @class Queue
         * @extends Mediator
         */
        function Queue() {
    
            /**
             * File counts:
             * * `numOfQueue` Number of queued files.
             * * `numOfSuccess` Number of successfully uploaded files
             * * `numOfCancel` Number of files removed
             * * `numOfProgress` Number of files being uploaded
             * * `numOfUploadFailed` Number of failed uploads.
             * * `numOfInvalid` Number of invalid files.
             * @property {Object} stats
             */
            this.stats = {
                numOfQueue: 0,
                numOfSuccess: 0,
                numOfCancel: 0,
                numOfProgress: 0,
                numOfUploadFailed: 0,
                numOfInvalid: 0
            };
    
            // Upload queue containing files awaiting upload.
            this._queue = [];
    
            // Store all files
            this._map = {};
        }
    
        $.extend( Queue.prototype, {
    
            /**
             * Append a file to the queue.
             *
             * @method append
             * @param  {File} file   file object
             */
            append: function( file ) {
                this._queue.push( file );
                this._fileAdded( file );
                return this;
            },
    
            /**
             * Add new files to the head of the queue
             *
             * @method prepend
             * @param  {File} file   file object
             */
            prepend: function( file ) {
                this._queue.unshift( file );
                this._fileAdded( file );
                return this;
            },
    
            /**
             * Get a file object
             *
             * @method getFile
             * @param  {String} fileId   file ID
             * @return {File}
             */
            getFile: function( fileId ) {
                if ( typeof fileId !== 'string' ) {
                    return fileId;
                }
                return this._map[ fileId ];
            },
    
            /**
             * Retrieve a file with the specified status from the queue.
             * @grammar fetch( status ) => File
             * @method fetch
             * @param {String} status [File status values](#WebUploader:File:File.Status)
             * @return {File} [File](#WebUploader:File)
             */
            fetch: function( status ) {
                var len = this._queue.length,
                    i, file;
    
                status = status || STATUS.QUEUED;
    
                for ( i = 0; i < len; i++ ) {
                    file = this._queue[ i ];
    
                    if ( status === file.getStatus() ) {
                        return file;
                    }
                }
    
                return null;
            },
    
            /**
             * Sort the queue to control the upload order.
             * @grammar sort( fn ) => undefined
             * @method sort
             * @param {Function} fn Sorting method
             */
            sort: function( fn ) {
                if ( typeof fn === 'function' ) {
                    this._queue.sort( fn );
                }
            },
    
            /**
             * Get files with the specified statuses. Each item is a [File](#WebUploader:File) object.
             * @grammar getFiles( [status1[, status2 ...]] ) => Array
             * @method getFiles
             * @param {String} [status] [File status values](#WebUploader:File:File.Status)
             */
            getFiles: function() {
                var sts = [].slice.call( arguments, 0 ),
                    ret = [],
                    i = 0,
                    len = this._queue.length,
                    file;
    
                for ( ; i < len; i++ ) {
                    file = this._queue[ i ];
    
                    if ( sts.length && !~$.inArray( file.getStatus(), sts ) ) {
                        continue;
                    }
    
                    ret.push( file );
                }
    
                return ret;
            },
    
            _fileAdded: function( file ) {
                var me = this,
                    existing = this._map[ file.id ];
    
                if ( !existing ) {
                    this._map[ file.id ] = file;
    
                    file.on( 'statuschange', function( cur, pre ) {
                        me._onFileStatusChange( cur, pre );
                    });
                }
    
                file.setStatus( STATUS.QUEUED );
            },
    
            _onFileStatusChange: function( curStatus, preStatus ) {
                var stats = this.stats;
    
                switch ( preStatus ) {
                    case STATUS.PROGRESS:
                        stats.numOfProgress--;
                        break;
    
                    case STATUS.QUEUED:
                        stats.numOfQueue --;
                        break;
    
                    case STATUS.ERROR:
                        stats.numOfUploadFailed--;
                        break;
    
                    case STATUS.INVALID:
                        stats.numOfInvalid--;
                        break;
                }
    
                switch ( curStatus ) {
                    case STATUS.QUEUED:
                        stats.numOfQueue++;
                        break;
    
                    case STATUS.PROGRESS:
                        stats.numOfProgress++;
                        break;
    
                    case STATUS.ERROR:
                        stats.numOfUploadFailed++;
                        break;
    
                    case STATUS.COMPLETE:
                        stats.numOfSuccess++;
                        break;
    
                    case STATUS.CANCELLED:
                        stats.numOfCancel++;
                        break;
    
                    case STATUS.INVALID:
                        stats.numOfInvalid++;
                        break;
                }
            }
    
        });
    
        Mediator.installTo( Queue.prototype );
    
        return Queue;
    });
    /**
     * @fileOverview queues
     */
    define('widgets/queue',[
        'base',
        'uploader',
        'queue',
        'file',
        'lib/file',
        'runtime/client',
        'widgets/widget'
    ], function( Base, Uploader, Queue, WUFile, File, RuntimeClient ) {
    
        var $ = Base.$,
            rExt = /\.\w+$/,
            Status = WUFile.Status;
    
        return Uploader.register({
            'sort-files': 'sortFiles',
            'add-file': 'addFiles',
            'get-file': 'getFile',
            'fetch-file': 'fetchFile',
            'get-stats': 'getStats',
            'get-files': 'getFiles',
            'remove-file': 'removeFile',
            'retry': 'retry',
            'reset': 'reset',
            'accept-file': 'acceptFile'
        }, {
    
            init: function( opts ) {
                var me = this,
                    deferred, len, i, item, arr, accept, runtime;
    
                if ( $.isPlainObject( opts.accept ) ) {
                    opts.accept = [ opts.accept ];
                }
    
                // Build matching rules from accept.
                if ( opts.accept ) {
                    arr = [];
    
                    for ( i = 0, len = opts.accept.length; i < len; i++ ) {
                        item = opts.accept[ i ].extensions;
                        item && arr.push( item );
                    }
    
                    if ( arr.length ) {
                        accept = '\\.' + arr.join(',')
                                .replace( /,/g, '$|\\.' )
                                .replace( /\*/g, '.*' ) + '$';
                    }
    
                    me.accept = new RegExp( accept, 'i' );
                }
    
                me.queue = new Queue();
                me.stats = me.queue.stats;
    
                // Stop here if the selected runtime is not html5.
                // Skip the remaining initialization.
                if ( this.request('predict-runtime-type') !== 'html5' ) {
                    return;
                }
    
                // Create an html5 runtime placeholder.
                // This allows externally supplied native File objects to be wrapped for WebUploader.
                deferred = Base.Deferred();
                runtime = new RuntimeClient('Placeholder');
                runtime.connectRuntime({
                    runtimeOrder: 'html5'
                }, function() {
                    me._ruid = runtime.getRuid();
                    deferred.resolve();
                });
                return deferred.promise();
            },
    
    
            // Support externally supplied native File objects.
            _wrapFile: function( file ) {
                if ( !(file instanceof WUFile) ) {
    
                    if ( !(file instanceof File) ) {
                        if ( !this._ruid ) {
                            throw new Error('Can\'t add external files.');
                        }
                        file = new File( this._ruid, file );
                    }
    
                    file = new WUFile( file );
                }
    
                return file;
            },
    
            // Determine whether a file can be added to the queue
            acceptFile: function( file ) {
                var invalid = !file || file.size < 6 || this.accept &&
    
                        // Apply the extension whitelist only if the filename has an extension.
                        rExt.exec( file.name ) && !this.accept.test( file.name );
    
                return !invalid;
            },
    
    
            /**
             * @event beforeFileQueued
             * @param {File} file File object
             * @description Triggered before a file is queued. Return `false` from the handler to prevent it from being queued.
             * @for  Uploader
             */
    
            /**
             * @event fileQueued
             * @param {File} file File object
             * @description Triggered when a file is added to the queue.
             * @for  Uploader
             */
    
            _addFile: function( file ) {
                var me = this;
    
                file = me._wrapFile( file );
    
                // Trigger `beforeFileQueued` before checking the file type.
                if ( !me.owner.trigger( 'beforeFileQueued', file ) ) {
                    return;
                }
    
                // Trigger an error and return if the file type is not accepted.
                if ( !me.acceptFile( file ) ) {
                    me.owner.trigger( 'error', 'Q_TYPE_DENIED', file );
                    return;
                }
    
                me.queue.append( file );
                me.owner.trigger( 'fileQueued', file );
                return file;
            },
    
            getFile: function( fileId ) {
                return this.queue.getFile( fileId );
            },
    
            /**
             * @event filesQueued
             * @param {File} files Array of original file (lib/File) objects.
             * @description Triggered when a batch of files is added to the queue.
             * @for  Uploader
             */
    
            /**
             * @method addFiles
             * @grammar addFiles( file ) => undefined
             * @grammar addFiles( [file1, file2 ...] ) => undefined
             * @param {Array of File or File} [files] File object or array of file objects.
             * @description Adding files to the queue
             * @for  Uploader
             */
            addFiles: function( files ) {
                var me = this;
    
                if ( !files.length ) {
                    files = [ files ];
                }
    
                files = $.map( files, function( file ) {
                    return me._addFile( file );
                });
    
                me.owner.trigger( 'filesQueued', files );
    
                if ( me.options.auto ) {
                    me.request('start-upload');
                }
            },
    
            getStats: function() {
                return this.stats;
            },
    
            /**
             * @event fileDequeued
             * @param {File} file File object
             * @description Triggered when a file is removed from the queue.
             * @for  Uploader
             */
    
            /**
             * @method removeFile
             * @grammar removeFile( file ) => undefined
             * @grammar removeFile( id ) => undefined
             * @param {File|id} file a file object or the id of this file object
             * @description Remove a file.
             * @for  Uploader
             * @example
             *
             * $li.on('click', '.remove-this', function() {
             *     uploader.removeFile( file );
             * })
             */
            removeFile: function( file ) {
                var me = this;
    
                file = file.id ? file : me.queue.getFile( file );
    
                file.setStatus( Status.CANCELLED );
                me.owner.trigger( 'fileDequeued', file );
            },
    
            /**
             * @method getFiles
             * @grammar getFiles() => Array
             * @grammar getFiles( status1, status2, status... ) => Array
             * @description Return files with the specified statuses, or all files if no statuses are supplied.
             * @for  Uploader
             * @example
             * console.log( uploader.getFiles() );    // => all files
             * console.log( uploader.getFiles('error') )    // => all error files.
             */
            getFiles: function() {
                return this.queue.getFiles.apply( this.queue, arguments );
            },
    
            fetchFile: function() {
                return this.queue.fetch.apply( this.queue, arguments );
            },
    
            /**
             * @method retry
             * @grammar retry() => undefined
             * @grammar retry( file ) => undefined
             * @description Retry the specified file, or retry all files with upload errors.
             * @for  Uploader
             * @example
             * function retry() {
             *     uploader.retry();
             * }
             */
            retry: function( file, noForceStart ) {
                var me = this,
                    files, i, len;
    
                if ( file ) {
                    file = file.id ? file : me.queue.getFile( file );
                    file.setStatus( Status.QUEUED );
                    noForceStart || me.request('start-upload');
                    return;
                }
    
                files = me.queue.getFiles( Status.ERROR );
                i = 0;
                len = files.length;
    
                for ( ; i < len; i++ ) {
                    file = files[ i ];
                    file.setStatus( Status.QUEUED );
                }
    
                me.request('start-upload');
            },
    
            /**
             * @method sort
             * @grammar sort( fn ) => undefined
             * @description Sort queued files before upload to control their upload order.
             * @for  Uploader
             */
            sortFiles: function() {
                return this.queue.sort.apply( this.queue, arguments );
            },
    
            /**
             * @method reset
             * @grammar reset() => undefined
             * @description Reset the uploader. Currently, only the queue is reset.
             * @for  Uploader
             * @example
             * uploader.reset();
             */
            reset: function() {
                this.queue = new Queue();
                this.stats = this.queue.stats;
            }
        });
    
    });
    /**
     * @fileOverview Methods for obtaining runtime information.
     */
    define('widgets/runtime',[
        'uploader',
        'runtime/runtime',
        'widgets/widget'
    ], function( Uploader, Runtime ) {
    
        Uploader.support = function() {
            return Runtime.hasRuntime.apply( Runtime, arguments );
        };
    
        return Uploader.register({
            'predict-runtime-type': 'predictRuntmeType'
        }, {
    
            init: function() {
                if ( !this.predictRuntmeType() ) {
                    throw Error('Runtime Error');
                }
            },
    
            /**
             * Predict which `Runtime` the uploader will use.
             * @grammar predictRuntmeType() => String
             * @method predictRuntmeType
             * @for  Uploader
             */
            predictRuntmeType: function() {
                var orders = this.options.runtimeOrder || Runtime.orders,
                    type = this.type,
                    i, len;
    
                if ( !type ) {
                    orders = orders.split( /\s*,\s*/g );
    
                    for ( i = 0, len = orders.length; i < len; i++ ) {
                        if ( Runtime.hasRuntime( orders[ i ] ) ) {
                            this.type = type = orders[ i ];
                            break;
                        }
                    }
                }
    
                return type;
            }
        });
    });
    /**
     * @fileOverview Transport
     */
    define('lib/transport',[
        'base',
        'runtime/client',
        'mediator'
    ], function( Base, RuntimeClient, Mediator ) {
    
        var $ = Base.$;
    
        function Transport( opts ) {
            var me = this;
    
            opts = me.options = $.extend( true, {}, Transport.options, opts || {} );
            RuntimeClient.call( this, 'Transport' );
    
            this._blob = null;
            this._formData = opts.formData || {};
            this._headers = opts.headers || {};
    
            this.on( 'progress', this._timeout );
            this.on( 'load error', function() {
                me.trigger( 'progress', 1 );
                clearTimeout( me._timer );
            });
        }
    
        Transport.options = {
            server: '',
            method: 'POST',
    
            // Allow cross-origin cookies; supported only by the HTML5 runtime.
            withCredentials: false,
            fileVal: 'file',
            timeout: 2 * 60 * 1000,    // 2 minutes
            formData: {},
            headers: {},
            sendAsBinary: false
        };
    
        $.extend( Transport.prototype, {
    
            // Set the blob to upload; the latest call replaces the previous blob.
            appendBlob: function( key, blob, filename ) {
                var me = this,
                    opts = me.options;
    
                if ( me.getRuid() ) {
                    me.disconnectRuntime();
                }
    
                // Connect to the same runtime the blob belongs to.
                me.connectRuntime( blob.ruid, function() {
                    me.exec('init');
                });
    
                me._blob = blob;
                opts.fileVal = key || opts.fileVal;
                opts.filename = filename || opts.filename;
            },
    
            // Adding additional fields
            append: function( key, value ) {
                if ( typeof key === 'object' ) {
                    $.extend( this._formData, key );
                } else {
                    this._formData[ key ] = value;
                }
            },
    
            setRequestHeader: function( key, value ) {
                if ( typeof key === 'object' ) {
                    $.extend( this._headers, key );
                } else {
                    this._headers[ key ] = value;
                }
            },
    
            send: function( method ) {
                this.exec( 'send', method );
                this._timeout();
            },
    
            abort: function() {
                clearTimeout( this._timer );
                return this.exec('abort');
            },
    
            destroy: function() {
                this.trigger('destroy');
                this.off();
                this.exec('destroy');
                this.disconnectRuntime();
            },
    
            getResponse: function() {
                return this.exec('getResponse');
            },
    
            getResponseAsJson: function() {
                return this.exec('getResponseAsJson');
            },
    
            getStatus: function() {
                return this.exec('getStatus');
            },
    
            _timeout: function() {
                var me = this,
                    duration = me.options.timeout;
    
                if ( !duration ) {
                    return;
                }
    
                clearTimeout( me._timer );
                me._timer = setTimeout(function() {
                    me.abort();
                    me.trigger( 'error', 'timeout' );
                }, duration );
            }
    
        });
    
        // Add event behavior to Transport.
        Mediator.installTo( Transport.prototype );
    
        return Transport;
    });
    /**
     * @fileOverview File upload management.
     */
    define('widgets/upload',[
        'base',
        'uploader',
        'file',
        'lib/transport',
        'widgets/widget'
    ], function( Base, Uploader, WUFile, Transport ) {
    
        var $ = Base.$,
            isPromise = Base.isPromise,
            Status = WUFile.Status;
    
        // Add default configuration items
        $.extend( Uploader.options, {
    
    
            /**
             * @property {Boolean} [prepareNextFile=false]
             * @namespace options
             * @for Uploader
             * @description Prepare the next file while the current file is being uploaded.
             * File preparation, such as image compression or md5 computation, can be time-consuming.
             * Preparing it during the current upload can reduce the total upload time.
             */
            prepareNextFile: false,
    
            /**
             * @property {Boolean} [chunked=false]
             * @namespace options
             * @for Uploader
             * @description Upload large files in chunks.
             */
            chunked: false,
    
            /**
             * @property {Boolean} [chunkSize=5242880]
             * @namespace options
             * @for Uploader
             * @description Chunk size when chunked uploads are enabled. Defaults to 5M.
             */
            chunkSize: 5 * 1024 * 1024,
    
            /**
             * @property {Boolean} [chunkRetry=2]
             * @namespace options
             * @for Uploader
             * @description Number of automatic retries when a chunk fails because of a network issue.
             */
            chunkRetry: 2,
    
            /**
             * @property {Boolean} [threads=3]
             * @namespace options
             * @for Uploader
             * @description Maximum number of concurrent uploads.
             */
            threads: 3,
    
    
            /**
             * @property {Object} [formData]
             * @namespace options
             * @for Uploader
             * @description Additional request parameters sent with every upload request.
             */
            formData: null
    
            /**
             * @property {Object} [fileVal='file']
             * @namespace options
             * @for Uploader
             * @description Name of the file upload field.
             */
    
            /**
             * @property {Object} [method='POST']
             * @namespace options
             * @for Uploader
             * @description File upload method: `POST` or `GET`.
             */
    
            /**
             * @property {Object} [sendAsBinary=false]
             * @namespace options
             * @for Uploader
             * @description Send the file as a binary stream. In PHP, `php://input` contains the file data,
             * and other parameters are available in the $_GET array.
             */
        });
    
        // Split files into chunks.
        function CuteFile( file, chunkSize ) {
            var pending = [],
                blob = file.source,
                total = blob.size,
                chunks = chunkSize ? Math.ceil( total / chunkSize ) : 1,
                start = 0,
                index = 0,
                len;
    
            while ( index < chunks ) {
                len = Math.min( chunkSize, total - start );
    
                pending.push({
                    file: file,
                    start: start,
                    end: chunkSize ? (start + len) : total,
                    total: total,
                    chunks: chunks,
                    chunk: index++
                });
                start += len;
            }
    
            file.blocks = pending.concat();
            file.remaning = pending.length;
    
            return {
                file: file,
    
                has: function() {
                    return !!pending.length;
                },
    
                fetch: function() {
                    return pending.shift();
                }
            };
        }
    
        Uploader.register({
            'start-upload': 'start',
            'stop-upload': 'stop',
            'skip-file': 'skipFile',
            'is-in-progress': 'isInProgress'
        }, {
    
            init: function() {
                var owner = this.owner;
    
                this.runing = false;
    
                // Track active uploads against the thread limit.
                this.pool = [];
    
                // Cache files being prepared for upload.
                this.pending = [];
    
                // Track how many chunks remain to be uploaded.
                this.remaning = 0;
                this.__tick = Base.bindFn( this._tick, this );
    
                owner.on( 'uploadComplete', function( file ) {
                    // Cancel the remaining chunks.
                    file.blocks && $.each( file.blocks, function( _, v ) {
                        v.transport && (v.transport.abort(), v.transport.destroy());
                        delete v.transport;
                    });
    
                    delete file.blocks;
                    delete file.remaning;
                });
            },
    
            /**
             * @event startUpload
             * @description Triggered when uploading begins.
             * @for  Uploader
             */
    
            /**
             * Start uploading from the initial state or resume a paused upload.
             * @grammar upload() => undefined
             * @method upload
             * @for  Uploader
             */
            start: function() {
                var me = this;
    
                // Remove invalid files
                $.each( me.request( 'get-files', Status.INVALID ), function() {
                    me.request( 'remove-file', this );
                });
    
                if ( me.runing ) {
                    return;
                }
    
                me.runing = true;
    
                // Resume interrupted uploads.
                $.each( me.pool, function( _, v ) {
                    var file = v.file;
    
                    if ( file.getStatus() === Status.INTERRUPT ) {
                        file.setStatus( Status.PROGRESS );
                        me._trigged = false;
                        v.transport && v.transport.send();
                    }
                });
    
                me._trigged = false;
                me.owner.trigger('startUpload');
                Base.nextTick( me.__tick );
            },
    
            /**
             * @event stopUpload
             * @description Triggered when uploading is paused.
             * @for  Uploader
             */
    
            /**
             * Pause uploading. The first argument determines whether to interrupt active file uploads.
             * @grammar stop() => undefined
             * @grammar stop( true ) => undefined
             * @method stop
             * @for  Uploader
             */
            stop: function( interrupt ) {
                var me = this;
    
                if ( me.runing === false ) {
                    return;
                }
    
                me.runing = false;
    
                interrupt && $.each( me.pool, function( _, v ) {
                    v.transport && v.transport.abort();
                    v.file.setStatus( Status.INTERRUPT );
                });
    
                me.owner.trigger('stopUpload');
            },
    
            /**
             * Determine whether the uploader is uploading.
             * @grammar isInProgress() => Boolean
             * @method isInProgress
             * @for  Uploader
             */
            isInProgress: function() {
                return !!this.runing;
            },
    
            getStats: function() {
                return this.request('get-stats');
            },
    
            /**
             * Mark the specified file as uploaded without uploading it.
             * @grammar skipFile( file ) => undefined
             * @method skipFile
             * @for  Uploader
             */
            skipFile: function( file, status ) {
                file = this.request( 'get-file', file );
    
                file.setStatus( status || Status.COMPLETE );
                file.skipped = true;
    
                // Cancel active uploads for this file.
                file.blocks && $.each( file.blocks, function( _, v ) {
                    var _tr = v.transport;
    
                    if ( _tr ) {
                        _tr.abort();
                        _tr.destroy();
                        delete v.transport;
                    }
                });
    
                this.owner.trigger( 'uploadSkip', file );
            },
    
            /**
             * @event uploadFinished
             * @description Triggered when all file uploads finish.
             * @for  Uploader
             */
            _tick: function() {
                var me = this,
                    opts = me.options,
                    fn, val;
    
                // Wait for the previous promise to settle before continuing.
                if ( me._promise ) {
                    return me._promise.always( me.__tick );
                }
    
                // Start the next chunk if an upload slot is available.
                if ( me.pool.length < opts.threads && (val = me._nextBlock()) ) {
                    me._trigged = false;
    
                    fn = function( val ) {
                        me._promise = null;
    
                        // Check val because the promise may have been rejected.
                        val && val.file && me._startSend( val );
                        Base.nextTick( me.__tick );
                    };
    
                    me._promise = isPromise( val ) ? val.always( fn ) : fn( val );
    
                // Nothing is queued or being uploaded.
                } else if ( !me.remaning && !me.getStats().numOfQueue ) {
                    me.runing = false;
    
                    me._trigged || Base.nextTick(function() {
                        me.owner.trigger('uploadFinished');
                    });
                    me._trigged = true;
                }
            },
    
            _nextBlock: function() {
                var me = this,
                    act = me._act,
                    opts = me.options,
                    next, done;
    
                // Return the next chunk of the active file if more chunks remain.
                if ( act && act.has() &&
                        act.file.getStatus() === Status.PROGRESS ) {
    
                    // Whether to prepare the next file in advance
                    if ( opts.prepareNextFile && !me.pending.length ) {
                        me._prepareNextFile();
                    }
    
                    return act.fetch();
    
                // Otherwise, prepare the next file if uploading is active and return its first chunk when ready.
                } else if ( me.runing ) {
    
                    // Use a prepared file if available; otherwise fetch one from the queue.
                    if ( !me.pending.length && me.getStats().numOfQueue ) {
                        me._prepareNextFile();
                    }
    
                    next = me.pending.shift();
                    done = function( file ) {
                        if ( !file ) {
                            return null;
                        }
    
                        act = CuteFile( file, opts.chunked ? opts.chunkSize : 0 );
                        me._act = act;
                        return act.fetch();
                    };
    
                    // The file may still be preparing or may already be ready.
                    return isPromise( next ) ?
                            next[ next.pipe ? 'pipe' : 'then']( done ) :
                            done( next );
                }
            },
    
    
            /**
             * @event uploadStart
             * @param {File} file File object
             * @description Triggered once per file before its upload begins.
             * @for  Uploader
             */
            _prepareNextFile: function() {
                var me = this,
                    file = me.request('fetch-file'),
                    pending = me.pending,
                    promise;
    
                if ( file ) {
                    promise = me.request( 'before-send-file', file, function() {
    
                        // A skipped file will no longer have QUEUED status.
                        if ( file.getStatus() === Status.QUEUED ) {
                            me.owner.trigger( 'uploadStart', file );
                            file.setStatus( Status.PROGRESS );
                            return file;
                        }
    
                        return me._finishFile( file );
                    });
    
                    // Replace a pending preparation promise with the file itself.
                    promise.done(function() {
                        var idx = $.inArray( promise, pending );
    
                        ~idx && pending.splice( idx, 1, file );
                    });
    
                    // The before-send-file hook failed.
                    promise.fail(function( reason ) {
                        file.setStatus( Status.ERROR, reason );
                        me.owner.trigger( 'uploadError', file, reason );
                        me.owner.trigger( 'uploadComplete', file );
                    });
    
                    pending.push( promise );
                }
            },
    
            // Release the upload slot so another chunk can start.
            _popBlock: function( block ) {
                var idx = $.inArray( block, this.pool );
    
                this.pool.splice( idx, 1 );
                block.file.remaning--;
                this.remaning--;
            },
    
            // Start uploading; rejection of the preparation promise skips this chunk.
            _startSend: function( block ) {
                var me = this,
                    file = block.file,
                    promise;
    
                me.pool.push( block );
                me.remaning++;
    
                // Use the original blob for a file with only one chunk.
                // Preserve its content-type information.
                block.blob = block.chunks === 1 ? file.source :
                        file.source.slice( block.start, block.end );
    
                // Run asynchronous preparation before sending each chunk.
                promise = me.request( 'before-send', block, function() {
    
                    // Do not send more chunks after the file upload has failed.
                    if ( file.getStatus() === Status.PROGRESS ) {
                        me._doSend( block );
                    } else {
                        me._popBlock( block );
                        Base.nextTick( me.__tick );
                    }
                });
    
                // Skip this chunk if preparation fails.
                promise.fail(function() {
                    if ( file.remaning === 1 ) {
                        me._finishFile( file ).always(function() {
                            block.percentage = 1;
                            me._popBlock( block );
                            me.owner.trigger( 'uploadComplete', file );
                            Base.nextTick( me.__tick );
                        });
                    } else {
                        block.percentage = 1;
                        me._popBlock( block );
                        Base.nextTick( me.__tick );
                    }
                });
            },
    
    
            /**
             * @event uploadBeforeSend
             * @param {Object} object
             * @param {Object} data Default upload parameters; extend this object to add parameters.
             * @description Triggered before each file chunk is sent, allowing additional parameters. It may run multiple times for a chunked upload.
             * @for  Uploader
             */
    
            /**
             * @event uploadAccept
             * @param {Object} object
             * @param {Object} ret Server response in JSON format. For non-JSON responses, parse ret._raw directly.
             * @description Validate the server response after upload. Returning `false` triggers `uploadError` with reason `server`.
             * @for  Uploader
             */
    
            /**
             * @event uploadProgress
             * @param {File} file File object
             * @param {Number} percentage Upload progress
             * @description Triggered during upload with the upload progress.
             * @for  Uploader
             */
    
    
            /**
             * @event uploadError
             * @param {File} file File object
             * @param {String} reason Error code.
             * @description Triggered when a file upload fails.
             * @for  Uploader
             */
    
            /**
             * @event uploadSuccess
             * @param {File} file File object
             * @param {Object} response Data returned by the server
             * @description Triggered when a file is uploaded successfully.
             * @for  Uploader
             */
    
            /**
             * @event uploadComplete
             * @param {File} [file] File object
             * @description Triggered when a file upload finishes, whether it succeeds or fails.
             * @for  Uploader
             */
    
            // Perform the upload.
            _doSend: function( block ) {
                var me = this,
                    owner = me.owner,
                    opts = me.options,
                    file = block.file,
                    tr = new Transport( opts ),
                    data = $.extend({}, opts.formData ),
                    headers = $.extend({}, opts.headers ),
                    requestAccept, ret;
    
                block.transport = tr;
    
                tr.on( 'destroy', function() {
                    delete block.transport;
                    me._popBlock( block );
                    Base.nextTick( me.__tick );
                });
    
                // Broadcast progress for the entire file.
                tr.on( 'progress', function( percentage ) {
                    var totalPercent = 0,
                        uploaded = 0;
    
                    // Progress callbacks may continue if the upload was not aborted.
                    // if ( !file.blocks ) {
                    //     return;
                    // }
    
                    totalPercent = block.percentage = percentage;
    
                    if ( block.chunks > 1 ) {    // Calculate overall file progress.
                        $.each( file.blocks, function( _, v ) {
                            uploaded += (v.percentage || 0) * (v.end - v.start);
                        });
    
                        totalPercent = uploaded / file.size;
                    }
    
                    owner.trigger( 'uploadProgress', file, totalPercent || 0 );
                });
    
                // Check whether the response should be rejected.
                requestAccept = function( reject ) {
                    var fn;
    
                    ret = tr.getResponseAsJson() || {};
                    ret._raw = tr.getResponse();
                    fn = function( value ) {
                        reject = value;
                    };
    
                    // A server response does not imply success; validate it.
                    if ( !owner.trigger( 'uploadAccept', block, ret, fn ) ) {
                        reject = reject || 'server';
                    }
    
                    return reject;
                };
    
                // Retry eligible chunks, then report upload errors.
                tr.on( 'error', function( type, flag ) {
                    block.retried = block.retried || 0;
    
                    // Auto retry
                    if ( block.chunks > 1 && ~'http,abort'.indexOf( type ) &&
                            block.retried < opts.chunkRetry ) {
    
                        block.retried++;
                        tr.send();
    
                    } else {
    
                        // http status 500 ~ 600
                        if ( !flag && type === 'server' ) {
                            type = requestAccept( type );
                        }
    
                        file.setStatus( Status.ERROR, type );
                        owner.trigger( 'uploadError', file, type );
                        owner.trigger( 'uploadComplete', file );
                    }
                });
    
                // Successfully uploaded
                tr.on( 'load', function() {
                    var reason;
    
                    // Report an upload error if the response is rejected.
                    if ( (reason = requestAccept()) ) {
                        tr.trigger( 'error', reason, true );
                        return;
                    }
    
                    // Finish the file after its last chunk is uploaded.
                    if ( file.remaning === 1 ) {
                        me._finishFile( file, ret );
                    } else {
                        tr.destroy();
                    }
                });
    
                // Set the default upload fields.
                data = $.extend( data, {
                    id: file.id,
                    name: file.name,
                    type: file.type,
                    lastModifiedDate: file.lastModifiedDate,
                    size: file.size
                });
    
                block.chunks > 1 && $.extend( data, {
                    chunks: block.chunks,
                    chunk: block.chunk
                });
    
                // Allow extra fields before transmission.
                // Listen to this event to extend the default fields.
                owner.trigger( 'uploadBeforeSend', block, data, headers );
    
                // Start sending.
                tr.appendBlob( opts.fileVal, block.blob, file.name );
                tr.append( data );
                tr.setRequestHeader( headers );
                tr.send();
            },
    
            // Finish the upload.
            _finishFile: function( file, ret, hds ) {
                var owner = this.owner;
    
                return owner
                        .request( 'after-send-file', arguments, function() {
                            file.setStatus( Status.COMPLETE );
                            owner.trigger( 'uploadSuccess', file, ret, hds );
                        })
                        .fail(function( reason ) {
    
                            // Preserve statuses that have already been changed externally.
                            if ( file.getStatus() === Status.PROGRESS ) {
                                file.setStatus( Status.ERROR, reason );
                            }
    
                            owner.trigger( 'uploadError', file, reason );
                        })
                        .always(function() {
                            owner.trigger( 'uploadComplete', file );
                        });
            }
    
        });
    });
    /**
     * @fileOverview Various verifications，Including whether the total size of the file exceeds、Whether a single file is exceeded and whether the file is duplicated。
     */
    
    define('widgets/validator',[
        'base',
        'uploader',
        'file',
        'widgets/widget'
    ], function( Base, Uploader, WUFile ) {
    
        var $ = Base.$,
            validators = {},
            api;
    
        /**
         * @event error
         * @param {String} type Types of errors。
         * @description When the validation fails，The caller will be notified in the form of a dispatch error event。via`upload.on('error', handler)`This kind of error can be caught，Currently, there are the following errors that will cause the wrong delivery under certain circumstances。
         *
         * * `Q_EXCEED_NUM_LIMIT` I'm setting it up`fileNumLimit`Also try to give`uploader`Dispatched when the number of added files exceeds this value。
         * * `Q_EXCEED_SIZE_LIMIT` I'm setting it up`Q_EXCEED_SIZE_LIMIT`Also try to give`uploader`Dispatched when the total size of added files exceeds this value。
         * @for  Uploader
         */
    
        // Expose the API to the outside world
        api = {
    
            // Add validators
            addValidator: function( type, cb ) {
                validators[ type ] = cb;
            },
    
            // Remove an authenticator
            removeValidator: function( type ) {
                delete validators[ type ];
            }
        };
    
        // Initialize Validators when the Uploader is initialized
        Uploader.register({
            init: function() {
                var me = this;
                $.each( validators, function() {
                    this.call( me.owner );
                });
            }
        });
    
        /**
         * @property {int} [fileNumLimit=undefined]
         * @namespace options
         * @for Uploader
         * @description Total number of verification files, If it is exceeded, you are not allowed to join the queue。
         */
        api.addValidator( 'fileNumLimit', function() {
            var uploader = this,
                opts = uploader.options,
                count = 0,
                max = opts.fileNumLimit >> 0,
                flag = true;
    
            if ( !max ) {
                return;
            }
    
            uploader.on( 'beforeFileQueued', function( file ) {
    
                if ( count >= max && flag ) {
                    flag = false;
                    this.trigger( 'error', 'Q_EXCEED_NUM_LIMIT', max, file );
                    setTimeout(function() {
                        flag = true;
                    }, 1 );
                }
    
                return count >= max ? false : true;
            });
    
            uploader.on( 'fileQueued', function() {
                count++;
            });
    
            uploader.on( 'fileDequeued', function() {
                count--;
            });
    
            uploader.on( 'uploadFinished', function() {
                count = 0;
            });
        });
    
    
        /**
         * @property {int} [fileSizeLimit=undefined]
         * @namespace options
         * @for Uploader
         * @description Verify that the total file size exceeds the limit, If it is exceeded, you are not allowed to join the queue。
         */
        api.addValidator( 'fileSizeLimit', function() {
            var uploader = this,
                opts = uploader.options,
                count = 0,
                max = opts.fileSizeLimit >> 0,
                flag = true;
    
            if ( !max ) {
                return;
            }
    
            uploader.on( 'beforeFileQueued', function( file ) {
                var invalid = count + file.size > max;
    
                if ( invalid && flag ) {
                    flag = false;
                    this.trigger( 'error', 'Q_EXCEED_SIZE_LIMIT', max, file );
                    setTimeout(function() {
                        flag = true;
                    }, 1 );
                }
    
                return invalid ? false : true;
            });
    
            uploader.on( 'fileQueued', function( file ) {
                count += file.size;
            });
    
            uploader.on( 'fileDequeued', function( file ) {
                count -= file.size;
            });
    
            uploader.on( 'uploadFinished', function() {
                count = 0;
            });
        });
    
        /**
         * @property {int} [fileSingleSizeLimit=undefined]
         * @namespace options
         * @for Uploader
         * @description Verify that the size of a single file exceeds the limit, If it is exceeded, you are not allowed to join the queue。
         */
        api.addValidator( 'fileSingleSizeLimit', function() {
            var uploader = this,
                opts = uploader.options,
                max = opts.fileSingleSizeLimit;
    
            if ( !max ) {
                return;
            }
    
            uploader.on( 'beforeFileQueued', function( file ) {
    
                if ( file.size > max ) {
                    file.setStatus( WUFile.Status.INVALID, 'exceed_size' );
                    this.trigger( 'error', 'F_EXCEED_SIZE', file );
                    return false;
                }
    
            });
    
        });
    
        /**
         * @property {int} [duplicate=undefined]
         * @namespace options
         * @for Uploader
         * @description Deduplication， According to the file name、File size and last modification time to generate the hash Key.
         */
        api.addValidator( 'duplicate', function() {
            var uploader = this,
                opts = uploader.options,
                mapping = {};
    
            if ( opts.duplicate ) {
                return;
            }
    
            function hashString( str ) {
                var hash = 0,
                    i = 0,
                    len = str.length,
                    _char;
    
                for ( ; i < len; i++ ) {
                    _char = str.charCodeAt( i );
                    hash = _char + (hash << 6) + (hash << 16) - hash;
                }
    
                return hash;
            }
    
            uploader.on( 'beforeFileQueued', function( file ) {
                var hash = file.__hash || (file.__hash = hashString( file.name +
                        file.size + file.lastModifiedDate ));
    
                // It's already repeated
                if ( mapping[ hash ] ) {
                    this.trigger( 'error', 'F_DUPLICATE', file );
                    return false;
                }
            });
    
            uploader.on( 'fileQueued', function( file ) {
                var hash = file.__hash;
    
                hash && (mapping[ hash ] = true);
            });
    
            uploader.on( 'fileDequeued', function( file ) {
                var hash = file.__hash;
    
                hash && (delete mapping[ hash ]);
            });
        });
    
        return api;
    });
    
    /**
     * @fileOverview Runtime component base class.
     */
    define('runtime/compbase',[],function() {
    
        function CompBase( owner, runtime ) {
    
            this.owner = owner;
            this.options = owner.options;
    
            this.getRuntime = function() {
                return runtime;
            };
    
            this.getRuid = function() {
                return runtime.uid;
            };
    
            this.trigger = function() {
                return owner.trigger.apply( owner, arguments );
            };
        }
    
        return CompBase;
    });
    /**
     * @fileOverview Html5Runtime
     */
    define('runtime/html5/runtime',[
        'base',
        'runtime/runtime',
        'runtime/compbase'
    ], function( Base, Runtime, CompBase ) {
    
        var type = 'html5',
            components = {};
    
        function Html5Runtime() {
            var pool = {},
                me = this,
                destory = this.destory;
    
            Runtime.apply( me, arguments );
            me.type = type;
    
    
            // This method is called by RuntimeClient.
            me.exec = function( comp, fn/*, args...*/) {
                var client = this,
                    uid = client.uid,
                    args = Base.slice( arguments, 2 ),
                    instance;
    
                if ( components[ comp ] ) {
                    instance = pool[ uid ] = pool[ uid ] ||
                            new components[ comp ]( client, me );
    
                    if ( instance[ fn ] ) {
                        return instance[ fn ].apply( instance, args );
                    }
                }
            };
    
            me.destory = function() {
                // @todo Delete all instances in the pool
                return destory && destory.apply( this, arguments );
            };
        }
    
        Base.inherits( Runtime, {
            constructor: Html5Runtime,
    
            // No external runtime connection is needed; invoke the callback directly.
            init: function() {
                var me = this;
                setTimeout(function() {
                    me.trigger('ready');
                }, 1 );
            }
    
        });
    
        // Register Components
        Html5Runtime.register = function( name, component ) {
            var klass = components[ name ] = Base.inherits( CompBase, component );
            return klass;
        };
    
        // Register the html5 runtime.
        // Register it only if supported.
        if ( window.Blob && window.FileReader && window.DataView ) {
            Runtime.addRuntime( type, Html5Runtime );
        }
    
        return Html5Runtime;
    });
    /**
     * @fileOverview HTML5 Blob implementation
     */
    define('runtime/html5/blob',[
        'runtime/html5/runtime',
        'lib/blob'
    ], function( Html5Runtime, Blob ) {
    
        return Html5Runtime.register( 'Blob', {
            slice: function( start, end ) {
                var blob = this.owner.source,
                    slice = blob.slice || blob.webkitSlice || blob.mozSlice;
    
                blob = slice.call( blob, start, end );
    
                return new Blob( this.getRuid(), blob );
            }
        });
    });
    /**
     * @fileOverview FilePaste
     */
    define('runtime/html5/dnd',[
        'base',
        'runtime/html5/runtime',
        'lib/file'
    ], function( Base, Html5Runtime, File ) {
    
        var $ = Base.$,
            prefix = 'webuploader-dnd-';
    
        return Html5Runtime.register( 'DragAndDrop', {
            init: function() {
                var elem = this.elem = this.options.container;
    
                this.dragEnterHandler = Base.bindFn( this._dragEnterHandler, this );
                this.dragOverHandler = Base.bindFn( this._dragOverHandler, this );
                this.dragLeaveHandler = Base.bindFn( this._dragLeaveHandler, this );
                this.dropHandler = Base.bindFn( this._dropHandler, this );
                this.dndOver = false;
    
                elem.on( 'dragenter', this.dragEnterHandler );
                elem.on( 'dragover', this.dragOverHandler );
                elem.on( 'dragleave', this.dragLeaveHandler );
                elem.on( 'drop', this.dropHandler );
    
                if ( this.options.disableGlobalDnd ) {
                    $( document ).on( 'dragover', this.dragOverHandler );
                    $( document ).on( 'drop', this.dropHandler );
                }
            },
    
            _dragEnterHandler: function( e ) {
                var me = this,
                    denied = me._denied || false,
                    items;
    
                e = e.originalEvent || e;
    
                if ( !me.dndOver ) {
                    me.dndOver = true;
    
                    // Note that only chrome supports this API.
                    items = e.dataTransfer.items;
    
                    if ( items && items.length ) {
                        me._denied = denied = !me.trigger( 'accept', items );
                    }
    
                    me.elem.addClass( prefix + 'over' );
                    me.elem[ denied ? 'addClass' :
                            'removeClass' ]( prefix + 'denied' );
                }
    
    
                e.dataTransfer.dropEffect = denied ? 'none' : 'copy';
    
                return false;
            },
    
            _dragOverHandler: function( e ) {
                // Handle events only within the container.
                var parentElem = this.elem.parent().get( 0 );
                if ( parentElem && !$.contains( parentElem, e.currentTarget ) ) {
                    return false;
                }
    
                clearTimeout( this._leaveTimer );
                this._dragEnterHandler.call( this, e );
    
                return false;
            },
    
            _dragLeaveHandler: function() {
                var me = this,
                    handler;
    
                handler = function() {
                    me.dndOver = false;
                    me.elem.removeClass( prefix + 'over ' + prefix + 'denied' );
                };
    
                clearTimeout( me._leaveTimer );
                me._leaveTimer = setTimeout( handler, 100 );
                return false;
            },
    
            _dropHandler: function( e ) {
                var me = this,
                    ruid = me.getRuid(),
                    parentElem = me.elem.parent().get( 0 );
    
                // Handle events only within the container.
                if ( parentElem && !$.contains( parentElem, e.currentTarget ) ) {
                    return false;
                }
    
                me._getTansferFiles( e, function( results ) {
                    me.trigger( 'drop', $.map( results, function( file ) {
                        return new File( ruid, file );
                    }) );
                });
    
                me.dndOver = false;
                me.elem.removeClass( prefix + 'over' );
                return false;
            },
    
            // Collect dropped files, traversing folders where supported, then invoke callback.
            _getTansferFiles: function( e, callback ) {
                var results  = [],
                    promises = [],
                    items, files, dataTransfer, file, item, i, len, canAccessFolder;
    
                e = e.originalEvent || e;
    
                dataTransfer = e.dataTransfer;
                items = dataTransfer.items;
                files = dataTransfer.files;
    
                canAccessFolder = !!(items && items[ 0 ].webkitGetAsEntry);
    
                for ( i = 0, len = files.length; i < len; i++ ) {
                    file = files[ i ];
                    item = items && items[ i ];
    
                    if ( canAccessFolder && item.webkitGetAsEntry().isDirectory ) {
    
                        promises.push( this._traverseDirectoryTree(
                                item.webkitGetAsEntry(), results ) );
                    } else {
                        results.push( file );
                    }
                }
    
                Base.when.apply( Base, promises ).done(function() {
    
                    if ( !results.length ) {
                        return;
                    }
    
                    callback( results );
                });
            },
    
            _traverseDirectoryTree: function( entry, results ) {
                var deferred = Base.Deferred(),
                    me = this;
    
                if ( entry.isFile ) {
                    entry.file(function( file ) {
                        results.push( file );
                        deferred.resolve();
                    });
                } else if ( entry.isDirectory ) {
                    entry.createReader().readEntries(function( entries ) {
                        var len = entries.length,
                            promises = [],
                            arr = [],    // To ensure order。
                            i;
    
                        for ( i = 0; i < len; i++ ) {
                            promises.push( me._traverseDirectoryTree(
                                    entries[ i ], arr ) );
                        }
    
                        Base.when.apply( Base, promises ).then(function() {
                            results.push.apply( results, arr );
                            deferred.resolve();
                        }, deferred.reject );
                    });
                }
    
                return deferred.promise();
            },
    
            destroy: function() {
                var elem = this.elem;
    
                elem.off( 'dragenter', this.dragEnterHandler );
                elem.off( 'dragover', this.dragEnterHandler );
                elem.off( 'dragleave', this.dragLeaveHandler );
                elem.off( 'drop', this.dropHandler );
    
                if ( this.options.disableGlobalDnd ) {
                    $( document ).off( 'dragover', this.dragOverHandler );
                    $( document ).off( 'drop', this.dropHandler );
                }
            }
        });
    });
    
    /**
     * @fileOverview FilePaste
     */
    define('runtime/html5/filepaste',[
        'base',
        'runtime/html5/runtime',
        'lib/file'
    ], function( Base, Html5Runtime, File ) {
    
        return Html5Runtime.register( 'FilePaste', {
            init: function() {
                var opts = this.options,
                    elem = this.elem = opts.container,
                    accept = '.*',
                    arr, i, len, item;
    
                // Build a matching regular expression from the accepted mimetypes.
                if ( opts.accept ) {
                    arr = [];
    
                    for ( i = 0, len = opts.accept.length; i < len; i++ ) {
                        item = opts.accept[ i ].mimeTypes;
                        item && arr.push( item );
                    }
    
                    if ( arr.length ) {
                        accept = arr.join(',');
                        accept = accept.replace( /,/g, '|' ).replace( /\*/g, '.*' );
                    }
                }
                this.accept = accept = new RegExp( accept, 'i' );
                this.hander = Base.bindFn( this._pasteHander, this );
                elem.on( 'paste', this.hander );
            },
    
            _pasteHander: function( e ) {
                var allowed = [],
                    ruid = this.getRuid(),
                    items, item, blob, i, len;
    
                e = e.originalEvent || e;
                items = e.clipboardData.items;
    
                for ( i = 0, len = items.length; i < len; i++ ) {
                    item = items[ i ];
    
                    if ( item.kind !== 'file' || !(blob = item.getAsFile()) ) {
                        continue;
                    }
    
                    allowed.push( new File( ruid, blob ) );
                }
    
                if ( allowed.length ) {
                    // Allow non-file paste events (text) to bubble.
                    e.preventDefault();
                    e.stopPropagation();
                    this.trigger( 'paste', allowed );
                }
            },
    
            destroy: function() {
                this.elem.off( 'paste', this.hander );
            }
        });
    });
    
    /**
     * @fileOverview FilePicker
     */
    define('runtime/html5/filepicker',[
        'base',
        'runtime/html5/runtime'
    ], function( Base, Html5Runtime ) {
    
        var $ = Base.$;
    
        return Html5Runtime.register( 'FilePicker', {
            init: function() {
                var container = this.getRuntime().getContainer(),
                    me = this,
                    owner = me.owner,
                    opts = me.options,
                    lable = $( document.createElement('label') ),
                    input = $( document.createElement('input') ),
                    arr, i, len, mouseHandler;
    
                input.attr( 'type', 'file' );
                input.attr( 'name', opts.name );
                input.addClass('webuploader-element-invisible');
    
                lable.on( 'click', function() {
                    input.trigger('click');
                });
    
                lable.css({
                    opacity: 0,
                    width: '100%',
                    height: '100%',
                    display: 'block',
                    cursor: 'pointer',
                    background: '#ffffff'
                });
    
                if ( opts.multiple ) {
                    input.attr( 'multiple', 'multiple' );
                }
    
                // @todo Firefox does not support specifying individual file extensions.
                if ( opts.accept && opts.accept.length > 0 ) {
                    arr = [];
    
                    for ( i = 0, len = opts.accept.length; i < len; i++ ) {
                        arr.push( opts.accept[ i ].mimeTypes );
                    }
    
                    input.attr( 'accept', arr.join(',') );
                }
    
                container.append( input );
                container.append( lable );
    
                mouseHandler = function( e ) {
                    owner.trigger( e.type );
                };
    
                input.on( 'change', function( e ) {
                    var fn = arguments.callee,
                        clone;
    
                    me.files = e.target.files;
    
                    // reset input
                    clone = this.cloneNode( true );
                    this.parentNode.replaceChild( clone, this );
    
                    input.off();
                    input = $( clone ).on( 'change', fn )
                            .on( 'mouseenter mouseleave', mouseHandler );
    
                    owner.trigger('change');
                });
    
                lable.on( 'mouseenter mouseleave', mouseHandler );
    
            },
    
    
            getFiles: function() {
                return this.files;
            },
    
            destroy: function() {
                // todo
            }
        });
    });
    /**
     * @fileOverview Transport
     * @todo Support chunked transmission. Benefits:
     * Split large files into smaller chunks and send them individually to improve upload reliability. If a chunk fails, resend only that chunk,
     * rather than the whole file. Resuming interrupted transfers also requires chunked transmission.
     */
    define('runtime/html5/transport',[
        'base',
        'runtime/html5/runtime'
    ], function( Base, Html5Runtime ) {
    
        var noop = Base.noop,
            $ = Base.$;
    
        return Html5Runtime.register( 'Transport', {
            init: function() {
                this._status = 0;
                this._response = null;
            },
    
            send: function() {
                var owner = this.owner,
                    opts = this.options,
                    xhr = this._initAjax(),
                    blob = owner._blob,
                    server = opts.server,
                    formData, binary, fr;
    
                if ( opts.sendAsBinary ) {
                    server += (/\?/.test( server ) ? '&' : '?') +
                            $.param( owner._formData );
    
                    binary = blob.getSource();
                } else {
                    formData = new FormData();
                    $.each( owner._formData, function( k, v ) {
                        formData.append( k, v );
                    });
    
                    formData.append( opts.fileVal, blob.getSource(),
                            opts.filename || owner._formData.name || '' );
                }
    
                if ( opts.withCredentials && 'withCredentials' in xhr ) {
                    xhr.open( opts.method, server, true );
                    xhr.withCredentials = true;
                } else {
                    xhr.open( opts.method, server );
                }
    
                this._setRequestHeader( xhr, opts.headers );
    
                if ( binary ) {
                    xhr.overrideMimeType('application/octet-stream');
    
                    // Sending a blob directly on Android can produce an empty file on the server.
                    // Bug details:
                    // https://code.google.com/p/android/issues/detail?id=39882
                    // Read it with FileReader first, then send the ArrayBuffer.
                    if ( Base.os.android ) {
                        fr = new FileReader();
    
                        fr.onload = function() {
                            xhr.send( this.result );
                            fr = fr.onload = null;
                        };
    
                        fr.readAsArrayBuffer( binary );
                    } else {
                        xhr.send( binary );
                    }
                } else {
                    xhr.send( formData );
                }
            },
    
            getResponse: function() {
                return this._response;
            },
    
            getResponseAsJson: function() {
                return this._parseJson( this._response );
            },
    
            getStatus: function() {
                return this._status;
            },
    
            abort: function() {
                var xhr = this._xhr;
    
                if ( xhr ) {
                    xhr.upload.onprogress = noop;
                    xhr.onreadystatechange = noop;
                    xhr.abort();
    
                    this._xhr = xhr = null;
                }
            },
    
            destroy: function() {
                this.abort();
            },
    
            _initAjax: function() {
                var me = this,
                    xhr = new XMLHttpRequest(),
                    opts = this.options;
    
                if ( opts.withCredentials && !('withCredentials' in xhr) &&
                        typeof XDomainRequest !== 'undefined' ) {
                    xhr = new XDomainRequest();
                }
    
                xhr.upload.onprogress = function( e ) {
                    var percentage = 0;
    
                    if ( e.lengthComputable ) {
                        percentage = e.loaded / e.total;
                    }
    
                    return me.trigger( 'progress', percentage );
                };
    
                xhr.onreadystatechange = function() {
    
                    if ( xhr.readyState !== 4 ) {
                        return;
                    }
    
                    xhr.upload.onprogress = noop;
                    xhr.onreadystatechange = noop;
                    me._xhr = null;
                    me._status = xhr.status;
    
                    if ( xhr.status >= 200 && xhr.status < 300 ) {
                        me._response = xhr.responseText;
                        return me.trigger('load');
                    } else if ( xhr.status >= 500 && xhr.status < 600 ) {
                        me._response = xhr.responseText;
                        return me.trigger( 'error', 'server' );
                    }
    
    
                    return me.trigger( 'error', me._status ? 'http' : 'abort' );
                };
    
                me._xhr = xhr;
                return xhr;
            },
    
            _setRequestHeader: function( xhr, headers ) {
                $.each( headers, function( key, val ) {
                    xhr.setRequestHeader( key, val );
                });
            },
    
            _parseJson: function( str ) {
                var json;
    
                try {
                    json = JSON.parse( str );
                } catch ( ex ) {
                    json = {};
                }
    
                return json;
            }
        });
    });
    /**
     * @fileOverview FlashRuntime
     */
    define('runtime/flash/runtime',[
        'base',
        'runtime/runtime',
        'runtime/compbase'
    ], function( Base, Runtime, CompBase ) {
    
        var $ = Base.$,
            type = 'flash',
            components = {};
    
    
        function getFlashVersion() {
            var version;
    
            try {
                version = navigator.plugins[ 'Shockwave Flash' ];
                version = version.description;
            } catch ( ex ) {
                try {
                    version = new ActiveXObject('ShockwaveFlash.ShockwaveFlash')
                            .GetVariable('$version');
                } catch ( ex2 ) {
                    version = '0.0';
                }
            }
            version = version.match( /\d+/g );
            return parseFloat( version[ 0 ] + '.' + version[ 1 ], 10 );
        }
    
        function FlashRuntime() {
            var pool = {},
                clients = {},
                destory = this.destory,
                me = this,
                jsreciver = Base.guid('webuploader_');
    
            Runtime.apply( me, arguments );
            me.type = type;
    
    
            // This method is called by RuntimeClient.
            me.exec = function( comp, fn/*, args...*/ ) {
                var client = this,
                    uid = client.uid,
                    args = Base.slice( arguments, 2 ),
                    instance;
    
                clients[ uid ] = client;
    
                if ( components[ comp ] ) {
                    if ( !pool[ uid ] ) {
                        pool[ uid ] = new components[ comp ]( client, me );
                    }
    
                    instance = pool[ uid ];
    
                    if ( instance[ fn ] ) {
                        return instance[ fn ].apply( instance, args );
                    }
                }
    
                return me.flashExec.apply( client, arguments );
            };
    
            function handler( evt, obj ) {
                var type = evt.type || evt,
                    parts, uid;
    
                parts = type.split('::');
                uid = parts[ 0 ];
                type = parts[ 1 ];
    
                // console.log.apply( console, arguments );
    
                if ( type === 'Ready' && uid === me.uid ) {
                    me.trigger('ready');
                } else if ( clients[ uid ] ) {
                    clients[ uid ].trigger( type.toLowerCase(), evt, obj );
                }
    
                // Base.log( evt, obj );
            }
    
            // Flash event receiver.
            window[ jsreciver ] = function() {
                var args = arguments;
    
                // Defer the handler so its exceptions can be caught.
                setTimeout(function() {
                    handler.apply( null, args );
                }, 1 );
            };
    
            this.jsreciver = jsreciver;
    
            this.destory = function() {
                // @todo Delete all instances in the pool
                return destory && destory.apply( this, arguments );
            };
    
            this.flashExec = function( comp, fn ) {
                var flash = me.getFlash(),
                    args = Base.slice( arguments, 2 );
    
                return flash.exec( this.uid, comp, fn, args );
            };
    
            // @todo
        }
    
        Base.inherits( Runtime, {
            constructor: FlashRuntime,
    
            init: function() {
                var container = this.getContainer(),
                    opts = this.options,
                    html;
    
                // if not the minimal height, shims are not initialized
                // in older browsers (e.g FF3.6, IE6,7,8, Safari 4.0,5.0, etc)
                container.css({
                    position: 'absolute',
                    top: '-8px',
                    left: '-8px',
                    width: '9px',
                    height: '9px',
                    overflow: 'hidden'
                });
    
                // insert flash object
                html = '<object id="' + this.uid + '" type="application/' +
                        'x-shockwave-flash" data="' +  opts.swf + '" ';
    
                if ( Base.browser.ie ) {
                    html += 'classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000" ';
                }
    
                html += 'width="100%" height="100%" style="outline:0">'  +
                    '<param name="movie" value="' + opts.swf + '" />' +
                    '<param name="flashvars" value="uid=' + this.uid +
                    '&jsreciver=' + this.jsreciver + '" />' +
                    '<param name="wmode" value="transparent" />' +
                    '<param name="allowscriptaccess" value="always" />' +
                '</object>';
    
                container.html( html );
            },
    
            getFlash: function() {
                if ( this._flash ) {
                    return this._flash;
                }
    
                this._flash = $( '#' + this.uid ).get( 0 );
                return this._flash;
            }
    
        });
    
        FlashRuntime.register = function( name, component ) {
            component = components[ name ] = Base.inherits( CompBase, $.extend({
    
                // @todo fix this later
                flashExec: function() {
                    var owner = this.owner,
                        runtime = this.getRuntime();
    
                    return runtime.flashExec.apply( owner, arguments );
                }
            }, component ) );
    
            return component;
        };
    
        if ( getFlashVersion() >= 11.4 ) {
            Runtime.addRuntime( type, FlashRuntime );
        }
    
        return FlashRuntime;
    });
    /**
     * @fileOverview FilePicker
     */
    define('runtime/flash/filepicker',[
        'base',
        'runtime/flash/runtime'
    ], function( Base, FlashRuntime ) {
        var $ = Base.$;
    
        return FlashRuntime.register( 'FilePicker', {
            init: function( opts ) {
                var copy = $.extend({}, opts ),
                    len, i;
    
                // Fix the Flash file picker dialog when no title is set.
                len = copy.accept && copy.accept.length;
                for (  i = 0; i < len; i++ ) {
                    if ( !copy.accept[ i ].title ) {
                        copy.accept[ i ].title = 'Files';
                    }
                }
    
                delete copy.button;
                delete copy.container;
    
                this.flashExec( 'FilePicker', 'init', copy );
            },
    
            destroy: function() {
                // todo
            }
        });
    });
    /**
     * @fileOverview  Transport Flash implementation
     */
    define('runtime/flash/transport',[
        'base',
        'runtime/flash/runtime',
        'runtime/client'
    ], function( Base, FlashRuntime, RuntimeClient ) {
        var $ = Base.$;
    
        return FlashRuntime.register( 'Transport', {
            init: function() {
                this._status = 0;
                this._response = null;
                this._responseJson = null;
            },
    
            send: function() {
                var owner = this.owner,
                    opts = this.options,
                    xhr = this._initAjax(),
                    blob = owner._blob,
                    server = opts.server,
                    binary;
    
                xhr.connectRuntime( blob.ruid );
    
                if ( opts.sendAsBinary ) {
                    server += (/\?/.test( server ) ? '&' : '?') +
                            $.param( owner._formData );
    
                    binary = blob.uid;
                } else {
                    $.each( owner._formData, function( k, v ) {
                        xhr.exec( 'append', k, v );
                    });
    
                    xhr.exec( 'appendBlob', opts.fileVal, blob.uid,
                            opts.filename || owner._formData.name || '' );
                }
    
                this._setRequestHeader( xhr, opts.headers );
                xhr.exec( 'send', {
                    method: opts.method,
                    url: server
                }, binary );
            },
    
            getStatus: function() {
                return this._status;
            },
    
            getResponse: function() {
                return this._response;
            },
    
            getResponseAsJson: function() {
                return this._responseJson;
            },
    
            abort: function() {
                var xhr = this._xhr;
    
                if ( xhr ) {
                    xhr.exec('abort');
                    xhr.destroy();
                    this._xhr = xhr = null;
                }
            },
    
            destroy: function() {
                this.abort();
            },
    
            _initAjax: function() {
                var me = this,
                    xhr = new RuntimeClient('XMLHttpRequest');
    
                xhr.on( 'uploadprogress progress', function( e ) {
                    return me.trigger( 'progress', e.loaded / e.total );
                });
    
                xhr.on( 'load', function() {
                    var status = xhr.exec('getStatus'),
                        err = '';
    
                    xhr.off();
                    me._xhr = null;
    
                    if ( status >= 200 && status < 300 ) {
                        me._response = xhr.exec('getResponse');
                        me._responseJson = xhr.exec('getResponseAsJson');
                    } else if ( status >= 500 && status < 600 ) {
                        me._response = xhr.exec('getResponse');
                        me._responseJson = xhr.exec('getResponseAsJson');
                        err = 'server';
                    } else {
                        err = 'http';
                    }
    
                    xhr.destroy();
                    xhr = null;
    
                    return err ? me.trigger( 'error', err ) : me.trigger('load');
                });
    
                xhr.on( 'error', function() {
                    xhr.off();
                    me._xhr = null;
                    me.trigger( 'error', 'http' );
                });
    
                me._xhr = xhr;
                return xhr;
            },
    
            _setRequestHeader: function( xhr, headers ) {
                $.each( headers, function( key, val ) {
                    xhr.exec( 'setRequestHeader', key, val );
                });
            }
        });
    });
    /**
     * @fileOverview Build without image processing.
     */
    define('preset/withoutimage',[
        'base',
    
        // widgets
        'widgets/filednd',
        'widgets/filepaste',
        'widgets/filepicker',
        'widgets/queue',
        'widgets/runtime',
        'widgets/upload',
        'widgets/validator',
    
        // runtimes
        // html5
        'runtime/html5/blob',
        'runtime/html5/dnd',
        'runtime/html5/filepaste',
        'runtime/html5/filepicker',
        'runtime/html5/transport',
    
        // flash
        'runtime/flash/filepicker',
        'runtime/flash/transport'
    ], function( Base ) {
        return Base;
    });
    define('webuploader',[
        'preset/withoutimage'
    ], function( preset ) {
        return preset;
    });
    return require('webuploader');
});
