// EDITOR CONSTRUCTOR

import { CodeMirror } from "./CodeMirror.js"
export { CodeMirror } from "./CodeMirror.js"

import { eventMixin } from "../util/event.js"
import { indexOf } from "../util/misc.js"

import { defineOptions } from "./options.js"

defineOptions(CodeMirror)

import addEditorMethods from "./methods.js"

addEditorMethods(CodeMirror)

import Doc from "../model/Doc.js"

// Set up methods on the CodeMirror prototype to delegate to the editor document.
let dontDelegate = "iter insert remove copy getEditor constructor".split(" ")
for (let prop in Doc.prototype) if (Doc.prototype.hasOwnProperty(prop) && indexOf(dontDelegate, prop) < 0)
  CodeMirror.prototype[prop] = (function(method) {
    return function() {return method.apply(this.doc, arguments)}
  })(Doc.prototype[prop])

eventMixin(Doc)

// INPUT HANDLING

import ContentEditableInput from "../input/ContentEditableInput.js"
import TextareaInput from "../input/TextareaInput.js"
CodeMirror.inputStyles = {"textarea": TextareaInput, "contenteditable": ContentEditableInput}

// MODE DEFINITION AND QUERYING

import { defineMIME, defineMode } from "../modes.js"

// Extra arguments are stored as mode dependencies, which are
// used by (legacy) mechanisms like loadmode.js to automatically
// load a mode. (The preferred mechanism uses require/define calls.)
CodeMirror.defineMode = function(name/*, mode, …*/) {
  if (!CodeMirror.defaults.mode && name != "null") CodeMirror.defaults.mode = name
  defineMode.apply(this, arguments)
}

CodeMirror.defineMIME = defineMIME

// Minimal default mode.
CodeMirror.defineMode("null", () => ({token: stream => stream.skipToEnd()}))
CodeMirror.defineMIME("text/plain", "null")

// EXTENSIONS

CodeMirror.defineExtension = (name, func) => {
  CodeMirror.prototype[name] = func
}
CodeMirror.defineDocExtension = (name, func) => {
  Doc.prototype[name] = func
}

import { fromTextArea } from "./fromTextArea.js"

CodeMirror.fromTextArea = fromTextArea

import { addLegacyProps } from "./legacy.js"

addLegacyProps(CodeMirror)

CodeMirror.version = "5.42.2"
