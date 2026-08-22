import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const bridge = await readFile(new URL('../assets/bridge.js', import.meta.url), 'utf8');

function extractFunction(source, name) {
  const marker = `function ${name}(`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `bridge.js must contain ${name}()`);
  const open = source.indexOf('{', start);
  let depth = 0;
  let quote = null;
  let escaped = false;
  let lineComment = false;
  let blockComment = false;
  for (let i = open; i < source.length; i += 1) {
    const ch = source[i];
    const next = source[i + 1];
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
      if (depth === 0) return source.slice(start, i + 1);
    }
  }
  throw new Error(`could not close ${name}()`);
}

class Element {
  constructor(tag, attrs = {}, children = [], text = '') {
    this.tagName = tag.toUpperCase();
    this._attrs = { ...attrs };
    this.className = attrs.class || '';
    this.children = children;
    this.childNodes = text ? [{ nodeType: 3, textContent: text }] : [];
    this.parentElement = null;
    for (const child of children) child.parentElement = this;
    this.attributes = Object.keys(this._attrs).map((name) => ({ name }));
  }

  hasAttribute(name) {
    return Object.prototype.hasOwnProperty.call(this._attrs, name);
  }

  getAttribute(name) {
    return this.hasAttribute(name) ? this._attrs[name] : null;
  }

  get textContent() {
    return this.childNodes.map((node) => node.textContent).join('')
      + this.children.map((child) => child.textContent).join('');
  }
}

const functions = [
  'classBaseForCongruence',
  'isPositionInSetUtility',
  'classListSorted',
  'childTagSequence',
  'shapeSignature',
  'congruentSiblings',
  'isDecorativeSeparator',
  'collectionSeparators',
  'collectionRuns',
  'collectionValuesVary',
  'collectionAttrValue',
  'normalizeStyleForCongruence',
  'collectionAttrsCongruent',
  'ownText',
  'diffCollectionMembers',
  'resolveCollectionSlotNode',
  'collectionSlotValue',
  'collectionUnitFor',
].map((name) => extractFunction(bridge, name)).join('\n');

const context = {};
vm.runInNewContext(`
  var COLLECTION_ATTR_WHITELIST = { src: 1, srcset: 1, href: 1, alt: 1 };
  var COLLECTION_ATTR_IGNORE = { id: 1, 'data-state': 1, 'data-dark': 1, 'data-d': 1 };
  var MAX_COLLECTION_SLOTS = 8;
  var root = {};
  function faqUnitFor() { return null; }
  function inMenuZone() { return false; }
  function pathOf() { return []; }
  ${functions}
  this.rules = {
    classListSorted, shapeSignature, collectionRuns, normalizeStyleForCongruence,
    collectionAttrsCongruent, diffCollectionMembers, collectionUnitFor,
  };
`, context);

const {
  classListSorted,
  shapeSignature,
  collectionRuns,
  normalizeStyleForCongruence,
  collectionAttrsCongruent,
  diffCollectionMembers,
  collectionUnitFor,
} = context.rules;

const cardA = new Element('div', {
  class: 'group relative lg:col-span-3 lg:rounded-tl-4xl border-b border-gray-200 pb-4 pt-8 p-4 rounded-3xl mt-4 [animation-delay:-26s]',
}, [new Element('a', { href: '/a' }, [], 'Insight')]);
const cardB = new Element('div', {
  class: 'group relative lg:col-span-2 lg:rounded-br-4xl border-t border-gray-200 pb-8 pt-4 p-4 rounded-3xl mt-4 z-10 overflow-visible! [animation-delay:-40s]',
}, [new Element('a', { href: '/b' }, [], 'Analysis')]);

assert.deepEqual(
  Array.from(classListSorted(cardA)),
  ['group', 'mt-4', 'p-4', 'relative', 'rounded-3xl'],
  'only position utilities should be removed from a member shape',
);
assert.equal(shapeSignature(cardA), shapeSignature(cardB), 'bento positions are not design shape');

