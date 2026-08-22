import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../assets/editor.js', import.meta.url), 'utf8');

function extractFunction(text, name) {
  const marker = `function ${name}(`;
  const start = text.indexOf(marker);
  assert.notEqual(start, -1, `editor.js must contain ${name}()`);
  const open = text.indexOf('{', start);
  let depth = 0;
  let quote = null;
  let escaped = false;
  let lineComment = false;
  let blockComment = false;
  for (let i = open; i < text.length; i += 1) {
    const ch = text[i];
    const next = text[i + 1];
    if (lineComment) {
      if (ch === '\n') lineComment = false;
      continue;
    }
    if (blockComment) {
      if (ch === '*' && next === '/') {
        blockComment = false;
        i += 1;
      }
      continue;
    }
    if (quote) {
      if (escaped) escaped = false;
      else if (ch === '\\') escaped = true;
      else if (ch === quote) quote = null;
      continue;
    }
    if (ch === '/' && next === '/') {
      lineComment = true;
      i += 1;
      continue;
    }
    if (ch === '/' && next === '*') {
      blockComment = true;
      i += 1;
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') {
      quote = ch;
      continue;
    }
    if (ch === '{') depth += 1;
    if (ch === '}') {
      depth -= 1;
      if (depth === 0) return text.slice(start, i + 1);
    }
  }
  throw new Error(`could not close ${name}()`);
}

class TextNode {
  constructor(value) {
    this.nodeType = 3;
    this.nodeValue = value;
  }

  get textContent() {
    return this.nodeValue;
  }

  set textContent(value) {
    this.nodeValue = value;
  }
}

class Element {
  constructor(tag, attrs = {}, children = [], textNodes = []) {
    this.tagName = tag.toUpperCase();
    this.className = attrs.class || '';
    this.children = children;
    this.childNodes = textNodes.map((value) => new TextNode(value));
    this.ownerDocument = {
      createTextNode: (value) => new TextNode(value),
    };
    for (const child of children) child.parentElement = this;
  }

  get firstChild() {
    return this.childNodes[0] || this.children[0] || null;
  }

  removeChild(node) {
    const index = this.childNodes.indexOf(node);
    if (index >= 0) this.childNodes.splice(index, 1);
    return node;
  }

  insertBefore(node, before) {
    if (!before) {
      this.childNodes.push(node);
      return node;
    }
    const textIndex = this.childNodes.indexOf(before);
    if (textIndex >= 0) this.childNodes.splice(textIndex, 0, node);
    else this.childNodes.unshift(node);
    return node;
  }
}

const functions = [
  'collectionClassBaseForShape',
  'collectionIsPositionUtility',
  'collectionShapeClassList',
  'collectionShapeChildTags',
  'collectionShapeSignature',
  'setCollectionOwnText',
].map((name) => extractFunction(source, name)).join('\n');

const context = {};
vm.runInNewContext(`${functions}
  this.rules = {
    collectionShapeSignature,
    setCollectionOwnText,
  };
`, context);

const { collectionShapeSignature, setCollectionOwnText } = context.rules;

const shortBody = new Element('tbody', { class: 'group' }, [
  new Element('tr'),
  new Element('tr'),
]);
const longBody = new Element('tbody', { class: 'group' }, [
  new Element('tr'),
  new Element('tr'),
  new Element('tr'),
]);
assert.equal(
  collectionShapeSignature(shortBody),
  collectionShapeSignature(longBody),
  'source-side collection membership must match variable-row table bodies',
);
assert.equal(collectionShapeSignature(shortBody), 'tbody|group|tr*');
assert.equal(
  collectionShapeSignature(new Element('div', {
    class: 'card lg:col-span-2 p-4 swiper-slide-active [animation-duration:2s]',
  }, [new Element('a')])),
  'div|card,p-4|a',
  'source-side shape keys must ignore runtime and positional classes like bridge.js',
);

const withIcon = new Element('button', {}, [new Element('svg')], ['Open', ' now']);
setCollectionOwnText(withIcon, 'Learn more');
assert.equal(withIcon.childNodes.length, 1);
assert.equal(withIcon.childNodes[0].nodeValue, 'Learn more');
assert.equal(withIcon.children.length, 1, 'text-own saves must preserve icon children');

console.log('PASS: plugin source collection save regression');
