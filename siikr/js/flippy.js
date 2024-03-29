!function(t, e) {
    "object" == typeof exports && "object" == typeof module ? module.exports = e() : "function" == typeof define && define.amd ? define([], e) : "object" == typeof exports ? exports.flip = e() : t.flip = e()
}(this, function() {
    return function(t) {
        function e(s) {
            if (i[s])
                return i[s].exports;
            var n = i[s] = {
                i: s,
                l: !1,
                exports: {}
            };
            return t[s].call(n.exports, n, n.exports, e),
            n.l = !0,
            n.exports
        }
        var i = {};
        return e.m = t,
        e.c = i,
        e.i = function(t) {
            return t
        }
        ,
        e.d = function(t, i, s) {
            e.o(t, i) || Object.defineProperty(t, i, {
                configurable: !1,
                enumerable: !0,
                get: s
            })
        }
        ,
        e.n = function(t) {
            var i = t && t.__esModule ? function() {
                return t.default
            }
            : function() {
                return t
            }
            ;
            return e.d(i, "a", i),
            i
        }
        ,
        e.o = function(t, e) {
            return Object.prototype.hasOwnProperty.call(t, e)
        }
        ,
        e.p = "",
        e(e.s = 1)
    }([function(t, e, i) {
        "use strict";
        function s(t, e) {
            if (!(t instanceof e))
                throw new TypeError("Cannot call a class as a function")
        }
        Object.defineProperty(e, "__esModule", {
            value: !0
        });
        var n = function() {
            function t(t, e) {
                for (var i = 0; i < e.length; i++) {
                    var s = e[i];
                    s.enumerable = s.enumerable || !1,
                    s.configurable = !0,
                    "value"in s && (s.writable = !0),
                    Object.defineProperty(t, s.key, s)
                }
            }
            return function(e, i, s) {
                return i && t(e.prototype, i),
                s && t(e, s),
                e
            }
        }()
          , o = i(2)
          , r = new Map
          , l = function() {
            function t(e, i) {
                if (s(this, t),
                !(e instanceof HTMLElement))
                    throw new TypeError("Element must be an HTMLElement");
                if (r.has(e)) {
                    var n = r.get(e);
                    return this.opts = {},
                    n.setOptions(i),
                    n
                }
                this.setOptions(i),
                r.set(e, this),
                this.elm = e,
                this._style = {}
            }
            return n(t, [{
                key: "setOptions",
                value: function(t) {
                    this.opts = Object.assign({}, {
                        duration: 400,
                        ease: "ease",
                        animatingClass: "flip-animating",
                        scalingClass: "flip-scaling",
                        useScale: !0
                    }, this.opts, t)
                }
            }, {
                key: "first",
                value: function() {
                    return this._playing && this.stop(),
                    this._first = (0,
                    o.getSnapshot)(this.elm, this.opts.useScale),
                    this.debug("first", this._first),
                    this
                }
            }, {
                key: "last",
                value: function() {
                    this._last = (0,
                    o.getSnapshot)(this.elm, this.opts.useScale),
                    this.debug("last", this._last),
                    this._style.willChange = this.elm.style.willChange,
                    this._style.transform = this.elm.style.transform,
                    this._style.transformOrigin = this.elm.style.transformOrigin,
                    this._style.transition = (0,
                    o.getTransitionFromElm)(this.elm),
                    this._style.width = this.elm.style.width,
                    this._style.height = this.elm.style.height;
                    for (var t in this._style) {
                        var e = this._style[t];
                        this._style[t] = e && "none" !== e ? e : ""
                    }
                    return this
                }
            }, {
                key: "invert",
                value: function() {
                    if (!this._first || !this._last)
                        throw new Error(".first() and .last() must be called before .invert()");
                    var t = (0,
                    o.getDelta)(this._first, this._last)
                      , e = "translate(" + t.left.toFixed(2) + "px, " + t.top.toFixed(2) + "px)"
                      , i = this.opts.useScale ? "scale(" + t.width.toFixed(2) + ", " + t.height.toFixed(2) + ")" : "";
                    return this.elm.style.transformOrigin = "50% 50%",
                    this.elm.style.transform = [e, this._first.transform, i].join(" "),
                    this.elm.style.willChange = "transform",
                    this.opts.useScale || (this.elm.style.width = this._first.width.toFixed(2) + "px",
                    this.elm.style.height = this._first.height.toFixed(2) + "px"),
                    this.debug("invert", this.elm.style.transform),
                    this
                }
            }, {
                key: "play",
                value: function() {
                    return !1 === this._playPart1() ? this : (this.elm.offsetHeight,
                    this._applyTransition(),
                    this.elm.offsetHeight,
                    this._playPart2(),
                    this)
                }
            }, {
                key: "_playPart1",
                value: function() {
                    return this._playing && this.stop(),
                    this._playing = !0,
                    !!this._checkMoved() || (this.debug("Ending early because of no change"),
                    this._animCb = this.opts.callback,
                    this.stop(),
                    !1)
                }
            }, {
                key: "_playPart2",
                value: function() {
                    var t = this;
                    return this.elm.classList.add(this.opts.animatingClass),
                    this._first.width === this._last.width && this._first.height === this._last.height || this.elm.classList.add(this.opts.scalingClass),
                    this.elm.style.transform = this._last.transform,
                    this.opts.useScale || (this.elm.style.width = this._last.width.toFixed(2) + "px",
                    this.elm.style.height = this._last.height.toFixed(2) + "px"),
                    this._animCb = this.opts.callback,
                    this._onTransitionEnd = function(e) {
                        e.target === t.elm && "transform" === e.propertyName && t.stop()
                    }
                    ,
                    this._timerFallback = setTimeout(function() {
                        return t._onTransitionEnd({
                            target: t.elm,
                            propertyName: "transform"
                        })
                    }, this.opts.duration + 100),
                    this.elm.addEventListener("transitionend", this._onTransitionEnd),
                    !0
                }
            }, {
                key: "_checkMoved",
                value: function() {
                    return !(Math.abs(this._first.left - this._last.left) <= 1 && Math.abs(this._first.top - this._last.top) <= 1 && Math.abs(this._first.width - this._last.width) <= 1 && Math.abs(this._first.height - this._last.height) <= 1)
                }
            }, {
                key: "_applyTransition",
                value: function() {
                    var t = (this.opts.duration / 1e3).toFixed(2);
                    this.elm.style.transition = [this._style.transition, "transform " + t + "s " + this.opts.ease, this.opts.useScale ? "" : "width " + t + "s " + this.opts.ease, this.opts.useScale ? "" : "height " + t + "s " + this.opts.ease].filter(Boolean).join(", ")
                }
            }, {
                key: "stop",
                value: function() {
                    return clearTimeout(this._timerFallback),
                    this.elm.removeEventListener("transitionend", this._onTransitionEnd),
                    this.clean().finish(),
                    this
                }
            }, {
                key: "clean",
                value: function() {
                    return this._first = null,
                    this._last = null,
                    this.elm.classList.remove(this.opts.animatingClass, this.opts.scalingClass),
                    this.elm.style.transition = this._style.transition,
                    this.elm.style.transformOrigin = this._style.transformOrigin,
                    this.elm.style.willChange = this._style.willChange,
                    this.elm.style.width = this._style.width,
                    this.elm.style.height = this._style.height,
                    this.elm.style.transform = this._style.transform ? "" : "translateX(10px)",
                    this.elm.offsetHeight,
                    this.elm.style.transform = this._style.transform,
                    this
                }
            }, {
                key: "finish",
                value: function() {
                    this._playing = !1,
                    this._animCb && (this._animCb(),
                    this._animCb = null)
                }
            }, {
                key: "debug",
                value: function(t, e) {
                    this.opts.debug && console.log("[", this.elm, "] ", t, e)
                }
            }]),
            t
        }();
        e.default = l
    }
    , function(t, e, i) {
        "use strict";
        var s = i(0).default;
        t.exports = function(t, e) {
            var i = arguments.length > 2 && void 0 !== arguments[2] ? arguments[2] : {};
            if (!(t && ("string" == typeof t || t instanceof Array || t instanceof HTMLElement)))
                throw new TypeError("Elements must be a string, array or element");
            if (!(e && e instanceof Function))
                throw new TypeError("Modifier must be a function");
            "string" == typeof t ? t = Array.from(document.querySelectorAll(t)) : t instanceof HTMLElement && (t = [t]),
            i.debug && console.groupCollapsed && console.groupCollapsed();
            var n = void 0
              , o = new Promise(function(t) {
                n = function() {
                    r && r(),
                    t()
                }
            }
            )
              , r = i.callback
              , l = t.length;
            return i.callback = function() {
                --l <= 0 && n()
            }
            ,
            t = t.map(function(t) {
                if (!(t instanceof HTMLElement))
                    throw new TypeError("Array must only contain elements");
                return new s(t,i)
            }),
            t.forEach(function(t) {
                return t.first()
            }),
            e(),
            t.forEach(function(t) {
                return t.last()
            }),
            t.forEach(function(t) {
                return t.invert()
            }),
            t = t.map(function(t) {
                return !1 === t._playPart1() ? (i.callback(),
                !1) : t
            }).filter(Boolean),
            document.body.offsetTop,
            t.forEach(function(t) {
                return t._applyTransition()
            }),
            document.body.offsetTop,
            t.forEach(function(t) {
                return t._playPart2()
            }),
            i.debug && console.groupCollapsed && console.groupEnd(),
            o
        }
        ,
        t.exports.FLIPElement = s
    }
    , function(t, e, i) {
        "use strict";
        function s(t, e) {
            var i = o(t)
              , s = window.getComputedStyle(t);
            return {
                left: i.left + (e ? i.width / 2 : 0),
                top: i.top + (e ? i.height / 2 : 0),
                width: i.width,
                height: i.height,
                transform: "none" !== s.transform && s.transform ? s.transform : ""
            }
        }
        function n(t, e) {
            var i = {
                left: t.left - e.left,
                top: t.top - e.top,
                width: t.width / e.width,
                height: t.height / e.height
            };
            return 0 === t.width && 0 === t.height && (i.left = i.top = 0),
            i
        }
        function o(t) {
            for (var e = {
                width: t.offsetWidth,
                height: t.offsetHeight,
                left: t.offsetLeft,
                top: t.offsetTop
            }, i = "fixed" === window.getComputedStyle(t).position; (t = t.offsetParent) && t !== document.body && t !== document.documentElement; )
                e.left += t.offsetLeft,
                e.top += t.offsetTop;
            if (i) {
                var s = document.documentElement;
                e.left += (window.pageXOffset || s.scrollLeft) - (s.clientLeft || 0),
                e.top += (window.pageYOffset || s.scrollTop) - (s.clientTop || 0)
            }
            return e
        }
        function r(t) {
            var e = window.getComputedStyle(t);
            return e.transitionProperty + " " + e.transitionDuration + " " + e.transitionTimingFunction + " " + e.transitionDelay
        }
        Object.defineProperty(e, "__esModule", {
            value: !0
        }),
        e.getSnapshot = s,
        e.getDelta = n,
        e.getClientRect = o,
        e.getTransitionFromElm = r
    }
    ])
});