const styledA = new Element('div', {
  class: 'card',
  style: 'opacity:1; transform: translateX(0px); color: red; --runtime: 1px',
});
const styledB = new Element('div', {
  class: 'card',
  style: 'opacity: 0.5; transform:translateX(12px); color:red; --runtime: 2px',
});
assert.equal(
  normalizeStyleForCongruence(styledA.getAttribute('style')),
  'color:red',
  'runtime style state must not reach congruence',
);
assert.equal(collectionAttrsCongruent(styledA, styledB), true, 'equivalent authored styles should normalize equally');
assert.equal(
  collectionAttrsCongruent(styledA, new Element('div', { class: 'card', style: 'color: blue' })),
  false,
  'authored style differences must remain meaningful',
);

const row1 = new Element('tr', { class: 'border-b' }, [new Element('td', {}, [], 'One')]);
const heading = new Element('tr', {}, [new Element('th', {}, [], 'Engineering')]);
const row2 = new Element('tr', { class: 'border-b' }, [new Element('td', {}, [], 'Two')]);
const tbody = new Element('tbody', {}, [row1, heading, row2]);
assert.deepEqual(Array.from(collectionRuns(tbody, [row1, row2]), (run) => run.length), [1, 1]);

const mixedParent = new Element('article', {}, [
  new Element('p', {}, [], 'A'),
  new Element('h2', {}, [], 'Heading'),
  new Element('p', {}, [], 'B'),
]);
assert.equal(collectionRuns(mixedParent, [mixedParent.children[0], mixedParent.children[2]]).length, 0);

// A word ticker punctuated by empty <i> dots is ONE list — the separators are
// decoration, not content, and refusing the merge left a real site's strip as
// ten individually-editable spans with no "Edit items" at all.
const ticker = new Element('div', { class: 'track' }, [
  new Element('span', {}, [], 'Unhurried'),
  new Element('i', {}, [], ''),
  new Element('span', {}, [], 'Film-inspired'),
  new Element('i', {}, [], ''),
  new Element('span', {}, [], 'Heirloom prints'),
  new Element('i', {}, [], ''),
]);
const tickerMembers = [ticker.children[0], ticker.children[2], ticker.children[4]];
const tickerRuns = collectionRuns(ticker, tickerMembers);
assert.equal(tickerRuns.length, 1, 'separator-punctuated members must merge into one run');
assert.equal(tickerRuns[0].length, 3, 'the merged run must hold every member');

// The prose protection stands: an <img> between paragraphs is content (src),
// never a separator, even though it has no children and no text.
const illustrated = new Element('div', {}, [
  new Element('p', {}, [], 'A'),
  new Element('img', { src: 'x.jpg' }, [], ''),
  new Element('p', {}, [], 'B'),
]);
assert.equal(collectionRuns(illustrated, [illustrated.children[0], illustrated.children[2]]).length, 0,
  'an image between paragraphs must not merge them into a list');

const list = new Element('div', {}, [cardA, cardB]);
const slots = [];
diffCollectionMembers([cardA, cardB], [], slots);
assert.equal(slots.length, 1, 'a congruent card set must retain its varying link slot');
const detected = collectionUnitFor(cardA);
assert.ok(detected, 'a real bento-card section must be offered as a collection');
assert.equal(detected.count, 2, 'the collection must retain every congruent card');
assert.equal(JSON.stringify(detected.slotSchema), JSON.stringify([{ type: 'link', path: [0] }]), 'the detected collection must expose its editable card link');

const bentoWithRenderMarker = new Element('div', {}, [
  new Element('div', { class: 'group relative flex flex-col', 'data-dark': 'true' }, [new Element('a', { href: '/networking' }, [], 'Networking')]),
  new Element('div', { class: 'group relative flex flex-col' }, [new Element('a', { href: '/meetings' }, [], 'Meetings')]),
  new Element('div', { class: 'group relative flex flex-col' }, [new Element('a', { href: '/engagement' }, [], 'Engagement')]),
  new Element('div', { class: 'group relative flex flex-col' }, [new Element('a', { href: '/source' }, [], 'Source')]),
]);
const markedCollection = collectionUnitFor(bentoWithRenderMarker.children[0]);
assert.ok(markedCollection, 'a render-state marker must not reject a real card collection');
assert.equal(markedCollection.count, 4, 'all marked bento cards must remain collection members');

const featureBodies = new Element('table', {}, [
  new Element('tbody', { class: 'group' }, [
    new Element('tr', {}, [new Element('th', {}, [new Element('div', {}, [], 'Features')])]),
    new Element('tr', {}, [new Element('th', {}, [], 'Accounts'), new Element('td', {}, [], '3')]),
    new Element('tr', {}, [new Element('th', {}, [], 'Boards'), new Element('td', {}, [], '5')]),
    new Element('tr', {}, [new Element('th', {}, [], 'Contacts'), new Element('td', {}, [], '100')]),
  ]),
  new Element('tbody', { class: 'group' }, [
    new Element('tr', {}, [new Element('th', {}, [new Element('div', {}, [], 'Analysis')])]),
    new Element('tr', {}, [new Element('th', {}, [], 'Competitors'), new Element('td', {}, [], '5 / month')]),
    new Element('tr', {}, [new Element('th', {}, [], 'Reporting'), new Element('td', {}, [], 'Included')]),
  ]),
  new Element('tbody', { class: 'group' }, [
    new Element('tr', {}, [new Element('th', {}, [new Element('div', {}, [], 'Support')])]),
    new Element('tr', {}, [new Element('th', {}, [], 'Email'), new Element('td', {}, [], 'Included')]),
  ]),
]);
const bodyCollection = collectionUnitFor(featureBodies.children[0]);
assert.ok(bodyCollection, 'table bodies with different row counts must be one collection');
assert.equal(bodyCollection.count, 3, 'all feature-group bodies must remain collection members');
assert.ok(bodyCollection.slotSchema.length >= 1, 'variable-row feature groups must retain an editable slot');

const loneRow = new Element('tr', {}, [
  new Element('th', {}, [], 'Feature'),
  new Element('td', {}, [], 'Included'),
  new Element('td', {}, [], 'Extra'),
  new Element('td', {}, [], 'Notes'),
]);
const loneBody = new Element('tbody', {}, [loneRow]);
assert.equal(collectionUnitFor(loneRow.children[1]), null, 'table cells must not become a collection');

// Keep the parent alive for the fake DOM and make accidental unused-variable
// removal by future edits visible to this test.
assert.equal(list.children.length, 2);
console.log('PASS: plugin collection congruence regression');

// A site with no reveal library staggers its own list: nothing on the first
// member, then 1, 2, 3, styled into a transition-delay. Read as design, it
// rejects every animated list on the site — measured on a photographer's
// pages, where service cards, process steps, add-ons and testimonials were
// ALL refused by this one attribute, so the owner would have been offered
// none of them.
const staggeredCards = new Element('div', { class: 'cards' }, [
  new Element('article', { class: 'card reveal' }, [
    new Element('a', { href: '/weddings' }, [], 'Explore weddings'),
  ]),
  new Element('article', { class: 'card reveal', 'data-d': '1' }, [
    new Element('a', { href: '/elopements' }, [], 'Explore elopements'),
  ]),
  new Element('article', { class: 'card reveal', 'data-d': '2' }, [
    new Element('a', { href: '/engagements' }, [], 'Explore engagements'),
  ]),
]);
const staggered = collectionUnitFor(staggeredCards.children[0]);
assert.ok(staggered, 'a hand-rolled stagger index must not reject a real card collection');
assert.equal(staggered.count, 3, 'every staggered card must remain a collection member');

// The case above runs against this file's own stub of the ignore list, so on
// its own it proves the mechanism and not the shipped rule. This reads the
// real list out of bridge.js, which is the half a stub cannot check.
assert.ok(
  /['"]data-d['"]\s*:\s*1/.test(bridge.slice(
    bridge.indexOf('COLLECTION_ATTR_IGNORE = {'),
    bridge.indexOf('};', bridge.indexOf('COLLECTION_ATTR_IGNORE = {')),
  )),
  'bridge.js itself must ignore the hand-rolled stagger index',
);
